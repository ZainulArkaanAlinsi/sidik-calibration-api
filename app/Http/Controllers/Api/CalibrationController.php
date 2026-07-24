<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalibrationRequest;
use App\Http\Resources\CalibrationResource;
use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Notifications\SesiDisetujui;
use App\Notifications\SesiMenungguApproval;
use App\Notifications\SesiPerluRevisi;
use App\Services\CalibrationValidator;
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use App\Services\LembarKerjaTemplate;
use App\Services\PerhitunganBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sesi kalibrasi: draft → menunggu_approval → disetujui / perlu_revisi.
 *
 * Data dari input manual dan dari hasil scan kamera masuk ke endpoint yang SAMA —
 * bedanya cuma `input_method`, buat statistik, bukan buat logic beda. Nggak ada
 * endpoint terpisah buat OCR.
 */
class CalibrationController extends Controller
{
    public function __construct(
        private readonly GumCalculator $gum,
        private readonly CalibrationValidator $validator,
        private readonly KondisiLingkungan $kondisi,
    ) {}

    /** Relasi yang selalu dibutuhin CalibrationResource. */
    private const RELASI = [
        'equipment', 'teknisi', 'standard', 'thermohygro', 'standarDicek', 'calibrationMethod', 'room',
        'uncertaintyCalculations.standard', 'certificate',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $sesi = CalibrationSession::query()
            ->with(self::RELASI)
            ->where('organization_id', $user->organization_id)
            // Teknisi SELALU cuma lihat sesi miliknya sendiri — nggak peduli query
            // param-nya diisi apa. Kalau `mine` dipercaya apa adanya, teknisi
            // tinggal ngirim `mine=false` buat ngintip kerjaan orang lain.
            // Buat admin & viewer, `mine=true` baru berfungsi sebagai filter.
            ->when(
                $user->role === User::ROLE_TEKNISI || $request->boolean('mine'),
                fn ($query) => $query->where('teknisi_id', $user->id),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('equipment_id'),
                fn ($query) => $query->where('equipment_id', $request->integer('equipment_id')),
            )
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return CalibrationResource::collection($sesi);
    }

    /**
     * Bentuk baku Lembar Kerja pH Meter (SIDIK-FM-CAL-0509_Rev.4) — susunan
     * bagian, kolom, jumlah pengulangan, dan mana yang keisi otomatis.
     *
     * Dipakai layar input teknisi biar tampilannya persis kertas yang mereka
     * pakai selama ini, dan biar nggak ada tebak-tebakan kolom mana yang harus
     * diisi. Jawabannya: nggak ada yang wajib — semua boleh dikosongin dan
     * lembar kerjanya tetap bisa dikirim.
     *
     * Bentuknya beda per role: teknisi dapat PERSIS kolom di lembar kerja,
     * admin dapat itu plus kolom administratif (spesifikasi poin 1 & 12A).
     */
    public function lembarKerja(Request $request, LembarKerjaTemplate $template): JsonResponse
    {
        return response()->json([
            'data' => $template->phMeter(untukAdmin: $request->user()->isAdmin()),
        ]);
    }

    public function store(CalibrationRequest $request): JsonResponse
    {
        $clientRequestId = $request->input('client_request_id');

        // Replay: teknisi di lapangan submit, sinyal putus pas nunggu respons
        // (padahal request-nya udah sampe ke server), mobile nganggep gagal &
        // retry begitu koneksi balik. Tanpa ini, retry-nya bikin sesi dobel
        // buat 1 kejadian kalibrasi yang sama. `client_request_id` di-scope ke
        // organisasi, sama kayak constraint DB-nya.
        if ($clientRequestId !== null) {
            $existing = CalibrationSession::where('organization_id', $request->user()->organization_id)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($existing) {
                return response()->json([
                    'data' => new CalibrationResource($existing->load(self::RELASI)),
                ], 200);
            }
        }

        $sesi = DB::transaction(function () use ($request, $clientRequestId): CalibrationSession {
            $sesi = CalibrationSession::create([
                ...$this->atributDariRequest($request),
                'organization_id' => $request->user()->organization_id,
                'teknisi_id' => $request->user()->id,
                'client_request_id' => $clientRequestId,
                'nomor_sesi' => $this->nomorSesiBerikutnya($request->user()->organization_id),
                'status' => CalibrationSession::STATUS_DRAFT,
            ]);

            return $this->isiUlangPengukuran($sesi, $request);
        });

        return response()->json(['data' => new CalibrationResource($sesi)], 201);
    }

