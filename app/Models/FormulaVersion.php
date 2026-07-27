<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu versi rumus kalibrasi (Keputusan 5).
 *
 * Dua aturan yang dijaga di sini, dan dua-duanya soal **hasil hitung bisa
 * dijelasin**:
 *
 * 1. **Rentang berlaku antar-versi satu rumus nggak boleh tumpang tindih.** Kalau
 *    tumpang tindih, buat satu tanggal ada dua versi yang sah-sah aja — dan
 *    pertanyaan "sesi 26 Mei dihitung pakai versi mana" jadi nggak punya jawaban.
 *    Itu justru pertanyaan yang bikin fitur ini ada.
 * 2. **Cuma satu versi `aktif` per rumus pada satu waktu.** Turunan dari aturan
 *    pertama, tapi dijaga terpisah biar pesan errornya jelas.
 *
 * Validasinya di APLIKASI, bukan DB: batasan "rentang tanggal nggak boleh tumpang
 * tindih" nggak bisa diungkapin sebagai unique index.
 *
 * @mixin IdeHelperFormulaVersion
 */
#[Fillable([
    'formula_id', 'organization_id', 'nomor_versi', 'sumber', 'parameter',
    'ekspresi', 'status', 'effective_from', 'effective_until', 'catatan', 'created_by',
])]
class FormulaVersion extends Model
{
    use Diaudit, HasFactory;

    /** Dihitung `GumCalculator` yang ditanam di kode program. */
    public const SUMBER_KODE = 'kode';

    /** Dihitung dari `ekspresi` di baris ini (butuh evaluator; belum ada). */
    public const SUMBER_DATABASE = 'database';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_ARSIP = 'arsip';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_AKTIF, self::STATUS_ARSIP];
    }

    /** @return list<string> */
    public static function sumbers(): array
    {
        return [self::SUMBER_KODE, self::SUMBER_DATABASE];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parameter' => 'array',
            'ekspresi' => 'array',
            // Tanggal POLOS di respons — aturan yang sama kayak field `date` lain
            // di API ini sejak 26 Jul.
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /** @return BelongsTo<Formula, $this> */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Hasil hitung yang distempel versi ini.
     *
     * Dipakai buat nolak pengarsipan versi yang udah kepakai: kalau diarsipin,
     * angka yang udah terbit nggak bisa dijelasin lagi — kebalikan dari gunanya
     * fitur ini.
     *
     * @return HasMany<UncertaintyCalculation, $this>
     */
    public function uncertaintyCalculations(): HasMany
    {
        return $this->hasMany(UncertaintyCalculation::class);
    }

    /** Berapa hasil hitung yang udah pakai versi ini. */
    public function jumlahHasilHitung(): int
    {
        return $this->uncertaintyCalculations()->count();
    }

    /**
     * Versi lain dari rumus yang sama yang rentang berlakunya nabrak versi ini.
     *
     * Cuma yang statusnya `aktif` yang dihitung nabrak: `draft` belum berlaku dan
     * `arsip` udah nggak dipakai, jadi dua draft dengan rentang sama itu wajar —
     * admin bisa nyiapin beberapa calon sebelum milih.
     *
     * @return Builder<FormulaVersion>
     */
    public function bentrokRentang(): Builder
    {
        $mulai = $this->effective_from instanceof Carbon
            ? $this->effective_from->toDateString()
            : (string) $this->effective_from;

        $sampai = $this->effective_until instanceof Carbon
            ? $this->effective_until->toDateString()
            : ($this->effective_until === null ? null : (string) $this->effective_until);

        return static::query()
            ->where('formula_id', $this->formula_id)
            ->where('status', self::STATUS_AKTIF)
            ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            // Dua rentang tumpang tindih kalau: mulai-A <= sampai-B DAN mulai-B <=
            // sampai-A. `null` di `effective_until` artinya "tak terbatas", jadi
            // sisi itu selalu memenuhi.
            ->where(function (Builder $q) use ($mulai, $sampai): void {
                $q->where(function (Builder $x) use ($sampai): void {
                    if ($sampai === null) {
                        return; // rentang ini tak terbatas → selalu nabrak sisi kiri
                    }

                    $x->whereDate('effective_from', '<=', $sampai);
                })
                    ->where(function (Builder $x) use ($mulai): void {
                        $x->whereNull('effective_until')
                            ->orWhereDate('effective_until', '>=', $mulai);
                    });
            });
    }

    /** Pesan siap-pakai kalau rentangnya nabrak; `null` kalau aman. */
    public function pesanBentrok(): ?string
    {
        $lain = $this->bentrokRentang()->first();

        if ($lain === null) {
            return null;
        }

        $sampai = $lain->effective_until?->toDateString() ?? 'sekarang';

        return "Rentang berlakunya nabrak versi {$lain->nomor_versi} "
            ."({$lain->effective_from?->toDateString()} s/d {$sampai}). "
            .'Satu tanggal cuma boleh punya satu versi aktif, biar hasil hitungnya '
            .'bisa dijelasin pakai versi mana.';
    }

    /** Nomor versi berikutnya buat satu rumus. */
    public static function nomorBerikutnya(int $formulaId): int
    {
        return (int) static::where('formula_id', $formulaId)->max('nomor_versi') + 1;
    }
}
