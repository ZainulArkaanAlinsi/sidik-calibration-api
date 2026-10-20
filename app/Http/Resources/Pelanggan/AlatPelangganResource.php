<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Services\Pelanggan\PengingatJatuhTempoPelanggan;
use Illuminate\Database\Eloquent\Builder;
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
    /** Kolom identitas yang terkunci begitu sertifikat terbit (label untuk layar). */
    public const KUNCI_IDENTITAS = [
        'nama_alat' => 'Nama alat',
        'merk' => 'Merk',
        'model' => 'Model',
        'serial_number' => 'Nomor seri',
        'no_identifikasi' => 'No. identifikasi',
        'range_min' => 'Rentang min.',
        'range_max' => 'Rentang maks.',
        'satuan' => 'Satuan',
        'resolusi' => 'Resolusi',
    ];

    public const STATUS_AMAN = 'aman';

    public const STATUS_SEGERA = 'segera_jatuh_tempo';

    public const STATUS_LEWAT = 'lewat_jatuh_tempo';

    public const STATUS_NONAKTIF = 'nonaktif';

    public const STATUS_BELUM_ADA = 'belum_ada_jadwal';

    /*
     * `status_alat` — status kalibrasi menurut tabel 02-SRS REQ-ALT-05, untuk
     * beranda & daftar alat (20 Okt 2026). ADDITIVE: field lama
     * `status_kalibrasi` tetap dikirim apa adanya karena aplikasi yang sudah
     * terpasang membacanya. Dua-duanya turunan, tidak disimpan.
     *
     * Kode `dalam_proses` milik rancangan yang sama SENGAJA tidak dikirim:
     * sumbernya permintaan kalibrasi pelanggan, yang dicabut K-50-09.
     */
    public const ALAT_TERKALIBRASI = 'terkalibrasi';

    public const ALAT_MENDEKATI_JADWAL = 'mendekati_jadwal';

    public const ALAT_LEWAT_JADWAL = 'lewat_jadwal';

    public const ALAT_BELUM_PERNAH = 'belum_pernah';

    public const ALAT_NONAKTIF = 'nonaktif';

    /** Diisi controller: sertifikat terbit terakhir alat ini, atau null. */
    public ?Certificate $sertifikatTerakhir = null;

    /**
     * Diisi controller lewat [konteks()] (satu query per halaman). Null = belum
     * diisi; `toArray()` lalu menghitungnya sendiri untuk alat ini saja.
     *
     * @var array{pernah_terbit: bool}|null
     */
    public ?array $konteksStatus = null;

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
            'status_alat' => self::statusAlat(
                $alat,
                $hari,
                ($this->konteksStatus ?? self::konteks([$alat])[$alat->id])['pernah_terbit'],
            ),
        ];
    }

    public function denganSertifikat(?Certificate $sertifikat): self
    {
        $this->sertifikatTerakhir = $sertifikat;

        return $this;
    }

    /** @param  array{pernah_terbit: bool}  $konteks */
    public function denganKonteks(array $konteks): self
    {
        $this->konteksStatus = $konteks;

        return $this;
    }

    /**
     * Pernah atau tidaknya tiap alat punya sertifikat `terbit` — SATU query,
     * berapa pun jumlah alatnya.
     *
     * @param  iterable<Equipment>  $alat
     * @return array<int, array{pernah_terbit: bool}>
     */
    public static function konteks(iterable $alat): array
    {
        $daftar = collect($alat);
        $hasil = $daftar->mapWithKeys(fn (Equipment $a) => [$a->id => ['pernah_terbit' => false]])->all();

        if ($daftar->isEmpty()) {
            return $hasil;
        }

        CalibrationSession::query()
            ->whereIn('equipment_id', $daftar->pluck('id')->all())
            ->whereHas('certificate', fn (Builder $c) => $c->where('status', Certificate::STATUS_TERBIT))
            ->distinct()
            ->pluck('equipment_id')
            ->each(function ($equipmentId) use (&$hasil): void {
                $hasil[(int) $equipmentId]['pernah_terbit'] = true;
            });

        return $hasil;
    }

    /**
     * Tabel 02-SRS REQ-ALT-05, dicek berurutan.
     *
     * `nonaktif` diperiksa PERTAMA walau barisnya di tabel paling bawah: dibaca
     * harfiah dia tidak pernah tercapai (setiap alat pasti "belum pernah" atau
     * "ada sertifikat terbit" lebih dulu). `belum_pernah` hanya bila TIDAK ada
     * sertifikat terbit DAN `tanggal_kalibrasi_terakhir` kosong. Jendela "mendekati" = anak tangga
     * pertama pengingat (30 hari), supaya layar dan push tidak berbeda.
     */
    public static function statusAlat(Equipment $alat, ?int $hari, bool $pernahTerbit): string
    {
        return match (true) {
            $alat->status === Equipment::STATUS_NONAKTIF => self::ALAT_NONAKTIF,
            // "Pernah" = ada sertifikat terbit di sistem ATAU tanggal kalibrasi
            // terakhir terisi (alat impor yang sertifikatnya terbit di luar
            // sistem). Keputusan orkestrator 20 Okt 2026, dilaporkan ke pemilik.
            ! $pernahTerbit && $alat->tanggal_kalibrasi_terakhir === null => self::ALAT_BELUM_PERNAH,
            $hari !== null && $hari < 0 => self::ALAT_LEWAT_JADWAL,
            $hari !== null && $hari <= PengingatJatuhTempoPelanggan::TANGGA_HARI[0] => self::ALAT_MENDEKATI_JADWAL,
            default => self::ALAT_TERKALIBRASI,
        };
    }

    /**
     * Bagian yang cuma ada di layar DETAIL & ubah alat (PL_Ubah_Alat, §42 B2):
     * kunci identitas dan catatan milik pelanggan.
     * Dihitung controller (butuh query) — daftar alat tidak membawanya.
     *
     * `catatan` di sini = `equipments.catatan_pelanggan`, BUKAN `catatan` lab.
     *
     * @return array<string, mixed>
     */
    public static function rincianUbah(Equipment $alat, bool $terkunci): array
    {
        return [
            // Isian awal PL_Ubah_Alat — salah satu dari sembilan kolom identitas.
            'resolusi' => $alat->resolusi,
            'catatan' => $alat->catatan_pelanggan,
            'terkunci' => $terkunci,
            'field_terkunci' => $terkunci ? array_keys(self::KUNCI_IDENTITAS) : [],
        ];
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