    public function show(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanBolehLihat($request, $calibration);

        // rawMeasurements cuma dimuat di detail (bukan list) — buat nampilin
        // status verifikasi tiap pembacaan. Lihat CalibrationResource.
        return response()->json([
            'data' => new CalibrationResource($calibration->load([...self::RELASI, 'rawMeasurements'])),
        ]);
    }

    /**
     * Teknisi ngerjain ulang sesi yang ditolak admin (`perlu_revisi`) atau
     * nerusin draft-nya. Semua titik & hasil hitung lama dibuang, diganti yang baru.
     *
     * Sesi yang udah `disetujui` NGGAK bisa diubah — sertifikatnya udah terbit,
     * dan angka di sertifikat yang udah dipegang pelanggan nggak boleh berubah
     * diam-diam. Kalau ada yang salah, terbitin revisi sertifikat, bukan edit sesi.
     */
    public function update(CalibrationRequest $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanBolehLihat($request, $calibration);

        abort_if(
            $calibration->teknisi_id !== $request->user()->id && ! $request->user()->isAdmin(),
            403,
            'Cuma teknisi yang ngerjain sesi ini yang boleh ngubahnya.',
        );

        if (! in_array($calibration->status, [
            CalibrationSession::STATUS_DRAFT,
            CalibrationSession::STATUS_PERLU_REVISI,
        ], true)) {
            return response()->json([
                'message' => 'Sesi yang lagi nunggu approval atau udah disetujui nggak bisa diubah.',
            ], 422);
        }

        $sesi = DB::transaction(function () use ($request, $calibration): CalibrationSession {
            $calibration->update([
                ...$this->atributDariRequest($request),
                // Begitu direvisi & disubmit ulang, catatan revisi lama nggak
                // relevan lagi — jangan sampai teknisi lihat teguran yang udah dibenerin.
                'catatan_revisi' => null,
            ]);

            return $this->isiUlangPengukuran($calibration, $request);
        });

        return response()->json(['data' => new CalibrationResource($sesi)]);
    }

    /**
     * Admin doang (dijaga `role:admin` di routes).
     *
     * Sebelum disetujui, sistem NGITUNG ULANG semua angka dari pembacaan mentah
     * & ngadu sama yang tersimpan (spesifikasi poin 11). Temuan fatal nahan
     * approve tanpa syarat; peringatan nahan sekali, dan admin harus lanjut
     * secara sadar dengan `abaikan_peringatan: true`.
     */
    public function approve(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibration);

        if ($calibration->status !== CalibrationSession::STATUS_MENUNGGU_APPROVAL) {
            return response()->json([
                'message' => 'Cuma sesi yang statusnya `menunggu_approval` yang bisa disetujui.',
            ], 422);
        }

        // Angka hasil OCR yang belum dikonfirmasi manusia nggak boleh ikut
        // disertifikasi — itu inti "kamera mempercepat, bukan menggantikan
        // verifikasi". Teknisi verifikasi dulu lewat endpoint measurements/verify.
        // Dicek duluan (di luar validator) supaya pesannya spesifik & langsung
        // nunjuk ke tindakan yang harus dilakuin.
        if ($calibration->rawMeasurements()->where('is_verified', false)->exists()) {
            return response()->json([
                'message' => 'Masih ada pembacaan hasil pindai (AI Vision) yang belum diverifikasi. Verifikasi dulu sebelum disetujui.',
            ], 422);
        }

        $periksa = $this->validator->periksa($calibration);
        $abaikan = $request->boolean('abaikan_peringatan');

        if (! $periksa['boleh_terbit']) {
            return response()->json([
                'message' => 'Ada masalah di data sesi ini yang bikin sertifikatnya nggak bisa diterbitin.',
                'validasi' => $periksa,
            ], 422);
        }

