<?php

namespace Tests\Unit;

use App\Models\CalibrationSession;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

/**
 * SEMUA profil balikin `peringatanSesi()` dalam bentuk yang sama.
 *
 * **Kenapa test ini ada.** `CalibrationValidator::periksaPeringatanProfil()`
 * mbungkus tiap temuan lewat `fn (array $p) => $this->temuan(..., $p['kode'],
 * $p['pesan'])`. Satu profil yang balikin string bikin seluruh pemeriksaan
 * sesi meledak — bukan salah satu peringatannya yang hilang, tapi tombol CHECK
 * dan APPROVE-nya yang mati total:
 *
 *     Argument #1 ($p) must be of type array, string given
 *
 * Itu yang kejadian sama `GasDetectorProfile`: dia sendirian balikin
 * `list<string>` sementara Viscometer & Conductivity balikin
 * `list<array{kode, pesan}>`. Docblock-nya pun ikut nulis `@return
 * list<string>`, jadi yang salah bukan cuma kodenya — niatnya ikut kesalin
 * salah.
 *
 * **Test lamanya nggak nangkep, malah ngunci yang salah.**
 * `GasDetectorBudgetTest` manggil `peringatanSesi()` langsung lalu
 * `assertStringContainsString(..., $peringatan[0])` — hijau justru KARENA
 * bentuknya string. Test yang ngetes satu profil sendirian nggak bisa lihat
 * bahwa profil itu beda sendiri dari saudara-saudaranya.
 *
 * Makanya yang dijaga di sini KONTRAK BERSAMANYA, dan dijalanin ke seluruh
 * isi registry — jadi profil alat ke-11 yang nanti ditambah lab ikut kena
 * tanpa ada yang perlu inget nulis test-nya.
 */
class PeringatanProfilBentukTest extends TestCase
{
    /**
     * Sesi yang sengaja disusun buat MEMANCING peringatan sebanyak mungkin:
     * tekanan udara kosong, dan dua titik yang gampang kebaca sebagai gas yang
     * sama. Profil yang emang nggak punya peringatan balik `[]` dan itu sah —
     * yang diuji bentuk isinya, bukan jumlahnya.
     */
    private function sesiPemancing(): CalibrationSession
    {
        $sesi = new CalibrationSession([
            'tekanan_awal' => null,
            'tekanan_akhir' => null,
            'spesifikasi_alat' => [],
        ]);

        $sesi->setRelation('uncertaintyCalculations', new EloquentCollection([
            new UncertaintyCalculation(['titik_ke' => 1, 'titik_ukur' => 25.0]),
            new UncertaintyCalculation(['titik_ke' => 2, 'titik_ukur' => 26.0]),
        ]));

        return $sesi;
    }

    public function test_semua_profil_balikin_kode_dan_pesan(): void
    {
        $sesi = $this->sesiPemancing();
        $adaYangNgeluarinPeringatan = false;

        foreach (app(CalibrationProfileRegistry::class)->semua() as $kunci => $profil) {
            $peringatan = $profil->peringatanSesi($sesi);

            $this->assertIsList(
                $peringatan,
                "Profil [$kunci] harus balikin list, bukan array berkunci."
            );

            foreach ($peringatan as $i => $p) {
                $adaYangNgeluarinPeringatan = true;

                $this->assertIsArray(
                    $p,
                    "Profil [$kunci] peringatan ke-$i balikin ".gettype($p).', '
                    .'bukan array. Bentuk yang bener: '
                    ."['kode' => ..., 'pesan' => ...]. Satu profil yang beda "
                    .'bikin CalibrationValidator meledak buat SEMUA sesi alat itu.'
                );

                $this->assertArrayHasKey('kode', $p, "Profil [$kunci] peringatan ke-$i nggak punya 'kode'.");
                $this->assertArrayHasKey('pesan', $p, "Profil [$kunci] peringatan ke-$i nggak punya 'pesan'.");

                $this->assertIsString($p['kode']);
                $this->assertIsString($p['pesan']);
                $this->assertNotSame('', trim($p['pesan']), "Profil [$kunci] peringatan ke-$i pesannya kosong.");
            }
        }

        // Penjaga buat penjaganya. Kalau sesi pemancingnya suatu hari berhenti
        // mancing apa pun, test di atas jadi hijau tanpa memeriksa satu bentuk
        // pun — hijau yang nggak berarti apa-apa.
        $this->assertTrue(
            $adaYangNgeluarinPeringatan,
            'Nggak ada satu profil pun yang ngeluarin peringatan buat sesi pemancing ini, '
            .'jadi bentuknya nggak beneran keuji. Sesuaikan sesiPemancing().'
        );
    }
}
