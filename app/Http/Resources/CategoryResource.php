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
        /** @var Collection<int, CalibrationCapability> $semua */
        $semua = $this->capabilities;

        // Ringkasannya dihitung CUMA dari baris yang punya angka.
        //
        // Sejak teknisi bisa nambah nama alat sendiri dari lapangan, satu
        // kategori gampang punya lebih banyak baris tanpa CMC (satuan & rentang
        // NULL semua) daripada yang lengkap. `groupBy('satuan')` di bawah bakal
        // ngitung NULL sebagai satuan "paling banyak dipakai", dan seluruh
        // ringkasan kategori — rentang ukur, ketidakpastian terbaik, satuan —
        // pulang kosong buat kategori yang datanya sebenernya lengkap. Yang
        // kelihatan di HP: kartu kategori yang tiba-tiba nggak punya angka,
        // tanpa ada yang berubah di data akreditasinya.
        $caps = $semua->filter(fn (CalibrationCapability $c): bool => $c->punyaCmc());

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
            // Tetap ngitung SEMUA baris, termasuk yang belum punya CMC — ini
            // jawaban buat "ada berapa jenis alat di kategori ini", dan nama
            // alat yang baru ditambah teknisi itu jenis alat yang beneran ada.
            'jumlah_kemampuan' => $semua->count(),
            // Berapa di antaranya yang angkanya belum diturunkan. Dipisah biar
            // layar bisa bilang "12 jenis alat, 3 belum ada CMC-nya" tanpa
            // narik detail kategorinya dulu.
            'jumlah_tanpa_cmc' => $semua->count() - $caps->count(),
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
