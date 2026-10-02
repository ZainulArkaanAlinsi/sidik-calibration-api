<?php

namespace App\Http\Controllers\Api;

use App\Events\PerubahanDataOrganisasi;
use App\Http\Controllers\Controller;
use App\Http\Requests\KembalikanDariPengesahanRequest;
use App\Http\Requests\SahkanSertifikatRequest;
use App\Http\Requests\TarikPengajuanRequest;
use App\Http\Resources\AntreanPengesahanResource;
use App\Http\Resources\CalibrationResource;
use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Notifications\PengajuanDikembalikan;
use App\Services\PemisahanWewenang;
use App\Services\PenerimaNotifikasi;
use App\Services\PenjagaOrganisasi;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Gerbang pengesahan sertifikat.
 *
 * Yang dijaga kelas ini: antara "admin sudah memeriksa" dan "sertifikat ada
 * nomornya" sekarang ada satu langkah lagi, dan langkah itu punya pemiliknya
 * sendiri (super admin). Empat aksinya:
 *
 *   antrean()      GET  /api/pengesahan/antrean                       — daftar yang nunggu
 *   sahkan()       POST /api/calibrations/{c}/sahkan                  — super admin: TERBITKAN
 *   kembalikan()   POST /api/calibrations/{c}/kembalikan-dari-pengesahan — super admin: tolak halus
 *   tarikPengajuan() POST /api/calibrations/{c}/tarik-pengajuan       — admin: ambil lagi
 *
 * ## Kenapa `sahkan()` di sini, bukan di `CalibrationController`
 *
 * `CalibrationController` sudah 4.900 baris dan pemiliknya lembar kerja: isi,
 * hitung, periksa, setujui. Pengesahan bukan lanjutan pekerjaan itu — dia
 * keputusan orang lain, dengan role lain, di layar lain, dan satu-satunya
 * tempat di seluruh sistem yang boleh melahirkan nomor sertifikat. Menaruhnya
 * di berkas yang sama membuat batas itu hilang dari pandangan orang yang
 * membacanya enam bulan lagi.
 *
 * ## Satu hal yang WAJIB benar, dan gampang dilewatkan
 *
 * `dispatch(new GenerateCertificate(...))` cuma boleh ada di `sahkan()`.
 * Selama dia masih ikut jalan di `CalibrationController::approve()`, sertifikat
 * tetap lahir waktu admin mencet Setujui — dan seluruh gerbang ini jadi hiasan
 * yang cuma menambah satu klik. Bedahnya ada di
 * `../BEDAH-01-CalibrationController-approve.md`, dan
 * `GerbangPengesahanTest::test_approve_tidak_menerbitkan_sertifikat()` yang
 * berteriak kalau dispatch-nya ketinggalan.
 */
class PengesahanController extends Controller
{
    /** Relasi yang dibutuhkan kartu antrean — di-eager load biar nggak N+1. */
    private const RELASI_ANTREAN = [
        'equipment:id,nama_alat,merk,model,serial_number,customer_id',
        'equipment.customer:id,nama',
        'teknisi:id,name,kode_teknisi',
        'reviewer:id,name',
        'pengaju:id,name',
    ];

    public function __construct(private readonly PemisahanWewenang $pemisahan) {}

