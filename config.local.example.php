<?php
/**
 * cliento (AI Sales Intelligence) - Local Configuration Template
 * 
 * Untuk menggunakan Supabase Cloud Database:
 * 1. Salin file ini menjadi "config.local.php"
 * 2. Masukkan URL dan kredensial database Supabase Anda di bawah ini
 * 3. Catatan: Gunakan Connection Pooler Supabase (port 5432 atau 6543) jika server/hosting Anda menggunakan IPv4.
 * 4. Jalankan "php database/migrate_supabase.php" untuk migrasi otomatis, atau jalankan "database/supabase_schema.sql" di SQL Editor Supabase.
 */
return [
    // Supabase Cloud Centralized Database Configuration
    // Contoh direct host: db.YOUR_PROJECT_REF.supabase.co (IPv6)
    // Contoh pooler host: aws-0-ap-southeast-1.pooler.supabase.com (IPv4 support, port 5432 atau 6543)
    'SUPABASE_DB_HOST'     => 'aws-0-ap-southeast-1.pooler.supabase.com',
    'SUPABASE_DB_PORT'     => '5432',
    'SUPABASE_DB_NAME'     => 'postgres',
    'SUPABASE_DB_USER'     => 'postgres.YOUR_PROJECT_REF',
    'SUPABASE_DB_PASSWORD' => 'YOUR_SUPABASE_DB_PASSWORD',

    'SUPABASE_URL'         => 'https://YOUR_PROJECT_REF.supabase.co',
    'SUPABASE_KEY'         => '',

    // API Keys (Opsional)
    'GEMINI_API_KEY'       => '',
    'GOOGLE_MAPS_API_KEY'  => '',
];
