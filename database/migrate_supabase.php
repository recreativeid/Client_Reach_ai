<?php
/**
 * Cliento — Automated Supabase Database Migrator
 * Connects to Supabase Cloud PostgreSQL and initializes all tables & seed data.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$configFile = __DIR__ . '/../config.local.php';
if (!file_exists($configFile)) {
    echo json_encode([
        'success' => false,
        'message' => 'Berkas config.local.php belum ditemukan. Harap masukkan kredensial Supabase terlebih dahulu.'
    ], JSON_PRETTY_PRINT);
    exit(1);
}

$config = require $configFile;
$host = $config['SUPABASE_DB_HOST'] ?? '';
$pass = $config['SUPABASE_DB_PASSWORD'] ?? '';
$user = $config['SUPABASE_DB_USER'] ?? 'postgres';
$port = $config['SUPABASE_DB_PORT'] ?? '5432';
$db   = $config['SUPABASE_DB_NAME'] ?? 'postgres';

if (empty($host) || empty($pass)) {
    echo json_encode([
        'success' => false,
        'message' => 'Kredensial SUPABASE_DB_HOST atau SUPABASE_DB_PASSWORD di config.local.php masih kosong.'
    ], JSON_PRETTY_PRINT);
    exit(1);
}

if (!extension_loaded('pdo_pgsql')) {
    echo json_encode([
        'success' => false,
        'message' => 'Ekstensi PHP pdo_pgsql belum aktif di server.'
    ], JSON_PRETTY_PRINT);
    exit(1);
}

echo "Menghubungkan ke Supabase Cloud PostgreSQL ($host:$port/$db)...\n";

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 15
    ]);
    echo "Koneksi ke Supabase BERHASIL!\n";
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal terhubung ke Supabase: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit(1);
}

// Read schema.sql
$schemaFile = __DIR__ . '/supabase_schema.sql';
if (!file_exists($schemaFile)) {
    echo "Berkas supabase_schema.sql tidak ditemukan.\n";
    exit(1);
}

$sql = file_get_contents($schemaFile);
echo "Menjalankan migrasi skema tabel ke Supabase...\n";

try {
    $pdo->exec($sql);
    echo "Migrasi skema tabel dan data awal BERHASIL dijalankan di Supabase!\n";

    // Verify users count
    $stmt = $pdo->query("SELECT count(*) as total FROM public.users");
    $cnt = $stmt->fetch()['total'] ?? 0;
    echo "Verifikasi: Total $cnt pengguna terdaftar di tabel public.users.\n";

    echo json_encode([
        'success' => true,
        'message' => 'Database Supabase Cloud siap 100% dan terhubung ke Cliento!',
        'host' => $host,
        'total_users' => (int)$cnt
    ], JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan saat menjalankan migrasi SQL: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit(1);
}
