<?php

namespace App\Http\Controllers\Api;

use App\Events\PerubahanDataOrganisasi;
use App\Http\Controllers\Controller;
use App\Http\Requests\KategoriRequest;
use App\Http\Resources\CategoryResource;
use App\Models\AuditLog;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Support\CatatanAlasan;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kelola kategori alat: buat, ubah nama, nonaktifkan/aktifkan, hapus yang tidak
 * dipakai (paket CRUD 18 Nov 2026). Bacanya tetap `CategoryController`.
 *
 * Semua rute di sini `role:admin` (+ super admin lewat K-49-14) dan
 * `throttle:kategori-tulis`. Kategori selalu diambil lewat `organization_id`
 * PEMANGGIL + `kode`, jadi kategori lab lain 404 (bukan 403).
 *
 * Aturan yang dijaga:
 * - `kode` diturunkan dari nama oleh server dan TIDAK berubah (alat dan mobile
 *   menyimpannya).
 * - Nama unik per organisasi (tanpa beda huruf besar/kecil & spasi ganda),
 *   diputuskan di dalam transaksi yang mengunci baris organisasi -- tidak ada
 *   indeks unik di `nama`, jadi tanpa kunci dua permintaan berbarengan sama-sama
 *   lolos.
 * - Nonaktif != dihapus. Nonaktif boleh walau dipakai alat; hapus hanya untuk
 *   yang tidak dipakai apa pun.
 */
class KategoriKelolaController extends Controller
{
    public function store(KategoriRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $nama = (string) $request->validated('nama');

        try {
            [$kategori, $kembar] = DB::transaction(function () use ($organizationId, $nama): array {
                Organization::kunciUntukPenomoran($organizationId);

                $kembar = $this->cariKembar($organizationId, $nama, null);

                if ($kembar !== null) {
                    return [null, $kembar];
                }

                return [EquipmentCategory::create([
                    'organization_id' => $organizationId,
                    'kode' => $this->kodeBaru($organizationId, $nama),
                    'nama' => $nama,
                ]), null];
            });
        } catch (UniqueConstraintViolationException) {
            // Dua pembuat berbarengan yang kodenya sama padahal nama berbeda
            // di mata `cariKembar()` (mis. "Massa" vs "Massa!"): kunci
            // organisasi di atas sudah menyerialkan, jadi ini nyaris mustahil
            // -- tetapi 500 untuk indeks unik itu salah jawaban.
            return response()->json([
                'message' => 'Kode kategori itu baru saja dipakai. Coba lagi.',
                'kode' => 'kode_kategori_bentrok',
            ], 409);
        }

        if ($kembar !== null) {
            return $this->galatKembar($kembar);
        }

        PerubahanDataOrganisasi::siarkanAman($organizationId, 'kategori', 'dibuat', $kategori->id);

        return response()->json([
            'message' => 'Kategori dibuat.',
            'data' => $this->bentuk($kategori, $organizationId),
        ], 201);
    }

    public function update(KategoriRequest $request, string $kode): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $nama = (string) $request->validated('nama');

        [$kategori, $kembar] = DB::transaction(function () use ($organizationId, $kode, $nama): array {
            Organization::kunciUntukPenomoran($organizationId);

            $kategori = $this->ambil($organizationId, $kode);
            $kembar = $this->cariKembar($organizationId, $nama, $kategori->id);

            if ($kembar !== null) {
                return [$kategori, $kembar];
            }

            // `save()` lewat Eloquent: `Diaudit` mencatat nama lama -> baru
            // sendiri, dan tidak menulis apa pun kalau namanya tidak berubah.
            $kategori->nama = $nama;
            $kategori->save();

            return [$kategori, null];
        });

        if ($kembar !== null) {
            return $this->galatKembar($kembar);
        }

        PerubahanDataOrganisasi::siarkanAman($organizationId, 'kategori', 'diubah', $kategori->id);

