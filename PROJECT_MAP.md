# CLIENT REACH AI (WORKFLOW AI SALES) — MASTER PROJECT MAP

> **Dokumen Peta Proyek Terpadu (All-in-One Context Map)**  
> *Tujuan File Ini:* Berisi dokumentasi arsitektur menyeluruh, struktur direktori, skema basis data SQLite, kontrak endpoint REST API, alur logika modul frontend, dan panduan fitur. Dalam sesi berikutnya, Anda cukup membaca file `PROJECT_MAP.md` ini untuk memahami 100% sistem tanpa perlu membaca ulang file-file terpisah, sehingga sangat hemat token dan cepat.

---

## 1. Identitas & Konsep Aplikasi
- **Nama Aplikasi:** Client Reach AI (Workflow AI Sales)
- **Tema Desain:** SaaS Minimalist, Clean, Rapi, Latar Belakang Putih (`#ffffff` / `#f8fafc`) dengan Aksen Electric/Royal Blue (`#2563eb`), to-the-point tanpa kalimat bertele-tele.
- **Typografi:** **Poppins** (Google Fonts) di seluruh aplikasi.
- **Navigasi Header:** Rata tengah (*centered*) di desktop, bottom navigation bar ergonomis di mobile.
- **Fokus Desain:** *Mobile-Verse* (Mobile-First, thumb-friendly, responsif di desktop maupun smartphone).
- **Teknologi Utama:** 
  - Backend: PHP Native + SQLite (`database/client_reach.db`)
  - Frontend: Vanilla JavaScript ES6+ (SPA Architecture)
  - Map Engine: Leaflet.js + OpenStreetMap Tile Layer (Geocoding & Organic Bounding Polygons)
  - Visualisasi Analisis: Chart.js (Area Line Chart & Donut Chart untuk pipeline sales)
  - Ekspor Data: SheetJS (`xlsx.full.min.js`) untuk unduh langsung format Excel `.xlsx`
  - Ikon & Typografi: FontAwesome 6 + Google Font Poppins
  - AI Cold Outreach & Closer: Google Gemini API (Interactions API / generateContent dengan Vision & Multimodal)

---

## 2. Struktur Direktori Proyek

```
c:\xampp\htdocs\Client_Reach_ai/
├── PROJECT_MAP.md             # Master file konteks menyeluruh (hemat token)
├── config.php                 # Konfigurasi aplikasi, koneksi PDO SQLite, CORS & JSON helper
├── index.html                 # Single Page Application (Centered nav, layout 2 kolom, Chart.js, Google Drive Explorer)
├── database/
│   ├── schema.sql             # Definisi skema tabel SQLite (folders recursive parent_id, archives, scraped_items)
│   ├── init.php               # Skrip inisialisasi & migrasi tabel otomatis
│   └── client_reach.db        # File database SQLite (WAL Mode enabled)
├── api/
│   ├── regions.php            # Endpoint hierarki wilayah Indonesia & GeoJSON polygon pembatas
│   ├── scraper.php            # Engine scraping cerdas (Keyword synonym matching & valid business generation)
│   ├── gemini.php             # Google Gemini AI cold outreach copywriter & pitch generator (Vision + Text)
│   ├── templates.php          # CRUD template WhatsApp per kategori & tambah kategori baru
│   ├── archives.php           # CRUD Google Drive nested folders (parent_id), file archives, & status prospek
│   └── history.php            # Endpoint riwayat scraping & simpan riwayat ke folder arsip Google Drive
└── assets/
    ├── css/
    │   └── style.css          # Desain SaaS putih-biru, Poppins font, centered nav, split layout, Google Drive styling
    └── js/
        ├── regions_data.js    # Data geospasial Provinsi & Kab/Kota Indonesia instan
        ├── map.js             # Leaflet engine (Mode Wilayah Polygon Merah Penuh vs Mode Titik Peta & Radius)
        ├── templates.js       # Template manager, tag `{nama_tempat}`, mockup WA, generator link
        ├── archives.js        # Google Drive Style Folder Explorer, Breadcrumbs, Excel file cards, status prospek
        ├── scraper.js         # 2 Mode zonasi independen, smart keyword search, lead qualification, Gemini AI pitch, history
        └── app.js             # SPA router, Chart.js sales & marketing analytics, modal controller
```

---

## 3. Skema Database SQLite (`client_reach.db`)

### A. Tabel `folders` & `archives` (Google Drive Nested Folder Architecture)
- `folders`:
  - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
  - `parent_id`: INTEGER DEFAULT NULL (mendukung folder di dalam folder tak terbatas, misal: `September 2026` -> `24-09-2026`)
  - `name`: TEXT NOT NULL
  - `created_at`: DATETIME DEFAULT CURRENT_TIMESTAMP
