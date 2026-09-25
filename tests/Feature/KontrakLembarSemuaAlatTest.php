<?php

namespace Tests\Feature;

use App\Http\Requests\CalibrationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kontrak definisi lembar ↔ cara HP menyusun payload, untuk SEMUA alat.
 *
 * Chaos review 25 Sep 2026 menemukan dua kelas cacat yang tidak menghasilkan
 * error di mana pun — definisi lembarnya kelihatan benar, HP-nya kelihatan
 * benar, dan datanya hilang di sambungan keduanya:
 *
 * 1. Tabel ber-`simpan_ke: spesifikasi_alat.X` DITANAM HP dengan menimpa
 *    kunci `X` utuh (`_tanamTabelSpesifikasi`: `induk[jalur.last] =
 *    {'baris': …}`). Isian `spesifikasi_alat.X.*` apa pun yang ditanam
 *    sebelumnya lenyap. Lembar Gaya kena: tabel preload menunjuk
 *    `spesifikasi_alat.gaya`, jadi satuan, standar, dan misalignment ketiga
 *    alat Gaya tidak pernah sampai server.
 * 2. Tabel ber-`simpan_ke: measurements[].K` mengirim deret `K` per titik.
 *    Tanpa aturan validasi `measurements.*.K`, deretnya lolos tanpa
 *    pemeriksaan bentuk — dan pada Proving Ring, `gaya_up`/`gaya_down` juga
 *    tidak dibaca penyusun pengukurannya sama sekali.
 *
 * Diuji dari DEFINISI, bukan dari daftar nama alat, supaya alat ke-43 ikut
 * terjaga tanpa satu baris pun di sini disentuh.
 */
class KontrakLembarSemuaAlatTest extends TestCase
{
    use RefreshDatabase;

    private const AWALAN_SPEK = 'spesifikasi_alat.';

    private const AWALAN_DERET = 'measurements[].';

    /** @return array<string, array{0: CalibrationProfile}> */
    public static function semuaProfil(): array
    {
        $hasil = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $p) {
            $hasil[$p->kode()] = [$p];
        }

        return $hasil;
    }

    /**
     * @return array{field: list<string>, tabel_spek: list<string>, deret: list<string>}
     */
    private static function jalur(CalibrationProfile $profil): array
    {
        $field = [];
        $tabelSpek = [];
        $deret = [];

        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $f) {
                $kode = (string) ($f['kode'] ?? '');
                if (str_starts_with($kode, self::AWALAN_SPEK)) {
                    $field[] = substr($kode, strlen(self::AWALAN_SPEK));
                }
            }

            foreach ($bagian['tabel'] ?? [] as $t) {
                $tujuan = (string) ($t['simpan_ke'] ?? '');

                if (str_starts_with($tujuan, self::AWALAN_SPEK)) {
                    $tabelSpek[] = substr($tujuan, strlen(self::AWALAN_SPEK));
                } elseif (str_starts_with($tujuan, self::AWALAN_DERET)) {
                    $kunci = substr($tujuan, strlen(self::AWALAN_DERET));
                    if ($kunci !== 'pembacaan') {
                        $deret[] = $kunci;
                    }
                }
            }
        }

        return ['field' => $field, 'tabel_spek' => $tabelSpek, 'deret' => array_values(array_unique($deret))];
    }

    #[DataProvider('semuaProfil')]
    public function test_tabel_spesifikasi_tidak_menimpa_isian_lain(CalibrationProfile $profil): void
    {
        $j = self::jalur($profil);

        $this->assertSame(
            count($j['tabel_spek']),
            count(array_unique($j['tabel_spek'])),
            "Dua tabel lembar {$profil->kode()} menunjuk simpan_ke yang sama — yang satu menimpa yang lain di HP.",
        );

        foreach ($j['tabel_spek'] as $tabel) {
            $ketimpa = array_values(array_filter(
                $j['field'],
                static fn (string $f): bool => $f === $tabel || str_starts_with($f, $tabel.'.'),
            ));

            $this->assertSame(
                [],
                $ketimpa,
                "Lembar {$profil->kode()}: tabel ber-simpan_ke `spesifikasi_alat.{$tabel}` menimpa isian "
                .implode(', ', array_map(static fn ($f) => "`spesifikasi_alat.{$f}`", $ketimpa))
                .' — HP menanam tabelnya sebagai `{baris: …}` di kunci itu dan isian di bawahnya hilang.',
            );
        }
    }

    #[DataProvider('semuaProfil')]
    public function test_tiap_deret_bernama_punya_aturan_validasi(CalibrationProfile $profil): void
    {
        $deret = self::jalur($profil)['deret'];

        if ($deret === []) {
            $this->addToAssertionCount(1);

            return;
        }

        Organization::factory()->create();
        $request = CalibrationRequest::create('/api/calibrations', 'POST');
        $request->setUserResolver(fn () => User::factory()->create());
        $aturan = $request->rules();

        foreach ($deret as $kunci) {
            $this->assertArrayHasKey(
                "measurements.*.{$kunci}",
                $aturan,
                "Lembar {$profil->kode()} mengirim deret `measurements[].{$kunci}` tanpa aturan validasi.",
            );
        }
    }
}
