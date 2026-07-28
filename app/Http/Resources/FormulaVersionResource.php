<?php

namespace App\Http\Resources;

use App\Models\FormulaVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu versi rumus kalibrasi (Keputusan 5).
 *
 * @mixin FormulaVersion
 */
class FormulaVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'formula_id' => $this->formula_id,
            'nomor_versi' => $this->nomor_versi,

            // `kode` = dihitung program (`GumCalculator`), `database` = dihitung
            // dari `ekspresi` di baris ini. Versi 1 selalu `kode`: rumus yang
            // sekarang jalan MEMANG ada di kode, dan nyatetnya sebagai "dari
            // database" bikin riwayatnya bohong sejak baris pertama.
            'sumber' => $this->sumber,
            'status' => $this->status,

            // Tanggal POLOS — aturan yang sama kayak field `date` lain sejak 26 Jul.
            'berlaku_dari' => $this->effective_from?->toDateString(),
            // `null` = masih berlaku sampai sekarang.
            'berlaku_sampai' => $this->effective_until?->toDateString(),

            'parameter' => $this->parameter,
            'ekspresi' => $this->ekspresi,
            'catatan' => $this->catatan,

            'pembuat' => $this->pembuat ? [
                'id' => $this->pembuat->id,
                'nama' => $this->pembuat->name,
            ] : null,
            // Versi 1 dibikin sistem waktu organisasinya pertama nyimpen kalibrasi,
            // bukan oleh orang. `null` di situ bukan data hilang.
            'oleh_sistem' => $this->created_by === null,

            'dibuat_pada' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
