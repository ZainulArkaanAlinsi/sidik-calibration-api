<?php

namespace App\Http\Resources;

use App\Models\PaketAcuanVersi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu versi data acuan (Studio Data Acuan, 03-SDD §3) — garis waktu,
 * `berlaku`, dan `GET /data-acuan/versi/{versi}`.
 *
 * Field dipetakan manual; bentuknya kontrak (`docs/kontrak-api.md` §15).
 * `isi` hanya ikut lewat [denganIsi]: daftar versi tidak membawa tabel utuh.
 *
 * @mixin PaketAcuanVersi
 */
class PaketAcuanVersiResource extends JsonResource
{
    private bool $denganIsi = false;

    /** Sertakan `isi` terurai (endpoint detail versi). */
    public function denganIsi(): static
    {
        $this->denganIsi = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'paket' => $this->relationLoaded('paketAcuan') ? $this->paketAcuan?->kode_profil : null,
            'jenis' => $this->jenis,
            'nomor' => $this->nomor_versi,
            'status' => $this->status,
            'skema_versi' => $this->skema_versi,
            'dasar_versi_id' => $this->dasar_versi_id,
            'berlaku_mulai' => $this->berlaku_mulai?->toDateString(),
            'berlaku_sampai' => $this->berlaku_sampai?->toDateString(),
            // Dikunci saat diajukan; `null` = draf yang isinya masih bisa berubah.
            'isi_sha256' => $this->isi_sha256,
            'alasan' => $this->alasan,
            'rujukan' => $this->rujukan,
            'catatan_keputusan' => $this->catatan_keputusan,
            'dibuat_oleh' => $this->dibuat_oleh,
            'diajukan_oleh' => $this->diajukan_oleh,
            'diajukan_pada' => $this->diajukan_pada?->toIso8601String(),
            'disahkan_oleh' => $this->disahkan_oleh,
            'disahkan_pada' => $this->disahkan_pada?->toIso8601String(),
            'ditolak_oleh' => $this->ditolak_oleh,
            'ditolak_pada' => $this->ditolak_pada?->toIso8601String(),
            'lock_versi' => $this->lock_versi,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Diurai sebagai OBJEK, bukan array PHP: `{}` tetap objek dan kunci
            // objek berangka (`"200"`) tetap kunci objek. Nilai angkanya apa
            // adanya — tidak dibulatkan (BR-05).
            'isi' => $this->when(
                $this->denganIsi,
                fn (): mixed => json_decode((string) $this->isi, false, 512, JSON_THROW_ON_ERROR),
            ),
        ];
    }

    /**
     * Bentuk ringkas untuk daftar paket, banding, dan riwayat sel.
     *
     * @return array{id: int, nomor: int, status: string, berlaku_mulai: ?string, berlaku_sampai: ?string, sha256: ?string}|null
     */
    public static function ringkas(?PaketAcuanVersi $versi): ?array
    {
        if ($versi === null) {
            return null;
        }

        return [
            'id' => (int) $versi->getKey(),
            'nomor' => (int) $versi->nomor_versi,
            'status' => (string) $versi->status,
            'berlaku_mulai' => $versi->berlaku_mulai?->toDateString(),
            'berlaku_sampai' => $versi->berlaku_sampai?->toDateString(),
            'sha256' => $versi->isi_sha256,
        ];
    }
}
