# Kesiapan Uji Sistem — 5 Oktober 2026

Disusun 4 Oktober 2026 malam. Semua pemeriksaan di bawah **baca saja**: tidak ada
INSERT/UPDATE/DELETE ke produksi, dan tidak ada deploy sesudah `12add55`. Status
memakai tiga label: **SIAP** (ada bukti perintah/test dan hasilnya), **BELUM SIAP**
(penyebab disebut), **TIDAK TERVERIFIKASI** (yang kurang disebut).

Berkas ini ada di repo publik, jadi sengaja tidak memuat nama pelanggan, sandi,
token, kunci API, atau email pribadi.

---

## 1. Status per area

### A. Infrastruktur

| Butir | Status | Bukti |
|---|---|---|
| API produksi sehat | **SIAP** | `GET /api/health` → `status: ok`, `deploy.versi = 12add55…` (cek 4 Okt) |
| Koneksi database | **SIAP** | Health membaca direktori lokal (10.320 baris); query baca lewat `tinker` berhasil |
| Migrasi terapan semua | **SIAP** | `php artisan migrate:status` ke produksi: 111 dari 111 `Ran`, 0 tertunda; jumlah berkas migrasi di repo juga 111 |
| Backup database terbaru | **SIAP** | `mysqldump --single-transaction` (tanpa lock) 4 Okt 20:33 → `C:\cadangan-sidik\produksi-20261004-2033.sql`, 3,4 MB, 53 tabel, berakhir `-- Dump completed`. **Uji restore** ke DB tes lokal: 53 tabel, 8 pengguna, 2 sesi, 220 pembacaan, 80 standar, 111 migrasi — cocok dengan sensus produksi |
| Backup otomatis penyedia DB (Aiven) | **TIDAK TERVERIFIKASI** | Konsol Aiven tidak diakses (kebijakan AGENTS.md); jangan bergantung padanya |
| APK terbaru | **SIAP** (dengan catatan) | v1.0.652 (build 652, 1 Okt) di GitHub Releases, unduhan HTTP 200, 73 MB. `API_BASE_URL` repo mobile = `https://sidik-calibration-api.onrender.com`. `GET /api/app/versi-terbaru` → 1.0.652. **Instalasi di HP tidak terverifikasi** (tidak ada perangkat) |
| Versi Windows/web | **SIAP** (dengan catatan) | `https://sidik-kalibrasi.web.app/versi-windows-v2.json` → 1.0.652; `sidik-windows.zip` HTTP 200. Instalasi tidak terverifikasi |
| Log error 24 jam | **TIDAK TERVERIFIKASI** | Render CLI tidak terpasang dan Render MCP belum login. Proksi dari database: `failed_jobs` 0 (24 jam maupun total), antrean `jobs` 0 |

### B. Olah data

| Butir | Status | Bukti |
|---|---|---|
| Daftar alat vs master | **SIAP** | 55 workbook master di `Project-PT-Sidik/alat-alat-Pt-Sidik/` (12 kelompok). Semua punya profil dan test yang merujuknya, termasuk varian ultrasonic, DRUCK/SPMK, dan timbangan substitusi |
| Angka sama dengan master (data contoh) | **SIAP** | 564 kasus di 49 kelas test hitung/rekonsiliasi (tabel di bawah). Lulus di suite MySQL penuh 4 Okt (5.218/5.218, commit `4c0de53`) dan SQLite 3 Okt (869 test) |
| Angka sama dengan master (data lapangan baru) | **TIDAK TERVERIFIKASI** | Produksi baru punya 2 sesi dan **0** hasil hitung. Uji pembanding dengan lembar nyata belum dilakukan — lihat `Lembar-Pembanding-Olah-Data.xlsx` |
| Percabangan, input tidak valid, pembulatan | **SIAP** sebagian | Test per alat memuat varian & penolakan (mis. `HydrometerMasterTest::varian_salah…`, `VolumetricGlasswareMasterTest::test_kelas_di_luar_a_b_ditolak`); pembulatan layar = sertifikat dijaga `DesimalLayarSamaDenganSertifikatTest`. Cakupan per alat tidak seragam |
| Versi rumus tersimpan di tiap hasil | **SIAP** | `formula_version_id` ditulis di jalur simpan (`CalibrationController.php:1464`) dan hitung ulang (`HitungUlangSesi.php:684`); dijaga `RumusBerversiTest` |
| Versi rumus sama di API dan mobile / tercantum | **BELUM SIAP** | Tidak ditemukan di snapshot maupun PDF sertifikat (`CertificateSnapshotBuilder`, `BerkasPdfSertifikat`, `resources/views`: nol rujukan) |

