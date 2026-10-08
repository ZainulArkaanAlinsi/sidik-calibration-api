# P9 — Saat Lab merevisi RUMUS di workbook master (lapis 2)

Ini jalur resmi untuk "olah datanya berubah". Studio tidak mengubah rumus; prompt ini yang
melakukannya, dengan bukti. Pola yang sudah terbukti di repo: alat Tekanan & Piston
(`database/data/manifest-workbook-tekanan-piston.json` + `log-metode-tekanan-piston.json`,
append-only, dijaga `LogMetodeTekananPistonTest`).

## Masukan yang wajib ada

Workbook lama & baru (`.xlsm`) di luar repo, sha256 keduanya, siapa di Lab yang memutuskan
revisi dan tanggalnya, nomor IK/dokumen metode baru bila ada.

## Tugas

1. **Bongkar beda rumus sel per sel**: baca kedua workbook dengan openpyxl (`data_only=False`)
   termasuk sheet tersembunyi, named range, tautan luar, VBA. Daftar sel yang rumusnya berubah,
   berikut rumus lama → baru.
2. **Golongkan**: beda yang hanya NILAI → bukan P9, pakai Studio (draf versi data). Beda RUMUS → lanjut.
3. **Buktikan dulu, baru tulis PHP** (skill `[[sidik-alat-baru-dari-master]]`): hitung ulang workbook
   baru, susun vektor per komponen budget, adu dengan kode sekarang → selisih yang diharapkan.
4. Ubah profil/kalkulator; naikkan `versiRumus()` (pola `TEKANAN-YYYY.MM.DD-n`); tambah entri
   **baru** di log metode append-only (apa berubah, sel mana, siapa memutuskan, tanggal,
   `tahan_terbit` bila belum diputuskan TM). Entri lama tidak disentuh.
5. Versi rumus baru berlaku untuk sesi baru (per tanggal kalibrasi); sertifikat terbit tidak berubah.
6. Penyimpangan dari master (kalau ada) dicatat di `type_b_components` dengan sumbernya
   (AGENTS.md §Olah data 4).
7. Bila skema lembar acuan ikut berubah (kolom baru) → naikkan `skema_versi` paket + migrasi isi versi.

## Selesai bila

Test master alat itu diperbarui **hanya** pada vektor yang memang berubah karena revisi (dengan
asal vektor baru), semua test lain hijau tanpa diubah, kedua suite hijau, log metode berisi entri
baru, laporan menyebut sel-sel yang berubah.
