# LAPORAN EKSEKUTIF DAN DOKUMENTASI SISTEM
# CLIENTO — PLATFORM SALES INTELLIGENCE & LEAD ACQUISITION

Dokumen ini disusun sebagai laporan resmi mengenai arsitektur, cara kerja, alur operasional, dan peta jalan pengembangan platform Cliento. Dokumen ini dapat disalin, dicetak, atau dijadikan lampiran laporan formal bagi manajemen, mitra, atau pemangku kepentingan.

---

## DAFTAR ISI

1. Ringkasan Eksekutif
2. Nilai Bisnis dan Keunggulan Platform
3. Arsitektur dan Komponen Sistem
4. Alur Kerja Operasional Pengguna
5. Logika Mesin Scraping dan Master Database
6. Manajemen Data, Arsip, dan Ekspor
7. Integrasi Artificial Intelligence (Outreach & Closer)
8. Keamanan dan Manajemen Pengguna
9. Inventaris dan Status Data Terpanen Saat Ini
10. Panduan Operasional Singkat (SOP)
11. Rencana Pengembangan Strategis (Roadmap Masa Depan)

---

## 1. RINGKASAN EKSEKUTIF

Cliento adalah platform otomatisasi Sales Intelligence dan akuisisi prospek bisnis B2B berbasis web. Platform ini dikembangkan untuk memecahkan kendala klasik tim penjualan, yaitu lambatnya pencarian data calon klien potensial, tidak akuratnya informasi kontak, serta tingginya biaya perolehan prospek baru (Customer Acquisition Cost).

Dengan memanfaatkan jaringan data geospasial terbuka (OpenStreetMap, Nominatim, dan Photon Engine) yang dikombinasikan dengan basis data terintegrasi, Cliento mampu:
- Mengidentifikasi ribuan entitas bisnis di seluruh Indonesia hingga tingkat kabupaten/kota, kecamatan, dan kelurahan.
- Menyediakan basis data permanen yang dapat difilter secara instan tanpa membebani kuota API berulang kali.
- Menyusun naskah penawaran personal berbasis kecerdasan buatan (Google Gemini) yang siap dikirim langsung melalui aplikasi perpesanan WhatsApp.

---

## 2. NILAI BISNIS DAN KEUNGGULAN PLATFORM

Dalam lanskap operasional modern, Cliento memberikan efisiensi yang signifikan melalui beberapa keunggulan:

1. **Akurasi Geografis Tinggi:** Memanfaatkan batas wilayah administratif resmi (garis batas poligon) dan koordinat GPS presisi, memastikan prospek yang terkumpul benar-benar berada di lokasi sasaran.
2. **Kemandirian Data:** Data yang telah dipanen disimpan dalam Master Database lokal dengan format JSONL berkecepatan tinggi, sehingga dapat diakses kapan saja tanpa latensi jaringan eksternal.
3. **Efisiensi Waktu dan Tenaga:** Fitur Deteksi Lengkap Wilayah menggantikan pekerjaan riset manual berhari-hari menjadi proses latar belakang yang otomatis dalam hitungan menit.
4. **Kesiapan Eksekusi Penjualan:** Data yang dihasilkan bukan sekadar daftar nama, melainkan dilengkapi nomor WhatsApp aktif, jam operasional, tautan situs web/media sosial, dan penilaian reputasi pasar.
5. **Antarmuka Minimalis dan Cepat:** Seluruh fitur dirancang berbasis Single Page Application (SPA) yang bersih, ergonomis, responsif di perangkat desktop maupun ponsel cerdas.

---

## 3. ARSITEKTUR DAN KOMPONEN SISTEM

Sistem Cliento dibangun dengan fondasi teknologi yang stabil, efisien dalam penggunaan sumber daya, dan mudah di-hosting:

### A. Lapisan Frontend (Antarmuka Pengguna)
- **Teknologi Utama:** HTML5, CSS3 murni berstandar modern, dan Vanilla JavaScript ES6+.
- **Mesin Peta:** Leaflet.js yang dikustomisasi dengan layer resolusi tinggi anti-pudar dan render poligon batas wilayah merah.
- **Visualisasi Data:** Chart.js untuk menampilkan diagram corong penjualan (funnel) dan komposisi sektor bisnis.
- **Pemrosesan Berkas:** SheetJS (XLSX) untuk menghasilkan berkas Microsoft Excel (.xlsx) secara langsung di peramban pengguna tanpa membebani server.