**Tabel test hitung per alat** (semua lulus; "selisih terbesar" tidak diukur — test hanya menyatakan selisih di bawah toleransinya):

| Alat / kelompok | Kelas test (jumlah kasus) | Toleransi |
|---|---|---|
| pH | PhMeterMasterTest (4), UncertaintyBudgetTest (9), SertifikatCocokMasterTest (22, bersama) | 5e-6 relatif |
| Turbidimeter / Chlorine / Refractometer | TurbidimeterBudgetTest (6), ChlorineBudgetTest (12), RefractometerBudgetTest (10) | inline |
| Conductivity | ConductivityBudgetTest (17) | 1e-8 |
| Spectrophotometer | SpectrophotometerBudgetTest (24), SertifikatSpektroCocokMasterTest (7) | 1e-12 |
| Viscometer | ViscometerBudgetTest (15), ViscometerMasterBaruTest (13) | inline |
| Autoclave / DO / Gas detector | AutoclaveCalculatorTest (5), DoMeterBudgetTest (8), GasDetectorBudgetTest (18) | inline / delta tetap |
| TITS / TIDS | TitsBudgetTest (24), TidsMasterTest (8) | 1e-12, k 5e-4 |
| Enclosure ×5 | EnclosureBudgetTest (20), TabelKalibratorEnclosureCocokMasterTest (8) | 1e-9, k 5e-4 |
| Thermocouple / Glass / Thermohygro | Suhu3AlatMasterTest (15), Suhu3AlatLembarKerjaTest (14) | inline |
| Timbangan / Anak timbangan | TimbanganMasterTest (19), AnakTimbanganMasterTest (12) | 5e-6 |
| Waktu ×3 | WaktuFrekuensiMasterTest (16), WaktuFrekuensiSertifikatTest (8) | 5e-6 |
| Micrometer / Height gauge / Dial / Jangka sorong / Sieve | MicrometerMasterTest (31), HeightGaugeMasterTest (17), DialIndicatorMasterTest (9), JangkaSorongMasterTest (10), SieveMasterTest (16) | 5e-6 |
| Flowmeter ×2 (+ultrasonic) | FlowmeterMasterTest (9), FlowmeterGravimetriMasterTest (10), FlowmeterLantaiCmcTest (4) | 5e-6 |
| Hydrometer | HydrometerMasterTest (13) | 1e-12 |
| Volumetrik ×6 | VolumetricGlasswareMasterTest (6), VolumetricGlasswareBudgetTest (5) | 5e-6 / 1e-10 |
| Gaya ×3 | ProvingRingMasterTest (11), GayaCalculatorTest (14), GayaBudgetTest (4), GayaSesiContohCocokMasterTest (5) | 5e-6 |
| Tekanan ×3 / Piston ×3 | TekananMasterTest (17), TekananCmcTest (6), PistonVolumeMasterTest (9) | 1e-12 |

**Boleh dicoba besok, jangan dipakai untuk sertifikat** sampai lab menjawab
pertanyaan yang tercatat terbuka (menurut `docs/pertanyaan-lab-suhu.md` dan
`docs/pertanyaan-lab-ph-dua-master.md`): TITS (pembagi U95), Enclosure (kolom
sebaran, baris Suhu Ruang), Thermocouple Type N/K (sel kosong dibaca nol, koreksi
Type K geser satu baris), dan pH (UTemperature ikut termometer standar sesi).

### C. Alur draf dan lembar kerja

Bukti utama: 436 test HTTP lulus (SQLite, 4 Okt) dari kelas yang dipetakan di
bawah, ditambah simulasi serentak `tests/Simulasi/SimulasiLabSerentakTest.php`
(MySQL lokal, 4 server + 1 pekerja): 14/14 skenario lulus 3 Okt.

