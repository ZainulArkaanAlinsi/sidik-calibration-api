<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu sheet dari `WorkbookMaster` (Sidik Studio S1b).
 *
 * TIDAK punya `organization_id`: diraih lewat induknya, dan induk itu yang
 * disaring (pola `RawMeasurement` → `CalibrationSession`). Jangan dikueri dari
 * akarnya tanpa lewat workbook milik lab pemanggil.
 *
 * `sel` = daftar sel kanonik (`KanonikWorkbook`), disimpan sebagai teks
 * LONGTEXT byte-kanonik dan TIDAK ikut `toArray()` bawaan — bisa megabyte.
 * Baca lewat `daftarSel()`; daftar lembar dimuat dengan `KOLOM_TANPA_SEL`.
 *
 * K-49-12 (13 Okt 2026): isi DATABASE / SERTIFIKAT disimpan apa adanya;
 * impor format 3 selalu `dikecualikan = false` dan hanya menandai
 * `tautan_tabel`. Baris lama ber-`dikecualikan` (format 1-2, K-49-08 yang
 * sudah dicabut) tetap ber-`sel` `[]`, dan `daftarSel()` menjaganya.
 *
 * @mixin IdeHelperWorkbookMasterLembar
 */
#[Fillable([
    'workbook_master_id', 'urutan', 'nama', 'tersembunyi', 'dikecualikan', 'alasan_dikecualikan',
    'tautan_tabel', 'jumlah_baris', 'jumlah_kolom', 'jumlah_sel', 'jumlah_rumus', 'tata_letak', 'sel',
])]
class WorkbookMasterLembar extends Model
{
    public const TERSEMBUNYI_TIDAK = 'tidak';

    public const TERSEMBUNYI_HIDDEN = 'hidden';

    public const TERSEMBUNYI_VERY_HIDDEN = 'veryHidden';

    /** Kolom untuk daftar lembar — semua kecuali `sel`. */
    public const KOLOM_TANPA_SEL = [
        'id', 'workbook_master_id', 'urutan', 'nama', 'tersembunyi', 'dikecualikan', 'alasan_dikecualikan',
        'tautan_tabel', 'jumlah_baris', 'jumlah_kolom', 'jumlah_sel', 'jumlah_rumus', 'tata_letak',
        'created_at', 'updated_at',
    ];

    protected $table = 'workbook_master_lembar';

    /** @var list<string> */
    protected $hidden = ['sel'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'dikecualikan' => 'boolean',
            'jumlah_baris' => 'integer',
            'jumlah_kolom' => 'integer',
            'jumlah_sel' => 'integer',
            'jumlah_rumus' => 'integer',
            'tata_letak' => 'array',
        ];
    }

    /** @return BelongsTo<WorkbookMaster, $this> */
    public function workbook(): BelongsTo
    {
        return $this->belongsTo(WorkbookMaster::class, 'workbook_master_id');
    }

    /**
     * Sel kanonik sebagai array PHP. Lembar ber-`dikecualikan` (hanya baris
     * lama format 1-2) → `[]` apa pun isi kolomnya.
     *
     * @return list<array<string, mixed>>
     */
    public function daftarSel(): array
    {
        if ($this->dikecualikan) {
            return [];
        }

        $mentah = $this->getAttribute('sel');

        if (! is_string($mentah) || $mentah === '') {
            return [];
        }

        /** @var list<array<string, mixed>> $sel */
        $sel = json_decode($mentah, true, 512, JSON_THROW_ON_ERROR);

        return $sel;
    }
}
