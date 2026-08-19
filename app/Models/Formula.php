<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Rumus kalibrasi (Keputusan 5). Wadahnya; isinya ada di `FormulaVersion`.
 *
 * `kode` yang dirujuk kode program, bukan `nama` — nama boleh diubah admin kapan
 * aja, dan kalau kode nyari lewat nama, sekali rename bikin jalur hitungnya nggak
 * ketemu rumusnya.
 *
 * @mixin IdeHelperFormula
 */
#[Fillable(['organization_id', 'kode', 'nama', 'besaran', 'deskripsi'])]
class Formula extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** Rumus GUM buat jalur pH — yang sekarang dihitung `GumCalculator`. */
    public const KODE_GUM_PH = 'gum-ph';

    /** Rumus GUM buat jalur Turbidimeter (budget 4 komponen, lihat TurbidimeterProfile). */
    public const KODE_GUM_TURBIDI = 'gum-turbidi';

    /** Rumus GUM buat jalur Chlorin Meter (budget 5 komponen, lihat ChlorineProfile). */
    public const KODE_GUM_CHLORINE = 'gum-chlorine';

    /** Rumus GUM buat jalur Refractometer (budget 5 komponen, lihat RefractometerProfile). */
    public const KODE_GUM_REFRACTOMETER = 'gum-refractometer';

    /** Rumus GUM buat jalur Conductivity Meter (budget 4 komponen, lihat ConductivityProfile). */
    public const KODE_GUM_CONDUCTIVITY = 'gum-conductivity';

    /**
     * Rumus GUM buat jalur Spectrophotometer. Beda sendiri dari lima di atas:
     * budgetnya disusun per KELOMPOK titik (Holmium / Didynium / %T), bukan per
     * titik — lihat SpectrophotometerCalculator & SpectrophotometerProfile.
     */
    public const KODE_GUM_SPECTRO = 'gum-spectro';

    /**
     * Rumus GUM buat jalur Viscometer. Budgetnya 4 komponen dan lahir PER TITIK
     * (bukan per kelompok kayak spektro), tapi dua bahannya nggak ada di alat
     * lain: nilai acuan standar diinterpolasi linier dari tabel sertifikat
     * larutan pada suhu terukur, dan batas keberterimaannya (MPE) dihitung dari
     * spindle & RPM titik itu — lihat ViscometerProfile.
     */
    public const KODE_GUM_VISCOMETER = 'gum-viscometer';

    /** @return HasMany<FormulaVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormulaVersion::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Versi yang berlaku pada suatu tanggal.
     *
     * Dicari per TANGGAL, bukan "yang statusnya aktif", karena itu dua pertanyaan
     * beda: "mana yang dipakai sekarang" vs "mana yang dipakai waktu sesi tanggal
     * 26 Mei dikerjain". Yang kedua yang bikin hasil hitung lama bisa dijelasin.
     */
    public function versiPadaTanggal(Carbon|string $tanggal): ?FormulaVersion
    {
        $t = $tanggal instanceof Carbon ? $tanggal->toDateString() : Carbon::parse($tanggal)->toDateString();

        return $this->versions()
            ->where('status', FormulaVersion::STATUS_AKTIF)
            ->whereDate('effective_from', '<=', $t)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $t))
            ->orderByDesc('effective_from')
            ->first();
    }

    /** Versi yang berlaku SEKARANG. */
    public function versiAktif(): ?FormulaVersion
    {
        return $this->versiPadaTanggal(now());
    }
}
