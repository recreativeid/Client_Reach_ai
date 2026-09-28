<?php
/**
 * Client Reach AI - Background Harvest Worker
 * 
 * Runs as a background PHP process to avoid browser timeout.
 * Called by harvest_queue.php via proc_open() or popen().
 * Writes progress to data/harvest_worker_status.json for frontend polling.
 * 
 * Usage (CLI):
 *   php harvest_worker.php
 *   → Processes all pending items in the queue sequentially
 */

set_time_limit(0);
ini_set('memory_limit', '1024M');
ignore_user_abort(true);

// Directories
$dataDir   = __DIR__ . '/../data';
$cacheDir  = $dataDir . '/cache';
$queueFile = $dataDir . '/harvest_queue.json';
$statusFile = $dataDir . '/harvest_worker_status.json';
$lockFile  = $dataDir . '/harvest_worker.lock';

foreach ([$dataDir, $cacheDir] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}

// ─── LOCK: Prevent multiple workers running simultaneously ───
if (file_exists($lockFile)) {
    $lockData = json_decode(file_get_contents($lockFile), true);
    $lockPid = $lockData['pid'] ?? 0;
    $lockTime = $lockData['started_at_ts'] ?? 0;
    
    // If lock is older than 30 minutes, assume stale and remove
    if (time() - $lockTime > 1800) {
        @unlink($lockFile);
    } else {
        // Check if the PID is still running (Windows-compatible)
        $isRunning = false;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("tasklist /FI \"PID eq $lockPid\" 2>NUL", $output);
            foreach ($output as $line) {
                if (strpos($line, (string)$lockPid) !== false) {
                    $isRunning = true;
                    break;
                }
            }
        } else {
            $isRunning = file_exists("/proc/$lockPid");
        }
        
        if ($isRunning) {
            writeStatus(['state' => 'error', 'error' => 'Worker sudah berjalan (PID: ' . $lockPid . ')']);
            exit(1);
        }
        @unlink($lockFile);
    }
}

// Create lock file
file_put_contents($lockFile, json_encode([
    'pid' => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
    'started_at_ts' => time()
], JSON_PRETTY_PRINT));

// ─── STATUS WRITER ───
function writeStatus($data) {
    global $statusFile, $lockFile;
    $data['updated_at'] = date('Y-m-d H:i:s');
    $data['updated_at_ts'] = time();
    file_put_contents($statusFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    if (!empty($lockFile)) {
        @file_put_contents($lockFile, json_encode([
            'pid' => getmypid(),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_at_ts' => time()
        ], JSON_PRETTY_PRINT));
    }
}

// ─── QUEUE HELPERS ───
function loadQueue() {
    global $queueFile;
    if (!file_exists($queueFile)) {
        return ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'), 'items' => []];
    }
    return json_decode(file_get_contents($queueFile), true) ?: ['items' => []];
}

function saveQueue($queue) {
    global $queueFile;
    $queue['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ─── DEDUP HELPER ───
function makeDedupKeyW($item) {
    if (!empty($item['osm_type']) && !empty($item['osm_id'])) {
        return $item['osm_type'] . '_' . $item['osm_id'];
    }
    $cleanName = strtolower(preg_replace('/[^a-z0-9]/', '', $item['name'] ?? ''));
    $latGrid = round((float)($item['lat'] ?? 0), 4);
    $lngGrid = round((float)($item['lng'] ?? 0), 4);
    return $cleanName . '@' . $latGrid . ',' . $lngGrid;
}

// ─── NOMINATIM BBOX RESOLVER ───
function resolveRegionBboxW($regionName, $provinceName = 'Indonesia') {
    global $cacheDir;
    
    $cacheKey = md5(strtolower($regionName . '_' . $provinceName));
    $cacheFile = $cacheDir . "/bbox_{$cacheKey}.json";
    
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && time() - ($cached['cached_at'] ?? 0) < 86400 * 30) {
            return $cached;
        }
    }
    
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
    
    if ($httpCode !== 200 || empty($res)) return null;
    
    $results = json_decode($res, true);
    if (empty($results)) return null;
    
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
        'cached_at' => time()
    ];
    
    file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $result;
}

// ─── MAIN WORKER LOOP ───
writeStatus([
    'state' => 'starting',
    'pid' => getmypid(),
    'message' => 'Worker dimulai, memuat antrean...',
    'processed' => 0,
    'total_pending' => 0,
    'current_region' => null,
    'total_places' => 0,
    'started_at' => date('Y-m-d H:i:s')
]);

// Include the full_harvest functions
require_once __DIR__ . '/full_harvest.php';

$queue = loadQueue();
$items = &$queue['items'];

// Count pending
$totalPending = 0;
foreach ($items as $item) {
    if (($item['status'] ?? '') === 'pending') $totalPending++;
}

if ($totalPending === 0) {
    writeStatus([
        'state' => 'idle',
        'message' => 'Tidak ada item pending di antrean.',
        'processed' => 0,
        'total_pending' => 0,
        'total_places' => 0
    ]);
    @unlink($lockFile);
    exit(0);
}

