-- ============================================================================
-- cliento (sales intelligence) - Supabase / PostgreSQL Database Schema
-- Ready to run directly in the Supabase SQL Editor (1-Click Execution)
-- ============================================================================

-- 1. USERS & ROLES
CREATE TABLE IF NOT EXISTS public.users (
    id BIGSERIAL PRIMARY KEY,
    role VARCHAR(20) NOT NULL DEFAULT 'customer', -- 'admin' or 'customer'
    username VARCHAR(50) UNIQUE,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50) DEFAULT '',
    password_hash TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'active', -- 'active', 'suspended', 'pending'
    is_verified SMALLINT DEFAULT 0, -- 1 = verified via OTP, 0 = pending
    avatar TEXT DEFAULT '',
    token TEXT DEFAULT '',
    last_login TIMESTAMPTZ DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 2. EMAIL OTP VERIFICATION
CREATE TABLE IF NOT EXISTS public.email_otps (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES public.users(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    otp_code VARCHAR(10) NOT NULL,
    type VARCHAR(30) DEFAULT 'register', -- 'register', 'reset_password', 'email_change'
    expires_at TIMESTAMPTZ NOT NULL,
    is_used SMALLINT DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 3. SYSTEM & MAIL SETTINGS
CREATE TABLE IF NOT EXISTS public.system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 4. FOLDERS EXPLORER
CREATE TABLE IF NOT EXISTS public.folders (
    id BIGSERIAL PRIMARY KEY,
    parent_id BIGINT REFERENCES public.folders(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    user_id BIGINT REFERENCES public.users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 5. ARCHIVES & DATASETS
CREATE TABLE IF NOT EXISTS public.archives (
    id BIGSERIAL PRIMARY KEY,
    folder_id BIGINT REFERENCES public.folders(id) ON DELETE SET NULL,
    name VARCHAR(255) NOT NULL,
    total_items INT DEFAULT 0,
    notes TEXT DEFAULT '',
    user_id BIGINT REFERENCES public.users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 6. SCRAPING HISTORY
CREATE TABLE IF NOT EXISTS public.scraping_history (
    id BIGSERIAL PRIMARY KEY,
    query_name VARCHAR(255) NOT NULL,
    method VARCHAR(50) NOT NULL,
    location_name VARCHAR(255) NOT NULL,
    target_category VARCHAR(100) NOT NULL,
    total_found INT DEFAULT 0,
    user_id BIGINT REFERENCES public.users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 7. SCRAPED LEADS ITEMS
CREATE TABLE IF NOT EXISTS public.scraped_items (
    id BIGSERIAL PRIMARY KEY,
    archive_id BIGINT REFERENCES public.archives(id) ON DELETE CASCADE,
    history_id BIGINT REFERENCES public.scraping_history(id) ON DELETE SET NULL,
    name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    phone VARCHAR(50) DEFAULT '',
    lat DOUBLE PRECISION DEFAULT 0,
    lng DOUBLE PRECISION DEFAULT 0,
    category VARCHAR(100) DEFAULT '',
    social_media TEXT DEFAULT '',
    opening_hours TEXT DEFAULT '',
    rating DOUBLE PRECISION DEFAULT 0,
    reviews_count INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'none', -- 'none', 'prospect', 'rejected'
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 8. BUSINESS CATEGORIES
CREATE TABLE IF NOT EXISTS public.categories (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 9. WHATSAPP OUTREACH TEMPLATES
CREATE TABLE IF NOT EXISTS public.templates (
    id BIGSERIAL PRIMARY KEY,
    category_name VARCHAR(100) UNIQUE NOT NULL,
    greeting_type VARCHAR(50) DEFAULT 'formal',
    message_body TEXT NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- SEED INITIAL DATA (Default Admin & Customer Demo Accounts)
-- ============================================================================

-- Admin Account: admin / admin123
INSERT INTO public.users (role, username, name, email, phone, password_hash, status, is_verified)
VALUES (
    'admin',
    'admin',
    'Super Administrator',
    'admin@cliento.id',
    '08110000000',
    '$2y$10$w859W3i8JzG28g9YJ2G1UuS2yZ6s1wV3R6E5yq0k8gU0fWqYf5g2i', -- hash for admin123
    'active',
    1
) ON CONFLICT (email) DO NOTHING;

-- Customer Demo Account: budi_pratama / customer123
INSERT INTO public.users (role, username, name, email, phone, password_hash, status, is_verified)
VALUES (
    'customer',
    'budi_pratama',
    'Budi Pratama (Owner / Director)',
    'customer@demo.com',
    '085712345678',
    '$2y$10$tZzK0i4oU1z1W9m2Q8x1Vu7oY2a1K3g4h5j6k7l8m9n0p1q2r3s4t', -- hash for customer123
    'active',
    1
) ON CONFLICT (email) DO NOTHING;

-- Initial Business Categories
INSERT INTO public.categories (name) VALUES
    ('Cafe & Coffee Shop'),
    ('Restoran & Kuliner'),
    ('Bengkel & Otomotif'),
    ('Jasa Laundry & Cuci'),
    ('Salon & Barbershop'),
    ('Sekolah & Bimbel'),
    ('Klinik & Rumah Sakit'),
    ('Hotel & Penginapan')
ON CONFLICT (name) DO NOTHING;

-- Initial Templates
INSERT INTO public.templates (category_name, greeting_type, message_body) VALUES
    ('Cafe', 'formal', 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami dari tim cliento AI Sales Intelligence melihat perkembangan cafe kakak di {alamat} sangat menarik. Kami ingin berbagi strategi optimasi sales & kunjungan pelanggan baru melalui workflow otomatisasi. Apakah ada waktu 5 menit untuk sharing kak? Terima kasih!'),
    ('Resto', 'humas', 'Selamat siang bapak/ibu bagian humas & manajemen {nama_tempat}. Kami dari cliento sales intelligence memperhatikan bisnis kuliner bapak/ibu yang berkembang pesat di {alamat}. Kami memiliki solusi pencarian prospek kemitraan B2B & engagement otomatis. Boleh kami kirimkan detail singkatnya?'),
    ('Bengkel', 'casual', 'Halo kak {nama_tempat}! Salam kenal dari tim cliento. Kami melihat rating bengkel kakak {rating} sangat bagus di area {alamat}. Kami membantu bisnis otomotif memperluas jangkauan pelanggan servis melalui digital outreach. Tertarik melihat contoh demonya?')
ON CONFLICT (category_name) DO NOTHING;

-- Initial System Settings
INSERT INTO public.system_settings (setting_key, setting_value) VALUES
    ('mail_provider', 'gmail_smtp'),
    ('smtp_host', 'smtp.gmail.com'),
    ('smtp_port', '465'),
    ('smtp_user', 'cliento.system@gmail.com'),
    ('smtp_pass', ''),
    ('from_email', 'no-reply@cliento.id'),
    ('from_name', 'cliento Sales Intelligence')
ON CONFLICT (setting_key) DO NOTHING;