| Langkah | Status | Bukti / celah |
|---|---|---|
| Buat sesi, simpan draf, lanjut, kirim | **SIAP** | `CalibrationTest::test_sesi_bisa_disimpen_sebagai_draft_dulu`, `ChaosSimpanLembarKerjaTest`, alur penuh 4 alat (`AlurPenuh*Test`, `RantaiTimbanganTeknisiSampaiSertifikatTest`) |
| Draf utuh sesudah aplikasi ditutup | **TIDAK TERVERIFIKASI** | Test mobile memulihkan draf **yang sudah tersimpan di server** (`lembar_kerja_pulihkan_draft_test.dart`). Isian yang belum pernah disimpan ke server sebelum aplikasi ditutup tidak punya test |
| Admin koreksi dengan alasan (nilai lama + baru) | **SIAP** | `KoreksiPembacaanAdminTest::test_angka_lama_dan_baru_tersimpan_berikut_alasannya`, `::test_koreksi_angka_tanpa_alasan_ditolak` |
| Kembalikan untuk revisi / tolak dengan alasan | **SIAP** | `CalibrationTest::test_nolak_sesi_wajib_pakai_catatan_revisi`. Bukti produksi: audit sesi `KAL/2026/08/0002` memuat baris penolakan (`status`, `catatan_revisi`, `reviewed_by`) |
| Setujui, terbitkan | **SIAP** | `CalibrationTest::test_admin_nyetujuin_sesi`, `ApproveDuaKaliSatuSertifikatTest`, simulasi S4–S7 |
| Koneksi putus + kirim ulang / kirim dua kali | **SIAP** | `CalibrationTest::test_retry_dengan_client_request_id_sama_cuma_bikin_1_sesi`, `ChaosSimpanLembarKerjaTest::test_kiriman_ganda_berbarengan_dijawab_replay_bukan_500`, simulasi S3 |
| Teknisi dan admin mengedit bersamaan | **TIDAK TERVERIFIKASI** | Tidak ada test. Yang ada cuma penguncian status (`AdminEditSesiTeknisiTest`) |
| Koma desimal | **TIDAK TERVERIFIKASI** di API | Mobile mengubah koma (`kondisi_lingkungan_pakai_koma_test.dart`); kiriman `"1,5"` mentah ke API tidak punya test |
| Sel kosong / satuan salah | **SIAP** | `TitikKosongTidakMenggeserTest`, `VarianSatuanWajibDitentukanTest` |
| Audit log koreksi/penolakan/persetujuan/penerbitan | **SIAP** sebagian | `AuditLogTest` (baris tidak bisa diubah/dihapus), `AdminEditSesiTeknisiTest::test_editan_admin_kecatat_di_audit_log`, `GerbangPengesahanTest::test_pengesahan_tercatat_di_jejak_audit`. Pembacaan lama yang diganti (koreksi admin & revisi sesudah dikembalikan) tersimpan sebagai `old_data`/`new_data` — lihat R1. **Celah:** perubahan centang standar tidak masuk `audit_logs` |
| Izin peran di server | **SIAP** | `RoleAccessTest` (termasuk `test_tanpa_token_semua_endpoint_data_nolak_401`), `PemisahanWewenangPersetujuanTest`, `GerbangPengesahanTest`, `SuperAdminAksesTest`, `RuteInternalMenolakRoleLainTest` |

### D. OCR

| Butir | Status | Bukti |
|---|---|---|
| Jalur cloud mati | **BELUM SIAP** (keputusan kebijakan) | `VISION_AKTIF` default `true` (`config/services.php:98`) dan tidak ada di `render.yaml`, jadi jalur cloud (`POST /raw-measurements/extract-from-photo`, `POST /dokumen/baca`) hidup begitu `GEMINI_API_KEY` terisi di dashboard. Pemakaian produksi sejauh ini: 0 (`worksheet_extraction_logs` kosong). Aplikasi mobile tidak memanggil rute ekstraksi foto |
| Jalur lokal tersedia | **SIAP** sebagian | ML Kit di perangkat + `POST /worksheet-scans`; server cuma menerima template ber-geometri terverifikasi: **6 dari 46** (pH, conductivity, chlorine, refractometer, spectrophotometer, turbidimeter) |
| Akurasi dasar (terang/redup/miring/buram) | **TIDAK TERVERIFIKASI** | Tidak ada set foto lembar contoh; OCR jalur lokal berjalan di HP, jadi tidak bisa diukur dari server malam ini |
| Hasil OCR hanya usulan | **SIAP** | Baris dari kamera lahir `is_verified=false`, `input_source` + `ocr_raw_text` tersimpan; `approve()` menolak 422 selama ada yang belum dikonfirmasi; konfirmasi mencatat `verified_by/verified_at` + audit. Test: `OcrMeasurementTest`, `VerificationTest`, `WorksheetScanTest` (lulus) |
| Kegagalan OCR tidak merusak draf | **SIAP** di backend, UI **TIDAK TERVERIFIKASI** | 503 (`dimatikan`/kunci kosong) tidak menyentuh sesi; mobile punya sebab gagal spesifik (`GagalPindai`). Tidak diuji lewat UI |

