# Pertanyaan lab — keluarga PISTON VOLUME (Piston Pipette, Dispensett, Buret Digital)

> Ditulis 28 Sep 2026 dari bedah dua Master Olah Data (Fixed & Graduated Piston
> Volume) — 274 sel diadu ke cache Excel oleh
> `docs/skrip/gen-tabel-standar-piston-volume.py`, selisih terbesar 2·10⁻¹⁴.
> Nomor `V-n` dirujuk jejak audit tiap sesi. **Yang memutuskan: Technical
> Manager.** Cacat yang merusak data/mengecilkan U dihitung benar (keputusan
> pemilik proyek 28 Sep 2026); kejanggalan metode ditiru.

| No | Temuan | Sel | Sekarang | Yang ditanyakan |
|---|---|---|---|---|
| V-1 | Metode: master memakai `SIDIK-IK-CAL-0522_Rev.5`, lampiran LK-285-IDN menulis Rev.4 | `DATABASE!C` Metode_Kalibrasi #22 | Rev.5 | Revisi mana yang berlaku di lampiran? |
| V-2 | Kertas FM-0528/0529 memungut "M11 (jika UUT < 50 µl)" dan "Time (s)" yang tidak dipakai master mana pun | kertas | Tidak dipungut | Untuk apa? (penguapan?) |
| V-3 | **Kertas tidak punya kotak tekanan udara (hPa)** padahal densitas udara → V20 membutuhkannya; master memakai "Thermobarometer Lutron" sementara kertas mencetak TH-3 | `INPUT DATA!E24:F24` | Wajib diisi di HP (`tekanan_awal/akhir`) | Revisi kertas? Alat baca tekanannya apa? |
| V-4 | Timbangan Fujitsu FSR-A dipakai master Graduated tapi tidak tercetak di kertas | `Tabel_Standar` | Ditambahkan ke dropdown | Revisi kertas? |
| V-5 | Master Fixed tidak punya blok pernyataan kesesuaian sama sekali (G-5) | `SERTIFIKAT` | Fixed tanpa vonis | Memang begitu? |
| V-6 | Blok MPE Graduated mencetak `#N/A` (G-4: kunci `$AE$17` sel indeks yang kosong); aturannya simple acceptance `|dev| ≤ MPE` | `SERTIFIKAT!AD18:AE26` | **Vonis BELUM diterbitkan** (§7.8.6.1: aturan keputusan belum jelas). MPE dari nominal titik + vonis usulan (guarded & simple) di jejak `kesesuaian`; nominal di luar tabel → alasan terbaca | Aturan keputusan yang berlaku untuk piston? |
| V-7 | Pita CMC memakai batas BULAT (`C33<=1`, `C33>=2…<=5`, `>=6…<=10`): kapasitas 1,5 / 5,5 ml → "cek range" → `MAX(U,"cek range")` = U tanpa lantai CMC (G-7) | `PERHITUNGAN U95%!J48` | **DITAHAN saat terpicu** (kapasitas di celah pita bulat): dua mode dihitung, tidak terbit sampai dijawab. Kapasitas di luar SEMUA pita (mis. pipet 20 ml) **DIBLOKIR** — errata panduan E-5 | Konfirmasi. |
| V-8 | Ambang kewajaran (selisih massa ±20% nominal, suhu air 15–35 °C, tekanan 900–1100 hPa) | adendum OCR K-3 | Peringatan, bukan blokir | Angka resminya? |
| V-9 | Graduated `P87 = (SQRT(P86^2)+(H87^2))` — kurung salah, U densitas air = 1e-6 + H87² bukan √(1e-6² + H87²) (G-9) | `PERHITUNGAN!P87` | **Dihitung benar, TIDAK menahan**: porsinya 0,000116 % (master) / 0,026 % (benar) dari jumlah (uᵢcᵢ)², U95 tetap di lantai CMC. Tetap tercatat di log | Konfirmasi. |
| V-10 | Dua master, dua metode: densitas air Fixed `√((1e-6/2)²+Δρ²)` ÷2 / Graduated `√(1e-6²+(Δρ/1,73)²)` ÷√3; suhu air Fixed ÷√3 / Graduated ÷2 | `PERHITUNGAN U95%!E38,E40`, `PERHITUNGAN!Q59/P87` | Ditiru per master | Mana yang sah? Diseragamkan? |
| V-11 | Ambang tara-ulang m7 titik MIN 20 g (saudaranya 200 g) (G-2) | `PERHITUNGAN!H48` | **DITAHAN saat terpicu** (satuan ml, 20 g < M10 titik MIN ≤ 200 g): dua mode dihitung, tidak terbit sampai dijawab | Konfirmasi. |
| V-12 | m7 titik MID/MAX cabang tara-ulang mengambil **M4** (`J32`/`L32`), bukan M7 (G-8) | `PERHITUNGAN!J48,L48` | **DITAHAN saat terpicu** (satuan ml, M10 titik MID/MAX > 200 g): dua mode dihitung, tidak terbit sampai dijawab. Digabung dengan V-11: tidak ada satu kolom m7 pun yang benar di master | Konfirmasi. |
| V-13 | Status Analytical Balance `AA13 = Z13+365` (ditambah, bukan dikurangi `NOW()`) — selalu VALID | `DATABASE!AA13` | Aplikasi memakai jatuh tempo, tidak meniru | — |
| V-14 | Termometer Yokogawa CA150 jatuh tempo **12 Agt 2026** di tabel master — setiap sesi sesudah tanggal itu DIBLOKIR sampai data sertifikat barunya dimasukkan | `DATABASE!Z16` | Blokir (standar kedaluwarsa) | Mohon sertifikat Yokogawa terbaru. |
| V-15 | Indeks koreksi suhu Fixed memakai nama `index_suhu` yang menunjuk WORKBOOK LAIN (`[6]STANDAR KALIBRATOR`) | `PERHITUNGAN!H49` | Dihitung dari tabel lokal (hasil sama: 25) | — (panduan eksternal keliru menyebutnya "diketik tangan") |
| V-16 | `k` dicetak penuh di sertifikat master (`2,039513446396408`), sementara kolom `faktor_cakupan_k` aplikasi 2 desimal | `SERTIFIKAT!V23` | Cetak 2 desimal; nilai penuh di jejak | Boleh dibulatkan? |
| V-17 | Jatuh tempo neraca: master piston 2027-01-19, master Anak Timbangan 2026-01-19 untuk neraca yang sama | `DATABASE!Z13` | Tabel piston ikut master piston | Mana yang terbaru? |
| V-18 | Mohon **2–3 sertifikat terbit per alat beserta data mentahnya** untuk verifikasi silang | — | — | — |

