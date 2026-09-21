# Insiden: approval "server nggak nyaut" & sertifikat macet — 21 Sep 2026

Dokumen serah-terima. Ditulis supaya sesi berikutnya **tidak mengulang
diagnosisnya dari nol**, dan tidak membalik lagi keputusan yang sudah dibalik
dua kali di sini.

## 1. Gejala yang dilaporkan

Dari pemilik proyek, tiga keluhan yang terdengar seperti tiga bug berbeda:

1. data yang sudah diisi nyangkut waktu dikirim ke admin;
2. approval gagal — "alasannya server gak nyaut lagi";
3. sertifikat tidak pernah terbit.

Nomor 2 dan 3 ternyata satu akar. Nomor 1 **belum terbukti** satu akar dengan
keduanya — lihat §7.

## 2. Bukti, bukan dugaan

Dibaca langsung dari produksi (baca-saja, agregat per status — nol data
pelanggan):

| Yang dihitung | Hasil |
|---|---|
| `calibration_sessions` | 32 `menunggu_approval`, 4 `disetujui`, 2 `perlu_revisi`, 1 `draft` |
| `certificates` | 16 `terbit`, **9 `menunggu_generate`** |
| `failed_jobs` | **0** |

Kesembilan baris `menunggu_generate` punya `snapshot` dan `validasi` **terisi**
tapi `pdf_path` **null**. Artinya prosesnya berhenti tepat sebelum PDF ditulis —
bukan gagal menghitung, bukan gagal validasi. Yang tertua sejak 15 Sep.

Ongkos rendernya diukur langsung pada satu sertifikat yang tersangkut:

```
{"durasi_detik":16.667,"ukuran_pdf_byte":1375673}
```

**16,7 detik** untuk satu PDF 1,37 MB.

## 3. Akar sebab

Render PDF dijalankan **di dalam request approve** (`$job->handle()`, bukan
`dispatch()`). Di CPU kecil Render, request-nya melewati batas waktu klien
mobile — dari HP itu terbaca "server nggak nyaut" — lalu prosesnya terputus
**sebelum sempat menandai sertifikatnya `gagal`**.

`failed_jobs` nol karena tidak pernah ada job di antrean: semuanya sinkron.

Yang membuatnya jadi jalan buntu, bukan sekadar lambat:
`CertificateController::retry()` hanya menerima sertifikat berstatus `gagal`.
Yang tersangkut di `menunggu_generate` ditolak **422**, jadi admin **tidak punya
tombol pemulihan sama sekali** — satu-satunya jalan keluar masuk database manual.

## 4. Yang diubah (commit `fa4bff2`)

| Berkas | Perubahan |
|---|---|
| `CalibrationController::approve()` | `$job->handle()` → `GenerateCertificate::dispatch()` |
| `CertificateController::retry()` | sama; + mewariskan `berlaku_sampai` (lihat §5) |
| `GenerateCertificate` | `$timeout = 600`, `$tries = 3`, `failed()` yang mengembalikan status ke `gagal` |
| `docker/entrypoint.sh` | worker dapat `--timeout=600` |
| `render.yaml` | `DB_QUEUE_RETRY_AFTER=660` (bawaan 90 detik — lebih pendek dari timeout job) |
| `SapuSertifikatTertunda` (baru) | `sertifikat:sapu-tertunda`, terjadwal tiap 10 menit |

`failed()` perlu ada karena timeout worker (SIGALRM) **tidak lewat `catch`** di
`handle()`. Tanpa dia, job yang kehabisan seluruh percobaan meninggalkan
sertifikat di `menunggu_generate` — persis keadaan yang sedang kita perbaiki.

## 5. Jebakan yang ditemukan sambil jalan

`updateOrCreate` di `GenerateCertificate::handle()` menulis **ULANG**
`issued_by`, `berlaku_sampai`, dan `diterbitkan_pada` tiap job jalan. Jadi
mendorong ulang job dengan argumen kosong berarti:

- `issued_by` jadi `null` → catatan siapa yang menyetujui **hilang**;
- `berlaku_sampai` dihitung ulang dari default organisasi → **menimpa tanggal
  yang dipilih admin** waktu approve, di dokumen terkendali yang akan dicetak
  dan dikirim ke pelanggan.

Tidak ada error yang muncul untuk keduanya. `retry()` sudah kena celah ini sejak
lama (baik versi sinkron maupun versi antrean) dan ikut diperbaiki. Penyapu
mewariskan dua-duanya dari baris sertifikatnya sendiri.

## 6. Kenapa keputusan sinkron dibalik LAGI — dan apa yang membuatnya sah

Ini penting, karena arahnya sudah bolak-balik:

- Dulu antrean → dibalik jadi **sinkron** (29 Jul 2026), karena tanpa
  `queue:work` yang hidup, approve sukses tapi sertifikat macet selamanya tanpa
  satu pun error.
- Sekarang balik ke **antrean**, karena render 16,7 detik di dalam request
  memutus approval sama sekali.

Kekhawatiran yang dulu **tidak salah** — 9 baris tersangkut membuktikan mode
macet itu nyata. Yang berubah bukan penilaiannya, tapi penutupnya:
`sertifikat:sapu-tertunda` mendorong ulang apa pun yang diam di
`menunggu_generate` tanpa PDF lebih dari 15 menit.

**Jangan balikkan ke sinkron lagi tanpa mengganti penyapunya dengan sesuatu
yang setara.** Kalau suatu saat penyapunya dihapus, mode macet itu kembali —
dan dia tidak akan memberi tahu siapa pun.

Ambang 15 menit dipilih di atas `$timeout = 600` (10 menit), supaya penyapu
tidak pernah mendorong ulang job yang sebenarnya masih merender.

## 7. Yang MASIH terbuka

- **Gejala "kirim ke admin nyangkut" belum terbukti** satu akar dengan approval.
  Belum diperiksa sampai selesai. Jangan diasumsikan ikut sembuh.
- **9 sertifikat produksi belum dipulihkan** saat dokumen ini ditulis.
  Pemulihannya lewat penyapu sesudah deploy naik — lalu **hitung ulang
  `certificates` per status untuk membuktikannya**, jangan diasumsikan.
- **Worker Render 512 MB.** Perbaikan ini menaruh dompdf di worker yang berbagi
  memori dengan web. Penyapu menutup kasus macet, **tidak** menutup OOM. Kalau
  ada job yang berulang masuk-keluar antrean, itu dugaan pertama.

## 8. Catatan alat (menghemat waktu sesi berikutnya)

- `php artisan tinker --execute` di Windows **menjalankan kodenya tapi tidak
  mengembalikan output**. Jangan dipakai untuk diagnosis. Yang jalan:
  `php --% -r "require 'vendor/autoload.php'; ..."`.
- Suite MySQL dulu menembak Aiven lewat internet. Sejak commit `7e45ff1`
  host-nya dipatok ke localhost — lihat §Kredensial di `phpunit.mysql.xml`.
- Jangan menjalankan dua suite sekaligus di satu checkout: `Storage::fake()`
  memakai satu direktori bersama dan saling menghapus.