    /**
     * Antrean pengesahan — sesi yang sudah diajukan admin dan nunggu disahkan.
     *
     * Disortir dari yang PALING LAMA menunggu, bukan yang terbaru. Layar ini
     * ada supaya tidak ada sertifikat yang tertinggal berhari-hari; urutan
     * "terbaru dulu" justru menyembunyikan yang paling perlu dilihat di dasar
     * daftar.
     */
    public function antrean(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $antrean = CalibrationSession::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('status', CalibrationSession::STATUS_MENUNGGU_PENGESAHAN)
            ->when(
                filled($data['q'] ?? null),
                fn ($q) => $q->where(function ($cari) use ($data): void {
                    $cari->where('nomor_sesi', 'like', '%'.$data['q'].'%')
                        ->orWhereHas(
                            'equipment',
                            fn ($alat) => $alat->where('nama_alat', 'like', '%'.$data['q'].'%')
                                ->orWhere('serial_number', 'like', '%'.$data['q'].'%')
                        );
                })
            )
            ->with(self::RELASI_ANTREAN)
            // `diajukan_pada` bisa null untuk baris yang dipindahkan migrasi
            // tangan; yang null diperlakukan paling tua supaya dia tidak hilang
            // dari pandangan.
            ->orderByRaw('diajukan_pada IS NULL DESC')
            ->orderBy('diajukan_pada')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => AntreanPengesahanResource::collection($antrean->items()),
            'meta' => [
                'total' => $antrean->total(),
                'per_page' => $antrean->perPage(),
                'current_page' => $antrean->currentPage(),
                'last_page' => $antrean->lastPage(),
                // Dipakai lencana merah di nav super admin. Dihitung dari
                // paginator, bukan query kedua.
                'jumlah_menunggu' => $antrean->total(),
            ],
        ]);
    }

    /**
     * Sahkan & terbitkan. SATU-SATUNYA pintu yang melahirkan nomor sertifikat.
     */
    public function sahkan(SahkanSertifikatRequest $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibration);

        if ($calibration->status !== CalibrationSession::STATUS_MENUNGGU_PENGESAHAN) {
            return response()->json([
                'message' => 'Cuma sesi yang statusnya `menunggu_pengesahan` yang bisa disahkan. '
                    .'Sesi ini statusnya `'.$calibration->status.'`.',
            ], 422);
        }

        $data = $request->validated();
        $pengesah = $request->user();

        // Penandatangan: yang dikirim pengesah > yang dititipkan admin waktu
        // mengajukan > null (artinya pakai `organizations.settings`).
        $penandatanganId = array_key_exists('penandatangan_user_id', $data)
            ? $data['penandatangan_user_id']
            : $calibration->penandatangan_user_id;

        $wewenang = $this->pemisahan->periksa($calibration, $pengesah, $penandatanganId);

        // Ditahan, tanpa pengecualian (K-30-03, 1 Okt 2026). `abaikan_peringatan`
        // sengaja TIDAK menembus: mode "peringatan lalu boleh lanjut" sudah
        // dicabut bersama sakelarnya. Alasan lengkapnya di docblock
        // `PemisahanWewenang`.
        if (! $wewenang['bersih']) {
            return response()->json([
                'message' => 'Pengesahan ditahan: kamu ikut mengisi, memeriksa, atau mengajukan sesi ini. '
                    .'Minta pengesah lain.',
                'kode' => 'pemisahan_wewenang',
                'wewenang' => $wewenang,
            ], 422);
        }

        $kolomPengesahan = array_flip([
            'status', 'disahkan_pada', 'disahkan_oleh', 'penandatangan_user_id', 'berlaku_sampai_diminta',
        ]);
        $sebelum = array_intersect_key($calibration->getAttributes(), $kolomPengesahan);

        $berlakuSampai = array_key_exists('berlaku_sampai', $data) && filled($data['berlaku_sampai'])
            ? Carbon::parse($data['berlaku_sampai'])->toDateString()
            : $calibration->berlaku_sampai_diminta?->toDateString();

        // Transisi BERSYARAT, bukan `update()` polos — alasan yang sama persis
        // dengan approve(): antara pemeriksaan status di atas dan baris ini ada
        // pemeriksaan pemisahan wewenang yang menyentuh database, jadi dua klik
        // "Sahkan" yang berdekatan sama-sama bisa lolos sampai sini.
        //
        // Di sini akibatnya lebih mahal daripada di approve(): yang kalah
        // balapan akan men-dispatch `GenerateCertificate` kedua, dan job itu
        // mengalokasikan NOMOR SERTIFIKAT. Dua nomor untuk satu sesi berarti
        // satu nomor beredar tanpa dokumen — dan nomor yang bolong itu temuan
        // audit, bukan cuma bug.
        $berhasil = CalibrationSession::whereKey($calibration->id)
            ->where('status', CalibrationSession::STATUS_MENUNGGU_PENGESAHAN)
            ->update([
                'status' => CalibrationSession::STATUS_DISETUJUI,
                'disahkan_pada' => now(),
                'disahkan_oleh' => $pengesah->id,
                'penandatangan_user_id' => $penandatanganId,
                'berlaku_sampai_diminta' => $berlakuSampai,
                'updated_at' => now(),
            ]);

        if ($berhasil === 0) {
            return response()->json([
                'message' => 'Sesi ini barusan sudah disahkan lewat permintaan lain — '
                    .'sertifikatnya nggak dibikin dua kali. Muat ulang halamannya.',
            ], 409);
        }

        $calibration->refresh();

        // UPDATE bersyarat di atas lewat query builder, dan query builder tidak
        // memicu event model — `Diaudit` tidak pernah tahu. Dicatat tangan,
        // bentuknya disamakan dengan `Diaudit::perubahanAudit()`: cuma kolom
        // yang berubah. Ini baris audit yang ditunjuk auditor kalau bertanya
        // "siapa yang mengesahkan dokumen ini", jadi dia nggak boleh hilang.
        $sesudah = array_intersect_key($calibration->getAttributes(), $kolomPengesahan);
        $berubah = array_keys(array_filter(
            $sesudah,
            fn (mixed $nilai, string $kolom): bool => $nilai != ($sebelum[$kolom] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));

        $calibration->catatAudit(
            AuditLog::ACTION_DIUBAH,
            array_intersect_key($sebelum, array_flip($berubah)),
            array_intersect_key($sesudah, array_flip($berubah)),
            'Disahkan & diterbitkan.',
        );

        $job = new GenerateCertificate($calibration->id, $pengesah->id, $berlakuSampai);

        // Status sesi SUDAH di-commit `disetujui` di atas. Antrean yang menolak
        // job di sini (tabel `jobs` nggak bisa ditulisi) meninggalkan sesi
        // disahkan tanpa baris sertifikat dan tanpa tombol retry. Baris `gagal`
        // dari `tinggalkanJalanPulih` memunculkan tombol "Terbitkan ulang".
        // Blok ini DIPINDAH dari approve() apa adanya — jangan disederhanakan.
        $gagalAntre = null;

        try {
            dispatch($job);
        } catch (\Throwable $e) {
            report($e);
            $gagalAntre = $e;
            $job->tinggalkanJalanPulih($e);
        }

        $segar = $calibration->fresh()->load(self::RELASI_ANTREAN);

        // Antrean pengesahan di HP/desktop super admin lain dan antrean admin
        // yang mengajukan ikut menyusut tanpa menunggu tarikan berkala.
        PerubahanDataOrganisasi::siarkanAman($calibration->organization_id, 'kalibrasi', 'disahkan', $calibration->id);

        if ($gagalAntre !== null) {
            return response()->json([
                'message' => 'Sesi sudah disahkan, tapi penerbitan sertifikatnya gagal dimulai. '
                    .'Buka sertifikatnya lalu tekan "Terbitkan ulang".',
                'data' => new CalibrationResource($segar),
                'wewenang' => $wewenang,
            ], 503);
        }

        return response()->json([
            'message' => 'Disahkan. Sertifikatnya sedang dicetak — nomornya muncul sebentar lagi.',
            'data' => new CalibrationResource($segar),
            'wewenang' => $wewenang,
        ]);
    }

    /**
     * Super admin mengembalikan pengajuan ke admin, tanpa menerbitkan apa pun.
     *
     * Bedanya dari `reject()` milik admin: `reject()` melempar lembar kerja
     * kembali ke TEKNISI karena angkanya diragukan. Yang ini berhenti di ADMIN —
     * yang dipersoalkan biasanya bukan angkanya, tapi kelengkapan berkas,
     * penandatangan yang salah, atau masa berlaku yang keliru. Melemparnya
     * sampai ke teknisi berarti teknisi mengerjakan ulang sesuatu yang tidak
     * salah, dan lembar kerja yang sudah benar dibuka lagi tanpa alasan.
     */
    public function kembalikan(
        KembalikanDariPengesahanRequest $request,
        CalibrationSession $calibration,
    ): JsonResponse {
        $this->pastikanSatuOrganisasi($request, $calibration);

        if ($calibration->status !== CalibrationSession::STATUS_MENUNGGU_PENGESAHAN) {
            return response()->json([
                'message' => 'Cuma pengajuan yang statusnya `menunggu_pengesahan` yang bisa dikembalikan.',
            ], 422);
        }

        return $this->batalkanPengajuan(
            $calibration,
            $request->user(),
            $request->validated()['alasan'],
            olehPengesah: true,
        );
    }

    /**
     * Admin menarik pengajuannya sendiri, selama belum disahkan.
     *
     * Ini janji yang dibeli gerbang ini: "masih bisa dibalik". Dan dia bisa
     * ditepati TANPA urusan ISO 17025 §7.8.8 justru karena sertifikatnya belum
     * pernah ada — tidak ada nomor yang terpakai, tidak ada PDF yang sudah
     * dikirim, tidak ada yang perlu diterbitkan sebagai "amandemen". Begitu
     * sudah disahkan, pintu ini tertutup dan yang tersisa cuma Revisi (`-R1`)
     * atau Pembatalan resmi.
     */
    public function tarikPengajuan(
        TarikPengajuanRequest $request,
        CalibrationSession $calibration,
    ): JsonResponse {
        $this->pastikanSatuOrganisasi($request, $calibration);

        if ($calibration->status !== CalibrationSession::STATUS_MENUNGGU_PENGESAHAN) {
            return response()->json([
                'message' => $calibration->status === CalibrationSession::STATUS_DISETUJUI
                    ? 'Sertifikatnya sudah disahkan dan terbit. Yang bisa dilakukan sekarang '
                        .'cuma Revisi (terbit pengganti) atau Pembatalan — dua-duanya tercatat.'
                    : 'Sesi ini nggak sedang menunggu pengesahan.',
            ], 422);
        }

        // "Pengajuannya sendiri" ditegakkan, bukan cuma ditulis di docblock
        // (temuan B04, paket 30 Sep). Admin lain yang menarik membatalkan
        // pemeriksaan orang lain tanpa sepengetahuannya; jalur yang sah untuk
        // itu `kembalikan` milik pengesah, yang tercatat sebagai pengesah.
        //
        // 403, bukan 404: sesinya memang terlihat oleh admin ini (satu lab, ada
        // di antrean), jadi 404 cuma membingungkan. Baris tanpa `diajukan_oleh`
        // (dipindah tangan sebelum kolom itu diisi) tidak punya pemilik yang
        // bisa dicocokkan, dan perilakunya dibiarkan seperti sebelumnya.
        if (
            $calibration->diajukan_oleh !== null
            && (int) $calibration->diajukan_oleh !== (int) $request->user()->id
        ) {
            return response()->json([
                'message' => 'Cuma admin yang mengajukan yang bisa menarik pengajuan ini. '
                    .'Minta dia, atau minta pengesah mengembalikannya.',
            ], 403);
        }

        return $this->batalkanPengajuan(
            $calibration,
            $request->user(),
            $request->validated()['alasan'],
            olehPengesah: false,
        );
    }

    /**
     * Jalur yang sama untuk "ditarik admin" dan "dikembalikan pengesah".
     *
     * Dua-duanya mendarat di `menunggu_approval`, BUKAN `perlu_revisi`. Yang
     * membedakan cuma siapa yang dikabari dan apa yang tertulis di riwayat.
     *
     * Kenapa `menunggu_approval` dan bukan `perlu_revisi`: `perlu_revisi` itu
     * kotak masuk TEKNISI, dan dia membuka kembali lembar kerja untuk diedit
     * teknisi. Sesi ini sudah lewat pemeriksaan admin dan angkanya tidak
     * dipersoalkan — yang perlu dibetulkan ada di meja admin. Mengirimnya ke
     * `perlu_revisi` membuat teknisi mengerjakan ulang pekerjaan yang benar.
     */
    private function batalkanPengajuan(
        CalibrationSession $calibration,
        User $pelaku,
        string $alasan,
        bool $olehPengesah,
    ): JsonResponse {
        $kolom = array_flip(['status', 'diajukan_pada', 'diajukan_oleh', 'catatan_pengajuan']);
        $sebelum = array_intersect_key($calibration->getAttributes(), $kolom);

        $berhasil = CalibrationSession::whereKey($calibration->id)
            ->where('status', CalibrationSession::STATUS_MENUNGGU_PENGESAHAN)
            ->update([
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                // Dinolkan, bukan disimpan: kalau tidak, umur di antrean
                // pengesahan dihitung dari pengajuan yang sudah dibatalkan dan
                // layarnya menampilkan "menunggu 6 hari" untuk sesuatu yang
                // baru diajukan tadi. Jejaknya tetap utuh di `audit_logs`.
                'diajukan_pada' => null,
                'diajukan_oleh' => null,
                'catatan_pengajuan' => null,
                'updated_at' => now(),
            ]);

        if ($berhasil === 0) {
            return response()->json([
                'message' => 'Terlambat — sesi ini barusan sudah disahkan lewat permintaan lain. '
                    .'Muat ulang halamannya.',
            ], 409);
        }

        $calibration->refresh();
        $sesudah = array_intersect_key($calibration->getAttributes(), $kolom);

        $calibration->catatAudit(
            AuditLog::ACTION_DIUBAH,
            $sebelum,
            $sesudah,
            ($olehPengesah ? 'Dikembalikan pengesah: ' : 'Pengajuan ditarik admin: ').$alasan,
        );

        $segar = $calibration->fresh()->load(self::RELASI_ANTREAN);

        PerubahanDataOrganisasi::siarkanAman(
            $calibration->organization_id,
            'kalibrasi',
            $olehPengesah ? 'dikembalikan' : 'ditarik',
            $calibration->id,
        );

        // Yang ditarik sendiri nggak perlu dikabari — orangnya yang mencet.
        // Yang dikembalikan pengesah HARUS: adminnya tidak sedang melihat layar
        // itu, dan pengajuan yang diam-diam balik ke antreannya adalah cara
        // paling rapi untuk membuat sertifikat tertahan seminggu tanpa ada yang
        // sadar.
        // Dibungkus `try` karena UPDATE di atas sudah ter-commit: saluran
        // `broadcast` yang meledak (Reverb mati) tidak boleh menjawab 500 untuk
        // pengembalian yang sudah terjadi.
        if ($olehPengesah) {
            try {
                Notification::send(
                    app(PenerimaNotifikasi::class)->adminAktif($calibration->organization_id),
                    PengajuanDikembalikan::dariSesi($segar, $pelaku->name, $alasan),
                );
            } catch (\Throwable $e) {
                Log::warning('Notifikasi pengembalian pengajuan gagal dikirim.', [
                    'calibration_session_id' => $calibration->id,
                    'pesan' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => $olehPengesah
                ? 'Pengajuan dikembalikan ke admin. Sertifikatnya belum pernah terbit, '
                    .'jadi nggak ada yang perlu dibatalkan.'
                : 'Pengajuan ditarik. Lembar kerjanya balik ke antrean pemeriksaan kamu.',
            'data' => new CalibrationResource($segar),
        ]);
    }

    /**
     * Sesi lab lain dijawab 404, bukan 403.
     *
     * Pola ini disamakan dengan `pastikanSatuOrganisasi` di enam controller
     * lain — bukan dikarang ulang. 403 mengonfirmasi bahwa id-nya ADA di lab
     * lain; 404 tidak membocorkan apa pun. Lihat `../../B-data-tidak-bocor/README.md`.
     */
    private function pastikanSatuOrganisasi(Request $request, CalibrationSession $sesi): void
    {
        // Diteruskan ke PenjagaOrganisasi: satu tempat yang memutuskan dan
        // satu tempat yang MENCATAT percobaannya. Perilaku dari luar identik
        // (404 yang sama); yang ditambahkan cuma jejaknya di audit_logs.
        PenjagaOrganisasi::pastikanSatu($request, $sesi);
    }
}
