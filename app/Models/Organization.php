<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperOrganization
 */
#[Fillable([
    'nama', 'alamat', 'telepon', 'email', 'no_akreditasi', 'standar_akreditasi',
    'akreditasi_mulai', 'akreditasi_berakhir', 'logo_path', 'settings',
])]
class Organization extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** Default ambang peringatan H- kalau org belum ngatur sendiri (± sebulan). */
    public const DEFAULT_AMBANG_HARI = 30;

    /** Kunci setting di `organization.settings` buat ambang peringatan. */
    public const KEY_AMBANG_HARI = 'reminder_hari_sebelum';

    /** Interval kalibrasi baku: 1 tahun. */
    public const DEFAULT_MASA_BERLAKU_BULAN = 12;

    /**
     * Kunci setting buat masa berlaku baku sertifikat.
     *
     * Nama panjang ini dipakai karena `OrganizationSeeder` UDAH nulis kunci ini
     * sejak awal — cuma nggak pernah ada kode yang baca (masa berlakunya dipaku
     * `now()->addYear()`). Bikin kunci baru yang lebih pendek artinya lab yang
     * udah nyetel `masa_berlaku_sertifikat_bulan: 6` diam-diam tetap dapat 12
     * bulan, dan nggak ada yang sadar sampai ada alat lewat jatuh tempo.
     */
    public const KEY_MASA_BERLAKU_BULAN = 'masa_berlaku_sertifikat_bulan';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'akreditasi_mulai' => 'date',
            'akreditasi_berakhir' => 'date',
        ];
    }

    /**
     * Ambang "mendekati kadaluarsa" (H- berapa hari) yang berlaku buat org ini.
     *
     * SATU angka dipakai dua tempat, sengaja:
     * - pengingat alat mendekati jatuh tempo (`PengingatJatuhTempo`)
     * - badge `warning` di standar acuan (`Standard::statusKalibrasi()`)
     *
     * Alasannya: keduanya jawab pertanyaan yang sama ("berapa lama sebelum
     * sesuatu kadaluarsa lab mau diingetin"), dan yang paling penting dari
     * permintaan mobile itu **satu sumber** — kalau HP nentuin ambangnya sendiri,
     * dia bisa bilang VALID padahal backend nolak waktu approve, dan teknisi
     * kerja sia-sia.
     *
     * Konsekuensi yang perlu disadari: admin yang ngecilin ambang biar reminder
     * alat nggak terlalu sering JUGA ngecilin jendela warning standar. Kalau
     * suatu saat perlu dipisah, tambah kunci setting baru — jangan hardcode
     * angka kedua di tempat lain.
     */
    public function ambangPeringatanHari(): int
    {
        $nilai = $this->settings[self::KEY_AMBANG_HARI] ?? null;

        return is_numeric($nilai) && (int) $nilai > 0
            ? (int) $nilai
            : self::DEFAULT_AMBANG_HARI;
    }

    /**
     * Masa berlaku baku sertifikat, dalam bulan.
     *
     * Ini cuma DEFAULT — admin tetap bisa nimpa per sertifikat waktu approve
     * (`berlaku_sampai`). Gunanya biar interval yang lab ini hampir selalu pakai
     * nggak perlu diketik ulang tiap approve; ngetik tanggal manual tiap kali itu
     * justru sumber salah ketik di kolom yang nentuin kapan alat harus dikalibrasi
     * lagi.
     *
     * Dibatasi 1–120 bulan: 0 bikin sertifikat kadaluarsa di hari terbitnya, dan
     * di atas 10 tahun hampir pasti salah ketik.
     */
    public function masaBerlakuBulan(): int
    {
        $nilai = $this->settings[self::KEY_MASA_BERLAKU_BULAN] ?? null;

        return is_numeric($nilai) && (int) $nilai >= 1 && (int) $nilai <= 120
            ? (int) $nilai
            : self::DEFAULT_MASA_BERLAKU_BULAN;
    }

    /**
     * Lab yang akreditasinya kadaluarsa nggak boleh nerbitin sertifikat
     * terakreditasi. Dipakai waktu generate sertifikat (Minggu 08).
     */
    public function akreditasiMasihBerlaku(): bool
    {
        return $this->akreditasi_berakhir === null || $this->akreditasi_berakhir->isFuture();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** @return HasMany<Equipment, $this> */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
