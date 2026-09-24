<?php
/**
 * Client Reach AI - Workflow AI Sales
 * Configuration & Database Connection
 */

// Enable error reporting during development
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Set headers for JSON APIs
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    exit(0);
}

// Database Connection (SQLite)
$dbDir = __DIR__ . '/database';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$dbPath = $dbDir . '/client_reach.db';

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Enable foreign keys and WAL mode for better concurrency
    $pdo->exec("PRAGMA foreign_keys = ON;");
    $pdo->exec("PRAGMA journal_mode = WAL;");
} catch (PDOException $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Gagal terhubung ke database: ' . $e->getMessage()
    ], 500);
}

// Load local overrides or environment variables securely
$localConfig = [];
if (file_exists(__DIR__ . '/config.local.php')) {
    $localConfig = require __DIR__ . '/config.local.php';
}

$geminiKey = $localConfig['GEMINI_API_KEY'] ?? (getenv('GEMINI_API_KEY') ?: '');
$gmapsKey  = $localConfig['GOOGLE_MAPS_API_KEY'] ?? (getenv('GOOGLE_MAPS_API_KEY') ?: '');

define('GEMINI_API_KEY', $geminiKey);
define('GOOGLE_MAPS_API_KEY', $gmapsKey);
define('APP_NAME', 'cliento');
define('APP_SUBTITLE', 'sales intelligence');

