# 06 — Matriks Paritas Alat

Status migrasi setiap alat dari profil PHP ke Lembar Hitung. Dokumen hidup: diperbarui di commit
yang sama dengan perubahan status alat. Dasar: Lampiran A CR-48
(`docs/studio-data-acuan/Lampiran-A-Inventaris-Master.md`, 46 folder non-tekanan), registry profil
(48 profil, `app/Services/Calibration/CalibrationProfileRegistry.php:95-266`), dan 6 workbook
tekanan/piston (`database/data/manifest-workbook-tekanan-piston.json`).

## 1. Definisi status

| Status | Arti | Jalur hitung sesi nyata | Syarat masuk |
|---|---|---|---|
| `belum` | Belum ada kandidat Lembar Hitung yang sedang dibayangkan | profil PHP (`sumber = kode`) | — |
| `bayangan` | Kandidat v1 beku dievaluasi di belakang setiap sesi nyata; belum pernah menstempel | profil PHP | kandidat nol galat statis + paritas pra-bayangan atas sesi tersimpan & vektor LULUS |
| `paritas` | Paritas final LULUS 100% (sesi tersimpan + vektor + seeder + laporan bayangan) dan diperiksa pemeriksa independen; menunggu/sedang dalam pengesahan atau tanggal berlaku | profil PHP | syarat bayangan Q-49-03 terpenuhi; tanda tangan Q-49-02 |
| `aktif` | Versi Lembar Hitung berlaku; profil PHP jadi pengawas sampai versi berikutnya | Lembar Hitung (`sumber = database`), pengawasan PHP | disahkan `super_admin` orang kedua; `berlaku_mulai` tiba |

Definisi paritas: `01-PRD.md` §5. Urutan: `03-SDD.md` ADR-49-04 dan §11.

Per 9 Okt 2026 **semua alat berstatus `belum`**: mesin rumus baru di tahap spike S0a.

## 2. Bahan master per folder (hitungan ulang 9 Okt 2026)

Dihitung dari 533 CSV ekspor di `Project-PT-Sidik/alat-alat-Pt-Sidik/` (checkout utama) dengan
pengurai riset D (`nilai [=rumus]`), skrip baca-saja di scratchpad sesi; hanya jumlah yang
dicetak, isi sel tidak. Sama dengan total riset D: 12.778 sel rumus, 93 larik, 21 `TINV`, 272
rumus bertautan luar. Kolom fungsi = jumlah **sel** yang memuat fungsi itu (riset D menghitung
kemunculan, jadi angkanya lebih besar).

**Temuan penting:** hanya **13 dari 52** folder yang CSV-nya memuat rumus (6 tekanan/piston, 3 gaya,
2 hydrometer, 2 volumetric). **39 folder hanya berisi nilai**, jadi peta sel Lembar Hitung untuk
alat-alat itu **wajib** dari `.xlsm` asli (Q-49-11), atau ditulis dari profil PHP. Di folder yang
berumus pun alamat rujukan bergeser 1–3 baris untuk 46 folder non-tekanan (riset D), sehingga CSV
bukan peta sel final.

`#REF!` di nilai bisa berasal dari sel tampilan atau sheet sertifikat, bukan selalu rantai hitung;
setiap alat diperiksa satu per satu saat migrasi.