$processed = 0;
$totalPlacesAll = 0;

for ($i = 0; $i < count($items); $i++) {
    if (($items[$i]['status'] ?? '') !== 'pending') continue;
    
    // Check for stop signal
    $stopFile = $dataDir . '/harvest_worker_stop.signal';
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        writeStatus([
            'state' => 'stopped',
            'message' => 'Worker dihentikan oleh pengguna.',
            'processed' => $processed,
            'total_pending' => $totalPending - $processed,
            'total_places' => $totalPlacesAll,
            'current_region' => null
        ]);
        @unlink($lockFile);
        exit(0);
    }
    
    $item = &$items[$i];
    $item['status'] = 'processing';
    $item['started_at'] = date('Y-m-d H:i:s');
    saveQueue($queue);
    
    writeStatus([
        'state' => 'processing',
        'pid' => getmypid(),
        'message' => "Memanen: {$item['name']}...",
        'processed' => $processed,
        'total_pending' => $totalPending,
        'current_region' => $item['name'],
        'current_province' => $item['province_name'] ?? '',
        'total_places' => $totalPlacesAll,
        'started_at' => date('Y-m-d H:i:s')
    ]);
    
    // Resolve bbox if needed
    $bbox = $item['bbox'];
    if (!$bbox) {
        $resolved = resolveRegionBboxW($item['name'], $item['province_name'] ?? 'Indonesia');
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
        
        writeStatus([
            'state' => 'processing',
            'message' => "⚠️ Gagal resolve bbox: {$item['name']}",
            'processed' => ++$processed,
            'total_pending' => $totalPending,
            'current_region' => null,
            'total_places' => $totalPlacesAll,
            'last_result' => ['name' => $item['name'], 'status' => 'error', 'error' => $item['error']]
        ]);
        continue;
    }
    
    // Run harvest
    try {
        $dateStr = date('Ymd');
        $regionKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($item['name']));
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
                        $dedupKey = makeDedupKeyW($record);
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
            throw new Exception("Cannot open $jsonlFile for writing");
        }
        
        // Stage 1: Overpass Grid
        $added1 = harvestOverpassGrid($bbox, $seenKeys, $harvestedPlaces, $fpOut, false, 0);
        
        // Update status mid-process
        writeStatus([
            'state' => 'processing',
            'message' => "{$item['name']}: Overpass selesai +{$added1}, memulai Photon...",
            'processed' => $processed,
            'total_pending' => $totalPending,
            'current_region' => $item['name'],
            'current_province' => $item['province_name'] ?? '',
            'total_places' => $totalPlacesAll + count($harvestedPlaces),
            'current_stage' => 'photon'
        ]);
        
        // Stage 2: Photon Keywords
        $regionConfig = [
            'minLat' => $bbox['minLat'],
            'maxLat' => $bbox['maxLat'],
            'minLng' => $bbox['minLng'],
            'maxLng' => $bbox['maxLng'],
            'centerLat' => ($bbox['minLat'] + $bbox['maxLat']) / 2,
            'centerLng' => ($bbox['minLng'] + $bbox['maxLng']) / 2,
            'cityKeywords' => [$item['name']]
        ];
        $added2 = harvestPhotonKeywords($regionConfig, $seenKeys, $harvestedPlaces, $fpOut, false);
        
        fclose($fpOut);
        
        $totalNew = $added1 + $added2;
        $totalAll = count($harvestedPlaces);
        
        // Write summary
        $summary = [
            'region' => $item['name'],
            'province_id' => $item['province_id'] ?? '',
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
        
        $item['status'] = 'done';
        $item['places_found'] = $totalAll;
        $item['new_places'] = $totalNew;
        $item['file'] = basename($jsonlFile);
        
        $totalPlacesAll += $totalAll;
        
    } catch (Exception $e) {
        $item['status'] = 'error';
        $item['error'] = $e->getMessage();
    }
    
    $item['completed_at'] = date('Y-m-d H:i:s');
    saveQueue($queue);
    $processed++;
    
    writeStatus([
        'state' => 'processing',
        'message' => "✓ {$item['name']}: {$item['places_found']} data terpanen.",
        'processed' => $processed,
        'total_pending' => $totalPending,
        'current_region' => null,
        'total_places' => $totalPlacesAll,
        'last_result' => [
            'name' => $item['name'],
            'status' => $item['status'],
            'places' => $item['places_found'] ?? 0,
            'new' => $item['new_places'] ?? 0,
            'duration' => $item['started_at'] && $item['completed_at']
                ? (strtotime($item['completed_at']) - strtotime($item['started_at'])) . 's'
                : null
        ]
    ]);
    
    // Brief pause between regions
    sleep(2);
}

// All done
writeStatus([
    'state' => 'completed',
    'message' => "🎉 Seluruh antrean selesai! {$processed} wilayah diproses, {$totalPlacesAll} bisnis terpanen.",
    'processed' => $processed,
    'total_pending' => 0,
    'current_region' => null,
    'total_places' => $totalPlacesAll,
    'completed_at' => date('Y-m-d H:i:s')
]);

@unlink($lockFile);
exit(0);
