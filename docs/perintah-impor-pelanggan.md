# `customers:impor` — memasukkan pelanggan historis lab

**Untuk siapa:** admin lab yang memegang arsip pelanggan (Excel, buku order, daftar sertifikat lama).
**Tanggal:** 2 September 2026
**Berdiri sendiri:** ya. Tidak perlu membaca dokumen lain untuk menjalankannya.

---

## 1. Kenapa ini yang dikerjakan duluan

Yang bikin teknisi mengetik ulang nama & alamat PT **bukan** perusahaan yang belum pernah dilayani
— itu justru jarang. Yang sering: pelanggan **lama** lab yang belum pernah masuk `customers`,
karena arsipnya masih di Excel.

Begitu mereka masuk, sebagian besar keluhan "ngetik ulang" hilang **tanpa satu rupiah pun keluar**
untuk langganan direktori berbayar.

## 2. Kenapa berhati-hati soal alamat

`certificates.snapshot` membekukan data pelanggan **sebagaimana saat sertifikat terbit**. Itu
perilaku yang benar — sertifikat harus mencerminkan keadaan waktu diterbitkan.

Konsekuensinya keras: **alamat yang salah waktu impor akan ikut tercetak, dan memperbaiki
`customers` besok tidak memperbaiki sertifikat yang sudah dipegang pelanggan.** Yang bisa dilakukan
cuma menerbitkan versi baru, dan itu urusan mutu — bukan urusan edit database.

Karena itu perintah ini **berhenti di laporan** untuk baris yang meragukan, bukan menebak.

---

## 3. Siapkan berkasnya

### 3.1 Dari Excel ke CSV

Buka daftar pelanggan di Excel → **File → Save As** → pilih **CSV**. Itu saja.

> Kalau ada kolom **nomor telepon**, sorot kolomnya dulu → **Format Cells → Text**, baru simpan.
> Kalau tidak, Excel menyimpan `081234567890` sebagai **angka**, dan yang keluar di CSV
> `8.1234567890E+11`. Perintah ini mengenali bentuk itu, **mengosongkan** teleponnya, dan menulis
> peringatan — jadi datanya tidak rusak diam-diam, tapi teleponnya juga tidak ikut masuk.

### 3.2 Kolom yang dikenali

Cuma **`nama` yang wajib**. Sisanya opsional. Judul kolomnya tidak harus persis — huruf besar-kecil
dan tanda baca diabaikan:

| Tujuan | Judul yang diterima |
|---|---|
| `nama` | `nama`, `nama pt`, `nama perusahaan`, `perusahaan`, `pelanggan`, `customer`, `nama pelanggan`, `nama customer` |
| `alamat` | `alamat`, `address`, `alamat perusahaan`, `alamat pt`, `alamat lengkap` |
| `contact_person` | `contact person`, `cp`, `pic`, `kontak`, `narahubung`, `nama kontak` |
| `telepon` | `telepon`, `telp`, `no telp`, `nomor telepon`, `no telepon`, `hp`, `no hp`, `nomor hp`, `phone`, `telephone` |
| `email` | `email`, `e-mail`, `surel`, `alamat email` |

Berkas satu kolom (nama PT saja) **tetap sah**. Itu bentuk yang wajar untuk impor pertama.

Yang tidak perlu dipikirkan, karena sudah ditangani sendiri:

- pemisah `;` (Excel berlokal Indonesia) maupun `,`, tab, atau `|`;
- BOM di depan header;
- berkas yang bukan UTF-8 (ANSI/Windows-1252);
- baris kosong di ujung berkas;
- spasi ganda dan spasi tanpa putus hasil salin-tempel.

---

## 4. Jalankan

### 4.0 Pastikan dulu database mana yang kena — ini bukan formalitas

Server Render paket gratis **tidak punya Shell**, jadi perintah ini dijalankan dari laptop. Dan
`.env` laptop biasanya menunjuk MySQL laptop, bukan database yang dipakai teknisi.

