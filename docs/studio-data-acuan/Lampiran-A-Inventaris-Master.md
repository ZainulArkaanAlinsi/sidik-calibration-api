# Lampiran A — Inventaris master olah data → Studio

Dibangkitkan 8 Okt 2026 dari isi `Project-PT-Sidik/alat-alat-Pt-Sidik/` (46 folder, 473 CSV
ekspor nilai) dan pemetaan manual ke profil/tabel/formulir. CSV **tidak memuat rumus**; peta sel
resmi dibuat dari `.xlsm` asli di mesin pemilik (T0.2, T3.2). Master Tekanan & Piston Volume
(6 workbook) tidak ada di folder ini — CSV-nya di-gitignore (`.gitignore` baris 245–252), acuannya
sudah di `tabel-standar-tekanan.json` & `tabel-standar-piston-volume.json` dengan manifest sha256.

Kolom:
- **Calon tab Studio (lapis 1)** = sheet selain `INPUT DATA` (→ tab Bentuk), `PERHITUNGAN*`/koef.
  sensitivitas/`Misalignment` (→ tab Rumus, baca saja), `SERTIFIKAT*` (→ pratinjau),
  `DATABASE` (hanya sel yang dipetakan; data pelanggan tidak pernah), `FORM VALIDASI` (→ versi
  workbook), `konsep`/`Sekilas Info`/`Drawing*` (dokumen, bukan data).
- **Sumber acuan hari ini**: berkas `database/data/*.json`, atau "konstanta PHP" = nilai masih
  di dalam kelas profil (fase 4), atau kelas PHP tanpa JSON.
- **Formulir**: nomor `SIDIK-FM-CAL-…` di `worksheet_alat_calibration/`.

