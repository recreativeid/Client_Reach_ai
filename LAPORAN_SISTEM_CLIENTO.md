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

### B. Lapisan Backend (Logika & API)
- **Bahasa Pemrograman:** PHP Native versi 8.x dengan struktur modular berbasis REST API.
- **Basis Data Transaksional:** SQLite 3 dengan mode Write-Ahead Logging (WAL) untuk menjamin kecepatan baca-tulis tinggi dan integritas data tanpa ketergantungan server database eksternal yang rumit.
- **Basis Data Penampung Massal (Master Database):** Berkas data terstruktur JSONL (JSON Lines) per wilayah yang memungkinkan pembacaan baris demi baris berkecepatan tinggi dengan penggunaan memori yang sangat rendah.

### C. Jaringan Ekstraksi Data Eksternal
- **Overpass API (OpenStreetMap):** Mesin kueri data spasial terdistribusi untuk mengekstrak titik-titik usaha komersial, industri, kuliner, pendidikan, kesehatan, dan perkantoran.
- **Nominatim & Photon Engine:** Layanan geocoding untuk pencarian koordinat dan validasi batas wilayah resmi.
- **Google Gemini AI:** Model bahasa cerdas untuk analisis konteks profil bisnis dan penyusunan naskah penjualan adaptif.

---

## 4. ALUR KERJA OPERASIONAL PENGGUNA

Operasional Cliento dirancang dalam 4 menu utama yang terfokus:

```
[ Menu Utama Cliento ]
       │
       ├─► 1. Dashboard (Analitik & Ringkasan Pipeline)
       │
       ├─► 2. Scraping Client (Pusat Pencarian & Basis Data Wilayah)
       │        ├─ Mode 1: Wilayah Administratif (Provinsi -> Kab/Kota -> Garis Batas)
       │        ├─ Mode 2: Titik Peta & Radius Jangkauan (1 - 25 KM)
       │        ├─ Pencarian Terarah (Kata Kunci / Kategori Usaha)
       │        ├─ Fitur Deteksi Lengkap Wilayah (Perekaman Massal)
       │        └─ Penjelajah Master Database (Live Filter Cepat)
       │
       ├─► 3. Template Pesan (Manajemen Skrip Penawaran WhatsApp)
       │
       ├─► 4. Koleksi Arsip (Penyimpanan Berstruktur Google Drive)
       │
       └─► Database Customer (Khusus Administrator)
```

---

## 5. LOGIKA MESIN SCRAPING DAN MASTER DATABASE

### A. Dua Mode Penentuan Wilayah Sasaran

1. **Mode Wilayah Administratif (Batas Garis Merah):**
   Pengguna memilih Provinsi, Kabupaten/Kota, dan secara opsional Kecamatan serta Kelurahan. Peta akan secara otomatis memusatkan tampilan dan menggambar garis batas resmi wilayah tersebut berwarna merah. Seluruh pencarian dan pengambilan data dikunci agar tidak melenceng keluar dari batas wilayah yang dipilih.

2. **Mode Titik Peta & Radius:**
   Pengguna cukup mengeklik titik mana saja pada peta dan mengatur jarak jangkauan melalui penggeser radius (1 KM hingga 25 KM). Sistem akan membentuk lingkaran zona merah dan mengidentifikasi entitas bisnis dalam cakupan tersebut.

### B. Mesin Deteksi Lengkap Wilayah (Perekaman Massal)
Ketika suatu wilayah dipilih, sistem secara otomatis memeriksa ketersediaan data di Master Database lokal:
- **Jika data sudah ada:** Kartu status hijau akan langsung menampilkan jumlah entitas bisnis yang tersedia, rincian kategori utama, dan menyediakan tombol instan "Buka Database".
- **Jika data belum ada atau ingin diperbarui:** Tersedia tombol aksi "Deteksi Seluruh Bisnis di Wilayah Ini". Ketika diklik, sistem memicu proses pemanenan latar belakang yang mengumpulkan seluruh kategori usaha:
  - Perusahaan, perkantoran, PT, CV, dan pabrik
  - Pertokoan, ritel, dan pusat perbelanjaan
  - Kuliner, restoran, kafe, dan warung
  - Sarana kesehatan, klinik, apotek, dan rumah sakit
  - Lembaga pendidikan, sekolah, dan bimbingan belajar
  - Bengkel, otomotif, salon, dan jasa profesional
  - Akomodasi, hotel, dan hiburan

### C. Live Filter Bar pada Master Database Explorer
Saat pengguna membuka Master Database, antarmuka menyediakan bilah filter interaktif:
1. **Pencarian Kata Kunci Real-Time:** Mengetik nama usaha, alamat, atau aktivitas bisnis dengan mekanisme debounce halus 400 milidetik.
2. **Penyaring Kategori Spesifik:** Memilih hanya sektor yang diminati (misalnya hanya Kuliner atau hanya Perusahaan).
3. **Penyaring Kelayakan Kontak:** Tombol cepat untuk memilah bisnis yang memiliki nomor kontak aktif atau yang belum memiliki nomor kontak.

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
