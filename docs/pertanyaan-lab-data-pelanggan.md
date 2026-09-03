# Pertanyaan lab — data pelanggan (nama PT & alamat)

**Sumber:** "STRATEGI DATA PELANGGAN — Nama PT & Alamat", 2 Sep 2026.

**Statusnya sekarang:** Milestone A (`php artisan customers:impor`) **sudah jalan** dan bisa
dipakai hari ini — lihat `docs/perintah-impor-pelanggan.md`. Yang di bawah ini **bukan blokir**
buat itu. Ini hal-hal yang cuma pemilik lab yang boleh memutuskan, dan sampai diputuskan kode
**tidak menebak**.

**P1 sudah dijawab 2 Sep 2026** (tetap `osm`). Sisa yang menunggu: P2, P3, P4.

---

## ~~P1~~ — Direktori luar: tetap `osm`, atau pindah ke `auto` dengan tagihan?

> **DIJAWAB 2 Sep 2026 — TETAP `osm`.** Keputusan pemilik proyek, menegaskan K16 (nol tagihan),
> bukan mengubahnya. **Nol perubahan kode**: ketiga tempat yang menentukan sudah `osm` dan
> diverifikasi hari itu juga — `config/services.php:190` (bawaan `'osm'`), `.env.example:255`, dan
> `render.yaml:189` yang **memaku** `value: osm` alih-alih `sync: false`.
>
> Yang ikut disetujui bersama keputusan ini, supaya tidak dilaporkan sebagai kerusakan nanti:
> pabrik di kawasan industri yang belum dipetakan sukarelawan **memang tidak akan ketemu**, dan
> teknisi menutupinya lewat `POST /customers/cepat`. Jalur Google tetap mati total selama
> `DIREKTORI_PERUSAHAAN_KEY` kosong.
>
> Isi §di bawah ini disimpan **apa adanya** sebagai bahan kalau suatu saat keputusannya ditinjau
> ulang — terutama tiga syarat wajib yang menyertai `auto`.


**Keadaan sekarang:** `DIREKTORI_PERUSAHAAN_DRIVER=osm`. Nol tagihan, tapi cakupannya tipis —
pabrik di kawasan industri yang belum dipetakan sukarelawan memang tidak akan ketemu. Itu
**setelan, bukan bug**.

**Kenapa ini ditanya, bukan diputuskan sendiri.** Bawaannya `osm` adalah **keputusan pemilik
proyek 31 Agt 2026** (K16 `docs/permintaan-user-7.md`): *nol tagihan*, penyedianya pindah ke
Nominatim. Mengubahnya ke `auto` membatalkan keputusan itu dan memunculkan tagihan per request.

| Pilihan | Cakupan | Biaya |
|---|---|---|
| `osm` (sekarang) | tipis untuk kawasan industri | nol |
| `auto` | Google dulu, OSM di belakang | kuota bebas bulanan, di atas itu ditagih per request |

**Yang dibutuhkan dari lab:** ya/tidak untuk `auto` + API key Places kalau ya.

**Nol kode.** Keempat variabelnya sudah ada di `.env.example`, `auto` sudah didukung
`PilihanDriver`, dan `GET /health` sudah melaporkan `direktori_perusahaan.disetel`.

**Kalau ya, tiga hal wajib menyertainya:**

1. **Batas kuota dipasang di konsol penyedia.** `DirektoriBercache` melindungi dari pencarian
   **berulang**, bukan dari pencarian **baru** yang membanjir.
2. **Key tidak pernah masuk APK** — key di dalam berkas APK bisa dicabut siapa pun lalu dipakai
   atas tagihan lab. HP menembak endpoint lab; lab yang memegang key. (Rancangan yang ada sudah
   begitu; ini catatan supaya tidak "dipermudah" nanti.)
3. **Harga & kuota diverifikasi saat itu.** Angka kuota bebas di komentar `config/services.php`
   ditulis per Maret 2025 dan bisa sudah berubah — jangan dipakai sebagai dasar anggaran tanpa
   dicek ulang.

---

## P2 — Arsip pelanggan lab: berkasnya di mana, dan siapa penanggung jawab impornya?

`customers:impor` sudah siap, tapi belum ada yang bisa diimpor sampai berkasnya ada.

> **Jalur ke produksi sudah dibereskan 3 Sep 2026** — bagian ini tadinya belum tertulis di mana
> pun, dan diam-diam bikin P2 tidak bisa dituntaskan meski berkasnya sudah ada.
>
> Render paket gratis tidak punya Shell, jadi perintahnya jalan dari laptop. `php artisan db:show`
> di laptop pemilik proyek menjawab `Host 127.0.0.1, Database sidik_db` — **MySQL laptop, bukan
> produksi**. Impor yang dijalankan begitu saja akan berhasil, laporannya hijau, dan teknisi tidak
> melihat apa-apa. Nol pesan error.
>
> Solusinya koneksi `produksi` terpisah + opsi `--koneksi=` (keputusan pemilik proyek, memilihnya
> di atas "tukar `DB_*` sebentar" karena `.env` yang lupa dikembalikan bikin **setiap** perintah
> artisan berikutnya mengenai database asli). Lihat `docs/perintah-impor-pelanggan.md` §4.0–4.1.
>
> Yang **ditolak** dan jangan diusulkan lagi: menaruh CSV pelanggan di repo lalu diimpor saat boot,
> meniru direktori perusahaan. Untuk direktori itu benar — datanya publik. Untuk pelanggan tidak:
> §9.1 strategi menyebut `contact_person`/`telepon`/`email` sebagai data pribadi (UU 27/2022), dan
> §10 poin 9 melarang mengeluarkannya dari kendali lab. Repo git + image Docker di infrastruktur
> penyedia adalah persis itu, dan riwayat git-nya permanen.