| Folder master | Profil | Sumber acuan hari ini | Formulir | Calon tab Studio (lapis 1) | Sheet rumus (lapis 2, baca saja) |
|---|---|---|---|---|---|
| Alat_Gaya/Gaya_Load_Cell | LoadCellProfile | `tabel-standar-gaya` | 0520 Rev.3 | STANDAR_LOADCELL | Misalignment, PERHITUNGAN_FC, PERHITUNGAN_U95pct |
| Alat_Gaya/Gaya_Proving_Ring | ProvingRingProfile | `tabel-standar-gaya` | 0521 Rev.3 | STANDAR_LOADCELL | Misalignment, PERHITUNGAN_FC, PERHITUNGAN_U95pct |
| Alat_Gaya/Gaya_UTM | UtmProfile | `tabel-standar-gaya` | 0519 Rev.3 | STANDAR_LOADCELL | Misalignment, PERHITUNGAN_FC, PERHITUNGAN_U95pct |
| Aliran_2/1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_ | FlowmeterTotalizerProfile (gravimetri) | `tabel-standar-flowmeter-gravimetri` | 0538.B Rev.3 | Drift Constant-TC Type K, Drift Timbangan Dini Argeo, Drift Timbangan Mettler, Drift Timbangan Sartorius, Drift Yokogawa-TC Type K, STANDAR KALIBRATOR | PERHITUNGAN FC, PERHITUNGAN U95% |
| Aliran_2/1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026 | FlowmeterTotalizerProfile (UFM) | `tabel-standar-flowmeter` | 0538 Rev.0 / 0538.B | Drift Yokogawa-TC Type K, STANDAR KALIBRATOR | PERHITUNGAN FC, PERHITUNGAN U95% |
| Aliran_2/2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026 | FlowmeterFlowrateProfile (gravimetri) | `tabel-standar-flowmeter-gravimetri` | 0538.A Rev.3 | Drift Constant-TC Type K, Drift Timbangan Dini Argeo, Drift Timbangan Mettler, Drift Timbangan Sartorius, Drift Timer Software Flowmeter, Drift Yokogawa-TC Type K, STANDAR KALIBRATOR, Unit Converter | PERHITUNGAN FC, PERHITUNGAN U95% |
| Aliran_2/Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026 | FlowmeterFlowrateProfile (UFM) | `tabel-standar-flowmeter` | 0538 Rev.0 / 0538.A | Drift Timer Software Flowmeter, Drift Yokogawa-TC Type K, STANDAR KALIBRATOR | PERHITUNGAN FC, PERHITUNGAN U95% |
| Hydrometer/Hydrometer_0.600-0.650_gmL | HydrometerProfile | `TabelStandarHydrometer (PHP)` | 0533 Rev.2 | Tabel_Surface_Tension | NILAI_U95pct, PERHITUNGAN, PERHITUNGAN_2 |
| Hydrometer/Hydrometer_1.800-2.000_gmL | HydrometerProfile | `TabelStandarHydrometer (PHP)` | 0533 Rev.2 | Tabel_Surface_Tension | NILAI_U95pct, PERHITUNGAN, PERHITUNGAN_2 |
| Massa_Timbangan/1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp | AnakTimbanganProfile | `tabel-standar-anak-timbangan` | 0541 Rev.0 | Data Sens Analytical Balance, Data Sens Excellent, Data Sens Fujitsu, Data Sens Mettler Toledo, Data Sens Semi Micro, Deviasi Standard Timbangan, Drift AT E2(0.1-200g), Drift AT E2(1kg), Drift AT E2(500g), Drift AT F1(10kg), Drift AT F1(20kg), Drift AT F1(2kg), Drift AT F1(5kg), MPE AT, STD AT | PERHITUNGAN FC, PERHITUNGAN U95% |
| Massa_Timbangan/_New__Master_Olda_Timbangan_gram | TimbanganProfile | `tabel-standar-timbangan` | 0508 Rev.6 | Drift AT E2(0.1-200g), Drift AT F1(0.1-500g), Drift AT F1(10kg), Drift AT F2(1-5kg), Drift AT M2(20kg), STANDAR_AT | PERHITUNGAN FC, PERHITUNGAN U95% - Correction, PERHITUNGAN U95%-Weighing |
| Massa_Timbangan/_New__Master_Olda_Timbangan_kg | TimbanganProfile | `tabel-standar-timbangan` | 0508 Rev.6 | Drift AT E2(0.1-200g), Drift AT F1(0.1-500g), Drift AT F1(10kg), Drift AT F2(1-5kg), Drift AT M2(20kg), STANDAR_AT | PERHITUNGAN FC, PERHITUNGAN U95% - Correction, PERHITUNGAN U95%-Weighing |
| Massa_Timbangan/_TERBARU__Master_Olda_Timbangan_Subtitusi_291025 | TimbanganProfile (substitusi) | `tabel-standar-timbangan` | 0508.A Rev.4 | STANDAR_AT | PERHITUNGAN FC, PERHITUNGAN U95% - Correction, PERHITUNGAN U95%-Weighing |
| Panjang_CSV/Master_Olah_Data_Micrometer_0-25mm | MicrometerProfile (A) | `tabel-standar-micrometer` | 0522.A Rev.1 | Standar_GB | PERHITUNGAN, PERHITUNGAN U95%, Perhitungan koef. Sensitivitas |
| Panjang_CSV/Master_Olah_Data_Micrometer_25-50mm | MicrometerProfile (B) | `tabel-standar-micrometer` | 0522.B Rev.1 | Standar_GB | PERHITUNGAN, PERHITUNGAN U95%, Perhitungan koef. Sensitivitas |
| Panjang_CSV/Master_Olah_Data_Micrometer_50-75mm | MicrometerProfile (C) | `tabel-standar-micrometer` | 0522.C Rev.1 | Standar_GB | PERHITUNGAN, PERHITUNGAN U95%, Perhitungan koef. Sensitivitas |
| Panjang_CSV/Master_Olah_Data_Micrometer_75-100mm | MicrometerProfile (D) | `tabel-standar-micrometer` | 0522.D Rev.1 | Standar_GB | PERHITUNGAN, PERHITUNGAN U95%, Perhitungan koef. Sensitivitas |
| Panjang_CSV/Master_olda_Height_Gauge_600_mm_2026 | HeightGaugeProfile | `tabel-standar-height-gauge` | — (belum ada PDF) | Std_CaliperCek | PERHITUNGAN, PERHITUNGAN U95% |
| Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_ | JangkaSorongProfile | `tabel-standar-jangka-sorong` | 0527 Rev.2 | Standar_GB, Std_CaliperCek | PERHITUNGAN, PERHITUNGAN U95%, Perhitungan koef. Sensitivitas |
| Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator | DialIndicatorProfile | `tabel-standar-dial-indicator` | 0526 Rev.3 | Standar_GB | PERHITUNGAN, PERHITUNGAN U95%, Perhitungan koef. Sensitivitas |
| Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Sieve_Mesh | SieveProfile | `tabel-standar-sieve` | 0536 Rev.2 | STANDAR_KALIBRATOR | PERHITUNGAN, PERHITUNGAN U95% |
| Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026 | FixedVolumetricGlasswareProfile | `tabel-standar-volumetric` | 0513 Rev.4 | FC_Prt_Pt100, SENSOR_PT100, STANDAR-YOKOGAWA, STANDARD_KALIBRATOR, Tabel_Maximum_Internal_Diameter | PERHITUNGAN, PERHITUNGAN_U95pct |
| Volumetric_Glassware_2026/Graduated_Volumetric_Glassware_2026 | GraduatedVolumetricGlasswareProfile | `tabel-standar-volumetric` | 0514 Rev.4 | FC_Prt_Pt100, SENSOR_PT100, STANDAR-YOKOGAWA, STANDARD_KALIBRATOR | PERHITUNGAN, PERHITUNGAN_U95pct, Tabel_Koefisien_Muai_Bahan |
| Waktu_/Master_Olda_Centrifuge | CentrifugeProfile | `tabel-standar-putaran` | 0515 Rev.4 | Drift Std Kalibrator, SERTIFIKAT KALIBRATOR | PERHITUNGAN, PERHITUNGAN U95% |
| Waktu_/Master_Olda_Tachometer | TachometerProfile | `tabel-standar-putaran` | 0515 Rev.4 | Drift Std Kalibrator, SERTIFIKAT KALIBRATOR | PERHITUNGAN, PERHITUNGAN U95% |
| Waktu_/Master_Olda_Timer_dan_Stopwatch | TimerStopwatchProfile | `tabel-standar-waktu` | 0512 Rev.4 | Drift Stopwatch, Human Reaction, SERTIFIKAT KALIBRATOR | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/5__Viscometer_86068360_terbaru_ | ViscometerProfile | `konstanta PHP` | 0524 Rev.3 | MPE Visco, Tabel Pengaruh Temperature | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/Gas_Detector_Uli_Skin__std_Rigaz_ | GasDetectorProfile | `konstanta PHP` | — (belum ada PDF) | — | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/Master_Olah_Data_Autoclave | AutoclaveProfile | `konstanta PHP` | 0539 Rev.4 | STANDAR KALIBRATOR, Tabel Suhu - Tekanan | PERHITUNGAN FC, PERHITUNGAN U95% |
| instrument-analiitk/Master_Olah_Data_Chlorine_Meter | ChlorineProfile | `konstanta PHP` | 0531 Rev.2 | — | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/Master_Olah_Data_Conductivity | ConductivityProfile | `konstanta PHP` | 0510 Rev.5 | — | PERHITUNGAN, PERHITUNGAN U95%, nilai koefisien sensitifitas |
| instrument-analiitk/Master_Olah_Data_DO_Meter | DoMeterProfile | `konstanta PHP` | 0532 Rev.2 | — | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/Master_Olah_Data_Refractometer | RefractometerProfile | `konstanta PHP` | 0523 Rev.2 | Tab Konversi Temperatur | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/Master_Olah_Data_Spectrofotometer | SpectrophotometerProfile | `konstanta PHP` | 0511 Rev.5 | STANDAR_KALIBRATOR | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/Master_Olah_Data_Turbidimeter | TurbidimeterProfile | `konstanta PHP` | 0530 Rev.2 | — | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/Master_Olah_Data_Viscometer | ViscometerProfile | `konstanta PHP` | 0524 Rev.3 | MPE Visco, Tabel Pengaruh Temperature | PERHITUNGAN, PERHITUNGAN U95% |
| instrument-analiitk/pH_meter_IMTE-WQ-129 | PhMeterProfile | `konstanta PHP` | 0509 Rev.4 | — | Nilai koefisien Sensitifitas, PERHITUNGAN, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa | Enclosure/* (Oven, Inkubator, …) | `tabel-kalibrator-enclosure` | 0504 Rev.3 | FC Prt Pt100, Interpolasi, SENSOR PT100, STANDAR KALIBRATOR, STANDAR-CONSTANT, STANDAR-YOKOGAWA, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Recorder | Enclosure/* | `tabel-kalibrator-enclosure` | 0504 Rev.3 | Drift Rec TC Type K, Drift Rec TC Type N, FC Prt Pt100, Interpolasi, Old_Std Kalibrator, SENSOR PT100, Standar_Kalibrator, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ | TidsProfile | `tabel-standar-tids + tabel-kalibrator-suhu` | 0506 Rev.4 | Drift Rec TC Type K, Drift Rec TC Type N, FC Prt Pt100, Interpolasi, SENSOR PT100, Standar_Recorder, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N, Variasi axial Dryblok A, Variasi axial Dryblok B, stdev drywell | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N | TidsProfile | `tabel-standar-tids + tabel-kalibrator-suhu` | 0506 Rev.4 | FC Prt Pt100, Interpolasi, SENSOR PT100, STANDAR KALIBRATOR, STANDAR-CONSTANT, STANDAR-YOKOGAWA, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N, Variasi axial Dryblok A, Variasi axial Dryblok B, stdev drywell | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT | TitsProfile | `tabel-kalibrator-suhu` | 0505 Rev.3 | FC Prt Pt100, Interpolasi, SENSOR PT100, STANDAR KALIBRATOR, STANDAR-CONSTANT, STANDAR-YOKOGAWA, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT | TitsProfile | `tabel-kalibrator-suhu` | 0505 Rev.3 | FC Prt Pt100, Interpolasi, SENSOR PT100, STANDAR KALIBRATOR, STANDAR-CONSTANT, STANDAR-YOKOGAWA, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermocouple | ThermocoupleProfile | `tabel-master-suhu-3alat` | 0535 Rev.2 | FC Prt Pt100, Interpolasi, SENSOR PT100, STANDAR KALIBRATOR, STANDAR-CONSTANT, STANDAR-VICTOR, STANDAR-YOKOGAWA, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N, Variasi axial Dryblok A, Variasi axial Dryblok B, stdev drywell | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermometer_Glass | ThermometerGlassProfile | `tabel-master-suhu-3alat` | 0537 Rev.2 | FC Prt Pt100, Interpolasi, SENSOR PT100, STANDAR KALIBRATOR, STANDAR-CONSTANT, Stndar Yokogawa, TERMOCOUPLE TYPE K, TERMOCOUPLE TYPE N, Variasi Spasial & stab Oilbath | PERHITUNGAN FC, PERHITUNGAN U95% |
| suhu_&_kelembapan/Master_Olah_Data_Suhu___Kelembapan | ThermohygroProfile | `tabel-master-suhu-3alat + thermohygro-lab` | 0525 Rev.3 | STANDAR_KALIBRATOR, stabilitas dan homogenitas Cham | PERHITUNGAN FC, PERHITUNGAN U95% |
## Catatan

- Satu profil bisa punya beberapa workbook: varian (Micrometer A–D), revisi (Timbangan gram/kg/
  substitusi), atau keluarga standar (TIDS Graptech vs Yokogawa). Satu paket per profil; varian
  & keluarga standar jadi lembar/kunci di dalam paket, bukan paket terpisah — mengikuti cara
  generator hari ini menggabungkannya (mis. `gen-tabel-standar-micrometer.py` mengadu 4 workbook).
- Formulir tanpa master di folder ini: 0507 Pressure & Vacuum, 0516 Kelistrikan, 0528/0529 Piston
  Volume (master di luar folder), 0540 TDS (Non KAN).
- Height Gauge dan Gas Detector punya master tetapi formulirnya tidak ada di
  `worksheet_alat_calibration/` per 8 Okt.
