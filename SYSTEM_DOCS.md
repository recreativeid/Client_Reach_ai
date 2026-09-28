# CLIENTO - DOKUMENTASI SISTEM & LAPORAN TEKNIS

> **Dokumen Teknis Lengkap Cara Kerja Sistem Cliento (Sales Intelligence Platform)**  
> Versi: 2.1 | Terakhir Diperbarui: 2026-09-28

---

## 1. Ringkasan Eksekutif

**Cliento** adalah platform Sales Intelligence berbasis web yang dirancang untuk membantu tim sales menemukan, menganalisis, dan menghubungi calon klien bisnis secara efisien. Sistem ini menggabungkan:

- Teknologi scraping data bisnis dari OpenStreetMap (Overpass API) dan Nominatim/Photon
- Peta interaktif Leaflet.js dengan visualisasi zona target
- AI copywriting (Google Gemini) untuk outreach WhatsApp
- Manajemen arsip dan template pesan

### Keunggulan Utama
| Fitur | Deskripsi |
|-------|-----------|
| Scraping Multi-Sumber | Overpass OSM + Nominatim + Photon Komoot |
| Cakupan Nasional | 38 Provinsi, 509+ Kab/Kota se-Indonesia |
| Deteksi Lengkap | Deteksi dan panen otomatis seluruh bisnis per wilayah |
| Master Database | Data terpanen tersimpan permanen, bisa diquery ulang |
| Live Filter | Pencarian keyword, filter kategori, filter kontak HP |
| AI Outreach | Google Gemini Vision untuk naskah sales personal |
| Ekspor Data | Download Excel (.xlsx) instan via SheetJS |

---

## 2. Arsitektur Sistem

### 2.1 Stack Teknologi
```
Frontend:
  - HTML5 (Single Page Application)
  - Vanilla JavaScript ES6+ (modular)
  - CSS3 (SaaS Minimalist Theme)
  - Leaflet.js (peta interaktif)
  - Chart.js (analitik dashboard)
  - SheetJS (ekspor Excel)
  - FontAwesome 6 + Google Fonts Poppins

Backend:
  - PHP 8.x Native (REST API)
  - SQLite 3 (database utama, WAL mode)
  - JSONL flat-file (Master Database bisnis)

External APIs:
  - Overpass API (OpenStreetMap query)
  - Nominatim (geocoding & boundary)
  - Photon Komoot (spatial search)
  - Google Gemini API (AI copywriting)
```

### 2.2 Alur Data Utama
```
[User Pilih Wilayah] 
    |
    v
[Cek Master Database (JSONL)] -----> [Data Ada] --> [Tampilkan + Filter]
    |                                                    |
    v                                                    v
[Data Belum Ada]                                   [Buka Database]
    |                                               [Export Excel]
    v                                               [Simpan Arsip]
[Sapu Bersih / Background Worker]
    |
    v
[Overpass API + Nominatim + Photon]
    |
    v
[Simpan ke JSONL (data/places_*.jsonl)]
    |
    v
[Data Tersedia di Master DB]
```

### 2.3 Struktur Direktori
```
Client_Reach_ai/
  index.html              - SPA utama (semua UI)
  config.php              - Konfigurasi global + koneksi DB
  PROJECT_MAP.md          - Peta konteks proyek (referensi AI)
  SYSTEM_DOCS.md          - Dokumen ini

  api/
    master_db.php          - Query Master Database (JSONL)
    scraper.php            - Engine scraping cerdas
    harvest_queue.php      - Antrean & worker manager
    harvest_worker.php     - Background worker process
    full_harvest.php       - Harvester lengkap Overpass
    regions.php            - Hierarki wilayah & GeoJSON
    gemini.php             - Google Gemini AI integration
    auth.php               - Autentikasi & OTP email
    customers.php          - CRUD database customer
    templates.php          - Kelola template WA
    archives.php           - Arsip Google Drive style
    history.php            - Riwayat scraping
    settings.php           - Pengaturan profil

  assets/
    css/style.css          - Desain SaaS putih-biru
    js/
      app.js               - SPA router & dashboard
      scraper.js           - Scraper Client + Master DB module
      harvest.js           - Background harvest manager
      map.js               - Leaflet engine
      regions_data.js      - Data geospasial Indonesia
      templates.js         - Template WhatsApp manager
      archives.js          - Google Drive folder explorer
      auth.js              - Controller autentikasi
      customers.js         - Controller database customer

  data/
    indonesia_regions.json - Master list 509 Kab/Kota
    places_*.jsonl         - Data bisnis terpanen per wilayah
    harvest_queue.json     - Status antrean worker
    harvest_worker.lock    - Lock file proses aktif

  database/
    client_reach.db        - SQLite database
    schema.sql             - Definisi skema
    init.php               - Inisialisasi & migrasi
```

