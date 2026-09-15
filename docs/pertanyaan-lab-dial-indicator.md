# Pertanyaan Lab — Dial Indicator (lampiran LK-285-IDN no. 36)

**Sumber:** `Master Olah Data_Dial Indicator.xlsm` (pw `spirit285`), kertas `SIDIK-FM-CAL-0526_Rev.3`.
**Disiapkan:** 15 Sep 2026. **Status angka:** 108 sel master direproduksi di Python dan PHP, nol beda
pada 1·10⁻⁹ (`DialIndicatorMasterTest`).

Aturan yang dipakai: kejanggalan METODE ditiru + ditanyakan; kerusakan salin-tempel dihitung benar +
selisihnya ditulis; sel kosong yang dibaca nol diblokir. Tidak ada yang diubah diam-diam.

## Ringkasan

| § | Hal | Jenis | Yang dilakukan sistem | Menggeser angka terbit? |
|---|---|---|---|---|
| 1 | Repeatability 10 pembacaan dibagi √5, vi = 4 | Metode | Ditiru | Tidak (hari ini nol) |
| 2 | Drift `/12` padahal selisihnya HARI | Metode | Ditiru (preseden Height Gauge §1) | Ya, kecil |
| 3 | Pembagi √6 di komponen berlabel `rect.` | Metode | Ditiru | Ya, sangat kecil |
| 4 | Komponen suhu memakai `Uα = 2·Δα` sebagai ci | Metode | Ditiru | Ya, sangat kecil |
| 5 | Evaluation sepuluh bacaan identik | Metode | **Terbit + peringatan** (beda dari Micrometer) | Tidak |
| 6 | Kertas 6 bacaan (UP×3, DOWN×3), workbook 5 | Kertas vs workbook | Ikut kertas, rata-rata semua | Bisa |
| 7 | Masa berlaku balok ukur beda dengan workbook Micrometer | Data | Baris standar tidak ditimpa | Status VALID/EXPIRED |
| 8 | Panjang sensitivitas `C61` dari KEPING, bukan tumpukan | Salin-tempel | **Dihitung benar** (tumpukan) | Ya, U naik digit ke-7 |
| 9 | Umur drift dari `NOW()` | Salin-tempel | **Dari tanggal sesi** | Ya |
| 10 | Pita CMC dipilih dari kapasitas MENTAH (bukan mm) | Salin-tempel | **Dari mm** | Ya untuk alat inch |
| 11 | Nominal balok tidak terdaftar → hilang dari `SUM` diam-diam | Sel kosong | **Titik diblokir**, alasan menyebut kepingnya | — |
| 12 | Koefisien muai `1,2/1000000` (αs, αt) | Metode/data | Ditiru — hari ini tanpa efek | Tidak |
| 13 | Format sertifikat `0.00` membuang koreksi | Tampilan | Lima desimal (preseden Micrometer §9) | Tampilan |

## §1 — Repeatability: sepuluh pembacaan, dibagi √5

`PERHITUNGAN U95%!K5 = PERHITUNGAN!N25 = STDEV(C25:M25)` — sepuluh pembacaan Evaluation.
Tapi `N5 = SQRT(5)` dan `Q5 = 5-1`. Bentuk "lima pembacaan" pada data sepuluh pembacaan.

Membetulkannya ke √10 / vi = 9 **mengecilkan** u pengulangan √2 kali — arah yang dilarang
aturan proyek tanpa keputusan lab. **Pertanyaan:** mana yang dimaksud metode IK-CAL-0519?

## §2 — Drift `/12` untuk selisih hari

`K10 = ((0,02 + 0,00025·L)·1)/1000 · ((X11 − W13)/12)`. `X11 − W13` hasilnya hari (695,4 di snapshot),
dibagi 12 seperti bulan. Sama persis dengan Height Gauge §1. Dengan `/365` drift ~30× lebih kecil.
**Pertanyaan:** satuan umur yang dimaksud — tahun (`/365`), bulan (`/30,4`), atau memang `/12`?

## §3 — √6 di komponen `rect.`

`N9 = SQRT(6)`, `J9 = "rect."`. Rect. = √3; √6 lazim untuk distribusi segitiga. Ditiru.

## §4 — Komponen suhu memakai Uα sebagai ci

`V8 = C61 · K9` dengan `K9 = W22 = 2·Δα = 2·10⁻⁵`, dan `K9` juga u komponen muai (`T9 = K9/√6`).
ci komponen suhu lazimnya `L·α`. **Pertanyaan:** apakah `2·Δα` memang dimaksud sebagai koefisien?

