<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalibrationRequest;
use App\Http\Resources\CalibrationResource;
use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Folder;
use App\Models\Standard;
use App\Models\User;
use App\Services\GumCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Sesi kalibrasi: draft → menunggu_approval → disetujui / perlu_revisi.
 *
 * Data dari input manual dan dari hasil scan kamera masuk ke endpoint yang SAMA —
 * bedanya cuma `input_method`, buat statistik, bukan buat logic beda. Nggak ada
 * endpoint terpisah buat OCR.
 */
class CalibrationController extends Controller
{
    public function __construct(private readonly GumCalculator $gum) {}

    /** Relasi yang selalu dibutuhin CalibrationResource. */
    private const RELASI = ['equipment', 'teknisi', 'standard', 'uncertaintyCalculations.standard', 'certificate'];

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
                'organization_id' => $request->user()->organization_id,
                'equipment_id' => $request->integer('equipment_id'),
                'folder_id' => $this->folderOtomatis($request->integer('equipment_id')),
                'standard_id' => $request->integer('standard_id'),
                'teknisi_id' => $request->user()->id,
                'client_request_id' => $clientRequestId,
                'nomor_sesi' => $this->nomorSesiBerikutnya($request->user()->organization_id),
                'nomor_order' => $request->input('nomor_order'),
                'input_method' => $request->string('input_method', 'manual'),
                'lokasi' => $request->string('lokasi', 'lab'),
                'tanggal_kalibrasi' => $request->date('tanggal_kalibrasi'),
                'tanggal_terima' => $request->date('tanggal_terima'),
                ...$this->dataLingkungan($request),
                'status' => CalibrationSession::STATUS_DRAFT,
            ]);

            return $this->isiUlangPengukuran($sesi, $request);
        });

        return response()->json(['data' => new CalibrationResource($sesi)], 201);
    }

    /**
     * Folder tempat sesi baru jatuh otomatis: folder akar perusahaan pemilik
     * alatnya.
     *
     * Sengaja lewat relasi `equipment.customer_id`, BUKAN cocok-cocokan nama
     * perusahaan. Teknisi milih alat waktu input, dan alat itu udah nempel ke
     * pemiliknya — jadi foldernya nggak mungkin salah. Kalau dicocokin nama,
     * "PT Tirta Gracia" / "PT. Tirta Gracia" / "pt tirta gracia" bakal jadi
     * tiga folder beda, dan sertifikat lab terakreditasi nyasar ke perusahaan
     * lain itu masalah serius.
     *
     * Prakteknya nggak pernah null: `equipments.customer_id` itu NOT NULL,
     * jadi tiap alat pasti punya pelanggan. Null-nya cuma jaga-jaga kalau
     * kolom itu suatu saat dibikin nullable — sesinya tetap kesimpen, cuma
     * belum kefolderin, dan bisa dipindah manual belakangan.
     */
    private function folderOtomatis(int $equipmentId): ?int
    {
        $customer = Equipment::find($equipmentId)?->customer;

        return $customer ? Folder::akarUntuk($customer)->id : null;
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
                'equipment_id' => $request->integer('equipment_id'),
                'standard_id' => $request->integer('standard_id'),
                'nomor_order' => $request->input('nomor_order'),
                'input_method' => $request->string('input_method', 'manual'),
                'lokasi' => $request->string('lokasi', 'lab'),
                'tanggal_kalibrasi' => $request->date('tanggal_kalibrasi'),
                'tanggal_terima' => $request->date('tanggal_terima'),
                ...$this->dataLingkungan($request),
                // Begitu direvisi & disubmit ulang, catatan revisi lama nggak
                // relevan lagi — jangan sampai teknisi lihat teguran yang udah dibenerin.
                'catatan_revisi' => null,
            ]);

            return $this->isiUlangPengukuran($calibration, $request);
        });

        return response()->json(['data' => new CalibrationResource($sesi)]);
    }

    /** Admin doang (dijaga `role:admin` di routes). */
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
        if ($calibration->rawMeasurements()->where('is_verified', false)->exists()) {
            return response()->json([
                'message' => 'Masih ada pembacaan hasil OCR yang belum diverifikasi. Verifikasi dulu sebelum disetujui.',
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

        return response()->json([
            'data' => new CalibrationResource($calibration->fresh()->load(self::RELASI)),
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
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $alat = Equipment::findOrFail($request->integer('equipment_id'));
        $standarDefault = Standard::findOrFail($request->integer('standard_id'));

        // Sebagian kategori alat (mis. pH) butuh standar beda per titik ukur
        // (buffer 4/7/10) — dimuat sekaligus di sini biar nggak query per titik.
        $standarPerTitik = Standard::whereIn(
            'id',
            array_filter(array_column($request->input('measurements'), 'standard_id')),
        )->get()->keyBy('id');

        $keputusanSesi = 'PASS';
        // Seluruh sesi ditandai OCR: tiap pembacaannya butuh verifikasi manusia,
        // walau metadata per-pembacaan (foto/skor) nggak dikirim. Metadata cuma
        // nambah jejak audit; yang nentuin "perlu diverifikasi" ya asal OCR-nya.
        $sesiOcr = (string) $request->string('input_method', 'manual') === 'ocr';

        foreach (array_values($request->input('measurements')) as $index => $titik) {
            $titikKe = $index + 1;
            $pembacaan = array_map(floatval(...), array_values($titik['pembacaan']));
            // Metadata OCR sejajar per-index sama pembacaan — boleh nggak ada
            // (input manual). Divalidasi panjangnya di CalibrationRequest.
            $ocr = array_values($titik['ocr'] ?? []);
            // Suhu larutan tiap pembacaan (worksheet pH nyatet pasangan pH/°C).
            // Sejajar per-index sama pembacaan; boleh kosong buat alat non-pH.
            $suhu = array_values($titik['suhu'] ?? []);
            $suhuSebelum = array_values($titik['suhu_sebelum'] ?? []);

            foreach ($pembacaan as $urutan => $nilai) {
                $meta = $ocr[$urutan] ?? null;
                $dariOcr = $meta !== null || $sesiOcr;

                $sesi->rawMeasurements()->create([
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $urutan + 1,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $titik['titik_ukur'],
                    'pembacaan' => $nilai,
                    'suhu' => isset($suhu[$urutan]) ? (float) $suhu[$urutan] : null,
                    'satuan' => $titik['satuan'],
                    'input_source' => $dariOcr ? 'ocr' : 'manual',
                    'photo_path' => $meta['photo_path'] ?? null,
                    'ocr_confidence' => $meta['confidence'] ?? null,
                    'ocr_raw_text' => $meta['raw_text'] ?? null,
                    // Input manual: yang ngetik manusianya sendiri, langsung
                    // terverifikasi. Hasil OCR: kamera cuma mempercepat input —
                    // angkanya WAJIB dikonfirmasi manusia (endpoint verify) dulu
                    // sebelum sesi bisa disetujui.
                    'is_verified' => ! $dariOcr,
                ]);
            }

            // As-found (sebelum adjustment). Buat pH, tahap ini juga diringkas
            // (rata-rata/koreksi/STDEV) di sertifikat — tapi TIDAK ikut budget
            // GUM resmi di bawah (yang disertifikasi kondisi sesudah adjustment).
            // Nilai standar bisa beda tipis dari sesudah (koreksi suhu larutan),
            // jadi `titik_ukur_sebelum` dipakai kalau dikirim.
            $titikUkurSebelum = $titik['titik_ukur_sebelum'] ?? $titik['titik_ukur'];
            foreach (array_values($titik['pembacaan_sebelum'] ?? []) as $urutan => $nilai) {
                $sesi->rawMeasurements()->create([
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $urutan + 1,
                    'tahap' => 'sebelum_adjustment',
                    'titik_ukur' => $titikUkurSebelum,
                    'pembacaan' => $nilai,
                    'suhu' => isset($suhuSebelum[$urutan]) ? (float) $suhuSebelum[$urutan] : null,
                    'satuan' => $titik['satuan'],
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

            $hasil = $this->gum->hitungTitik(
                $titikKe,
                (float) $titik['titik_ukur'],
                $pembacaan,
                $alat,
                $standarTitik,
            );

            $sesi->uncertaintyCalculations()->create($hasil);

            // Satu titik FAIL bikin seluruh sesi FAIL.
            if ($hasil['keputusan'] === 'FAIL') {
                $keputusanSesi = 'FAIL';
            }
        }

        $sesi->update([
            'keputusan' => $keputusanSesi,
            'status' => $request->string('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL),
            'submitted_at' => $request->string('status')->value() === CalibrationSession::STATUS_DRAFT
                ? null
                : now(),
        ]);

        return $sesi->fresh()->load(self::RELASI);
    }

    /**
     * Rakit field kondisi lingkungan buat disimpen di sesi. Rata-rata & U95%
     * DIHITUNG server dari awal/akhir + U95% thermohygro — biar mobile nggak
     * ngitung sendiri (gampang salah). Kalau awal/akhir nggak dikirim, jatuh
     * balik ke `suhu_ruang`/`kelembaban` yang dikirim langsung (kompatibel mundur).
     *
     * @return array<string, mixed>
     */
    private function dataLingkungan(Request $request): array
    {
        $suhuAwal = $request->input('suhu_ruang_awal');
        $suhuAkhir = $request->input('suhu_ruang_akhir');
        $rhAwal = $request->input('kelembaban_awal');
        $rhAkhir = $request->input('kelembaban_akhir');

        $rata = fn ($awal, $akhir, $fallback) => ($awal !== null && $akhir !== null)
            ? ((float) $awal + (float) $akhir) / 2
            : $fallback;

        // U95% lingkungan dihitung kalau awal/akhir + U thermohygro (`*_u_std`)
        // dikirim; kalau nggak, pakai `*_u95` yang dikirim langsung (atau null).
        $u95 = fn ($awal, $akhir, $uStd, $langsung) => ($awal !== null && $akhir !== null && $uStd !== null)
            ? $this->gum->ketidakpastianLingkungan((float) $awal, (float) $akhir, (float) $uStd)
            : $langsung;

        return [
            'suhu_ruang' => $rata($suhuAwal, $suhuAkhir, $request->input('suhu_ruang')),
            'kelembaban' => $rata($rhAwal, $rhAkhir, $request->input('kelembaban')),
            'suhu_ruang_awal' => $suhuAwal,
            'suhu_ruang_akhir' => $suhuAkhir,
            'kelembaban_awal' => $rhAwal,
            'kelembaban_akhir' => $rhAkhir,
            'suhu_ruang_koreksi' => $request->input('suhu_ruang_koreksi'),
            'kelembaban_koreksi' => $request->input('kelembaban_koreksi'),
            'suhu_ruang_u95' => $u95($suhuAwal, $suhuAkhir, $request->input('suhu_ruang_u_std'), $request->input('suhu_ruang_u95')),
            'kelembaban_u95' => $u95($rhAwal, $rhAkhir, $request->input('kelembaban_u_std'), $request->input('kelembaban_u95')),
            'thermohygro' => $request->input('thermohygro'),
        ];
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
