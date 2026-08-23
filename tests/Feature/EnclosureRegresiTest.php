<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\EnclosureCalculator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua regresi yang lolos SEMUA tes yang ada, karena dua-duanya cuma muncul pada
 * bentuk request yang belum pernah diuji.
 *
 * ## 1. Urutan grid menentukan angka
 *
 * Sensor Acuan itu sensor PERTAMA di grid, dan Keseragaman diukur relatif ke
 * dia. Tiga jalur menyusun grid dengan urutan berbeda:
 *
 *   jalur simpan     `CalibrationController` → urutan array dari request
 *   jalur validasi   `CalibrationValidator`  → dikelompokkan per `sensor_ke`
 *   jalur hitung ulang `kalibrasi:hitung-ulang` → idem
 *
 * Selama grid dikirim urut nomor ketiganya sepakat — dan SEMUA fixture memang
 * urut nomor, jadi tidak ada tes yang bisa jatuh. Begitu urutannya lain, satu
 * data mentah menghasilkan dua jawaban: pada grid contoh Keseragaman jadi
 * **0,4 °C bukan 0,2 °C** (dua kali lipat) dan U95 ikut bergeser. Efek
 * lanjutannya: tiap sesi begitu ke-flag "data berubah sesudah submit" padahal
 * tidak ada yang berubah, dan `kalibrasi:hitung-ulang` menulis angka yang beda
 * dari yang tercetak di sertifikat.
 *
 * ## 2. Nomor termokopel yang sama di set point BERBEDA ditolak 422
 *
 * Aturan `distinct` pada wildcard bertingkat (`measurements.*.sensor_grid.*.no`)
 * membandingkan seluruh atribut hasil ekspansi, bukan cuma satu `sensor_grid`.
 * Sesi enclosure normal memakai termokopel yang SAMA di tiap set point, jadi
 * aturan itu menolak justru cara alat ini dipakai.
 *
 * Nggak ada tes yang jatuh karena tiap tes API yang ada cuma mengirim SATU set
 * point. Bug-nya baru kelihatan pada set point kedua.
 */
class EnclosureRegresiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 1e-9;

    /** @return array<string, mixed> */
    private static function fixtureGolden(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true);
    }

    /**
     * Urutan grid TIDAK boleh mengubah angka: sensor acuan ditentukan dari nomor
     * termokopel terkecil, bukan dari posisi di array.
     */
    public function test_urutan_grid_tidak_mengubah_angka(): void
    {
        $fix = self::fixtureGolden()['yokogawa'];
        $sp = $fix['setpoints'][0];

        $urut = array_map(
            static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
            $sp['sensors'],
        );

        // Sensor bernomor TERBESAR ditaruh paling depan — bentuk yang dulu bikin
        // dia jadi Sensor Acuan.
        $dibalik = $urut;
        array_unshift($dibalik, array_pop($dibalik));

        $spek = [
            'merk' => $fix['merk'],
            'tipe_sensor' => $fix['tipe_sensor'],
            'cmc' => (float) $fix['cmc'],
            'resolusi_alat' => 0.1,
        ];

        $kalkulator = new EnclosureCalculator;
        $a = $kalkulator->hitungSetpoint($urut, $sp['indikator'], (float) $sp['setpoint'], $spek);
        $b = $kalkulator->hitungSetpoint($dibalik, $sp['indikator'], (float) $sp['setpoint'], $spek);

        $this->assertSame(
            $a['sensor_acuan'],
            $b['sensor_acuan'],
            'sensor acuan harus sama, apa pun urutan grid yang dikirim',
        );
        $this->assertSame(
            min(array_column($urut, 'no')),
            $a['sensor_acuan'],
            'acuan = nomor termokopel terkecil, bukan yang pertama di array',
        );

        foreach (['keseragaman', 'kestabilan', 'variasi_keseluruhan', 'ketidakpastian_gabungan', 'u95_sertifikat'] as $besaran) {
            $this->assertEqualsWithDelta(
                $a[$besaran],
                $b[$besaran],
                self::TOLERANSI,
                "{$besaran} berubah cuma gara-gara urutan grid",
            );
        }

        // Dan sebaran per sensor tetap dilaporkan per nomor yang benar.
        $this->assertSame(
            array_column($a['sensor'], 'no'),
            array_column($b['sensor'], 'no'),
            'daftar sensor harus terurut sama di dua-duanya',
        );
    }

    /**
     * DUA set point yang memakai nomor termokopel yang SAMA — bentuk normal
     * sesi enclosure — harus diterima, bukan ditolak 422.
     */
    public function test_nomor_termokopel_sama_di_set_point_berbeda_diterima(): void
    {
        [$teknisi, $alat, $standar] = $this->aktorYokogawa();
        $fix = self::fixtureGolden()['yokogawa'];

        $respons = $this->actingAs($teknisi)->postJson('/api/calibrations/preview', [
            ...$this->identitasSesi($alat, $standar),
            'measurements' => [
                $this->setPoint($fix['setpoints'][0]),
                $this->setPoint($fix['setpoints'][1]),
            ],
        ]);

        $respons->assertOk();

        $titik = $respons->json('data.titik');
        $this->assertCount(2, $titik, 'dua set point harus kehitung dua-duanya');
        $this->assertEqualsWithDelta(15.0, (float) $titik[0]['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta(35.0, (float) $titik[1]['titik_ukur'], self::TOLERANSI);
    }

    /** Nomor kembar DALAM SATU set point tetap ditolak. */
    public function test_nomor_termokopel_kembar_dalam_satu_set_point_ditolak(): void
    {
        [$teknisi, $alat, $standar] = $this->aktorYokogawa();
        $fix = self::fixtureGolden()['yokogawa'];

        $titik = $this->setPoint($fix['setpoints'][0]);
        // Sensor kedua dinomori ulang jadi sama dengan yang pertama.
        $titik['sensor_grid'][1]['no'] = $titik['sensor_grid'][0]['no'];

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', [
                ...$this->identitasSesi($alat, $standar),
                'measurements' => [$titik],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('measurements.0.sensor_grid');
    }

    /** @return array{User, Equipment, Standard} */
    private function aktorYokogawa(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
            Equipment::where('serial_number', 'D132469')->firstOrFail(),
            Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail(),
        ];
    }

    /** @return array<string, mixed> */
    private function identitasSesi(Equipment $alat, Standard $standar): array
    {
        return [
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-05-02',
            'tipe_sensor' => 'Type N',
            'suhu_awal' => 23.7, 'suhu_akhir' => 23.7,
            'kelembaban_awal' => 47, 'kelembaban_akhir' => 46,
        ];
    }

    /**
     * @param  array<string, mixed>  $sp
     * @return array<string, mixed>
     */
    private function setPoint(array $sp): array
    {
        return [
            'titik_ukur' => (float) $sp['setpoint'],
            'satuan' => '°C',
            'sensor_grid' => array_map(
                static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
                $sp['sensors'],
            ),
            'indikator' => $sp['indikator'],
        ];
    }
}