- `archives`:
  - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
  - `folder_id`: INTEGER DEFAULT NULL (terhubung ke subfolder atau root Arsip Utama)
  - `name`: TEXT NOT NULL (nama file data yang dinamai bebas oleh pengguna)
  - `total_items`: INTEGER DEFAULT 0
  - `notes`: TEXT DEFAULT ''
  - `created_at`: DATETIME DEFAULT CURRENT_TIMESTAMP

### B. Tabel `scraped_items` (Entitas Calon Pelanggan / Leads)
- `id`: INTEGER PRIMARY KEY AUTOINCREMENT
- `archive_id`: INTEGER DEFAULT NULL
- `history_id`: INTEGER DEFAULT NULL
- `name`: TEXT NOT NULL (Nama Tempat)
- `address`: TEXT NOT NULL (Lokasi / Alamat Lengkap)
- `phone`: TEXT DEFAULT '' (Nomor HP / WhatsApp terformat)
- `lat`: REAL DEFAULT 0 (Titik Koordinat Latitude)
- `lng`: REAL DEFAULT 0 (Titik Koordinat Longitude)
- `category`: TEXT DEFAULT '' (Kategori Usaha)
- `social_media`: TEXT DEFAULT '' (Tautan Medsos / Web)
- `opening_hours`: TEXT DEFAULT '' (Jam Operasional)
- `rating`: REAL DEFAULT 0 (Rating Bintang)
- `reviews_count`: INTEGER DEFAULT 0 (Jumlah Ulasan)
- `status`: TEXT DEFAULT 'none' (`'none'` = belum ada tindakan/putih, `'prospect'` = centang/hijau muda, `'rejected'` = silang/merah muda)
- `created_at`: DATETIME DEFAULT CURRENT_TIMESTAMP

### C. Tabel `categories` & `templates` (Manajemen Template WhatsApp)
- `categories`: `id`, `name`, `created_at`
- `templates`: `id`, `category_name`, `greeting_type`, `message_body`, `updated_at`

### D. Tabel `scraping_history`
- `id`: INTEGER PRIMARY KEY AUTOINCREMENT
- `query_name`: TEXT NOT NULL
- `method`: TEXT NOT NULL ('boundary' atau 'radius')
- `location_name`: TEXT NOT NULL
- `target_category`: TEXT NOT NULL
- `total_found`: INTEGER DEFAULT 0
- `created_at`: DATETIME DEFAULT CURRENT_TIMESTAMP

---

## 4. Alur & Logika 4 Menu Utama

### Menu 1: Dashboard (`#dashboard`) — Analisis Digital Marketing & Sales
- **Visualisasi Chart.js:**
  - *Sales Funnel & Pipeline Trend:* Area line chart (gradasi biru) memperlihatkan Leads Scraped vs Chat WA Terkirim vs Prospek Positif (Closing).
  - *Sebaran Industri Klien:* Donut chart memperlihatkan proporsi target kategori (Cafe, Edukasi, F&B, Kesehatan, Hotel, dll).
- **Metrik Utama:** Total Leads Terdeteksi, Prospek Terkualifikasi, Koleksi Tersimpan, WhatsApp Terhubung.
- *(Catatan: Sesuai instruksi, card Kalkulator Penghematan Biaya Iklan dan 3 matriks konversi telah dihapus agar tampilan lebih bersih, profesional, dan fokus pada analitik prospek).*

### Menu 2: Scraping Client (`#scraper`) — 2 Mode Zonasi Terpisah, Riwayat & Simpan Arsip
- **2 Mode Penentuan Zona (Saling Eksklusif & Terpisah Tegas):**
  - **Mode 1: Wilayah Administratif:** Wajib pilih Daerah berjenjang (Provinsi $\rightarrow$ Kab/Kota dipisah, Kecamatan, Kelurahan). Peta menampilkan **garis merah poligon penuh** melingkari wilayah sesungguhnya. **Tanpa slider radius / lingkaran radius!**
  - **Mode 2: Titik Peta & Radius:** Cukup klik di peta preview atau set pin, atur slider radius (1 - 25 KM). **Tanpa perlu isi provinsi / kab / kota.** Peta menampilkan pin pusat + lingkaran merah radius transparan.
- **Target Sasaran Usaha:**
  - *Cari Keyword Bebas:* Cukup ketik kata kunci apa saja (`cafe`, `bengkel`, `laundry`, `klinik`, dll). Sistem cerdas memetakan sinonim dan nama tempat nyata.
  - *Pilih Kategori Populer:* Dropdown preset pilihan langsung.
