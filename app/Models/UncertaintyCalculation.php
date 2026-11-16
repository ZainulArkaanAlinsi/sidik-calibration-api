<?php

namespace App\Models;

use App\Casts\JejakTypeB;
use App\Support\PresisiPenuh;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperUncertaintyCalculation
 */
#[Fillable([
    'calibration_session_id', 'standard_id', 'titik_ke', 'titik_ukur', 'rata_rata', 'error', 'koreksi',
    'standar_deviasi', 'jumlah_pengulangan', 'type_a', 'type_b_components', 'type_b',
    'ketidakpastian_gabungan', 'faktor_cakupan_k', 'derajat_kebebasan_efektif',
    'ketidakpastian_diperluas', 'toleransi', 'keputusan', 'metode', 'calculated_at',
    'formula_version_id',
    // Stempel versi data acuan (migrasi 2026_10_08_100100); null = dari berkas JSON.
    'paket_acuan_versi_id',
])]
class UncertaintyCalculation extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Seperti `array`, tetapi kunci beku `presisi_penuh` tidak terbaca.
            'type_b_components' => JejakTypeB::class,
            'calculated_at' => 'datetime',
            'titik_ukur' => 'float',
            'rata_rata' => 'float',
            'error' => 'float',
            'koreksi' => 'float',
            'standar_deviasi' => 'float',
            'type_a' => 'float',
            'type_b' => 'float',
            'ketidakpastian_gabungan' => 'float',
            'faktor_cakupan_k' => 'float',
            'ketidakpastian_diperluas' => 'float',
            'toleransi' => 'float',
        ];
    }

    /**
     * Baris BARU membekukan float aslinya sebelum masuk kolom `decimal(20,8)`
     * ([PresisiPenuh]) — titik seri sertifikat dicetak seperti master hanya
     * kalau sisa biner double-nya selamat sampai render.
     *
     * Dipasang di `save()`, bukan di event `creating`: seeder dijalankan
     * `WithoutModelEvents`, dan penulis baru yang lupa memanggil pembekuan tidak
     * menghasilkan error apa pun — cuma angka cetak yang diam-diam lain dari
     * master. Jalur simpan sesi (`CalibrationController`) membekukan SEBELUM
     * pembulatan 8 desimal; kunci yang sudah ada tidak ditimpa di sini.
     *
     * Isi jejak dibaca MENTAH ([PresisiPenuh::jejakMentah]), bukan lewat
     * atribut: atribut menyembunyikan kunci beku (cast [JejakTypeB]) dan, untuk
     * penulis yang mengisi string JSON mentah, mengembalikan string. Hook ini
     * tidak boleh melempar apa pun bentuk jejaknya.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            $jejak = PresisiPenuh::jejakMentah($this);
            $dibekukan = PresisiPenuh::sisipkan($jejak, $this->getAttributes());

            if ($dibekukan !== $jejak) {
                $this->type_b_components = $dibekukan;
            }
        }

        return parent::save($options);
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /**
     * Standar yang beneran dipakai buat titik ini — bisa beda dari standar
     * default sesi. `withTrashed()` — sama kayak `CalibrationSession::standard()`
     * — karena standar yang udah di-retire tetap harus bisa ditelusuri di
     * sertifikat lama.
     */
    /** @return BelongsTo<Standard, $this> */
    public function standard(): BelongsTo
    {
        $relasi = $this->belongsTo(Standard::class);
        $relasi->withTrashed();

        return $relasi;
    }

    /**
     * Versi rumus yang MENGHASILKAN angka di baris ini (Keputusan 5).
     *
     * Ini yang bikin hasil hitung lama tetap bisa dijelasin sesudah rumusnya
     * diubah. `null` cuma buat baris yang udah ada sebelum fitur ini dipasang —
     * dan itu jujur: versinya beneran nggak diketahui, dan nebak lebih buruk.
     *
     * @return BelongsTo<FormulaVersion, $this>
     */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /**
     * Versi data acuan yang dipakai menghitung baris ini (Studio Data Acuan).
     *
     * `null` = tabel acuan dibaca dari berkas JSON, bukan dari versi di
     * database. Kolomnya `nullOnDelete`: hilangnya versi tidak boleh menyeret
     * hasil hitung yang sudah dipakai sertifikat.
     *
     * @return BelongsTo<PaketAcuanVersi, $this>
     */
    public function paketAcuanVersi(): BelongsTo
    {
        return $this->belongsTo(PaketAcuanVersi::class);
    }
}