| Folder | CSV | Sel rumus | Larik | VLOOKUP | TINV | INDEX / MATCH | OFFSET | NOW | FORECAST | IFERROR | `#REF!` di rumus | `#REF!` di nilai | Tautan luar `[n]` |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `6_Alat_Pressure_Piston_MasterOlahData_CSV/Master_Olah_Data_Fixed_Piston_Volume` | 12 | 1048 | 0 | 85 | 1 | 1 / 1 | 0 | 1 | 0 | 6 | 14 | 14 | 58 |
| `6_Alat_Pressure_Piston_MasterOlahData_CSV/Master_Olah_Data_Graduated_Piston_Volume` | 12 | 1189 | 0 | 89 | 1 | 0 / 0 | 0 | 1 | 0 | 6 | 16 | 16 | 73 |
| `6_Alat_Pressure_Piston_MasterOlahData_CSV/Master_Olda_Pressure_DRUCK07G_-0.82bar_new` | 9 | 869 | 0 | 96 | 1 | 0 / 0 | 0 | 1 | 0 | 4 | 0 | 0 | 0 |
| `6_Alat_Pressure_Piston_MasterOlahData_CSV/Master_Olda_Pressure_DRUCK13G_020bar` | 9 | 844 | 0 | 96 | 1 | 0 / 0 | 0 | 1 | 0 | 4 | 0 | 0 | 0 |
| `6_Alat_Pressure_Piston_MasterOlahData_CSV/Master_Olda_Pressure_Differential` | 9 | 977 | 0 | 69 | 1 | 0 / 0 | 0 | 0 | 0 | 4 | 0 | 0 | 1 |
| `6_Alat_Pressure_Piston_MasterOlahData_CSV/Master_Olda_Pressure_SPMK` | 9 | 832 | 0 | 84 | 1 | 0 / 0 | 0 | 1 | 0 | 4 | 0 | 0 | 0 |
| `Alat_Gaya/Gaya_Load_Cell` | 8 | 758 | 16 | 76 | 1 | 16 / 16 | 0 | 0 | 0 | 4 | 0 | 0 | 0 |
| `Alat_Gaya/Gaya_Proving_Ring` | 8 | 687 | 18 | 71 | 1 | 18 / 18 | 0 | 0 | 0 | 4 | 3 | 3 | 0 |
| `Alat_Gaya/Gaya_UTM` | 8 | 761 | 16 | 82 | 1 | 16 / 16 | 0 | 0 | 0 | 4 | 0 | 0 | 0 |
| `Aliran_2/1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | 12 | **0** | — | — | — | — | — | — | — | — | — | 1 | — |
| `Aliran_2/1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | 8 | **0** | — | — | — | — | — | — | — | — | — | 1 | — |
| `Aliran_2/2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | 14 | **0** | — | — | — | — | — | — | — | — | — | 1 | — |
| `Aliran_2/Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | 9 | **0** | — | — | — | — | — | — | — | — | — | 2 | — |
| `Hydrometer/Hydrometer_0.600-0.650_gmL` | 8 | 1214 | 6 | 62 | 5 | 6 / 11 | 5 | 1 | 5 | 38 | 0 | 0 | 0 |
| `Hydrometer/Hydrometer_1.800-2.000_gmL` | 8 | 1186 | 6 | 62 | 5 | 6 / 11 | 5 | 1 | 5 | 38 | 0 | 0 | 0 |
| `Massa_Timbangan/1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | 21 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Massa_Timbangan/_New__Master_Olda_Timbangan_gram` | 14 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Massa_Timbangan/_New__Master_Olda_Timbangan_kg` | 14 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Massa_Timbangan/_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | 9 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Panjang_CSV/Master_Olah_Data_Micrometer_0-25mm` | 8 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Panjang_CSV/Master_Olah_Data_Micrometer_25-50mm` | 8 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Panjang_CSV/Master_Olah_Data_Micrometer_50-75mm` | 8 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Panjang_CSV/Master_Olah_Data_Micrometer_75-100mm` | 8 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Panjang_CSV/Master_olda_Height_Gauge_600_mm_2026` | 7 | **0** | — | — | — | — | — | — | — | — | — | 9 | — |
| `Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | 10 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator` | 8 | **0** | — | — | — | — | — | — | — | — | — | 18 | — |
| `Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Sieve_Mesh` | 7 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026` | 11 | 1070 | 7 | 83 | 1 | 7 / 7 | 0 | 1 | 0 | 6 | 14 | 14 | 70 |
| `Volumetric_Glassware_2026/Graduated_Volumetric_Glassware_2026` | 11 | 1343 | 24 | 109 | 1 | 19 / 19 | 0 | 1 | 0 | 11 | 17 | 17 | 70 |
| `Waktu_/Master_Olda_Centrifuge` | 10 | **0** | — | — | — | — | — | — | — | — | — | 87 | — |
| `Waktu_/Master_Olda_Tachometer` | 10 | **0** | — | — | — | — | — | — | — | — | — | 87 | — |
| `Waktu_/Master_Olda_Timer_dan_Stopwatch` | 10 | **0** | — | — | — | — | — | — | — | — | — | 85 | — |
| `instrument-analiitk/5__Viscometer_86068360_terbaru_` | 8 | **0** | — | — | — | — | — | — | — | — | — | 2 | — |
| `instrument-analiitk/Gas_Detector_Uli_Skin__std_Rigaz_` | 6 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `instrument-analiitk/Master_Olah_Data_Autoclave` | 9 | **0** | — | — | — | — | — | — | — | — | — | 6 | — |
| `instrument-analiitk/Master_Olah_Data_Chlorine_Meter` | 6 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `instrument-analiitk/Master_Olah_Data_Conductivity` | 8 | **0** | — | — | — | — | — | — | — | — | — | 6 | — |
| `instrument-analiitk/Master_Olah_Data_DO_Meter` | 6 | **0** | — | — | — | — | — | — | — | — | — | 2 | — |
| `instrument-analiitk/Master_Olah_Data_Refractometer` | 7 | **0** | — | — | — | — | — | — | — | — | — | 23 | — |
| `instrument-analiitk/Master_Olah_Data_Spectrofotometer` | 7 | **0** | — | — | — | — | — | — | — | — | — | 2 | — |
| `instrument-analiitk/Master_Olah_Data_Turbidimeter` | 6 | **0** | — | — | — | — | — | — | — | — | — | 8 | — |
| `instrument-analiitk/Master_Olah_Data_Viscometer` | 8 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `instrument-analiitk/pH_meter_IMTE-WQ-129` | 7 | **0** | — | — | — | — | — | — | — | — | — | 0 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | 15 | **0** | — | — | — | — | — | — | — | — | — | 124 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Recorder` | 16 | **0** | — | — | — | — | — | — | — | — | — | 12 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | 17 | **0** | — | — | — | — | — | — | — | — | — | 6 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | 17 | **0** | — | — | — | — | — | — | — | — | — | 23 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | 15 | **0** | — | — | — | — | — | — | — | — | — | 31 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | 15 | **0** | — | — | — | — | — | — | — | — | — | 29 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermocouple` | 19 | **0** | — | — | — | — | — | — | — | — | — | 26 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermometer_Glass` | 15 | **0** | — | — | — | — | — | — | — | — | — | 108 | — |
| `suhu_&_kelembapan/Master_Olah_Data_Suhu___Kelembapan` | 9 | **0** | — | — | — | — | — | — | — | — | — | 1 | — |

