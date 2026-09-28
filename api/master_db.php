<?php
/**
 * Client Reach AI — Master Database API
 * 
 * Queries harvested JSONL data files by region.
 * Supports keyword search, category filter, pagination, and statistics.
 * 
 * Endpoints:
 *   ?action=status&region=<name>        — Check if data exists for a region
 *   ?action=query&region=<name>         — Query data with filters
 *   ?action=regions                     — List all available harvested regions
 *   ?action=stats                       — Global statistics across all data
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dataDir = __DIR__ . '/../data';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':
        handleStatus($dataDir);
        break;
    case 'query':
        handleQuery($dataDir);
        break;
    case 'regions':
        handleRegions($dataDir);
        break;
    case 'stats':
        handleStats($dataDir);
        break;
    default:
        echo json_encode(['error' => 'Invalid action. Use: status, query, regions, stats']);
}

// ─────────────────────────────────────────────────────────
// ACTION: status — Check data availability for a region
// ─────────────────────────────────────────────────────────
function handleStatus($dataDir) {
    $region = $_GET['region'] ?? '';
    if (!$region) {
        echo json_encode(['error' => 'Parameter "region" is required']);
        return;
    }

    $files = findRegionFiles($dataDir, $region);
    $totalPlaces = 0;
    $categories = [];

    foreach ($files as $file) {
        $counts = countJsonlFile($file['path']);
        $totalPlaces += $counts['total'];
        foreach ($counts['categories'] as $cat => $cnt) {
            $categories[$cat] = ($categories[$cat] ?? 0) + $cnt;
        }
    }

    arsort($categories);

    echo json_encode([
        'available' => $totalPlaces > 0,
        'region' => $region,
        'total_places' => $totalPlaces,
        'files_count' => count($files),
        'files' => array_map(fn($f) => [
            'name' => $f['name'],
            'size' => $f['size'],
            'date' => $f['date']
        ], $files),
        'categories_summary' => $categories
    ]);
}

// ─────────────────────────────────────────────────────────
// ACTION: query — Query data with filters and pagination
// ─────────────────────────────────────────────────────────
function handleQuery($dataDir) {
    $region   = $_GET['region'] ?? '';
    $keyword  = trim($_GET['keyword'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $page     = max(1, intval($_GET['page'] ?? 1));
    $perPage  = min(200, max(10, intval($_GET['per_page'] ?? 50)));
    $sortBy   = $_GET['sort'] ?? 'name';
    $hasPhone = isset($_GET['has_phone']) ? filter_var($_GET['has_phone'], FILTER_VALIDATE_BOOLEAN) : null;

    if (!$region) {
        echo json_encode(['error' => 'Parameter "region" is required']);
        return;
    }

    $files = findRegionFiles($dataDir, $region);
    if (empty($files)) {
        echo json_encode([
            'data' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
            'region' => $region,
            'available' => false
        ]);
        return;
    }

    // Collect all matching records
    $allRecords = [];
    $totalRaw = 0;
    $categoryStats = [];

    foreach ($files as $file) {
        $handle = fopen($file['path'], 'r');
        if (!$handle) continue;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $record = json_decode($line, true);
            if (!$record || empty($record['name'])) continue;

            $totalRaw++;
            $recCat = strtolower($record['category'] ?? 'lainnya');
            $categoryStats[$recCat] = ($categoryStats[$recCat] ?? 0) + 1;

            // Apply filters
            if ($category && stripos($recCat, strtolower($category)) === false) continue;
            if ($hasPhone !== null) {
                $phone = trim($record['phone'] ?? '');
                if ($hasPhone && empty($phone)) continue;
                if (!$hasPhone && !empty($phone)) continue;
            }
            if ($keyword) {
                $keywordLower = strtolower($keyword);
                $searchFields = strtolower(
                    ($record['name'] ?? '') . ' ' .
                    ($record['address'] ?? '') . ' ' .
                    ($record['category'] ?? '') . ' ' .
                    ($record['tags'] ?? '')
                );
                if (strpos($searchFields, $keywordLower) === false) continue;
            }

            $allRecords[] = $record;
        }
        fclose($handle);
    }

    // Sort
    usort($allRecords, function($a, $b) use ($sortBy) {
        switch ($sortBy) {
            case 'category': return strcasecmp($a['category'] ?? '', $b['category'] ?? '');
            case 'phone': return empty($a['phone']) - empty($b['phone']);
            default: return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        }
    });

    // Paginate
    $totalFiltered = count($allRecords);
    $totalPages = ceil($totalFiltered / $perPage);
    $offset = ($page - 1) * $perPage;
    $pageData = array_slice($allRecords, $offset, $perPage);

    // Clean output
    $output = array_map(function($r) {
        return [
            'name'          => $r['name'] ?? '-',
            'address'       => $r['address'] ?? '-',
            'phone'         => $r['phone'] ?? '',
            'category'      => $r['category'] ?? 'Lainnya',
            'lat'           => floatval($r['lat'] ?? 0),
            'lng'           => floatval($r['lng'] ?? $r['lon'] ?? 0),
            'website'       => $r['website'] ?? '',
            'opening_hours' => $r['opening_hours'] ?? '',
            'tags'          => $r['tags'] ?? '',
            'osm_type'      => $r['osm_type'] ?? '',
            'osm_id'        => $r['osm_id'] ?? ''
        ];
    }, $pageData);

    arsort($categoryStats);

    echo json_encode([
        'data'            => $output,
        'total_raw'       => $totalRaw,
        'total_filtered'  => $totalFiltered,
        'page'            => $page,
        'per_page'        => $perPage,
        'total_pages'     => $totalPages,
        'region'          => $region,
        'available'       => true,
        'filters_applied' => [
            'keyword'  => $keyword ?: null,
            'category' => $category ?: null,
            'has_phone'=> $hasPhone
        ],
        'categories_summary' => $categoryStats
    ]);
}

// ─────────────────────────────────────────────────────────
// ACTION: regions — List all available harvested regions
// ─────────────────────────────────────────────────────────
function handleRegions($dataDir) {
    $pattern = $dataDir . '/places_*.jsonl';
    $files = glob($pattern);
    $regions = [];

    foreach ($files as $filePath) {
        $basename = basename($filePath);
        // Extract region name from filename: places_<region>_<date>.jsonl
        if (preg_match('/^places_(.+?)_\d{8}\.jsonl$/', $basename, $m)) {
            $regionSlug = $m[1];
            $regionName = ucwords(str_replace('_', ' ', $regionSlug));
            
            $lineCount = 0;
            $handle = fopen($filePath, 'r');
            if ($handle) {
                while (fgets($handle) !== false) $lineCount++;
                fclose($handle);
            }

            $regions[] = [
                'slug'        => $regionSlug,
                'name'        => $regionName,
                'total_places'=> $lineCount,
                'file_size'   => filesize($filePath),
                'file_date'   => date('Y-m-d', filemtime($filePath)),
                'filename'    => $basename
            ];
        }
    }

    // Sort by total_places descending
    usort($regions, fn($a, $b) => $b['total_places'] - $a['total_places']);

    echo json_encode([
        'regions' => $regions,
        'total_regions' => count($regions)
    ]);
}

// ─────────────────────────────────────────────────────────
// ACTION: stats — Global statistics across all data
// ─────────────────────────────────────────────────────────
function handleStats($dataDir) {
    $pattern = $dataDir . '/places_*.jsonl';
    $files = glob($pattern);
    
    $totalPlaces = 0;
    $totalRegions = 0;
    $totalSize = 0;
    $categories = [];

    foreach ($files as $filePath) {
        $totalRegions++;
        $totalSize += filesize($filePath);
        
        $handle = fopen($filePath, 'r');
        if (!$handle) continue;
        
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;
            $record = json_decode($line, true);
            if (!$record) continue;
            
            $totalPlaces++;
            $cat = strtolower($record['category'] ?? 'lainnya');
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        }
        fclose($handle);
    }

    arsort($categories);

    echo json_encode([
        'total_places'  => $totalPlaces,
        'total_regions' => $totalRegions,
        'total_size_mb' => round($totalSize / (1024 * 1024), 2),
        'categories'    => $categories,
        'last_updated'  => date('Y-m-d H:i:s')
    ]);
}

// ─────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────

/**
 * Find all JSONL data files matching a region name.
 * Matches against slug patterns in filenames.
 */
