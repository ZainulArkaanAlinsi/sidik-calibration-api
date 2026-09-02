<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutoclaveController;
use App\Http\Controllers\Api\CalibrationController;
use App\Http\Controllers\Api\CalibrationMethodController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\DokumenGenerikController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\FolderFileController;
use App\Http\Controllers\Api\FormulaController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\KemampuanKalibrasiController;
use App\Http\Controllers\Api\LaporanController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StandardController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\VersiAplikasiController;
use App\Http\Controllers\Api\WorksheetExtractionController;
use App\Http\Controllers\Api\WorksheetScanController;
use App\Services\Direktori\DirektoriPerusahaan;
use App\Services\Direktori\PilihanDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Publik (tanpa auth)
|--------------------------------------------------------------------------
| Semuanya dibatesin per menit per IP — nahan brute force & spam.
*/

// Dipakai mobile buat mastiin sambungan ke API jalan.
Route::get('/health', fn (DirektoriPerusahaan $direktori) => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->utc()->toIso8601ZuluString(),

    // Setelan yang nggak bisa diperiksa dari luar tanpa login, padahal
    // pertanyaannya sering: "key-nya udah kebaca server belum?"
    //
    // Tanpa ini jawabannya cuma bisa didapat dengan membuka aplikasi, login
    // sebagai teknisi, dan menekan tombol cari — atau membuka dashboard
    // penyedia hosting. Dua-duanya butuh orang yang megang akunnya, dan itu
    // bikin pertanyaan sepele ("sudah nyampe belum?") jadi bolak-balik yang
    // panjang tiap kali setelannya diubah.
    //
    // Yang dilaporkan cuma ADA/TIDAK-nya, dan itu batas yang disengaja:
    //  - Nilainya sendiri nggak pernah ikut. Nggak juga panjangnya.
    //  - NOL request ke penyedia. `tersedia()` cuma membaca config, jadi
    //    endpoint publik ini nggak bisa dipakai orang buat menghabiskan kuota
    //    berbayar lab — itu yang bikin dia aman dibiarkan tanpa auth.
    //
    // `driver` & `bisa_ditagih` ditambah 2 Sep 2026, dan sebabnya nyata:
    // `disetel` SENDIRIAN nggak bisa menjawab pertanyaan yang paling mahal.
    // Dia `true` buat `osm` MAUPUN `auto` — yang pertama gratis, yang kedua
    // menembak Google duluan. Waktu bawaan direktori dipindah ke OSM, nilainya
    // sempat tertinggal di `auto` selama sehari dan nggak ada satu pun cara
    // memeriksanya dari luar; yang menemukan akhirnya tagihan Google Cloud,
    // bukan endpoint ini.
    //
    // Yang dilaporkan driver EFEKTIF, bukan isi `.env` apa adanya: `osmm` yang
    // salah ketik jatuh ke `osm`, dan yang membaca perlu tahu yang jalan yang
    // mana — bukan yang diketik. Lihat [PilihanDriver].
    //
    // Batasnya nggak berubah: nama driver itu STATUS, bukan rahasia. Key-nya
    // tetap nggak pernah ikut, dan tetap nol request ke penyedia.
    'direktori_perusahaan' => [
        'disetel' => $direktori->tersedia(),
        'driver' => PilihanDriver::sekarang(),
        'bisa_ditagih' => PilihanDriver::bisaDitagih(PilihanDriver::sekarang()),
    ],

    // Tiga pertanyaan yang selama ini cuma bisa dijawab dari dashboard
    // penyedia hosting — dan karena itu selalu jadi bolak-balik.
    //
    // Batasnya SAMA dengan `direktori_perusahaan` di atas: yang dilaporkan
    // status, bukan nilai. Nol request ke penyedia, nol rahasia, aman
    // dibiarkan tanpa auth.
    //
    // `versi` — commit yang BENERAN jalan di container ini. Sesudah deploy,
    // "udah naik belum?" cuma bisa dijawab dengan membuka dashboard Render dan
    // mencocokkan SHA-nya. Repo ini publik, jadi SHA-nya bukan rahasia; yang
    // dibeli: satu `curl` menjawab pertanyaan yang tadinya butuh akun.
    //
    // `arsip.awet` — false artinya berkas (PDF sertifikat, tanda tangan, kop)
    // tinggal di disk container yang KEHAPUS TIAP DEPLOY. PDF-nya masih bisa
    // dibangun ulang dari snapshot (lihat App\Services\BerkasPdfSertifikat),
    // tapi gambar tanda tangan & kop tidak punya sumber beku — sekali hilang,
    // hilang. Selama ini false, dan nggak ada yang bisa melihatnya dari luar.
    //
    // `seed_saat_boot` — true artinya seeder menulis ulang seluruh sesi contoh
    // TIAP container nyala. Dokumennya bilang "nyalain sekali pas deploy
    // pertama, terus matiin"; kalau tidak pernah dimatikan, tiap deploy
    // membayar ongkosnya lagi — menit yang diambil dari jendela health check
    // Render yang cuma 15 menit, dan itu tersangka utama deploy yang timeout.
    //
    // Ketiganya dibaca lewat `config()`, BUKAN `env()` langsung — lihat
    // config/deploy.php buat alasannya. Singkatnya: entrypoint memanggil
    // `config:cache` sebelum server nyala, dan sesudah itu `env()` di luar
    // berkas config berhenti membaca `.env`.
    'deploy' => [
        'versi' => config('deploy.versi'),
        'arsip' => ['awet' => config('filesystems.disks.arsip.driver') !== 'local'],
        'seed_saat_boot' => config('deploy.seed_saat_boot'),
    ],
]));

