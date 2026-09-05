<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu nilai pada satu pembacaan — field tunggal atau sel tabel — berikut
 * angka yang akhirnya dipakai teknisi.
 *
 * ## Model ANAK: nggak punya `organization_id` sendiri
 *
 * Organisasinya dibawa [DokumenBacaan]. Artinya tabel ini **nggak boleh
 * dikueri dari akarnya**: `DokumenBacaanNilai::query()->...` tanpa lewat
 * induknya itu query lintas-organisasi, walau nggak ada kolom yang kelihatan
 * salah dan walau balasannya 200.
 *
 * Masuknya lewat relasi induk yang sudah tersaring (`$bacaan->nilai()`), atau
 * `whereHas` ke induknya.
 *
 * @mixin IdeHelperDokumenBacaanNilai
 */
#[Fillable([
    'dokumen_bacaan_id', 'kunci', 'jenis', 'bagian_nama', 'label',
    'baris_ke', 'kolom_ke', 'tipe', 'satuan',
    // Teks, bukan desimal — jalur generik membaca nama, tanggal, dan checkbox
    // juga, dan `25,4` disimpan apa adanya biar sama persis sama kertasnya.
    'nilai_baca', 'nilai_final',
    'sumber', 'keyakinan', 'status', 'halaman', 'kotak',
    'cocok', 'dikoreksi_oleh', 'dikoreksi_pada',
])]
class DokumenBacaanNilai extends Model
{
    protected $table = 'dokumen_bacaan_nilai';

    public const JENIS_FIELD = 'field';

    public const JENIS_SEL = 'sel';

    public const STATUS_OK = 'OK';

    public const STATUS_PERLU_REVIEW = 'REVIEW_REQUIRED';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'baris_ke' => 'integer',
            'kolom_ke' => 'integer',
            'halaman' => 'integer',
            'keyakinan' => 'float',
            'kotak' => 'array',
            'cocok' => 'boolean',
            'dikoreksi_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<DokumenBacaan, $this> */
    public function bacaan(): BelongsTo
    {
        return $this->belongsTo(DokumenBacaan::class, 'dokumen_bacaan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function dikoreksiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikoreksi_oleh');
    }
}