## Rujukan sel untuk errata panduan eksternal (28 Sep 2026)

Penulis errata minta rujukan sel untuk dua klaim. Dibaca dari workbook yang
dibekukan (Graduated, sha256 `c09a6efd…`), sheet `PERHITUNGAN`. Baris 28..38 =
M0..M10 kumulatif, jadi baris 32 = **M4** dan baris 35 = **M7**; baris 38 = M10
(total, dipakai sebagai syarat `>200`).

| Sel | Rumus di master | Catatan |
|---|---|---|
| `H45` (m4 MIN) | `=IF(AND('INPUT DATA'!G15="ml",H38>200),H32,H32-H31)` | acuan pola: m4 tara-ulang = M4 |
| `J45` / `L45` (m4 MID/MAX) | `=IF(…,J38>200),J32,J32-J31)` / `L32,L32-L31` | sama |
| `H48` (m7 MIN) | `=IF(AND('INPUT DATA'!G15="ml",H38>20),H35,H35-H34)` | ambang **20**, bukan 200 (G-2, V-11) |
| `J48` (m7 MID) | `=IF(AND('INPUT DATA'!G15="ml",J38>200),PERHITUNGAN!J32,J35-J34)` | cabang tara-ulang mengambil **J32 = M4**, bukan J35 = M7 (G-8, V-12) |
| `L48` (m7 MAX) | `=IF(AND('INPUT DATA'!G15="ml",L38>200),L32,L35-L34)` | sama: **L32 = M4** |
| `P87` (U densitas air) | `=(SQRT(P86^2)+(H87^2))` | kurung salah tempat: `√(P86²) + H87²` = 1,000226e-06; seharusnya `√(P86² + H87²)` = 1,506852e-05 (G-9, V-9). `P86 = 1/1000000`, `H87 = H86/1,73`. Mengalir ke `PERHITUNGAN U95%!D38` |

**E-4 digantikan.** Errata meminta T-11 (DRUCK13G cabang Vacum) ditiru
persis; keputusan pertama pemilik proyek pagi itu menghitungnya benar. Dua-duanya
berlubang, dan keputusan akhir 28 Sep 2026 adalah jalan ketiga: hitung dua
mode, tampilkan keduanya, **terbitkan tidak satu pun** sampai Technical Manager
menjawab P-11 (juga P-1, P-2). Untuk piston: G-2/G-7/G-8 ditahan hanya kalau
terpicu; G-9 tidak menahan. Riwayatnya di `keputusan` pada
`database/data/log-metode-tekanan-piston.json`.
