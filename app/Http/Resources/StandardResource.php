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

            // Dua data sertifikat yang bikin lembar perhitungan jalan otomatis.
            // Dikirim apa adanya (boleh null) supaya form master data di mobile
            // bisa nampilin & ngedit tanpa nebak bentuknya.
            'koefisien_suhu' => $this->koefisien_suhu,
            'parameter_kondisi' => $this->parameter_kondisi,
            // Jalan pintas biar layar nggak perlu ngintip isi JSON-nya cuma
            // buat mutusin nampilin lencana "siap dipakai perhitungan".
            'punya_kurva_suhu' => $this->nilaiPadaSuhu(25.0) !== null,
        ];
    }
}
