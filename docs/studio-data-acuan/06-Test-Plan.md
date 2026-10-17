# 06 — Test Plan Studio Data Acuan

Gerbang: suite SQLite **dan** MySQL hijau (AGENTS.md §Test), widget/golden test Flutter hijau,
uji pembanding alat pilot hijau, uji HP nyata untuk FR-21/23. Satu alat tidak boleh disebut
"selesai" sebelum 06 §3 terisi dan lulus.

## 1. Pemetaan FR → test

| FR | Test (rencana nama kelas / kasus) | Jenis |
|---|---|---|
| FR-01 | `DataAcuanPaketTest::daftar_menampilkan_paket_organisasi_sendiri`, `…_paket_belum_migrasi_ditandai` | Feature |
| FR-02 | `SkemaAcuanMicrometerTest::skema_mencakup_semua_kunci_json` | Unit |
| FR-03 | `RiwayatSelAcuanTest::asal_dan_pemakai_sel` | Feature |
| FR-04 | `VersiAcuanBerlakuTest::tanggal_sebelum_v1_null`, `…_batas_rentang_inklusif` | Feature |
| FR-05 | `DrafAcuanTest::draf_menyalin_isi_dasar_persis` (sha256 isi = dasar) | Feature |
| FR-06 | `SuntingSelAcuanTest` — per kode: `nilai_tidak_valid`, `kunci_kembar`, sel kosong pada kolom wajib, koma/titik | Feature |
| FR-07 | `angka_lokal_test.dart` (20 kasus BR-04) + `TempelBlokAcuanTest` | Unit (Dart) + Feature |
| FR-08 | `BarisAcuanTest::hapus_balok_yang_dipakai_titik_ditolak` (nominal 5,1 = 1,1+2,5+1,5) | Feature |
| FR-09 | `KunciDrafAcuanTest::if_match_usang_409`, `…_bukan_pemilik_403`, `…_ambil_alih_sa_tercatat` | Feature |
| FR-10 | `ImporWorkbookAcuanTest::berkas_kembar`, `…_struktur_berubah`, `…_sel_kosong`, `…_terenkripsi` | Feature |
| FR-11 | `BandingVersiAcuanTest::selisih_absolut_relatif` | Feature |
| FR-12 | `SimulasiAcuanTest::ubah_satu_balok_lapor_titik_dan_kolom`, `…_angka_cetak_berubah_dihitung_dengan_desimal_profil`, `…_tidak_menyimpan_ke_sesi` | Feature |
| FR-13 | `SimulasiAcuanTest::sunting_sesudah_simulasi_kedaluwarsa` | Feature |
| FR-14 | `UjiPembandingMicrometerTest` (06 §3) | Feature |
| FR-15 | `AjukanAcuanTest::tanpa_simulasi_ditolak`, `…_isi_beku_sha256` | Feature |
| FR-16 | `SahkanAcuanTest::sa_bukan_penyunting_berhasil`, `…_rentang_ditutup_satu_transaksi`, `…_sandi_salah` | Feature |
| FR-17 | `SahkanAcuanTest::tolak_final`, `…_kembalikan_jadi_draf_baru` | Feature |
| FR-18 | `BerlakuMundurAcuanTest::menyentuh_terbit_ditolak` | Feature |
| FR-19 | `TarikVersiAcuanTest::sesi_belum_terbit_ditahan_menunggu_keputusan_tm` | Feature |
| FR-20 | `StempelAcuanTest::semua_jalur_simpan_menstempel` (store, update, preview TIDAK menyimpan, hitung-ulang) | Feature |
| FR-21 | `LembarKerjaVersiAcuanTest::etag_304`, `…_field_lama_identik` | Feature |
| FR-22 | `SiaranDataAcuanTest::siarkan_aman_saat_aktif_dan_terjadwal` | Feature |
| FR-23 | `banner_draf_terdampak_test.dart` + uji HP nyata | Widget + manual |
| FR-24/25 | `BentukLembarOverrideTest::hapus_field_kontrak_422`, `…_jenis_field_baru_klien_lama_ditolak`, `…_nominal_dipatok_ik_terkunci` | Feature |
| FR-26/27 | `PetaRumusTest::dari_deklarasi_kode`, `UsulanRumusTest::markdown_lengkap_tanpa_mengubah_hitung` | Feature |
| FR-28 | `LaporanPerubahanAcuanTest::csv_dan_pdf` | Feature |
| FR-29 | `PengingatStandarAcuanTest::h30_h7_h1` | Feature |
| BR-08 | `PemisahanWewenangAcuanTest` — pembuat, penyunting, pengaju, pengunggah masing-masing ditolak sebagai pengesah, termasuk SA | Feature |
| Izin | `IzinDataAcuanTest` — matriks 02 §4 lengkap via HTTP, + org lain 404 | Feature |
| Cache | `CacheAcuanPerVersiTest::worker_hidup_memakai_versi_baru_sesudah_sah` | Feature |
| Setara | `TabelAcuanSetaraJsonTest` — tiap paket termigrasi: float yang sampai ke calculator identik bit dengan baca JSON lama | Unit |

