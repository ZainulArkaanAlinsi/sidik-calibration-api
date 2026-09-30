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

        return response()->json([
            'data' => collect($alat->items())
                ->map(fn (Equipment $a) => (new AlatPelangganResource($a))
                    ->denganSertifikat($terakhir->get($a->id))
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
        $riwayat = $lingkup->sertifikat()
            ->whereHas('session', fn (Builder $s) => $s->where('equipment_id', $baris->id))
            ->with(['session:id,equipment_id', 'revisionOf:id,nomor'])
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => (new AlatPelangganResource($baris))
                ->denganSertifikat($riwayat->first())
                ->resolve($request) + [
                    'riwayat_sertifikat' => $riwayat
                        ->map(fn (Certificate $c) => (new SertifikatPelangganResource($c))->resolve($request))
                        ->values(),
                ],
        ]);
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

        return $lingkup->sertifikat()
            ->whereHas('session', fn (Builder $s) => $s->whereIn('equipment_id', $idAlat))
            ->with('session:id,equipment_id')
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->get(['id', 'nomor', 'calibration_session_id', 'diterbitkan_pada', 'berlaku_sampai'])
            ->groupBy(fn (Certificate $c) => $c->session->equipment_id)
            ->map(fn (Collection $daftar) => $daftar->first());
    }
}
