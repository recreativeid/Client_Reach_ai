<?php
/**
 * Client Reach AI - Database Manager for Scraping & Regional Harvesting
 * 
 * Provides unified database access supporting:
 * 1. SQLite (database/client_reach.db) - Portable, zero-config, version-controlled in GitHub, works out-of-the-box in cPanel
 * 2. MySQL / MariaDB - Standard cPanel database (when DB_HOST / MYSQL_HOST configured)
 * 3. PostgreSQL / Supabase - Cloud DB (when SUPABASE_DB_HOST configured)
 */

$dataDir = __DIR__ . '/../data';
$dbDir   = __DIR__ . '/../database';

if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0777, true);
}

/**
 * Primary Database Connection: Supabase Cloud PostgreSQL (Single Source of Truth)
 */
function getPrimaryDb() {
    static $primary = null;
    if ($primary !== null) return $primary;

    $localConfig = [];
    if (file_exists(__DIR__ . '/../config.local.php')) {
        $localConfig = require __DIR__ . '/../config.local.php';
    }

    // 1. Supabase Cloud PostgreSQL (Sole Operational Database)
    $pgHost = $localConfig['SUPABASE_DB_HOST'] ?? (getenv('SUPABASE_DB_HOST') ?: '');
    $pgPass = $localConfig['SUPABASE_DB_PASSWORD'] ?? (getenv('SUPABASE_DB_PASSWORD') ?: '');
    $pgUser = $localConfig['SUPABASE_DB_USER'] ?? (getenv('SUPABASE_DB_USER') ?: 'postgres');
    $pgPort = $localConfig['SUPABASE_DB_PORT'] ?? (getenv('SUPABASE_DB_PORT') ?: '6543');
    $pgDb   = $localConfig['SUPABASE_DB_NAME'] ?? (getenv('SUPABASE_DB_NAME') ?: 'postgres');

    if (!empty($pgHost) && extension_loaded('pdo_pgsql')) {
        try {
            $dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb};sslmode=require";
            $pdo = new PDO($dsn, $pgUser, $pgPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 15
            ]);
            initHarvestTables($pdo, 'pgsql');
            $primary = $pdo;
            return $primary;
        } catch (PDOException $e) {
            error_log("Supabase Cloud connection error in db_manager: " . $e->getMessage());
        }
    }

    // 2. MySQL fallback (cPanel if explicitly configured)
    $mysqlHost = $localConfig['DB_HOST'] ?? (getenv('DB_HOST') ?: ($localConfig['MYSQL_HOST'] ?? (getenv('MYSQL_HOST') ?: '')));
    $mysqlDb   = $localConfig['DB_NAME'] ?? (getenv('DB_NAME') ?: ($localConfig['MYSQL_DATABASE'] ?? (getenv('MYSQL_DATABASE') ?: 'client_reach')));
    $mysqlUser = $localConfig['DB_USER'] ?? (getenv('DB_USER') ?: ($localConfig['MYSQL_USER'] ?? (getenv('MYSQL_USER') ?: '')));
    $mysqlPass = $localConfig['DB_PASSWORD'] ?? (getenv('DB_PASSWORD') ?: ($localConfig['MYSQL_PASSWORD'] ?? (getenv('MYSQL_PASSWORD') ?: '')));
    $mysqlPort = $localConfig['DB_PORT'] ?? (getenv('DB_PORT') ?: ($localConfig['MYSQL_PORT'] ?? (getenv('MYSQL_PORT') ?: '3306')));

    if (!empty($mysqlHost) && !empty($mysqlUser) && extension_loaded('pdo_mysql')) {
        try {
            $dsn = "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb};charset=utf8mb4";
            $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            initHarvestTables($pdo, 'mysql');
            $primary = $pdo;
            return $primary;
        } catch (PDOException $e) {}
    }

    return null;
}

function getSqliteDb() {
    // Forward directly to primary Supabase Cloud DB
    return getPrimaryDb();
}

/**
 * Initialize harvested_places table in the given PDO connection
 */
