# Hydrometer — paket `hydrometer`

| | |
|---|---|
| Kelompok | Massa jenis |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `HydrometerProfile` |
| Kalkulator | `HydrometerCalculator` |
| Kelas tabel | `TabelStandarHydrometer` |
| Formulir resmi | SIDIK-FM-CAL-0533_Rev.2 - LEMBAR KERJA HYDROMETER.pdf |
| Folder master | `Hydrometer/Hydrometer_0.600-0.650_gmL`, `Hydrometer/Hydrometer_1.800-2.000_gmL` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-hydrometer.md` |
| Serah-terima frontend | — |

**Catatan:** Kelas `TabelStandarHydrometer` ada, tetapi tanpa JSON tabel sendiri; pita CMC-nya dibaca dari kemampuan-kalibrasi.json.

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarHydrometer` ← `CalibrationController`, `HydrometerCalculator`, `HydrometerMentah`, `HydrometerProfile`, `VolumetricGlasswareCalculator`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarHydrometer.php`
- `app/Services/Calibration/HydrometerCalculator.php`
- `app/Services/Calibration/Profiles/HydrometerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/CetakLembarKerjaOcrTest.php`
- `tests/Feature/HydrometerSesiTest.php`
- `tests/Unit/HydrometerGerbangTest.php`
- `tests/Unit/HydrometerMasterTest.php`
- `tests/Unit/VolumetricGlasswareMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Hydrometer_0.600-0.650_gmL` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 77 |
| `Hydrometer_0.600-0.650_gmL` | FORM_VALIDASI | sumber nomor versi workbook (F1) | 64 |
| `Hydrometer_0.600-0.650_gmL` | INPUT_DATA | tab Bentuk Lembar (lapis 3) | 42 |
| `Hydrometer_0.600-0.650_gmL` | NILAI_U95pct | tab Rumus — baca saja (lapis 2) | 184 |
| `Hydrometer_0.600-0.650_gmL` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 94 |
| `Hydrometer_0.600-0.650_gmL` | PERHITUNGAN_2 | tab Rumus — baca saja (lapis 2) | 86 |
| `Hydrometer_0.600-0.650_gmL` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 32 |
| `Hydrometer_0.600-0.650_gmL` | Tabel_Surface_Tension | tab data acuan (bisa disunting lewat versi) | 13 |
| `Hydrometer_1.800-2.000_gmL` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 77 |
| `Hydrometer_1.800-2.000_gmL` | FORM_VALIDASI | sumber nomor versi workbook (F1) | 64 |
| `Hydrometer_1.800-2.000_gmL` | INPUT_DATA | tab Bentuk Lembar (lapis 3) | 42 |
| `Hydrometer_1.800-2.000_gmL` | NILAI_U95pct | tab Rumus — baca saja (lapis 2) | 184 |
| `Hydrometer_1.800-2.000_gmL` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 90 |
| `Hydrometer_1.800-2.000_gmL` | PERHITUNGAN_2 | tab Rumus — baca saja (lapis 2) | 86 |
| `Hydrometer_1.800-2.000_gmL` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 32 |
| `Hydrometer_1.800-2.000_gmL` | Tabel_Surface_Tension | tab data acuan (bisa disunting lewat versi) | 13 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Hydrometer_0.600-0.650_gmL › Tabel_Surface_Tension**

```
toC
0.01 | 75.64 | 0.38 | 0.01
5 | 74.95 | 0.37 | 0
10 | 74.23 | 0.37 | -0.01
15 | 73.5 | 0.37 | -0.01
20 | 72.75 | 0.36 | -0.01
```
**Hydrometer_1.800-2.000_gmL › Tabel_Surface_Tension**

```
toC
0.01 | 75.64 | 0.38 | 0.01
5 | 74.95 | 0.37 | 0
10 | 74.23 | 0.37 | -0.01
15 | 73.5 | 0.37 | -0.01
20 | 72.75 | 0.36 | -0.01
```

## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `TabelStandarHydrometer.php:31` | `SURFACE_TENSION` | `[` | const |
| `TabelStandarHydrometer.php:46` | `FAKTOR_TEGANGAN_KE_DYNE` | `['dyne/cm' => 1.0, 'mN/m' => 1.0, 'N/m' => 1000.0];` | const |
| `TabelStandarHydrometer.php:54` | `FAKTOR_DENSITAS_KE_G_PER_ML` | `['g/ml' => 1.0, 'kg/m3' => 0.001];` | const |
| `TabelStandarHydrometer.php:63` | `SUHU_ACUAN_SAH` | `[15.0, 20.0, 27.5];` | const |
| `TabelStandarHydrometer.php:66` | `PENGULANGAN` | `3;` | const |
| `TabelStandarHydrometer.php:69` | `UKUR_DIAMETER_STEM` | `3;` | const |
| `TabelStandarHydrometer.php:76` | `PHI` | `3.14;                 // PERHITUNGAN!J89 — lab memakai 3,14, bukan π` | const |
| `TabelStandarHydrometer.php:78` | `GRAVITASI_CM_S2` | `980.665;  // PERHITUNGAN!J90 = 9.80665 * 100` | const |
| `TabelStandarHydrometer.php:80` | `ALPHA` | `1.0e-5;             // PERHITUNGAN!J83 — muai volumetrik bahan hydrometer` | const |
| `TabelStandarHydrometer.php:82` | `KAPPA` | `2.5e-11;            // PERHITUNGAN!J86 — isothermal compressibility (1/Pa)` | const |
| `TabelStandarHydrometer.php:84` | `DENSITAS_BEBAN_STANDAR` | `8.0;   // PERHITUNGAN!AF85 — ρ beban standar timbangan` | const |
| `TabelStandarHydrometer.php:86` | `DENSITAS_SINKER` | `8.0;          // PERHITUNGAN!J91 — ρ beban tambahan` | const |
| `TabelStandarHydrometer.php:88` | `ERR_TIMBANG` | `0.0002;           // PERHITUNGAN!AF84 — kesalahan indikasi alat timbang` | const |
| `TabelStandarHydrometer.php:90` | `ERR_DENSITAS` | `0.0002;          // PERHITUNGAN!J87 — ketidakstabilan densitas acuan` | const |
| `TabelStandarHydrometer.php:92` | `TEKANAN_ACUAN_HPA` | `1013.25;    // PERHITUNGAN!AD29 = 101.325 kPa * 10` | const |
| `TabelStandarHydrometer.php:100` | `SUHU_ACUAN_FAKTOR_BAWAAN` | `20.0;` | const |
| `TabelStandarHydrometer.php:109` | `POLINOM_DENSITAS_AIR` | `[` | const |
| `TabelStandarHydrometer.php:126` | `U95_TIMBANGAN_LOP` | `0.00074;    // E29, g` | const |
| `TabelStandarHydrometer.php:128` | `K_TIMBANGAN_LOP` | `2.0;          // E30` | const |
| `TabelStandarHydrometer.php:130` | `STDEV_TIMBANG` | `0.0001;         // E34, g` | const |
| `TabelStandarHydrometer.php:132` | `N_TIMBANG` | `10;                 // E35` | const |
| `TabelStandarHydrometer.php:134` | `U95_THERMOMETER` | `0.72;         // N29, °C` | const |
| `TabelStandarHydrometer.php:136` | `K_THERMOMETER` | `2.0;            // N30` | const |
| `TabelStandarHydrometer.php:138` | `U95_SENSOR` | `0.06;              // N34, °C` | const |
| `TabelStandarHydrometer.php:140` | `K_SENSOR` | `2.0;                 // N35` | const |
| `TabelStandarHydrometer.php:143` | `U_DENSITAS_ACUAN` | `5.0e-5;` | const |
| `TabelStandarHydrometer.php:146` | `U_GRAVITASI` | `0.0005;` | const |
| `TabelStandarHydrometer.php:152` | `U95_TH_SUHU` | `1.2;` | const |
| `TabelStandarHydrometer.php:154` | `U95_TH_RH` | `3.0;` | const |
| `HydrometerCalculator.php:96` | `SUHU_ACUAN_BUDGET` | `20.0;` | const |
| `HydrometerCalculator.php:99` | `VI_NORMAL` | `60.0;` | const |
| `HydrometerCalculator.php:101` | `VI_RECTANGULAR` | `50.0;` | const |
| `HydrometerProfile.php:63` | `KODE_METODE` | `'SIDIK-IK-CAL-0525_Rev.3';` | const |
| `HydrometerProfile.php:66` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0533_Rev.2';` | const |
| `HydrometerProfile.php:69` | `KODE_SERTIFIKAT` | `'SIDIK-FM-CAL-2403_Rev.0';` | const |
| `HydrometerProfile.php:72` | `SATUAN` | `'g/ml';` | const |
| `HydrometerProfile.php:94` | `CMC_MASTER` | `0.0007;` | const |
| `HydrometerProfile.php:113` | `TITIK_CI_STEM_MASTER_RUSAK` | `3;` | const |
| `HydrometerProfile.php:137` | `TITIK_TANPA_PEMBANDING_MASTER` | `4;` | const |
| `HydrometerProfile.php:140` | `PENGULANGAN` | `TabelStandarHydrometer::PENGULANGAN;` | const |
| `HydrometerProfile.php:143` | `TITIK_AWAL` | `3;` | const |
| `HydrometerProfile.php:145` | `TITIK_MAKS` | `5;` | const |
| `HydrometerProfile.php:163` | `RASIO_RENTANG_MINIMUM` | `0.5;` | const |
| `HydrometerProfile.php:173` | `KOREKSI_MAKS_DARI_LEBAR` | `0.5;` | const |
| `HydrometerProfile.php:219` | `STANDARD_TERCETAK` | `[` | const |
| `HydrometerProfile.php:239` | `NERACA_NAMA` | `'Analytical Balance Fujitsu FS-AR210';` | const |
| `HydrometerProfile.php:241` | `NERACA_SERI` | `'INS-N1600555';` | const |
| `HydrometerProfile.php:244` | `THERMOHYGRO_TERCETAK` | `['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];` | const |
| `HydrometerProfile.php:1060` | `ci` | `0.0` | literal_dalam_method |
| `HydrometerProfile.php:1084` | `ci` | `0.0` | literal_dalam_method |

## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!F31`, `DATABASE!V20:W21`, `DATABASE!V29:W31`, `NILAI U95%!C55`, `NILAI U95%!E39`, `NILAI U95%!L79`, `NILAI U95%!N43`, `NILAI U95%!Q49`, `PERHITUNGAN!AD29`, `PERHITUNGAN!AD39`, `PERHITUNGAN!AE39`, `PERHITUNGAN!AF84`, `PERHITUNGAN!AF85`, `PERHITUNGAN!G26`, `PERHITUNGAN!G56`, `PERHITUNGAN!J78`, `PERHITUNGAN!J83`, `PERHITUNGAN!J86`, `PERHITUNGAN!J87`, `PERHITUNGAN!J89`, `PERHITUNGAN!J90`, `PERHITUNGAN!J91`, `PERHITUNGAN!L53`, `PERHITUNGAN!P15`, `SERTIFIKAT!E17`, `SERTIFIKAT!O17`

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
