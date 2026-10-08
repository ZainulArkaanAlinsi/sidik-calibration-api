# Prompt Claude Code 16 — paket `enclosure` (Enclosure — oven, inkubator, bath, furnace, refrigerator)

Mode: **P7 — pindahkan tabel JSON ke SumberAcuan ber-versi**. Tempel seluruh isi berkas ini ke Claude Code yang dibuka di repo
`sidik-calibration-api`.

---

Kerjakan pemindahan paket data acuan **`enclosure`** ke Studio Data Acuan.

## 0. Gerbang — periksa dulu, berhenti kalau gagal

1. `git pull origin main`, lalu buat branch `feat/acuan-enclosure`. Jangan bekerja di `main`.
2. Pastikan Gelombang 1 & 2 sudah mendarat: ada model `PaketAcuan`, `PaketAcuanVersi`, kelas
   `App\Services\Calibration\SumberAcuan`, perintah `acuan:impor-awal`, dan `TabelAcuanSetaraJsonTest`.
   Kalau salah satu belum ada: **berhenti**, laporkan yang kurang, jangan membangunnya diam-diam di prompt ini.

## 1. Baca sebelum mengetik

- `AGENTS.md` (§Olah data — aturan keras, §Aturan yang Lahir dari Kesalahan Nyata)
- `docs/studio-data-acuan/03-SDD-ADR.md` §2.6, §4, ADR-03, ADR-04, ADR-08
- `docs/studio-data-acuan/katalog/paket/enclosure/KARTU.md` dan `skema.json` — **ini peta paketnya**
- Berkas kode:
   - `app/Services/Calibration/TabelKalibratorEnclosure.php`
   - `app/Services/Calibration/EnclosureCalculator.php`
   - `app/Services/Calibration/Profiles/Enclosure/EnclosureProfileBase.php`
   - `app/Services/Calibration/Profiles/Enclosure/OvenProfile.php`
   - `app/Services/Calibration/Profiles/Enclosure/InkubatorProfile.php`
   - `app/Services/Calibration/Profiles/Enclosure/BathProfile.php`
   - `app/Services/Calibration/Profiles/Enclosure/FurnaceProfile.php`
   - `app/Services/Calibration/Profiles/Enclosure/RefrigeratorProfile.php`
- Pertanyaan lab terbuka: `docs/pertanyaan-lab-enclosure.md`

Tulis daftar berkas yang AKAN diubah sebelum mulai (AGENTS.md §Alur Kerja 3).

## 2. Kerjakan

1. Tambahkan `skemaAcuan()` pada profil `EnclosureProfileBase`, `OvenProfile`, `InkubatorProfile`, `BathProfile`, `FurnaceProfile`, `RefrigeratorProfile`: lembar, kolom, tipe, kunci baris, boleh tambah
   baris atau tidak — sesuai `skema.json`. Satuan diambil dari komentar/kode/workbook, **bukan** dari
   `satuan_tebakan`. Kolom yang asal selnya belum terbukti diberi `asal: perlu_konfirmasi`.
2. Ubah `TabelKalibratorEnclosure` supaya membaca isi versi lewat `SumberAcuan`. **Buang cache statis per
   proses** (`private static ?array $data`) — ganti cache per (versi_id, sha256) (03-SDD §4).
3. `php artisan acuan:impor-awal enclosure` → versi 1 `aktif`; sha256 kanonik isi = sha256 kanonik
   `database/data/tabel-kalibrator-enclosure.json`.
4. Tambahkan kasus `enclosure` ke `TabelAcuanSetaraJsonTest`: setiap float yang diberikan ke kalkulator
   identik bit antara jalur JSON lama dan jalur versi.
5. Bila paket ini dibaca profil lain (—), jalankan juga test mereka.

## 3. Jangan

- Mengubah rumus, kalkulator, urutan hitung, pembulatan, atau satu angka acuan pun.
- Menyunting `database/data/*.json` dengan tangan (generator saja yang boleh menulisnya).
- Memasukkan nama/alamat pelanggan dari workbook ke berkas mana pun.
- Commit atau push ke `main`. Push branch boleh hanya bila diminta.

## 4. Selesai bila

- Test berikut hijau **tanpa diubah**, di SQLite DAN MySQL (`php artisan test -c phpunit.mysql.xml`):
   - `tests/Feature/EnclosureGridParsialTest.php`
   - `tests/Feature/EnclosureKepalaLembarTest.php`
   - `tests/Feature/EnclosureRegresiTest.php`
   - `tests/Feature/EnclosureSesiTest.php`
   - `tests/Feature/EnclosureStandarTertautTest.php`
   - `tests/Feature/ResolusiAlatAutoklafNolDitolakTest.php`
   - `tests/Feature/StandarTercetakTerdaftarTest.php`
   - `tests/Unit/EnclosureBudgetTest.php`
   - `tests/Unit/EnclosureProfilTest.php`
- `TabelAcuanSetaraJsonTest` kasus `enclosure` hijau.
- `skemaAcuan()` mencakup 100% sel data paket (skrip `alat-bantu/validasi_katalog.py` di ZIP).

## 5. Laporan (tempel balik ke Zainul)

Berkas yang diubah; perintah test + hasilnya (SQLite & MySQL); sha256 versi 1; kolom yang masih
`perlu_konfirmasi`; pertanyaan lab baru (bernomor); risiko tersisa.