### E. Sertifikat

| Butir | Status | Bukti |
|---|---|---|
| Sertifikat dari data uji, kolom vs master | **SIAP** pada data contoh | `SertifikatCocokMasterTest` (22), `CertificateGenerationTest`, `CertificateSnapshotTest`, `BerkasPdfSertifikatTest`; simulasi S7/S10: 10 sertifikat terbit, PDF terunduh, QR verifikasi 200 |
| Pembulatan dan satuan | **SIAP** | `DesimalLayarSamaDenganSertifikatTest`, `FlowmeterSatuanSertifikatTest`, test sertifikat per alat |
| Versi metode tercantum | **BELUM SIAP** | `formula_version_id` tidak dicetak (lihat B) |
| Tanda tangan/persetujuan hanya peran berwenang | **SIAP** | `GerbangPengesahanTest`, `PemisahanWewenangPersetujuanTest`, `TandaTanganSertifikatTest`. **Penting:** `GERBANG_PENGESAHAN=false` di blueprint produksi, artinya menekan **"Setujui" di produksi langsung menerbitkan sertifikat bernomor resmi** `CAL/…` — tidak ada langkah sahkan super admin di antaranya |
| Terbit tidak bisa ditimpa, revisi dengan riwayat | **SIAP** | `CalibrationTest::test_sesi_yang_udah_disetujui_nggak_bisa_diubah_lagi`, `SesiDisetujuiTidakBisaDimundurkanTest`, `RevisiSertifikatTest` (11 kasus, nomor `-Rn`, batal wajib alasan) |

---

## 2. Bug dan risiko (urut tingkat bahaya)

| # | Temuan | Bahaya | Memblokir uji besok? |
|---|---|---|---|
| R1 | **Dikoreksi 5 Okt — versi awal keliru.** Versi pertama dokumen ini menulis bahwa revisi lewat `PUT` menghapus pembacaan lama tanpa jejak. Itu salah: `update()` memotret pembacaan lama sebelum ditimpa (`CalibrationController.php:657`), lalu `catatKoreksiPembacaan()` (`:817–841`) menulis baris `audit_logs` berisi `old_data`/`new_data` untuk koreksi admin (sesi `menunggu_approval`) **dan** revisi teknisi sesudah lembar dikembalikan (`perlu_revisi`, `:684–698`). Draf yang belum pernah dikirim sengaja tidak dijejak. Bukti test (lulus 5 Okt): `ChaosSimpanLembarKerjaTest::test_revisi_teknisi_sesudah_dikembalikan_menyimpan_angka_lama`, `::test_draft_yang_belum_pernah_disubmit_tidak_dijejak`, `KoreksiPembacaanAdminTest::test_angka_lama_dan_baru_tersimpan_berikut_alasannya`. **Yang tersisa:** perubahan centang standar (`simpanUsageCheck()` → `sync()`) tidak diaudit, jadi pergantian kalibrator yang dipakai sesi tidak meninggalkan jejak | Rendah–sedang (ketertelusuran standar) | Tidak |
| R2 | Sesi, sertifikat, pembacaan, permintaan, dan pengguna **tidak punya soft delete**. Data uji di produksi tidak bisa "dibersihkan lewat soft delete". Karena `GERBANG_PENGESAHAN=false`, satu tekan **"Setujui" di produksi langsung menerbitkan sertifikat bernomor resmi** `CAL/2026/10/…` | Tinggi (jejak nomor sertifikat diperiksa asesor) | **Diputuskan 5 Okt:** uji tidak dilakukan di produksi; tidak ada sertifikat diterbitkan di produksi |
| R3 | Jalur AI cloud hidup secara default (lihat D). Foto lembar kerja bisa terkirim ke Gemini, dan bila gagal ke OpenAI (`VISION_DRIVER_CADANGAN=openai`) | Sedang (privasi/kebijakan) | **Ya** untuk uji OCR cloud; jalur lokal tidak terpengaruh |
| R4 | Login empat peran butuh sandi yang belum disetel | Sedang | **Ya** — tanpa akun, uji tidak bisa mulai |
| R5 | Standar dobel dengan data bertentangan: Yokogawa 23P1005 (id 13 tertelusur LK-285-IDN, U 0,72 °C; id 45 tertelusur LK-202-IDN, U kosong) dan recorder C305B1470 (id 46 berlaku s/d 2027-09-18; id 62 s/d 2027-02-18) | Sedang (ketertelusuran) | Tidak; pilih satu baris saat uji |
| R6 | Sesi Oven produksi `KAL/2026/08/0002` tidak terhitung (dua kalibrator dicentang) | Rendah untuk uji | Tidak |
| R7 | Versi rumus tidak tercetak di sertifikat | Rendah–sedang | Tidak |
| R8 | Draf yang belum tersimpan ke server tidak terbukti selamat saat aplikasi ditutup | Sedang (UX lapangan) | Tidak — uji manual besok |
| R9 | Edit bersamaan teknisi/admin dan koma desimal di API tanpa test | Rendah | Tidak — uji manual besok |
| R10 | Log Render 24 jam tidak bisa diperiksa dari sini | Rendah | Tidak — periksa di dashboard Render |

