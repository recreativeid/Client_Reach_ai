<?php
/**
 * Database Initialization Script
 */
require_once __DIR__ . '/../config.php';

try {
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($sql);
    echo "Database initialized successfully.\n";
} catch (Exception $e) {
    echo "Initialization error: " . $e->getMessage() . "\n";
    exit(1);
}
