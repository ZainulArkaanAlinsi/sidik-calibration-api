<?php

namespace App\Http\Resources;

use App\Models\Organization;
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

            // Badge di kepala lembar kerja: `valid` / `warning` / `expired`.
            // `masih_berlaku` (bool) nggak cukup — worksheet-nya punya state
            // TENGAH ("mau kadaluarsa"), dan ambangnya harus dari backend biar
            // mobile nggak bilang VALID buat standar yang nanti ditolak approve.
            'status_kalibrasi' => $this->statusKalibrasi($this->ambangHari($request)),
            // Bertanda: positif = sisa hari, negatif = udah lewat, null = standar
            // ini nggak punya tanggal berlaku.
            'hari_menuju_kadaluarsa' => $this->hariMenujuKadaluarsa(),

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

    /**
     * Ambang `warning` milik organisasi yang minta.
     *
     * Diambil dari user, bukan dari `$this->organization` — semua standar di satu
     * respons pasti milik org si pemanggil (query-nya di-scope), dan relasi di
     * objek user kecache, jadi ini SATU query buat seluruh koleksi. Kalau lewat
     * standar-nya, jadi satu query per baris.
     *
     * Fallback ke default kalau nggak ada user: semua route `/standards` ada di
     * dalam `auth:sanctum`, jadi ini cuma jaring kalau resource-nya suatu saat
     * dipakai di luar HTTP (console command, snapshot) — biar nggak 500.
     */
    private function ambangHari(Request $request): int
    {
        return $request->user()?->organization?->ambangPeringatanHari()
            ?? Organization::DEFAULT_AMBANG_HARI;
    }
}