Tidak ada perbaikan kode yang dikerjakan malam ini: R2 adalah keputusan rancangan,
dan sisa R1 (audit centang standar) dikerjakan sesudah uji di branch terpisah.

---

## 3. Skenario uji besok

### Persiapan (sebelum mulai)

1. **Putuskan R2 dulu.** Pilih salah satu:
   - (a) Uji di produksi **sampai "disetujui" hanya untuk satu sesi**, lalu sertifikatnya **dibatalkan resmi** dengan alasan "UJI SISTEM" (nomornya tetap tercatat beserta pembatalannya); atau
   - (b) Uji di produksi **berhenti sebelum persetujuan** (draf, kirim, koreksi, kembalikan, tolak), dan langkah terbit diuji di lingkungan lokal; atau
   - (c) Siapkan staging dulu (belum ada; `render.yaml` cuma punya satu layanan web).
2. **Akun (R4).** Salah satu: jalankan `ganti-sandi-akun.ps1` untuk empat akun yang ada, atau setujui pembuatan empat akun `UJI-` (satu per peran, sandi acak, dinonaktifkan sesudah uji). Pembuatan akun = tulis ke produksi, menunggu persetujuan.
3. **Data uji.** Buat lewat panel `/admin`, semua berawalan `UJI-`: pelanggan `UJI-PT Simulasi`, alat pH dengan nomor seri `UJI-PH-001`, alat Oven `UJI-OVEN-001`. Jangan memakai alat pelanggan asli.
4. **AI cloud (R3).** Sampai lab memutuskan, jangan menekan fitur baca dokumen/AI. Jalur pindai lokal boleh.
5. Pasang APK v1.0.652 di HP teknisi; panel admin di `https://sidik-calibration-api.onrender.com/admin`.

### Teknisi (HP)

| # | Langkah | Hasil yang diharapkan |
|---|---|---|
| T1 | Login (email atau ID pegawai) | Masuk ke beranda teknisi |
| T2 | Buka lembar pH untuk `UJI-PH-001`, isi identitas + standar, isi 2 titik, **Simpan draf** | Draf tersimpan, muncul di daftar Draf |
| T3a | Buka lembar baru, isi beberapa sel, **jangan simpan**. Tutup aplikasi sepenuhnya (geser dari daftar aplikasi terbuka), buka lagi | Menguji R8: catat apakah isian yang belum disimpan masih ada atau hilang. Ini yang belum pernah dibuktikan |
| T3b | Lanjutkan draf dari T2 (yang sudah disimpan), lalu tutup-buka aplikasi lagi | Isian draf yang sudah tersimpan tetap utuh |
| T4 | Isi titik ke-3 dengan koma desimal (`7,01`), kosongkan satu sel | Koma diterima sebagai desimal; sel kosong tidak menggeser titik |
| T5 | Kirim ganda: tekan **Kirim**, lalu putus koneksi **saat menunggu jawaban** (matikan WiFi HP begitu tombol ditekan), sambungkan lagi dan kirim ulang. Di API diuji juga dengan dua kiriman ber-`client_request_id` sama | Satu sesi saja di server; kiriman kedua dijawab sebagai ulangan (200), bukan sesi baru. Catatan: mode pesawat **sebelum** Kirim tidak menguji ini — kirimannya tidak pernah sampai ke server |
| T6 | Lembar Oven `UJI-OVEN-001`: centang **dua** kalibrator, tekan Kirim | Ditolak dengan pesan "Centang SATU kalibrator saja…" yang menyebut kedua kalibrator |
| T7 | Lepas satu centang, kirim | Terkirim; titik terhitung |
| T8 | Coba buka menu setujui (kalau ada) / panggil approve | Ditolak (403/422) |

