<?php

namespace Tests\Concerns;

use App\Models\UncertaintyCalculation;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Jalankan banyak pemeriksaan profil di SATU test, lalu laporkan SEMUA yang
 * gagal sekaligus (PK-5, 18 Nov 2026).
 *
 * Kenapa bukan satu test per profil: tiap test `RefreshDatabase` + `DatabaseSeeder`
 * penuh memakan ~19 detik lokal dan satu seed di setiap shard CI. Kelas
 * `*SesiContohCocokMasterTest` kelompok B memeriksa 2-4 profil masing-masing; satu
 * seed per kelas menahan tambahan beban CI, dan pengumpulan kegagalan di sini
 * menjaga hal yang dikorbankan — gagal di profil pertama tidak menyembunyikan
 * profil berikutnya, jadi satu jalan CI cukup untuk melihat semua selisihnya.
 *
 * Yang ditangkap HANYA kegagalan assertion; galat lain (pengecualian kode) tetap
 * meledak apa adanya.
 */
trait MengumpulkanGagalPerProfil
{
    /** @param array<string, callable(): void> $pemeriksaan nama profil => pemeriksaannya */
    protected function periksaSemuaProfil(array $pemeriksaan): void
    {
        $gagal = [];

        foreach ($pemeriksaan as $profil => $periksa) {
            try {
                $periksa();
            } catch (AssertionFailedError $e) {
                $gagal[$profil] = $e->getMessage();
            }
        }

        $this->assertSame(
            '',
            implode("\n\n", array_map(
                static fn (string $profil, string $pesan): string => "### {$profil}\n{$pesan}",
                array_keys($gagal),
                $gagal,
            )),
            count($gagal).' dari '.count($pemeriksaan).' profil gagal: '.implode(', ', array_keys($gagal)),
        );
    }

    /**
     * Nilai TERSIMPAN di kolom `decimal(20,8)` sama dengan sel master.
     *
     * MySQL menyimpan delapan desimal, SQLite float penuh (AGENTS.md §Float beku titik seri) —
     * jadi selisih terbesar yang sah antara keduanya 5e-9. Toleransi 6e-9 menampung itu dan
     * tetap menolak angka yang benar-benar salah. Hanya untuk kolom decimal; angka CETAK
     * tidak pernah dilonggarkan, dan kolom JSON (15 digit signifikan) punya tolerans sendiri.
     */
    protected function assertTersimpanSama(float $master, mixed $tersimpan, string $pesan): void
    {
        $this->assertEqualsWithDelta($master, (float) $tersimpan, 6e-9, $pesan);
    }

    /**
     * Apakah jejak hasil hitung memuat catatan dengan `kode` atau `sumber` ini
     * (AGENTS.md §Olah data butir 4: penyimpangan dari master harus terbaca di
     * `type_b_components`, bukan hanya di komentar kode).
     *
     * @param  iterable<UncertaintyCalculation>  $hitungan
     */
    protected function jejakMemuat(iterable $hitungan, string $kode): bool
    {
        foreach ($hitungan as $h) {
            foreach ($h->type_b_components as $c) {
                if (is_array($c) && (($c['kode'] ?? null) === $kode || ($c['sumber'] ?? null) === $kode)) {
                    return true;
                }
            }
        }

        return false;
    }
}