## 3. Matriks per profil (48)

Kolom:
- **Jalur hari ini**: `grup` = profil menimpa `hitungPerGrup()` (budget lahir dari konteks sesi);
  `budget` = profil menyetor `komponenBudget()` ke `GumCalculator::hitungTitik()`. Menentukan
  `mode_iterasi` Lembar Hitung (`per_titik`/`per_sesi`) dan resolver yang dibutuhkan.
- **`.xlsm`** (diperbarui 13 Okt 2026 dari `_agents_workspace/studio-data-acuan/01-riset-peta-workbook.md`,
  pencocokan NAMA berkas atas 57 `.xlsm` di Downloads pemilik + subfolder `suhu\`): `manifest` = sha256
  tercatat di `database/data/manifest-workbook-*.json`; `ada` = berkas ada di Downloads pemilik, sha256
  belum dicatat (dicatat otomatis oleh `studio:impor-workbook --xlsm`, ST-T-208); `belum` = tidak ada
  `.xlsm`. `Qn` = pertanyaan pemilik bernomor di riset peta. Keterangan 9 Okt ("tidak ada `.xlsm` di
  Downloads") basi — agen saat itu tidak bisa membaca folder itu.
- **Catatan mesin**: hal yang diketahui dari kode/riset yang memengaruhi Lembar Hitung.
- **Gelombang**: usulan, `05-Task-Breakdown.md` S6.

| # | Kode profil | Kelas PHP (induk) | Jalur hari ini | Folder master | `.xlsm` | Rumus di CSV | Catatan mesin | Pertanyaan lab terbuka | Gelombang | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `labu_ukur` | `LabuUkurProfile` (`FixedVolumetricGlasswareProfile`) | grup | `Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026` | ada (Fixed; salinan per-alat LU-200/250/500-1, Q4) | ya (1.070) | contoh lengkap `03-SDD.md` §6.5; `#REF!` 14, tautan luar 70 (SENSOR_PT100 `[4]`, dibaca dari `FC_Prt_Pt100` lokal); veff master `K43` tidak ditiru di v1 (blok agregasi bawaan, K-49-07; meniru butuh versi baru + jawaban lab) | `pertanyaan-lab-volumetric.md` (13 butir), V-1–V-3 | **Pilot S4** | `belum` |
| 2 | `pipet_volume` | `PipetVolumeProfile` (Fixed) | grup | idem | ada (Fixed) | ya | idem | idem | **Pilot S4** | `belum` |
| 3 | `picnometer` | `PicnometerProfile` (Fixed) | grup | idem | ada (Fixed) | ya | idem; keluarga Fixed/Graduated dipertanyakan | idem no. 6 | **Pilot S4** | `belum` |
| 4 | `buret` | `BuretProfile` (`GraduatedVolumetricGlasswareProfile`) | grup | `Volumetric_Glassware_2026/Graduated_Volumetric_Glassware_2026` | ada (Graduated) | ya (1.343) | `per_sesi` (satu budget semua titik); nol hantu master `H55` sebagai catatan pembanding; larik 24; `#REF!` 17 | `pertanyaan-lab-volumetric.md` no. 2, 4, 5, 9, 10, 12 | **Pilot S4** | `belum` |
| 5 | `gelas_ukur` | `GelasUkurProfile` (Graduated) | grup | idem | ada (Graduated) | ya | idem | idem | **Pilot S4** | `belum` |
| 6 | `pipet_ukur` | `PipetUkurProfile` (Graduated) | grup | idem | ada (Graduated; salinan PV 0.5/2/3/4-1-26 — PV = pipet_ukur atau pipet_volume? Q1) | ya | idem; lembar pemilik memakai struktur Fixed | V-4 | **Pilot S4** | `belum` |
| 7 | `micrometer` | `MicrometerProfile` | grup | `Panjang_CSV/Master_Olah_Data_Micrometer_{0-25,25-50,50-75,75-100}mm` | **manifest** (4) | tidak | Data Acuan DB berstempel (P1); `NOW()` master → tanggal sesi (`MicrometerCalculator.php:55,164`); `skemaAcuan()` satu-satunya | `pertanyaan-lab-micrometer.md`, `keputusan-lab-micrometer.md`, T-1–T-9 | **Pilot S4** | `belum` |
| 8 | `dial_indicator` | `DialIndicatorProfile` | grup | `Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator` | ada | tidak | memakai balok ukur Micrometer (lingkup versi P1); `#REF!` nilai 18 | `pertanyaan-lab-dial-indicator.md` | W1 | `belum` |
| 9 | `jangka_sorong` | `JangkaSorongProfile` | grup | `…/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | ada | tidak | — | `pertanyaan-lab-jangka-sorong.md` | W1 | `belum` |
| 10 | `height_gauge` | `HeightGaugeProfile` | grup | `Panjang_CSV/Master_olda_Height_Gauge_600_mm_2026` | ada (2 berkas berukuran sama — duplikat? Q5) | tidak | `NOW()` → tanggal sesi (`HeightGaugeCalculator.php:77,210`); rujukan `L27` meleset dihitung benar; tanpa lantai CMC | `pertanyaan-lab-height-gauge.md` | W1 | `belum` |
| 11 | `sieve` | `SieveProfile` | grup | `…/Master_Olah_Data_Sieve_Mesh` | ada | tidak | `faktorCakupanTetap()` (k tetap) → sel `k_tetap` di blok agregasi bawaan | `pertanyaan-lab-sieve.md` | W1 | `belum` |
| 12 | `utm` | `UtmProfile` (`GayaProfile`) | grup | `Alat_Gaya/Gaya_UTM` | ada | ya (761) | larik 16, INDEX/MATCH 16 | `pertanyaan-lab-gaya.md` | W2 | `belum` |
| 13 | `load_cell` | `LoadCellProfile` (`GayaProfile`) | grup | `Alat_Gaya/Gaya_Load_Cell` | ada | ya (758) | larik 16 | idem | W2 | `belum` |
| 14 | `proving_ring` | `ProvingRingProfile` (`GayaProfile`) | grup | `Alat_Gaya/Gaya_Proving_Ring` | ada | ya (687) | larik 18; `#REF!` 3 | idem | W2 | `belum` |
| 15 | `timer_stopwatch` | `TimerStopwatchProfile` | grup | `Waktu_/Master_Olda_Timer_dan_Stopwatch` | ada | tidak | kelompok Waktu & Frekuensi: empat kerusakan master dihitung benar, lima titik hantu diblokir (baris G9 `permintaan-user-7.md`) | `pertanyaan-lab-waktu-frekuensi.md` | W3 | `belum` |
| 16 | `centrifuge` | `CentrifugeProfile` (`ProfilPutaran`) | grup | `Waktu_/Master_Olda_Centrifuge` | ada | tidak | `#REF!` nilai 87 | idem | W3 | `belum` |
| 17 | `tachometer` | `TachometerProfile` (`ProfilPutaran`) | grup | `Waktu_/Master_Olda_Tachometer` | ada | tidak | `#REF!` nilai 87 | idem | W3 | `belum` |
| 18 | `timbangan` | `TimbanganProfile` | grup | `Massa_Timbangan/_New__Master_Olda_Timbangan_{gram,kg}`, `…/_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | ada | tidak | tiga varian master (`VarianMasterTimbangan`), dua budget per titik | `pertanyaan-lab-timbangan.md` | W4 | `belum` |
| 19 | `anak_timbangan` | `AnakTimbanganProfile` | grup | `Massa_Timbangan/1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | belum (tidak ada di Downloads, Q6) | tidak | 21 CSV (sheet data sensitivitas & drift per standar) | `pertanyaan-lab-anak-timbangan.md` | W4 | `belum` |
| 20 | `tits` | `TitsProfile` | grup | `suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_{Measure,Source}_utk_UUT` | ada | tidak | pencocokan TERDEKAT tabel kalibrator (`TabelKalibratorSuhu.php:35-38`), bukan interpolasi | `pertanyaan-lab-tits.md` | W5 | `belum` |
| 21 | `tids` | `TidsProfile` | grup | `…/Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_`, `…/Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | ada | tidak | dua keluarga standar | `pertanyaan-lab-tids-workbook.md` | W5 | `belum` |
| 22 | `oven` | `OvenProfile` (`EnclosureProfileBase`) | grup | `…/Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa`, `…/Master_Olah_Data_Suhu_Enclosure_Recorder` | ada (Constant Yokogawa + Recorder; workbook mana untuk alat mana Q3) | tidak | lima profil Enclosure satu rancangan; pencocokan terdekat; `#REF!` nilai 124 + 12 | `pertanyaan-lab-enclosure.md` | W5 | `belum` |
| 23 | `furnace` | `FurnaceProfile` (Enclosure) | grup | idem | ada (idem) | tidak | idem | idem | W5 | `belum` |
| 24 | `bath` | `BathProfile` (Enclosure) | grup | idem | ada (idem) | tidak | idem | idem | W5 | `belum` |
| 25 | `inkubator` | `InkubatorProfile` (Enclosure) | grup | idem | ada (idem) | tidak | idem | idem | W5 | `belum` |
| 26 | `refrigerator` | `RefrigeratorProfile` (Enclosure) | grup | idem | ada (idem) | tidak | idem | idem | W5 | `belum` |
| 27 | `thermocouple` | `ThermocoupleProfile` (`ProfilSuhuPasangan`) | grup | `…/Master_Olah_Data_Suhu_Thermocouple` | ada | tidak | — | `pertanyaan-lab-suhu-3alat.md` | W5 | `belum` |
| 28 | `thermometer_glass` | `ThermometerGlassProfile` (`ProfilSuhuPasangan`) | grup | `…/Master_Olah_Data_Suhu_Thermometer_Glass` | ada | tidak | pola catatan `pengulangan_standar_dibagi_n` (`AGENTS.md` §Olah data 4); `#REF!` nilai 108 | idem | W5 | `belum` |
| 29 | `thermohygro` | `ThermohygroProfile` (`ProfilSuhuPasangan`) | grup | `…/Master_Olah_Data_Suhu___Kelembapan` | ada | tidak | — | `pertanyaan-lab-thermohygro-satuan.md`, `pertanyaan-lab-suhu-3alat.md` | W5 | `belum` |
| 30 | `flowmeter_totalizer` | `FlowmeterTotalizerProfile` (`FlowmeterProfile`) | grup | `Aliran_2/1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` (gravimetri), `Aliran_2/1_3_…Ultrasonic_Totalizer…` (UFM) | ada | tidak | varian metode UFM/gravimetri (`VarianMetodeFlowmeter`); pencocokan terdekat (`FlowmeterCalculator.php:101`) | `pertanyaan-lab-flowmeter.md`, `pertanyaan-lab-flowmeter-gravimetri.md` | W6 | `belum` |
| 31 | `flowmeter_flowrate` | `FlowmeterFlowrateProfile` (`FlowmeterProfile`) | grup | `Aliran_2/2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` (gravimetri), `Aliran_2/Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` (UFM) | ada | tidak | idem; deret UUT bersarang tiga durasi | idem | W6 | `belum` |
| 32 | `hydrometer` | `HydrometerProfile` | grup | `Hydrometer/Hydrometer_0.600-0.650_gmL`, `Hydrometer/Hydrometer_1.800-2.000_gmL` | belum (tidak ada di Downloads, Q6) | ya (1.214 + 1.186) | OFFSET 5 + FORECAST 5 per workbook (ditulis ulang tanpa volatile); polinom densitas orde-5, Cuckow; IFERROR 38 | `pertanyaan-lab-hydrometer.md` (§7 pita CMC, §14 rujukan meleset) | W7 | `belum` |
| 33 | `ph_meter` | `PhMeterProfile` | budget | `instrument-analiitk/pH_meter_IMTE-WQ-129` | ada (salinan per-alat IMTE-WQ-129, Q7) | tidak | konstanta di PHP (CR-48 fase 4); dua master | `pertanyaan-lab-ph-dua-master.md` | W8 | `belum` |
| 34 | `turbidimeter` | `TurbidimeterProfile` | budget | `…/Master_Olah_Data_Turbidimeter` | ada | tidak | konstanta di PHP; `#REF!` nilai 8 | — | W8 | `belum` |
| 35 | `chlorine_meter` | `ChlorineProfile` | budget | `…/Master_Olah_Data_Chlorine_Meter` | ada | tidak | konstanta di PHP | — | W8 | `belum` |
| 36 | `refractometer` | `RefractometerProfile` | budget | `…/Master_Olah_Data_Refractometer` | ada | tidak | konstanta di PHP; `#REF!` nilai 23 | — | W8 | `belum` |
| 37 | `conductivity_meter` | `ConductivityProfile` | budget | `…/Master_Olah_Data_Conductivity` | ada | tidak | konstanta di PHP | `pertanyaan-lab-conductivity.md` | W8 | `belum` |
| 38 | `spectrophotometer` | `SpectrophotometerProfile` | grup | `…/Master_Olah_Data_Spectrofotometer` | ada | tidak | STDEV terbesar per kelompok (`hitungPerGrup`) | `pertanyaan-lab-r2-spektro.md` | W8 | `belum` |
| 39 | `viscometer` | `ViscometerProfile` | budget | `…/5__Viscometer_86068360_terbaru_`, `…/Master_Olah_Data_Viscometer` | ada (Master + salinan 86068360, Q7) | tidak | `faktorCakupanTetap()` k = 2 (`GumCalculator.php:544-547`) → sel `k_tetap` di blok agregasi bawaan | `pertanyaan-lab-viscometer.md` | W8 | `belum` |
| 40 | `autoclave` | `AutoclaveProfile` | budget | `…/Master_Olah_Data_Autoclave` | ada | tidak | `AutoclaveCalculator` + `AutoclaveInputBuilder`; tabel suhu–tekanan | — | W8 | `belum` |
| 41 | `do_meter` | `DoMeterProfile` | budget | `…/Master_Olah_Data_DO_Meter` | ada | tidak | konstanta di PHP | — | W8 | `belum` |
| 42 | `gas_detector` | `GasDetectorProfile` | budget | `…/Gas_Detector_Uli_Skin__std_Rigaz_` | ada (salinan "Uli Skin (std Rigaz)", Q7) | tidak | pergeseran ruangan (riset A §6) | `pertanyaan-lab-gas-detector.md` | W8 | `belum` |
| 43 | `pressure_gauge` | `PressureGaugeProfile` (`TekananProfile`) | grup | `6_Alat_Pressure_Piston_MasterOlahData_CSV/Master_Olda_Pressure_{DRUCK07G_-0.82bar_new,DRUCK13G_020bar,SPMK}` | **manifest** | ya (869/844/832) | `versiRumus()` + log metode append-only + `tahan_terbit` (`TekananProfile.php:129`, `database/data/log-metode-tekanan-piston.json`); rekonsiliasi ST-T-169 | `pertanyaan-lab-tekanan.md` | W9 | `belum` |
| 44 | `vacuum_gauge` | `VacuumGaugeProfile` (`TekananProfile`) | grup | DRUCK07G, DRUCK13G (tanpa SPMK, `VacuumGaugeProfile.php:51-53`) | **manifest** | ya | idem | idem | W9 | `belum` |
| 45 | `differential_pressure` | `DifferentialPressureProfile` (`TekananProfile`) | grup | `…/Master_Olda_Pressure_Differential` | **manifest** | ya (977) | idem; tautan luar 1 | idem | W9 | `belum` |
| 46 | `piston_pipette` | `PistonPipetteProfile` (`PistonVolumeProfile`) | grup | `…/Master_Olah_Data_{Fixed,Graduated}_Piston_Volume` | **manifest** | ya (1.048 / 1.189) | `versiRumus()` (`PistonVolumeProfile.php:120`); `#REF!` 14/16, tautan luar 58/73; pernyataan kesesuaian belum terbit | `pertanyaan-lab-piston-volume.md` | W9 | `belum` |
| 47 | `dispensett` | `DispensettProfile` (`PistonVolumeProfile`) | grup | idem | **manifest** | ya | idem | idem | W9 | `belum` |
| 48 | `buret_digital` | `BuretDigitalProfile` (`PistonVolumeProfile`) | grup | idem | **manifest** | ya | idem | idem | W9 | `belum` |

Ringkasan: 48 profil; pilot S4 = 7 profil (Volumetric 6 + Micrometer); S6 = 41 profil dalam 9
gelombang. Jalur `budget` = 9 profil (semua di W8); 39 profil `grup`. `.xlsm`: 46 profil punya
berkas (7 `manifest`, 39 `ada`), 2 `belum` (anak_timbangan, hydrometer).

Mode **Asli Excel** (S1b, 13 Okt 2026): ke-46 folder CSV di repo sudah terimpor ke `workbook_master`
(DB lokal tablet: 473 lembar, 135.489 sel, 6.360 rumus, 99 lembar DATABASE/SERTIFIKAT dikecualikan);
peta folder → profil `PetaProfilWorkbook`. Enam folder tekanan/piston tidak ter-track (memuat data
pelanggan) dan menunggu impor `.xlsm` (ST-T-208).

## 4. Kenapa pilot Volumetric dan Micrometer

| | Volumetric (Fixed + Graduated) | Micrometer |
|---|---|---|
| Contoh pemilik | lembar "CALIBRATION BUDGET UNCERTAINTY" Pipet Ukur (9 Okt) | pilot CR-48 |
| Rumus di CSV | ya (1.070 + 1.343 sel) | tidak |
| `.xlsm` | ada di Downloads pemilik (Fixed + Graduated + salinan LU/PV; 13 Okt) | ada di mesin pemilik (manifest 4 berkas) |
| Bukti kode = master | V20 Fixed dan uc pipet 1 ml selisih 0 (`04-paritas-volumetric.md`) | 53 nilai pada 5·10⁻⁶ (§17), isi `.xlsm` = JSON (§48 P0) |
| Yang diuji pilot | rantai panjang, larik INDEX/MATCH, tabel acuan, resolver CMC, catatan penyimpangan, mode `per_titik` dan `per_sesi` | Data Acuan berstempel DB, empat varian, `NOW()` → tanggal sesi |
| Pertanyaan terbuka | V-1–V-4 + 13 butir (tidak menghalangi paritas, karena paritas lawan PHP) | T-1–T-9 (toleransi uji pembanding) |

## 5. Cara memperbarui matriks

Satu baris berubah status = satu commit yang juga memuat bukti: tautan laporan `simulasi_versi`
(paritas/bayangan) dan catatan penjaga-angka. Jangan menulis `aktif` tanpa versi `sumber = database`
yang benar-benar berlaku (`GET /api/lembar-hitung/alat`). Status yang ditulis tangan bisa basi; yang
mengikat adalah respons API itu.