**Yang dibutuhkan dari lab:**

1. **Berkas daftar pelanggan** (Excel/CSV). Minimum satu kolom `nama`. Kalau ada alamat, ikut.
2. **ID user penanggung jawab** untuk `--oleh`. Ini bukan formalitas: tanpa itu barisnya lahir
   tanpa siapa-siapa di `audit_logs` maupun di `customers.dibuat_oleh_user_id`, dan riwayat impor
   tanpa penanggung jawab persis yang ditanya asesor.

> Kalau kolom telepon ada, sorot kolomnya di Excel → **Format Cells → Text** sebelum menyimpan
> CSV. Kalau tidak, `081234567890` tersimpan sebagai angka dan keluar sebagai `8.1234567890E+11`.
> Perintahnya mengenali bentuk itu dan **mengosongkan** teleponnya + menulis peringatan — datanya
> tidak rusak diam-diam, tapi teleponnya juga tidak ikut masuk.

---

## P3 — Baris `perlu_tinjau`: siapa yang memutuskan?

Impor **tidak menggabungkan apa pun**. Baris yang namanya mirip pelanggan yang sudah ada berhenti
di laporan dan tidak pernah masuk database.

Keputusannya per baris cuma dua: *perusahaan yang sama* (hapus dari CSV, yang di database sudah
benar) atau *perusahaan lain* (bedakan namanya, misal tambah kota/cabang).

**Yang dibutuhkan dari lab:** siapa yang berwenang memutuskan ini. Jawabannya menentukan apakah
Milestone C (aksi gabung di panel admin) perlu dibangun, atau cukup laporan CSV saja.

---

## P4 — Verifikasi alamat: perlu status terverifikasi, atau cukup apa adanya?

Milestone D mengusulkan `alamat_terverifikasi_pada` + `alamat_diverifikasi_oleh`, plus
**peringatan** (bukan blokir) saat menerbitkan sertifikat dengan alamat yang belum terverifikasi.

**Kenapa ini ditanya.** Peringatan yang terlalu sering muncul melatih admin menekan "terbitkan
saja" tanpa membaca — dan itu **lebih buruk** daripada tidak ada peringatan, karena peringatan
berikutnya yang beneran penting ikut tidak terbaca. Jebakan ini sudah pernah terbukti di proyek
ini (peringatan sesi palsu dari tabel akreditasi yang terbaca sebagian).

Jadi ini cuma layak dibangun kalau sebagian besar alamat memang akan diverifikasi. Kalau
tidak, yang lahir cuma peringatan yang selalu menyala.

**Yang dibutuhkan dari lab:** apakah ada proses pencocokan alamat ke surat order / NPWP
pelanggan. Kalau tidak ada, D **tidak dibangun**.

---

## Yang TIDAK akan dikerjakan, dan alasannya

Ditulis di sini supaya tidak ditanyakan ulang tiap beberapa bulan:

- **Seed daftar PT se-Indonesia.** Tidak ada sumbernya. AHU punya nama badan hukum resmi tapi
  tanpa API publik; Google Places dan OpenStreetMap punya API tapi isinya alamat **peta**, bukan
  alamat **akta**; OSS/BKPM cuma untuk mitra berizin. Yang bisa ditulis dari ingatan itu karangan,
  dan karangannya berbentuk wajar sehingga tidak ada yang curiga.
- **Scraping AHU / OSS / situs direktori.** Melanggar ketentuan pemakaian. Untuk lab
  terakreditasi, sumber data yang cara perolehannya bermasalah adalah risiko yang tidak sebanding
  dengan waktu mengetik yang dihemat.
- **Bulk geocoding lewat Nominatim.** Dilarang kebijakan pemakaiannya, dan yang diblokir alamat
  IP servernya — bukan satu request.
- **Auto-geocode alamat yang diketik tangan.** Menambah tebakan ke data yang ujungnya masuk
  dokumen resmi.
- **Memecah alamat jadi provinsi/kota/kecamatan.** Format alamat Indonesia terlalu bervariasi, dan
  pemecahan yang salah lebih buruk daripada string utuh yang benar.

Semuanya berpangkal pada satu hal yang sama: `certificates.snapshot` membekukan data pelanggan
saat sertifikat terbit, jadi **alamat yang salah tidak bisa ditarik**. Memperbaiki `customers`
besok tidak memperbaiki sertifikat yang sudah dipegang pelanggan.
