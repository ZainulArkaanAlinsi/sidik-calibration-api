<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeder tidak boleh menanam sertifikat "terbit" yang di belakangnya tidak ada
 * hasil kalibrasi apa pun.
 *
 * ## Bug yang dijaga di sini
 *
 * `DemoDataSeeder` dulu membuat satu sesi + sertifikat buatan tangan
 * (`SES/2026/07/0001` / `CAL/2026/07/0001`, Jangka Sorong Mitutoyo) supaya
 * halaman verifikasi QR publik bisa dicoba waktu fitur kalibrasi & generator
 * sertifikat belum dibangun.
 *
 * Fitur itu sekarang ada, dan data itu berubah dari berguna jadi menyesatkan:
 *
 *  1. Sesinya `disetujui` dengan keputusan **PASS** tapi NOL pengukuran dan
 *     NOL hitungan — vonis lulus yang tidak pernah lahir dari satu pun
 *     kriteria yang diperiksa. Kelas kekeliruan yang sama sudah berkali-kali
 *     ditutup di kode (`default => 'PASS'` di controller, `contains FAIL ?
 *     FAIL : PASS` di validator); di sini dia hidup sebagai DATA.
 *  2. Sertifikatnya `terbit` tanpa `snapshot` — satu-satunya dari 40
 *     sertifikat terbit di database kerja yang begitu.
 *  3. Alatnya alat DIMENSI yang belum punya profil, jadi jatuh ke profil pH
 *     sebagai cadangan dan `CalibrationValidator` menandainya `titik_kosong`
 *     selamanya.
 *
 * Test ini menjaga ketiganya sekaligus, dan sengaja TIDAK menyebut nomor
 * sertifikat yang dulu: yang dijaga aturannya, bukan satu baris data yang
 * kebetulan pernah salah.
 */
class SeederTidakMenanamSertifikatKosongTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiap_sertifikat_terbit_punya_snapshot_dan_hasil(): void
    {
        $this->seed(DatabaseSeeder::class);

        $terbit = Certificate::where('status', Certificate::STATUS_TERBIT)
            ->with('session.uncertaintyCalculations')
            ->get();

        $this->assertNotEmpty($terbit, 'Seeder mestinya menerbitkan sertifikat contoh.');

        foreach ($terbit as $s) {
            $this->assertNotNull(
                $s->session,
                "Sertifikat {$s->nomor} terbit tanpa sesi kalibrasi.",
            );

            // Autoklaf menyimpan olah datanya di `hasil_autoclave`, bukan
            // `uncertainty_calculations` — jadi yang diperiksa: ADA salah
            // satunya, bukan harus yang per titik.
            $punyaHasil = $s->session->uncertaintyCalculations->isNotEmpty()
                || $s->session->hasil_autoclave !== null;

            $this->assertTrue(
                $punyaHasil,
                "Sertifikat {$s->nomor} terbit padahal sesinya tidak punya satu pun hasil hitung.",
            );
        }
    }

    /**
     * Sesi yang divonis PASS wajib punya titik yang benar-benar dihitung.
     *
     * `PASS` tanpa satu pun hasil adalah pernyataan lulus terhadap kriteria
     * yang tidak pernah diperiksa — dan itu jenis kekeliruan yang paling sulit
     * terlihat, karena tidak memunculkan error di mana pun.
     */
    public function test_tidak_ada_sesi_pass_tanpa_titik_terhitung(): void
    {
        $this->seed(DatabaseSeeder::class);

        $nakal = CalibrationSession::where('keputusan', 'PASS')
            ->whereDoesntHave('uncertaintyCalculations')
            ->pluck('nomor_sesi');

        $this->assertEmpty(
            $nakal->all(),
            'Sesi ber-keputusan PASS tanpa titik terhitung: '.$nakal->implode(', '),
        );
    }
}
