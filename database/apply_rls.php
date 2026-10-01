<?php
/**
 * Cliento — Apply Row Level Security (RLS) to Supabase
 */
$configFile = __DIR__ . '/../config.local.php';
if (!file_exists($configFile)) {
    die("config.local.php not found.\n");
}
$c = require $configFile;

echo "Connecting to Supabase Cloud...\n";
try {
    $pdo = new PDO(
        "pgsql:host={$c['SUPABASE_DB_HOST']};port={$c['SUPABASE_DB_PORT']};dbname={$c['SUPABASE_DB_NAME']};sslmode=require",
        $c['SUPABASE_DB_USER'],
        $c['SUPABASE_DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Connected successfully!\n";

    $sqlFile = __DIR__ . '/supabase_rls_security.sql';
    $sql = file_get_contents($sqlFile);

    echo "Applying RLS policies to Supabase...\n";
    $pdo->exec($sql);
    echo "SUCCESS: Row Level Security (RLS) is now 100% ACTIVATED on Supabase!\n";
} catch (Exception $e) {
    echo "ERROR applying RLS: " . $e->getMessage() . "\n";
}