### Admin (panel / HP)

| # | Langkah | Hasil yang diharapkan |
|---|---|---|
| A1 | Buka sesi pH dari T5 | Status menunggu approval; rincian U95 per titik tampil |
| A2 | Koreksi satu pembacaan **tanpa alasan** | Ditolak |
| A3 | Koreksi dengan alasan | Nilai lama + baru + alasan tercatat |
| A4 | Setujui sesi yang barusan dikoreksi sendiri | **Ditolak** (pemisahan wewenang) |
| A5 | Kembalikan untuk revisi dengan catatan | Status perlu revisi; teknisi melihat catatannya |
| A6 | Teknisi merevisi dan mengirim ulang; admin lain menolak dengan alasan | Status berubah, alasan tercatat |
| A7 | (Hanya bila R2 opsi a) Admin **lain** menyetujui satu sesi | Sertifikat terbit, PDF bisa diunduh, QR verifikasi publik menampilkan nomornya |
| A8 | (Lanjutan A7) Batalkan sertifikat dengan alasan "UJI SISTEM" | Status batal, alasan tercatat, nomor tetap ada di riwayat |
| A9 | Viewer login dan mencoba menulis apa pun | Cuma bisa membaca |

Catat untuk tiap langkah: waktu, akun, hasil, dan tangkapan layar bila berbeda dari
harapan.

---

## 4. Rencana cadangan

**Rollback kode.** Versi sebelum `12add55` adalah `fe76fcd`. `12add55` tidak
membawa migrasi, jadi rollback cukup di kode:
- Dashboard Render → layanan `sidik-calibration-api` → Deploys → pilih deploy `fe76fcd` → Rollback; atau
- `git revert -m 1 12add55` lalu push ke `main` lewat PR (CI + deploy ±35 menit).
- Verifikasi: `GET /api/health` → `deploy.versi` sesuai.

**Restore database** (menimpa SELURUH isi produksi — hanya dengan persetujuan
eksplisit pemilik, dan sesudah semua pengguna berhenti memakai aplikasi):
1. Backup ulang keadaan saat itu dulu (supaya restore bisa dibalik).
2. `mysql --host=<host Aiven> --port=<port> --user=<user> --ssl-mode=REQUIRED <nama db> < C:\cadangan-sidik\produksi-20261004-2033.sql` — berkasnya memuat `DROP TABLE IF EXISTS` per tabel.
3. Cek `GET /api/health` dan hitung baris tabel utama.
Prosedur ini sudah diuji ke DB lokal 4 Okt (lihat A).

**Membersihkan data uji.**
- Alat, pelanggan, standar: soft delete lewat panel `/admin` (resource Filament ada, model punya SoftDeletes).
- Order: **tidak ada resource Order di Filament.** Satu-satunya jalur hapus adalah `DELETE /api/orders/{order}`, dan itu dijawab 422 bila order sudah terkait sesi kalibrasi (`OrderController::destroy`). Order seperti itu diubah statusnya jadi "dibatalkan", tidak dihapus.
- Akun uji: ubah status jadi nonaktif (model `User` tanpa soft delete).
- Sesi, pembacaan, permintaan, sertifikat: **tidak ada soft delete** (R2). Pilihannya: dibiarkan dengan penanda `UJI-` (sesi) dan **dibatalkan resmi** (sertifikat), atau dihapus permanen lewat prosedur `docs/aturan-akses-database.md` butir 4 (rencana tertulis → cadangan terverifikasi → dry-run → instruksi terpisah).
- `audit_logs` tidak bisa dan tidak boleh dihapus.