        return response()->json([
            'message' => 'Kategori diubah.',
            'data' => $this->bentuk($kategori, $organizationId),
        ]);
    }

    public function nonaktifkan(Request $request, string $kode): JsonResponse
    {
        $data = $request->validate(['alasan' => CatatanAlasan::aturan(wajib: false)]);

        return $this->ubahStatus($request, $kode, aktif: false, alasan: $data['alasan'] ?? null);
    }

    public function aktifkan(Request $request, string $kode): JsonResponse
    {
        $data = $request->validate(['alasan' => CatatanAlasan::aturan(wajib: false)]);

        return $this->ubahStatus($request, $kode, aktif: true, alasan: $data['alasan'] ?? null);
    }

    public function destroy(Request $request, string $kode): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        $hasil = DB::transaction(function () use ($organizationId, $kode): array {
            Organization::kunciUntukPenomoran($organizationId);

            $kategori = $this->ambil($organizationId, $kode);
            $pemakai = $this->pemakai($kategori);

            if (array_sum($pemakai) > 0) {
                return [$kategori, $pemakai];
            }

            // Soft delete lewat Eloquent: `Diaudit` mencatat `dihapus`, dan
            // barisnya tetap di database.
            $kategori->delete();

            return [$kategori, null];
        });

        [$kategori, $pemakai] = $hasil;

        if ($pemakai !== null) {
            return response()->json([
                'message' => $this->pesanDipakai($pemakai),
                'kode' => 'kategori_dipakai',
                'pemakai' => $pemakai,
            ], 422);
        }

        PerubahanDataOrganisasi::siarkanAman($organizationId, 'kategori', 'diubah', $kategori->id);

        return response()->json(['message' => 'Kategori dihapus.']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function ubahStatus(Request $request, string $kode, bool $aktif, ?string $alasan): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        [$kategori, $berubah] = DB::transaction(function () use ($organizationId, $kode, $aktif, $alasan): array {
            // Dikunci: dua ketukan "Nonaktifkan" yang berbarengan tidak boleh
            // menulis dua baris audit untuk satu perubahan.
            $kategori = EquipmentCategory::query()
                ->where('organization_id', $organizationId)
                ->where('kode', $kode)
                ->lockForUpdate()
                ->firstOrFail();

            if ($kategori->aktif() === $aktif) {
                return [$kategori, false];
            }

            $sekarang = now();
            $lama = $kategori->nonaktif_pada?->toIso8601String();
            $baru = $aktif ? null : $sekarang->toIso8601String();

            // Query builder, bukan `save()`: satu baris audit yang membawa
            // ALASAN, bukan dua (event model + catatan). Pola yang sama dengan
            // `JatuhTempoAlatController`.
            EquipmentCategory::query()->whereKey($kategori->id)->update([
                'nonaktif_pada' => $aktif ? null : $sekarang,
                'updated_at' => $sekarang,
            ]);

            $kategori->catatAudit(
                $aktif ? AuditLog::ACTION_DIAKTIFKAN : AuditLog::ACTION_DINONAKTIFKAN,
                ['nonaktif_pada' => $lama],
                ['nonaktif_pada' => $baru, 'alasan' => $alasan],
                CatatanAlasan::note(
                    $aktif ? CatatanAlasan::AWALAN_AKTIFKAN_KATEGORI : CatatanAlasan::AWALAN_NONAKTIFKAN_KATEGORI,
                    $alasan,
                ),
            );

            return [$kategori->refresh(), true];
        });

        if ($berubah) {
            PerubahanDataOrganisasi::siarkanAman($organizationId, 'kategori', 'diubah', $kategori->id);
        }

        return response()->json([
            'message' => $aktif ? 'Kategori diaktifkan.' : 'Kategori dinonaktifkan.',
            'data' => $this->bentuk($kategori, $organizationId),
        ]);
    }

    /** Kategori lab pemanggil lewat `kode`; lab lain / tidak ada / sudah dihapus = 404. */
    private function ambil(int $organizationId, string $kode): EquipmentCategory
    {
        return EquipmentCategory::query()
            ->where('organization_id', $organizationId)
            ->where('kode', $kode)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function bentuk(EquipmentCategory $kategori, int $organizationId): array
    {
        $kategori->load(['capabilities' => fn (HasMany $relasi) => $relasi->milikOrganisasi($organizationId)])
            ->loadCount('equipments');

        return (new CategoryResource($kategori))->resolve();
    }

    /** Kategori LAIN di lab ini yang namanya sama (aktif maupun nonaktif, yang belum dihapus). */
    private function cariKembar(int $organizationId, string $nama, ?int $kecualiId): ?EquipmentCategory
    {
        $kunci = self::kunciNama($nama);

        return EquipmentCategory::query()
            ->where('organization_id', $organizationId)
            ->when($kecualiId !== null, fn ($q) => $q->whereKeyNot($kecualiId))
            ->get(['id', 'organization_id', 'kode', 'nama', 'nonaktif_pada'])
            ->first(fn (EquipmentCategory $c): bool => self::kunciNama($c->nama) === $kunci);
    }

    private static function kunciNama(string $nama): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nama)));
    }

    /**
     * Kode dari nama; bila sudah dipakai (termasuk oleh kategori yang sudah
     * dihapus lunak -- indeks unik DB tidak mengabaikan soft delete) diberi
     * akhiran -2, -3, ...
     */
    private function kodeBaru(int $organizationId, string $nama): string
    {
        // Dipotong 100: transliterasi huruf non-Latin bisa menggandakan panjang
        // slug (satu huruf CJK -> beberapa huruf Latin), dan kolom `kode`
        // string(255) memuat dasar + akhiran `-N` dengan lega.
        $dasar = rtrim(mb_substr(Str::slug($nama), 0, 100), '-');

        $terpakai = EquipmentCategory::withTrashed()
            ->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->where('kode', $dasar)->orWhere('kode', 'like', $dasar.'-%'))
            ->pluck('kode')
            ->map(fn (string $k): string => mb_strtolower($k))
            ->all();

        if (! in_array($dasar, $terpakai, true)) {
            return $dasar;
        }

        for ($i = 2; ; $i++) {
            if (! in_array($dasar.'-'.$i, $terpakai, true)) {
                return $dasar.'-'.$i;
            }
        }
    }

    private function galatKembar(EquipmentCategory $kembar): JsonResponse
    {
        return response()->json([
            'message' => 'Sudah ada kategori bernama itu.',
            'kode' => 'nama_kategori_kembar',
            'errors' => ['nama' => ['Sudah ada kategori bernama itu.']],
            'kategori' => [
                'kode' => $kembar->kode,
                'nama' => $kembar->nama,
                'aktif' => $kembar->aktif(),
            ],
        ], 422);
    }

    /**
     * Tabel yang menunjuk kategori lewat `equipment_category_id` (kunci hasil
     * `pemakai` => [tabel, kolom]). DAFTAR, bukan kueri tulis tangan, supaya
     * `KolomQueryPaketCrudTest` bisa memeriksa tiap tabel/kolom terhadap skema
     * sungguhan.
     *
     * ## Kenapa itu penting (jebakan 18 Nov 2026)
     *
     * Versi pertama menghitung `formulas.equipment_category_id` -- kolom yang TIDAK
     * ADA. SQLite meloloskannya: identifier ber-kutip-ganda yang tak dikenal
     * diperlakukan sebagai string literal, jadi kondisinya selalu salah dan
     * hasilnya 0 tanpa galat. MySQL (CI) menjawab 500 `Unknown column`. Test
     * SQLite yang hijau tidak membuktikan nama kolom benar.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PEMAKAI_LEWAT_ID = [
        'alat' => ['equipments', 'equipment_category_id'],
        'kemampuan' => ['calibration_capabilities', 'equipment_category_id'],
        'penugasan' => ['penugasan_item', 'equipment_category_id'],
        'metode' => ['calibration_methods', 'equipment_category_id'],
    ];

    /**
     * `formulas` TIDAK punya FK ke kategori. Hubungannya cuma `besaran`, yang
     * migrasinya (`2026_07_27_110000`) tulis sebagai "nyocokin `kode` di
     * `equipment_categories`": rumus se-organisasi yang `besaran`-nya sama dengan
     * KODE kategori. [tabel, kolom besaran, kolom organisasi].
     *
     * Catatan jujur: `besaran` yang ditulis kode program berasal dari
     * `CalibrationProfile::besaran()` (`massa`, `suhu`, `ph`, ...), jadi cocoknya
     * persis hanya untuk kategori yang kodenya memang sama (mis. `massa`,
     * `volume`, `tekanan`, `gaya`). Itu arah yang aman: yang tidak cocok tidak
     * terhalang, yang cocok pasti tertahan.
     *
     * @var array{0: string, 1: string, 2: string}
     */
    public const PEMAKAI_RUMUS = ['formulas', 'besaran', 'organization_id'];

    /**
     * Berapa baris yang masih menunjuk kategori ini.
     *
     * Dihitung lewat tabelnya (`DB::table`), jadi baris yang sudah dihapus lunak
     * ikut terhitung: sesi lama masih menunjuk alatnya, dan menghapus kategori
     * yang masih punya CMC atau rumus berarti menelantarkan data akreditasi.
     *
     * @return array{alat: int, kemampuan: int, penugasan: int, metode: int, rumus: int}
     */
    private function pemakai(EquipmentCategory $kategori): array
    {
        $hasil = [];

        foreach (self::PEMAKAI_LEWAT_ID as $kunci => [$tabel, $kolom]) {
            $hasil[$kunci] = DB::table($tabel)->where($kolom, $kategori->id)->count();
        }

        [$tabel, $kolomBesaran, $kolomOrganisasi] = self::PEMAKAI_RUMUS;
        $hasil['rumus'] = DB::table($tabel)
            ->where($kolomOrganisasi, $kategori->organization_id)
            ->where($kolomBesaran, $kategori->kode)
            ->count();

        return $hasil;
    }

    /** @param  array<string, int>  $pemakai */
    private function pesanDipakai(array $pemakai): string
    {
        $bagian = [];

        foreach (['alat' => 'alat', 'kemampuan' => 'nama alat kemampuan', 'penugasan' => 'baris penugasan', 'metode' => 'metode', 'rumus' => 'rumus'] as $kunci => $label) {
            if (($pemakai[$kunci] ?? 0) > 0) {
                $bagian[] = $pemakai[$kunci].' '.$label;
            }
        }

        return 'Kategori ini masih dipakai ('.implode(', ', $bagian).'). '
            .'Nonaktifkan saja supaya tidak ditawarkan lagi.';
    }
}
