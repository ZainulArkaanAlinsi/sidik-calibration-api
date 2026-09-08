<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `flowmeter:audit-cmc` — pelingkup arsip untuk
 * `docs/pertanyaan-lab-flowmeter.md` §2.
 *
 * Yang dijaga di sini BUKAN "perintahnya jalan", melainkan **apa yang ditemukan
 * dan apa yang TIDAK**. Pelingkup yang menandai semuanya sama tidak bergunanya
 * dengan yang tidak menandai apa pun: yang pertama melatih pembacanya
 * mengabaikan daftarnya.
 */
class AuditFlowmeterCmcTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sesi contoh yang SEHAT tidak ditandai `di_bawah_cmc`.
     *
     * Ini kontrol positifnya. Keempat titik sesi contoh sudah berlantai CMC
     * (titik 2 Flowrate justru mendarat PERSIS di 1,2 %), jadi tidak satu pun
     * boleh muncul sebagai di bawah pita — kalau muncul, berarti lantainya
     * tidak terpasang di jalur simpan.
     */
    public function test_sesi_contoh_tidak_ditandai_di_bawah_cmc(): void
    {
        $this->seed(DatabaseSeeder::class);

        $keluaran = $this->jalankan();

        $this->assertStringContainsString('Sesi Flowmeter diperiksa: 2', $keluaran);
        $this->assertStringNotContainsString('di_bawah_cmc', $keluaran);
        $this->assertStringNotContainsString('di_luar_pita', $keluaran);
    }

    /**
     * Jarak ke titik tabel standar yang jauh TETAP dilingkupi walau dia tidak
     * menahan penerbitan.
     *
     * Sesi contoh Flowrate titik 2: bacaan 310,6 Lpm memungut koreksi titik
     * tabel 236,147 Lpm — jaraknya 24 %. Koreksinya dipakai UTUH, dan itu
     * menggeser deviasi 22 %.
     */
    public function test_jarak_tabel_jauh_ikut_dilingkupi(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertStringContainsString('jarak_tabel_24pct', $this->jalankan());
    }

    /**
     * Titik yang U95-nya di BAWAH pita ketahuan.
     *
     * Disimulasikan dengan menulis U95 master apa adanya (3,2512 Lpm pada
     * bacaan 310,64 = 1,0466 %OR) ke baris hitungan — yaitu persis angka yang
     * TERCETAK di sertifikat lab sebelum lantainya dipasang.
     */
    public function test_u95_di_bawah_pita_ketahuan(): void
    {
        $this->seed(DatabaseSeeder::class);

        CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')
            ->firstOrFail()
            ->uncertaintyCalculations()
            ->where('titik_ke', 2)
            ->update(['ketidakpastian_diperluas' => 3.2512387943296726]);

        $keluaran = $this->jalankan();

        $this->assertStringContainsString('di_bawah_cmc', $keluaran);
        // Angkanya ikut tercetak supaya bisa dinilai tanpa membuka sesinya.
        $this->assertStringContainsString('1.0466', $keluaran);
    }

    /**
     * Bacaan di LUAR kedua pita ketahuan — dan itu temuan yang berbeda dari
     * "di bawah pita".
     *
     * Keduanya sengaja dipisah: yang pertama sertifikat yang mengklaim
     * ketidakpastian lebih baik dari yang diakui, yang kedua sertifikat yang
     * membawa nomor lingkup untuk pengukuran yang sama sekali tidak
     * diakreditasi. Ditindaklanjuti orang dengan cara yang berbeda.
     */
    public function test_di_luar_kedua_pita_ketahuan(): void
    {
        $this->seed(DatabaseSeeder::class);

        // 2500 L jauh di atas pita Totalizer teratas (78–1991 L).
        CalibrationSession::where('nomor_sesi', 'DEMO-FM-TOT-001')
            ->firstOrFail()
            ->uncertaintyCalculations()
            ->where('titik_ke', 1)
            ->update(['rata_rata' => 2500.0, 'ketidakpastian_diperluas' => 40.0]);

        $this->assertStringContainsString('di_luar_pita', $this->jalankan());
    }

    /**
     * Perintah ini READ-ONLY.
     *
     * Alat pelingkupan yang diam-diam menulis berarti arsip yang sedang
     * ditinjau berubah selama ditinjau — dan yang berubah justru angka yang
     * jadi bahan keputusannya.
     */
    public function test_tidak_menulis_apa_pun_ke_database(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sebelum = [
            CalibrationSession::count(),
            RawMeasurement::count(),
            UncertaintyCalculation::count(),
        ];

        $this->artisan('flowmeter:audit-cmc')->assertExitCode(0);

        $this->assertSame($sebelum, [
            CalibrationSession::count(),
            RawMeasurement::count(),
            UncertaintyCalculation::count(),
        ]);
    }

    /** Arsip kosong bukan kegagalan — dia keadaan yang sah. */
    public function test_arsip_tanpa_sesi_flowmeter_bukan_kegagalan(): void
    {
        $this->assertStringContainsString('Nggak ada sesi Flowmeter di arsip', $this->jalankan());
    }

    /**
     * `Artisan::call()`, BUKAN `$this->artisan()`.
     *
     * Yang kedua membungkus perintahnya di `PendingCommand` dengan buffer
     * keluarannya sendiri, jadi `Artisan::output()` sesudahnya pulang string
     * KOSONG — dan test yang mencari temuan di string kosong lulus kalau
     * assertion-nya `assertStringNotContainsString`. Persis cara test sapuan
     * gagal tanpa bersuara.
     */
    private function jalankan(): string
    {
        $this->assertSame(0, Artisan::call('flowmeter:audit-cmc'));

        return Artisan::output();
    }
}
