<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use JsonException;
use UnexpectedValueException;

/**
 * Satu versi data acuan (lapis 1) atau bentuk lembar (lapis 3) sebuah paket
 * (03-SDD §2.2).
 *
 * `isi` SENGAJA tidak di-cast `array`. Dia disimpan sebagai byte kanonik
 * (`App\Support\JsonKanonik`) dan `isi_sha256` dihitung atas byte itu; cast
 * array akan meng-encode ulang saat menyimpan dengan aturan `json_encode`
 * biasa (urutan kunci ikut masukan, `1.0E-5`), dan hash berhenti cocok dengan
 * isinya. Baca strukturnya lewat `isiTerurai()`.
 *
 * @mixin IdeHelperPaketAcuanVersi
 */
#[Fillable([
    'paket_acuan_id', 'organization_id', 'jenis', 'nomor_versi', 'status',
    'isi', 'isi_sha256', 'skema_versi', 'dasar_versi_id',
    'berlaku_mulai', 'berlaku_sampai', 'alasan', 'rujukan',
    'dibuat_oleh', 'diajukan_oleh', 'disahkan_oleh', 'ditolak_oleh',
    'diajukan_pada', 'disahkan_pada', 'ditolak_pada', 'catatan_keputusan',
    // Belum ber-FK: tabel simulasi lahir di P2 (lihat migrasi 2026_10_08_100000).
    'simulasi_terakhir_id',
    'lock_versi',
])]
class PaketAcuanVersi extends Model
{
    use Diaudit;

    protected $table = 'paket_acuan_versi';

    /** Lapis 1 — parameter & tabel referensi. */
    public const JENIS_DATA = 'data';

    /** Lapis 3 — bentuk lembar kerja. */
    public const JENIS_BENTUK = 'bentuk';

    public const STATUS_DRAF = 'draf';

    public const STATUS_DIAJUKAN = 'diajukan';

    public const STATUS_TERJADWAL = 'terjadwal';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_PENSIUN = 'pensiun';

    public const STATUS_DITOLAK = 'ditolak';

    public const STATUS_DITARIK = 'ditarik';

    public const STATUS_DIBUANG = 'dibuang';

    /** @return list<string> */
    public static function jenisSemua(): array
    {
        return [self::JENIS_DATA, self::JENIS_BENTUK];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAF,
            self::STATUS_DIAJUKAN,
            self::STATUS_TERJADWAL,
            self::STATUS_AKTIF,
            self::STATUS_PENSIUN,
            self::STATUS_DITOLAK,
            self::STATUS_DITARIK,
            self::STATUS_DIBUANG,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nomor_versi' => 'integer',
            'skema_versi' => 'integer',
            'lock_versi' => 'integer',
            'rujukan' => 'array',
            // Tanggal POLOS di respons — aturan yang sama dengan
            // `FormulaVersion::effective_from`.
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'diajukan_pada' => 'datetime',
            'disahkan_pada' => 'datetime',
            'ditolak_pada' => 'datetime',
        ];
    }

    /**
     * Isi kanonik sebagai array PHP.
     *
     * Gagal keras kalau isinya bukan objek/list JSON yang sah: isi rusak yang
     * diurai jadi `null` lalu dibaca sebagai "tabel kosong" adalah angka
     * karangan, bukan data acuan.
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    public function isiTerurai(): array
    {
        $hasil = json_decode((string) $this->isi, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($hasil)) {
            throw new UnexpectedValueException(
                "Isi paket_acuan_versi #{$this->getKey()} bukan objek/list JSON.",
            );
        }

        return $hasil;
    }

    /**
     * `isi` tidak disalin ke `audit_logs`.
     *
     * Isinya seluruh tabel acuan (Micrometer saja 175 sel); menyalinnya
     * tiap kali baris versi dibuat/diubah melipatgandakan dokumen itu di tabel
     * riwayat tanpa menambah keterbacaan. Jejak perubahan isi per sel sudah
     * dicatat `paket_acuan_suntingan` (nilai lama & baru per sel), dan
     * `isi_sha256` — yang membuktikan isi mana yang dikunci — tetap tercatat.
     *
     * @return list<string>
     */
    protected function kolomDilewat(): array
    {
        return ['updated_at', 'created_at', 'isi'];
    }

    /** @return BelongsTo<PaketAcuan, $this> */
    public function paketAcuan(): BelongsTo
    {
        return $this->belongsTo(PaketAcuan::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<PaketAcuanVersi, $this> */
    public function dasarVersi(): BelongsTo
    {
        return $this->belongsTo(self::class, 'dasar_versi_id');
    }

    /** @return HasMany<PaketAcuanSuntingan, $this> */
    public function suntingan(): HasMany
    {
        return $this->hasMany(PaketAcuanSuntingan::class);
    }

    /**
     * Hasil hitung yang distempel versi ini — pasangan
     * `UncertaintyCalculation::paketAcuanVersi()`.
     *
     * @return HasMany<UncertaintyCalculation, $this>
     */
    public function uncertaintyCalculations(): HasMany
    {
        return $this->hasMany(UncertaintyCalculation::class);
    }
}
