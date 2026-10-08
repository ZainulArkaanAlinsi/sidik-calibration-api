# P5 — Uji pembanding Excel & unggah workbook

Repo: `sidik-calibration-api` (+ layar S10 di mobile). Rujukan: `02-SRS.md` FR-10, FR-14;
`06-Test-Plan.md` §3; `05` T3.1–T3.3; Project doc F1 (Data Acuan dari Excel).

## Gerbang masuk

Manifest sha256 workbook Micrometer ada (P0). Toleransi pembanding Micrometer **sudah ditetapkan
Lab** (K-48-07). Kalau belum: kerjakan bagian teknis, tapi uji berstatus `menunggu_toleransi`,
bukan lulus.

## Tugas

1. **Vektor uji dari workbook DIHITUNG ULANG**: salin `.xlsm` ke folder sementara di luar repo,
   hitung ulang dengan LibreOffice headless (`soffice --headless --convert-to xlsx ...` atau
   makro recalc), baru baca nilainya dengan openpyxl `data_only=True`. Bandingkan dengan nilai
   cache asli; selisih dilaporkan. Simpan vektor (tanpa data pelanggan) sebagai fixture ber-sha256.
2. **Uji per komponen budget**, bukan cuma U95: normal, batas rentang, percabangan tangga
   ketidakpastian balok, nominal tak ada di tabel (diblokir dengan alasan), kasus pembulatan .5.
3. **Peta sel** `database/peta-acuan/micrometer.json`: tiap kolom skema → `berkas`, `sheet`, `sel`
   atau rentang, plus daftar sel RUMUS untuk sidik jari. Mulai dari "Rujukan sel" di KARTU; setiap
   alamat diverifikasi di `.xlsm`. Ingat temuan: blok balok ukur di varian 25-50 & 50-75 tergeser
   satu kolom — peta sel per varian, divalidasi isi.
4. **Unggah workbook** (FR-10): PhpSpreadsheet **[dependensi baru — minta izin]**, hanya di worker;
   berkas utuh + sha256 ke disk privat; hanya sel terpetakan yang dibaca; sidik jari rumus beda →
   `struktur_berubah` (tidak bisa disimulasikan); hasil = draf baru. Kode error F1 lengkap.
5. Mobile S10: seret-lepas `.xlsm`, laporan per sel (akan diubah / ditolak + alasan).

## Jangan

Memakai nilai cache Excel sebagai kebenaran tanpa dihitung ulang; memilih toleransi sendiri;
menyalin sheet DATABASE.

## Selesai bila

`UjiPembandingMicrometerTest` hijau dengan toleransi Lab; unggah workbook yang rumusnya diubah
→ `struktur_berubah`; unggah workbook revisi nilai saja → draf dengan beda yang benar.
