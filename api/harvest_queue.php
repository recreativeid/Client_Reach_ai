<?php
/**
 * Client Reach AI - Harvest Queue Manager
 * 
 * Automated region-based harvesting with:
 * - Province/City selection from indonesia_regions.json
 * - Auto-discovery of Kab/Kota via Nominatim + Overpass
 * - Queue management with progress tracking
 * - Sequential processing with auto-resume
 * 
 * Endpoints:
 *   GET  ?action=list_provinces              → List all 34 provinces
 *   GET  ?action=discover_cities&province=ID  → Discover Kab/Kota in province
 *   POST ?action=queue_province&province=ID   → Queue entire province for harvest
 *   POST ?action=queue_city                   → Queue single city {name, province, bbox}
 *   GET  ?action=queue_status                 → Current queue state
 *   GET  ?action=process_next                 → Process next pending item
 *   POST ?action=clear_queue                  → Clear all queue items
 *   GET  ?action=harvested_regions            → List all completed harvests
 */

set_time_limit(0);
ini_set('memory_limit', '1024M');
ignore_user_abort(true);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

// Directories
$dataDir   = __DIR__ . '/../data';
$cacheDir  = $dataDir . '/cache';
$queueFile = $dataDir . '/harvest_queue.json';
$regionsFile = $dataDir . '/indonesia_regions.json';

foreach ([$dataDir, $cacheDir] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}

// Parse input
$rawInput  = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$action    = $_GET['action'] ?? ($jsonInput['action'] ?? 'queue_status');

// Load regions database
function loadRegions() {
    global $regionsFile;
    if (!file_exists($regionsFile)) {
        return ['provinces' => []];
    }
    return json_decode(file_get_contents($regionsFile), true) ?: ['provinces' => []];
}

// Load/save queue
function loadQueue() {
    global $queueFile;
    if (!file_exists($queueFile)) {
        return [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'items' => []
        ];
    }
    return json_decode(file_get_contents($queueFile), true) ?: ['items' => []];
}