function findRegionFiles($dataDir, $regionName) {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $regionName));
    $slug = trim($slug, '_');
    
    $pattern = $dataDir . '/places_*.jsonl';
    $allFiles = glob($pattern);
    $matched = [];

    foreach ($allFiles as $filePath) {
        $basename = strtolower(basename($filePath));
        // Try exact slug match first
        if (strpos($basename, 'places_' . $slug . '_') !== false) {
            $matched[] = [
                'path' => $filePath,
                'name' => basename($filePath),
                'size' => filesize($filePath),
                'date' => date('Y-m-d', filemtime($filePath))
            ];
        }
    }

    // If no exact match, try partial match
    if (empty($matched)) {
        $parts = explode('_', $slug);
        foreach ($allFiles as $filePath) {
            $basename = strtolower(basename($filePath));
            $matchCount = 0;
            foreach ($parts as $part) {
                if (strlen($part) >= 3 && strpos($basename, $part) !== false) {
                    $matchCount++;
                }
            }
            if ($matchCount >= max(1, count($parts) - 1)) {
                $matched[] = [
                    'path' => $filePath,
                    'name' => basename($filePath),
                    'size' => filesize($filePath),
                    'date' => date('Y-m-d', filemtime($filePath))
                ];
            }
        }
    }

    return $matched;
}

/**
 * Count lines and category distribution in a JSONL file.
 */
function countJsonlFile($filePath) {
    $total = 0;
    $categories = [];

    $handle = fopen($filePath, 'r');
    if (!$handle) return ['total' => 0, 'categories' => []];

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if (empty($line)) continue;
        $record = json_decode($line, true);
        if (!$record) continue;
        
        $total++;
        $cat = strtolower($record['category'] ?? 'lainnya');
        $categories[$cat] = ($categories[$cat] ?? 0) + 1;
    }
    fclose($handle);

    return ['total' => $total, 'categories' => $categories];
}
