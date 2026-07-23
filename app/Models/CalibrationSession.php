<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * @mixin IdeHelperCalibrationSession
 */
#[Fillable([
    'organization_id', 'equipment_id', 'teknisi_id', 'client_request_id', 'standard_id', 'reviewed_by',
    'calibration_method_id', 'thermohygro_standard_id',
    'nomor_sesi', 'nomor_order', 'input_method', 'status', 'keputusan', 'tanggal_kalibrasi',
    'tanggal_terima', 'lokasi', 'room_id', 'suhu_ruang', 'suhu_ketidakpastian', 'kelembaban',
    'kelembaban_ketidakpastian', 'suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir',
    'catatan_revisi', 'catatan_teknisi', 'submitted_at', 'reviewed_at',
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
            'suhu_ketidakpastian' => 'float',
            'kelembaban' => 'float',
            'kelembaban_ketidakpastian' => 'float',
            'suhu_awal' => 'float',
            'suhu_akhir' => 'float',
            'kelembaban_awal' => 'float',
            'kelembaban_akhir' => 'float',
        ];
    }

    /**
     * Field administratif — dihapus dari layar teknisi (spesifikasi poin 1) dan
     * dibuang dari payload-nya sama `CalibrationRequest`.
     *
     * Yang masuk daftar ini persis yang disebut spesifikasi: Order Number,
     * Calibration Method, Thermohygro Used, plus ketidakpastian kondisi ruang
     * (angka ± di Env. Condition — datangnya dari sertifikat thermohygro, bukan
     * dari yang dilihat teknisi di lapangan).
     *
     * `tanggal_terima`, `suhu_ruang`, `kelembaban`, dan `room_id` SENGAJA nggak
     * di sini: itu fakta lapangan yang cuma teknisi yang tau, dan spesifikasi
     * nggak nyuruh dihapus dari layar teknisi.
     *
     * @return array<int, string>
     */
    public static function fieldAdmin(): array
    {
        return [
            'nomor_order', 'calibration_method_id', 'thermohygro_standard_id',
            'suhu_ketidakpastian', 'kelembaban_ketidakpastian',
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

    /** Ruangan lab tempat sesi dikerjain — "Calibration Location" di sertifikat. */
    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Thermohygro yang dipakai nyatet kondisi ruang. `withTrashed()` dengan
     * alasan yang sama kayak `standard()`: ketertelusuran sertifikat lama.
     *
     * @return BelongsTo<Standard, $this>
     */
    public function thermohygro(): BelongsTo
    {
        return $this->belongsTo(Standard::class, 'thermohygro_standard_id')->withTrashed();
    }

    /**
     * IK yang dipakai. `withTrashed()` — revisi IK yang dipensiunin tetap harus
     * kebaca di sertifikat yang diterbitin waktu revisi itu masih berlaku.
     *
     * @return BelongsTo<CalibrationMethod, $this>
     */
    public function calibrationMethod(): BelongsTo
    {
        return $this->belongsTo(CalibrationMethod::class, 'calibration_method_id')->withTrashed();
    }

    /**
     * Kolom "Usage Check" di lembar kerja: standar mana aja yang dicentang
     * teknisi. `withoutGlobalScope(SoftDeletingScope::class)` dengan alasan yang
     * sama kayak `standard()` — standar yang udah dipensiunin tetap harus
     * kebaca di lembar kerja & sertifikat lama.
     *
     * @return BelongsToMany<Standard, $this>
     */
    public function standarDicek(): BelongsToMany
    {
        return $this->belongsToMany(Standard::class, 'calibration_session_standard')
            ->withPivot(['dipakai', 'keterangan'])
            ->withTimestamps()
            ->withoutGlobalScope(SoftDeletingScope::class);
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
