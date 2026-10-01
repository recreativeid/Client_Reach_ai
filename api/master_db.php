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
    case 'tree_stats':
        handleTreeStats($dataDir);
        break;
    case 'stats':
        handleStats($dataDir);
        break;
    default:
        echo json_encode(['error' => 'Invalid action. Use: status, query, regions, stats, tree_stats']);
}

/**
 * Get active database connection: Supabase Cloud PostgreSQL first, fallback to SQLite
 */
function getMasterActiveDb() {
    require_once __DIR__ . '/db_manager.php';
    try {
        $primary = getPrimaryDb();
        if ($primary) return $primary;
    } catch (Exception $e) {}
    return getSqliteDb();
}

/**
 * Resolve bounding box for subdistrict / kecamatan using curated DB or quadrant heuristic
 */
function resolveSubdistrictBounds($cleanCity, $cleanKec, $dataDir) {
    if (empty($cleanKec)) return null;

    $cleanKec = trim(preg_replace('/^(kecamatan|kec\.)\s+/i', '', $cleanKec));
    $cleanCity = trim(preg_replace('/^(kabupaten|kota|kab\.|adm\.)\s+/i', '', $cleanCity));

    $curatedFile = $dataDir . '/curated_regions_bbox.json';
    $curData = null;
    if (file_exists($curatedFile)) {
        $curData = json_decode(file_get_contents($curatedFile), true);
        if (!empty($curData['cities'])) {
            $cKecLower = strtolower(trim($cleanKec));
            $cCityLower = strtolower(trim($cleanCity));

            // 1. Direct name match
            foreach ($curData['cities'] as $key => $item) {
                $itemName = strtolower($item['name'] ?? '');
                if ($itemName === $cKecLower || $itemName === "$cCityLower $cKecLower" || $itemName === "$cKecLower $cCityLower") {
                    if (!empty($item['bbox'])) return $item['bbox'];
                }
            }

            // 2. Substring matching with city context
            foreach ($curData['cities'] as $key => $item) {
                $itemName = strtolower($item['name'] ?? '');
                if (strpos($itemName, $cKecLower) !== false && (empty($cCityLower) || strpos($itemName, $cCityLower) !== false || strpos($key, $cCityLower) !== false)) {
                    if (!empty($item['bbox'])) return $item['bbox'];
                }
            }

            // 3. Fallback: match cleanKec alone
            foreach ($curData['cities'] as $key => $item) {
                $itemName = strtolower($item['name'] ?? '');
                if (strpos($itemName, $cKecLower) !== false) {
                    if (!empty($item['bbox'])) return $item['bbox'];
                }
            }
        }
    }

    // 4. Directional offset heuristic fallback (Utara, Selatan, Barat, Timur, Tengah) relative to city center
    $cityBbox = null;
    if (!empty($curData['cities'])) {
        $cCityLower = strtolower(trim($cleanCity));
        foreach ($curData['cities'] as $key => $item) {
            $itemName = strtolower($item['name'] ?? '');
            if ($itemName === $cCityLower || strpos($itemName, $cCityLower) !== false) {
                $cityBbox = $item['bbox'] ?? null;
                break;
            }
        }
    }

    if ($cityBbox) {
        $midLat = ($cityBbox['minLat'] + $cityBbox['maxLat']) / 2;
        $midLng = ($cityBbox['minLng'] + $cityBbox['maxLng']) / 2;
        $dLat = abs($cityBbox['maxLat'] - $cityBbox['minLat']);
        $dLng = abs($cityBbox['maxLng'] - $cityBbox['minLng']);

        if (stripos($cleanKec, 'utara') !== false) {
            return [
                'minLat' => $midLat - ($dLat * 0.1),
                'maxLat' => $cityBbox['maxLat'],
                'minLng' => $cityBbox['minLng'],
                'maxLng' => $cityBbox['maxLng']
            ];
        } elseif (stripos($cleanKec, 'selatan') !== false) {
            return [
                'minLat' => $cityBbox['minLat'],
                'maxLat' => $midLat + ($dLat * 0.1),
                'minLng' => $cityBbox['minLng'],
                'maxLng' => $cityBbox['maxLng']
            ];
        } elseif (stripos($cleanKec, 'barat') !== false) {
            return [
                'minLat' => $cityBbox['minLat'],
                'maxLat' => $cityBbox['maxLat'],
                'minLng' => $cityBbox['minLng'],
                'maxLng' => $midLng + ($dLng * 0.1)
            ];
        } elseif (stripos($cleanKec, 'timur') !== false) {
            return [
                'minLat' => $cityBbox['minLat'],
                'maxLat' => $cityBbox['maxLat'],
                'minLng' => $midLng - ($dLng * 0.1),
                'maxLng' => $cityBbox['maxLng']
            ];
        } elseif (stripos($cleanKec, 'tengah') !== false || stripos($cleanKec, 'pusat') !== false) {
            return [
                'minLat' => $midLat - ($dLat * 0.25),
                'maxLat' => $midLat + ($dLat * 0.25),
                'minLng' => $midLng - ($dLng * 0.25),
                'maxLng' => $midLng + ($dLng * 0.25)
            ];
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────
// ACTION: status — Check data availability for a region
// ─────────────────────────────────────────────────────────
function handleStatus($dataDir) {
    $rawRegion = $_GET['region'] ?? '';
    $region = trim(preg_replace('/\s*\[.*?\]\s*/', '', (string)$rawRegion));
    $kecamatan = trim($_GET['kecamatan'] ?? ($_GET['subdistrict'] ?? ''));
    if (!$region) {
        echo json_encode(['error' => 'Parameter "region" is required']);
        return;
    }

    // 1. Check Primary Cloud Database (Supabase) or SQLite (Fast & Indexed)
    try {
        $db = getMasterActiveDb();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $likeOp = ($driver === 'pgsql') ? 'ILIKE' : 'LIKE';

        $cleanCity = preg_replace('/^(kabupaten|kota|kab\.|adm\.)\s+/i', '', $region);
        $cleanCity = trim(explode(',', $cleanCity)[0]);

        $where = ["(city $likeOp ? OR province $likeOp ? OR address $likeOp ?)"];
        $params = ["%$cleanCity%", "%$cleanCity%", "%$cleanCity%"];

        // Support kecamatan / subdistrict filtering
        if (!empty($kecamatan) && strpos($kecamatan, '--') !== 0) {
            $cleanKec = preg_replace('/^(kecamatan|kec\.)\s+/i', '', $kecamatan);
            // Check if bbox is provided
            $minLat = isset($_GET['minLat']) ? (float)$_GET['minLat'] : null;
            $maxLat = isset($_GET['maxLat']) ? (float)$_GET['maxLat'] : null;
            $minLng = isset($_GET['minLng']) ? (float)$_GET['minLng'] : null;
            $maxLng = isset($_GET['maxLng']) ? (float)$_GET['maxLng'] : null;

            if ($minLat === null || $maxLat === null || $minLng === null || $maxLng === null) {
                $resolvedKecBbox = resolveSubdistrictBounds($cleanCity, $cleanKec, $dataDir);
                if ($resolvedKecBbox) {
                    $minLat = $resolvedKecBbox['minLat'];
                    $maxLat = $resolvedKecBbox['maxLat'];
                    $minLng = $resolvedKecBbox['minLng'];
                    $maxLng = $resolvedKecBbox['maxLng'];
                }
            }

            if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
                $where[] = "((lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?) OR address $likeOp ? OR name $likeOp ?)";
                $params[] = $minLat;
                $params[] = $maxLat;
                $params[] = $minLng;
                $params[] = $maxLng;
                $params[] = "%$cleanKec%";
                $params[] = "%$cleanKec%";
            } else {
                $where[] = "(address $likeOp ? OR name $likeOp ?)";
                $params[] = "%$cleanKec%";
                $params[] = "%$cleanKec%";
            }
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $db->prepare("SELECT COUNT(*) FROM harvested_places WHERE $whereSql");
        $stmt->execute($params);
        $dbCount = (int)$stmt->fetchColumn();

        // Also get overall city total
        $overallStmt = $db->prepare("SELECT COUNT(*) FROM harvested_places WHERE city $likeOp ? OR province $likeOp ? OR address $likeOp ?");
        $overallStmt->execute(["%$cleanCity%", "%$cleanCity%", "%$cleanCity%"]);
        $overallCityTotal = (int)$overallStmt->fetchColumn();

        if ($dbCount > 0 || $overallCityTotal > 0) {
            $catStmt = $db->prepare("SELECT COALESCE(sector, category_name, 'Lainnya') as cat, COUNT(*) as cnt 
                                        FROM harvested_places 
                                        WHERE $whereSql
                                        GROUP BY cat ORDER BY cnt DESC");
            $catStmt->execute($params);
            $categories = [];
            while ($row = $catStmt->fetch()) {
                $catName = $row['cat'] ?: 'Lainnya';
                $categories[$catName] = (int)$row['cnt'];
            }

            $effectiveTotal = (!empty($kecamatan) && strpos($kecamatan, '--') !== 0) ? $dbCount : $overallCityTotal;

            $dbPath = __DIR__ . '/../database/client_reach.db';
            echo json_encode([
                'available' => true,
                'region' => $region,
                'subdistrict' => (!empty($kecamatan) && strpos($kecamatan, '--') !== 0) ? $kecamatan : null,
                'total_places' => $effectiveTotal,
                'subdistrict_places' => $dbCount,
                'city_total_places' => $overallCityTotal,
                'source' => ($driver === 'pgsql') ? 'supabase_cloud' : 'sqlite_database',
                'files_count' => 1,
                'files' => [[
                    'name' => ($driver === 'pgsql') ? 'Supabase PostgreSQL Cloud' : 'database/client_reach.db',
                    'size' => file_exists($dbPath) ? filesize($dbPath) : 0,
                    'date' => date('Y-m-d')
                ]],
                'categories_summary' => $categories
            ]);
            return;
        }
    } catch (Exception $e) {
        // Fall back to JSONL files
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
        'subdistrict' => $kecamatan ?: null,
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
    $rawRegion = $_GET['region'] ?? '';
    $region    = trim(preg_replace('/\s*\[.*?\]\s*/', '', (string)$rawRegion));
    $province  = trim($_GET['province'] ?? '');
    $kecamatan = trim($_GET['kecamatan'] ?? ($_GET['subdistrict'] ?? ''));
    $keyword   = trim($_GET['keyword'] ?? '');
    $category  = trim($_GET['category'] ?? '');
    $page      = max(1, intval($_GET['page'] ?? 1));
    $perPage   = min(200, max(10, intval($_GET['per_page'] ?? 50)));
    $sortBy    = $_GET['sort'] ?? 'name';
    $hasPhone  = isset($_GET['has_phone']) ? filter_var($_GET['has_phone'], FILTER_VALIDATE_BOOLEAN) : null;

    if (!$region && !$province) {
        echo json_encode(['error' => 'Parameter "region" or "province" is required']);
        return;
    }

    // 1. Try Primary Cloud Database (Supabase) or SQLite (Instant, Indexed, Portable)
    try {
        $db = getMasterActiveDb();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $likeOp = ($driver === 'pgsql') ? 'ILIKE' : 'LIKE';
        
        $where = [];
        $params = [];

        if (!empty($province) && empty($region)) {
            $cleanProv = preg_replace('/^(provinsi|prov\.)\s+/i', '', $province);
            $cleanProv = trim($cleanProv);
            $where[] = "(province $likeOp ?)";
            $params[] = "%$cleanProv%";
        } elseif (!empty($region)) {
            $cleanCity = preg_replace('/^(kabupaten|kota|kab\.|adm\.)\s+/i', '', $region);
            $cleanCity = trim(explode(',', $cleanCity)[0]);
            $where[] = "(city $likeOp ? OR province $likeOp ? OR address $likeOp ?)";
            $params[] = "%$cleanCity%";
            $params[] = "%$cleanCity%";
            $params[] = "%$cleanCity%";
        }

        // Support kecamatan / subdistrict filtering with exact/like matching and spatial coordinates
        if (!empty($kecamatan) && strpos($kecamatan, '--') !== 0) {
            $cleanKec = preg_replace('/^(kecamatan|kec\.)\s+/i', '', $kecamatan);
            $cleanKec = trim($cleanKec);
            $where[] = "(subdistrict $likeOp ? OR address $likeOp ? OR name $likeOp ?)";
            $params[] = "%$cleanKec%";
            $params[] = "%$cleanKec%";
            $params[] = "%$cleanKec%";
        }

        if (!empty($keyword)) {
            $where[] = "(name $likeOp ? OR address $likeOp ? OR category_name $likeOp ?)";
            $params[] = "%$keyword%";
            $params[] = "%$keyword%";
            $params[] = "%$keyword%";
        }
        if (!empty($category)) {
            $where[] = "(sector $likeOp ? OR subsector $likeOp ? OR category_name $likeOp ?)";
            $params[] = "%$category%";
            $params[] = "%$category%";
            $params[] = "%$category%";
        }
        $phoneParam = $_GET['has_phone'] ?? null;
        if ($phoneParam === 'true' || $phoneParam === '1') {
            $where[] = "(phone IS NOT NULL AND phone != '' AND phone != '-')";
        } elseif ($phoneParam === 'false' || $phoneParam === '0') {
            $where[] = "(phone IS NULL OR phone = '' OR phone = '-')";
        } elseif ($phoneParam === 'wa') {
            $where[] = "(phone $likeOp '08%' OR phone $likeOp '+628%' OR phone $likeOp '628%')";
        }

        $whereSql = implode(' AND ', $where);

        // Performance Optimization: Skip full table counts and category group by if navigating pages
        $skipMeta = (!empty($_GET['skip_meta']) || ($page > 1 && isset($_GET['known_total'])));

        if ($skipMeta && isset($_GET['known_total'])) {
            $total = (int)$_GET['known_total'];
            $overallCityTotal = (int)($_GET['known_city_total'] ?? $total);
            $catSummary = [];
        } else {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM harvested_places WHERE $whereSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $targetTerm = !empty($cleanCity) ? $cleanCity : (!empty($cleanProv) ? $cleanProv : '');
            $overallStmt = $db->prepare("SELECT COUNT(*) FROM harvested_places WHERE city $likeOp ? OR province $likeOp ?");
            $overallStmt->execute(["%$targetTerm%", "%$targetTerm%"]);
            $overallCityTotal = (int)$overallStmt->fetchColumn();

            // Compute category breakdown for the filtered results (initial query only)
            $catStmt = $db->prepare("SELECT COALESCE(sector, 'lainnya') as cat, COUNT(*) as cnt FROM harvested_places WHERE $whereSql GROUP BY cat ORDER BY cnt DESC");
            $catStmt->execute($params);
            $catSummary = [];
            while ($crow = $catStmt->fetch()) {
                $catSummary[$crow['cat']] = (int)$crow['cnt'];
            }
        }

        if ($total > 0 || $overallCityTotal > 0) {
            $orderSql = "ORDER BY name ASC";
            if ($sortBy === 'category') $orderSql = "ORDER BY category_name ASC";
            if ($sortBy === 'phone') $orderSql = "ORDER BY (phone != '' AND phone IS NOT NULL) DESC, name ASC";

            $offset = ($page - 1) * $perPage;
            $queryStmt = $db->prepare("SELECT * FROM harvested_places WHERE $whereSql $orderSql LIMIT $perPage OFFSET $offset");
            $queryStmt->execute($params);
            $records = $queryStmt->fetchAll();

            // Map fields to match UI expectations
            $formattedRecords = [];
            foreach ($records as $r) {
                $formattedRecords[] = [
                    'osm_id' => $r['osm_id'],
                    'name' => $r['name'],
                    'sector' => $r['sector'],
                    'subsector' => $r['subsector'],
                    'category' => $r['category_name'] ?: ($r['sector'] ?: 'Lainnya'),
                    'category_name' => $r['category_name'],
                    'lat' => (float)$r['lat'],
                    'lng' => (float)$r['lng'],
                    'address' => $r['address'],
                    'subdistrict' => $r['subdistrict'] ?? '',
                    'city' => $r['city'],
                    'province' => $r['province'],
                    'phone' => $r['phone'],
                    'website' => $r['website'],
                    'opening_hours' => $r['opening_hours'],
                    'source' => $r['source']
                ];
            }

            echo json_encode([
                'source' => ($driver === 'pgsql') ? 'supabase_cloud' : 'sqlite_database',
                'data' => $formattedRecords,
                'total' => $total,
                'city_total_places' => $overallCityTotal,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => max(1, ceil($total / $perPage)),
                'region' => $region,
                'subdistrict' => (!empty($kecamatan) && strpos($kecamatan, '--') !== 0) ? $kecamatan : null,
                'categories_summary' => $catSummary,
                'available' => true
            ]);
            return;
        }
    } catch (Exception $e) {
        // Fall back to JSONL files
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
            $recCat = $record['category'] ?? ($record['category_name'] ?? 'Lainnya');
            $record['category'] = $recCat;

            list($phone, $website, $hours) = enrichContactInfo(
                $record['name'] ?? '',
                $recCat,
                $record['address'] ?? $region,
                $record['phone'] ?? '',
                $record['website'] ?? '',
                $record['opening_hours'] ?? ''
            );
            $record['phone'] = $phone;
            $record['website'] = $website;
            $record['opening_hours'] = $hours;

            $recCatLower = strtolower($recCat);
            $categoryStats[$recCatLower] = ($categoryStats[$recCatLower] ?? 0) + 1;

            // Apply filters
            if ($category && stripos($recCatLower, strtolower($category)) === false) continue;
            if ($hasPhone !== null) {
                $checkPhone = trim($record['phone'] ?? '');
                if ($hasPhone && (empty($checkPhone) || $checkPhone === '-')) continue;
                if (!$hasPhone && (!empty($checkPhone) && $checkPhone !== '-')) continue;
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
    $regionsMap = [];

    // 1. Load regions from SQLite Database (Fast & Instant)
    try {
        require_once __DIR__ . '/db_manager.php';
        $sqlite = getSqliteDb();
        $stmt = $sqlite->query("SELECT city, province, COUNT(*) as cnt, MAX(scraped_at) as last_scraped 
                               FROM harvested_places 
                               WHERE city IS NOT NULL AND city != '' 
                               GROUP BY city ORDER BY cnt DESC");
        while ($row = $stmt->fetch()) {
            $cityName = $row['city'];
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $cityName));
            $regionsMap[$slug] = [
                'slug'         => $slug,
                'name'         => $cityName,
                'province'     => $row['province'] ?? '',
                'total_places' => (int)$row['cnt'],
                'file_size'    => 0,
                'file_date'    => substr($row['last_scraped'] ?? date('Y-m-d'), 0, 10),
                'filename'     => 'database/client_reach.db',
                'source'       => 'database'
            ];
        }
    } catch (Exception $e) {}

    // 2. Merge with any standalone JSONL files
    $pattern = $dataDir . '/places_*.jsonl';
    $files = glob($pattern);

    foreach ($files as $filePath) {
        $basename = basename($filePath);
        if (preg_match('/^places_(.+?)_\d{8}\.jsonl$/', $basename, $m)) {
            $regionSlug = $m[1];
            $regionName = ucwords(str_replace('_', ' ', $regionSlug));
            
            $lineCount = 0;
            $handle = fopen($filePath, 'r');
            if ($handle) {
                while (fgets($handle) !== false) $lineCount++;
                fclose($handle);
            }

            if (!isset($regionsMap[$regionSlug]) || $regionsMap[$regionSlug]['total_places'] < $lineCount) {
                $regionsMap[$regionSlug] = [
                    'slug'        => $regionSlug,
                    'name'        => $regionName,
                    'province'    => $regionsMap[$regionSlug]['province'] ?? '',
                    'total_places'=> $lineCount,
                    'file_size'   => filesize($filePath),
                    'file_date'   => date('Y-m-d', filemtime($filePath)),
                    'filename'    => $basename,
                    'source'      => 'jsonl'
                ];
            }
        }
    }

    $regions = array_values($regionsMap);
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
    // 1. Try SQLite Database first (Millisecond response)
    try {
        require_once __DIR__ . '/db_manager.php';
        $sqlite = getSqliteDb();
        $totalPlaces = (int)$sqlite->query("SELECT COUNT(*) FROM harvested_places")->fetchColumn();
        $totalRegions = (int)$sqlite->query("SELECT COUNT(DISTINCT city) FROM harvested_places WHERE city IS NOT NULL AND city != ''")->fetchColumn();
        
        $dbPath = __DIR__ . '/../database/client_reach.db';
        $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;

        $catStmt = $sqlite->query("SELECT COALESCE(sector, 'Lainnya') as sec, COUNT(*) as cnt FROM harvested_places GROUP BY sec ORDER BY cnt DESC");
        $categories = [];
        while ($r = $catStmt->fetch()) {
            $categories[$r['sec']] = (int)$r['cnt'];
        }

        if ($totalPlaces > 0) {
            echo json_encode([
                'total_places'  => $totalPlaces,
                'total_regions' => $totalRegions,
                'total_size_mb' => round($dbSize / (1024 * 1024), 2),
                'categories'    => $categories,
                'source'        => 'sqlite_database',
                'last_updated'  => date('Y-m-d H:i:s')
            ]);
            return;
        }
    } catch (Exception $e) {}

    // 2. Fallback to JSONL files
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
        'source'        => 'jsonl',
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
    // Clean bracketed badges such as [Database Siap: 906] or other suffixes
    $cleanName = preg_replace('/\s*\[.*?\]\s*/', '', (string)$regionName);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $cleanName));
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

/**
 * Intelligent Business Contact & Operational Hours Enrichment
 */
function enrichContactInfo($name, $category = '', $address = '', $phone = '', $website = '', $hours = '') {
    $seed = abs(crc32(strtolower(trim((string)$name)) . '|' . strtolower(trim((string)$address))));

    // 1. Phone / WhatsApp — ONLY use real data from source, DO NOT generate fake phone numbers!
    $phone = trim((string)$phone);
    if (empty($phone) || $phone === '-' || $phone === 'null' || strlen($phone) < 6) {
        $phone = '';
    }

    // 2. Website / Social Media — ONLY use real website from source, DO NOT fabricate fake domains!
    $website = trim((string)$website);
    if (empty($website) || $website === '-' || $website === 'null' || !preg_match('/\./', $website)) {
        $website = '';
    }

    // 3. Operating Hours — ONLY use real hours from source, DO NOT fabricate fake schedules!
    $hours = trim((string)$hours);
    if (empty($hours) || $hours === '-' || $hours === 'null') {
        $hours = '';
    }

    return [$phone, $website, $hours];
}

// ─────────────────────────────────────────────────────────
// ACTION: tree_stats — Return Indonesian 38 Provinces Folder Tree
// ─────────────────────────────────────────────────────────
function handleTreeStats($dataDir) {
    $officialProvinces = [
        'Aceh', 'Sumatera Utara', 'Sumatera Barat', 'Riau', 'Kepulauan Riau',
        'Jambi', 'Sumatera Selatan', 'Kepulauan Bangka Belitung', 'Bengkulu', 'Lampung',
        'DKI Jakarta', 'Jawa Barat', 'Banten', 'Jawa Tengah', 'DI Yogyakarta', 'Jawa Timur',
        'Bali', 'Nusa Tenggara Barat', 'Nusa Tenggara Timur',
        'Kalimantan Barat', 'Kalimantan Tengah', 'Kalimantan Selatan', 'Kalimantan Timur', 'Kalimantan Utara',
        'Sulawesi Utara', 'Gorontalo', 'Sulawesi Tengah', 'Sulawesi Barat', 'Sulawesi Selatan', 'Sulawesi Tenggara',
        'Maluku', 'Maluku Utara',
        'Papua', 'Papua Barat', 'Papua Selatan', 'Papua Tengah', 'Papua Pegunungan', 'Papua Barat Daya'
    ];

    $tree = [];
    foreach ($officialProvinces as $prov) {
        $tree[$prov] = [
            'province' => $prov,
            'total_places' => 0,
            'cities' => []
        ];
    }

    // Populate baseline cities from indonesia_regions.json so all provinces have their official folders
    $indFile = $dataDir . '/indonesia_regions.json';
    if (file_exists($indFile)) {
        $indData = json_decode(file_get_contents($indFile), true);
        if (!empty($indData['provinces'])) {
            foreach ($indData['provinces'] as $ip) {
                $pName = trim($ip['name']);
                $targetProv = $pName;
                if (!isset($tree[$targetProv])) {
                    foreach ($officialProvinces as $op) {
                        if (strcasecmp($op, $pName) === 0 || stripos($pName, $op) !== false) {
                            $targetProv = $op;
                            break;
                        }
                    }
                }
                if (isset($tree[$targetProv]) && !empty($ip['cities'])) {
                    foreach ($ip['cities'] as $ic) {
                        $cName = trim($ic['name']);
                        if (!isset($tree[$targetProv]['cities'][$cName])) {
                            $tree[$targetProv]['cities'][$cName] = [
                                'name' => $cName,
                                'total_places' => 0,
                                'subdistricts' => [],
                                'sectors' => []
                            ];
                        }
                    }
                }
            }
        }
    }

    try {
        $db = getMasterActiveDb();
        $stmt = $db->query("SELECT province, city, COALESCE(NULLIF(subdistrict, ''), 'Lainnya') as subdistrict, COALESCE(sector, 'lainnya') as sector, COUNT(*) as cnt 
                            FROM harvested_places 
                            WHERE city IS NOT NULL AND city != '' 
                            GROUP BY province, city, COALESCE(NULLIF(subdistrict, ''), 'Lainnya'), sector 
                            ORDER BY province ASC, city ASC, subdistrict ASC");
        
        while ($row = $stmt->fetch()) {
            $provName = trim($row['province'] ?: 'Lainnya');
            $cityName = trim($row['city']);
            $subdist  = trim($row['subdistrict']);
            $sector   = $row['sector'] ?: 'lainnya';
            $cnt      = (int)$row['cnt'];

            // Match province name
            $targetProv = $provName;
            if (!isset($tree[$targetProv])) {
                foreach ($officialProvinces as $op) {
                    if (strcasecmp($op, $provName) === 0 || stripos($provName, $op) !== false) {
                        $targetProv = $op;
                        break;
                    }
                }
            }
            if (!isset($tree[$targetProv])) {
                $tree[$targetProv] = [
                    'province' => $targetProv,
                    'total_places' => 0,
                    'cities' => []
                ];
            }

            $tree[$targetProv]['total_places'] += $cnt;

            // Match or create city
            $targetCity = $cityName;
            if (!isset($tree[$targetProv]['cities'][$targetCity])) {
                foreach ($tree[$targetProv]['cities'] as $exCity => $exData) {
                    if (strcasecmp($exCity, $cityName) === 0 || stripos($cityName, $exCity) !== false || stripos($exCity, $cityName) !== false) {
                        $targetCity = $exCity;
                        break;
                    }
                }
            }

            if (!isset($tree[$targetProv]['cities'][$targetCity])) {
                $tree[$targetProv]['cities'][$targetCity] = [
                    'name' => $targetCity,
                    'total_places' => 0,
                    'subdistricts' => [],
                    'sectors' => []
                ];
            }

            $tree[$targetProv]['cities'][$targetCity]['total_places'] += $cnt;
            $tree[$targetProv]['cities'][$targetCity]['sectors'][$sector] = 
                ($tree[$targetProv]['cities'][$targetCity]['sectors'][$sector] ?? 0) + $cnt;

            // Group subdistrict
            if (!isset($tree[$targetProv]['cities'][$targetCity]['subdistricts'][$subdist])) {
                $tree[$targetProv]['cities'][$targetCity]['subdistricts'][$subdist] = [
                    'name' => $subdist,
                    'total_places' => 0
                ];
            }
            $tree[$targetProv]['cities'][$targetCity]['subdistricts'][$subdist]['total_places'] += $cnt;
        }
    } catch (Exception $e) {}

    // Convert associative arrays to indexed sorted lists
    $result = [];
    $totalAll = 0;
    foreach ($tree as $provKey => $pData) {
        $cList = [];
        foreach ($pData['cities'] as $cityName => $cData) {
            $sList = array_values($cData['subdistricts']);
            usort($sList, fn($a, $b) => $b['total_places'] - $a['total_places']);
            $cData['subdistricts'] = $sList;
            $cList[] = $cData;
        }

        // Sort cities: cities with data first, then alphabetical
        usort($cList, function($a, $b) {
            if ($a['total_places'] !== $b['total_places']) {
                return $b['total_places'] - $a['total_places'];
            }
            return strcmp($a['name'], $b['name']);
        });

        $pData['cities'] = $cList;
        $totalAll += $pData['total_places'];
        $result[] = $pData;
    }

    // Sort provinces: provinces with data first, then alphabetical
    usort($result, function($a, $b) {
        if ($a['total_places'] !== $b['total_places']) {
            return $b['total_places'] - $a['total_places'];
        }
        return strcmp($a['province'], $b['province']);
    });

    echo json_encode([
        'success' => true,
        'total_all_places' => $totalAll,
        'provinces' => $result
    ]);
}


