<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Satu alat di dalam sebuah order kalibrasi. */
#[Fillable([
    'order_id', 'equipment_id', 'teknisi_id', 'kondisi_terima', 'kelengkapan', 'catatan',
    // Pelacakan paket: dua tahap FISIK yang tidak punya sumber lain. Enam tahap
    // lainnya diturunkan `TahapPaket` dari status sesi & sertifikat.
    'tahap_fisik', 'tahap_fisik_pada', 'tahap_fisik_oleh', 'diserahkan_kepada',
])]
class OrderItem extends Model
{
    use HasFactory;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * Teknisi yang DITUGASKAN ngerjain alat ini. Beda sama
     * `CalibrationSession::teknisi()` yang nyatet siapa yang SUDAH ngerjain —
     * yang itu kepake di sertifikat dan nggak boleh berubah, yang ini rencana
     * kerja dan boleh dioper ke orang lain.
     *
     * @return BelongsTo<User, $this>
     */
    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }

    /**
     * Bisa lebih dari satu: sesi yang ditolak admin harus diulang, dan
     * riwayat percobaan sebelumnya tetap disimpen buat jejak audit.
     *
     * @return HasMany<CalibrationSession, $this>
     */
    public function calibrationSessions(): HasMany
    {
        return $this->hasMany(CalibrationSession::class);
    }

    /**
     * Sesi TERAKHIR untuk baris paket ini — sumber tahap pelacakan.
     *
     * Lewat `calibration_sessions.order_item_id`, bukan lewat `equipment_id`:
     * satu alat bisa masuk dua paket (kalibrasi ulang tahun berikutnya), dan
     * relasi lewat alat akan membaca sesi milik paket lain. Sesi yang ditolak
     * lalu diulang punya baris sendiri; yang relevan untuk pelacakan cuma yang
     * terbaru, dan `latestOfMany()` menjawab itu di SQL.
     *
     * @return HasOne<CalibrationSession, $this>
     */
    public function sesiTerakhir(): HasOne
    {
        return $this->hasOne(CalibrationSession::class)->latestOfMany();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tahap_fisik_pada' => 'datetime'];
    }
}
