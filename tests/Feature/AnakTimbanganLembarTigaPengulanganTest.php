<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use App\Support\AnakTimbanganMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keputusan pemilik 20 Okt 2026 (opsi a): lembar kerja HP Anak Timbangan
 * (`SIDIK-FM-CAL-0541_Rev.0`) dilayani dengan TIGA kolom pengulangan secara
 * bawaan, supaya 120 sel hasil pindai (10 blok × S/T/T/S × X1–X3) punya kotak.
 *
 * Yang dijaga juga: `?pengulangan=` eksplisit tetap menang, konstanta hitung
 * `PENGULANGAN_KERTAS` tidak bergeser, dan sesi lama berisi SATU pembacaan per
 * peran tidak berubah angkanya — baik dihitung ulang maupun dikirim ulang dari
 * lembar tiga kolom yang X2/X3-nya kosong.
 */
class AnakTimbanganLembarTigaPengulanganTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/calibrations/lembar-kerja?profil=anak_timbangan';

    /**
     * Tabel ABBA di bentuk lembar (sesudah `susunDuaHalaman`), per grup.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, array<string, mixed>>
     */
    private static function tabelAbba(array $bentuk): array
    {
        $hasil = [];

        $susuri = static function (array $simpul) use (&$susuri, &$hasil): void {
            if (isset($simpul['grup']) && in_array($simpul['grup'], AnakTimbanganMentah::PERAN_URUT, true)
                && array_key_exists('pengulangan', $simpul)) {
                $hasil[$simpul['grup']] = $simpul;
            }

            foreach ($simpul as $nilai) {
                if (is_array($nilai)) {
                    $susuri($nilai);
                }
            }
        };

        $susuri($bentuk);

        return $hasil;
    }

    public function test_sesi_baru_tanpa_parameter_mendapat_tiga_kolom_di_keempat_peran(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $data = $this->actingAs($teknisi)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.jumlah_pengulangan', 3)
            ->json('data');

        $tabel = self::tabelAbba($data);
        $this->assertCount(4, $tabel);

        foreach ($tabel as $grup => $t) {
            $this->assertSame([1, 2, 3], $t['pengulangan'], "{$grup}: kotak X1–X3 wajib ada");
            $this->assertCount(AnakTimbanganProfile::BARIS_KERTAS, $t['baris']);
        }

        // 10 blok × 4 peran × 3 = 120 kotak, sama dengan sel kertas yang dipindai.
        $this->assertSame(120, array_sum(array_map(
            static fn (array $t): int => count($t['baris']) * count($t['pengulangan']),
            $tabel,
        )));

        // Konstanta perhitungan tidak ikut digeser.
        $this->assertSame(1, AnakTimbanganMentah::PENGULANGAN_KERTAS);
        $this->assertSame(1, AnakTimbanganProfile::PENGULANGAN);
    }

    public function test_pilihan_eksplisit_tetap_menang(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $data = $this->actingAs($teknisi)->getJson(self::URL.'&pengulangan=2')
            ->assertOk()
            ->assertJsonPath('data.jumlah_pengulangan', 2)
            ->json('data');

        foreach (self::tabelAbba($data) as $grup => $t) {
            $this->assertSame([1, 2], $t['pengulangan'], $grup);
        }

        $this->actingAs($teknisi)->getJson(self::URL.'&pengulangan=5')
            ->assertOk()
            ->assertJsonPath('data.jumlah_pengulangan', 5);
    }

    public function test_profil_lain_tidak_ikut_bergeser(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $this->actingAs($teknisi)->getJson('/api/calibrations/lembar-kerja?profil=ph_meter')
            ->assertOk()
            ->assertJsonPath('data.jumlah_pengulangan', 5);
    }

    /**
     * Sesi lama (satu angka per peran) tetap satu angka per peran dan angkanya
     * identik, walau lembar yang dilayani sekarang tiga kolom: dihitung ulang
     * tidak bergeser, dan dikirim dari lembar tiga kolom dengan X2/X3 kosong
     * (`null`, seperti HP) menghasilkan baris mentah dan U95 yang sama.
     */
    public function test_sesi_lama_satu_pembacaan_tidak_berubah(): void
    {
        $this->seed(DatabaseSeeder::class);

        $satu = [
            ['titik_ukur' => 100.0, 'at_s1' => [100.0], 'at_t1' => [99.9999], 'at_t2' => [99.9999], 'at_s2' => [100.0]],
            ['titik_ukur' => 50.0, 'at_s1' => [50.0003], 'at_t1' => [50.0003], 'at_t2' => [50.0003], 'at_s2' => [50.0002]],
        ];

        $sesiLama = $this->kirim($satu);
        $u95Lama = $this->u95($sesiLama);
        $this->assertCount(2, $u95Lama);
        $this->assertCount(8, $sesiLama->rawMeasurements);

        // Lembar sekarang tiga kolom — tidak menyentuh data tersimpan.
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.jumlah_pengulangan', 3);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesiLama->nomor_sesi]])->assertSuccessful();
        $sesiLama->refresh();
        $this->assertCount(8, $sesiLama->rawMeasurements);
        $this->assertSame(1, (int) $sesiLama->rawMeasurements->max('pembacaan_ke'));

        foreach ($u95Lama as $titikKe => $u95) {
            $this->assertEqualsWithDelta($u95, $this->u95($sesiLama)[$titikKe], 1e-8, "Titik {$titikKe} bergeser.");
        }

        // Draf lama dibuka di lembar tiga kolom: X2/X3 terkirim null.
        $berlubang = array_map(static function (array $k): array {
            foreach (AnakTimbanganMentah::PERAN_URUT as $peran) {
                $k[$peran] = [$k[$peran][0], null, null];
            }

            return $k;
        }, $satu);

        $sesiUlang = $this->kirim($berlubang);
        $this->assertCount(8, $sesiUlang->rawMeasurements);

        foreach ($u95Lama as $titikKe => $u95) {
            $this->assertEqualsWithDelta($u95, $this->u95($sesiUlang)[$titikKe], 1e-8, "Titik {$titikKe} bergeser.");
        }
    }

    /** Tiga pembacaan per peran dirata-ratakan: sama dengan satu pembacaan bernilai rata-ratanya. */
    public function test_tiga_pembacaan_sama_dengan_satu_pembacaan_rata_ratanya(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rata = $this->kirim([
            ['titik_ukur' => 50.0, 'at_s1' => [50.0003], 'at_t1' => [50.0004], 'at_t2' => [50.0004], 'at_s2' => [50.0002]],
        ]);
        $tiga = $this->kirim([
            [
                'titik_ukur' => 50.0,
                'at_s1' => [50.0002, 50.0003, 50.0004],
                'at_t1' => [50.0003, 50.0004, 50.0005],
                'at_t2' => [50.0004, 50.0004, 50.0004],
                'at_s2' => [50.0001, 50.0002, 50.0003],
            ],
        ]);

        $this->assertCount(12, $tiga->rawMeasurements);

        $a = $rata->uncertaintyCalculations()->firstOrFail();
        $b = $tiga->uncertaintyCalculations()->firstOrFail();

        foreach (['rata_rata', 'error', 'koreksi', 'ketidakpastian_gabungan', 'ketidakpastian_diperluas'] as $kolom) {
            $this->assertEqualsWithDelta((float) $a->{$kolom}, (float) $b->{$kolom}, 1e-8, $kolom);
        }
    }

    /** @param  list<array<string, mixed>>  $keping */
    private function kirim(array $keping): CalibrationSession
    {
        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $alat = Equipment::findOrFail($contoh->equipment_id);
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $contoh->standard_id,
                'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
                'tanggal_kalibrasi' => '2026-09-11',
                'suhu_awal' => 23.1,
                'suhu_akhir' => 23.0,
                'kelembaban_awal' => 55.0,
                'kelembaban_akhir' => 56.0,
                'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => [
                    'kelas_uut' => 'F1',
                    'kelas_standar' => 'E2',
                    'timbangan' => 'Analytical Balance',
                    'meter_lingkungan' => 'Thermobarometer',
                    'kapasitas_g' => 200,
                    'suhu_awal' => 23.1,
                    'suhu_akhir' => 23.0,
                    'kelembaban_awal' => 55.0,
                    'kelembaban_akhir' => 56.0,
                    'tekanan_awal' => 933.2,
                    'tekanan_akhir' => 933.1,
                ]],
                'measurements' => $keping,
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /** @return array<int, float> */
    private function u95(CalibrationSession $sesi): array
    {
        return $sesi->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->pluck('ketidakpastian_diperluas', 'titik_ke')
            ->map(static fn ($x): float => (float) $x)
            ->all();
    }
}
