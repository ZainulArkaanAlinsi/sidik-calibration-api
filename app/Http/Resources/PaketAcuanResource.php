<?php

namespace App\Http\Resources;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu paket data acuan (Studio Data Acuan FR-01, `GET /data-acuan/paket`
 * dan `/paket/{kode}`).
 *
 * Paket yang BELUM diimpor (profil punya `skemaAcuan()` tapi lab ini belum
 * punya barisnya) dibawa sebagai model yang belum tersimpan — `diimpor:
 * false`, tanpa versi.
 *
 * Ringkasan versi disiapkan controller, termasuk keputusan apa yang boleh
 * dilihat teknisi: kunci yang tidak disiapkan TIDAK ikut dikirim (bukan
 * `null`), jadi aturan "teknisi cuma versi aktif" tinggal di satu tempat
 * (`DataAcuanController::bolehLihatSemua`).
 *
 * TIDAK bisa dipakai lewat `::collection()` — konstruktornya butuh ringkasan.
 *
 * @mixin PaketAcuan
 */
class PaketAcuanResource extends JsonResource
{
    /**
     * @param  array{
     *     versi_aktif: ?PaketAcuanVersi,
     *     versi_terjadwal?: list<PaketAcuanVersi>,
     *     jumlah_draf?: int,
     *     jumlah_diajukan?: int,
     *     skema?: ?array<string, mixed>,
     * }  $ringkasan
     */
    public function __construct(PaketAcuan $paket, private readonly array $ringkasan)
    {
        parent::__construct($paket);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ada = fn (string $kunci): bool => array_key_exists($kunci, $this->ringkasan);

        return [
            'kode' => $this->kode_profil,
            'nama' => $this->nama,
            'skema_versi' => $this->skema_versi,
            'diimpor' => $this->exists,
            'versi_aktif' => PaketAcuanVersiResource::ringkas($this->ringkasan['versi_aktif']),
            'versi_terjadwal' => $this->when($ada('versi_terjadwal'), fn (): array => array_map(
                PaketAcuanVersiResource::ringkas(...),
                $this->ringkasan['versi_terjadwal'] ?? [],
            )),
            'jumlah_draf' => $this->when($ada('jumlah_draf'), fn (): int => $this->ringkasan['jumlah_draf'] ?? 0),
            'jumlah_diajukan' => $this->when($ada('jumlah_diajukan'), fn (): int => $this->ringkasan['jumlah_diajukan'] ?? 0),
            'skema' => $this->when($ada('skema'), fn (): ?array => $this->ringkasan['skema'] ?? null),
        ];
    }
}
