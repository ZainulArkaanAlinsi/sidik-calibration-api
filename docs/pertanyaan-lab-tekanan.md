# Pertanyaan lab — keluarga TEKANAN (Pressure Gauge, Vacuum Gauge, Differential Pressure)

> Ditulis 28 Sep 2026 dari bedah empat Master Olah Data tekanan (DRUCK07G,
> DRUCK13G, SPMK, Differential) — 962 sel perhitungan diadu ke cache Excel oleh
> `docs/skrip/gen-tabel-standar-tekanan.py`, selisih terbesar 1·10⁻¹³ relatif.
> Nomor `P-n` di bawah dirujuk dari kode (`TekananProfile`, `TekananCalculator`)
> dan dari jejak audit tiap sesi (`type_b_components.penyimpangan_master`).
>
> **Yang memutuskan: Technical Manager.** Sampai dijawab, perilaku aplikasi
> tertulis di kolom "Sekarang". Tiga yang ditandai **DITAHAN** (P-1, P-2, P-11)
> diputus pemilik proyek 28 Sep 2026: sesi yang memicunya dihitung DUA mode
> (tiru master & dibetulkan), kedua angka ditampilkan ke admin, tapi
> sertifikatnya **tidak terbit** sampai pertanyaan ini dijawab — validator
> ERROR `menunggu_keputusan_tm`. Keputusan sebelumnya pagi itu ("hitung benar
> lalu terbitkan") digantikan, karena menghitung beda dari metode yang
> divalidasi berarti menerbitkan dari metode yang belum disahkan (ISO/IEC
> 17025 7.2.1.5). Jawaban dicatat sebagai versi baru di
> `database/data/log-metode-tekanan-piston.json`; penahanannya lepas dari
> sana. Sisanya ditiru apa adanya.

## A. Ketidakpastian

| No | Temuan | Sel | Sekarang | Yang ditanyakan |
|---|---|---|---|---|
| P-1 | U95 kalibrator DRUCK07G selalu dari set point 0 kPa karena `VLOOKUP(ABC4,…)` — salah ketik di ke-14 baris | `PERHITUNGAN FC!AH24:AH37` | **DITAHAN** (sesi DRUCK07G): dua mode dihitung — master `ABC4` lawan `VLOOKUP(AC24,…)` lalu MAX. Sesi contoh: 0,06 → 0,09 kPa | Konfirmasi salah ketik. Mana yang benar: MAX U95 titik terpakai (struktur sheet 07G/13G) atau U95 di indeks terbesar (SPMK/Differential)? |
| P-2 | Komponen "Pengulangan Pembacaan" SELALU NOL di 07G, 13G, Differential: `N14` merujuk `I44` yang kosong (blok baris 40–44 ikut terhapus waktu adaptasi dari SPMK) | `PERHITUNGAN U95%!N14` → `PERHITUNGAN FC!I44` | **DITAHAN** (sesi 07G/13G/Differential): dua mode dihitung — master nol lawan rumus induk SPMK `MAX(U24:V37)`. Sesi contoh 07G: U 0,0692 → 0,7533 kPa (≈11×); Differential 3,998 → 5,047 mbar. Catatan: lantai CMC bisa menyamakan U95 TERCETAK kedua mode (sesi contoh VG-07G: dua-duanya 1,4561477 kPa) | **Apakah sertifikat tekanan yang sudah terbit dari tiga master ini perlu ditinjau?** Ini mengecilkan U95 justru saat alatnya tidak stabil. |
| P-3 | Pembagi komponen pengulangan `3` dengan v = 2, bukan √3 | `PERHITUNGAN U95%!Q14`, `S14` | Ditiru | Kalau masukannya s dari n = 3, u rata-rata = s/√3. Mana yang sah menurut IK? |
| P-5 | Drift DRUCK yang dipakai budget = spesifikasi pabrik "drift sementara" (0,0185% FS), bukan drift terhitung dari riwayat rekalibrasi di sheet yang sama (07G: 0,01554 lawan 0,0015 kPa) | `'Druck (kPa) vacum'!H4`, `'Druck (psi)'!H5` | Ditiru (konservatif) | Mana yang sah? |
| P-6 | Sheet drift Additel mencetak "PAKAI INI" di metode STDEV/√3 (`T38` = 0,01128) tapi budget memakai `M39 = 0,5×MAX(ΔC)` = 0,0234 | `'Record Drift Additel'!M39` lawan `T38` | Ditiru (`M39`) | Mana yang sah? |
| P-11 | DRUCK13G cabang Vacum membaca kolom 4 (U95) sebagai koreksi standar UP; cabang Non Vacum kolom 2 | `PERHITUNGAN FC!AD24` | **DITAHAN** (sesi DRUCK13G vakum): dua mode dihitung — kolom 4 lawan kolom 2; selisih titik 0 psi 0,1383 psi = 65,5 % U95, tanda terbalik di 4 dari 5 titik sesi contoh. Errata E-4 (tiru) digantikan keputusan ini | Konfirmasi salah ketik. |
| P-15 | Zero error dibaca dari titik PERTAMA saja (`G38:I38` cuma baris 24), apa pun setelannya | `PERHITUNGAN FC!Q39` | Ditiru; sesi yang titik pertamanya bukan nol diberi peringatan | Memang titik pertama wajib nol? |

## B. Rentang, standar, satuan

| No | Temuan | Sekarang | Yang ditanyakan |
|---|---|---|---|
| P-4 | Kertas `SIDIK-FM-CAL-0507_Rev.5` mencetak `FLUKE/718 300G/3281083` yang tidak punya master, tabel koreksi, maupun jatuh tempo; dua kalibrator Druck DPI611 yang dipakai master TIDAK tercetak | Fluke tidak dimasukkan ke dropdown standar; Druck ditambahkan | Revisi formulir? Fluke masih dipakai? |
| P-7 | Excel tidak menjaga rentang: titik 1999,8 kPa di contoh 07G memakai koreksi set point 200 kPa (kalibrator cuma sampai 200) — T-4 panduan | **Diblokir** dengan alasan terbaca | Konfirmasi blokir. |
| P-9 | Jatuh tempo Druck DPI611-07G tertulis dua: `STANDAR_DRUCK!K3` 29 Apr 2027 (interval 1 th) lawan `DATABASE!X13` 29 Apr 2028 (interval 2 th) | Seed memakai yang lebih awal (2027-04-29) | Mana yang benar? |
| P-10 | Faktor Torr di DRUCK13G masih nilai kPa (0,133322), seharusnya ≈0,0193368 Psi | Ditiru (belum terpakai data contoh) | Betulkan, atau cabut Torr dari profil ini? |
| P-12 | Gauge compound (vakum + positif) — profil Pressure Gauge dikunci CMC non-vakum, Vacuum Gauge dikunci vakum | Mengikuti nama alat | Satu sesi dua CMC, atau dua sesi? |
| P-13 | Metode Vacuum Gauge: lampiran menyebut `SIDIK-IK-CAL-0534_Rev.1`, contoh master DRUCK07G memilih IK-0504 | Memakai lampiran (0534) | Konfirmasi. |
| P-14 | Desimal cetak di-set tangan per berkas: U95 07G 3 desimal, 13G 1, Differential 2, SPMK 2 | Nilai = desimal resolusi; U95 = +1 | Aturan bakunya? |

## C. Pencegahan salah baca (adendum OCR K-3)

| No | Yang ditanyakan |
|---|---|
| P-8 | Ambang kewajaran: histeresis wajar per jenis alat, |deviasi| maksimum sebelum alat dianggap perlu servis, sebaran wajar antar pengulangan. Sekarang cuma dua peringatan pasti: titik pertama bukan nol, dan bacaan standar UP turun padahal setelan naik. |

## D. Data untuk verifikasi silang

| No | Yang diminta |
|---|---|
| P-16 | Data contoh di master BUKAN sesi nyata (07G 0–2000 cmHg dengan kalibrator 200 kPa; Differential 0–10 psi dengan kalibrator ±10 mbar). Mohon **2–3 sertifikat terbit per alat beserta data mentahnya** untuk verifikasi yang tidak bergantung pada pembacaan workbook. |

## Catatan yang TIDAK butuh jawaban (sudah dibuktikan dari sel)

- **CMC Vacum bukan hasil tempel.** `DATABASE!S5 = 0,43*S34`: 0,43 inHg (lampiran no. 25) dikonversi → 1,4561477 kPa / 0,2111962854574… Psi. Non Vacum 07G = `0,0048*S30` (lampiran no. 24 pita psi). Diuji `TekananCmcTest`.
- **SPMK menambahkan koreksi beda tinggi** `R44 = Δh·ρ·g/10⁵` ke bacaan standar UP dan DOWN (`AF24` → `AG/AH`), bukan cuma ke budget.
- **`TINV` memotong v_eff ke bawah**, dan `GumCalculator` sudah melakukannya untuk semua alat berbasis TINV (lihat laporan Langkah 0).
- **Sel U95 gabungan `G10:G17` STANDAR_DRUCK 07G** — nilai 0,06 berlaku untuk set point −80…−10 kPa; generator mengisinya dari sel teratas gabungan, bukan membaca kosong.
