<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @mixin IdeHelperCustomer
 */
#[Fillable(['organization_id', 'nama', 'alamat', 'contact_person', 'telepon', 'email'])]
class Customer extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** Didaftarkan admin dari panel — asal semua baris sebelum jalur teknisi ada. */
    public const SUMBER_ADMIN = 'admin';

    /**
     * Diketik teknisi dari lapangan lewat `POST /api/customers/cepat`.
     * Langsung kepakai tanpa antrean persetujuan (keputusan pemilik proyek,
     * sejalan dengan K3/K4 buat nama alat).
     */
    public const SUMBER_TEKNISI = 'teknisi';

    /**
     * Dipilih dari hasil pencarian direktori luar, bukan diketik.
     *
     * Dibedakan dari [SUMBER_TEKNISI] karena bobot buktinya beda: nama & alamat
     * ini datang dari direktori tempat usaha, bukan dari ingatan orang yang
     * lagi berdiri di gerbang pabrik. Dua-duanya tetap **bukan** data legal —
     * lihat catatan di `DirektoriPerusahaan`.
     */
    public const SUMBER_DIREKTORI = 'direktori';

    /** @var list<string> */
    public const SUMBER = [self::SUMBER_ADMIN, self::SUMBER_TEKNISI, self::SUMBER_DIREKTORI];

    /**
     * Turunkan nama PT ke bentuk yang bisa diadu buat mencari kembar.
     *
     * Huruf besar-kecil, tanda baca, dan spasi ganda itu tiga cara paling sering
     * bikin satu perusahaan kedaftar dua kali: `PT. Maju Jaya`, `PT Maju Jaya`,
     * dan `pt maju  jaya` semuanya turun ke `pt maju jaya` di sini. Unique index
     * di kolom `nama` jalan di teks MENTAH, jadi ketiganya lolos berdampingan —
     * dan lab bangun tiga folder arsip buat satu pelanggan.
     *
     * Yang SENGAJA nggak dibuang: bentuk badan usahanya. `PT Maju` dan `CV Maju`
     * itu dua badan hukum berbeda dengan NPWP berbeda, dan menyamakan keduanya
     * bikin sertifikat mendarat ke perusahaan yang salah — persis kelas
     * kesalahan yang mau dicegah kolom ini.
     */
    public static function normalkanNama(string $nama): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($nama)));
    }

    protected static function booted(): void
    {
        // Diturunkan di model, bukan di controller: `nama_normal` yang meleset
        // dari `nama` bikin penjaga kembarnya diam-diam berhenti jalan, dan
        // nggak ada yang kelihatan salah sampai ada dua folder buat satu PT.
        static::saving(function (self $pelanggan): void {
            $pelanggan->nama_normal = self::normalkanNama((string) $pelanggan->nama);
        });
    }

    /**
     * Siapa yang mendaftarkan. Kosong buat baris lama yang lahir sebelum kolom
     * ini ada — dibiarkan kosong, bukan ditebak ke admin mana pun.
     *
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh_user_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Equipment, $this> */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * Semua sesi kalibrasi punya PT ini, lewat alat-alatnya.
     *
     * Ada supaya jumlah sertifikat per PT bisa dihitung sekali jalan
     * (`withCount`) di daftar arsip. Tanpa ini tiap baris PT butuh query
     * sendiri, dan yang kelihatan di layar cuma angka nol.
     *
     * @return HasManyThrough<CalibrationSession, Equipment, $this>
     */
    public function calibrationSessions(): HasManyThrough
    {
        return $this->hasManyThrough(CalibrationSession::class, Equipment::class);
    }
}