Kalau itu yang terjadi, impornya **tetap berhasil**: laporannya hijau, "142 pelanggan masuk",
tidak ada satu pun pesan error — tapi barisnya mendarat di laptop dan teknisi tidak melihat
apa-apa.

```bash
php artisan db:show
```

Lihat baris **Host**. `127.0.0.1` atau `localhost` = MySQL laptop.

Perintah impornya juga menyebutkan tujuannya sendiri di baris pertama, setiap kali dijalankan:

```
Tujuan: koneksi `mysql` — host 127.0.0.1, database sidik_db.
```

Baca baris itu sebelum melanjutkan.

### 4.1 Menulis ke database produksi

Isi kunci `DB_PRODUKSI_*` di `.env` **laptop** (nilainya dari dashboard Render → service →
Environment; `DB_PRODUKSI_SSL_CA` menunjuk berkas `.pem` dari penyedia database). Lalu tambahkan
`--koneksi=produksi`.

`.env` utama tidak perlu diubah sama sekali — itu justru alasan opsi ini ada. Menukar `DB_*`
sebentar juga bisa, tapi selama tertukar **setiap** perintah artisan dari laptop mengenai database
asli, termasuk `migrate:fresh` yang tidak sengaja, dan tidak ada yang mengingatkan kalau lupa
dikembalikan.

Kalau kuncinya belum diisi, `--koneksi=produksi` **berhenti** dan menyebutkan kunci mana yang
kosong. Sengaja begitu: koneksi `produksi` ditulis tanpa nilai bawaan supaya tidak bisa diam-diam
jatuh ke MySQL laptop.

### 4.2 Perintahnya

**Selalu `--uji-coba` dulu.** Sekali. Lalu baca laporannya.

```bash
php artisan customers:impor daftar-pelanggan.csv \
    --organization=1 \
    --oleh=7 \
    --laporan=storage/app/tinjauan-impor.csv \
    --uji-coba
```

Kalau laporannya sudah benar, jalankan lagi **tanpa** `--uji-coba`.

| Opsi | Wajib | Arti |
|---|---|---|
| `berkas` | ya | path CSV-nya |
| `--organization=` | **ya** | ID organisasi tujuan. Berkas tidak boleh menentukan organisasinya sendiri — satu berkas salah taruh berarti pelanggan mendarat di lab lain |
| `--oleh=` | tidak | ID user yang bertanggung jawab. Ikut ke `customers.dibuat_oleh_user_id` **dan** ke riwayat `audit_logs`. Kalau kosong, barisnya lahir tanpa penanggung jawab — jujur, tapi asesor akan menanyakannya |
| `--sumber=` | tidak | `admin` (bawaan), `teknisi`, atau `direktori` |
| `--laporan=` | tidak | path CSV hasil tinjauan. **Isi selalu** |
| `--uji-coba` | tidak | jalan tanpa menulis apa pun |
| `--koneksi=` | tidak | koneksi database tujuan. Kosong = koneksi default `.env`. Pakai `produksi` untuk menulis ke database server dari laptop — lihat §4.1 |

---

## 5. Membaca laporannya

Kolom `keranjang` menentukan nasib tiap baris:

| Keranjang | Artinya | Nasibnya |
|---|---|---|
| `baru` | belum ada yang mirip | **ditulis** |
| `kembar_pasti` | sudah ada di database, atau muncul dua kali di berkas ini | **dilewati** |
| `perlu_tinjau` | ada yang **mirip**, tapi belum tentu sama | **TIDAK ditulis** |
| `ditolak` | barisnya tidak terbaca (nama kosong, atau nama > 255 huruf) | **TIDAK ditulis** |

Kolom `lawan` menyebut nama yang jadi pembandingnya, dan `sebab` menjelaskan kenapa.

### Yang harus dilakukan untuk `perlu_tinjau`

