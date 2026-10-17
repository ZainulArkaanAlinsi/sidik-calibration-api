# Prompt Claude Code 10 — paket `gaya` (Gaya — UTM, Load Cell, Proving Ring)

Mode: **P7 — pindahkan tabel JSON ke SumberAcuan ber-versi**. Tempel seluruh isi berkas ini ke Claude Code yang dibuka di repo
`sidik-calibration-api`.

---

Kerjakan pemindahan paket data acuan **`gaya`** ke Studio Data Acuan.

## 0. Gerbang — periksa dulu, berhenti kalau gagal

1. `git pull origin main`, lalu buat branch `feat/acuan-gaya`. Jangan bekerja di `main`.
2. Pastikan Gelombang 1 & 2 sudah mendarat: ada model `PaketAcuan`, `PaketAcuanVersi`, kelas
   `App\Services\Calibration\SumberAcuan`, perintah `acuan:impor-awal`, dan `TabelAcuanSetaraJsonTest`.
   Kalau salah satu belum ada: **berhenti**, laporkan yang kurang, jangan membangunnya diam-diam di prompt ini.

## 1. Baca sebelum mengetik

- `AGENTS.md` (§Olah data — aturan keras, §Aturan yang Lahir dari Kesalahan Nyata)
- `docs/studio-data-acuan/03-SDD-ADR.md` §2.6, §4, ADR-03, ADR-04, ADR-08
- `docs/studio-data-acuan/katalog/paket/gaya/KARTU.md` dan `skema.json` — **ini peta paketnya**
- Berkas kode:
   - `app/Services/Calibration/TabelStandarGaya.php`
   - `app/Services/Calibration/GayaCalculator.php`
   - `app/Services/Calibration/Profiles/GayaProfile.php`
   - `app/Services/Calibration/Profiles/UtmProfile.php`
   - `app/Services/Calibration/Profiles/LoadCellProfile.php`
   - `app/Services/Calibration/Profiles/ProvingRingProfile.php`
- Pertanyaan lab terbuka: `docs/pertanyaan-lab-gaya.md`

Tulis daftar berkas yang AKAN diubah sebelum mulai (AGENTS.md §Alur Kerja 3).

## 2. Kerjakan

1. Tambahkan `skemaAcuan()` pada profil `GayaProfile`, `UtmProfile`, `LoadCellProfile`, `ProvingRingProfile`: lembar, kolom, tipe, kunci baris, boleh tambah
   baris atau tidak — sesuai `skema.json`. Satuan diambil dari komentar/kode/workbook, **bukan** dari
   `satuan_tebakan`. Kolom yang asal selnya belum terbukti diberi `asal: perlu_konfirmasi`.
2. Ubah `TabelStandarGaya` supaya membaca isi versi lewat `SumberAcuan`. **Buang cache statis per
   proses** (`private static ?array $data`) — ganti cache per (versi_id, sha256) (03-SDD §4).
3. `php artisan acuan:impor-awal gaya` → versi 1 `aktif`; sha256 kanonik isi = sha256 kanonik
   `database/data/tabel-standar-gaya.json`.
4. Tambahkan kasus `gaya` ke `TabelAcuanSetaraJsonTest`: setiap float yang diberikan ke kalkulator
   identik bit antara jalur JSON lama dan jalur versi.
5. Bila paket ini dibaca profil lain (—), jalankan juga test mereka.

## 3. Jangan

- Mengubah rumus, kalkulator, urutan hitung, pembulatan, atau satu angka acuan pun.
- Menyunting `database/data/*.json` dengan tangan (generator saja yang boleh menulisnya).
- Memasukkan nama/alamat pelanggan dari workbook ke berkas mana pun.
- Commit atau push ke `main`. Push branch boleh hanya bila diminta.

## 4. Selesai bila

- Test berikut hijau **tanpa diubah**, di SQLite DAN MySQL (`php artisan test -c phpunit.mysql.xml`):
   - `tests/Feature/GayaDariHpTest.php`
   - `tests/Feature/GayaSesiContohCocokMasterTest.php`
   - `tests/Feature/GayaSesiSimpanTest.php`
   - `tests/Feature/GayaValidasiSesiTest.php`
   - `tests/Unit/GayaBudgetTest.php`
   - `tests/Unit/GayaCalculatorTest.php`
   - `tests/Unit/GayaValidasiTitikTest.php`
   - `tests/Unit/ProvingRingMasterTest.php`
- `TabelAcuanSetaraJsonTest` kasus `gaya` hijau.
- `skemaAcuan()` mencakup 100% sel data paket (skrip `alat-bantu/validasi_katalog.py` di ZIP).

## 5. Laporan (tempel balik ke Zainul)

Berkas yang diubah; perintah test + hasilnya (SQLite & MySQL); sha256 versi 1; kolom yang masih
`perlu_konfirmasi`; pertanyaan lab baru (bernomor); risiko tersisa.