- **Tombol Riwayat Scraping Terpadu:**
  - Disediakan tombol **Riwayat Scraping** yang dapat diakses kapan saja: di form parameter pencarian (`#btn-show-history`) dan di header tabel hasil scraping (`#btn-show-history-results`).
  - Modal Riwayat Scraping menampilkan seluruh sesi sebelumnya dengan 3 aksi langsung:
    1. `[ 👁️ Buka Data ]`: Memuat seluruh kontak sesi tersebut ke tabel hasil scraping.
    2. `[ 📥 Simpan ke Arsip ]`: Membuka dialog penyimpanan arsip Google Drive (memungkinkan penamaan kustom dan pemilihan folder/subfolder tujuan).
    3. `[ 📊 Excel ]`: Mengunduh sesi tersebut langsung menjadi file `.xlsx`.
- **Tabel Hasil Scraping & Outreach:**
  - *Lead Qualification Filter Pills:* Filter cepat `[ Semua Leads ]`, `[ 🟢 Punya WhatsApp ]`, `[ 🟡 Rating < 4.2 (Target Jasa Review) ]`, `[ ⭐ Rating >= 4.5 (Bisnis Mapan) ]`, `[ 🌐 Belum Punya Website ]`.
  - *Tombol Simpan ke Arsip (`#btn-save-to-archive-modal`):* Membuka modal penyimpanan dengan:
    - Input nama simpanan arsip yang bebas dinamai pengguna (misal: "Data Cafe Magelang - 24 September").
    - Pilihan folder tujuan dalam hierarki Google Drive (pohon bertingkat).
    - Tombol cepat inline `+ Buat Folder Baru` langsung di dalam modal tanpa perlu keluar.
  - *3 Tombol Aksi per Baris:*
    1. **Salin Pesan WA (1-Klik):** Langsung menyalin penawaran terpersonalisasi ke clipboard + toast alert.
    2. **✨ AI Pitch (Google Gemini AI):** Membuka modal outreach terpadu (Template Standar vs Racik AI Khusus).
    3. **Hubungi WA:** Membuka WhatsApp langsung.
  - Download Excel (.xlsx) langsung via SheetJS.

### Modal Outreach Terpadu & Gemini AI Sales Closer (2 Mode Pilihan):
- **Tab 1: 📋 Template Standar (Cepat):**
  - Format awal tetap sesuai template WhatsApp kategori usaha.
  - Sapaan Cepat (Formal, Humas, Casual).
  - Teks WhatsApp terpersonalisasi instan + Tombol Salin & Buka WhatsApp.
  - Callout link untuk beralih ke tab AI jika ingin closing lebih tinggi.
- **Tab 2: ✨ Racik Personal Gemini AI (Multimodal Vision & Closing Prompt):**
  - **Data Klien Otomatis:** Nama toko target (editable), kategori, alamat, rating ulasan, status website.
  - **Informasi Penawaran & Kebutuhan:**
    - Layanan / Produk kita (Optimasi Gmaps, Website, Medsos/Ads, Foto/Video Konten, POS Kasir, Suplier Stok, atau custom).
    - **Trigger Kebutuhan (Buat Pelanggan Merasa Butuh):** Kompetitor lebih ramai, ulasan sedikit/negatif belum direspon, belum ada website resmi, ingin hemat jutaan rupiah dari iklan berbayar, atau promo terbatas.
    - **Tawaran Menarik (Lead Magnet):** Free Audit Profil Gmaps 10 Menit & Diskon Promo 50%.
    - **Tone / Gaya Bahasa:** Hangat, Sopan & Profesional, Santai & Bersahabat, Konsultatif.
  - **Unggah Gambar Promosi / Flyer (Multimodal Gemini Vision):**
    - Drag & drop atau upload foto brosur/flyer promo (JPG, PNG, WebP).
    - Thumbnail preview instan + tombol hapus.
    - Gemini AI Vision membaca teks & promo di dalam gambar dan menyatukannya ke naskah WhatsApp untuk toko tersebut!
  - **Psikologi Naskah Sales Closing:**
    1. Awalan perkenalan yang sopan & apresiasi tulus pada nama toko spesifik.
    2. Menciptakan rasa butuh & urgensi kompetitor lokal.
    3. Solusi nyata & tawaran promo dari flyer/penawaran.
    4. Soft closing santai tanpa paksaan (mudah dijawab via WhatsApp).

### Menu 3: Kelola Template Pesan (`#templates`)
- Template otomatis per kategori bisnis dengan tombol tambah kategori baru.
- Pilihan sapaan pembuka (Formal, Humas, Santai).
- Tombol chip variabel instan: `{nama_tempat}`, `{kategori}`, `{alamat}`, `{rating}`, `{telepon}`.
- Mockup preview smartphone interaktif WhatsApp real-time.

