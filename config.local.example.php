<?php
/**
 * cliento (AI Sales Intelligence) - Local Configuration Template
 * 
 * Untuk menggunakan Supabase Cloud Database:
 * 1. Salin file ini menjadi "config.local.php"
 * 2. Masukkan URL dan kredensial database Supabase Anda di bawah ini
 * 3. Eksekusi skrip "database/supabase_schema.sql" di menu SQL Editor pada dashboard Supabase Anda.
 */
return [
    // Supabase Cloud Configuration
    'SUPABASE_URL'         => 'https://YOUR_PROJECT_REF.supabase.co',
    'SUPABASE_KEY'         => 'YOUR_SUPABASE_ANON_OR_SERVICE_ROLE_KEY',
    'SUPABASE_DB_HOST'     => 'db.YOUR_PROJECT_REF.supabase.co',
    'SUPABASE_DB_PORT'     => '5432',
    'SUPABASE_DB_NAME'     => 'postgres',
    'SUPABASE_DB_USER'     => 'postgres',
    'SUPABASE_DB_PASSWORD' => 'YOUR_SUPABASE_DB_PASSWORD',

    // API Keys (Opsional)
    'GEMINI_API_KEY'       => '',
    'GOOGLE_MAPS_API_KEY'  => '',
];
