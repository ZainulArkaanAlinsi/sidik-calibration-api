<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sertifikat **Micrometer** diadu baris demi baris ke sheet `SERTIFIKAT`
 * master `Master_Olah_Data_Micrometer_2550mm.xlsm`.
 *
 * ## Kenapa test ini ada, terpisah dari MicrometerMasterTest
 *
 * `MicrometerMasterTest` mengadu MESIN HITUNGnya — budget, `uc`, `veff`, `k`,
 * `U95` — dalam µm, satuan sheet `PERHITUNGAN U95%`. Semuanya hijau, dan tetap
 * hijau waktu sertifikatnya mencetak angka yang salah seribu kali lipat.
 *
 * Sebabnya: kolom `uncertainty_calculations.ketidakpastian_diperluas` bersanding
 * dengan `koreksi` di tabel sertifikat, dan `koreksi` itu mm. Lembar ini sempat
 * jadi satu-satunya yang menyimpan ketidakpastiannya dalam µm, jadi sertifikat
 * mencetak `0,00027` dan `0,871` di KOLOM YANG SAMA — dan kepala kolomnya
 * telanjang tanpa satuan, karena `satuanTitik()` belum diisi. Nol error di
 * seluruh jalur; ketahuan cuma waktu HTML-nya beneran dirender dan diadu ke
 * kertas master.
 *
 * Jadi yang dijaga di sini bukan hitungannya, tapi **yang tercetak**.
 */
class MicrometerSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /**
     * Sebelas baris `SERTIFIKAT!D18:L28` master, apa adanya.
     *
     * @return list<array{float, float, float}> [Standar Reading, UUT, Correction]
     */
    private const BARIS_MASTER = [
        [25.00027, 25.0, 0.00027],
        [27.50041, 27.5002, 0.00021],
        [30.99997, 31.0, -0.00003],
        [32.70044, 32.7, 0.00044],
        [35.3001, 35.3, 0.00010],
        [37.9002, 37.9, 0.00020],
        [40.00014, 40.0, 0.00014],
        [42.59983, 42.6, -0.00017],
        [45.20007, 45.2, 0.00007],
        [47.80041, 47.8, 0.00041],
        [49.9999, 49.9996, 0.00030],
    ];

    /** @return array{CalibrationSession, array<string, mixed>} */
    private function terbitkan(): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $sesi = CalibrationSession::where('nomor_sesi', '0106-CAL-1023')->firstOrFail();

        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return [$sesi->fresh(), $sesi->fresh()->certificate()->firstOrFail()->snapshot];
    }

    public function test_sebelas_baris_cocok_sertifikat_master(): void
    {
        [, $snapshot] = $this->terbitkan();

        $this->assertCount(11, $snapshot['hasil'], 'Sertifikat master mencetak SEBELAS titik.');

        foreach ($snapshot['hasil'] as $i => $baris) {
            [$standar, $uut, $koreksi] = self::BARIS_MASTER[$i];

            $this->assertEqualsWithDelta($standar, $baris['standard_value'], self::TOLERANSI, "Standar baris {$i}");
            $this->assertEqualsWithDelta($uut, $baris['unit_under_test'], self::TOLERANSI, "UUT baris {$i}");
            $this->assertEqualsWithDelta($koreksi, $baris['correction'], self::TOLERANSI, "Koreksi baris {$i}");
        }
    }

    /**
     * U95 tercetak dalam MILIMETER — satuan yang sama dengan kolom di atasnya.
     *
     * Master mencetak `0.0008737653585539594` di `SERTIFIKAT!J29` dengan `mm`
     * di `L29`. Punya kita 0,00087097 mm: bedanya PERSIS komponen drift yang
     * di-nol-kan karena sesi ini mendahului sertifikat balok ukurnya
     * (0,06192/√3 = 0,03575 µm dalam kuadratur) — penyimpangan §2 yang memang
     * disengaja untuk sesi historis.
     *
     * Yang dikunci di sini ORDE BESARANnya, bukan digit terakhirnya: nilai
     * dalam µm bakal 871 kali lebih besar dan tetap lolos kalau ambangnya
     * longgar.
     */
    public function test_u95_tercetak_dalam_mm_bukan_um(): void
    {
        [, $snapshot] = $this->terbitkan();

        $u95 = (float) $snapshot['hasil'][0]['u95'];

        $this->assertEqualsWithDelta(0.00087097, $u95, 1e-7, 'U95 sertifikat (mm)');

        // Penjaga orde besaran, ditulis eksplisit supaya kegagalannya kebaca:
        // 0,871 (µm) lolos ambang mana pun yang cuma "lebih besar dari nol".
        $this->assertLessThan(
            0.01,
            $u95,
            'U95 tercetak dalam µm, bukan mm — di kolom yang sama dengan koreksi (0,00027 mm) '
            .'angka itu terbaca seribu kali lebih besar dari sebenarnya.',
        );

        // Dan tetap di ATAS lantai CMC pita B (0,87 µm = 0,00087 mm).
        $this->assertGreaterThanOrEqual(0.00087, $u95, 'U95 jatuh di bawah lantai CMC 0,87 µm.');
    }

    /**
     * Kepala kolom menyebut satuannya.
     *
     * Tanpa ini blade mencetak `Standard` / `Correction` telanjang, dan tabel mm
     * bersebelahan dengan baris U95 tanpa satu pun petunjuk satuan. Master
     * mencetak baris satuannya sendiri (`D17`/`I17`/`L17`, tiga sel berisi `mm`).
     */
    public function test_kepala_kolom_menyebut_satuan(): void
    {
        [, $snapshot] = $this->terbitkan();

        foreach ($snapshot['hasil'] as $baris) {
            $this->assertSame('mm', $baris['satuan'], 'Satuan baris sertifikat.');
        }
    }

    /**
     * Koreksi tercetak dengan desimal yang CUKUP — bukan `0,000` di semua titik.
     *
     * Master justru begitu: `SERTIFIKAT!D18:L28` berformat `0.000`, jadi
     * sertifikat cetaknya menampilkan koreksi `0.000` di kesebelas titik dan
     * U95 `0.001`. Koreksi mikrometer ini besarnya ~0,0003 mm — tiga desimal
     * menghapus seluruh isi pengukurannya.
     *
     * TIDAK ditiru, dan alasannya sama dengan `IFERROR(…,"")`: kolom koreksi
     * yang seluruhnya nol memberi tahu pelanggan alatnya sempurna di tiap
     * titik. Diangkat sebagai pertanyaan lab §9.
     */
    public function test_desimal_cukup_untuk_koreksi_mikrometer(): void
    {
        [, $snapshot] = $this->terbitkan();

        $this->assertGreaterThanOrEqual(
            5,
            (int) $snapshot['hasil'][0]['desimal'],
            'Koreksi ~0,0003 mm butuh minimal lima desimal; di bawah itu seluruh kolom jadi 0,000.',
        );
        $this->assertGreaterThanOrEqual(
            5,
            (int) $snapshot['hasil'][0]['desimal_u95'],
            'U95 ~0,00087 mm butuh minimal lima desimal; di tiga desimal dia jadi 0,001.',
        );
    }

    /** Sertifikatnya beneran kerender, bukan cuma snapshot-nya kebentuk. */
    public function test_html_kerender_dengan_satuan_dan_sebelas_baris(): void
    {
        [$sesi] = $this->terbitkan();

        $bahan = app(DataTampilanSertifikat::class)->untuk($sesi->certificate()->firstOrFail());
        $html = view('sertifikat.pdf', [...$bahan, 'paksaPadat' => false])->render();

        $this->assertStringContainsString('Standard (mm)', $html);
        $this->assertStringContainsString('Correction (mm)', $html);
        $this->assertStringContainsString('0,00087', $html);
        $this->assertStringContainsString('0,00027', $html);
        $this->assertStringNotContainsString('0,871', $html);
    }
}
