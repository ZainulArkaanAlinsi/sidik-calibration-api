<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\TitsProfile;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua sesi TITS dari ujung ke ujung: seeder → `TitsProfile::hitungPerGrup()` →
 * baris `uncertainty_calculations`, diadu ke kedua master.
 *
 * Beda dari `Tests\Unit\TitsBudgetTest` (murni, cuma kalkulator): yang diuji di
 * sini rantai lengkapnya lewat database — termasuk hal yang cuma muncul kalau
 * datanya beneran disimpan dan dibaca balik, yaitu pencocokan alat ke profil,
 * baris CMC per tipe sensor dari lampiran akreditasi, dan `keputusan` yang harus
 * NULL.
 *
 * Jalankan juga di MySQL (`php artisan test -c phpunit.mysql.xml`): kolom
 * `decimal(20,8)` dibaca float oleh SQLite tapi string oleh MySQL, dan seluruh
 * angka di bawah lewat kolom itu.
 */
class TitsSesiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Presisi kolom `uncertainty_calculations` — 8 desimal. Presisi penuhnya
     * diuji di `Tests\Unit\TitsBudgetTest`.
     */
    private const TOLERANSI = 1e-7;

    /**
     * `SERTIFIKAT!E20:L28` master fungsi Measure (01-CAL-625).
     *
     * @var list<array{standard: float, uut: float, koreksi: float}>
     */
    private const MASTER_MEASURE = [
        ['standard' => -19.91, 'uut' => -20.29333333, 'koreksi' => 0.38333333],
        ['standard' => 10.07, 'uut' => 9.69166667, 'koreksi' => 0.37833333],
        ['standard' => 50.05, 'uut' => 49.80166667, 'koreksi' => 0.24833333],
        ['standard' => 100.03, 'uut' => 99.99166667, 'koreksi' => 0.03833333],
        ['standard' => 200.03, 'uut' => 199.51666667, 'koreksi' => 0.51333333],
        ['standard' => 399.97, 'uut' => 399.98333333, 'koreksi' => -0.01333333],
        ['standard' => 599.91, 'uut' => 600.33333333, 'koreksi' => -0.42333333],
        ['standard' => 799.86, 'uut' => 800.41666667, 'koreksi' => -0.55666667],
        ['standard' => 999.81, 'uut' => 1000.08333333, 'koreksi' => -0.27333333],
    ];

    /**
     * `SERTIFIKAT!E16:L23` master fungsi Source (0159-CAL-626).
     *
     * @var list<array{standard: float, uut: float, koreksi: float}>
     */
    private const MASTER_SOURCE = [
        ['standard' => 4.98, 'uut' => 0.0, 'koreksi' => 4.98],
        ['standard' => 104.4, 'uut' => 100.0, 'koreksi' => 4.4],
        ['standard' => 304.5, 'uut' => 300.0, 'koreksi' => 4.5],
        ['standard' => 503.935, 'uut' => 500.0, 'koreksi' => 3.935],
        ['standard' => 704.475, 'uut' => 700.0, 'koreksi' => 4.475],
        ['standard' => 904.025, 'uut' => 900.0, 'koreksi' => 4.025],
        ['standard' => 1103.65, 'uut' => 1100.0, 'koreksi' => 3.65],
        ['standard' => 1203.5, 'uut' => 1200.0, 'koreksi' => 3.5],
    ];

    public function test_alat_tits_ketemu_profilnya(): void
    {
        $this->seed(DatabaseSeeder::class);

        $alat = Equipment::where('serial_number', 'C305B1470')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);

        // Kalau ejaan `nama_alat_kemampuan` meleset satu huruf, alatnya diam-diam
        // jatuh ke profil default (pH) — tanpa error di mana pun.
        $this->assertInstanceOf(TitsProfile::class, $profil);
        $this->assertSame('tits', $profil->kode());
    }

    /**
     * `GET /api/calibrations/{id}` memulangkan kode profil yang SUDAH
     * diresolusi, bukan cuma nama alat pelanggan.
     *
     * Tanpa kunci ini mobile kepaksa menebak jenis lembar kerja dari
     * `equipment.nama_alat` — dan buat TITS tebakan itu tidak pernah bisa
     * kena: kedua alat di master terdaftar "Temperature Calibrator" dan
     * "Temperature Recorder Controller", nol kemiripan dengan "Temperature
     * Indicator tanpa Sensor" yang jadi kunci pencocokan profilnya. Sesi yang
     * dibuka lagi jatuh ke formulir pH — tiga titik 4/7/10,01 di atas lembar
     * suhu sembilan titik −20…1000 °C — tanpa satu pun error muncul.
     */
    public function test_respons_sesi_bawa_kode_profil(): void
    {
        $sesi = $this->sesi('22506.01.A');
        $admin = User::factory()->admin()->create();

        $respons = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk();

        $respons->assertJsonPath('data.equipment.profil', 'tits');
        $respons->assertJsonPath(
            'data.equipment.nama_alat_kemampuan',
            'Temperature Indicator tanpa Sensor',
        );
        // Nama alat pelanggan tetap apa adanya — dipakai buat judul, bukan
        // buat nebak profil.
        $respons->assertJsonPath('data.equipment.nama_alat', 'Temperature Calibrator');
    }

    /**
     * Kolom "Environmental Meter Used" harus datang dengan pilihannya terisi.
     *
     * Kalau `pilihan` kosong, aplikasi teknisi nggambarnya sebagai teks MATI
     * ("Belum ada unit thermohygro terdaftar") — dropdown-nya nggak bisa
     * dibuka, jadi sesi TITS nggak punya unit thermohygro sama sekali dan
     * koreksi kondisi lingkungan nempel ke unit yang salah. Sembilan profil
     * lain sudah mengisinya; TITS ketinggalan, dan nggak ada yang gagal —
     * kolomnya cuma diam.
     */
    public function test_kolom_thermohygro_bawa_pilihannya(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teknisi = User::factory()->create();

        $bentuk = $this->actingAs($teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=tits')
            ->assertOk()
            ->json('data');

        $kolom = null;
        foreach ($bentuk['bagian'] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                if ($field['kode'] === 'thermohygro_standard_id') {
                    $kolom = $field;
                }
            }
        }

        $this->assertNotNull($kolom, 'bentuk TITS nggak punya kolom thermohygro');
        $this->assertNotEmpty($kolom['pilihan'], 'pilihan thermohygro kosong — dropdown-nya bakal mati');

        // Dikelompokkan Insitu/Inlab persis kayak kertasnya.
        $grup = array_unique(array_column($kolom['pilihan'], 'grup'));
        sort($grup);
        $this->assertSame(['Inlab', 'Insitu'], $grup);
    }

    public function test_sesi_mode_measure_cocok_master(): void
    {
        $sesi = $this->sesi('22506.01.A');

        $this->assertSame('measure', $sesi->mode_kalibrasi);
        $this->assertSame('Type N', $sesi->tipe_sensor);

        $baris = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();
        $this->assertCount(9, $baris);

        foreach (self::MASTER_MEASURE as $i => $harap) {
            $this->assertEqualsWithDelta($harap['standard'], (float) $baris[$i]->titik_ukur, self::TOLERANSI);
            $this->assertEqualsWithDelta($harap['uut'], (float) $baris[$i]->rata_rata, self::TOLERANSI);
            $this->assertEqualsWithDelta($harap['koreksi'], (float) $baris[$i]->koreksi, self::TOLERANSI);
        }

        // Satu U95 untuk seluruh sesi — semua baris membawa angka yang sama.
        // `AC28 = 0,8534` menang atas CMC Type N 0,83.
        foreach ($baris as $b) {
            $this->assertEqualsWithDelta(0.85336874, (float) $b->ketidakpastian_diperluas, 1e-3);
            $this->assertEqualsWithDelta(6.50682455, (float) $b->derajat_kebebasan_efektif, self::TOLERANSI);
            $this->assertEqualsWithDelta(2.40157723, (float) $b->faktor_cakupan_k, 1e-3);
            $this->assertNull($b->keputusan);
            $this->assertNull($b->toleransi);
        }

        // Jejak audit itu LIST komponen (bentuk yang dimengerti
        // `CalibrationResource`), dengan konteks sesi sebagai baris terakhir.
        $konteks = $this->konteksSesi($baris[0]->type_b_components);
        $this->assertSame('hitung', $konteks['sumber_u95']);
        $this->assertEqualsWithDelta(0.83, (float) $konteks['cmc'], self::TOLERANSI);
        $this->assertSame('Thermocouple sensor type N', $konteks['cmc_parameter']);
        $this->assertSame('measure', $konteks['mode']);
    }

    public function test_sesi_mode_source_cocok_master(): void
    {
        $sesi = $this->sesi('2606.08.C');

        $this->assertSame('source', $sesi->mode_kalibrasi);
        $this->assertSame('Type S', $sesi->tipe_sensor);

        $baris = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();
        $this->assertCount(8, $baris);

        foreach (self::MASTER_SOURCE as $i => $harap) {
            $this->assertEqualsWithDelta($harap['standard'], (float) $baris[$i]->titik_ukur, self::TOLERANSI);
            $this->assertEqualsWithDelta($harap['uut'], (float) $baris[$i]->rata_rata, self::TOLERANSI);
            $this->assertEqualsWithDelta($harap['koreksi'], (float) $baris[$i]->koreksi, self::TOLERANSI);
        }

        // `AC31 = MAX(1,1377; CMC 1,2)` — lantai CMC yang menang di sesi ini.
        foreach ($baris as $b) {
            $this->assertEqualsWithDelta(1.2, (float) $b->ketidakpastian_diperluas, self::TOLERANSI);
        }

        $konteks = $this->konteksSesi($baris[0]->type_b_components);
        $this->assertSame('cmc', $konteks['sumber_u95']);
        $this->assertEqualsWithDelta(
            1.1376795812146776,
            (float) $konteks['ketidakpastian_diperluas_hitung'],
            1e-3,
        );

        // Komponen drift bersel mati master ikut tercatat di jejak audit —
        // kalau suatu saat dibuang, catatannya yang jatuh duluan.
        $sumber = array_column($baris[0]->type_b_components, 'sumber');
        $this->assertContains('drift_referensi_mati', $sumber);
        $this->assertContains('lantai_cmc', $sumber);
    }

    /**
     * Enam pembacaan per titik tersimpan utuh — tiga UP, tiga DOWN. Kalau suatu
     * saat ada yang memisahkan dua arah jadi dua titik, jumlah barisnya yang
     * jatuh duluan.
     */
    public function test_enam_pembacaan_per_titik_tersimpan(): void
    {
        $sesi = $this->sesi('22506.01.A');

        $this->assertCount(9 * 6, $sesi->rawMeasurements);

        $titikPertama = $sesi->rawMeasurements->where('titik_ke', 1)->sortBy('pembacaan_ke')->values();
        $this->assertCount(6, $titikPertama);
        $this->assertEqualsWithDelta(-20.05, (float) $titikPertama[0]->pembacaan, self::TOLERANSI);
        $this->assertEqualsWithDelta(-20.42, (float) $titikPertama[5]->pembacaan, self::TOLERANSI);
    }

    /**
     * Rantai cetak: baris `Mode : …` di atas tabel hasil, dan angka sertifikat
     * yang sudah dibulatkan sesuai format sel master (1 desimal hasil, 2 desimal
     * U95, 0 desimal `k`).
     */
    public function test_snapshot_sertifikat_membawa_baris_mode(): void
    {
        $sesi = $this->sesi('22506.01.A');
        $sertifikat = new Certificate(['calibration_session_id' => $sesi->id, 'nomor' => 'UJI-TITS']);

        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat);

        // Dua sertifikat alat yang sama cuma dibedakan baris ini dan nomornya.
        $this->assertSame('Mode : Measure Type N', $snapshot['catatan_atas_hasil']);

        $baris = $snapshot['hasil'][0];
        $this->assertEqualsWithDelta(-19.91, (float) $baris['standard_value'], self::TOLERANSI);
        $this->assertEqualsWithDelta(-20.29333333, (float) $baris['unit_under_test'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.38333333, (float) $baris['correction'], self::TOLERANSI);
        $this->assertSame(1, $baris['desimal']);
        $this->assertSame(2, $baris['desimal_u95']);
        // `k` nol desimal, mengikuti format sel `SERTIFIKAT!O30` master.
        $this->assertSame(0, $baris['desimal_k']);
    }

    /**
     * Tanpa mode & tipe sensor, sesi TIDAK dihitung diam-diam — tiap titik
     * pulang dengan alasan yang kebaca.
     */
    public function test_tanpa_mode_sesi_tidak_dihitung(): void
    {
        $this->seed(DatabaseSeeder::class);

        $alat = Equipment::where('serial_number', 'C305B1470')->firstOrFail();
        $standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();

        $hasil = (new TitsProfile)->hitungPerGrup([[
            'titik_ke' => 1,
            'titik_ukur' => 100.0,
            'pembacaan' => [100.1, 100.2, 100.1, 100.0, 100.0, 100.1],
            'standard' => $standar,
            'konteks' => [],
        ]], $alat);

        $this->assertSame([], $hasil['hitungan']);
        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertStringContainsString('Mode kalibrasi', $hasil['belum_dihitung'][0]['alasan']);
    }

    /**
     * Baris `konteks_sesi` di jejak audit — yang membawa mode, tipe sensor,
     * CMC, dan asal U95.
     *
     * @param  list<array<string, mixed>>  $audit
     * @return array<string, mixed>
     */
    private function konteksSesi(array $audit): array
    {
        foreach ($audit as $baris) {
            if (($baris['sumber'] ?? null) === 'konteks_sesi') {
                return $baris;
            }
        }

        $this->fail('jejak audit nggak punya baris `konteks_sesi`');
    }

    private function sesi(string $nomor): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', $nomor)
            ->with(['uncertaintyCalculations', 'rawMeasurements'])
            ->firstOrFail();
    }
}
