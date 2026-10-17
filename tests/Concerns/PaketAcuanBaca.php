<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Acuan\SumberAcuan;
use App\Support\JsonKanonik;

/**
 * Perkakas bersama test API baca Studio Data Acuan (B4): `IzinDataAcuanTest`,
 * `DataAcuanBacaTest`, `LembarKerjaVersiAcuanTest`.
 *
 * Versi 1 selalu lahir dari `acuan:impor-awal` (isi = berkas JSON repo apa
 * adanya). Versi berikutnya = isi versi dasar dengan perubahan yang disebut
 * test — angka gantinya angka UJI, bukan nilai master.
 */
trait PaketAcuanBaca
{
    /** `acuan:impor-awal micrometer` untuk organisasi ini; pulangkan versi 1. */
    protected function imporV1(Organization $organisasi): PaketAcuanVersi
    {
        SumberAcuan::lupakanCache();

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $organisasi->id])
            ->assertSuccessful();

        return PaketAcuanVersi::query()
            ->where('organization_id', $organisasi->id)
            ->where('nomor_versi', 1)
            ->sole();
    }

    /**
     * Versi baru di paket `$dasar`. `$ubah` menerima isi terurai dan
     * memulangkan isi baru. Draf belum punya `isi_sha256` (dikunci saat
     * diajukan, 03-SDD §2.2).
     *
     * @param  (callable(array<array-key, mixed>): array<array-key, mixed>)|null  $ubah
     */
    protected function versiBaru(
        PaketAcuanVersi $dasar,
        int $nomor,
        string $status,
        ?string $mulai = null,
        ?callable $ubah = null,
        ?string $sampai = null,
    ): PaketAcuanVersi {
        $isi = $dasar->isiTerurai();
        $kanonik = JsonKanonik::serialisasi($ubah !== null ? $ubah($isi) : $isi);

        return PaketAcuanVersi::create([
            'paket_acuan_id' => $dasar->paket_acuan_id,
            'organization_id' => $dasar->organization_id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => $nomor,
            'status' => $status,
            'isi' => $kanonik,
            'isi_sha256' => $status === PaketAcuanVersi::STATUS_DRAF ? null : JsonKanonik::sha256($kanonik),
            'skema_versi' => $dasar->skema_versi,
            'dasar_versi_id' => $dasar->id,
            'berlaku_mulai' => $mulai,
            'berlaku_sampai' => $sampai,
        ]);
    }

    /** Ganti nilai terkoreksi satu balok ukur (kunci mis. `19.0`). */
    protected static function ubahBalok(string $kunci, float $nilai): callable
    {
        return static function (array $isi) use ($kunci, $nilai): array {
            if (! array_key_exists($kunci, $isi['balok_ukur'])) {
                throw new \LogicException("balok {$kunci} tidak ada di isi dasar");
            }

            $isi['balok_ukur'][$kunci] = $nilai;

            return $isi;
        };
    }

    protected function pengguna(Organization $organisasi, string $role): User
    {
        return User::factory()->create([
            'organization_id' => $organisasi->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
        ]);
    }
}
