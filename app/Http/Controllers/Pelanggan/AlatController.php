<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\AlatPelangganResource;
use App\Http\Resources\Pelanggan\SertifikatPelangganResource;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Services\Pelanggan\PengingatJatuhTempoPelanggan;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * `/alat` — daftar & detail alat milik perusahaan pelanggan.
 *
 * ID alat dicari DI DALAM lingkup perusahaan (`LingkupData::alat()`), bukan
 * lewat route-model binding lalu dicek pemiliknya. Hasilnya sama-sama 404 buat
 * alat perusahaan lain, tapi yang ini tidak punya urutan "ambil dulu, periksa
 * kemudian" yang bisa lupa diperiksa waktu method baru ditambah.
 */
class AlatController extends Controller
{
    public const SARING = ['semua', 'segera', 'lewat'];

    public function index(Request $request, Konteks $konteks): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'saring' => ['sometimes', 'nullable', Rule::in(self::SARING)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $lingkup = new LingkupData($konteks);
        $jendela = PengingatJatuhTempoPelanggan::TANGGA_HARI[0];

        $alat = $lingkup->alat()
            ->when(filled($data['q'] ?? null), fn (Builder $q) => $q->where(function (Builder $cari) use ($data): void {
                $kata = '%'.$data['q'].'%';
                $cari->where('nama_alat', 'like', $kata)
                    ->orWhere('serial_number', 'like', $kata)
                    ->orWhere('no_identifikasi', 'like', $kata)
                    ->orWhere('merk', 'like', $kata);
            }))
            ->when(($data['saring'] ?? null) === 'lewat', fn (Builder $q) => $q->overdue())
            ->when(($data['saring'] ?? null) === 'segera', fn (Builder $q) => $q
                ->where('status', Equipment::STATUS_AKTIF)
                ->whereNotNull('tanggal_jatuh_tempo')
                ->whereDate('tanggal_jatuh_tempo', '>=', now()->startOfDay())
                ->whereDate('tanggal_jatuh_tempo', '<=', now()->addDays($jendela)))
            // Yang paling mendesak di atas; alat tanpa jadwal di paling bawah.
            ->orderByRaw('tanggal_jatuh_tempo IS NULL ASC')
            ->orderBy('tanggal_jatuh_tempo')
            ->orderBy('nama_alat')
            ->paginate($data['per_page'] ?? 20);

        $terakhir = $this->sertifikatTerakhir($lingkup, collect($alat->items())->pluck('id'));
        $konteksStatus = AlatPelangganResource::konteks($alat->items());

        return response()->json([
            'data' => collect($alat->items())
                ->map(fn (Equipment $a) => (new AlatPelangganResource($a))
                    ->denganSertifikat($terakhir->get($a->id))
                    ->denganKonteks($konteksStatus[$a->id])
                    ->resolve($request))
                ->values(),
            'meta' => [
                'total' => $alat->total(),
                'per_page' => $alat->perPage(),
                'current_page' => $alat->currentPage(),
                'last_page' => $alat->lastPage(),
                'jendela_segera_hari' => $jendela,
            ],
        ]);
    }

    public function show(Request $request, Konteks $konteks, string $alat): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        $baris = $lingkup->alat()->whereKey($alat)->firstOrFail();

        // Riwayat sertifikat alat ini — yang terbaru di atas. Termasuk yang
        // sudah digantikan revisi: di riwayat, itu justru yang ingin dilihat
        // ("dulu hasilnya berapa").
        return response()->json(['data' => $this->rinci($request, $lingkup, $baris)]);
    }

    /*
     * `update()` (PL_Ubah_Alat, §42 B2) DICABUT — keputusan pemilik proyek
     * 20 Okt 2026: aplikasi pelanggan tidak mengubah data alat. Perubahan
     * identitas, lokasi, dan catatan lewat lab. `PengecualianTulisPelangganTest`
     * menahan rute tulis baru yang lahir tanpa keputusan.
     */

    /** Sudah ada sertifikat TERBIT untuk alat ini → identitasnya tercetak. */
    public static function terkunci(Equipment $alat): bool
    {
        return Certificate::query()
            ->where('organization_id', $alat->organization_id)
            ->where('status', Certificate::STATUS_TERBIT)
            ->whereHas('session', fn (Builder $s) => $s->where('equipment_id', $alat->getKey()))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function rinci(Request $request, LingkupData $lingkup, Equipment $baris): array
    {
        // Riwayat sertifikat alat ini — yang terbaru di atas. Termasuk yang
        // sudah digantikan revisi atau dibatalkan: di riwayat, itu justru yang
        // ingin dilihat ("dulu hasilnya berapa"), dan statusnya ikut tampil.
        $riwayat = $lingkup->sertifikat()
            ->whereHas('session', fn (Builder $s) => $s->where('equipment_id', $baris->id))
            ->with(['session:id,equipment_id', 'revisionOf:id,nomor', 'revisiTerakhir'])
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return (new AlatPelangganResource($baris))
            // Yang BERLAKU, bukan sekadar yang terbaru: revisi yang
            // dibatalkan atau pendahulu yang digantikan bukan dokumen alat ini.
            ->denganSertifikat($riwayat->first(fn (Certificate $c) => $c->status === Certificate::STATUS_TERBIT
                && ! in_array($c->revisiTerakhir?->status, Certificate::STATUS_PENGGANTI, true)))
            ->resolve($request)
            + AlatPelangganResource::rincianUbah($baris, self::terkunci($baris))
            + [
                'riwayat_sertifikat' => $riwayat
                    ->map(fn (Certificate $c) => (new SertifikatPelangganResource($c))->resolve($request))
                    ->values(),
            ];
    }

    /**
     * Sertifikat terbit TERAKHIR per alat, untuk satu halaman daftar.
     *
     * Satu query untuk seluruh halaman, bukan satu per baris.
     *
     * @param  Collection<int, int>  $idAlat
     * @return Collection<int, Certificate>
     */
    private function sertifikatTerakhir(LingkupData $lingkup, Collection $idAlat): Collection
    {
        if ($idAlat->isEmpty()) {
            return collect();
        }

        // Yang BERLAKU saja: `LingkupData::sertifikat()` sejak 1 Okt 2026 ikut
        // memuat yang dibatalkan (supaya tampil di riwayat), dan pendahulu yang
        // sudah digantikan bukan "sertifikat terakhir" alat itu.
        return $lingkup->sertifikat()
            ->where('status', Certificate::STATUS_TERBIT)
            ->belumDigantikan()
            ->whereHas('session', fn (Builder $s) => $s->whereIn('equipment_id', $idAlat))
            ->with('session:id,equipment_id')
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->get(['id', 'nomor', 'calibration_session_id', 'diterbitkan_pada', 'berlaku_sampai'])
            ->groupBy(fn (Certificate $c) => $c->session->equipment_id)
            ->map(fn (Collection $daftar) => $daftar->first());
    }
}
