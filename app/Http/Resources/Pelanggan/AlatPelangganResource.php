<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\Certificate;
use App\Models\Equipment;
use App\Services\Pelanggan\PengingatJatuhTempoPelanggan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu alat, dilihat dari sisi PEMILIKNYA.
 *
 * Sengaja tanpa kolom internal lab: `toleransi`, `resolusi_rentang`,
 * `catatan`, kategori internal, dan siapa teknisinya. Yang pelanggan butuhkan
 * cuma "alat saya yang mana, kapan terakhir dikalibrasi, kapan harus kembali,
 * dan mana sertifikatnya".
 *
 * `status_kalibrasi` DITURUNKAN dari tanggal jatuh tempo — tidak disimpan —
 * dengan jendela "segera" yang sama persis dengan anak tangga pertama
 * pengingat push-nya ({@see PengingatJatuhTempoPelanggan::TANGGA_HARI}). Kalau
 * dua angka itu beda, HP-nya berbunyi "30 hari lagi" sementara layarnya masih
 * menampilkan alat itu sebagai "aman".
 *
 * @property Equipment $resource
 */
class AlatPelangganResource extends JsonResource
{
    public const STATUS_AMAN = 'aman';

    public const STATUS_SEGERA = 'segera_jatuh_tempo';

    public const STATUS_LEWAT = 'lewat_jatuh_tempo';

    public const STATUS_NONAKTIF = 'nonaktif';

    public const STATUS_BELUM_ADA = 'belum_ada_jadwal';

    /** Diisi controller: sertifikat terbit terakhir alat ini, atau null. */
    public ?Certificate $sertifikatTerakhir = null;

    public function toArray(Request $request): array
    {
        /** @var Equipment $alat */
        $alat = $this->resource;
        $hari = self::hariKeJatuhTempo($alat);

        return [
            'id' => $alat->id,
            'nama' => $alat->nama_alat,
            'merk' => $alat->merk,
            'model' => $alat->model,
            'serial' => $alat->serial_number,
            'no_identifikasi' => $alat->no_identifikasi,
            'rentang' => [
                'min' => $alat->range_min,
                'max' => $alat->range_max,
                'satuan' => $alat->satuan,
            ],
            'lokasi' => $alat->lokasi,
            'tanggal_kalibrasi_terakhir' => $alat->tanggal_kalibrasi_terakhir?->toDateString(),
            'tanggal_jatuh_tempo' => $alat->tanggal_jatuh_tempo?->toDateString(),
            'hari_ke_jatuh_tempo' => $hari,
            'status_kalibrasi' => self::statusKalibrasi($alat, $hari),
            'sertifikat_terakhir' => $this->sertifikatTerakhir === null ? null : [
                'id' => $this->sertifikatTerakhir->id,
                'nomor' => $this->sertifikatTerakhir->nomor,
                'diterbitkan_pada' => $this->sertifikatTerakhir->diterbitkan_pada?->toDateString(),
                'berlaku_sampai' => $this->sertifikatTerakhir->berlaku_sampai?->toDateString(),
            ],
        ];
    }

    public function denganSertifikat(?Certificate $sertifikat): self
    {
        $this->sertifikatTerakhir = $sertifikat;

        return $this;
    }

    /** Negatif = sudah lewat sekian hari. Null = belum ada jadwal. */
    public static function hariKeJatuhTempo(Equipment $alat): ?int
    {
        return $alat->tanggal_jatuh_tempo === null
            ? null
            : (int) now()->startOfDay()->diffInDays($alat->tanggal_jatuh_tempo->copy()->startOfDay(), false);
    }

    public static function statusKalibrasi(Equipment $alat, ?int $hari): string
    {
        if ($alat->status === Equipment::STATUS_NONAKTIF) {
            return self::STATUS_NONAKTIF;
        }

        if ($hari === null) {
            return self::STATUS_BELUM_ADA;
        }

        if ($hari < 0) {
            return self::STATUS_LEWAT;
        }

        return $hari <= PengingatJatuhTempoPelanggan::TANGGA_HARI[0] ? self::STATUS_SEGERA : self::STATUS_AMAN;
    }
}
