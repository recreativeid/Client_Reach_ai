-- Client Reach AI - Workflow AI Sales
-- SQLite Database Schema

CREATE TABLE IF NOT EXISTS folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER DEFAULT NULL,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS archives (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folder_id INTEGER DEFAULT NULL,
    name TEXT NOT NULL,
    total_items INTEGER DEFAULT 0,
    notes TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS scraping_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    query_name TEXT NOT NULL,
    method TEXT NOT NULL,
    location_name TEXT NOT NULL,
    target_category TEXT NOT NULL,
    total_found INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS scraped_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    archive_id INTEGER DEFAULT NULL,
    history_id INTEGER DEFAULT NULL,
    name TEXT NOT NULL,
    address TEXT NOT NULL,
    phone TEXT DEFAULT '',
    lat REAL DEFAULT 0,
    lng REAL DEFAULT 0,
    category TEXT DEFAULT '',
    social_media TEXT DEFAULT '',
    opening_hours TEXT DEFAULT '',
    rating REAL DEFAULT 0,
    reviews_count INTEGER DEFAULT 0,
    status TEXT DEFAULT 'none', -- 'none' (putih), 'prospect' (centang/hijau), 'rejected' (silang/merah)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
    FOREIGN KEY (history_id) REFERENCES scraping_history(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_name TEXT UNIQUE NOT NULL,
    greeting_type TEXT DEFAULT 'formal',
    message_body TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role TEXT NOT NULL DEFAULT 'customer', -- 'admin' or 'customer'
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    phone TEXT DEFAULT '',
    password_hash TEXT NOT NULL,
    status TEXT DEFAULT 'active', -- 'active', 'suspended', 'pending'
    is_verified INTEGER DEFAULT 0, -- 1 = verified via OTP, 0 = pending OTP
    avatar TEXT DEFAULT '',
    token TEXT DEFAULT '',
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS email_otps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER DEFAULT NULL,
    email TEXT NOT NULL,
    otp_code TEXT NOT NULL,
    type TEXT DEFAULT 'register', -- 'register', 'reset_password', 'login'
    expires_at DATETIME NOT NULL,
    is_used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Seed initial categories and standard templates
INSERT OR IGNORE INTO categories (name) VALUES 
('Cafe'),
('Sekolah'),
('Restoran'),
('Klinik & RS'),
('Hotel & Penginapan'),
('Instansi'),
('Toko & Retail'),
('Umum / Lainnya');

INSERT OR IGNORE INTO templates (category_name, greeting_type, message_body) VALUES 
('Cafe', 'formal', 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami dari Client Reach AI melihat perkembangan cafe kakak di {alamat} sangat menarik. Kami ingin berbagi strategi optimasi sales & kunjungan pelanggan baru melalui workflow otomatisasi. Apakah ada waktu 5 menit untuk sharing kak? Terima kasih!'),
('Sekolah', 'formal', 'Selamat siang bapak/ibu bagian humas & manajemen {nama_tempat}. Salam silaturahmi dari tim kami. Kami memiliki program digitalisasi dan publikasi yang sangat relevan untuk mendukung kegiatan di {nama_tempat}. Apakah kami bisa terhubung dengan PIC terkait? Terima kasih.'),
('Restoran', 'formal', 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami melihat rating resto kakak ({rating} bintang) sangat positif. Kami ingin menawarkan solusi sales automation untuk meningkatkan repeat order pelanggan resto kakak. Boleh kami kirimkan brief singkatnya?'),
('Klinik & RS', 'formal', 'Selamat pagi/siang tim manajemen {nama_tempat}. Kami mengapresiasi layanan kesehatan bapak/ibu di {alamat}. Kami memiliki workflow AI penjadwalan & reminder pasien otomatis via WA. Boleh kami jadwalkan demo singkat?'),
('Hotel & Penginapan', 'formal', 'Hallo tim reservasi dan manajemen {nama_tempat}. Kami dari Client Reach AI memiliki program direct booking booster via WhatsApp untuk meningkatkan okupansi kamar {nama_tempat}. Apakah berkenan menerima penawaran kami?'),
('Umum / Lainnya', 'formal', 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Salam kenal dari tim Client Reach AI. Kami melihat potensi bisnis kakak di {alamat} sangat bagus. Kami ingin menawarkan workflow AI sales untuk menjangkau calon pelanggan potensial lebih cepat. Boleh kami sharing detailnya kak?');