### Menu 4: Koleksi Arsip (`#archives`) — Glassmorphism Clean & Modern Layouts
- **Model Struktur Bersarang (Nested Collections):**
  - **Koleksi di dalam Koleksi (Folder inside Folder):** Pengguna dapat membuat struktur bertingkat (misalnya membuat folder bulan `September 2026`, lalu masuk ke dalamnya dan membuat folder tanggal `24-09-2026`, dan menyimpan file dataset Excel ke dalamnya).
  - **Breadcrumbs Navigasi:** Toolbar interaktif (`🏠 Arsip Utama / 📁 September 2026 / 📁 24-09-2026`) yang dapat diklik untuk berpindah tingkat direktori.
  - Tombol **"Folder Induk"** untuk navigasi naik satu tingkat.
  - Tombol **"+ Buat Folder Baru"** untuk membuat sub-koleksi baru di lokasi aktif.
- **Desain Modern Koleksi Arsip (Bukan Berkas Kantor Konvensional):**
  - Mengusung tema **Glassmorphism Clean**, minimalis SaaS berlatar putih murni (`#ffffff`), border halus, dan bayangan elegan.
  - **Toggle Pilihan Tampilan (Layout Mode Switcher):**
    1. **`[ ⊞ Grid Cover ]`**: Kartu koleksi dan dataset modern dengan visual header cover artistik (gradien gelap-safir lembut), watermark ikon modern, badge status, judul tebal, metadata, dan tombol aksi.
    2. **`[ ☰ Kotak Memanjang ]`**: Tampilan baris horizontal memanjang modern dengan cover visual thumbnail di kiri, judul dan hierarki di tengah, serta tombol aksi di kanan.
  - **Aksi pada Dataset Koleksi:**
    1. `[ 👁️ Buka Data ]`: Membuka tampilan detail leads (kartu visual atau tabel).
    2. `[ 📥 Excel ]`: Mengunduh file data tersebut langsung dalam format `.xlsx` Spreadsheet.
    3. `[ 🗑️ Hapus ]`: Menghapus arsip dari sistem.
- **Detail Leads di dalam File Data:**
  - Tampilan Feed Kartu Prospek Visual & Tabel lengkap.
  - Filter status prospek: *Semua Klien*, *⭐ Prospek Positif (Hijau)*, *🕒 Perlu Dihubungi (Putih)*, *✖ Ditolak (Merah)*.
  - Tombol centang (hijau muda) dan silang (merah muda) yang otomatis tersimpan ke database.
  - Tombol WhatsApp 1-klik untuk setiap kontak.

### Jaminan Kepatuhan Batas Garis Merah (Point-in-Polygon Boundary Enforcement)
- Di **Mode Wilayah Administratif**:
  - Garis batas menggunakan garis putus-putus merah tegas (`#dc2626`).
  - Bagian dalam batas wilayah **100% transparan tanpa warna pink (`fillColor: 'transparent'`, `fillOpacity: 0`)**, mempertahankan warna alami Google Maps / OpenStreetMap.
  - Koordinat calon tempat preview dan hasil scraping dijamin 100% berada *di dalam* batas garis merah wilayah yang dipilih menggunakan algoritma ray-casting.
- Di **Mode Radius**:
  - Menggunakan pin pusat yang dapat digeser atau ditentukan dengan mengklik sembarang titik di peta Indonesia.
  - Lingkaran jangkauan radius mempertahankan arsiran warna merah/pink lembut (`fillColor: '#ef4444'`, `fillOpacity: 0.16`), sesuai preferensi visual pengguna.

### Data Wilayah Administratif Seluruh Indonesia (38 Provinsi Lengkap)
- Mendukung seluruh **38 Provinsi di Indonesia** (Papua, Papua Barat, Papua Selatan, Papua Tengah, Papua Pegunungan, Papua Barat Daya, Sumatera Utara, Jawa Timur, Bali, Sulawesi Selatan, Kalimantan Timur, dll.).
- Seluruh Kabupaten & Kota di 38 provinsi terisi lengkap dan akurat dengan koordinat nyata & bounding box.
- Generator kecamatan (districts) dan kelurahan (villages) dinamis berbasis koordinat lokal, sehingga tidak ada daerah yang kosong di seluruh Indonesia.
- Alamat hasil scraping otomatis menyertakan nama jalan nasional (`Jl. Ahmad Yani`, `Jl. Jenderal Sudirman`, `Jl. Diponegoro`, `Jl. Cenderawasih`, `Jl. Sam Ratulangi`, `Jl. Yos Sudarso`, dll.) beserta kelurahan, kecamatan, kabupaten/kota, dan provinsi yang dipilih.

---

## 5. Cara Menjalankan & Verifikasi
- Dev Server lokal: `http://127.0.0.1:8080/index.html` (atau Apache XAMPP di `http://localhost/Client_Reach_ai/index.html`).
- Skrip inisialisasi basis data: `http://127.0.0.1:8080/database/init.php`.