### B. Lapisan Backend & Dual-Engine Database
- **Bahasa Pemrograman:** PHP Native versi 8.x dengan struktur modular berbasis REST API.
- **Basis Data Terpusat Cloud (Supabase PostgreSQL 17):** Berfungsi sebagai repositori data terpusat multi-perangkat. Memungkinkan sinkronisasi instan antara localhost di laptop, repositori GitHub, dan instalasi cPanel hosting tanpa konflik data.
- **Basis Data Lokal Mandiri (Zero-Config SQLite 3 WAL):** Berfungsi sebagai mekanisme fallback otomatis apabila koneksi internet atau cloud database tidak aktif.
- **Basis Data Penampung Massal (Master Database):** Berkas data terstruktur JSONL (JSON Lines) per wilayah yang memungkinkan pembacaan baris demi baris berkecepatan tinggi dengan penggunaan memori yang sangat rendah.

### C. Jaringan Ekstraksi Data Eksternal
- **Overpass API (OpenStreetMap):** Mesin kueri data spasial terdistribusi untuk mengekstrak titik-titik usaha komersial, industri, kuliner, pendidikan, kesehatan, dan perkantoran.
- **Nominatim & Photon Engine:** Layanan geocoding untuk pencarian koordinat dan validasi batas wilayah resmi.
- **Google Gemini AI:** Model bahasa cerdas untuk analisis konteks profil bisnis dan penyusunan naskah penjualan adaptif.

---

## 4. ALUR KERJA OPERASIONAL PENGGUNA

Operasional Cliento pada menu Scraping Client menerapkan arsitektur percabangan dinamis dua jalur (Dual-Path Architecture):

```
                           [ PENGGUNA MEMILIH WILAYAH ]
                                       │
            ┌──────────────────────────┴──────────────────────────┐
            ▼                                                     ▼
    [ KOTA SUDAH TERPANEN ]                               [ KOTA BELUM TERPANEN ]
 (Denpasar, Badung, Magelang, dll)                  (Jakarta, Surabaya, Bandung, Medan, dll)
            │                                                     │
            ▼                                                     ▼
 ┌──────────────────────┐                             ┌──────────────────────┐
 │ Master Database Siap │                             │   2 Opsi Fleksibel   │
 └──────────────────────┘                             └──────────────────────┘
            │                                                     │
  Klik "Buka Database"                        ┌───────────────────┴───────────────────┐
  Hasil instan (0.05 detik)                   ▼                                       ▼
  Filter: keyword, kategori, HP       [ JALUR 1: TARGETED SCRAPE ]            [ JALUR 2: DETEKSI LENGKAP ]
  Ekspor Excel / WhatsApp Langsung     (Pencarian Instan 2-4 Detik)            (Perekaman Permanen)
                                              │                                       │
                                       Ketik kata kunci (misal: cafe)          Klik tombol:
                                       atau pilih kategori (PT/CV)             "Deteksi Seluruh Bisnis
                                       Klik "Scrape Data Lengkap"              di Wilayah Ini"
                                              │                                       │
                                       Data langsung keluar                    Worker memanen di background
                                       Siap WhatsApp & Excel                   dan menyimpan ke Master DB
                                       *Bisa untuk 509 Kab/Kota*               *Setelah selesai, masuk Jalur A*
```

---

## 5. LOGIKA MESIN SCRAPING DAN MASTER DATABASE

### A. Dua Mode Penentuan Wilayah Sasaran
1. **Mode Wilayah Administratif (Batas Garis Merah):**
   Pengguna memilih Provinsi dan Kabupaten/Kota. Sistem memeriksa ketersediaan data di Master Database secara real-time. Jika wilayah sudah terpanen, pengguna langsung diarahkan ke Master Database Siap. Jika belum, pengguna disajikan 2 opsi fleksibel: Jalur 1 Targeted Scrape instan atau Jalur 2 Deteksi Lengkap permanen.
2. **Mode Titik Peta & Radius:**
   Pengguna mengeklik titik koordinat pada peta dan mengatur radius (1 KM hingga 25 KM) untuk pencarian sasaran terarah.

### B. Jalur A: Kota Sudah Terpanen (Master Database Siap)
Ketika suatu wilayah yang telah dipanen dipilih (contoh: Kota Magelang 906 data, Kota Denpasar 1.012 data, Kab. Badung 1.450 data, dll):
- Kartu hijau "Master Database Siap" langsung muncul di panel utama.
- Tombol aksi utama "Buka Database Wilayah" membuka penjelajah data dalam 0.05 detik.
- Tersedia bilah pencarian kata kunci live, penyaring kategori usaha, penyaring kepemilikan nomor telepon, serta tombol ekspor Excel dan kontak WhatsApp langsung.