        if (! $periksa['valid'] && ! $abaikan) {
            return response()->json([
                'message' => 'Hasil hitung ulang beda dari yang tersimpan. Periksa dulu; '
                    .'kalau memang mau lanjut, kirim ulang dengan `abaikan_peringatan: true`.',
                'butuh_konfirmasi' => true,
                'validasi' => $periksa,
            ], 422);
        }

        // Sesi FAIL tetap boleh disetujui — hasil FAIL itu temuan yang sah dan
        // sertifikatnya tetap terbit (isinya "tidak laik pakai"). Yang beda cuma
        // keputusannya, bukan boleh/nggaknya terbit.
        $calibration->update([
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'catatan_revisi' => null,
        ]);

        // Generate sertifikat jalan di queue (async) — bikin PDF bisa lama, jadi
        // `certificate_id` boleh masih null sesaat sesudah approve.
        GenerateCertificate::dispatch($calibration->id, $request->user()->id);

        $segar = $calibration->fresh()->load(self::RELASI);
        $this->kabarinTeknisi($segar, SesiDisetujui::dariSesi($segar));

        return response()->json([
            'data' => new CalibrationResource($segar),
            'validasi' => $periksa,
        ]);
    }

    /**
     * Lembar PERHITUNGAN — tampilan sisi admin dari lembar kerja yang dikirim
     * teknisi. Identitas alat & customer, perhitungan kondisi lingkungan, dan
     * tabel hasil sebelum/sesudah adjustment lengkap dengan Average, Correction,
     * dan STDEV.
     *
     * Admin doang: teknisi ngisi lembar kerja, admin yang ngolah. Angkanya
     * TURUNAN semua — nggak ada yang diketik ulang di sini.
     */
    public function perhitungan(Request $request, CalibrationSession $calibration, PerhitunganBuilder $builder): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibration);

        return response()->json(['data' => $builder->bangun($calibration)]);
    }

    /**
     * Hasil pemeriksaan tanpa nyetujuin apa-apa — buat tombol "Periksa" di
     * panel admin, biar admin bisa lihat temuannya sebelum mutusin.
     */
    public function validasi(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibration);

        return response()->json(['data' => $this->validator->periksa($calibration)]);
    }

    /**
     * Field administratif sertifikat — admin doang (spesifikasi poin 1 & 12A).
     *
     * Dipisah dari `update()` karena beda aturannya: `update()` cuma boleh buat
     * sesi draft/perlu_revisi dan bakal ngitung ulang seluruh pengukuran, sedang
     * yang ini cuma nyentuh kolom administratif dan tetap boleh dipakai waktu
     * sesi udah nunggu approval. Sesi yang udah DISETUJUI tetap dikunci —
     * sertifikatnya udah terbit.
     */
    public function updateAdminFields(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibration);

        if ($calibration->status === CalibrationSession::STATUS_DISETUJUI) {
            return response()->json([
                'message' => 'Sesi yang udah disetujui nggak bisa diubah — sertifikatnya udah terbit.',
            ], 422);
        }

        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'nomor_order' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tanggal_terima' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.$calibration->tanggal_kalibrasi?->toDateString()],
            'calibration_method_id' => [
                'sometimes', 'nullable',
                Rule::exists('calibration_methods', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'thermohygro_standard_id' => [
                'sometimes', 'nullable',
                Rule::exists('standards', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'room_id' => [
                'sometimes', 'nullable',
                Rule::exists('rooms', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'suhu_ruang' => ['sometimes', 'nullable', 'numeric'],
            'suhu_ketidakpastian' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'kelembaban' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'kelembaban_ketidakpastian' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $calibration->update($data);

        // Admin baru milih thermohygro-nya di sini — koreksi & U95% kondisi
        // lingkungan baru bisa dihitung sekarang.
        $this->kondisi->terapkan($calibration->fresh()->load('thermohygro'));

        return response()->json([
            'data' => new CalibrationResource($calibration->fresh()->load(self::RELASI)),
        ]);
    }

    /** Admin doang (dijaga `role:admin` di routes). */
    public function reject(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibration);

        $data = $request->validate([
            'catatan_revisi' => ['required', 'string', 'min:5'],
        ], [
            'catatan_revisi.required' => 'Catatan revisi wajib diisi — teknisi perlu tahu apa yang harus dibenerin.',
        ]);

        if ($calibration->status !== CalibrationSession::STATUS_MENUNGGU_APPROVAL) {
            return response()->json([
                'message' => 'Cuma sesi yang statusnya `menunggu_approval` yang bisa ditolak.',
            ], 422);
        }

        $calibration->update([
            'status' => CalibrationSession::STATUS_PERLU_REVISI,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'catatan_revisi' => $data['catatan_revisi'],
        ]);

        $segar = $calibration->fresh()->load(self::RELASI);
        $this->kabarinTeknisi($segar, SesiPerluRevisi::dariSesi($segar));

        return response()->json([
            'data' => new CalibrationResource($segar),
        ]);
    }

    /**
     * Upload foto display alat buat pembacaan hasil OCR. Balikin `photo_path`
     * yang lalu dirujuk di payload `measurements[].ocr[].photo_path` waktu submit.
     * Dipisah dari submit biar submit-nya tetap JSON murni & fotonya bisa dicicil.
     *
     * Disk `local` (privat) — foto ini bukti audit, bukan konsumsi publik.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'photo.required' => 'Fotonya wajib ada.',
            'photo.image' => 'File-nya harus berupa gambar.',
            'photo.max' => 'Foto maksimal 8 MB.',
        ]);

        $path = $request->file('photo')->store('measurements', 'local');

        return response()->json(['data' => ['photo_path' => $path]], 201);
    }

    /**
     * Konfirmasi pembacaan hasil OCR: tandain `is_verified = true`. Tanpa ini,
     * sesi yang ada pembacaan OCR-nya nggak bisa di-approve (lihat `approve`).
     *
     * `measurement_ids` opsional — kalau dikirim, cuma baris itu yang diverifikasi
     * (teknisi ngonfirmasi satu-satu); kalau kosong, semua pembacaan sesi ini
     * yang belum terverifikasi langsung ditandai (teknisi udah nyocokin semua).
     *
     * Ini murni "iya, angkanya udah bener" — kalau OCR salah baca, teknisi
     * betulin lewat PUT /calibrations/{id} (angka baru masuk sebagai manual).
     */
    public function verifyMeasurements(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanBolehLihat($request, $calibration);

        abort_if(
            $calibration->teknisi_id !== $request->user()->id && ! $request->user()->isAdmin(),
            403,
            'Cuma teknisi yang ngerjain sesi ini yang boleh verifikasi pembacaannya.',
        );

        $data = $request->validate([
            'measurement_ids' => ['sometimes', 'array'],
            'measurement_ids.*' => ['integer'],
        ]);

        $query = $calibration->rawMeasurements()->where('is_verified', false);

        if (! empty($data['measurement_ids'])) {
            $query->whereIn('id', $data['measurement_ids']);
        }

        $jumlah = $query->update(['is_verified' => true]);

        return response()->json([
            'data' => new CalibrationResource($calibration->fresh()->load([...self::RELASI, 'rawMeasurements'])),
            'meta' => ['diverifikasi' => $jumlah],
        ]);
    }

    /**
     * Simpen pembacaan mentah + hasil hitung GUM-nya. Dipakai waktu bikin sesi
     * baru dan waktu revisi — makanya yang lama dihapus dulu, biar nggak numpuk.
     */
    private function isiUlangPengukuran(CalibrationSession $sesi, CalibrationRequest $request): CalibrationSession
    {
        $this->simpanUsageCheck($sesi, $request);

        // Payload tanpa kunci `measurements` sama sekali = teknisi cuma nyimpen
        // bagian header lembar kerja. Yang udah kecatat jangan dihapus.
        if (! $request->has('measurements')) {
            return $this->tutupPengisian($sesi, $request, $sesi->uncertaintyCalculations()->get());
        }

        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $alat = Equipment::findOrFail($request->integer('equipment_id'));
        $standarDefault = $request->filled('standard_id')
            ? Standard::findOrFail($request->integer('standard_id'))
            : null;

        // Sebagian kategori alat (mis. pH) butuh standar beda per titik ukur
        // (buffer 4/7/10) — dimuat sekaligus di sini biar nggak query per titik.
        $standarPerTitik = Standard::whereIn(
            'id',
            array_filter(array_column($request->input('measurements', []), 'standard_id')),
        )->get()->keyBy('id');

        // Angka yang datang dari KAMERA (AI Vision — atau OCR versi lama) butuh
        // verifikasi manusia, walau metadata per-pembacaan (foto/skor) nggak
        // dikirim. Metadata cuma nambah jejak audit; yang nentuin "perlu
        // diverifikasi" ya asal-kamera-nya.
        $metodeInput = (string) $request->string('input_method', 'manual');
        $sesiKamera = in_array($metodeInput, ['ocr', 'ai_vision'], true);
        // Nilai yang disimpen di kolom input_source: kalau metodenya bukan
        // kamera tapi ada metadata per-pembacaan (app lama), anggap 'ocr'.
        $sumberKamera = $sesiKamera ? $metodeInput : 'ocr';
        $satuanDefault = $alat->satuan;

        foreach (array_values($request->input('measurements', [])) as $index => $titik) {
            $titikKe = $index + 1;
            $satuan = $titik['satuan'] ?? $satuanDefault;
            // Metadata OCR sejajar per-index sama pembacaan — boleh nggak ada
            // (input manual). Divalidasi panjangnya di CalibrationRequest.
            $ocr = array_values($titik['ocr'] ?? []);
            $suhu = array_values($titik['suhu'] ?? []);

            // Sel kosong di lembar kerja tetap kekirim (sebagai null) supaya
            // nomor pengulangannya nggak geser. Yang disimpen cuma yang keisi.
            foreach (array_values($titik['pembacaan'] ?? []) as $urutan => $nilai) {
                if ($nilai === null || $nilai === '') {
                    continue;
                }

                $meta = $ocr[$urutan] ?? null;
                $dariKamera = $meta !== null || $sesiKamera;

                $sesi->rawMeasurements()->create([
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $urutan + 1,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $titik['titik_ukur'],
                    'pembacaan' => $nilai,
                    'suhu' => $suhu[$urutan] ?? null,
                    'satuan' => $satuan,
                    'input_source' => $dariKamera ? $sumberKamera : 'manual',
                    'photo_path' => $meta['photo_path'] ?? null,
                    'ocr_confidence' => $meta['confidence'] ?? null,
                    'ocr_raw_text' => $meta['raw_text'] ?? null,
                    // Input manual: yang ngetik manusianya sendiri, langsung
                    // terverifikasi. Hasil AI Vision: kamera cuma mempercepat
                    // input — angkanya WAJIB dikonfirmasi manusia (endpoint
                    // verify) dulu sebelum sesi bisa disetujui.
                    'is_verified' => ! $dariKamera,
                ]);
            }

            // As-found (sebelum adjustment) — dokumentasi kondisi alat doang,
            // TIDAK ikut GumCalculator::hitungTitik() di bawah. Selalu manual
            // (belum ada jalur OCR buat state ini) & langsung terverifikasi.
            $suhuSebelum = array_values($titik['suhu_sebelum'] ?? []);

            foreach (array_values($titik['pembacaan_sebelum'] ?? []) as $urutan => $nilai) {
                if ($nilai === null || $nilai === '') {
                    continue;
                }

                $sesi->rawMeasurements()->create([
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $urutan + 1,
                    'tahap' => 'sebelum_adjustment',
                    'titik_ukur' => $titik['titik_ukur'],
                    'pembacaan' => $nilai,
                    'suhu' => $suhuSebelum[$urutan] ?? null,
                    'satuan' => $satuan,
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            // CalibrationRequest udah validasi standard_id per titik itu ada &
            // masih berlaku — kalau sampe nggak ketemu di sini (standar
            // dihapus di antara validasi & baris ini, atau endpoint lain
            // manggil isiUlangPengukuran tanpa lewat CalibrationRequest),
            // gagal keras. Diam-diam pakai standar default sesi bakal nyimpen
            // hasil hitung yang ngaku dihitung pakai standar yang salah.
            $standarTitik = $standarDefault;

            if (isset($titik['standard_id'])) {
                $standarTitik = $standarPerTitik->get($titik['standard_id']);

                abort_if(
                    $standarTitik === null,
                    422,
                    "Standar acuan titik ke-{$titikKe} nggak ketemu — mungkin baru dihapus.",
                );
            }

            $pembacaanTerisi = $sesi->rawMeasurements()
                ->where('titik_ke', $titikKe)
                ->where('tahap', 'sesudah_adjustment')
                ->orderBy('pembacaan_ke')
                ->pluck('pembacaan')
                ->map(fn ($nilai): float => (float) $nilai)
                ->all();

            // Titik yang datanya belum cukup TETAP disimpen mentah, cuma nggak
            // dihitung. Ini yang bikin lembar kerja setengah jadi boleh dikirim
            // dari lapangan tanpa maksa sistem ngarang angka: yang nahan bukan
            // tombol kirim, tapi penerbitan sertifikatnya (CalibrationValidator).
            if (! $this->bisaDihitung($pembacaanTerisi, $alat, $standarTitik)) {
                continue;
            }

            $sesi->uncertaintyCalculations()->create($this->gum->hitungTitik(
                $titikKe,
                (float) $titik['titik_ukur'],
                $pembacaanTerisi,
                $alat,
                $standarTitik,
            ));
        }

        return $this->tutupPengisian($sesi, $request, $sesi->uncertaintyCalculations()->get());
    }

    /**
     * Syarat minimal biar satu titik bisa dihitung ketidakpastiannya.
     *
     * - Pengulangan minimal 2: standar deviasi (Type A) nggak ada artinya dari
     *   satu angka.
     * - Standar acuan harus ada: ketidakpastiannya komponen Type B terbesar.
     * - Toleransi alat harus ada: tanpa batas pembanding, PASS/FAIL nggak punya
     *   dasar sama sekali.
     *
     * @param  list<float>  $pembacaan
     */
    private function bisaDihitung(array $pembacaan, Equipment $alat, ?Standard $standar): bool
    {
        return count($pembacaan) >= GumCalculator::MIN_PENGULANGAN
            && $standar !== null
            && $alat->toleransi !== null;
    }

    /**
     * Kolom "Usage Check" di lembar kerja. Nggak dikirim = nggak diapa-apain,
     * biar simpan-header doang nggak ngehapus centang yang udah ada.
     */
    private function simpanUsageCheck(CalibrationSession $sesi, CalibrationRequest $request): void
    {
        if (! $request->has('standar_dicek')) {
            return;
        }

        $pivot = [];

        foreach ((array) $request->input('standar_dicek', []) as $baris) {
            $pivot[(int) $baris['standard_id']] = [
                'dipakai' => (bool) ($baris['dipakai'] ?? true),
                'keterangan' => $baris['keterangan'] ?? null,
            ];
        }

        $sesi->standarDicek()->sync($pivot);
    }

    /**
     * Tutup pengisian: tentuin keputusan sesi & statusnya.
     *
     * Keputusan sesi `null` kalau nggak ada satu pun titik yang kehitung —
     * lembar kerja yang masih setengah nggak punya hasil, dan nulis "PASS" di
     * situ jauh lebih berbahaya daripada ngosongin.
     *
     * @param  Collection<int, UncertaintyCalculation>  $titik
     */
    private function tutupPengisian(
        CalibrationSession $sesi,
        CalibrationRequest $request,
        $titik,
    ): CalibrationSession {
        $draft = $request->string('status')->value() === CalibrationSession::STATUS_DRAFT;

        $sesi->update([
            'keputusan' => match (true) {
                $titik->isEmpty() => null,
                // Satu titik FAIL bikin seluruh sesi FAIL.
                $titik->contains('keputusan', 'FAIL') => 'FAIL',
                default => 'PASS',
            },
            'status' => $request->string('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL),
            'submitted_at' => $draft ? null : now(),
        ]);

        // Kondisi lingkungan yang dicetak di sertifikat dihitung ulang tiap
        // sesi disimpen — biar nggak pernah ketinggalan dari pembacaan
        // awal/akhir yang barusan diubah.
        $this->kondisi->terapkan($sesi);

        $segar = $sesi->fresh()->load(self::RELASI);

        // Draft nggak ngabarin siapa-siapa — belum masuk antrean approval.
        $this->kabarinAdmin($segar);

        return $segar;
    }

    /**
     * Atribut sesi yang datang dari payload teknisi/admin. Dipakai `store()`
     * sama `update()` biar dua-duanya nggak bisa beda perlakuan.
     *
     * Field administratif cuma ditulis kalau BENERAN dikirim. Kalau nggak,
     * revisi dari teknisi bakal ngosongin nomor order yang udah diisi admin —
     * padahal payload teknisi emang nggak pernah bawa field itu.
     *
     * @return array<string, mixed>
     */
    private function atributDariRequest(CalibrationRequest $request): array
    {
        $atribut = [
            'equipment_id' => $request->integer('equipment_id'),
            'standard_id' => $request->filled('standard_id') ? $request->integer('standard_id') : null,
            'input_method' => $request->string('input_method', 'manual'),
            'lokasi' => $request->string('lokasi', 'lab'),
            'tanggal_kalibrasi' => $request->date('tanggal_kalibrasi'),
            'tanggal_terima' => $request->date('tanggal_terima'),
            // Angka yang DICETAK di sertifikat. Biasanya nggak dikirim mobile —
            // dihitung dari pembacaan awal/akhir + koreksi sertifikat
            // thermohygro sesudah sesi disimpen (lihat KondisiLingkungan).
            'suhu_ruang' => $request->input('suhu_ruang'),
            'kelembaban' => $request->input('kelembaban'),
        ];

        $opsional = [
            ...CalibrationSession::fieldAdmin(),
            'room_id', 'suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir', 'catatan_teknisi',
        ];

        foreach ($opsional as $field) {
            if ($request->has($field)) {
                $atribut[$field] = $request->input($field);
            }
        }

        return $atribut;
    }

    /**
     * Kabarin teknisi yang ngerjain sesi. Admin yang ngerjain sesinya sendiri
     * nggak perlu dikabarin soal tindakannya sendiri.
     */
    private function kabarinTeknisi(CalibrationSession $sesi, object $notifikasi): void
    {
        $teknisi = $sesi->teknisi;

        if ($teknisi === null || $teknisi->id === request()->user()?->id) {
            return;
        }

        $teknisi->notify($notifikasi);
    }

    /** Kabarin semua admin aktif kalau ada sesi baru masuk antrean approval. */
    private function kabarinAdmin(CalibrationSession $sesi): void
    {
        if ($sesi->status !== CalibrationSession::STATUS_MENUNGGU_APPROVAL) {
            return;
        }

        $admin = User::query()
            ->where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_AKTIF)
            ->where('id', '!=', $sesi->teknisi_id)
            ->get();

        $notifikasi = SesiMenungguApproval::dariSesi($sesi);

        foreach ($admin as $user) {
            $user->notify($notifikasi);
        }
    }

    /** Nomor sesi urut per organisasi per bulan: KAL/2026/07/0001. */
    private function nomorSesiBerikutnya(int $organizationId): string
    {
        $prefix = sprintf('KAL/%s/', now()->format('Y/m'));

        $urutanTerakhir = CalibrationSession::where('organization_id', $organizationId)
            ->where('nomor_sesi', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('nomor_sesi')
            ->value('nomor_sesi');

        $urutan = $urutanTerakhir ? ((int) substr($urutanTerakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Teknisi cuma boleh buka sesi miliknya sendiri. Tanpa ini, `GET /calibrations`
     * udah difilter tapi `GET /calibrations/{id}` masih bocor — tinggal tebak ID.
     */
    private function pastikanBolehLihat(Request $request, CalibrationSession $sesi): void
    {
        $this->pastikanSatuOrganisasi($request, $sesi);

        $user = $request->user();

        abort_if(
            $user->role === User::ROLE_TEKNISI && $sesi->teknisi_id !== $user->id,
            404,
        );
    }

    /** Jaring pengaman multi-tenant: PT lain nggak boleh bisa baca sesi kita. */
    private function pastikanSatuOrganisasi(Request $request, CalibrationSession $sesi): void
    {
        abort_if($sesi->organization_id !== $request->user()->organization_id, 404);
    }
}
