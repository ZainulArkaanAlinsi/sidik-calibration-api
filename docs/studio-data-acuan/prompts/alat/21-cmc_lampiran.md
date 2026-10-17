# Prompt Claude Code 21 — paket `cmc_lampiran` (Pita CMC lampiran akreditasi LK-285-IDN (semua alat))

Mode: **P7 (mode seed) — versikan data yang sudah ada di tabel database**. Tempel seluruh isi berkas ini ke Claude Code yang dibuka di repo
`sidik-calibration-api`.

---

Kerjakan pemindahan paket data acuan **`cmc_lampiran`** ke Studio Data Acuan.

## 0. Gerbang — periksa dulu, berhenti kalau gagal

1. `git pull origin main`, lalu buat branch `feat/acuan-cmc-lampiran`. Jangan bekerja di `main`.
2. Pastikan Gelombang 1 & 2 sudah mendarat: ada model `PaketAcuan`, `PaketAcuanVersi`, kelas
   `App\Services\Calibration\SumberAcuan`, perintah `acuan:impor-awal`, dan `TabelAcuanSetaraJsonTest`.
   Kalau salah satu belum ada: **berhenti**, laporkan yang kurang, jangan membangunnya diam-diam di prompt ini.

## 1. Baca sebelum mengetik

- `AGENTS.md` (§Olah data — aturan keras, §Aturan yang Lahir dari Kesalahan Nyata)
- `docs/studio-data-acuan/03-SDD-ADR.md` §2.6, §4, ADR-03, ADR-04, ADR-08
- `docs/studio-data-acuan/katalog/paket/cmc_lampiran/KARTU.md` dan `skema.json` — **ini peta paketnya**
- Berkas kode:
   - `app/Services/Calibration/TabelStandarHydrometer.php`
   - `app/Services/Calibration/TabelStandarTimbangan.php`
- Pertanyaan lab terbuka: —

Tulis daftar berkas yang AKAN diubah sebelum mulai (AGENTS.md §Alur Kerja 3).

## 2. Kerjakan

1. Data paket ini SUDAH berada di tabel database sesudah seed (`calibration_capabilities`).
   **Jangan membuat salinan kedua.** Versi 1 paket dibangun dari baris tabel itu, bukan dari JSON seed.
2. Rancang `skemaAcuan()` khusus paket lintas alat ini sesuai `skema.json`, lalu jalur baca yang
   memakai versi berlaku pada `tanggal_kalibrasi` sesi (pola `RumusKalibrasi`).
3. Penyuntingan lewat Studio harus memperbarui tabel sumbernya melalui alur versi (draf → simulasi →
   sahkan), bukan menulis langsung. Simulasi wajib menjalankan **semua profil** yang memakai paket ini.
4. Tulis test yang membuktikan seeder lama dan versi 1 menghasilkan angka identik.

## 3. Jangan

- Mengubah rumus, kalkulator, urutan hitung, pembulatan, atau satu angka acuan pun.
- Menyunting `database/data/*.json` dengan tangan (generator saja yang boleh menulisnya).
- Memasukkan nama/alamat pelanggan dari workbook ke berkas mana pun.
- Commit atau push ke `main`. Push branch boleh hanya bila diminta.

## 4. Selesai bila

- Test berikut hijau **tanpa diubah**, di SQLite DAN MySQL (`php artisan test -c phpunit.mysql.xml`):
   - `tests/Unit/HydrometerMasterTest.php`
   - `tests/Unit/TimbanganCmcCocokAkreditasiTest.php`
   - `tests/Unit/TimbanganMasterTest.php`
   - `tests/Unit/VolumetricGlasswareMasterTest.php`
- `TabelAcuanSetaraJsonTest` kasus `cmc_lampiran` hijau.
- `skemaAcuan()` mencakup 100% sel data paket (skrip `alat-bantu/validasi_katalog.py` di ZIP).

## 5. Laporan (tempel balik ke Zainul)

Berkas yang diubah; perintah test + hasilnya (SQLite & MySQL); sha256 versi 1; kolom yang masih
`perlu_konfirmasi`; pertanyaan lab baru (bernomor); risiko tersisa.