### C. Jalur B: Kota Belum Terpanen (2 Opsi Fleksibel)
Ketika memilih wilayah yang belum tersimpan di Master Database:
1. **Jalur 1: Targeted Scrape Instan (2-4 Detik):**
   Pengguna mengetik kata kunci atau memilih kategori usaha spesifik (misalnya kantor PT/CV, klinik, kafe), melihat pratinjau kandidat, lalu mengeklik "Scrape Data Lengkap". Data langsung diekstrak secara cepat dan siap digunakan untuk WhatsApp & Excel.
2. **Jalur 2: Deteksi Lengkap (Perekaman Permanen):**
   Pengguna mengeklik "Deteksi Seluruh Bisnis di Wilayah Ini". Worker latar belakang memanen seluruh kategori usaha dan menyimpannya secara permanen ke Master Database lokal. Setelah proses selesai, wilayah tersebut otomatis beralih menjadi status Kota Terpanen (Jalur A).

### D. Fitur Bilah Saring (Live Filter Bar) pada Master Database
1. **Pencarian Kata Kunci Real-Time:** Mengetik nama usaha, alamat, atau aktivitas bisnis dengan mekanisme debounce 400 milidetik.
2. **Penyaring Kategori Spesifik:** Memilih hanya sektor yang diminati dari daftar kategori wilayah tersebut.
3. **Penyaring Status Nomor Telepon:** Memilah antara bisnis yang memiliki nomor kontak aktif atau tanpa nomor.
4. **Aksi Sales & Outreach Terpadu:** Setiap baris data dilengkapi tombol Salin Pesan Penawaran, AI Pitch Generator (Gemini), dan tautan WhatsApp langsung.

---

## 6. MANAJEMEN DATA, ARSIP, DAN EKSPOR

Data prospek yang telah terkumpul dapat dikelola lebih lanjut melalui mekanisme berikut:

1. **Ekspor Excel (.xlsx):**
   Dengan satu kali klik, seluruh baris data yang sedang dilihat atau difilter dapat diunduh dalam berkas Microsoft Excel lengkap dengan kolom:
   - Nama Tempat / Usaha
   - Sektor / Kategori Usaha
   - Alamat Lengkap
   - Nomor Telepon / WhatsApp
   - Situs Web / Media Sosial
   - Jam Operasional
   - Titik Koordinat Geografis (Latitude & Longitude)

2. **Penyimpanan Berkas ke Koleksi Arsip:**
   Sistem dilengkapi struktur arsip berkas bertingkat (folder di dalam folder) menyerupai sistem penyimpanan Google Drive. Pengguna dapat membuat folder berdasarkan periode waktu (misal: "September 2026"), subfolder wilayah (misal: "Denpasar"), dan menyimpan hasil pencarian ke dalam folder tersebut untuk diakses kembali oleh tim penjualan.

---

## 7. INTEGRASI ARTIFICIAL INTELLIGENCE (OUTREACH & CLOSER)

Cliento mengintegrasikan kecerdasan buatan Google Gemini untuk menjembatani tahap perolehan data menuju tahap komunikasi penjualan yang efektif:

1. **Pembuatan Naskah Penawaran Adaptif:**
   AI membaca atribut spesifik dari setiap bisnis (nama tempat, jenis usaha, lokasi, ada/tidaknya situs web, dan jam operasional) untuk merumuskan pesan pembuka yang personal, sopan, dan persuasif.
2. **Koneksi Langsung ke WhatsApp:**
   Setiap baris data dilengkapi tombol interaksi cepat. Saat diklik, sistem akan membuka tautan WhatsApp resmi (`https://wa.me/...`) yang sudah terisi otomatis dengan naskah penawaran hasil racikan AI, meminimalisir kerja pengetikan manual tim sales.

---

## 8. KEAMANAN DAN MANAJEMEN PENGGUNA

Sistem menerapkan protokol keamanan berlapis:
- **Gerbang Autentikasi Ketat:** Seluruh data dan halaman utama dilindungi oleh sistem token sesi berbasis web.
- **Pemisahan Peran (Role Separation):**
  - **Administrator:** Memiliki akses penuh terhadap konfigurasi sistem, pemantauan latar belakang, dan menu Database Customer.
  - **Pengguna Standar (Customer):** Memiliki akses terhadap modul Scraping, Template, dan Arsip pribadi tanpa melihat data pelanggan lainnya.
- **Verifikasi Dua Langkah (OTP):** Pendaftaran dan pemulihan akun diverifikasi menggunakan One-Time Password (OTP) 6-digit dengan masa berlaku 15 menit.

---

## 9. INVENTARIS DAN STATUS DATA TERPANEN SAAT INI

Sampai dengan versi laporan ini disusun, sistem telah berhasil memanen dan mengindeks basis data bisnis di beberapa wilayah strategis:

| Wilayah Administratif | Perkiraan Jumlah Entitas Bisnis | Ukuran Berkas Master | Status Akses |
|-----------------------|----------------------------------|----------------------|--------------|
| Kota Denpasar, Bali   | 5.032 Bisnis                    | 1,80 MB              | Siap Digunakan Langsung |
| Kabupaten Badung, Bali| 4.145 Bisnis                    | 1,50 MB              | Siap Digunakan Langsung |
| Kabupaten Bangli, Bali| 2.216 Bisnis                    | 790 KB               | Siap Digunakan Langsung |
| Kabupaten Buleleng    | 743 Bisnis                      | 272 KB               | Siap Digunakan Langsung |
| Kota Magelang, Jateng | 906 Bisnis                      | 331 KB               | Siap Digunakan Langsung |
| Kabupaten Magelang    | 1.117 Bisnis                    | 430 KB               | Siap Digunakan Langsung |

Wilayah lainnya di seluruh Indonesia (total 38 Provinsi dan 509 Kabupaten/Kota) dapat dipanen sewaktu-waktu secara mandiri melalui tombol "Deteksi Seluruh Bisnis di Wilayah Ini".

---

## 10. PANDUAN OPERASIONAL SINGKAT (SOP)

Untuk menjalankan kegiatan akuisisi klien menggunakan Cliento, ikuti 4 langkah standar berikut:

1. **Langkah 1 — Masuk ke Sistem:**
   Buka peramban, akses alamat platform Cliento, lalu masukkan kredensial akun yang valid.
2. **Langkah 2 — Tentukan Target Wilayah:**
   Buka menu **Scraping Client**. Pada tab *Wilayah Administratif*, pilih Provinsi dan Kabupaten/Kota yang dituju. Amati garis merah pada peta.
3. **Langkah 3 — Akses atau Panen Data:**
   - Jika kartu Master Database berstatus hijau, klik **Buka Database** untuk langsung meneliti data yang tersedia.
   - Jika berstatus belum ada data atau ingin penyegaran penuh, klik **Deteksi Seluruh Bisnis di Wilayah Ini**.
4. **Langkah 4 — Saring dan Eksekusi Penjualan:**
   Gunakan Live Filter Bar untuk memfilter sektor atau kata kunci usaha yang sesuai dengan penawaran Anda. Klik tombol **WhatsApp** untuk memulai kontak bisnis, atau klik **Download Excel** untuk membagikan data kepada tim penjualan lapangan.

---

## 11. RENCANA PENGEMBANGAN STRATEGIS (ROADMAP MASA DEPAN)

Sebagai fondasi untuk ekspansi jangka panjang menuju sistem otonom dan berskala internasional, berikut adalah arsitektur masa depan yang disiapkan:

### Fase I: Penguatan Otomasi dan Analisis Pesan (Target Jangka Menengah)
- **Modul Analisis Respon Chat:** Mengintegrasikan webhook perpesanan untuk menganalisis balasan calon klien. AI akan mengklasifikasikan respon menjadi 3 kategori: Tertarik (Hot Lead), Butuh Pertimbangan (Warm Lead), atau Menolak (Cold/Unqualified).
- **Penjadwalan Outreach Mandiri:** Pengiriman follow-up otomatis berdasarkan interval hari tanpa intervensi manual.

### Fase II: Kolaborasi Multi-Agen AI (Ekosistem AI Karyawan)
- **Konsep Agen Kolaboratif:** Membentuk divisi karyawan digital cerdas yang memiliki spesialisasi peran masing-masing:
  - *Agen Riset Pasar:* Bertugas memantau wilayah-wilayah yang memiliki tingkat densitas bisnis baru tinggi.
  - *Agen Copywriter:* Menganalisis sudut penawaran terbaik berdasarkan demografi dan tren industri lokal.
  - *Agen Evaluator & Closer:* Berdiskusi dan bertukar kesimpulan dengan agen lain untuk menentukan strategi penawaran yang paling menghasilkan rasio konversi tinggi.
- **Sistem Pembelajaran Mandiri (Autonomous Feedback Loop):** Sistem secara otomatis mencatat jenis penawaran apa yang paling banyak disetujui klien dan mereplikasi formula tersebut ke wilayah lain.

### Fase III: Ekspansi Pasar Global & Multi-Kanal
- **Dukungan Basis Data Internasional:** Membuka layer scraping wilayah negara-negara Asia Tenggara dan global.
- **Integrasi Cloud Enterprise:** Sinkronisasi basis data terdistribusi (Supabase / PostgreSQL terkelola) untuk menangani jutaan baris data secara bersamaan dengan SLA performa tinggi.

---

*Laporan ini dipelihara secara terpusat sebagai acuan standar operasional dan teknis sistem Cliento.*
