<?php

namespace App\Http\Resources;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Dipakai mobile buat dropdown kategori. Bentuknya ngikutin docs/kontrak-api.md.
 *
 * Catatan penting: satu kelompok pengukuran bisa punya banyak satuan (contoh
 * "Panjang" isinya µm DAN mm). `rentang_ukur`, `ketidakpastian_terbaik` &
 * `satuan` di sini itu RINGKASAN dari satuan yang paling banyak dipakai di
 * kelompok itu — buat ditampilin sekilas, bukan buat validasi. Validasi rentang
 * yang beneran pakai daftar `kemampuan` (lihat GET /api/categories/{kode}).
 *
 * @mixin EquipmentCategory
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, CalibrationCapability> $caps */
        $caps = $this->capabilities;

        $satuanDominan = $caps->groupBy('satuan')
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->keys()
            ->first();

        $sesuaiSatuan = $caps->where('satuan', $satuanDominan);

        $min = $sesuaiSatuan->whereNotNull('range_min')->min('range_min');
        $max = $sesuaiSatuan->max('range_max');

        return [
            'kode' => $this->kode,
            'nama' => $this->nama,
            'rentang_ukur' => $this->formatRentang($min, $max, $satuanDominan),
            'ketidakpastian_terbaik' => $sesuaiSatuan->min('ketidakpastian_terbaik'),
            'satuan' => $satuanDominan,
            'jumlah_kemampuan' => $caps->count(),
        ];
    }

    private function formatRentang(?float $min, ?float $max, ?string $satuan): ?string
    {
        if ($max === null) {
            return null;
        }

        return $min === null
            ? sprintf('s/d %s %s', $this->angka($max), $satuan)
            : sprintf('%s – %s %s', $this->angka($min), $this->angka($max), $satuan);
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 4, '.', ''), '0'), '.');
    }
}
