<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu perusahaan di direktori rujukan lab — BUKAN pelanggan.
 *
 * Bedanya dengan [Customer] bukan soal rapi, dan gampang tertukar karena
 * bentuknya mirip:
 *
 * | | `customers` | `direktori_lokal` |
 * |---|---|---|
 * | Milik siapa | satu lab (`organization_id`) | tidak ada yang memiliki |
 * | Isinya | perusahaan yang BENERAN dilayani | petunjuk hasil impor |
 * | Penanggung jawab | `dibuat_oleh_user_id` | tidak ada, dan memang tidak bisa |
 * | Boleh tercetak di sertifikat | ya | **tidak**, sebelum ada orang memilihnya |
 * | Disalin ke HP | ya, lewat `SimpananPelanggan` | **tidak pernah** |
 *
 * Baris di sini pindah jadi `customers` HANYA lewat teknisi yang memilihnya di
 * layar, dan yang lahir di sana baris baru dengan `sumber = direktori` plus
 * `direktori_ref` yang menunjuk balik ke sini.
 *
 * **Sengaja tanpa [Diaudit].** Riwayat perubahan gunanya melacak siapa mengubah
 * data lab; tabel ini tidak memuat data lab, dan impor sepuluh ribu baris akan
 * menenggelamkan `audit_logs` dengan baris yang tidak ada yang membacanya.
 * Jejaknya ada di tempat yang tepat: `sumber` + `updated_at` per baris, dan
 * ringkasan tiap impor di keluaran perintahnya.
 *
 * @mixin IdeHelperDirektoriLokal
 */
#[Fillable(['sumber', 'ref', 'nama', 'alamat', 'kota', 'provinsi'])]
class DirektoriLokal extends Model
{
    protected $table = 'direktori_lokal';

    /** Kawasan Industri Jababeka — 450 perusahaan, sumbernya bertanggal 2020. */
    public const SUMBER_JABABEKA = 'jababeka';

    /** Indonetwork — 9.870 perusahaan, isian mandiri tiap perusahaan. */
    public const SUMBER_INDONETWORK = 'indonetwork';

    /** @var list<string> */
    public const SUMBER = [self::SUMBER_JABABEKA, self::SUMBER_INDONETWORK];

    /**
     * Jaga `nama_normal` selalu ikut `nama`, persis seperti [Customer].
     *
     * Diturunkan di model, bukan di perintah impor: kalau impor punya salinan
     * aturannya sendiri, cukup satu perubahan di `Customer::normalkanNama()`
     * buat bikin pencarian di sini berhenti cocok dengan penjaga kembar
     * `customers` — dan tidak ada satu pun error yang menandainya.
     */
    protected static function booted(): void
    {
        static::saving(function (self $baris): void {
            $baris->nama_normal = Customer::normalkanNama((string) $baris->nama);
        });
    }

    /**
     * Penanda asal yang disimpan di `customers.direktori_ref` waktu teknisi
     * memilih baris ini.
     *
     * Berawalan `lokal:` supaya bisa dibedakan dari ref Google/OSM yang bentuknya
     * buram — admin yang merapikan master perlu tahu alamat itu datang dari
     * direktori yang mana sebelum memutuskan mempercayainya.
     */
    public function refDirektori(): string
    {
        return "lokal:{$this->sumber}:{$this->ref}";
    }
}