---

## 3. Alur Kerja Menu Utama

### 3.1 Dashboard
- Menampilkan metrik utama: Total Leads, Prospek, Koleksi, WhatsApp
- Chart.js: Sales Funnel (area line) + Sebaran Industri (donut)
- Data diambil dari database SQLite (scraping_history, scraped_items)

### 3.2 Scraping Client (Menu Utama)

#### Mode 1: Wilayah Administratif
1. User memilih Provinsi -> Kab/Kota -> (opsional) Kecamatan -> Kelurahan
2. Peta menampilkan garis merah batas wilayah (polygon)
3. Sistem otomatis cek Master Database
4. Jika data tersedia: tampil badge hijau + tombol "Buka Database"
5. Jika belum: tampil opsi "Deteksi Seluruh Bisnis di Wilayah Ini"

#### Mode 2: Titik Peta & Radius
1. User klik titik di peta
2. Atur slider radius (1-25 KM)
3. Peta menampilkan lingkaran radius merah

#### Scraping Targeted (Keyword/Kategori)
1. User ketik keyword bebas ATAU pilih kategori preset
2. Klik "Scrape Data Lengkap"
3. Sistem scrape via Overpass + Nominatim
4. Hasil tampil di tabel dengan filter lead qualification

#### Deteksi Lengkap Wilayah (Perekaman Otomatis)
1. Tombol "Deteksi Seluruh Bisnis di Wilayah Ini"
2. Memicu background worker (harvest_queue.php -> harvest_worker.php)
3. Worker scrape semua kategori: Toko, Kuliner, Kesehatan, Kantor, Jasa, Pendidikan, Pabrik, Hotel, Hiburan
4. Data disimpan ke file JSONL (data/places_*.jsonl)
5. Progress bar + polling status realtime

#### Database Explorer
1. Buka via tombol "Buka Database" setelah Deteksi selesai
2. Filter Bar:
   - Pencarian keyword (debounce 400ms)
   - Dropdown filter kategori
   - Quick filter: Semua / Punya HP / Tanpa HP
3. Tabel data dengan pagination (50 per halaman)
4. Aksi: WhatsApp langsung, Export Excel, Simpan ke Arsip

### 3.3 Template Pesan
- Template WhatsApp per kategori bisnis
- Variabel tag: {nama_tempat}, {kategori}, {alamat}, {rating}, {telepon}
- Preview mockup WhatsApp realtime

### 3.4 Koleksi Arsip
- Folder bersarang (nested) seperti Google Drive
- Breadcrumbs navigasi
- Grid/List view toggle
- Status prospek per kontak (Prospek/Belum/Ditolak)

### 3.5 Database Customer (Admin)
- CRUD pelanggan
- Verifikasi OTP email
- Filter status & live search

---

## 4. API Endpoints

### 4.1 Master Database API (`api/master_db.php`)
| Endpoint | Method | Deskripsi |
|----------|--------|-----------|
| `?action=status&region=<nama>` | GET | Cek ketersediaan data wilayah |
| `?action=query&region=<nama>&keyword=<x>&category=<x>&page=<n>` | GET | Query data dengan filter |
| `?action=regions` | GET | List semua wilayah terpanen |
| `?action=stats` | GET | Statistik global seluruh data |

