<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use App\Services\Studio\Workbook\KunciJenisWorkbook;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu workbook master alat hasil impor, mode "Asli Excel" baca-saja
 * (Sidik Studio S1b, `docs/permintaan-user-7.md` §49 K-49-08..11).
 *
 * Milik satu lab (`organization_id`) — disaring tangan seperti model lab lain,
 * tidak ada global scope (`sidik-query-organisasi`). Isinya per sheet ada di
 * `WorkbookMasterLembar`.
 *
 * `sha256` = kunci idempoten impor per lab: csv → sha256 isi kanonik hasil
 * ekstraksi; xlsm → sha256 byte berkas asli terenkripsi (nama berkasnya di
 * disk privat, K-49-11). Sandi tidak pernah disimpan di mana pun.
 *
 * `berkas_terenkripsi`: true = berkas asli tersimpan masih terenkripsi; false =
 * sumbernya `.xlsm` ZIP polos dan disimpan apa adanya (K-49-11 "apa adanya",
 * tinjau S1b #5); null = tidak ada berkas asli (csv).
 *
 * Jenis master (K-49-13, 15 Okt 2026; `JenisWorkbook`): satu `aktif` per
 * (organization_id, `kunci_jenis`), sisanya riwayat (`digantikan_oleh_id`)
 * atau arsip (`diarsipkan_pada`, keluar dari Studio). Kolom `aktif` di DB
 * bernilai 1 atau NULL — indeks unik hanya bisa menjaga "satu aktif" begitu;
 * accessor-nya selalu bool dan `aktif = false` disimpan NULL. Baris yang
 * dibuat tanpa `kunci_jenis`/`versi` mendapatkannya dari nama (`booted`).
 *
 * @mixin IdeHelperWorkbookMaster
 */
#[Fillable([
    'organization_id', 'kode_profil', 'keluarga', 'kunci_jenis', 'aktif', 'versi', 'digantikan_oleh_id',
    'diarsipkan_pada', 'nama', 'sumber', 'sha256', 'sha256_hidup', 'ukuran',
    'jumlah_lembar', 'berkas_disk', 'berkas_path', 'berkas_terenkripsi', 'catatan', 'diimpor_oleh',
])]
class WorkbookMaster extends Model
{
    use Diaudit;

    public const SUMBER_CSV = 'csv';

    public const SUMBER_XLSM = 'xlsm';

    protected $table = 'workbook_master';

    protected static function booted(): void
    {
        // `sha256_hidup` (22 Okt 2026): = sha256 selama baris hidup; NULL
        // hanya untuk riwayat hasil `--ganti` (diisi ImporWorkbook). Unik
        // `(organization_id, sha256_hidup)` menggantikan unik sha256 lama.
        static::creating(static function (WorkbookMaster $w): void {
            if (! array_key_exists('sha256_hidup', $w->getAttributes())) {
                $w->sha256_hidup = $w->sha256;
            }
        });

        static::updating(static function (WorkbookMaster $w): void {
            if ($w->isDirty('sha256') && ! $w->isDirty('sha256_hidup') && $w->getOriginal('sha256_hidup') !== null) {
                $w->sha256_hidup = $w->sha256;
            }
        });

        static::creating(static function (WorkbookMaster $w): void {
            $w->kunci_jenis ??= KunciJenisWorkbook::dari($w->kode_profil, $w->keluarga, (string) $w->nama);

            if ($w->getAttributes()['versi'] ?? null) {
                return;
            }

            $w->versi = 1 + (int) static::query()
                ->where('organization_id', $w->organization_id)
                ->where('kunci_jenis', $w->kunci_jenis)
                ->max('versi');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ukuran' => 'integer',
            'jumlah_lembar' => 'integer',
            'berkas_terenkripsi' => 'boolean',
            'versi' => 'integer',
            'digantikan_oleh_id' => 'integer',
            'diarsipkan_pada' => 'datetime',
        ];
    }

    /**
     * true | NULL di DB (lihat kepala kelas). Baris yang baru dibuat tanpa
     * `aktif` memakai bawaan kolom (true) — accessor tidak melihat bawaan DB
     * sebelum `refresh()`, jadi pembuat yang peduli selalu mengisinya.
     *
     * @return Attribute<bool, bool|null>
     */
    protected function aktif(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $v): bool => (bool) $v,
            set: static fn (mixed $v): ?bool => $v ? true : null,
        );
    }

    /** @param  Builder<WorkbookMaster>  $q */
    public function scopeDiStudio(Builder $q): void
    {
        $q->whereNull('diarsipkan_pada');
    }

    /** @return BelongsTo<WorkbookMaster, $this> */
    public function digantikanOleh(): BelongsTo
    {
        return $this->belongsTo(self::class, 'digantikan_oleh_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pengimpor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diimpor_oleh');
    }

    /** @return HasMany<WorkbookMasterLembar, $this> */
    public function lembar(): HasMany
    {
        return $this->hasMany(WorkbookMasterLembar::class);
    }

    /** K-49-11: berkas asli terenkripsi tersimpan di disk privat. */
    public function berkasAsliTersimpan(): bool
    {
        return $this->berkas_disk !== null && $this->berkas_path !== null;
    }
}
