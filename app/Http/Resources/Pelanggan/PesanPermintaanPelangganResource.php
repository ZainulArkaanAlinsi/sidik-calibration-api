<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\PesanPermintaan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu pesan di utas permintaan, dilihat pelanggan.
 *
 * Pesan dari LAB tidak membawa nama adminnya — cuma "Tim {nama lab}", persis
 * seperti di desain (PL_Permintaan). Pelanggan tidak perlu tahu siapa admin
 * yang membalas, dan nama orang lab tidak boleh bocor lewat satu field yang
 * kelak ditambahkan. Pesan dari rekan sepekerjanya memakai nama mereka.
 *
 * @property PesanPermintaan $resource
 */
class PesanPermintaanPelangganResource extends JsonResource
{
    public function __construct($resource, private readonly int $userId = 0, private readonly string $namaLab = 'lab')
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var PesanPermintaan $pesan */
        $pesan = $this->resource;
        $dariLab = $pesan->sisi === PesanPermintaan::SISI_LAB;

        return [
            'id' => $pesan->id,
            'sisi' => $pesan->sisi,
            'dari_saya' => ! $dariLab && $pesan->pengirim_id === $this->userId,
            'nama_pengirim' => $dariLab ? 'Tim '.$this->namaLab : ($pesan->pengirim?->name ?? 'Anggota'),
            'isi' => $pesan->isi,
            'dibuat_pada' => $pesan->created_at?->toIso8601ZuluString(),
        ];
    }
}