function initHarvestTables($pdo, $driver = null) {
    if (!$driver) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    try {
        if ($driver === 'mysql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS harvested_places (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    osm_id VARCHAR(50) DEFAULT NULL,
                    osm_type VARCHAR(10) DEFAULT 'node',
                    name VARCHAR(255) NOT NULL,
                    sector VARCHAR(100) DEFAULT NULL,
                    subsector VARCHAR(100) DEFAULT NULL,
                    category_name VARCHAR(150) DEFAULT NULL,
                    lat DECIMAL(11, 8) NOT NULL,
                    lng DECIMAL(11, 8) NOT NULL,
                    province VARCHAR(100) DEFAULT NULL,
                    city VARCHAR(100) DEFAULT NULL,
                    subdistrict VARCHAR(100) DEFAULT NULL,
                    address TEXT DEFAULT NULL,
                    phone VARCHAR(50) DEFAULT NULL,
                    website VARCHAR(255) DEFAULT NULL,
                    opening_hours VARCHAR(255) DEFAULT NULL,
                    brand VARCHAR(150) DEFAULT NULL,
                    operator VARCHAR(150) DEFAULT NULL,
                    source VARCHAR(50) DEFAULT 'harvest',
                    scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_place (name(150), city(50), lat, lng),
                    INDEX idx_city (city),
                    INDEX idx_prov (province),
                    INDEX idx_subdist (subdistrict),
                    INDEX idx_sec (sector)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } elseif ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS public.harvested_places (
                    id BIGSERIAL PRIMARY KEY,
                    osm_id VARCHAR(50) DEFAULT NULL,
                    osm_type VARCHAR(10) DEFAULT 'node',
                    name VARCHAR(255) NOT NULL,
                    sector VARCHAR(100) DEFAULT NULL,
                    subsector VARCHAR(100) DEFAULT NULL,
                    category_name VARCHAR(150) DEFAULT NULL,
                    lat DOUBLE PRECISION NOT NULL,
                    lng DOUBLE PRECISION NOT NULL,
                    province VARCHAR(100) DEFAULT NULL,
                    city VARCHAR(100) DEFAULT NULL,
                    subdistrict VARCHAR(100) DEFAULT NULL,
                    address TEXT DEFAULT NULL,
                    phone VARCHAR(50) DEFAULT NULL,
                    website VARCHAR(255) DEFAULT NULL,
                    opening_hours VARCHAR(255) DEFAULT NULL,
                    brand VARCHAR(150) DEFAULT NULL,
                    operator VARCHAR(150) DEFAULT NULL,
                    source VARCHAR(50) DEFAULT 'harvest',
                    scraped_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uk_place UNIQUE (name, city, lat, lng)
                );
                CREATE INDEX IF NOT EXISTS idx_hp_city ON public.harvested_places (city);
                CREATE INDEX IF NOT EXISTS idx_hp_prov ON public.harvested_places (province);
                CREATE INDEX IF NOT EXISTS idx_hp_subdist ON public.harvested_places (subdistrict);
                CREATE INDEX IF NOT EXISTS idx_hp_sec ON public.harvested_places (sector);
            ");
        } else {
            // SQLite
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS harvested_places (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    osm_id TEXT DEFAULT NULL,
                    osm_type TEXT DEFAULT 'node',
                    name TEXT NOT NULL,
                    sector TEXT DEFAULT NULL,
                    subsector TEXT DEFAULT NULL,
                    category_name TEXT DEFAULT NULL,
                    lat REAL NOT NULL,
                    lng REAL NOT NULL,
                    province TEXT DEFAULT NULL,
                    city TEXT DEFAULT NULL,
                    subdistrict TEXT DEFAULT NULL,
                    address TEXT DEFAULT NULL,
                    phone TEXT DEFAULT NULL,
                    website TEXT DEFAULT NULL,
                    opening_hours TEXT DEFAULT NULL,
                    brand TEXT DEFAULT NULL,
                    operator TEXT DEFAULT NULL,
                    source TEXT DEFAULT 'harvest',
                    scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (name, city, lat, lng)
                );
                CREATE INDEX IF NOT EXISTS idx_hp_city ON harvested_places (city);
                CREATE INDEX IF NOT EXISTS idx_hp_prov ON harvested_places (province);
                CREATE INDEX IF NOT EXISTS idx_hp_subdist ON harvested_places (subdistrict);
                CREATE INDEX IF NOT EXISTS idx_hp_sec ON harvested_places (sector);
                CREATE INDEX IF NOT EXISTS idx_hp_coords ON harvested_places (lat, lng);
            ");
        }
    } catch (Exception $e) {
        // Table may already exist
    }
}

