<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'equipment_id', 'folder_id', 'order_item_id', 'teknisi_id', 'client_request_id', 'standard_id', 'reviewed_by',
    'nomor_sesi', 'nomor_order', 'input_method', 'status', 'keputusan', 'tanggal_kalibrasi',
    'tanggal_terima', 'lokasi', 'suhu_ruang', 'kelembaban', 'catatan_revisi', 'submitted_at', 'reviewed_at',
    // Kondisi lingkungan rinci (worksheet pH): awal/akhir + koreksi + U95% + label thermohygro.
    'suhu_ruang_awal', 'suhu_ruang_akhir', 'kelembaban_awal', 'kelembaban_akhir',
    'suhu_ruang_koreksi', 'kelembaban_koreksi', 'suhu_ruang_u95', 'kelembaban_u95', 'thermohygro',
])]
class CalibrationSession extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MENUNGGU_APPROVAL = 'menunggu_approval';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_PERLU_REVISI = 'perlu_revisi';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_kalibrasi' => 'date',
            'tanggal_terima' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'suhu_ruang' => 'float',
            'kelembaban' => 'float',
            'suhu_ruang_awal' => 'float',
            'suhu_ruang_akhir' => 'float',
            'kelembaban_awal' => 'float',
            'kelembaban_akhir' => 'float',
            'suhu_ruang_koreksi' => 'float',
            'kelembaban_koreksi' => 'float',
            'suhu_ruang_u95' => 'float',
            'kelembaban_u95' => 'float',
        ];
    }

    /**
     * Titik ukur yang NENTUIN hasil sesi: yang marginnya paling mepet ke batas
     * toleransi (|error| + U terbesar).
     *
     * Sesi punya banyak titik, tapi sertifikat cuma nampilin satu keputusan —
     * dan keputusannya digerakin sama titik terburuk. Satu titik FAIL bikin
     * seluruh sesi FAIL, walaupun titik lainnya lolos semua.
     */
    public function titikPenentu(): ?UncertaintyCalculation
    {
        return $this->uncertaintyCalculations
            ->sortByDesc(fn (UncertaintyCalculation $titik): float => abs($titik->error) + $titik->ketidakpastian_diperluas)
            ->first();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * Folder arsip tempat sesi ini disimpen. Null buat sesi lama (sebelum
     * fitur folder) atau sesi yang alatnya belum punya pelanggan.
     *
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * Alat yang diterima lewat order, kalau sesinya emang lahir dari order.
     * Nullable: sesi lama (sebelum ada entitas order) dan sesi dadakan yang
     * dibikin teknisi langsung nggak punya ini.
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }

    /** Admin yang approve/reject — penandatangan sertifikat. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * `withTrashed()` itu WAJIB di sini, bukan pemanis.
     *
     * Standar yang dipensiunin di-soft-delete. Tanpa ini, sesi kalibrasi dari
     * tahun lalu bakal balikin `standar_acuan: null` begitu standarnya dihapus —
     * ketertelusurannya ilang, padahal itu justru yang dicari asesor waktu audit.
     *
     * @return BelongsTo<Standard, $this>
     */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class)->withTrashed();
    }

    /** @return HasMany<RawMeasurement, $this> */
    public function rawMeasurements(): HasMany
    {
        return $this->hasMany(RawMeasurement::class);
    }

    /** @return HasMany<UncertaintyCalculation, $this> */
    public function uncertaintyCalculations(): HasMany
    {
        return $this->hasMany(UncertaintyCalculation::class);
    }

    /** @return HasOne<Certificate, $this> */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }
}
