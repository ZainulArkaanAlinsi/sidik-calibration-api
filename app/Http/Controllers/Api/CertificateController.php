<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificate;
use App\Mail\SertifikatKePelanggan;
use App\Models\Certificate;
use App\Models\CertificateEmailLog;
use App\Models\User;
use App\Services\BerkasPdfSertifikat;
use App\Services\CertificateExcelExporter;
use App\Services\QrCodeGenerator;
use App\Support\Mailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sertifikat yang udah terbit dari sesi kalibrasi yang disetujui.
 *
 * PDF-nya disimpen di disk `local` (privat) — nggak bisa diakses langsung lewat
 * URL storage. Satu-satunya jalan ngambilnya buat orang dalam ya lewat sini,
 * dengan auth + scope organisasi. (Yang publik cuma verifikasi QR, bukan PDF-nya.)
 */
class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateExcelExporter $excel,
        private readonly QrCodeGenerator $qr,
    ) {}

    /** Relasi yang dibutuhin CertificateResource. */
    // `customer` ikut karena resource-nya nampilin kontak pelanggan (email &
    // telepon) buat tombol kirim. Tanpa dimuat di sini, daftar sertifikat jadi
    // satu query tambahan per baris.
    private const RELASI = ['session.equipment.customer'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $sertifikat = Certificate::query()
            ->with(self::RELASI)
            ->where('organization_id', $user->organization_id)
            // Teknisi SELALU cuma lihat sertifikat dari sesi miliknya sendiri —
            // sama polanya kayak di daftar sesi kalibrasi. `mine` cuma berpengaruh
            // buat admin/viewer.
            ->when(
                $user->role === User::ROLE_TEKNISI || $request->boolean('mine'),
                fn ($query) => $query->whereHas('session', fn ($q) => $q->where('teknisi_id', $user->id)),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return CertificateResource::collection($sertifikat);
    }

    /**
     * Isi sertifikat lengkap — dari snapshot yang dibekukan waktu terbit.
     * Dipakai layar pratinjau sertifikat sebelum diunduh/dikirim.
     */
    public function show(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanBolehLihat($request, $certificate);

        return response()->json([
            'data' => [
                ...(new CertificateResource($certificate->load(self::RELASI)))->toArray($request),
                'snapshot' => $certificate->snapshot,
                // Hasil pemeriksaan hitung ulang waktu terbit (spesifikasi poin
                // 11) — biar admin bisa lihat sertifikat mana yang dulu terbit
                // dengan peringatan.
                'validasi' => $certificate->validasi,
            ],
        ]);
    }

    /** Kirim file PDF-nya. Streamed — file bisa gede, jangan ditahan di memori. */
    public function download(
        Request $request,
        Certificate $certificate,
        BerkasPdfSertifikat $berkas,
    ): StreamedResponse {
        $this->pastikanBolehLihat($request, $certificate);

        abort_unless(
            $certificate->status === Certificate::STATUS_TERBIT && $certificate->pdf_path,
            404,
            'Sertifikat ini belum punya PDF yang bisa diunduh.',
        );

        // Baris di DB bilang terbit tapi file-nya raib — kehapus manual, atau
        // (yang jauh lebih sering) kebuang deploy karena disk arsip Render itu
        // sementara. Dibangun ulang dari snapshot alih-alih 404.
        $path = $berkas->pastikanAda($certificate);

        abort_unless($path !== null, 404);

        return Storage::disk('arsip')->download($path, $certificate->namaFile('pdf'));
    }

    /**
     * Export satu sertifikat ke Excel (spesifikasi poin 10).
     *
     * File-nya dibikin ON DEMAND, bukan disimpan waktu terbit: isinya turunan
     * dari snapshot yang udah beku, jadi hasilnya selalu sama — nyimpen file
     * kedua cuma nambah barang yang bisa basi & nggak sinkron.
     */
    public function exportExcel(Request $request, Certificate $certificate): BinaryFileResponse
    {
        $this->pastikanBolehLihat($request, $certificate);

        abort_unless(
            $certificate->status === Certificate::STATUS_TERBIT && filled($certificate->snapshot),
            404,
            'Sertifikat ini belum terbit, jadi belum ada isinya buat diexport.',
        );

        $tmp = tempnam(sys_get_temp_dir(), 'sertifikat-').'.xlsx';
        $this->excel->satu($certificate, $tmp);

        return response()->download($tmp, $certificate->namaFile('xlsx'))->deleteFileAfterSend();
    }

    /**
     * Rekap banyak sertifikat sekaligus — bulanan atau per PT (spesifikasi
     * poin 10). Admin doang: isinya lintas pelanggan.
     */
    public function exportRekap(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'bulan' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'dari' => ['sometimes', 'nullable', 'date'],
            'sampai' => ['sometimes', 'nullable', 'date', 'after_or_equal:dari'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $query = Certificate::query()
            ->with(['session.equipment.customer'])
            ->where('organization_id', $request->user()->organization_id)
            ->where('status', Certificate::STATUS_TERBIT)
            ->when(
                isset($data['bulan']),
                fn ($q) => $q->whereYear('diterbitkan_pada', substr($data['bulan'], 0, 4))
                    ->whereMonth('diterbitkan_pada', substr($data['bulan'], 5, 2)),
            )
            ->when(isset($data['dari']), fn ($q) => $q->whereDate('diterbitkan_pada', '>=', $data['dari']))
            ->when(isset($data['sampai']), fn ($q) => $q->whereDate('diterbitkan_pada', '<=', $data['sampai']))
            ->when(
                isset($data['customer_id']),
                fn ($q) => $q->whereHas(
                    'session.equipment',
                    fn ($e) => $e->where('customer_id', $data['customer_id']),
                ),
            )
            ->orderBy('diterbitkan_pada')
            ->orderBy('nomor');

        $sertifikat = $query->get();

        abort_if($sertifikat->isEmpty(), 404, 'Nggak ada sertifikat yang cocok sama penyaring itu.');

        $tmp = tempnam(sys_get_temp_dir(), 'rekap-sertifikat-').'.xlsx';
        $this->excel->rekap($sertifikat, $tmp);

        $namaFile = 'Rekap-Sertifikat-'.($data['bulan'] ?? now()->format('Y-m-d')).'.xlsx';

        return response()->download($tmp, $namaFile)->deleteFileAfterSend();
    }

    /**
     * Gambar QR sertifikat sebagai PNG (spesifikasi poin 13). Dipakai mobile &
     * panel buat nampilin/ngeprint ulang QR-nya di luar PDF.
     */
    public function qr(Request $request, Certificate $certificate): Response
    {
        $this->pastikanBolehLihat($request, $certificate);

        $png = $this->qr->png((string) ($certificate->qr_payload ?? url("/verify/{$certificate->qr_token}")));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // Isinya cuma turunan dari qr_token yang nggak pernah berubah —
            // aman di-cache lama di sisi klien.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Coba terbitin ulang sertifikat yang generate-nya gagal. Admin doang
     * (dijaga `role:admin` di routes) — ini tindakan penerbitan, sejalan sama approve.
     *
     * Job-nya idempoten & pakai updateOrCreate di baris sesi yang sama, jadi aman
     * dipanggil ulang.
     *
     * Dikerjain LANGSUNG, bukan lewat antrean — alasannya sama persis kayak di
     * `CalibrationController::approve()`, dan di sini taruhannya lebih tinggi.
     * Dulu method ini nge-set status ke `menunggu_generate` DULU baru
     * `dispatch()`, dan tanpa `queue:work` yang jalan urutannya jadi: admin
     * pencet retry → 200 OK → job ngendon di tabel `jobs` → mobile nunjukin
     * "lagi diproses" selamanya → dan tombol retry-nya IKUT ILANG, karena
     * statusnya udah bukan `gagal` lagi. Sertifikat yang tadinya masih bisa
     * dicoba ulang jadi nggak bisa disentuh sama sekali tanpa masuk database
     * manual. Retry itu jalur pemulihan — dia dipencet justru waktu udah ada
     * yang gagal, jadi dia yang paling nggak boleh punya mode macet sendiri.
     *
     * Pre-update ke `menunggu_generate` dibuang, bukan cuma dipindah: job-nya
     * udah nge-set status itu sendiri di dalam transaksi tepat sebelum ngerender
     * (`GenerateCertificate::handle()`). Dengan dibuang, status sertifikat
     * SELALU mendarat di keadaan akhir — `terbit` kalau jadi, `gagal` kalau
     * nggak — bahkan kalau prosesnya mati total di tengah. Yang gagal tetap
     * nawarin retry.
     */
    public function retry(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        if ($certificate->status !== Certificate::STATUS_GAGAL) {
            return response()->json([
                'message' => 'Cuma sertifikat yang statusnya `gagal` yang bisa dicoba terbitin ulang.',
            ], 422);
        }

        try {
            (new GenerateCertificate(
                $certificate->calibration_session_id,
                $request->user()->id,
            ))->handle();
        } catch (\Throwable $e) {
            // Job-nya udah nandain sertifikatnya `gagal` lagi + ngabarin admin
            // sebelum ngelempar, jadi tombol retry tetap ada di layar. Responsnya
            // sengaja tetap 200 dengan status apa adanya: yang nanya "berhasil
            // nggak?" itu field `status` di data, bukan kode HTTP-nya.
            Log::warning('Sertifikat gagal dibikin waktu retry.', [
                'certificate_id' => $certificate->id,
                'calibration_session_id' => $certificate->calibration_session_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => new CertificateResource($certificate->fresh()->load(self::RELASI)),
        ]);
    }

    /**
     * Kirim sertifikat ke email pelanggan (fase-2 §3d). **Admin doang.**
     *
     * Kenapa di backend dan bukan di mobile — dua alasan dari permintaannya, dan
     * dua-duanya dipenuhi di sini:
     *
     * 1. **Alamat pengirim harus domain lab**, bukan Gmail teknisi. Dipenuhi lewat
     *    `MAIL_FROM_ADDRESS`; email lab ditaruh di `Reply-To` (lihat penjelasan di
     *    `SertifikatKePelanggan` — `From` yang domainnya beda dari domain pengirim
     *    bikin SPF/DKIM gagal dan email-nya masuk spam).
     * 2. **Pengirimannya tercatat buat audit** — siapa ngirim ke siapa, kapan.
     *    Semua percobaan dicatat di `certificate_email_logs`, termasuk yang GAGAL:
     *    "kami udah nyoba kirim tapi alamatnya nolak" itu informasi yang justru
     *    dicari waktu pelanggan ngaku nggak nerima.
     *
     * Dikirim SINKRON, bukan lewat queue. Admin yang mencet "Kirim" perlu tahu
     * hasilnya saat itu juga; kiriman yang di-queue lalu gagal diam-diam berarti
     * pelanggan nggak pernah nerima dan nggak ada yang sadar sampai ditanya.
     */
    public function kirimEmail(
        Request $request,
        Certificate $certificate,
        CertificateExcelExporter $excel,
        BerkasPdfSertifikat $berkas,
    ): JsonResponse {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $data = $request->validate([
            'ke' => ['required', 'array', 'min:1', 'max:10'],
            'ke.*' => ['required', 'email:rfc'],
            'cc' => ['sometimes', 'nullable', 'array', 'max:10'],
            'cc.*' => ['required', 'email:rfc'],
            // Default `pdf` — app lama yang belum ngirim field ini tetap jalan
            // persis kayak sebelumnya.
            'format' => ['sometimes', Rule::in(CertificateEmailLog::formatTersedia())],
        ], [
            'ke.required' => 'Alamat tujuannya wajib diisi.',
            'ke.max' => 'Maksimal 10 alamat tujuan sekali kirim.',
            'ke.*.email' => 'Ada alamat email yang formatnya nggak valid.',
            'format.in' => 'Format kirimnya cuma bisa pdf, xlsx, atau tautan.',
        ]);

        $format = $data['format'] ?? CertificateEmailLog::FORMAT_PDF;

        // Cuma sertifikat TERBIT yang boleh dikirim. Yang `gagal` atau
        // `menunggu_generate` belum punya PDF — dan email sertifikat tanpa
        // lampirannya lebih buruk daripada nggak ngirim: pelanggan nganggep udah
        // beres, padahal dokumennya nggak ada.
        // Dua sebab, dua pesan. Digabung jadi satu pesan bikin admin bingung:
        // "harus terbit & punya PDF, yang ini statusnya `terbit`" kedengeran kayak
        // backend-nya ngaco, padahal yang kurang PDF-nya.
        if ($certificate->status !== Certificate::STATUS_TERBIT) {
            return response()->json([
                'message' => 'Cuma sertifikat yang udah terbit yang bisa dikirim. '
                    .'Yang ini statusnya `'.$certificate->status.'`.',
            ], 422);
        }

        // Tiap format punya syarat berkasnya sendiri, dan yang kurang disebut
        // spesifik. "Sertifikat belum siap dikirim" bikin admin nebak-nebak;
        // yang dia butuh tahu itu PDF-nya yang belum jadi, atau justru datanya.
        if ($format === CertificateEmailLog::FORMAT_PDF) {
            if (! filled($certificate->pdf_path)) {
                return response()->json([
                    'message' => 'Sertifikat ini belum punya berkas PDF, jadi belum ada yang bisa '
                        .'dilampirkan. Coba terbitkan ulang dulu.',
                ], 422);
            }

            // Sama seperti jalur unduh: berkas yang raib dibangun ulang dari
            // snapshot dulu, baru dinyatakan hilang. Tanpa ini tiap deploy
            // bikin seluruh kiriman email berlampiran PDF ditolak 422.
            abort_unless(
                $berkas->pastikanAda($certificate) !== null,
                422,
                'Berkas PDF sertifikatnya nggak ketemu di penyimpanan. Coba terbitkan ulang dulu.',
            );
        }

        if ($format === CertificateEmailLog::FORMAT_XLSX && blank($certificate->snapshot)) {
            return response()->json([
                'message' => 'Sertifikat ini belum punya data beku (snapshot), jadi Excel-nya '
                    .'belum bisa dirakit. Coba terbitkan ulang dulu.',
            ], 422);
        }

        if ($format === CertificateEmailLog::FORMAT_TAUTAN && blank($certificate->qr_token)) {
            return response()->json([
                'message' => 'Sertifikat ini belum punya kode verifikasi, jadi belum ada tautan '
                    .'yang bisa dikirim. Coba terbitkan ulang dulu.',
            ], 422);
        }

        $ke = array_values(array_unique($data['ke']));
        $cc = array_values(array_unique($data['cc'] ?? []));
        $certificate->loadMissing(['organization', 'session.equipment.customer']);

        // Excel dirakit on-the-fly (nggak disimpen kayak PDF), jadi berkas
        // sementaranya dihapus di `finally` — termasuk waktu pengiriman gagal.
        $berkasXlsx = null;

        try {
            if ($format === CertificateEmailLog::FORMAT_XLSX) {
                $berkasXlsx = tempnam(sys_get_temp_dir(), 'sertifikat-').'.xlsx';
                $excel->satu($certificate, $berkasXlsx);
            }

            Mail::to($ke)->send(new SertifikatKePelanggan($certificate, $cc, $format, $berkasXlsx));
            $status = CertificateEmailLog::STATUS_TERKIRIM;
            $error = null;
        } catch (\Throwable $e) {
            $status = CertificateEmailLog::STATUS_GAGAL;
            $error = $e->getMessage();

            Log::warning('Kirim email sertifikat gagal.', [
                'certificate_id' => $certificate->id,
                'format' => $format,
                'error' => $error,
            ]);
        } finally {
            if ($berkasXlsx !== null && is_file($berkasXlsx)) {
                @unlink($berkasXlsx);
            }
        }

        // Mailer pengembangan nggak ngelempar exception, jadi `catch` di atas
        // nggak kena dan statusnya terlanjur `terkirim`. Dibetulin SEBELUM
        // dicatat — kalau sesudah, riwayatnya udah telanjur bohong.
        //
        // Ini yang bikin layar kirim nampilin tiga baris "Sent" ke pelanggan
        // yang sebenernya nggak pernah nerima apa-apa.
        $mailerMati = $status === CertificateEmailLog::STATUS_TERKIRIM
            ? Mailer::takMengirim()
            : null;

        if ($mailerMati !== null) {
            $status = CertificateEmailLog::STATUS_TIDAK_TERKIRIM;
            $error = "MAIL_MAILER masih `{$mailerMati}` — emailnya cuma dicatat di server, "
                .'nggak dikirim ke mana-mana.';

            Log::warning('Email sertifikat NGGAK beneran dikirim: MAIL_MAILER masih mailer pengembangan.', [
                'certificate_id' => $certificate->id,
                'mail_mailer' => $mailerMati,
                'ke' => $ke,
            ]);
        }

        // Dicatat DULUAN sebelum responsnya dibalik, dan buat SEMUA hasilnya.
        // Percobaan yang gagal — dan yang nggak pernah keluar — tetap kejadian
        // yang perlu bisa dibuktiin.
        $log = CertificateEmailLog::create([
            'organization_id' => $certificate->organization_id,
            'certificate_id' => $certificate->id,
            'ke' => $ke,
            'cc' => $cc === [] ? null : $cc,
            'format' => $format,
            'status' => $status,
            'error' => $error,
            'dikirim_oleh' => $request->user()->id,
        ]);

        if ($status === CertificateEmailLog::STATUS_GAGAL) {
            return response()->json([
                'message' => 'Email gagal dikirim. Percobaannya tetap tercatat di riwayat pengiriman.',
                'data' => $this->barisRiwayat($log),
            ], 502);
        }

        // Mailer pengembangan "berhasil" tanpa ngirim apa-apa. Kalau kita diem,
        // admin baca "Sertifikat terkirim ke pic@..." dan nungguin balasan yang
        // nggak bakal datang — kegagalan paling mahal, karena nggak keliatan.
        if ($mailerMati !== null) {
            $peringatan = "Belum beneran terkirim. MAIL_MAILER masih `{$mailerMati}`, jadi emailnya "
                .'cuma dicatat di server dan pelanggan nggak nerima apa-apa. Isi setelan SMTP di '
                .'`.env` dulu, baru kirim ulang.';

            return response()->json([
                'message' => $peringatan,
                'peringatan' => $peringatan,
                'data' => $this->barisRiwayat($log),
            ]);
        }

        return response()->json([
            'message' => match ($format) {
                CertificateEmailLog::FORMAT_XLSX => 'Sertifikat (Excel) terkirim ke '.implode(', ', $ke).'.',
                CertificateEmailLog::FORMAT_TAUTAN => 'Tautan verifikasi terkirim ke '.implode(', ', $ke).'.',
                default => 'Sertifikat (PDF) terkirim ke '.implode(', ', $ke).'.',
            },
            'peringatan' => null,
            'data' => $this->barisRiwayat($log),
        ]);
    }

    /**
     * Catat bahwa sertifikat dikirim lewat WhatsApp.
     *
     * **Server nggak ngirim apa-apa di sini.** Pesannya dikirim dari HP admin
     * lewat aplikasi WhatsApp-nya sendiri (`wa.me`); endpoint ini cuma nyimpen
     * jejaknya. Beda dari `kirimEmail()` yang beneran ngirim, jadi statusnya
     * SELALU `terkirim` — nggak ada kegagalan yang bisa kita lihat dari sini.
     *
     * Kenapa tetap dicatat: waktu pelanggan ngaku nggak nerima, yang ditanya
     * "kapan dikirim, ke nomor mana, sama siapa". Kalau jejaknya cuma ada di HP
     * satu orang, nggak ada yang bisa mbuktiin apa-apa — persis alasan riwayat
     * email ada.
     */
    public function catatWhatsapp(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $data = $request->validate([
            // Nomor disimpen apa adanya (mis. `+6281234567890`) — yang harus
            // bisa dibuktikan itu nomor yang BENERAN dipakai waktu itu, bukan
            // nomor pelanggan yang mungkin udah diubah sesudahnya.
            'ke' => ['required', 'array', 'min:1', 'max:10'],
            'ke.*' => ['required', 'string', 'max:32'],
            // Yang mau dikirim: PDF, Excel, atau halaman verifikasi.
            'format' => ['sometimes', Rule::in([
                CertificateEmailLog::FORMAT_PDF,
                CertificateEmailLog::FORMAT_XLSX,
                CertificateEmailLog::FORMAT_TAUTAN,
            ])],
        ], [
            'ke.required' => 'Nomor WhatsApp tujuannya wajib diisi.',
            'format.in' => 'Format kirimnya cuma bisa pdf, xlsx, atau tautan.',
        ]);

        if ($certificate->status !== Certificate::STATUS_TERBIT) {
            return response()->json([
                'message' => 'Cuma sertifikat yang udah terbit yang bisa dikirim. '
                    .'Yang ini statusnya `'.$certificate->status.'`.',
            ], 422);
        }

        if (blank($certificate->qr_token)) {
            return response()->json([
                'message' => 'Sertifikat ini belum punya kode verifikasi, jadi belum ada '
                    .'tautan yang bisa dikirim. Coba terbitkan ulang dulu.',
            ], 422);
        }

        $format = $data['format'] ?? CertificateEmailLog::FORMAT_TAUTAN;

        if ($format === CertificateEmailLog::FORMAT_XLSX && blank($certificate->snapshot)) {
            return response()->json([
                'message' => 'Sertifikat ini belum punya data beku (snapshot), jadi Excel-nya '
                    .'belum bisa dirakit. Coba terbitkan ulang dulu.',
            ], 422);
        }

        $ke = array_values(array_unique($data['ke']));

        $log = CertificateEmailLog::create([
            'organization_id' => $certificate->organization_id,
            'certificate_id' => $certificate->id,
            'ke' => $ke,
            'cc' => null,
            // Formatnya dicatat sebagai `whatsapp` — yang perlu bisa dijawab
            // waktu pelanggan ngaku nggak nerima itu "lewat mana", dan itu yang
            // paling ngebedain. Isi persisnya (PDF/Excel/tautan) nempel di
            // `pesan` yang dikirim, dan sama-sama nunjuk sertifikat yang ini.
            'format' => CertificateEmailLog::FORMAT_WHATSAPP,
            'status' => CertificateEmailLog::STATUS_TERKIRIM,
            'error' => null,
            'dikirim_oleh' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Pengiriman lewat WhatsApp ke '.implode(', ', $ke).' tercatat.',
            // Pesan & tautannya disusun DI SINI, bukan di mobile.
            //
            // Tautan unduhnya nempel ke `qr_token` dan skema URL yang cuma
            // backend yang tahu. Kalau mobile nyusun sendiri, satu perubahan
            // rute bikin pelanggan nerima tautan mati — dan yang ketahuan
            // belakangan, sesudah pesannya kekirim.
            'pesan' => $this->pesanWhatsapp($certificate, $format),
            'data' => $this->barisRiwayat($log),
        ]);
    }

    /**
     * Teks siap-kirim buat WhatsApp, per format.
     *
     * WhatsApp nggak bisa dititipin lampiran tanpa Business API, dan nempelin
     * PDF ke pesan pribadi juga bikin dokumen resmi nyebar tanpa jejak. Jadi
     * yang dikirim TAUTAN — bedanya cuma tautan ke apa:
     *
     * - `pdf`    → unduhan PDF langsung
     * - `xlsx`   → unduhan Excel langsung
     * - `tautan` → halaman verifikasi (lembar sertifikat + tombol unduh)
     *
     * Ketiganya tanpa auth: pelanggan nggak punya akun, dan yang jagain tetap
     * `qr_token` — 10 karakter acak, bukan id berurutan. Siapa pun yang megang
     * tautannya emang udah dikasih sertifikatnya.
     */
    private function pesanWhatsapp(Certificate $certificate, string $format): string
    {
        $certificate->loadMissing('organization', 'session.equipment');

        $tautan = match ($format) {
            CertificateEmailLog::FORMAT_PDF => route('verify.download', $certificate->qr_token),
            CertificateEmailLog::FORMAT_XLSX => route('verify.download', $certificate->qr_token).'?format=xlsx',
            default => route('verify', $certificate->qr_token),
        };

        $baris = array_filter([
            'Sertifikat Kalibrasi '.($certificate->nomor ?? ''),
            '',
            'Alat: '.($certificate->session?->equipment?->nama_alat ?? '-'),
            'Serial: '.($certificate->session?->alat_serial_number
                ?: $certificate->session?->equipment?->serial_number ?: '-'),
            'Berlaku sampai: '.($certificate->berlaku_sampai?->translatedFormat('d F Y') ?? '-'),
            '',
            match ($format) {
                CertificateEmailLog::FORMAT_PDF => 'Unduh PDF-nya di sini:',
                CertificateEmailLog::FORMAT_XLSX => 'Unduh versi Excel-nya di sini:',
                default => 'Lihat & unduh sertifikatnya di sini:',
            },
            $tautan,
            '',
            $certificate->organization?->nama ?? '',
        ], fn (string $b): bool => $b !== '' || true);

        return implode("\n", $baris);
    }

    /**
     * Riwayat pengiriman satu sertifikat — terbaru dulu. **Admin doang.**
     *
     * Ini sisi bacanya dari catatan audit yang diminta fase-2 §3d. Tanpa endpoint
     * ini, catatannya ada di database tapi nggak bisa dilihat siapa pun — dan
     * catatan yang nggak bisa dilihat sama saja dengan nggak nyatet.
     */
    public function riwayatEmail(Request $request, Certificate $certificate): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $riwayat = CertificateEmailLog::where('certificate_id', $certificate->id)
            ->with('pengirim')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $riwayat->map(fn (CertificateEmailLog $l): array => $this->barisRiwayat($l))->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function barisRiwayat(CertificateEmailLog $log): array
    {
        $log->loadMissing('pengirim');

        return [
            'id' => $log->id,
            'ke' => $log->ke,
            'cc' => $log->cc ?? [],
            'format' => $log->format ?? CertificateEmailLog::FORMAT_PDF,
            'status' => $log->status,
            'error' => $log->error,
            'dikirim_oleh' => $log->pengirim ? [
                'id' => $log->pengirim->id,
                'nama' => $log->pengirim->name,
            ] : null,
            'dikirim_pada' => $log->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Teknisi cuma boleh megang sertifikat dari sesi miliknya sendiri. Tanpa ini,
     * daftar udah difilter tapi download per-ID masih bocor — tinggal tebak ID.
     */
    private function pastikanBolehLihat(Request $request, Certificate $certificate): void
    {
        $this->pastikanSatuOrganisasi($request, $certificate);

        $user = $request->user();

        abort_if(
            $user->role === User::ROLE_TEKNISI && $certificate->session?->teknisi_id !== $user->id,
            404,
        );
    }

    /** Jaring pengaman multi-tenant: PT lain nggak boleh baca sertifikat kita. */
    private function pastikanSatuOrganisasi(Request $request, Certificate $certificate): void
    {
        abort_if($certificate->organization_id !== $request->user()->organization_id, 404);
    }
}