/**
 * Save an array of place records to Supabase Cloud PostgreSQL (Single Source of Truth)
 */
function saveHarvestPlacesToDb($records, $regionName = '', $provinceName = '', $subdistrictName = '') {
    if (empty($records)) return 0;

    $inserted = 0;

    // Single Source of Truth: Supabase Cloud PostgreSQL
    try {
        $primaryDb = getPrimaryDb();
        if ($primaryDb) {
            $driver = $primaryDb->getAttribute(PDO::ATTR_DRIVER_NAME);
            $inserted = insertRecordsToPdo($primaryDb, $driver, $records, $regionName, $provinceName, $subdistrictName);
            syncToScrapedItems($primaryDb, $records, $regionName, $provinceName);
        }
    } catch (Exception $e) {
        error_log("Error saving to Supabase primary DB: " . $e->getMessage());
    }

    return $inserted;
}

/**
 * Helper to insert records into a PDO connection with transaction
 */
function insertRecordsToPdo($pdo, $driver, $records, $regionName, $provinceName, $subdistrictName = '') {
    if (empty($records)) return 0;

    $inserted = 0;

    if ($driver === 'mysql') {
        $sql = "INSERT IGNORE INTO harvested_places 
            (osm_id, osm_type, name, sector, subsector, category_name, lat, lng, province, city, subdistrict, address, phone, website, opening_hours, brand, operator, source, scraped_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    } elseif ($driver === 'pgsql') {
        $sql = "INSERT INTO public.harvested_places 
            (osm_id, osm_type, name, sector, subsector, category_name, lat, lng, province, city, subdistrict, address, phone, website, opening_hours, brand, operator, source, scraped_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (name, city, lat, lng) DO NOTHING";
    } else {
        // SQLite
        $sql = "INSERT OR IGNORE INTO harvested_places 
            (osm_id, osm_type, name, sector, subsector, category_name, lat, lng, province, city, subdistrict, address, phone, website, opening_hours, brand, operator, source, scraped_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);

        foreach ($records as $r) {
            $osmId   = $r['osm_id'] ?? null;
            $osmType = $r['osm_type'] ?? 'node';
            $name    = trim($r['name'] ?? '');
            if (empty($name)) continue;

            $sector    = $r['sector'] ?? 'retail';
            $subsector = $r['subsector'] ?? 'umum';
            $catName   = $r['category_name'] ?? ($r['category'] ?? 'Bisnis & Usaha');
            $lat       = (float)($r['lat'] ?? 0);
            $lng       = (float)($r['lng'] ?? ($r['lon'] ?? 0));
            $city      = $r['city'] ?? ($regionName ?: '');
            $prov      = $r['province'] ?? ($provinceName ?: 'Indonesia');
            $address   = $r['address'] ?? ($name . ($city ? ", $city" : ''));
            $subdist   = $r['subdistrict'] ?? ($r['kecamatan'] ?? ($subdistrictName ?: ''));
            if (empty($subdist) && !empty($address)) {
                if (preg_match('/Kec(?:amatan|\.)?\s*([A-Za-z0-9\s]+?)(?:,|$)/i', $address, $mKec)) {
                    $subdist = trim($mKec[1]);
                }
            }
            $phone     = $r['phone'] ?? '';
            $website   = $r['website'] ?? '';
            $hours     = $r['opening_hours'] ?? '';
            $brand     = $r['brand'] ?? '';
            $operator  = $r['operator'] ?? '';
            $source    = $r['source'] ?? 'harvest';
            $scrapedAt = $r['scraped_at'] ?? date('Y-m-d H:i:s');

            $stmt->execute([
                $osmId ? (string)$osmId : null,
                $osmType,
                $name,
                $sector,
                $subsector,
                $catName,
                $lat,
                $lng,
                $prov,
                $city,
                $subdist,
                $address,
                $phone,
                $website,
                $hours,
                $brand,
                $operator,
                $source,
                $scrapedAt
            ]);
            $inserted += $stmt->rowCount();
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("insertRecordsToPdo error: " . $e->getMessage());
    }

    return $inserted;
}

/**
 * Sync harvest records into scraping_history and scraped_items for UI display
 */
function syncToScrapedItems($pdo, $records, $regionName, $provinceName) {
    if (empty($records)) return;

    try {
        $locName = $regionName ?: ($records[0]['city'] ?? 'Indonesia');
        $catName = 'Semua Bisnis & Usaha';
        $total   = count($records);

        // Create or get history entry
        $hStmt = $pdo->prepare("
            INSERT INTO scraping_history (query_name, method, location_name, target_category, total_found, created_at)
            VALUES (?, 'Automated Regional Harvest (Overpass + Photon)', ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $hStmt->execute(["Harvest $locName", $locName, $catName, $total]);
        $historyId = $pdo->lastInsertId();

        // Batch insert into scraped_items
        $itemStmt = $pdo->prepare("
            INSERT INTO scraped_items 
            (history_id, name, address, phone, lat, lng, category, social_media, opening_hours, rating, reviews_count, status, insights_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, 4.5, 10, 'none', ?, CURRENT_TIMESTAMP)
        ");

        $pdo->beginTransaction();
        $count = 0;
        foreach ($records as $r) {
            $name    = trim($r['name'] ?? '');
            if (empty($name)) continue;

            $addr    = $r['address'] ?? ($name . ($regionName ? ", $regionName" : ''));
            $phone   = $r['phone'] ?? '';
            $lat     = (float)($r['lat'] ?? 0);
            $lng     = (float)($r['lng'] ?? ($r['lon'] ?? 0));
            $cat     = $r['category_name'] ?? ($r['category'] ?? 'Bisnis');
            $hours   = $r['opening_hours'] ?? '';

            // Generate structured AI insight preview
            $insights = [
                'sector' => $r['sector'] ?? 'retail',
                'subsector' => $r['subsector'] ?? 'umum',
                'source' => $r['source'] ?? 'harvest',
                'osm_id' => $r['osm_id'] ?? null,
                'province' => $r['province'] ?? $provinceName,
                'city' => $r['city'] ?? $regionName,
                'website' => $r['website'] ?? null
            ];

            $itemStmt->execute([
                $historyId,
                $name,
                $addr,
                $phone,
                $lat,
                $lng,
                $cat,
                $hours,
                json_encode($insights, JSON_UNESCAPED_UNICODE)
            ]);

            $count++;
            // Limit scraped_items insert to top 200 items per region to keep UI lightweight
            if ($count >= 200) break;
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("syncToScrapedItems error: " . $e->getMessage());
    }
}

/**
 * Scan all JSONL files in data/ and import missing records into database/client_reach.db
 */
function syncAllJsonlFilesToDatabase() {
    global $dataDir;
    $files = glob($dataDir . '/places_*.jsonl');
    $totalImported = 0;
    $fileStats = [];

    foreach ($files as $f) {
        $basename = basename($f);
        // Extract city from filename: places_{region}_{date}.jsonl
        $parts = explode('_', str_replace(['places_', '.jsonl'], '', $basename));
        $dateStr = array_pop($parts);
        $regionSlug = implode(' ', $parts);
        $regionName = ucwords(str_replace('_', ' ', $regionSlug));

        $records = [];
        $fp = @fopen($f, 'r');
        if ($fp) {
            while (($line = fgets($fp)) !== false) {
                $rec = json_decode(trim($line), true);
                if ($rec && !empty($rec['name'])) {
                    $records[] = $rec;
                }
            }
            fclose($fp);
        }

        if (!empty($records)) {
            $added = saveHarvestPlacesToDb($records, $regionName, 'Indonesia');
            $totalImported += $added;
            $fileStats[] = [
                'file' => $basename,
                'region' => $regionName,
                'total_in_file' => count($records),
                'newly_inserted' => $added
            ];
        }
    }

    return [
        'success' => true,
        'files_processed' => count($files),
        'total_imported' => $totalImported,
        'details' => $fileStats
    ];
}

/**
 * Export SQLite harvested_places into a cPanel-ready MySQL dump (.sql)
 */
function exportToCpanelMysqlDump() {
    $sqlite = getSqliteDb();
    $sqlFile = __DIR__ . '/../database/cpanel_mysql_dump.sql';

    $places = $sqlite->query("SELECT * FROM harvested_places ORDER BY id ASC")->fetchAll();

    $header = "-- Client Reach AI - cPanel MySQL / MariaDB Database Dump\n";
    $header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $header .= "-- Total Places: " . count($places) . "\n\n";
    $header .= "SET NAMES utf8mb4;\n";
    $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tableDef = "
CREATE TABLE IF NOT EXISTS `harvested_places` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `osm_id` VARCHAR(50) DEFAULT NULL,
    `osm_type` VARCHAR(10) DEFAULT 'node',
    `name` VARCHAR(255) NOT NULL,
    `sector` VARCHAR(100) DEFAULT NULL,
    `subsector` VARCHAR(100) DEFAULT NULL,
    `category_name` VARCHAR(150) DEFAULT NULL,
    `lat` DECIMAL(11, 8) NOT NULL,
    `lng` DECIMAL(11, 8) NOT NULL,
    `province` VARCHAR(100) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `website` VARCHAR(255) DEFAULT NULL,
    `opening_hours` VARCHAR(255) DEFAULT NULL,
    `brand` VARCHAR(150) DEFAULT NULL,
    `operator` VARCHAR(150) DEFAULT NULL,
    `source` VARCHAR(50) DEFAULT 'harvest',
    `scraped_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_place` (`name`(150), `city`(50), `lat`, `lng`),
    INDEX `idx_city` (`city`),
    INDEX `idx_prov` (`province`),
    INDEX `idx_sec` (`sector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
\n";

    $fp = fopen($sqlFile, 'w');
    fwrite($fp, $header . $tableDef);

    if (!empty($places)) {
        fwrite($fp, "INSERT IGNORE INTO `harvested_places` (`osm_id`, `osm_type`, `name`, `sector`, `subsector`, `category_name`, `lat`, `lng`, `province`, `city`, `address`, `phone`, `website`, `opening_hours`, `brand`, `operator`, `source`, `scraped_at`) VALUES\n");
        $total = count($places);
        foreach ($places as $idx => $p) {
            $vals = [
                $p['osm_id'] ? "'" . addslashes($p['osm_id']) . "'" : "NULL",
                "'" . addslashes($p['osm_type'] ?? 'node') . "'",
                "'" . addslashes($p['name']) . "'",
                "'" . addslashes($p['sector'] ?? '') . "'",
                "'" . addslashes($p['subsector'] ?? '') . "'",
                "'" . addslashes($p['category_name'] ?? '') . "'",
                (float)$p['lat'],
                (float)$p['lng'],
                "'" . addslashes($p['province'] ?? '') . "'",
                "'" . addslashes($p['city'] ?? '') . "'",
                "'" . addslashes($p['address'] ?? '') . "'",
                "'" . addslashes($p['phone'] ?? '') . "'",
                "'" . addslashes($p['website'] ?? '') . "'",
                "'" . addslashes($p['opening_hours'] ?? '') . "'",
                "'" . addslashes($p['brand'] ?? '') . "'",
                "'" . addslashes($p['operator'] ?? '') . "'",
                "'" . addslashes($p['source'] ?? 'harvest') . "'",
                "'" . addslashes($p['scraped_at'] ?? date('Y-m-d H:i:s')) . "'"
            ];
            $line = "(" . implode(', ', $vals) . ")";
            $line .= ($idx === $total - 1) ? ";\n" : ",\n";
            fwrite($fp, $line);
        }
    }

    fclose($fp);

    return [
        'success' => true,
        'dump_file' => basename($sqlFile),
        'total_places' => count($places),
        'size_bytes' => filesize($sqlFile)
    ];
}