---

## 5. Butuh keputusan manusia

| # | Keputusan | Pemilik keputusan |
|---|---|---|
| K1 | Opsi uji terbit sertifikat di produksi (R2: a/b/c) | Pemilik + manajer teknis |
| K2 | Akun uji: reset sandi akun yang ada atau buat empat akun `UJI-` | Pemilik |
| K3 | Kebijakan foto lembar kerja ke AI cloud (R3). Kalau dilarang: tambah `VISION_AKTIF=false` dan kosongkan `VISION_DRIVER_CADANGAN` di `render.yaml` (perlu deploy) | Lab |
| K4 | Standar dobel (R5): baris mana yang benar (sertifikat kalibrasi alat), lalu baris lain dinonaktifkan, bukan dihapus | Lab |
| K5 | Sesi Oven `KAL/2026/08/0002`: kalibrator mana yang sebenarnya dipakai; buka ulang lewat "kembalikan untuk revisi" | Penanggung jawab teknis |
| K6 | Sisa R1: perubahan centang standar ikut diaudit (dikerjakan sesudah uji, branch terpisah) | Manajer mutu |
| K7 | Versi rumus wajib tercetak di sertifikat? (R7) | Manajer teknis |
| K8 | Pertanyaan lab terbuka suhu & pH sebelum alat terkait dipakai untuk sertifikat | Lab |
| K9 | Menyalakan `GERBANG_PENGESAHAN` (super admin mengesahkan) atau tetap admin langsung terbit | Pemilik |

---

## 6. Empat butir yang menunggu (dikerjakan baca saja)

### 6.1 Standar dobel

Query SELECT ke produksi (tabel `standards`, `information_schema`, dan setiap
tabel ber-kolom `*standard_id`). Tabel `standards` tidak punya kolom "status aktif"
maupun "tanggal kalibrasi" — yang ada `berlaku_sampai` dan `deleted_at` (keempatnya
belum dihapus).

| id | Nama | Merk / model | S/N | Tertelusur ke | U | Berlaku s/d | Dibuat | Dirujuk |
|---|---|---|---|---|---|---|---|---|
| 13 | Termometer & Sensor Std. | Yokogawa CA 150 Handy Cal | 23P1005 | LK-285-IDN | 0,72 °C (k=2) | 2027-09-18 | 2026-08-21 | 0 baris |
| 45 | Temperature Calibrator Yokogawa CA 150 Handy Cal | Yokogawa CA 150 Handy Cal | 23P1005 | LK-202-IDN | kosong | 2027-09-18 | 2026-09-07 | 1 centang (sesi 13, 2026-09-28) |
| 46 | Temperature Recorder Graphtech GL840 | Graphtech GL840 | C305B1470 | LK-285-IDN | kosong | 2027-09-18 | 2026-09-07 | 0 baris |
| 62 | Temperature Recorder Graptech GL840 | Graptech GL840 | C305B1470 | LK-285-IDN | kosong | 2027-02-18 | 2026-09-18 | 0 baris |

Kolom yang merujuk `standards` (FK): `calibration_session_standard.standard_id`,
`calibration_sessions.standard_id`, `calibration_sessions.thermohygro_standard_id`,
`raw_measurements.standard_id`, `uncertainty_calculations.standard_id`,
`worksheet_scan_cells.standard_id`. Sertifikat yang merujuk: **0** untuk keempatnya.

Rekomendasi (belum dieksekusi):
- **Yokogawa:** jangan digabung sebelum lab memastikan dua hal: apakah 13 dan 45 alat yang sama dengan dua peran (termometer untuk pH, kalibrator untuk Enclosure), dan lab mana yang benar di sertifikatnya (LK-285 atau LK-202). Kalau satu alat: pertahankan **45** (dipakai sesi nyata, namanya cocok dengan pencocok lembar Enclosure), salin U 0,72 °C dan ketertelusuran yang benar ke situ, lalu nonaktifkan 13 — tapi periksa dulu profil pH yang mungkin membaca 13.
- **Recorder:** pertahankan **46** kalau sertifikatnya memang berlaku s/d 2027-09-18; nonaktifkan **62** (tidak dirujuk apa pun). Tanggal berlaku keduanya bertentangan, jadi cocokkan dengan sertifikat fisik dulu.

### 6.2 Sesi Oven `KAL/2026/08/0002`