// Limiter-nya BERNAMA (didaftarin di AppServiceProvider), bukan `throttle:5,1`.
// Kalau pakai yang angka, semua endpoint publik berbagi satu jatah per IP —
// salah password beberapa kali bikin /forgot-password ikut kena 429.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

// Versi aplikasi mobile terbaru. TANPA auth, dan itu disengaja: layar yang
// paling butuh tahu "aplikasimu ketinggalan" justru layar LOGIN — dikunci
// token, teknisi yang aplikasinya terlalu lama nggak akan pernah lihat
// pemberitahuannya. Lihat docblock controllernya.
Route::get('/app/versi-terbaru', VersiAplikasiController::class)
    ->middleware('throttle:60,1');

// Verifikasi QR sertifikat — buat orang luar, tanpa auth (versi JSON-nya;
// versi halaman webnya ada di routes/web.php).
Route::get('/verify/{qr_token}', [VerificationController::class, 'show'])->middleware('throttle:30,1');

/*
|--------------------------------------------------------------------------
| Butuh login
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    // Izin pemanggil buat nyembunyiin tombol yang bakal ditolak (fase-2 §1).
    // Jawabannya diturunkan dari middleware `role:` di rute-rute di bawah, jadi
    // nggak bisa beda dari penjagaan yang beneran jalan. Lihat `MatriksIzin`.
    Route::get('/me/permissions', [AuthController::class, 'permissions']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Otorisasi channel privat buat realtime sync (Echo authEndpoint di mobile &
    // desktop → /api/broadcasting/auth, pakai token Sanctum). Lihat routes/channels.php.
    Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));
    // Token Sanctum nggak kadaluarsa sendiri — ini caranya matiin sesi di HP
    // yang ilang.
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    // Grafik tren rentang bebas (permintaan-endpoint.md §2). Angkanya dari service
    // yang SAMA dengan `grafik_pekerjaan` di `/dashboard`, biar dua grafik nggak
    // bisa beda buat bulan yang sama. Didaftarin SESUDAH `/dashboard` — beda path,
    // nggak bentrok.
    Route::get('/dashboard/tren', [DashboardController::class, 'tren']);

    // Notifikasi — ikonnya pindah ke atas layar & punya halaman sendiri
    // (spesifikasi poin 4). Semua role: tiap orang cuma dapat notifikasinya sendiri.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Perangkat yang boleh dikirimi push. Cuma nutup satu celah yang websocket
    // nggak bisa: HP dengan aplikasi ketutup total. `DELETE` dipanggil waktu
    // logout — HP yang dipakai gantian nggak boleh terus nerima notifikasi
    // kerja orang sebelumnya di layar kuncinya.
    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);

    // Folder Manager (spesifikasi poin 3 & 7). Bacanya semua role — menunya ada
    // di navbar bawah teknisi; isinya disaring per-role di controller.
    Route::get('/folders', [FolderController::class, 'index']);
    Route::get('/folders/{folder}', [FolderController::class, 'show']);
    // Alias `/arsip/*` yang dipanggil mobile (docs/permintaan-backend-2026-07-24.md §2).
    // Folder akar = per-PT, jadi daftar "perusahaan" = index tanpa parent_id.
    Route::get('/arsip/perusahaan', [FolderController::class, 'index']);
    // Tap PT → buka folder akarnya, dibikin kalau belum ada (find-or-create).
    // Inti Folder Manager: tanpa ini, PT yang belum pernah punya sertifikat
    // mentok 404 padahal PT-nya ada. Balikannya bentuk `show`.
    // {customer} = id PELANGGAN, bukan id folder — lihat kontrak-api.md §8.
    Route::get('/arsip/perusahaan/{customer}/folder', [FolderController::class, 'folderPelanggan']);
    Route::get('/arsip/folders/{folder}', [FolderController::class, 'show']);
    Route::get('/folder-files', [FolderFileController::class, 'index']);
    Route::get('/folder-files/{folderFile}/download', [FolderFileController::class, 'download'])
        ->name('folder-files.download');

    // Metode kalibrasi (IK) — bacanya semua role buat dropdown & tampilan detail.
    Route::get('/calibration-methods', [CalibrationMethodController::class, 'index']);
    Route::get('/calibration-methods/{calibrationMethod}', [CalibrationMethodController::class, 'show']);

    // Baca data alat & kategori: semua role, termasuk viewer.
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{kode}', [CategoryController::class, 'show']);
    Route::get('/equipments', [EquipmentController::class, 'index']);
    Route::get('/equipments/{equipment}', [EquipmentController::class, 'show']);

    // Dropdown pelanggan — SEMUA role, read-only, cuma id/nama/alamat.
    //
    // Kepisah dari `/customers` yang admin-only: `POST /equipments` boleh dipakai
    // teknisi dan `pelanggan_id` itu wajib, jadi tanpa ini form Tambah Alat mentok
    // total di akun teknisi (dropdown 403 → alat nggak bisa disimpen).
    //
    // WAJIB didaftarin SEBELUM blok `role:admin` di bawah, biar "lookup" nggak
    // kebaca sebagai `{customer}` di `GET /customers/{customer}`.
    Route::get('/customers/lookup', [CustomerController::class, 'lookup']);

    // Standar acuan milik lab — buat dropdown "Standar Acuan" di layar kalibrasi.
    Route::get('/standards', [StandardController::class, 'index']);
    Route::get('/standards/{standard}', [StandardController::class, 'show']);

    // Ruangan lab: bacanya semua role — teknisi butuh buat dropdown "Ruangan"
    // waktu ngisi sesi. Nulisnya admin doang, di blok bawah.
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::get('/rooms/{room}', [RoomController::class, 'show']);

    // Laporan kalibrasi berpenyaring + export (spesifikasi poin 08, fase-2 §5).
    // Semua role boleh; teknisi cuma dapat pekerjaannya sendiri (di service).
    // `/export` didaftarin SEBELUM yang tanpa suffix biar urutannya jelas.
    Route::get('/laporan/kalibrasi/export', [LaporanController::class, 'export'])
        // Bikin file (PDF/Excel) dari sampai 5000 baris — jauh lebih berat dari
        // baca biasa, jadi jatahnya dipisah & lebih sedikit.
        ->middleware('throttle:20,1');
    Route::get('/laporan/kalibrasi', [LaporanController::class, 'kalibrasi']);

    // Bentuk baku lembar kerja (SIDIK-FM-CAL-0509_Rev.4) buat layar input
    // teknisi. Didaftarin SEBELUM `/calibrations/{calibration}` biar
    // "lembar-kerja" nggak kebaca sebagai id sesi.
    Route::get('/calibrations/lembar-kerja', [CalibrationController::class, 'lembarKerja']);

    // Order kalibrasi: bacanya semua role — teknisi butuh lihat alat apa aja
    // yang masuk dan harus dikerjain. Nulisnya admin doang (meja penerimaan),
    // di blok bawah.
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    // Baca sesi kalibrasi: semua role — tapi teknisi cuma dapat sesi miliknya
    // sendiri. Penyaringnya di controller, bukan di query param dari mobile.
    Route::get('/calibrations', [CalibrationController::class, 'index']);
    Route::get('/calibrations/{calibration}', [CalibrationController::class, 'show']);

    // Sertifikat terbit: semua role bisa lihat & unduh — teknisi cuma miliknya
    // sendiri (scope di controller). PDF-nya di disk privat, cuma bisa lewat sini.
    Route::get('/certificates', [CertificateController::class, 'index']);
    // Rekap banyak sertifikat — didaftarin SEBELUM `/certificates/{certificate}`
    // biar "export" nggak kebaca sebagai id sertifikat.
    Route::get('/certificates/export/excel', [CertificateController::class, 'exportRekap'])
        ->middleware('role:admin');
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show']);
    Route::get('/certificates/{certificate}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');
    // Export Excel & gambar QR per sertifikat (spesifikasi poin 10 & 13).
    Route::get('/certificates/{certificate}/excel', [CertificateController::class, 'exportExcel'])
        ->name('certificates.excel');
    Route::get('/certificates/{certificate}/qr', [CertificateController::class, 'qr'])
        ->name('certificates.qr');

    // Nulis data alat & sesi kalibrasi: admin & teknisi. Viewer ditolak 403.
    Route::middleware('role:admin,teknisi')->group(function () {
        Route::post('/equipments', [EquipmentController::class, 'store']);
        Route::put('/equipments/{equipment}', [EquipmentController::class, 'update']);
        Route::delete('/equipments/{equipment}', [EquipmentController::class, 'destroy']);

        // Nama alat baru buat master kemampuan kalibrasi, langsung dari HP.
        //
        // Ditaruh di blok `role:admin,teknisi` (bukan `role:admin` bareng master
        // data lain) karena itu SELURUH gunanya: teknisi yang ketemu alat yang
        // namanya belum kedaftar harus bisa lanjut kerja tanpa nunggu admin.
        // Yang dibikin cuma NAMA-nya — angka CMC nggak bisa diisi lewat sini
        // sama sekali (lihat `KemampuanKalibrasiRequest`), jadi teknisi nggak
        // bisa, sengaja atau nggak, ngubah angka yang kecetak di sertifikat
        // terakreditasi.
        Route::post('/categories/{kode}/kemampuan', [KemampuanKalibrasiController::class, 'store']);

        // PT baru dari lapangan, alasannya persis sama dengan rute di atas:
        // `pelanggan_id` itu WAJIB di `POST /equipments`, jadi pelanggan yang
        // belum kedaftar bikin kerjaan teknisi berhenti total sampai ada admin
        // yang buka laptop. Yang bisa diisi lewat sini cuma nama & alamat —
        // kontak & seluruh pengelolaan pelanggan tetap di `role:admin`.
        Route::post('/customers/cepat', [CustomerController::class, 'cepat']);

        // Cari nama & alamat PT di direktori LUAR, buat ngisi rute di atas.
        //
        // Di-throttle karena endpoint di baliknya ditagih PER REQUEST ke pihak
        // ketiga. Batas ini penjaga terakhir, bukan yang utama — yang utama
        // jeda ketik di sisi HP dan kuota di konsol penyedianya. Tapi klien yang
        // salah tulis (atau APK lama yang nggak punya jeda) bisa menghabiskan
        // tagihan lab dalam hitungan menit, dan itu nggak boleh cuma dijaga di
        // sisi yang nggak kita kendalikan.
        Route::get('/customers/direktori', [CustomerController::class, 'direktori'])
            ->middleware('throttle:direktori-luar');

        Route::post('/calibrations', [CalibrationController::class, 'store']);
        // Hitung tanpa nyimpen — "hitung sambil ngetik" di lembar kerja
        // (docs/permintaan-worksheet-ph.md §4). Body sama persis kayak POST
        // /calibrations. Throttle-nya longgar karena ini dipanggil tiap teknisi
        // selesai ngisi satu baris, bukan sekali per sesi — tapi tetap ada
        // batasnya, soalnya tiap panggilan mutar perhitungan GUM penuh.
        Route::post('/calibrations/preview', [CalibrationController::class, 'preview'])
            ->middleware('throttle:120,1');
        // Olah data Autoklaf (bentuk data beda — 3 disk suhu + 1 titik tekanan).
        // Tabel kalibrator & CMC dari server; body cuma data ukur teknisi.
        Route::post('/calibrations/autoclave/preview', [AutoclaveController::class, 'preview'])
            ->middleware('throttle:120,1');
        // Simpan sesi Autoklaf (snapshot hasil di kolom JSON, bukan titik ukur).
        // Masuk riwayat/approval yang sama kayak alat lain.
        Route::post('/calibrations/autoclave', [CalibrationController::class, 'simpanAutoclave']);
        // Buat ngerjain ulang sesi yang ditolak admin, atau nerusin draft.
        Route::put('/calibrations/{calibration}', [CalibrationController::class, 'update']);

        // Upload foto display alat → balikin photo_path.
        Route::post('/calibrations/photos', [CalibrationController::class, 'uploadPhoto']);

        // AI Vision: foto tabel lembar kerja → { baris: [...] } + skor keyakinan
        // per sel. Hasilnya buat dikonfirmasi teknisi, BUKAN langsung disimpen —
        // submit final tetap lewat POST/PUT /calibrations.
        //
        // JALUR CADANGAN, bukan jalur utama lagi. Aplikasi mobile pindah ke
        // pindai lokal di bawah dan nggak pernah manggil endpoint ini lagi.
        // Dimatikan lewat `VISION_AKTIF=false` — dia mengirim foto lembar kerja
        // pelanggan ke layanan pihak ketiga, jadi lab harus bisa menutupnya
        // tanpa nunggu rilis. Path ngikut SPEC-vision-prompt.md §8.
        Route::post('/raw-measurements/extract-from-photo', [WorksheetExtractionController::class, 'extract'])
            ->middleware('throttle:30,1');

        // OCR TEMPLATE LOKAL — jalur pindai UTAMA. Tanpa AI pihak ketiga & tanpa
        // biaya per foto: ANGKANYA dibaca di HP, yang nyampe sini teks per sel +
        // mutu foto + geometri, plus citra lembar yang udah diratakan (dipakai
        // layar koreksi buat nampilin tulisan aslinya). Jadi citranya memang
        // keluar HP — retensinya `config/ocr.php`. Sama kayak jalur AI Vision, hasilnya usulan: submit
        // final tetap lewat POST/PUT /calibrations.
        Route::get('/worksheet-templates', [WorksheetScanController::class, 'templates']);
        Route::get('/worksheet-templates/{kode}', [WorksheetScanController::class, 'template']);
        // Throttle lebih longgar dari AI Vision: nggak ada biaya per panggilan,
        // dan teknisi wajar ngulang motret beberapa kali sampai lembarnya kebaca.
        Route::post('/worksheet-scans', [WorksheetScanController::class, 'store'])
            ->middleware('throttle:60,1');
        Route::get('/worksheet-scans/{worksheetScan}', [WorksheetScanController::class, 'show']);
        // Potongan citra per sel — dipanggil sekali per sel yang dicek teknisi,
        // jadi batasnya jauh lebih tinggi dari endpoint lain.
        Route::get('/worksheet-scans/{worksheetScan}/sel/{kunci}/crop', [WorksheetScanController::class, 'crop'])
            ->middleware('throttle:300,1')
            ->where('kunci', '[A-Za-z0-9_|\-\.]+');
        Route::post('/worksheet-scans/{worksheetScan}/koreksi', [WorksheetScanController::class, 'koreksi']);

        // BACA DOKUMEN GENERIK — lembar APA PUN, termasuk yang belum punya
        // profil dan geometri. Jawaban buat lembar baru, biar jawabannya bukan
        // "template nggak dikenal".
        //
        // Nempel di saklar `VISION_AKTIF` yang SAMA dengan AI Vision di atas,
        // dan itu disengaja: endpoint ini mengirim SELURUH HALAMAN ke layanan
        // pihak ketiga — lebih luas dari jalur AI Vision yang cuma mengirim
        // foto tabel. Saklar kedua berarti lab yang sudah menutup pengiriman
        // foto tetap mengirim lewat sini tanpa sadar.
        //
        // Throttle seketat AI Vision: ada biaya per panggilan, dan gambarnya
        // lebih besar.
        Route::post('/dokumen/baca', [DokumenGenerikController::class, 'baca'])
            ->middleware('throttle:30,1');
        // Buka ulang & koreksi hasil baca. Dua-duanya nggak manggil AI, jadi
        // batasnya jauh lebih longgar dari `baca` — layar review wajar dibuka
        // berkali-kali sambil teknisi mencocokkan angka sama kertasnya.
        Route::get('/dokumen/bacaan/{dokumenBacaan}', [DokumenGenerikController::class, 'show'])
            ->middleware('throttle:120,1');
        Route::post('/dokumen/bacaan/{dokumenBacaan}/koreksi', [DokumenGenerikController::class, 'koreksi'])
            ->middleware('throttle:120,1');

        // Konfirmasi pembacaan hasil pindai (is_verified) — syarat sebelum approve.
        Route::post(
            '/calibrations/{calibration}/measurements/verify',
            [CalibrationController::class, 'verifyMeasurements'],
        );
    });

    // Approval kalibrasi & master data: admin doang.
    Route::middleware('role:admin')->group(function () {
        // Sesi FAIL tetap boleh di-approve — sertifikatnya terbit dengan hasil
        // "tidak laik pakai". Yang beda keputusannya, bukan boleh/nggaknya terbit.
        Route::post('/calibrations/{calibration}/approve', [CalibrationController::class, 'approve']);
        Route::post('/calibrations/{calibration}/reject', [CalibrationController::class, 'reject']);

        // Hitung ulang & periksa tanpa nyetujuin (spesifikasi poin 11) — buat
        // tombol "Periksa" sebelum admin mutusin.
        Route::get('/calibrations/{calibration}/validasi', [CalibrationController::class, 'validasi']);

        // Lembar PERHITUNGAN: tampilan admin dari lembar kerja teknisi.
        Route::get('/calibrations/{calibration}/perhitungan', [CalibrationController::class, 'perhitungan']);

        // Field administratif sertifikat (Order Number, IK, thermohygro, dst) —
        // dihapus dari layar teknisi, diisi di sini (spesifikasi poin 1 & 12A).
        Route::patch('/calibrations/{calibration}/admin', [CalibrationController::class, 'updateAdminFields']);

        // Terbitin ulang sertifikat yang generate-nya gagal. Penerbitan = admin,
        // sejalan sama approve. Ini yang nyalain tombol retry di mobile.
        Route::post('/certificates/{certificate}/retry', [CertificateController::class, 'retry']);
        // Kirim sertifikat ke email pelanggan (fase-2 §3d). Di backend, bukan
        // mobile, karena dua hal: alamat pengirim harus domain lab, dan
        // pengirimannya wajib tercatat buat audit. Throttle-nya ketat — ini ngirim
        // dokumen resmi ke luar, bukan baca data.
        Route::post('/certificates/{certificate}/kirim-email', [CertificateController::class, 'kirimEmail'])
            ->middleware('throttle:20,1');
        // Catat pengiriman lewat WhatsApp. Pesannya sendiri dikirim dari HP
        // admin (buka WhatsApp lewat `wa.me`), BUKAN dari server — makanya
        // endpoint-nya cuma nyatet, nggak ngirim.
        //
        // Kenapa tetap dicatat padahal servernya nggak ngirim: pertanyaan yang
        // muncul waktu pelanggan ngaku nggak nerima itu "kapan dikirim, ke
        // nomor mana, sama siapa" — dan itu nggak bisa dijawab kalau jejaknya
        // cuma ada di HP satu orang.
        Route::post('/certificates/{certificate}/catat-whatsapp', [CertificateController::class, 'catatWhatsapp'])
            ->middleware('throttle:20,1');
        Route::get('/certificates/{certificate}/riwayat-email', [CertificateController::class, 'riwayatEmail']);

        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::put('/organization', [OrganizationController::class, 'update']);
        // Logo yang dicetak di kop sertifikat (fase-2 §3a). Multipart, field
        // `logo`. Disimpen di disk publik — GenerateCertificate udah baca dari
        // situ, dan logo PT itu identitas yang memang dipajang.
        Route::post('/organization/logo', [OrganizationController::class, 'uploadLogo']);
        Route::delete('/organization/logo', [OrganizationController::class, 'deleteLogo']);

        // Tanda tangan penanda tangan sertifikat (fase-2 §3c). Admin doang —
        // teknisi & viewer nggak boleh nyentuh sama sekali.
        //
        // File-nya di disk PRIVAT (beda dari logo): gambar tanda tangan yang URL-nya
        // bisa diakses siapa pun berarti siapa pun bisa nempelin ke dokumen palsu.
        // Makanya pratinjaunya lewat endpoint yang ngecek hak akses, bukan URL storage.
        Route::post('/organization/tanda-tangan', [OrganizationController::class, 'uploadTandaTangan']);
        Route::get('/organization/tanda-tangan', [OrganizationController::class, 'previewTandaTangan']);
        Route::delete('/organization/tanda-tangan', [OrganizationController::class, 'deleteTandaTangan']);
        // Posisi & ukuran cetaknya — ini yang bakal dipakai UI drag-and-drop.
        // Disimpen SEKALI di tingkat template, bukan per sertifikat: sertifikat yang
        // udah terbit itu dokumen terkendali dan nggak boleh bisa diedit.
        Route::patch('/organization/tanda-tangan/posisi', [OrganizationController::class, 'updatePosisiTandaTangan']);

        // Pemicu MANUAL pengingat jatuh tempo (spec poin 6). Otomatisnya jalan
        // tiap pagi lewat scheduler (routes/console.php). Ambang H- diatur di
        // organization.settings.reminder_hari_sebelum (default 30 hari).
        Route::post('/reminders/jatuh-tempo', [ReminderController::class, 'jatuhTempo']);

        // Standar acuan: bacanya semua role (di atas), nulisnya admin doang —
        // salah ngetik ketidakpastian di sini bikin SEMUA sertifikat yang pakai
        // standar itu ikut salah.
        Route::post('/standards', [StandardController::class, 'store']);
        Route::put('/standards/{standard}', [StandardController::class, 'update']);
        Route::delete('/standards/{standard}', [StandardController::class, 'destroy']);

        Route::apiResource('customers', CustomerController::class)->except(['show']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);

        // Ruangan: bacanya udah didaftarin di atas buat semua role, di sini
        // tinggal yang nulis.
        Route::apiResource('rooms', RoomController::class)->only(['store', 'update', 'destroy']);

        // Order: bacanya udah didaftarin di atas buat semua role, di sini
        // tinggal yang nulis — pencatatan alat masuk itu kerjaan meja depan.
        Route::apiResource('orders', OrderController::class)->only(['store', 'update', 'destroy']);
        // Bagi-bagi kerjaan ke teknisi tanpa ngirim ulang seluruh order —
        // aksinya sering, payload `PUT` lengkap kemahalan buat ini.
        Route::post('/orders/{order}/penugasan', [OrderController::class, 'penugasan']);

        // Master data teknisi. Beda sama /users yang ngurusin approval akun:
        // yang ini khusus akun role `teknisi` dan bawa jumlah kalibrasinya,
        // buat layar "Data Teknisi" di mobile.
        Route::apiResource('technicians', TechnicianController::class);

        // Master data Instruksi Kerja — "Calibration Method" di sertifikat.
        Route::post('/calibration-methods', [CalibrationMethodController::class, 'store']);
        Route::put('/calibration-methods/{calibrationMethod}', [CalibrationMethodController::class, 'update']);
        Route::delete('/calibration-methods/{calibrationMethod}', [CalibrationMethodController::class, 'destroy']);

        // Folder Manager: yang nulis admin doang. Bacanya udah didaftarin di atas.
        Route::post('/folders', [FolderController::class, 'store']);
        Route::put('/folders/{folder}', [FolderController::class, 'update']);
        Route::delete('/folders/{folder}', [FolderController::class, 'destroy']);
        // Alias `/arsip/*` (docs/permintaan-backend-2026-07-24.md §2) — handler sama.
        //
        // `store` sempat kelewat di sini sementara `update` & `destroy` didaftar.
        // Akibatnya tombol "bikin folder" di layar Arsip mobile
        // (`ApiArsipService.bikinFolder` nembak `POST /api/arsip/folders`) balik
        // 404 — satu-satunya cara nambah folder arsip mati, sementara ganti nama
        // dan hapus jalan normal. Ketimpangan itu yang bikin gejalanya kebaca
        // kayak bug mobile, bukan rute yang hilang.
        Route::post('/arsip/folders', [FolderController::class, 'store']);
        Route::put('/arsip/folders/{folder}', [FolderController::class, 'update']);
        Route::delete('/arsip/folders/{folder}', [FolderController::class, 'destroy']);
        // Pindahin folder ke induk lain. Kepisah dari `update()` yang cuma rename:
        // yang ini nyentuh struktur pohonnya, dan struktur yang rusak bikin folder
        // ilang dari layar tanpa kehapus. Folder sistem ditolak — lihat handler-nya.
        Route::put('/arsip/folders/{folder}/pindah', [FolderController::class, 'pindah']);
        // Pindahin berkas sertifikat, dikunci pakai id SESI KALIBRASI (bukan id
        // folder_files) — itu yang dipegang mobile di layar arsip.
        Route::put('/arsip/berkas/{calibration}/pindah', [FolderFileController::class, 'pindahBerkasSesi']);
        Route::post('/folder-files', [FolderFileController::class, 'store']);
        Route::put('/folder-files/{folderFile}', [FolderFileController::class, 'update']);
        Route::delete('/folder-files/{folderFile}', [FolderFileController::class, 'destroy']);

        // Import Excel buat masa transisi (spesifikasi poin 12C).
        Route::get('/imports/format', [ImportController::class, 'format']);
        Route::post('/imports/excel', [ImportController::class, 'excel']);

        // Rumus kalibrasi berversi (Keputusan 5). Admin doang — salah ngetik di
        // sini ngubah angka yang masuk sertifikat terakreditasi.
        Route::get('/formulas', [FormulaController::class, 'index']);
        Route::get('/formulas/{formula}/versions', [FormulaController::class, 'versions']);
        // "Sesi tanggal 26 Mei dihitung pakai aturan yang mana?" — pertanyaan yang
        // bikin fitur ini ada, dan beda dari "aturan apa yang dipakai sekarang".
        Route::get('/formulas/{formula}/versi-berlaku', [FormulaController::class, 'versiPadaTanggal']);
        // Terbitin versi baru = bikin versinya + tutup rentang versi sebelumnya,
        // dalam SATU transaksi. Kalau dipisah, ada jeda di mana dua versi
        // sama-sama berlaku buat satu tanggal.
        Route::post('/formulas/{formula}/versions', [FormulaController::class, 'storeVersion']);
        Route::patch('/formula-versions/{formulaVersion}', [FormulaController::class, 'updateVersion']);

        // Riwayat perubahan data (Keputusan 4) — baca-saja, admin doang.
        // Nggak ada POST/PUT/DELETE: baris audit lahir dari perubahan datanya
        // sendiri (trait `Diaudit`), bukan dari request. Riwayat yang bisa ditulis
        // tangan berhenti jadi bukti.
        // `/export` didaftarin SEBELUM yang tanpa suffix biar urutannya jelas.
        Route::get('/audit-logs/export', [AuditLogController::class, 'export'])
            ->middleware('throttle:20,1');
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::get('/users', [UserController::class, 'index']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/approve', [UserController::class, 'approve']);
        Route::post('/users/{user}/reject', [UserController::class, 'reject']);
        // Buat kasus yang /forgot-password nggak bisa tolong: emailnya salah ketik.
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    });
});