## §5 — Evaluation sepuluh bacaan identik: TERBIT, bukan diblokir

Sesi contoh master: `25,01` sepuluh kali (dan tiap titik lima bacaan identik). Simpangan baku nol.

Di Micrometer & Height Gauge kondisi ini **diblokir** karena di sana terbukti data rusak (635,0 = bug
satuan). Di Dial Indicator resolusi 0,01 mm, bacaan identik adalah bentuk normal, komponen resolusi
(`r/(2√3)` = 0,00289 mm) sudah menampungnya, dan ada lantai CMC. Sistem **menerbitkan** dan memunculkan
peringatan `dial_indicator_evaluation_tanpa_sebaran`.

**Pertanyaan:** setuju perlakuan ini? Atau lab ingin lantai keterulangan berbasis resolusi?

## §6 — Kertas enam bacaan, workbook lima

Kertas `SIDIK-FM-CAL-0526_Rev.3`: kolom **UP X1 X2 X3** dan **DOWN X1 X2 X3**, lima belas baris.
`INPUT DATA`: **X1..X5**, sepuluh baris. Sistem mengikuti kertas (enam kotak) dan merata-rata SEMUA
bacaan yang terisi — sama dengan `AVERAGE(I31:M33)` atas kotak terisi.

**Pertanyaan:** (a) apakah UP dan DOWN memang dirata-rata jadi satu koreksi? (b) atau histeresis
(UP − DOWN) perlu dilaporkan terpisah seperti USBR 1007-89? (c) berapa baris yang wajib diisi?

## §7 — Masa berlaku balok ukur GB-9122-0 tidak sepakat

Workbook Dial Indicator `DATABASE!W13:Y13`: kalibrasi 2024-01-24, interval **3 tahun** → 2027-01-24.
Seeder Micrometer (dari workbook Micrometer): berlaku sampai **2026-01-24**. Satu set fisik.
Sistem tidak menimpa baris standar. **Pertanyaan:** interval yang benar 2 atau 3 tahun? Sertifikat
balok ukur yang berlaku sekarang tanggal berapa?

## §8 — Panjang sensitivitas dari keping (dihitung benar)

`C61 = MAX(C31:E60)` = keping terbesar (21 mm). Tumpukan terpanjang sesi contoh 21 + 1,8 + 1,7 = 24,5 mm.
Sistem memakai 24,5 mm (preseden Micrometer §3). ci suhu & muai naik 16,7 %; U95 sesi contoh berubah
di digit ke-7. `DialIndicatorMasterTest` menguji rumus dengan L = 21 (cocok master) dan arahnya.

## §9 — `NOW()` (dihitung dari tanggal sesi)

Snapshot master 2025-12-19 → umur 695,4 hari; sesi 2024-05-06 → 103 hari. Tanpa ini U95 sesi yang sama
berubah tiap berkas dibuka.

## §10 — Pita CMC dari kapasitas mentah (dihitung benar)

`AA20` membaca `INPUT DATA!E15` apa adanya. Dial 1 inch terbaca kapasitas 1 → pita 0-25 (6,5 µm);
yang benar 25,4 mm → pita 0-50 (8,6 µm). Sistem memilih dari mm. Kapasitas kosong/0 dan >300 mm
**diblokir** (master: `"cek range"` lalu `MAX()` mengabaikannya — terbit tanpa lantai).

## §11 — Balok ukur tidak terdaftar (diblokir)

`F31 = IF(ISERROR(VLOOKUP(C31;Nominal_mm;2;0));"";…)` lalu `H31 = SUM(F31:G33)`. Keping salah ketik
(mis. 1,25) hilang dari jumlah; titik 5,0 mm terbaca 3,7 mm tanpa error. Sistem menolak titiknya dengan
alasan "Balok ukur 1,25 mm tidak ada di daftar Gauge Block terkalibrasi".

## §12 — αs = αt = 1,2·10⁻⁶ /°C

Baja ~11,5·10⁻⁶. Karena δϴ = 0 dan δα = 0 suku ini nol hari ini. **Pertanyaan:** salah ketik `12`?

## §13 — Format `0.00` di sertifikat

`SERTIFIKAT!D18:L27` berformat `0.00`: koreksi −0,00011 tercetak `0,00`, standar 1,09989 tercetak `1,10`.
Sistem mencetak lima desimal. **Pertanyaan:** betulkan format master juga?