- Status `menunggu_approval`, belum disetujui, **0** sertifikat, **0** hasil hitung, 220 pembacaan (180 termokopel, 20 indikator, 20 suhu ruang; 4 titik), tipe sensor Type K, `standard_id` sesi kosong.
- Centang "Dipakai": standar 44 (Constant, S/N 99875850) **dan** 45 (Yokogawa, S/N 23P1005).
- Riwayat audit (10 baris): dibuat oleh teknisi 2026-08-26 10:07 → dikirim → **ditolak admin** 2026-08-26 10:34 (`status`, `catatan_revisi`, `reviewed_by`) → disunting dan dikirim ulang oleh **akun admin** 2026-09-28 07:09–07:12 (dua kali). Kolom `reviewed_at` masih menyimpan waktu penolakan 26 Agt.
- Alur resmi membuka ulang: `POST /calibrations/{id}/reject` (admin, `catatan_revisi` wajib ≥5 karakter) → `perlu_revisi` → teknisi melepas satu centang dan mengirim lewat `PUT /calibrations/{id}`. Efek: status & alasan tercatat di `audit_logs`; pembacaan yang berubah dicatat lama+baru oleh `catatKoreksiPembacaan()` (status `perlu_revisi`); perubahan centang **tidak** diaudit (sisa R1). `tarik-pengajuan` tidak berlaku (khusus status menunggu pengesahan).
- Admin yang mengirim ulang 28 Sep **tidak bisa** menyetujui sesi ini (pemisahan wewenang).
- Backup baca-saja: `C:\cadangan-sidik\sesi-13-KAL-2026-08-0002-20261004-2039.json` (sesi, 220 pembacaan, centang, 10 audit, sertifikat). Ditambah backup penuh di atas.

### 6.3 Skrip `ganti-sandi-akun.ps1`

Tidak dijalankan. Isinya:
- **Sasaran:** database yang dikonfigurasi `.env` repo di laptop ini, yaitu **produksi** (`php artisan tinker` memakai koneksi default).
- **Akun:** 6 — akun super admin (email pemilik), `admin@sidik.test`, `teknisi@sidik.test`, `viewer@sidik.test`, dan dua teknisi lain. Dicocokkan lewat email + role (`firstOrFail`).
- **Sandi:** diketik pengguna (`Read-Host -AsSecureString`, tidak tampil), dioper ke tinker lewat variabel lingkungan proses, ditulis ke `users.password` (di-hash oleh cast `hashed`). Variabel dihapus di akhir.
- **Efek samping:** semua token login akun yang diubah dicabut (semua perangkat keluar). Sandi sempat ada di variabel lingkungan proses selama skrip berjalan.
- **Alternatif yang lebih aman:** belum ada staging. Pilihan terbaik: buat empat akun `UJI-` (satu per peran) dengan sandi acak yang dicetak sekali ke layar pemilik, tidak masuk repo, dan dinonaktifkan sesudah uji. Itu tetap tulis ke produksi — menunggu persetujuan (K2).

### 6.4 Foto lembar kerja ke AI cloud

- Tidak ada data yang dikirim ke layanan mana pun.
- Integrasi cloud: `WorksheetVisionExtractor` (Anthropic/Gemini/OpenAI) lewat `POST /raw-measurements/extract-from-photo`, dan `KlienVisi` lewat `POST /dokumen/baca`; peran admin & teknisi.
- Flag: `VISION_AKTIF` (default `true`), `VISION_DRIVER` (`gemini` di blueprint), `VISION_DRIVER_CADANGAN` (`openai` di blueprint). Dimatikan → kedua rute menjawab 503 `dimatikan` sebelum menyentuh layanan luar; jalur lokal tetap jalan.
- Kunci tertanam: pola kunci Google/Anthropic/OpenAI/GitHub/private key **tidak ditemukan** di berkas ter-track kedua repo maupun di riwayat git (`git log -G`); `.env` tidak ter-track. Apakah `GEMINI_API_KEY` terisi di dashboard Render: tidak terverifikasi (sengaja tidak dibaca).
- Usulan (default OFF, belum diterapkan): `render.yaml` tambah `VISION_AKTIF: "false"` dan kosongkan `VISION_DRIVER_CADANGAN`; opsional default kode `false`. Butuh deploy — tunggu keputusan lab (K3).