## 2. Regresi (wajib hijau tanpa diubah)

Tidak boleh ada test di bawah yang disunting untuk membuat perubahan ini lulus:

- Micrometer: `MicrometerSesiTest`, `MicrometerSertifikatTest`, `AuditMicrometerCmcTest`
- Rumus berversi: `RumusBerversiTest`, `NomorVersiRumusTidakBentrokTest`, `VersiRumusTekananPistonTest`
- Pembanding master lain: `SertifikatCocokMasterTest`, `SertifikatSpektroCocokMasterTest`,
  `GayaSesiContohCocokMasterTest`, `ViscometerMasterBaruTest`, `PhMeterMasterTest`
- Wewenang & gerbang: `PemisahanWewenangPersetujuanTest`, `GerbangPengesahanTest`,
  `SuperAdminAksesTest`, `SuperAdminLintasOrganisasiTest`, `SesiDisetujuiTidakBisaDimundurkanTest`
- Realtime: `RealtimeSyncTest`
- Seluruh suite untuk setiap alat yang `Tabel*`-nya dipindah ke `SumberAcuan` (T3.7, T4.x)

Mobile: seluruh `flutter test` + golden desktop shell; uji HP fisik untuk lembar Micrometer.

## 3. Uji pembanding Excel — alat pilot Micrometer

**Sumber kebenaran:** 4 workbook `.xlsm` pada sha256 manifest (T0.2), **dihitung ulang** dulu
(LibreOffice headless, lalu baca nilai) — bukan nilai cache yang mungkin usang. Bila nilai
hasil hitung ulang berbeda dari cache, selisihnya dilaporkan sebelum apa pun dibandingkan.

**Yang diadu:** setiap komponen budget per titik (bukan cuma U95), koreksi, nilai standar,
U95, k, angka cetak sertifikat, vonis.

**Kasus wajib per alat:**

| Kelas | Isi untuk Micrometer |
|---|---|
| Normal | sesi master `sesi-master-micrometer.json` per varian A–D |
| Batas rentang | titik 0,0 dan 25,0 (A); kapasitas tepat di batas pita 25/50/75/100 mm |
| Percabangan | tangga ketidakpastian balok (≤10, ≤21, ≤50, ≤100, persis 101,6/200, lainnya) |
| Tidak valid | nominal yang tidak ada di tabel → titik **diblokir dengan alasan**, bukan nol |
| Pembulatan | nilai yang tepat di tengah digit cetak terakhir (…5) pada `desimalSertifikat` & `desimalU95` |
| Perubahan acuan | v2 = v1 dengan satu nilai terkoreksi diubah → hanya titik yang memakai keping itu berubah |

**Toleransi** — diisi Lab (T0.3), dengan dasar resolusi alat dan metode, **bukan** dipilih supaya
lulus:

| Besaran | Toleransi | Dasar | Disetujui |
|---|---|---|---|
| nilai standar tumpukan (mm) | _diisi Lab_ | | |
| tiap komponen u (µm) | _diisi Lab_ | | |
| U95 (µm) | _diisi Lab_ | | |
| angka cetak | identik string | aturan cetak | |

Pembanding yang sudah dipakai repo, sebagai rujukan skala (bukan pengganti keputusan Lab):
pencocokan kunci tabel 1e-9 (`TabelStandarMicrometer::nilaiTerkoreksi`), pembuktian Waktu &
Frekuensi 464 nilai pada 5·10⁻⁶ (`permintaan-user-7.md` §15).

**Bila ada selisih:** telusuri ke sheet/sel/rumus/satuan; catat di `docs/pertanyaan-lab-*.md`;
penerbitan sesi terdampak ditahan sampai diputuskan.

## 4. Bukti yang sudah ada sebelum kode ditulis (8 Okt 2026)

| ID | Pemeriksaan | Hasil |
|---|---|---|
| TC-REF-01 | Nominal & nilai terkoreksi balok ukur di 4 `Standar_GB.csv` Micrometer vs `database/data/tabel-standar-micrometer.json` | **32/32 cocok** di keempat varian, selisih 0 (toleransi 1e-9) |
| TC-REF-02 | Tata letak kolom blok balok ukur antar-varian | 0-25 & 75-100: kolom 1 & 9; 25-50 & 50-75: kolom 1, **2**, & 9 → importer wajib berbasis peta sel + validasi isi |

Skrip pemeriksaan disimpan sebagai lampiran kerja; versi resminya menjadi test di T3.1.

## 5. Uji non-fungsional

| NFR | Cara |
|---|---|
| NFR-01 | grid 2.000×20 di laptop kantor, frame time direkam |
| NFR-02 | 500 sesi sintetis → simulasi diukur; simpan sel p95 dari log |
| NFR-03 | sha256 dihitung di SQLite & MySQL dari isi sama → identik |
| NFR-07 | putus jaringan saat menyunting → tidak ada data hilang, pesan benar |
| Keamanan | rute tulis tanpa izin → 403/404 via HTTP (bukan baca daftar rute) |
