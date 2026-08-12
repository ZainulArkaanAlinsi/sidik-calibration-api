<?php

namespace App\Http\Controllers\Api;

use App\Events\PerubahanDataOrganisasi;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalibrationRequest;
use App\Http\Resources\CalibrationResource;
use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Notifications\SesiDisetujui;
use App\Notifications\SesiMenungguApproval;
use App\Notifications\SesiPerluRevisi;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\CalibrationValidator;
use App\Services\FolderOrganizer;
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use App\Services\PerhitunganBuilder;
use App\Services\RumusKalibrasi;
use Carbon\Carbon;
// Relasi tiruan di `preview()` HARUS Eloquent Collection, bukan Support Collection:
// `loadMissing('uncertaintyCalculations.standard')` di PerhitunganBuilder butuh
// method `load()` yang cuma ada di Eloquent Collection.
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        private readonly FolderOrganizer $folder,
        private readonly CalibrationProfileRegistry $profil,
    ) {}

    /**
     * Relasi yang selalu dibutuhin CalibrationResource.
     *
     * `reviewer` ikut karena resource-nya nampilin "Checked by" — tanpa dimuat di
     * sini, daftar sesi jadi satu query tambahan per baris.
     */
    private const RELASI = [
        'equipment.customer', 'teknisi', 'reviewer', 'standard', 'thermohygro', 'standarDicek',
        'calibrationMethod', 'room', 'uncertaintyCalculations.standard', 'certificate',
    ];

    /**
     * Presisi kolom `raw_measurements` & `uncertainty_calculations`.
     *
     * Angka dibulatin di sini SEBELUM masuk DB, biar `POST /calibrations/preview`
     * (yang nggak lewat DB) dan sesi tersimpan ngasih angka yang sama. Kalau
     * presisi kolomnya diubah di migrasi, ubah di sini juga —
     * `CalibrationPreviewTest::test_angka_preview_identik_sama_angka_yang_tersimpan`
     * yang bakal ngasih tahu kalau kelewat.
     */
    private const DESIMAL_PEMBACAAN = 8;   // decimal(20, 8)

    private const DESIMAL_SUHU = 2;        // decimal(8, 2)

    // `k` bukan konstanta 2 lagi: sejak budget penuh jalan dia dihitung dari
    // t-student pada derajat kebebasan efektif (mis. 1.97065259 buat veff
    // 223.13). Dibulatkan 2 desimal, yang kesimpen jadi 1.97 — dan siapa pun
    // yang ngalikan `k × u_c` dari baris itu dapat angka yang beda dari `U`
    // yang beneran dilaporkan. Kolomnya udah dinaikin ke decimal(12,8).
    private const DESIMAL_K = 8;           // faktor_cakupan_k: decimal(12, 8)

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
     *
     * Bentuknya juga beda per JENIS ALAT: `?instrumen=Turbidimeter` (atau
     * `?profil=turbidimeter`) milih profilnya. Default pH — mobile lama yang
     * belum ngirim param tetap dapat lembar pH persis kayak sebelumnya.
     */
    public function lembarKerja(Request $request, CalibrationProfileRegistry $registry): JsonResponse
    {
        $request->validate([
            // Berapa KOTAK pengulangan yang digambar. Nggak dikirim = pakai
            // bawaan profilnya (5, ngikut form kertas).
            'pengulangan' => [
                'sometimes', 'integer',
                'between:'.CalibrationProfile::MIN_KOLOM_PENGULANGAN.','.CalibrationProfile::MAKS_KOLOM_PENGULANGAN,
            ],
            // Alat yang bakal dikalibrasi, kalau layar udah tahu.
            //
            // Bikin lembarnya ngikut ALAT PELANGGAN, bukan cuma jenis alatnya:
            // conductivity meter nampilin µS/cm atau mS/cm sendiri-sendiri per
            // titik, dan sertifikatnya wajib ikut yang tampil di layar alatnya
            // (arahan lab 11 Agt 2026). Tanpa param ini lembarnya tetap keluar
            // — cuma pakai satuan bawaan profil, dan layar yang harus nawarin
            // dua varian buat dipilih.
            'equipment_id' => [
                'sometimes', 'integer',
                Rule::exists('equipments', 'id')
                    ->where('organization_id', $request->user()->organization_id)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'pengulangan.between' => 'Kolom pengulangan cuma boleh '
                .CalibrationProfile::MIN_KOLOM_PENGULANGAN.'–'.CalibrationProfile::MAKS_KOLOM_PENGULANGAN
                .'. Di bawah 2 standar deviasi nggak bisa dihitung.',
        ]);

        $alat = $request->filled('equipment_id')
            ? Equipment::find($request->integer('equipment_id'))
            : null;

        // Alat yang dikirim MENANG buat milih profil — dia lebih spesifik
        // daripada nama jenis alat yang diketik pemanggil.
        $kode = (string) $request->string('profil', '');
        $profil = ($alat !== null ? $registry->untukAlat($alat) : null)
            ?? ($kode !== '' ? $registry->untukKode($kode) : null)
            ?? $registry->untukNamaAlat((string) $request->string('instrumen', 'pH Meter'));

        $bentuk = $profil->bentukLembarKerja(
            untukAdmin: $request->user()->isAdmin(),
            equipment: $alat,
        );

        // Alat udah ditunjuk tapi lembarnya MASIH nyodorin dua varian satuan
        // buat titik yang sama — artinya master alatnya belum bilang varian
        // mana yang dipakai, dan yang bakal milih jadinya teknisi.
        //
        // Itu keputusan yang bukan haknya. Varian satuan nentuin style
        // sertifikat, dan salah pilih bikin nilai acuannya meleset 1000×:
        // 1413 di baris `1,412 mS/cm` keluar Correction -1411,588 (sesi 53,
        // 12 Agt 2026). Waktu alatnya masih sedikit, orang lab hafal mana yang
        // benar; begitu alatnya banyak, nggak ada yang tau.
        //
        // Dicek dari BENTUKNYA — ada baris yang saling `eksklusif_dengan` —
        // bukan dari nama profil, jadi profil baru yang punya varian ikut
        // kejaga tanpa nyentuh baris ini.
        if ($alat !== null && self::adaVarianBelumDitentukan($bentuk)) {
            return response()->json([
                'message' => 'Alat ini punya titik dengan dua varian satuan, dan master alatnya belum '
                    .'nyebut yang mana. Isi "Resolusi per titik" di data Alat dulu — satuan yang kepakai '
                    .'itu properti alat pelanggan, bukan pilihan teknisi di lapangan.',
            ], 422);
        }

        if ($request->filled('pengulangan')) {
            $bentuk = CalibrationProfile::setelKolomPengulangan($bentuk, $request->integer('pengulangan'));
        }

        return response()->json(['data' => $bentuk]);
    }

    /**
     * Bentuk lembar masih nyisain dua baris yang saling meniadakan?
     *
     * `eksklusif_dengan` nunjuk ke titik ukur pasangannya. Kalau dua-duanya
     * masih ada di bentuk yang UDAH disusutin ke alat, penyusutannya nggak
     * kejadian — master alatnya nggak punya keterangan buat milih.
     *
     * @param  array<string, mixed>  $bentuk
     */
    private static function adaVarianBelumDitentukan(array $bentuk): bool
    {
        $titik = [];
        $pasangan = [];

        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $tabel) {
                foreach ($tabel['baris'] ?? [] as $baris) {
                    $titik[(string) (float) $baris['titik_ukur']] = true;

                    if (($baris['eksklusif_dengan'] ?? null) !== null) {
                        $pasangan[] = (string) (float) $baris['eksklusif_dengan'];
                    }
                }
            }
        }

        foreach ($pasangan as $p) {
            if (isset($titik[$p])) {
                return true;
            }
        }

        return false;
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
            // Duluan sebelum pengukurannya disusun: `isiUlangPengukuran` muter
            // GUM pakai alat yang dibaca ulang dari DB, jadi satuannya mesti
            // udah kesimpen kalau nggak mau hasil hitungnya ikut satuan lama.
            $this->simpanSatuanAlat($request);

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

        $this->siarkan($sesi, 'dibuat');

        return response()->json(['data' => new CalibrationResource($sesi)], 201);
    }

    /**
     * Hitung tanpa nyimpen — buat "hitung sambil ngetik" di lembar kerja
     * (docs/permintaan-worksheet-ph.md §4).
     *
     * Body-nya SAMA PERSIS kayak `POST /calibrations`, jadi mobile nggak perlu
     * bikin payload kedua: kirim draft yang sedang diisi, dapat angkanya, ulangi.
     *
     * Kenapa harus di backend padahal cuma buat dilihat sekilas: kalau HP yang
     * ngitung Average/Correction/STDEV sendiri, angka di layar bisa beda tipis
     * dari yang nanti tercetak di sertifikat (pembulatan, urutan operasi, nilai
     * buffer pada suhu). Buat lab terakreditasi, dua angka beda buat satu
     * pengukuran itu temuan audit. Jadi yang ngitung tetap satu mesin.
     *
     * NGGAK NYIMPEN APA-APA:
     * - nggak ada baris `calibration_sessions` / `raw_measurements` / `uncertainty_calculations`
     * - nggak makan nomor sesi (`nomor_sesi` di-generate cuma waktu `store`)
     * - nggak nyalain notifikasi & nggak nyiarin sinyal realtime
     * - `client_request_id` diabaikan — preview bukan submit, jadi nggak ada
     *   yang perlu di-idempoten-in
     */
    public function preview(CalibrationRequest $request, PerhitunganBuilder $builder): JsonResponse
    {
        $susunan = $this->susunPengukuran($request);

        // Sesi TIRUAN, sengaja nggak pernah disimpen. Relasinya diisi di memori
        // (setRelation) supaya PerhitunganBuilder & KondisiLingkungan bisa jalan
        // di atasnya — dua-duanya cuma baca atribut & koleksi, nggak nyentuh DB
        // buat itu. Kalau relasinya nggak diisi duluan, `loadMissing` di
        // PerhitunganBuilder bakal query `calibration_session_id IS NULL` dan
        // nimpa hasil hitung kita sama koleksi kosong.
        $sesi = new CalibrationSession([
            ...$this->atributDariRequest($request),
            'organization_id' => $request->user()->organization_id,
            'teknisi_id' => $request->user()->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);

        $sesi->setRelation('rawMeasurements', new EloquentCollection(array_map(
            fn (array $baris): RawMeasurement => new RawMeasurement($baris),
            $susunan['mentah'],
        )));

        $sesi->setRelation('uncertaintyCalculations', new EloquentCollection(array_map(
            fn (array $hitungan): UncertaintyCalculation => new UncertaintyCalculation($hitungan),
            $susunan['hitungan'],
        )));

        $titik = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();

        // Keputusan sesi kalau dikirim sekarang. Satu titik FAIL bikin seluruh
        // sesi FAIL — aturan yang sama kayak `tutupPengisian()`.
        $sesi->keputusan = match (true) {
            $titik->isEmpty() => null,
            $titik->contains('keputusan', 'FAIL') => 'FAIL',
            default => 'PASS',
        };

        $perhitungan = $builder->bangun($sesi);

        return response()->json([
            'data' => [
                'keputusan' => $sesi->keputusan,
                // `hasil` & `titik` SENGAJA sama arti + sama bentuk kayak di
                // GET /calibrations/{id} — dua-duanya lewat helper yang sama di
                // CalibrationResource, jadi parser mobile bisa dipakai ulang
                // apa adanya. Jangan diisi hal lain di sini.
                'hasil' => CalibrationResource::petakanHasil($sesi->titikPenentu(), $sesi->keputusan),
                'titik' => $titik
                    // Alat & organisasi ikut dikirim biar `desimal` per titik
                    // ikut kehitung di sini juga — kalau nggak, layar preview
                    // nampilin desimal beda dari sesi tersimpan buat alat yang
                    // resolusinya berubah per rentang (Turbidimeter).
                    ->map(fn (UncertaintyCalculation $t): array => CalibrationResource::petakanTitik(
                        $t,
                        $sesi->equipment,
                        $sesi->organization,
                    ))
                    ->all(),
                // Dua tabel lembar kerja (Before/After adjustment) lengkap sama
                // baris Average, Correction, STDEV, dan MAX STDEV — ini yang
                // diminta worksheet §4 buat "hitung sambil ngetik".
                //
                // Namanya BUKAN `hasil` walau isinya `data.hasil`-nya
                // GET /calibrations/{id}/perhitungan: di sesi tersimpan, key
                // `hasil` artinya ringkasan titik penentu. Satu nama dua arti
                // itu jebakan; jadi di sini dinamain apa adanya.
                'lembar_perhitungan' => $perhitungan['hasil'],
                'kondisi_lingkungan' => $perhitungan['kondisi_lingkungan'],
                // Titik yang belum keluar angkanya + alasannya. Tanpa ini, titik
                // yang ilang dari `titik` kelihatan kayak bug di mata teknisi.
                'belum_dihitung' => $susunan['belum_dihitung'],
            ],
        ]);
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

        // Admin boleh ngedit lembar yang UDAH disubmit teknisi (`menunggu_approval`),
        // teknisi nggak.
        //
        // Sebelum ini status itu ngunci semua orang, jadi admin yang nemu satu
        // angka keliru waktu review cuma punya satu jalan: `reject()` — lembar
        // balik ke teknisi, teknisi benerin, submit ulang, admin review lagi.
        // Buat salah ketik satu digit, itu muter-muter dan bikin lembar bolak-balik
        // cuma buat perbaikan yang admin sendiri udah tahu benernya.
        //
        // Yang dibuka CUMA jendela statusnya. Isi yang boleh diubah tetap lewat
        // `CalibrationRequest` + `atributDariRequest()` yang sama persis kayak
        // jalur teknisi — termasuk field administratif, yang emang cuma kebuka
        // buat admin. Nggak ada kolom yang lolos validasi cuma gara-gara yang
        // ngirim admin.
        //
        // `disetujui` tetap terkunci buat SEMUA orang, admin sekalipun:
        // sertifikatnya udah terbit dan udah kekirim ke pelanggan. Ngubahnya
        // lewat jalur terbitkan-ulang, bukan lewat sini.
        $statusBolehDiubah = [
            CalibrationSession::STATUS_DRAFT,
            CalibrationSession::STATUS_PERLU_REVISI,
        ];

        if ($request->user()->isAdmin()) {
            $statusBolehDiubah[] = CalibrationSession::STATUS_MENUNGGU_APPROVAL;
        }

        if (! in_array($calibration->status, $statusBolehDiubah, true)) {
            return response()->json([
                'message' => $calibration->status === CalibrationSession::STATUS_DISETUJUI
                    ? 'Sesi yang udah disetujui nggak bisa diubah — sertifikatnya udah terbit.'
                    : 'Sesi yang lagi nunggu approval nggak bisa diubah teknisi. Minta admin yang ngedit, atau minta lembarnya dibalikin dulu.',
            ], 422);
        }

        $sesi = DB::transaction(function () use ($request, $calibration): CalibrationSession {
            // Sama kayak `store`: sebelum pengukurannya disusun ulang. Teknisi
            // yang sadar salah pilih satuan lalu ngerevisi sesinya mesti bikin
            // hitungannya ikut berubah, bukan cuma labelnya.
            $this->simpanSatuanAlat($request);

            $calibration->update([
                ...$this->atributDariRequest($request),
                // Begitu direvisi & disubmit ulang, catatan revisi lama nggak
                // relevan lagi — jangan sampai teknisi lihat teguran yang udah dibenerin.
                //
                // `revisi_field` ikut dibuang, dan itu sempat kelewat: catatannya
                // ilang tapi kolom yang ditandai NGGAK, jadi garis merahnya
                // nempel terus di lembar yang udah dibetulin — bahkan sesudah
                // sesinya disetujui.
                'catatan_revisi' => null,
                'revisi_field' => null,
            ]);

            return $this->isiUlangPengukuran($calibration, $request);
        });

        $this->siarkan($sesi, 'diubah');

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

        // Masa berlaku sertifikat itu keputusan admin, bukan angka mati di kode:
        // interval kalibrasi beda-beda per jenis alat & permintaan pelanggan.
        // Kalau nggak dikirim, dipakai default masa berlaku organisasi.
        //
        // `after:tanggal kalibrasi` bukan `after:today` — sertifikat yang
        // berlakunya habis sebelum atau pas hari alat dikerjain itu nggak masuk
        // akal, dan `max` 10 tahun nahan salah ketik tahun (2035 → 2350).
        $data = $request->validate([
            'berlaku_sampai' => [
                'sometimes', 'nullable', 'date',
                'after:'.($calibration->tanggal_kalibrasi?->toDateString() ?? 'today'),
                'before_or_equal:'.now()->addYears(10)->toDateString(),
            ],
        ], [
            'berlaku_sampai.after' => 'Masa berlaku harus sesudah tanggal kalibrasi.',
            'berlaku_sampai.before_or_equal' => 'Masa berlaku kejauhan — maksimal 10 tahun dari sekarang.',
        ]);

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
            'revisi_field' => null,
        ]);

        $job = new GenerateCertificate(
            $calibration->id,
            $request->user()->id,
            filled($data['berlaku_sampai'] ?? null)
                ? Carbon::parse($data['berlaku_sampai'])->toDateString()
                : null,
        );

        // Sertifikatnya dibikin LANGSUNG, bukan dilempar ke antrean.
        //
        // Dulu ini `dispatch()`, dan konsekuensinya nggak kelihatan sampai
        // dipakai beneran: kalau nggak ada `queue:work` yang jalan, approve-nya
        // sukses tapi sertifikatnya nggak pernah terbit — dan dari layar admin
        // itu kelihatan kayak "lagi diproses" selamanya, tanpa error di mana
        // pun. Satu proses yang lupa dinyalain bikin seluruh alur mati diam.
        //
        // Bikin PDF-nya ~1-2 detik; admin nunggu sebentar jauh lebih baik
        // daripada nunggu sesuatu yang nggak akan datang. Kalau nanti volumenya
        // naik dan ada pekerja antrean yang beneran diawasi, balikin ke
        // `dispatch()` — job-nya idempoten, jadi aman dipindah-pindah.
        try {
            $job->handle();
        } catch (\Throwable $e) {
            // Job-nya udah nandain sertifikatnya `gagal` + ngabarin admin, jadi
            // tombol retry muncul di layar. Approve-nya sendiri TETAP SAH: sesi
            // udah disetujui, dan mbatalin approve gara-gara PDF gagal cuma
            // maksa admin ngulang pemeriksaan yang udah bener.
            Log::warning('Sertifikat gagal dibikin waktu approve.', [
                'calibration_session_id' => $calibration->id,
                'error' => $e->getMessage(),
            ]);
        }

        $segar = $calibration->fresh()->load(self::RELASI);
        $this->kabarinTeknisi($segar, SesiDisetujui::dariSesi($segar));
        $this->siarkan($segar, 'disetujui');

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
            // Kode kolom yang diminta dibetulin, mis. `alat_serial_number`.
            // Opsional: nolak tanpa nunjuk kolom tertentu tetap sah ("hasilnya
            // nggak masuk akal, ulangi seluruh titik 7").
            //
            // Sengaja NGGAK divalidasi terhadap daftar kolom yang ada. Yang
            // ngirim ini layar admin yang bentuk formulirnya juga dari backend,
            // jadi kode asing artinya formulirnya berubah — dan kalau itu bikin
            // 422, admin keblokir nolak gara-gara hal yang bukan urusannya.
            // Efek terburuk dari kode yang nggak dikenal cuma: nggak ada kolom
            // yang kesorot, prosa catatannya tetap kebaca.
            'revisi_field' => ['sometimes', 'nullable', 'array', 'max:40'],
            'revisi_field.*' => ['required', 'string', 'max:64'],
        ], [
            'catatan_revisi.required' => 'Catatan revisi wajib diisi — teknisi perlu tahu apa yang harus dibenerin.',
        ]);

        if ($calibration->status !== CalibrationSession::STATUS_MENUNGGU_APPROVAL) {
            return response()->json([
                'message' => 'Cuma sesi yang statusnya `menunggu_approval` yang bisa ditolak.',
            ], 422);
        }

        $field = array_values(array_unique($data['revisi_field'] ?? []));

        $calibration->update([
            'status' => CalibrationSession::STATUS_PERLU_REVISI,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'catatan_revisi' => $data['catatan_revisi'],
            'revisi_field' => $field === [] ? null : $field,
        ]);

        $segar = $calibration->fresh()->load(self::RELASI);
        $this->kabarinTeknisi($segar, SesiPerluRevisi::dariSesi($segar));
        $this->siarkan($segar, 'ditolak');

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

        $susunan = $this->susunPengukuran($request);

        foreach ($susunan['mentah'] as $baris) {
            $sesi->rawMeasurements()->create($baris);
        }

        // Stempel versi rumus yang berlaku di TANGGAL KALIBRASI sesi ini
        // (Keputusan 5). Ini yang bikin hasil hitung lama tetap bisa dijelasin
        // sesudah rumusnya diubah: tanpa stempel, nggak ada cara tahu sertifikat
        // mana dihitung pakai aturan yang mana.
        //
        // Dihitung SEKALI di luar loop — semua titik di satu sesi pakai versi yang
        // sama, dan manggilnya per titik cuma nambah query tanpa nambah informasi.
        $versiRumus = app(RumusKalibrasi::class)->versiUntukSesi($sesi);

        foreach ($susunan['hitungan'] as $hitungan) {
            $sesi->uncertaintyCalculations()->create([
                ...$hitungan,
                'formula_version_id' => $versiRumus?->id,
            ]);
        }

        return $this->tutupPengisian($sesi, $request, $sesi->uncertaintyCalculations()->get());
    }

    /**
     * Susun pembacaan mentah + hasil hitung GUM dari payload — TANPA nyentuh DB.
     *
     * Dipisah dari penyimpanan supaya `POST /calibrations/preview` bisa mutar
     * perhitungan yang SAMA PERSIS tanpa nyimpen apa-apa. Kalau logikanya disalin
     * jadi dua, angka preview bisa diam-diam beda dari angka yang tersimpan —
     * dan buat lab terakreditasi, dua angka beda buat satu pengukuran itu temuan.
     * Satu-satunya cara mencegahnya: satu fungsi, dua pemakai.
     *
     * @return array{
     *     mentah: list<array<string, mixed>>,
     *     hitungan: list<array<string, mixed>>,
     *     belum_dihitung: list<array{titik_ke: int, alasan: string}>,
     * }
     */
    /**
     * Satuan yang dipilih teknisi di "7. Satuan Refracto", atau null kalau
     * lembar kerjanya emang nggak punya kolom itu.
     *
     * String kosong dianggap **nggak milih**, bukan "kosongin satuan alatnya".
     * Lembar kerja boleh dikirim setengah jadi dari lapangan — itu aturan yang
     * nggak berubah sejak awal — dan kolom yang dilewat nggak boleh diam-diam
     * ngehapus data master yang udah bener.
     */
    private function satuanPilihan(CalibrationRequest $request): ?string
    {
        $satuan = trim((string) $request->input('equipment_satuan', ''));

        return $satuan === '' ? null : $satuan;
    }

    /**
     * Tempelin satuan pilihan ke objek alat, **tanpa nyimpen**.
     *
     * Sengaja dipisah dari [simpanSatuanAlat]: yang ini jalan juga di
     * `preview`, yang nggak boleh nyentuh DB sama sekali.
     */
    private function tempelSatuanPilihan(CalibrationRequest $request, Equipment $alat): void
    {
        $satuan = $this->satuanPilihan($request);

        if ($satuan !== null) {
            $alat->satuan = $satuan;
        }
    }

    /**
     * Simpen satuan pilihan teknisi ke data master alat (`equipments.satuan`).
     *
     * Ini satu-satunya jalur di controller ini yang nulis ke luar sesi, jadi
     * dipagerin tiga lapis: `equipment_id` udah divalidasi ada DI ORGANISASI
     * si pengirim (lihat CalibrationRequest), kolomnya cuma dikirim mobile buat
     * lembar yang punya "7. Satuan Refracto", dan nilai yang sama persis nggak
     * bikin tulisan baru.
     *
     * Kenapa ke master alat dan bukan ke sesi: `RefractometerProfile::satuan()`
     * bacanya `equipments.satuan`, dan lewat situ juga sertifikat, budget CMC,
     * & hitung ulang di kemudian hari ngambil satuannya. Nyimpen di sesi doang
     * bikin sesi ini bener tapi semua yang mbaca alatnya masih salah.
     */
    private function simpanSatuanAlat(CalibrationRequest $request): void
    {
        $satuan = $this->satuanPilihan($request);

        if ($satuan === null) {
            return;
        }

        $alat = Equipment::find($request->integer('equipment_id'));

        if ($alat === null || $alat->satuan === $satuan) {
            return;
        }

        $alat->update(['satuan' => $satuan]);
    }

    private function susunPengukuran(CalibrationRequest $request): array
    {
        $alat = Equipment::findOrFail($request->integer('equipment_id'));

        // Satuan pilihan teknisi ditempelin ke objek alat SEBELUM apa pun
        // dihitung — di memori doang, penyimpanannya urusan `simpanSatuanAlat()`.
        //
        // Ditaruh di sini, bukan cuma di `store`, supaya `POST
        // /calibrations/preview` ikut kena. Preview & sesi tersimpan wajib
        // ngeluarin angka yang sama persis; kalau satuannya cuma nempel waktu
        // nyimpen, teknisi mindahin alatnya ke °Brix, ngintip preview, dapat
        // angka yang dihitung pakai koefisien n20D, terus sertifikatnya keluar
        // beda. Buat lab terakreditasi, dua angka beda buat satu pengukuran itu
        // temuan audit.
        $this->tempelSatuanPilihan($request, $alat);

        $standarDefault = $request->filled('standard_id')
            ? Standard::findOrFail($request->integer('standard_id'))
            : null;

        // Rata-rata suhu ruang MENTAH — (awal + akhir) / 2, SEBELUM koreksi
        // sertifikat thermohygro. Cuma Refractometer yang makai (komponen budget
        // "Pengaruh Perbedaan Temperature"), dan master Excel-nya emang ngambil
        // yang mentah: 20,9 & 21,2 → 21,05 → baris tabel 21,0. Kalau dipakein
        // `suhu_ruang` yang udah dikoreksi (21,96) hasilnya turun satu baris
        // lebih dan U95-nya meleset.
        $suhuRuangTerisi = array_values(array_filter(
            [$request->input('suhu_awal'), $request->input('suhu_akhir')],
            fn ($s): bool => $s !== null && $s !== '',
        ));
        $suhuRuang = $suhuRuangTerisi === []
            ? null
            : array_sum(array_map('floatval', $suhuRuangTerisi)) / count($suhuRuangTerisi);

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

        $mentah = [];
        $hitungan = [];
        $belumDihitung = [];

        // Baris yang NGGAK nyumbang satu pun pembacaan — titik yang di-hide di
        // lembar kerja, atau yang barisnya emang dikosongin karena alat
        // pelanggan nggak punya titik itu — dibuang SEBELUM penomoran, bukan
        // sesudah.
        //
        // Dulu dia tetap kekirim dan tetap makan satu slot `$index`, jadi tiap
        // titik sesudahnya kegeser satu: titik 25 µS/cm yang mestinya `titik_ke`
        // 1 kesimpen sebagai 2. `PerhitunganBuilder` nyari standar pakai
        // `keyBy('titik_ke')` dan sertifikatnya nyetak baris per `titik_ke`,
        // jadi geseran itu mendarat langsung di dokumen — angka titik pertama
        // nongol di baris kedua, persis kayak kasus salah-petak hasil foto.
        //
        // `pembacaan_sebelum` ikut diitung: baris yang cuma punya as-found tetap
        // titik yang nyata, cuma belum bisa dihitung.
        $titikTerpakai = array_values(array_filter(
            $request->input('measurements', []),
            static fn (array $titik): bool => array_filter(
                [...array_values($titik['pembacaan'] ?? []), ...array_values($titik['pembacaan_sebelum'] ?? [])],
                static fn ($nilai): bool => $nilai !== null && $nilai !== '',
            ) !== [],
        ));

        foreach ($titikTerpakai as $index => $titik) {
            $titikKe = $index + 1;
            $satuan = $titik['satuan'] ?? $satuanDefault;
            // Metadata OCR sejajar per-index sama pembacaan — boleh nggak ada
            // (input manual). Divalidasi panjangnya di CalibrationRequest.
            $ocr = array_values($titik['ocr'] ?? []);
            $suhu = array_values($titik['suhu'] ?? []);

            // Dihitung SEBELUM barisnya disusun, karena tiap baris mentah ikut
            // nyimpen standarnya sendiri sekarang. Dulu ini di bawah — waktu
            // standar cuma dipakai buat ngitung, urutannya nggak penting.
            //
            // CalibrationRequest udah validasi standard_id per titik itu ada &
            // masih berlaku — kalau sampe nggak ketemu di sini (standar
            // dihapus di antara validasi & baris ini, atau endpoint lain
            // manggil susunPengukuran tanpa lewat CalibrationRequest),
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

            // Centang standar milik BARIS ini, bukan standar default sesi.
            //
            // Bedanya penting waktu lembarnya dibuka lagi: `$standarDefault`
            // kepasang ke semua titik, jadi kalau dia yang disimpen, teknisi
            // bakal lihat semua baris kecentang padahal dia belum milih apa
            // pun per titik.
            $standarBaris = isset($titik['standard_id']) ? $standarTitik?->id : null;

            $pembacaanTerisi = [];
            // Suhu larutan, disaring sejajar sama `$pembacaanTerisi` — bukan
            // seluruh kolom suhu. Rata-ratanya dipakai buat nurunin nilai buffer
            // pada suhu pengukuran, jadi dia harus ngikut baris yang beneran
            // ikut dihitung; baris yang pembacaannya kosong nggak boleh nyeret
            // suhunya ke rata-rata.
            $suhuTerisi = [];

            // Sel kosong di lembar kerja tetap kekirim (sebagai null) supaya
            // nomor pengulangannya nggak geser. Yang disimpen cuma yang keisi.
            foreach (array_values($titik['pembacaan'] ?? []) as $urutan => $nilai) {
                if ($nilai === null || $nilai === '') {
                    continue;
                }

                if (($suhu[$urutan] ?? null) !== null && $suhu[$urutan] !== '') {
                    $suhuTerisi[] = (float) $suhu[$urutan];
                }

                $meta = $ocr[$urutan] ?? null;
                $dariKamera = $meta !== null || $sesiKamera;

                $pembacaan = $this->bulatkanKolom($nilai, self::DESIMAL_PEMBACAAN);
                $pembacaanTerisi[] = $pembacaan;

                $mentah[] = [
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $urutan + 1,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $titik['titik_ukur'],
                    'standard_id' => $standarBaris,
                    'pembacaan' => $pembacaan,
                    'suhu' => $this->bulatkanKolom($suhu[$urutan] ?? null, self::DESIMAL_SUHU),
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
                ];
            }

            // As-found (sebelum adjustment) — dokumentasi kondisi alat doang,
            // TIDAK ikut GumCalculator::hitungTitik() di bawah. Selalu manual
            // (belum ada jalur OCR buat state ini) & langsung terverifikasi.
            $suhuSebelum = array_values($titik['suhu_sebelum'] ?? []);

            foreach (array_values($titik['pembacaan_sebelum'] ?? []) as $urutan => $nilai) {
                if ($nilai === null || $nilai === '') {
                    continue;
                }

                $mentah[] = [
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $urutan + 1,
                    'tahap' => 'sebelum_adjustment',
                    'titik_ukur' => $titik['titik_ukur'],
                    'standard_id' => $standarBaris,
                    'pembacaan' => $this->bulatkanKolom($nilai, self::DESIMAL_PEMBACAAN),
                    'suhu' => $this->bulatkanKolom($suhuSebelum[$urutan] ?? null, self::DESIMAL_SUHU),
                    'satuan' => $satuan,
                    'input_source' => 'manual',
                    'is_verified' => true,
                ];
            }

            // Titik yang datanya belum cukup TETAP disimpen mentah, cuma nggak
            // dihitung. Ini yang bikin lembar kerja setengah jadi boleh dikirim
            // dari lapangan tanpa maksa sistem ngarang angka: yang nahan bukan
            // tombol kirim, tapi penerbitan sertifikatnya (CalibrationValidator).
            $alasan = $this->alasanBelumBisaDihitung($pembacaanTerisi, $alat, $standarTitik);

            if ($alasan !== null) {
                $belumDihitung[] = ['titik_ke' => $titikKe, 'alasan' => $alasan];

                continue;
            }

            $hitungan[] = $this->bulatkanHitungan($this->gum->hitungTitik(
                $titikKe,
                (float) $titik['titik_ukur'],
                $pembacaanTerisi,
                $alat,
                $standarTitik,
                // Suhu larutan rata-rata titik ini. Null kalau teknisi nggak
                // ngisi kolom suhu — `hitungTitik()` bakal balik ke nilai
                // nominal yang diketik, sama kayak perilaku sebelumnya.
                $suhuTerisi === [] ? null : array_sum($suhuTerisi) / count($suhuTerisi),
                $suhuRuang,
            ));
        }

        return ['mentah' => $mentah, 'hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Bulatin hasil GUM ke presisi kolom `uncertainty_calculations`.
     *
     * Dilakuin SEBELUM insert, bukan cuma buat preview — supaya dua jalurnya
     * dapat angka yang sama karena dihitung sama, bukan karena dicocokin
     * belakangan. Sebelumnya pembulatan ini dikerjain MySQL diam-diam waktu
     * INSERT, jadi sesi tersimpan balikin `0.03` sementara preview (yang nggak
     * lewat DB) balikin `0.03000000000000025`.
     *
     * `type_b_components` sengaja NGGAK disentuh: kolomnya JSON, jadi DB nyimpen
     * apa adanya tanpa pembulatan.
     *
     * @param  array<string, mixed>  $hitungan
     * @return array<string, mixed>
     */
    private function bulatkanHitungan(array $hitungan): array
    {
        // decimal(20, 8) — semua besaran hasil hitung.
        $delapanDesimal = [
            'titik_ukur', 'rata_rata', 'error', 'koreksi', 'standar_deviasi',
            'type_a', 'type_b', 'ketidakpastian_gabungan', 'derajat_kebebasan_efektif',
            'ketidakpastian_diperluas', 'toleransi',
        ];

        foreach ($delapanDesimal as $field) {
            if (isset($hitungan[$field])) {
                $hitungan[$field] = round((float) $hitungan[$field], self::DESIMAL_PEMBACAAN);
            }
        }

        // decimal(5, 2) — beda dari yang lain, gampang kelewat.
        if (isset($hitungan['faktor_cakupan_k'])) {
            $hitungan['faktor_cakupan_k'] = round((float) $hitungan['faktor_cakupan_k'], self::DESIMAL_K);
        }

        return $hitungan;
    }

    /**
     * Bulatin ke presisi kolomnya di `raw_measurements`.
     *
     * MySQL yang bulatin sendiri waktu INSERT, jadi jalur simpan (yang baca ulang
     * dari DB) selalu dapat angka yang udah dibulatkan. Preview nggak lewat DB,
     * jadi kalau nggak dibulatin di sini angkanya bisa beda tipis dari yang
     * tersimpan. Paling kerasa di `suhu`: cuma 2 desimal, dan buat pH nilai itu
     * masuk ke `Standard::nilaiPadaSuhu()` — jadi ngaruh ke nilai standarnya.
     */
    private function bulatkanKolom(mixed $nilai, int $desimal): ?float
    {
        return $nilai === null || $nilai === '' ? null : round((float) $nilai, $desimal);
    }

    /**
     * Syarat minimal biar satu titik bisa dihitung ketidakpastiannya. Balikin
     * `null` kalau udah memenuhi, atau alasannya kalau belum.
     *
     * - Pengulangan minimal 2: standar deviasi (Type A) nggak ada artinya dari
     *   satu angka.
     * - Standar acuan harus ada: ketidakpastiannya komponen Type B terbesar.
     * - Toleransi alat harus ada — TAPI cuma buat alat yang emang divonis
     *   PASS/FAIL. Conductivity Meter nggak: `punyaToleransi()` false, dan
     *   `toleransi` NULL di situ artinya "emang nggak ada", bukan "belum
     *   diisi". `CalibrationValidator::periksaKelengkapanHitung()` udah kasih
     *   pengecualian itu dan `GumCalculator::keputusan()` udah balikin null
     *   waktu toleransinya <= 0, cuma gerbang di sini yang kelewat — jadi tiap
     *   titik Conductivity divonis "belum bisa dihitung", sesinya keluar tanpa
     *   satu pun hasil hitung, dan di admin muncul sebagai `titik_kosong` yang
     *   MEMBLOKIR penerbitan. Selama `equipments.toleransi` alat itu masih
     *   keisi `0.0` bug-nya kependem; begitu diberesin jadi NULL (yang benar),
     *   semua sesi Conductivity mati.
     *
     * Alasannya dikembaliin sebagai teks (bukan cuma bool) supaya
     * `POST /calibrations/preview` bisa ngasih tahu teknisi KENAPA satu titik
     * belum keluar angkanya. Tanpa itu, titik yang ilang dari hasil kelihatan
     * kayak bug — padahal cuma kurang satu pembacaan.
     *
     * @param  list<float>  $pembacaan
     */
    private function alasanBelumBisaDihitung(array $pembacaan, Equipment $alat, ?Standard $standar): ?string
    {
        $jumlah = count($pembacaan);

        return match (true) {
            $jumlah < GumCalculator::MIN_PENGULANGAN => sprintf(
                'Baru %d pembacaan terisi, minimal %d — standar deviasi nggak bisa dihitung dari satu angka.',
                $jumlah,
                GumCalculator::MIN_PENGULANGAN,
            ),
            $standar === null => 'Standar acuan belum dipilih buat titik ini.',
            $alat->toleransi === null && $this->profil->untukAlat($alat)->punyaToleransi()
                => 'Toleransi alat masih kosong — isi dulu lewat data Alat, tanpa itu PASS/FAIL nggak ada dasarnya.',
            default => null,
        };
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

        // Lembar yang UDAH dikirim diarsip ke folder PT-nya. Draft nggak:
        // dia masih diisi, dan folder PT bukan tempat naruh pekerjaan
        // setengah jalan.
        //
        // Ditaruh di sini, bukan di store()/update() masing-masing, karena
        // ini satu-satunya tempat status & `submitted_at` diputusin — dua
        // pemanggil itu lewat sini semua, termasuk kirim ulang sesudah revisi.
        //
        // Kegagalannya ditelan on purpose: pengarsipan itu turunan, bukan
        // syarat sahnya lembar kerja. Kalau folder-nya gagal dibikin, lembarnya
        // tetap kekirim dan tetap masuk antrean approval — teknisi nggak
        // kehilangan kerjaannya gara-gara urusan rak berkas.
        if ($segar->submitted_at !== null) {
            try {
                $this->folder->tautkanLembarKerja($segar);
            } catch (\Throwable $e) {
                Log::warning('Lembar kerja gagal ditaut ke folder PT.', [
                    'calibration_session_id' => $segar->id,
                    'pesan' => $e->getMessage(),
                ]);
            }
        }

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
            // Disebut EKSPLISIT, bukan ikut `fieldAdmin()`. Dulu dia nebeng di
            // sana; waktu dikeluarkan (biar teknisi bisa ngisi "6. Thermohygro
            // used"), dia ikut hilang dari daftar yang disimpan — kolomnya lolos
            // validasi tapi nggak pernah nyampe database. Gejalanya persis
            // kayak field yang dibuang: teknisi ngisi, hasilnya null.
            'thermohygro_standard_id',
            'room_id', 'suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir', 'catatan_teknisi',
            // Identitas alat & pemilik versi teknisi (lembar kerja poin 3-5 &
            // OWNER 1-2). Ikut `$opsional`, bukan blok wajib di atas: yang
            // nggak dikirim TIDAK ditimpa null — teknisi bisa nyimpen draft
            // bertahap tanpa ngosongin yang udah dia isi sebelumnya.
            'alat_model', 'alat_serial_number', 'alat_merk', 'pemilik_nama', 'pemilik_alamat',
        ];

        foreach ($opsional as $field) {
            if ($request->has($field)) {
                $atribut[$field] = $request->input($field);
            }
        }

        return $atribut;
    }

    /**
     * Sinyal realtime ke channel organisasi biar HP & panel desktop nge-refresh
     * data yang sama barengan (spec poin 12D). Cuma sinyal — isi datanya tetap
     * ditarik lewat REST biasa.
     */
    private function siarkan(CalibrationSession $sesi, string $aksi): void
    {
        PerubahanDataOrganisasi::dispatch($sesi->organization_id, 'kalibrasi', $aksi, $sesi->id);
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