### 4.2 Scraper API (`api/scraper.php`)
| Endpoint | Method | Deskripsi |
|----------|--------|-----------|
| `?action=scrape` | POST | Eksekusi scraping targeted |
| `?action=preview` | GET | Preview titik bisnis |

### 4.3 Harvest Queue API (`api/harvest_queue.php`)
| Endpoint | Method | Deskripsi |
|----------|--------|-----------|
| `?action=status` | GET | Status worker & antrean |
| POST body: `{action: 'add_and_run'}` | POST | Tambah wilayah & mulai worker |

### 4.4 Auth API (`api/auth.php`)
| Endpoint | Method | Deskripsi |
|----------|--------|-----------|
| `?action=login` | POST | Login user/admin |
| `?action=register` | POST | Registrasi customer baru |
| `?action=me` | GET | Verifikasi session aktif |
| `?action=verify_otp` | POST | Verifikasi kode OTP |

---

## 5. Data Wilayah Indonesia

- **38 Provinsi** lengkap (termasuk Papua pemekaran)
- **509+ Kabupaten/Kota** dengan koordinat & bounding box
- Kecamatan & Kelurahan: generator dinamis berbasis koordinat
- File referensi: `data/indonesia_regions.json`
- Data geospasial frontend: `assets/js/regions_data.js`

---

## 6. Keamanan & Autentikasi

- Token Bearer berbasis localStorage
- Session endpoint: `api/auth.php?action=me`
- Role: Admin (full access) | Customer (standard access)
- Verifikasi OTP email 6-digit (berlaku 15 menit)
- Protected routes: Database Customer hanya Admin

---

## 7. Data yang Sudah Terpanen (Status Saat Ini)

| Wilayah | Total Bisnis | Ukuran File |
|---------|-------------|-------------|
| Kota Denpasar | ~4,500+ | 1.8 MB |
| Kabupaten Badung | ~3,700+ | 1.5 MB |
| Kabupaten Bangli | ~2,000+ | 790 KB |
| Kota Magelang | ~800+ | 331 KB |
| Kabupaten Buleleng | ~170+ | 69 KB |

*Data terus bertambah seiring aktivitas Deteksi Wilayah.*

---

## 8. Roadmap & Rencana Upgrade

### Fase 1 (Selesai)
- [x] Scraping multi-sumber (Overpass + Nominatim + Photon)
- [x] Peta interaktif 2 mode (Wilayah + Radius)
- [x] Background worker harvest otomatis
- [x] Master Database (JSONL) + Live Filter
- [x] Deteksi Lengkap Wilayah terintegrasi di Scraping Client
- [x] AI Outreach Gemini Vision
- [x] Template WhatsApp + Arsip Google Drive

### Fase 2 (Mendatang)
- [ ] Analisis chat WhatsApp (respon prospek otomatis)
- [ ] Scoring prospek berbasis AI (kualifikasi otomatis)
- [ ] Auto-outreach: sistem kirim pesan otomatis berdasarkan jadwal
- [ ] Dashboard analitik real (data dari database, bukan mock)

### Fase 3 (Jangka Panjang)
- [ ] Multi-AI agent: AI karyawan yang berkolaborasi antar sesi
- [ ] Auto-discovery pasar: sistem identifikasi peluang baru otomatis
- [ ] CRM pipeline lengkap (follow-up tracking)
- [ ] Integrasi Supabase / cloud database
- [ ] API publik untuk integrasi pihak ketiga

---

## 9. Cara Menjalankan

### Prasyarat
- XAMPP / PHP 8.x + SQLite3
- Browser modern (Chrome/Firefox)

### Langkah
1. Clone/copy proyek ke `htdocs/Client_Reach_ai/`
2. Akses `http://localhost/Client_Reach_ai/database/init.php` (inisialisasi DB)
3. Akses `http://localhost/Client_Reach_ai/index.html`
4. Login: `admin@cliento.id` / `admin123`

### Port Alternatif
- Jika menggunakan PHP built-in server: `php -S localhost:8080`
- Akses: `http://localhost:8080/index.html`

---

*Dokumen ini di-generate dan diperbarui secara berkala sebagai referensi teknis sistem Cliento.*