function saveQueue($queue) {
    global $queueFile;
    $queue['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// JSON response helper
function respond($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ─── PHP BINARY DETECTOR (XAMPP / CLI) ───
function getPhpBinary() {
    $candidates = [
        defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : '',
        'C:\\xampp\\php\\php.exe',
        'c:/xampp/php/php.exe',
        'php'
    ];
    foreach ($candidates as $c) {
        if (!empty($c) && (is_executable($c) || file_exists($c))) {
            return $c;
        }
    }
    return 'php';
}

function isWorkerProcessAlive($pid) {
    if (!$pid || !is_numeric($pid)) return false;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $commands = [
            'C:\\Windows\\System32\\tasklist.exe /FI "PID eq ' . (int)$pid . '" 2>NUL',
            'tasklist /FI "PID eq ' . (int)$pid . '" 2>NUL'
        ];
        foreach ($commands as $cmd) {
            $out = [];
            @exec($cmd, $out);
            foreach ($out as $l) {
                if (strpos($l, (string)$pid) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
    return file_exists("/proc/$pid");
}

// ─── NOMINATIM: Resolve a region name to bounding box ───
function resolveRegionBbox($regionName, $provinceName = 'Indonesia') {
    global $cacheDir;
    
    $cacheKey = md5(strtolower($regionName . '_' . $provinceName));
    $cacheFile = $cacheDir . "/bbox_{$cacheKey}.json";
    
    // Check cache first (valid for 30 days)
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && time() - ($cached['cached_at'] ?? 0) < 86400 * 30) {
            return $cached;
        }
    }
    
    // Query Nominatim
    $query = urlencode("$regionName, $provinceName, Indonesia");
    $url = "https://nominatim.openstreetmap.org/search?q={$query}&format=json&addressdetails=1&limit=3&countrycodes=id";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'ClientReachAI_Harvester/4.0 (info@recreative.id)',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($res)) {
        return null;
    }
    
    $results = json_decode($res, true);
    if (empty($results)) return null;
    
    // Pick the best result (prefer administrative boundary)
    $best = $results[0];
    foreach ($results as $r) {
        if (($r['class'] ?? '') === 'boundary' && ($r['type'] ?? '') === 'administrative') {
            $best = $r;
            break;
        }
    }
    
    $bb = $best['boundingbox'] ?? null;
    if (!$bb || count($bb) < 4) return null;
    
    $result = [
        'name' => $best['display_name'] ?? $regionName,
        'short_name' => $regionName,
        'lat' => (float)$best['lat'],
        'lng' => (float)$best['lon'],
        'bbox' => [
            'minLat' => (float)$bb[0],
            'maxLat' => (float)$bb[1],
            'minLng' => (float)$bb[2],
            'maxLng' => (float)$bb[3]
        ],
        'osm_id' => $best['osm_id'] ?? null,
        'osm_type' => $best['osm_type'] ?? null,
        'cached_at' => time()
    ];
    
    // Cache result
    file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    return $result;
}

// ─── OVERPASS: Discover Kab/Kota within a province bbox ───
function discoverCitiesOverpass($provinceBbox) {
    global $cacheDir;
    
    $bboxKey = md5(json_encode($provinceBbox));
    $cacheFile = $cacheDir . "/cities_{$bboxKey}.json";
    
    // Check cache (valid 7 days)
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && time() - ($cached['cached_at'] ?? 0) < 86400 * 7) {
            return $cached['cities'] ?? [];
        }
    }
    
    $b = $provinceBbox;
    $bStr = sprintf('%.4f,%.4f,%.4f,%.4f', $b['minLat'], $b['minLng'], $b['maxLat'], $b['maxLng']);
    
    // Query admin_level 5 = Kabupaten/Kota in Indonesia
    $ql = "[out:json][timeout:60];
        relation[\"admin_level\"=\"5\"][\"boundary\"=\"administrative\"]($bStr);
        out tags center;";
    
    $endpoints = [
        'https://overpass-api.de/api/interpreter',
        'https://lz4.overpass-api.de/api/interpreter'
    ];
    
    $response = null;
    foreach ($endpoints as $ep) {
        $ch = curl_init($ep);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'data=' . urlencode($ql),
            CURLOPT_USERAGENT => 'ClientReachAI_Harvester/4.0 (info@recreative.id)',
            CURLOPT_TIMEOUT => 65,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && !empty($res)) {
            $response = $res;
            break;
        }
        usleep(500000);
    }
    
    if (!$response) return [];
    
    $data = json_decode($response, true);
    $elements = $data['elements'] ?? [];
    
    $cities = [];
    foreach ($elements as $el) {
        $tags = $el['tags'] ?? [];
        $name = $tags['name'] ?? '';
        if (empty($name)) continue;
        
        $center = $el['center'] ?? null;
        $adminLevel = $tags['admin_level'] ?? '5';
        $type = 'kabupaten';
        if (preg_match('/^Kota /i', $name)) {
            $type = 'kota';
        }
        
        $cities[] = [
            'name' => $name,
            'type' => $type,
            'admin_level' => $adminLevel,
            'osm_id' => $el['id'] ?? null,
            'center' => $center ? [
                'lat' => (float)$center['lat'],
                'lng' => (float)$center['lon']
            ] : null
        ];
    }
    
    // Sort by name
    usort($cities, function($a, $b) { return strcmp($a['name'], $b['name']); });
    
    // Cache
    file_put_contents($cacheFile, json_encode([
        'cached_at' => time(),
        'total' => count($cities),
        'cities' => $cities
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    return $cities;
}

// ─── NOMINATIM: Discover Kab/Kota (fallback method) ───
function discoverCitiesNominatim($provinceName) {
    global $cacheDir;
    
    $cacheKey = md5('nom_cities_' . strtolower($provinceName));
    $cacheFile = $cacheDir . "/nomcities_{$cacheKey}.json";
    
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && time() - ($cached['cached_at'] ?? 0) < 86400 * 7) {
            return $cached['cities'] ?? [];
        }
    }
    
    $cities = [];
    $types = ['city', 'town'];
    
    foreach ($types as $t) {
        $query = urlencode("$t in $provinceName Indonesia");
        $url = "https://nominatim.openstreetmap.org/search?q={$query}&format=json&addressdetails=1&limit=50&countrycodes=id";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'ClientReachAI_Harvester/4.0 (info@recreative.id)',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        
        $results = json_decode($res, true) ?: [];
        foreach ($results as $r) {
            $name = $r['display_name'] ?? '';
            $parts = explode(',', $name);
            $shortName = trim($parts[0] ?? '');
            
            if (!empty($shortName) && stripos($name, $provinceName) !== false) {
                $bb = $r['boundingbox'] ?? null;
                $cities[] = [
                    'name' => $shortName,
                    'type' => $t === 'city' ? 'kota' : 'kabupaten',
                    'center' => [
                        'lat' => (float)($r['lat'] ?? 0),
                        'lng' => (float)($r['lon'] ?? 0)
                    ],
                    'bbox' => $bb ? [
                        'minLat' => (float)$bb[0],
                        'maxLat' => (float)$bb[1],
                        'minLng' => (float)$bb[2],
                        'maxLng' => (float)$bb[3]
                    ] : null
                ];
            }
        }
        
        usleep(1100000); // Nominatim rate limit
    }
    
    // Deduplicate
    $seen = [];
    $unique = [];
    foreach ($cities as $c) {
        $key = strtolower($c['name']);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $c;
        }
    }
    
    usort($unique, function($a, $b) { return strcmp($a['name'], $b['name']); });
    
    file_put_contents($cacheFile, json_encode([
        'cached_at' => time(),
        'total' => count($unique),
        'cities' => $unique
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    return $unique;
}

// ─── CORE HARVEST FUNCTION (inline, reusing full_harvest logic) ───
function runHarvestForRegion($regionName, $bbox, $provinceId) {
    global $dataDir;
    
    $dateStr = date('Ymd');
    $regionKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($regionName));
    $jsonlFile = $dataDir . "/places_{$regionKey}_{$dateStr}.jsonl";
    $summaryFile = $dataDir . "/harvest_{$regionKey}_{$dateStr}.json";
    
    // Load existing if resuming
    $seenKeys = [];
    $harvestedPlaces = [];
    
    if (file_exists($jsonlFile)) {
        $fpRead = @fopen($jsonlFile, 'r');
        if ($fpRead) {
            while (($line = fgets($fpRead)) !== false) {
                $record = json_decode(trim($line), true);
                if ($record && !empty($record['name'])) {
                    $dedupKey = makeDedupKeyQ($record);
                    $seenKeys[$dedupKey] = true;
                    $harvestedPlaces[$dedupKey] = $record;
                }
            }
            fclose($fpRead);
        }
    }
    
    $initialCount = count($harvestedPlaces);
    $fpOut = fopen($jsonlFile, 'a');
    
    if (!$fpOut) {
        return ['success' => false, 'error' => "Cannot open $jsonlFile for writing"];
    }
    
    // Include full_harvest functions
    require_once __DIR__ . '/full_harvest.php';
    
    // Stage 1: Overpass Grid
    $added1 = harvestOverpassGrid($bbox, $seenKeys, $harvestedPlaces, $fpOut, false, 0);
    
    // Stage 2: Photon Keywords
    $regionConfig = [
        'minLat' => $bbox['minLat'],
        'maxLat' => $bbox['maxLat'],
        'minLng' => $bbox['minLng'],
        'maxLng' => $bbox['maxLng'],
        'centerLat' => ($bbox['minLat'] + $bbox['maxLat']) / 2,
        'centerLng' => ($bbox['minLng'] + $bbox['maxLng']) / 2,
        'cityKeywords' => [$regionName]
    ];
    $added2 = harvestPhotonKeywords($regionConfig, $seenKeys, $harvestedPlaces, $fpOut, false);
    
    fclose($fpOut);
    
    $totalNew = $added1 + $added2;
    $totalAll = count($harvestedPlaces);
    
    // Write summary
    $summary = [
        'region' => $regionName,
        'province_id' => $provinceId,
        'date' => date('Y-m-d H:i:s'),
        'bbox' => $bbox,
        'initial_count' => $initialCount,
        'overpass_added' => $added1,
        'photon_added' => $added2,
        'total_new' => $totalNew,
        'total_records' => $totalAll,
        'jsonl_file' => basename($jsonlFile)
    ];
    file_put_contents($summaryFile, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    return [
        'success' => true,
        'region' => $regionName,
        'new_places' => $totalNew,
        'total_places' => $totalAll,
        'file' => basename($jsonlFile)
    ];
}

// Dedup key helper (standalone to avoid conflicts with full_harvest.php)
function makeDedupKeyQ($item) {
    if (!empty($item['osm_type']) && !empty($item['osm_id'])) {
        return $item['osm_type'] . '_' . $item['osm_id'];
    }
    $cleanName = strtolower(preg_replace('/[^a-z0-9]/', '', $item['name'] ?? ''));
    $latGrid = round((float)($item['lat'] ?? 0), 4);
    $lngGrid = round((float)($item['lng'] ?? 0), 4);
    return $cleanName . '@' . $latGrid . ',' . $lngGrid;
}

// ═══════════════════════════════════════════════════════
// ACTION HANDLERS
// ═══════════════════════════════════════════════════════

switch ($action) {

// ─── LIST PROVINCES ───
case 'list_provinces':
    $regions = loadRegions();
    $provinces = $regions['provinces'] ?? [];
    
    // Enrich with harvest status
    $enriched = [];
    foreach ($provinces as $p) {
        $pid = $p['id'];
        // Check if any harvest files exist for this province's cities
        $harvestFiles = glob($dataDir . "/places_*_{$pid}_*.jsonl");
        $totalHarvested = 0;
        foreach ($harvestFiles as $f) {
            $totalHarvested += intval(exec("wc -l < \"$f\"") ?: 0);
        }
        
        $p['harvest_status'] = $totalHarvested > 0 ? 'partial' : 'pending';
        $p['harvested_places'] = $totalHarvested;
        $enriched[] = $p;
    }
    
    // Group by island
    $byIsland = [];
    foreach ($enriched as $p) {
        $island = $p['island'] ?? 'other';
        $byIsland[$island][] = $p;
    }
    
    respond([
        'success' => true,
        'total_provinces' => count($enriched),
        'island_groups' => $regions['island_groups'] ?? [],
        'by_island' => $byIsland,
        'provinces' => $enriched
    ]);
    break;

// ─── DISCOVER CITIES IN PROVINCE ───
case 'discover_cities':
    $provinceId = $_GET['province'] ?? ($jsonInput['province'] ?? '');
    if (empty($provinceId)) {
        respond(['success' => false, 'error' => 'Parameter "province" required']);
    }
    
    $regions = loadRegions();
    $province = null;
    foreach ($regions['provinces'] as $p) {
        if ($p['id'] === $provinceId) {
            $province = $p;
            break;
        }
    }
    
    if (!$province) {
        respond(['success' => false, 'error' => "Province '$provinceId' not found"]);
    }
    
    // Use pre-loaded cities if available in database, otherwise discover via Overpass/Nominatim
    if (!empty($province['cities'])) {
        $cities = $province['cities'];
    } else {
        $cities = discoverCitiesOverpass($province['bbox']);
        if (empty($cities)) {
            $cities = discoverCitiesNominatim($province['name']);
        }
    }
    
    // Check harvest status for each city
    foreach ($cities as &$c) {
        $cityKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($c['name']));
        $files = glob($dataDir . "/places_{$cityKey}_*.jsonl");
        $count = 0;
        foreach ($files as $f) {
            $fp = @fopen($f, 'r');
            if ($fp) {
                while (fgets($fp) !== false) $count++;
                fclose($fp);
            }
        }
        $c['harvested'] = $count;
        $c['status'] = $count > 0 ? 'done' : 'pending';
    }
    unset($c);
    
    respond([
        'success' => true,
        'province' => $province['name'],
        'province_id' => $provinceId,
        'total_cities' => count($cities),
        'cities' => $cities
    ]);
    break;

// ─── QUEUE ENTIRE PROVINCE ───
case 'queue_province':
    $provinceId = $_GET['province'] ?? ($jsonInput['province'] ?? '');
    if (empty($provinceId)) {
        respond(['success' => false, 'error' => 'Parameter "province" required']);
    }
    
    $regions = loadRegions();
    $province = null;
    foreach ($regions['provinces'] as $p) {
        if ($p['id'] === $provinceId) {
            $province = $p;
            break;
        }
    }
    
    if (!$province) {
        respond(['success' => false, 'error' => "Province '$provinceId' not found"]);
    }
    
    // Get cities: pre-loaded or discovered
    if (!empty($province['cities'])) {
        $cities = $province['cities'];
    } else {
        $cities = discoverCitiesOverpass($province['bbox']);
        if (empty($cities)) {
            $cities = discoverCitiesNominatim($province['name']);
        }
    }
    
    if (empty($cities)) {
        // Fallback: queue the entire province as one region
        $cities = [
            [
                'name' => $province['name'],
                'type' => 'provinsi',
                'center' => $province['center']
            ]
        ];
    }
    
    $queue = loadQueue();
    $added = 0;
    
    foreach ($cities as $city) {
        // Check if already in queue
        $exists = false;
        foreach ($queue['items'] as $item) {
            if (strtolower($item['name']) === strtolower($city['name']) && $item['province_id'] === $provinceId) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $queue['items'][] = [
                'id' => uniqid('hq_'),
                'name' => $city['name'],
                'type' => $city['type'] ?? 'kabupaten',
                'province_id' => $provinceId,
                'province_name' => $province['name'],
                'center' => $city['center'] ?? null,
                'bbox' => null, // Will be resolved when processing
                'status' => 'pending',
                'queued_at' => date('Y-m-d H:i:s'),
                'started_at' => null,
                'completed_at' => null,
                'places_found' => 0,
                'error' => null
            ];
            $added++;
        }
    }
    
    saveQueue($queue);
    
    respond([
        'success' => true,
        'province' => $province['name'],
        'cities_queued' => $added,
        'total_queue' => count($queue['items']),
        'message' => "$added kab/kota dari {$province['name']} ditambahkan ke antrian harvest"
    ]);
    break;

// ─── QUEUE SINGLE CITY ───
case 'queue_city':
    $cityName = $_GET['name'] ?? ($jsonInput['name'] ?? '');
    $provinceName = $_GET['province_name'] ?? ($jsonInput['province_name'] ?? 'Indonesia');
    $provinceId = $_GET['province_id'] ?? ($jsonInput['province_id'] ?? 'custom');
    $customBbox = $jsonInput['bbox'] ?? null;
    
    if (empty($cityName)) {
        respond(['success' => false, 'error' => 'Parameter "name" required']);
    }
    
    $queue = loadQueue();
    
    // Check duplicate
    foreach ($queue['items'] as $item) {
        if (strtolower($item['name']) === strtolower($cityName)) {
            respond(['success' => false, 'error' => "'$cityName' sudah ada di antrian"]);
        }
    }
    
    $queue['items'][] = [
        'id' => uniqid('hq_'),
        'name' => $cityName,
        'type' => 'custom',
        'province_id' => $provinceId,
        'province_name' => $provinceName,
        'center' => null,
        'bbox' => $customBbox,
        'status' => 'pending',
        'queued_at' => date('Y-m-d H:i:s'),
        'started_at' => null,
        'completed_at' => null,
        'places_found' => 0,
        'error' => null
    ];
    
    saveQueue($queue);
    
    respond([
        'success' => true,
        'message' => "'$cityName' ditambahkan ke antrian harvest",
        'total_queue' => count($queue['items'])
    ]);
    break;

// ─── QUEUE STATUS ───
case 'queue_status':
    $queue = loadQueue();
    $items = $queue['items'] ?? [];
    
    $stats = [
        'total' => count($items),
        'pending' => 0,
        'processing' => 0,
        'done' => 0,
        'error' => 0,
        'total_places' => 0
    ];
    
    foreach ($items as $item) {
        $status = $item['status'] ?? 'pending';
        if (isset($stats[$status])) $stats[$status]++;
        $stats['total_places'] += (int)($item['places_found'] ?? 0);
    }
    
    $stats['progress_pct'] = $stats['total'] > 0
        ? round(($stats['done'] / $stats['total']) * 100, 1)
        : 0;
    
    respond([
        'success' => true,
        'stats' => $stats,
        'updated_at' => $queue['updated_at'] ?? null,
        'items' => $items
    ]);
    break;

// ─── PROCESS NEXT ITEM ───
case 'process_next':
    $queue = loadQueue();
    $items = &$queue['items'];
    
    // Find next pending
    $targetIdx = -1;
    for ($i = 0; $i < count($items); $i++) {
        if (($items[$i]['status'] ?? '') === 'pending') {
            $targetIdx = $i;
            break;
        }
    }
    
    if ($targetIdx === -1) {
        respond([
            'success' => true,
            'message' => 'Tidak ada item pending di antrian',
            'all_done' => true
        ]);
    }
    
    $item = &$items[$targetIdx];
    $item['status'] = 'processing';
    $item['started_at'] = date('Y-m-d H:i:s');
    saveQueue($queue);
    
    // Resolve bbox if not available
    $bbox = $item['bbox'];
    if (!$bbox) {
        $resolved = resolveRegionBbox($item['name'], $item['province_name'] ?? 'Indonesia');
        if ($resolved && !empty($resolved['bbox'])) {
            $bbox = $resolved['bbox'];
            $item['bbox'] = $bbox;
            $item['center'] = ['lat' => $resolved['lat'], 'lng' => $resolved['lng']];
        }
    }
    
    if (!$bbox) {
        $item['status'] = 'error';
        $item['error'] = 'Tidak dapat menemukan bounding box untuk ' . $item['name'];
        $item['completed_at'] = date('Y-m-d H:i:s');
        saveQueue($queue);
        
        respond([
            'success' => false,
            'error' => $item['error'],
            'item' => $item
        ]);
    }
    
    // Run harvest
    try {
        $result = runHarvestForRegion($item['name'], $bbox, $item['province_id']);
        
        if ($result['success']) {
            $item['status'] = 'done';
            $item['places_found'] = $result['total_places'] ?? 0;
            $item['new_places'] = $result['new_places'] ?? 0;
            $item['file'] = $result['file'] ?? null;
        } else {
            $item['status'] = 'error';
            $item['error'] = $result['error'] ?? 'Unknown error';
        }
    } catch (Exception $e) {
        $item['status'] = 'error';
        $item['error'] = $e->getMessage();
    }
    
    $item['completed_at'] = date('Y-m-d H:i:s');
    saveQueue($queue);
    
    // Count remaining
    $remaining = 0;
    foreach ($items as $it) {
        if ($it['status'] === 'pending') $remaining++;
    }
    
    respond([
        'success' => true,
        'processed' => [
            'name' => $item['name'],
            'status' => $item['status'],
            'places_found' => $item['places_found'] ?? 0,
            'duration' => $item['started_at'] && $item['completed_at']
                ? (strtotime($item['completed_at']) - strtotime($item['started_at'])) . 's'
                : null
        ],
        'remaining' => $remaining,
        'all_done' => $remaining === 0
    ]);
    break;

// ─── AUTO PROCESS ALL ───
case 'process_all':
    $queue = loadQueue();
    $items = &$queue['items'];
    $results = [];
    $processed = 0;
    
    // Stream output for real-time progress
    if (!headers_sent()) {
        header('X-Accel-Buffering: no');
        header('Content-Type: application/x-ndjson');
    }
    
    ob_implicit_flush(true);
    
    for ($i = 0; $i < count($items); $i++) {
        if ($items[$i]['status'] !== 'pending') continue;
        
        $item = &$items[$i];
        $item['status'] = 'processing';
        $item['started_at'] = date('Y-m-d H:i:s');
        saveQueue($queue);
        
        // Send progress
        echo json_encode([
            'event' => 'processing',
            'name' => $item['name'],
            'index' => $processed + 1,
            'total_pending' => count(array_filter($items, fn($x) => $x['status'] === 'pending')) + 1
        ]) . "\n";
        flush();
        
        // Resolve bbox
        $bbox = $item['bbox'];
        if (!$bbox) {
            $resolved = resolveRegionBbox($item['name'], $item['province_name'] ?? 'Indonesia');
            if ($resolved && !empty($resolved['bbox'])) {
                $bbox = $resolved['bbox'];
                $item['bbox'] = $bbox;
            }
        }
        
        if (!$bbox) {
            $item['status'] = 'error';
            $item['error'] = 'Bbox not found';
            $item['completed_at'] = date('Y-m-d H:i:s');
            saveQueue($queue);
            echo json_encode(['event' => 'error', 'name' => $item['name'], 'error' => $item['error']]) . "\n";
            flush();
            continue;
        }
        
        // Run harvest
        try {
            $result = runHarvestForRegion($item['name'], $bbox, $item['province_id']);
            $item['status'] = $result['success'] ? 'done' : 'error';
            $item['places_found'] = $result['total_places'] ?? 0;
            $item['new_places'] = $result['new_places'] ?? 0;
            $item['file'] = $result['file'] ?? null;
            if (!$result['success']) $item['error'] = $result['error'] ?? 'Unknown';
        } catch (Exception $e) {
            $item['status'] = 'error';
            $item['error'] = $e->getMessage();
        }
        
        $item['completed_at'] = date('Y-m-d H:i:s');
        saveQueue($queue);
        $processed++;
        
        echo json_encode([
            'event' => 'completed',
            'name' => $item['name'],
            'status' => $item['status'],
            'places' => $item['places_found'] ?? 0,
            'processed' => $processed
        ]) . "\n";
        flush();
        
        // Brief pause between regions to be polite to APIs
        sleep(2);
    }
    
    echo json_encode([
        'event' => 'all_done',
        'total_processed' => $processed,
        'total_places' => array_sum(array_column($items, 'places_found'))
    ]) . "\n";
    exit;

// ─── CLEAR QUEUE ───
case 'clear_queue':
    $mode = $_GET['mode'] ?? ($jsonInput['mode'] ?? 'all');
    $queue = loadQueue();
    
    if ($mode === 'done') {
        $queue['items'] = array_values(array_filter($queue['items'], fn($i) => $i['status'] !== 'done'));
    } elseif ($mode === 'error') {
        // Retry errors: reset to pending
        foreach ($queue['items'] as &$item) {
            if ($item['status'] === 'error') {
                $item['status'] = 'pending';
                $item['error'] = null;
                $item['started_at'] = null;
                $item['completed_at'] = null;
            }
        }
        unset($item);
    } else {
        $queue['items'] = [];
    }
    
    saveQueue($queue);
    respond(['success' => true, 'message' => "Queue cleared (mode: $mode)", 'remaining' => count($queue['items'])]);
    break;

// ─── LIST HARVESTED REGIONS ───
case 'harvested_regions':
    $files = glob($dataDir . '/harvest_*.json');
    $harvests = [];
    
    foreach ($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        if ($data) {
            $data['summary_file'] = basename($f);
            $harvests[] = $data;
        }
    }
    
    // Sort by date desc
    usort($harvests, function($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });
    
    $totalPlaces = array_sum(array_column($harvests, 'total_records'));
    
    respond([
        'success' => true,
        'total_harvests' => count($harvests),
        'total_places' => $totalPlaces,
        'harvests' => $harvests
    ]);
    break;

// ─── REMOVE QUEUE ITEM ───
case 'remove_item':
    $itemId = $_GET['id'] ?? ($jsonInput['id'] ?? '');
    if (empty($itemId)) {
        respond(['success' => false, 'error' => 'Parameter "id" required']);
    }
    
    $queue = loadQueue();
    $queue['items'] = array_values(array_filter($queue['items'], fn($i) => $i['id'] !== $itemId));
    saveQueue($queue);
    
    respond(['success' => true, 'message' => "Item $itemId removed", 'remaining' => count($queue['items'])]);
    break;

// ─── LAUNCH BACKGROUND WORKER ───
case 'launch_worker':
    $lockFile = $dataDir . '/harvest_worker.lock';
    $statusFile = $dataDir . '/harvest_worker_status.json';
    $stopFile = $dataDir . '/harvest_worker_stop.signal';
    
    if (file_exists($stopFile)) @unlink($stopFile);
    
    // Check if worker already running
    $isRunning = false;
    $lockPid = 0;
    if (file_exists($lockFile)) {
        $lockData = json_decode(file_get_contents($lockFile), true);
        $lockPid = $lockData['pid'] ?? 0;
        if ($lockPid > 0 && isWorkerProcessAlive($lockPid)) {
            $isRunning = true;
        } else {
            @unlink($lockFile);
        }
    }
    
    if ($isRunning) {
        respond([
            'success' => true,
            'already_running' => true,
            'message' => "Worker sudah berjalan di latar belakang (PID: $lockPid)",
            'pid' => $lockPid
        ]);
    }
    
    // Clean stale lock
    if (file_exists($lockFile)) @unlink($lockFile);
    
    $workerScript = realpath(__DIR__ . '/harvest_worker.php');
    $phpBin = getPhpBinary();
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($workerScript);
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($workerScript) . ' > /dev/null 2>&1 &';
        exec($cmd);
    }
    
    // Brief sleep to allow worker to write lock
    usleep(400000);
    
    $newPid = 0;
    if (file_exists($lockFile)) {
        $ld = json_decode(file_get_contents($lockFile), true);
        $newPid = $ld['pid'] ?? 0;
    }
    
    respond([
        'success' => true,
        'message' => 'Background harvest worker berhasil diluncurkan.',
        'pid' => $newPid
    ]);
    break;

// ─── WORKER STATUS ───
case 'worker_status':
    $lockFile = $dataDir . '/harvest_worker.lock';
    $statusFile = $dataDir . '/harvest_worker_status.json';
    
    $statusData = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
    $isRunning = false;
    $lockPid = 0;
    
    if (file_exists($lockFile)) {
        $lockData = json_decode(file_get_contents($lockFile), true);
        $lockPid = $lockData['pid'] ?? 0;
        
        if ($lockPid > 0 && isWorkerProcessAlive($lockPid)) {
            $isRunning = true;
        } else {
            $recent = ($statusData && ($statusData['state'] ?? '') === 'processing' && (time() - ($statusData['updated_at_ts'] ?? 0) < 600));
            if (!$recent) {
                @unlink($lockFile);
            } else {
                $isRunning = true;
            }
        }
    } else if ($statusData && ($statusData['state'] ?? '') === 'processing') {
        $statusPid = $statusData['pid'] ?? 0;
        if ($statusPid > 0 && isWorkerProcessAlive($statusPid)) {
            $isRunning = true;
            $lockPid = $statusPid;
            @file_put_contents($lockFile, json_encode([
                'pid' => $statusPid,
                'started_at' => $statusData['started_at'] ?? date('Y-m-d H:i:s'),
                'updated_at_ts' => time()
            ], JSON_PRETTY_PRINT));
        }
    }
    if (!$statusData) {
        $statusData = [
            'state' => 'idle',
            'message' => 'Worker siap dijalankan.',
            'processed' => 0,
            'total_pending' => 0,
            'total_places' => 0
        ];
    }
    
    $queue = loadQueue();
    $pendingCount = 0;
    $doneCount = 0;
    foreach ($queue['items'] as $it) {
        if (($it['status'] ?? '') === 'pending') $pendingCount++;
        if (($it['status'] ?? '') === 'done') $doneCount++;
    }
    
    respond([
        'success' => true,
        'is_running' => $isRunning,
        'pid' => $lockPid,
        'status' => $statusData,
        'queue_summary' => [
            'total' => count($queue['items']),
            'pending' => $pendingCount,
            'done' => $doneCount
        ]
    ]);
    break;

// ─── STOP WORKER ───
case 'stop_worker':
    $lockFile = $dataDir . '/harvest_worker.lock';
    $stopFile = $dataDir . '/harvest_worker_stop.signal';
    file_put_contents($stopFile, date('Y-m-d H:i:s'));
    
    if (file_exists($lockFile)) {
        $ld = json_decode(file_get_contents($lockFile), true);
        $pid = $ld['pid'] ?? 0;
        if ($pid > 0) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                @exec("taskkill /F /PID $pid 2>NUL");
            } else {
                @exec("kill -9 $pid 2>/dev/null");
            }
        }
        @unlink($lockFile);
    }
    
    $statusFile = $dataDir . '/harvest_worker_status.json';
    if (file_exists($statusFile)) {
        $sd = json_decode(file_get_contents($statusFile), true) ?: [];
        $sd['state'] = 'stopped';
        $sd['message'] = 'Worker dihentikan oleh pengguna.';
        $sd['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($statusFile, json_encode($sd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    respond([
        'success' => true,
        'message' => 'Worker berhasil dihentikan.'
    ]);
    break;

// ─── QUEUE ALL INDONESIA ───
case 'queue_all_indonesia':
    $regions = loadRegions();
    $queue = loadQueue();
    $existingMap = [];
    foreach ($queue['items'] as $it) {
        $existingMap[strtolower($it['name']) . '@' . ($it['province_id'] ?? '')] = true;
    }
    
    $added = 0;
    foreach ($regions['provinces'] as $prov) {
        $provId = $prov['id'];
        $provName = $prov['name'];
        $cities = $prov['cities'] ?? [];
        
        if (empty($cities)) {
            $cities = [['name' => $provName, 'type' => 'provinsi', 'center' => $prov['center']]];
        }
        
        foreach ($cities as $c) {
            $key = strtolower($c['name']) . '@' . $provId;
            if (!isset($existingMap[$key])) {
                $queue['items'][] = [
                    'id' => uniqid('hq_'),
                    'name' => $c['name'],
                    'type' => $c['type'] ?? 'kabupaten',
                    'province_id' => $provId,
                    'province_name' => $provName,
                    'center' => $c['center'] ?? null,
                    'bbox' => null,
                    'status' => 'pending',
                    'queued_at' => date('Y-m-d H:i:s'),
                    'started_at' => null,
                    'completed_at' => null,
                    'places_found' => 0,
                    'error' => null
                ];
                $existingMap[$key] = true;
                $added++;
            }
        }
    }
    
    saveQueue($queue);
    
    respond([
        'success' => true,
        'cities_queued' => $added,
        'total_queue' => count($queue['items']),
        'message' => "Berhasil mengantrekan $added kab/kota se-Indonesia. Total antrean: " . count($queue['items'])
    ]);
    break;

default:
    respond([
        'success' => false,
        'error' => "Unknown action: $action",
        'available_actions' => [
            'list_provinces', 'discover_cities', 'queue_province', 'queue_city',
            'queue_all_indonesia', 'queue_status', 'process_next', 'process_all',
            'launch_worker', 'worker_status', 'stop_worker', 'clear_queue',
            'harvested_regions', 'remove_item'
        ]
    ]);
}