Perintah ini **tidak** menggabungkan apa pun. Untuk tiap baris `perlu_tinjau`, putuskan sendiri:

- **Memang perusahaan yang sama** → hapus barisnya dari CSV, lalu jalankan lagi. Yang di database
  sudah benar.
- **Perusahaan lain** → bedakan namanya di CSV (misalnya tambahkan kota atau cabangnya), lalu
  jalankan lagi.

### `PT` dan `CV` tidak pernah digabung

`PT Maju Jaya` dan `CV Maju Jaya` itu **dua badan hukum dengan NPWP berbeda**. Keduanya masuk
sebagai baris terpisah dan tidak pernah muncul sebagai kandidat kembar satu sama lain — walaupun
namanya cuma beda dua huruf. Hal yang sama berlaku untuk `UD`/`PD`, `Firma`, `Koperasi`, dan
`Yayasan`.

---

## 6. Janji yang dipegang perintah ini

1. **Aman dijalankan berkali-kali.** Jalan kedua dengan berkas yang sama tidak menambah satu baris
   pun. Kalau ragu apakah impornya sudah jalan, jalankan lagi.
2. **Tidak pernah meng-update baris yang sudah ada.** Alamat yang sudah dibetulkan admin di panel
   **tidak** akan tertimpa oleh berkas Excel lama.
3. **Tidak pernah menebak alamat.** Baris tanpa alamat masuk dengan alamat **kosong** — bukan
   placeholder. Alamat kosong bisa dilihat dan dilengkapi; alamat karangan kelihatan lengkap dan
   ikut tercetak.
4. **Semua atau tidak sama sekali.** Kalau ada yang gagal di tengah, seluruh impor dibatalkan.
   Tidak ada keadaan "setengah masuk".
5. **Tercatat.** Tiap baris masuk ke `audit_logs`.

---

## 7. Kalau gagal

| Pesan | Sebabnya | Obatnya |
|---|---|---|
| `tidak punya kolom nama` | judul kolomnya tidak dikenali | samakan dengan tabel §3.2, atau ganti jadi `nama` |
| `Koneksi X belum disetel: host, database, username masih kosong` | `--koneksi=produksi` dipakai tapi `DB_PRODUKSI_*` belum diisi | isi kuncinya di `.env` laptop, §4.1. **Nol baris ditulis** |
| `Koneksi X tidak ada di config/database.php` | nama koneksinya salah ketik | yang dikenal: `mysql`, `produksi` |
| `--organization wajib diisi` | opsinya tidak diisi | isi ID organisasinya |
| `Organisasi N tidak ada` | ID-nya salah | cek daftar organisasi |
| `User N tidak ada di organisasi N` | `--oleh` orang dari lab lain | pakai user dari organisasi tujuan |
| `Berkas kosong` / `tidak bisa dibaca` | path salah atau berkasnya kosong | cek path-nya |
| `Impor dibatalkan, tidak ada baris yang masuk` | ada kegagalan database di tengah | baca pesannya; tidak ada baris yang masuk, jadi aman diulang |

---

## 8. Yang perintah ini **tidak** kerjakan

- **Tidak menggabungkan kembar yang sudah terlanjur ada.** Itu butuh orang yang memutuskan mana
  yang dipertahankan, plus pemindahan relasi `orders`/`equipments`/`folders`/`calibration_sessions`.
- **Tidak memverifikasi alamat** ke sumber mana pun, dan tidak melakukan geocoding.
- **Tidak membaca xlsx langsung.** Perlu "Save As CSV" satu kali. Menambahkan pembaca xlsx berarti
  dependensi baru untuk perintah yang jalan beberapa kali seumur hidup lab.
- **Tidak menyentuh direktori luar** (Google Places / OpenStreetMap). Itu jalur terpisah, dipakai
  waktu teknisi mencari pelanggan yang **belum pernah** dilayani.
