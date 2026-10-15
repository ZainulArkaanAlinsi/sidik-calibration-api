<?php

namespace App\Http\Resources\Studio;

use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Workbook\KanonikWorkbook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu lembar workbook (meta, TANPA sel). Sel diambil per rentang baris lewat
 * `GET /studio/workbook/{workbook}/lembar/{lembar}`.
 *
 * @property WorkbookMasterLembar $resource
 */
class WorkbookMasterLembarResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;

        return [
            'id' => $l->id,
            'urutan' => $l->urutan,
            'nama' => $l->nama,
            'tersembunyi' => $l->tersembunyi,
            'dikecualikan' => $l->dikecualikan,
            'alasan_dikecualikan' => $l->alasan_dikecualikan,
            'tautan_tabel' => $l->tautan_tabel,
            'jumlah_baris' => $l->jumlah_baris,
            'jumlah_kolom' => $l->jumlah_kolom,
            'jumlah_sel' => $l->jumlah_sel,
            'jumlah_rumus' => $l->jumlah_rumus,
            // Peta `lebar_kolom`/`tinggi_baris` keluar sebagai objek JSON (`{}` bila kosong).
            'tata_letak' => KanonikWorkbook::tataLetakKabel(is_array($l->tata_letak) ? $l->tata_letak : []),
        ];
    }
}
