# Walkthrough: Google Drive Style Nested Folders & Archive Management

Dokumentasi implementasi fitur pengelolaan arsip bertingkat gaya **Google Drive**, integrasi tombol **Riwayat Scraping**, penamaan arsip kustom, serta pembersihan elemen ROI Dashboard pada **Client Reach AI (Workflow AI Sales)**.

---

## Ringkasan Perubahan

### 1. Pembersihan Dashboard (Sesuai Permintaan)
- **Dihapus:** Bagian *Kalkulator Penghematan Biaya Iklan (Ad Spend Savings ROI)*, teks *100% Hemat Biaya Iklan*, *Estimasi Penghematan Anggaran Iklan Rp 900.000*, serta 3 matriks konversi (*Prospek Siap Closing*, *Target Jasa Reputasi*, *Peluang Jasa Pembuatan Website*).
- **Tampilan Baru:** Dashboard kini tampil bersih, terfokus pada 4 metrik kinerja utama (Total Leads, Prospek Terkualifikasi, Arsip Tersimpan, WhatsApp Terhubung) dan 2 grafik profesional Chart.js (Tren Pipeline Sales & Sebaran Industri).

### 2. Tombol Riwayat Scraping yang Mudah Diakses
- Tombol **Riwayat Scraping** tersedia di 2 lokasi strategis:
  1. Pada panel input parameter pencarian (`#btn-show-history`).
  2. Pada header tabel hasil scraping (`#btn-show-history-results`).
- Modal **Riwayat Scraping Google Maps** menampilkan seluruh log sesi scraping sebelumnya dengan 3 aksi instan per baris:
  - `[ 👁️ Buka Data ]`: Memuat ulang data sesi ke tabel scraping aktif.
  - `[ 📥 Simpan ke Arsip ]`: Membuka modal penyimpanan langsung ke folder Google Drive pilihan.
  - `[ 📊 Excel ]`: Mengunduh sesi tersebut langsung menjadi file `.xlsx`.

### 3. Penamaan Kustom Simpanan Arsip
- Saat menekan tombol **"Simpan ke Arsip"** (baik dari hasil scraping langsung maupun dari riwayat scraping):
  - Pengguna bebas memberi nama simpanan data (misal: `Data Cafe Magelang - 24 September`).
  - Disediakan dropdown pemilihan folder tujuan yang tersusun bertingkat (*hierarchical indented tree*).
  - Terdapat tombol cepat inline **`+ Buat Folder Baru`** di dalam modal agar pengguna bisa langsung membuat folder baru tanpa perlu berpindah halaman.

### 4. Arsitektur Arsip Bertingkat Gaya Google Drive (*Folder di dalam Folder*)
- **Dukungan Subfolder Tak Terbatas:** Pengguna bisa membuat struktur folder bersarang (misal: Folder Bulan `September 2026` $\rightarrow$ Folder Tanggal `24-09-2026` $\rightarrow$ File data Excel di dalamnya).
- **Toolbar Breadcrumbs Interaktif:** Menampilkan path navigasi (`📁 Arsip Utama / 📁 September 2026 / 📁 24-09-2026`) yang dapat diklik untuk melompat ke level folder mana pun.
- **Tombol "Folder Atas":** Memudahkan navigasi naik 1 tingkat hierarki.
- **Grid Folder & File Data:**
  - Kartu Folder berwarna emas dengan badge jumlah subfolder dan jumlah file data di dalamnya, serta tombol hapus folder.
  - Kartu File Data berikon Excel hijau (`.xlsx`) yang menampilkan jumlah kontak leads, tanggal simpan, tombol `[ 👁️ Buka Data ]`, tombol `[ 📊 Excel ]` (unduh langsung), dan tombol `[ 🗑️ Hapus ]`.
- **Tampilan Leads di dalam File:**
  - Menampilkan kartu visual prospek & tabel lengkap dengan penandaan status instan (Centang Hijau untuk Prospek, Silang Merah untuk Ditolak, dan Putih untuk Netral) serta tombol chat WhatsApp 1-klik.

---

## Verifikasi & Pengujian Sistem

| No | Kasus Pengujian | Hasil yang Diharapkan | Status |
|:---|:---|:---|:---:|
| 1 | Penghapusan ROI Calculator pada Dashboard | Elemen ROI & 3 matriks hilang; layout rapi & tanpa error console | **Berhasil (Pass)** |
| 2 | Pembuatan Folder Utama (`September 2026`) | Folder berhasil dibuat di root (`parent_id = null`) | **Berhasil (Pass)** |
| 3 | Pembuatan Subfolder Bersarang (`24-09-2026`) | Subfolder tersimpan di bawah folder `September 2026` (`parent_id = 3`) | **Berhasil (Pass)** |
| 4 | Breadcrumbs Navigasi Google Drive | Breadcrumbs menampilkan `Arsip Utama / September 2026 / 24-09-2026` | **Berhasil (Pass)** |
| 5 | Simpan Data Scraping dengan Nama Kustom | Data tersimpan sebagai file di subfolder `24-09-2026` dengan nama kustom | **Berhasil (Pass)** |
| 6 | Simpan Riwayat Scraping ke Folder Arsip | Riwayat scraping berhasil dikloning ke file arsip di folder yang dipilih | **Berhasil (Pass)** |
| 7 | Toggle Status Prospek (Hijau/Merah/Putih) | Status baris berhasil diubah via API dan tersimpan di database | **Berhasil (Pass)** |
| 8 | Unduh Excel File Data Langsung | SheetJS menghasilkan workbook `.xlsx` langsung dari file kartu arsip | **Berhasil (Pass)** |

---

## Panduan Penggunaan Singkat

1. **Membuat Folder & Subfolder:**
   - Masuk ke menu **Koleksi Arsip**.
   - Klik **"+ Buat Folder Baru"**, beri nama misal `September 2026`.
   - Klik folder `September 2026` untuk membukanya.
   - Klik lagi **"+ Buat Folder Baru"**, beri nama tanggal misal `24-09-2026`.
2. **Menyimpan Data Scraping ke Folder:**
   - Pada halaman **Scraping Client** setelah proses scraping selesai (atau dari modal **Riwayat Scraping** $\rightarrow$ klik tombol **Simpan**).
   - Masukkan nama file data yang diinginkan.
   - Pilih folder tujuan (misal: `📁 September 2026 ↳ 📁 24-09-2026`).
   - Klik **"Simpan Data ke Arsip"**.
3. **Membuka Data & Unduh Excel:**
   - Buka menu **Koleksi Arsip**, masuk ke folder terkait.
   - Klik tombol **"Buka Data"** untuk meninjau prospek dan kirim pesan WhatsApp, atau klik **"Excel"** untuk mengunduh spreadsheet langsung.
