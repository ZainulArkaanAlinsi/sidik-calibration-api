<?php

namespace App\Http\Resources;

use App\Models\Standard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Standard
 */
class StandardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'merk' => $this->merk,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            // Nilai yang tertulis di sertifikat standar (mis. buffer "pH 4"
            // yang sebenernya 4.01), plus suhu acuannya — nilai buffer pH
            // berubah sama suhu, jadi angkanya nggak ada artinya sendirian.
            'nilai_konvensional' => $this->nilai_konvensional,
            'suhu_referensi' => $this->suhu_referensi,

            'no_sertifikat' => $this->no_sertifikat,
            'tertelusur_ke' => $this->tertelusur_ke,
            'berlaku_sampai' => $this->berlaku_sampai?->toIso8601ZuluString(),
            // Standar yang sertifikatnya lewat masa berlaku ditolak `422` waktu
            // dipakai kalibrasi. Dikirim sebagai flag siap pakai biar mobile nggak
            // perlu banding-bandingin tanggal sendiri — gampang salah zona waktu.
            'masih_berlaku' => $this->masihBerlaku(),

            // Angka dari sertifikat standar: ini ketidakpastian DIPERLUAS, udah
            // dikali `faktor_cakupan`. Backend yang bagi balik waktu ngitung
            // Type B — mobile cukup nampilin apa adanya.
            'ketidakpastian' => $this->ketidakpastian,
            'satuan_ketidakpastian' => $this->satuan_ketidakpastian,
            'faktor_cakupan' => $this->faktor_cakupan,
            'drift' => $this->drift,
        ];
    }
}
