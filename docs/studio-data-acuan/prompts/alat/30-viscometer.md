# Prompt Claude Code 30 — paket `viscometer` (Viscometer)

Mode: **P8 — ekstrak konstanta dari kode ke paket**. Tempel seluruh isi berkas ini ke Claude Code yang dibuka di repo
`sidik-calibration-api`.

---

Kerjakan pemindahan paket data acuan **`viscometer`** ke Studio Data Acuan.

## 0. Gerbang — periksa dulu, berhenti kalau gagal

1. `git pull origin main`, lalu buat branch `feat/acuan-viscometer`. Jangan bekerja di `main`.
2. Pastikan Gelombang 1 & 2 sudah mendarat: ada model `PaketAcuan`, `PaketAcuanVersi`, kelas
   `App\Services\Calibration\SumberAcuan`, perintah `acuan:impor-awal`, dan `TabelAcuanSetaraJsonTest`.
   Kalau salah satu belum ada: **berhenti**, laporkan yang kurang, jangan membangunnya diam-diam di prompt ini.

## 1. Baca sebelum mengetik

- `AGENTS.md` (§Olah data — aturan keras, §Aturan yang Lahir dari Kesalahan Nyata)
- `docs/studio-data-acuan/03-SDD-ADR.md` §2.6, §4, ADR-03, ADR-04, ADR-08
- `docs/studio-data-acuan/katalog/paket/viscometer/KARTU.md` dan `skema.json` — **ini peta paketnya**
- Berkas kode:
   - `app/Services/Calibration/Profiles/ViscometerProfile.php`
- Pertanyaan lab terbuka: `docs/pertanyaan-lab-viscometer.md`

Tulis daftar berkas yang AKAN diubah sebelum mulai (AGENTS.md §Alur Kerja 3).

## 2. Kerjakan

1. Golongkan setiap baris di KARTU §"Kandidat nilai acuan yang masih ditulis di kode" menjadi
   lapis 1 / 2 / 3 memakai aturan AGENTS.md §Olah data butir 1. Tulis tabel golongannya di laporan.
   Yang ragu → tulis sebagai pertanyaan bernomor di `docs/pertanyaan-lab-viscometer.md`, jangan diputuskan sendiri.
2. Buat `database/data/acuan-viscometer.json` berisi nilai lapis 1 **persis** seperti di kode
   (angka sebagai string kanonik, ADR-08). Tiap nilai diberi `_asal` = berkas:baris kode hari ini.
3. Refactor profil supaya nilai lapis 1 dibaca lewat `SumberAcuan` (bukan konstanta). Perilaku tidak boleh berubah.
4. Tambahkan `skemaAcuan()` di profil, cocok dengan isi JSON baru.
5. `php artisan acuan:impor-awal viscometer` → versi 1. Tambahkan kasus `viscometer` ke `TabelAcuanSetaraJsonTest`
   yang membuktikan float yang sampai ke kalkulator identik bit dengan konstanta lama.
6. Nilai yang sudah ada di tabel `standards` (U, k, drift standar) **jangan diduplikasi** ke paket — rujuk saja.

## 3. Jangan

- Mengubah rumus, kalkulator, urutan hitung, pembulatan, atau satu angka acuan pun.
- Menyunting `database/data/*.json` dengan tangan (generator saja yang boleh menulisnya).
- Memasukkan nama/alamat pelanggan dari workbook ke berkas mana pun.
- Commit atau push ke `main`. Push branch boleh hanya bila diminta.

## 4. Selesai bila

- Test berikut hijau **tanpa diubah**, di SQLite DAN MySQL (`php artisan test -c phpunit.mysql.xml`):
   - `tests/Feature/BentukPindaiFotoCocokTabelTest.php`
   - `tests/Feature/PindaiViscometerTest.php`
   - `tests/Feature/ViscometerApiTest.php`
   - `tests/Feature/ViscometerMasterBaruTest.php`
   - `tests/Feature/ViscometerSesiLainTest.php`
   - `tests/Unit/ViscometerBudgetTest.php`
   - `tests/Unit/ViscometerDataLainTest.php`
- `TabelAcuanSetaraJsonTest` kasus `viscometer` hijau.
- `skemaAcuan()` mencakup 100% sel data paket (skrip `alat-bantu/validasi_katalog.py` di ZIP).

## 5. Laporan (tempel balik ke Zainul)

Berkas yang diubah; perintah test + hasilnya (SQLite & MySQL); sha256 versi 1; kolom yang masih
`perlu_konfirmasi`; pertanyaan lab baru (bernomor); risiko tersisa.
