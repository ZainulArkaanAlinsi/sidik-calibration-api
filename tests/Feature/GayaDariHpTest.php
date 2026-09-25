<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Support\GayaMentah as M;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lembar GAYA dikirim dalam bentuk yang BENAR-BENAR dikirim HP.
 *
 * ## Kenapa berkas ini ada
 *
 * `GayaSesiSimpanTest` mengirim `preload_zero`, `preload_max`, dan
 * `misalignment` langsung sebagai deret. HP tidak pernah mengirim bentuk itu:
 * dia menyusun payload dari definisi lembar server (`sidik-calibration-mobile`,
 * `LembarKerjaState.spesifikasiAlat` + `_tanamTabelSpesifikasi`):
 *
 * - isian spesifikasi = teks mentah apa adanya, termasuk koma desimal;
 * - misalignment = peta `{"1": …, "4": …}` dari kotak `misalignment.1..4`;
 * - tabel preload = `{baris: [{titik_ukur: null, pembacaan: [...]}, …]}` di
 *   jalur `simpan_ke`-nya;
 * - tiap titik membawa `pembacaan`/`suhu`/`pembacaan_sebelum`/`suhu_sebelum`
 *   berisi null, ditambah deret bernamanya.
 *
 * Chaos review 25 Sep 2026: test lama hijau sementara ketiga alat Gaya tidak
 * pernah terhitung dari HP — tabel preload ber-`simpan_ke`
 * `spesifikasi_alat.gaya` menimpa seluruh blok, dan pembacaan UP/DOWN Proving
 * Ring tidak dibaca server sama sekali.
 */
class GayaDariHpTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Equipment, 1: User} */
    private function siapkan(string $serial): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', $serial)->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * Satu titik persis `TitikLembarKerja.toJson()` + `kolomBaris` HP.
     *
     * @param  array<string, list<float>>  $deret
     * @return array<string, mixed>
     */
    private static function titikHp(float $nominal, array $deret): array
    {
        return [
            'titik_ukur' => $nominal,
            'pembacaan' => [null, null, null],
            'suhu' => [null, null, null],
            'pembacaan_sebelum' => [null, null, null],
            'suhu_sebelum' => [null, null, null],
            ...$deret,
        ];
    }

    /** Tabel preload persis `_tanamTabelSpesifikasi` HP. */
    private static function preloadHp(array $zero, array $max): array
    {
        return ['baris' => [
            ['titik_ukur' => null, 'pembacaan' => $zero],
            ['titik_ukur' => null, 'pembacaan' => $max],
        ]];
    }

    public function test_load_cell_dari_bentuk_hp_terhitung(): void
    {
        [$alat, $teknisi] = $this->siapkan('DEMO-LC-001');
        $deret = static fn (float $a, float $b): array => [$a, $a, $b];

        $payload = [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-09-24',
            'suhu_awal' => '27,7', 'suhu_akhir' => '27,7',
            'kelembaban_awal' => '70', 'kelembaban_akhir' => '70',
            'measurements' => [
                self::titikHp(2.0, [
                    M::PERAN_POSISI[0] => $deret(2.16, 2.16), M::PERAN_POSISI[1] => $deret(2.16, 2.16),
                    M::PERAN_POSISI[2] => $deret(2.16, 2.16), M::PERAN_POSISI[3] => $deret(2.14, 2.14),
                ]),
                self::titikHp(3.0, [
                    M::PERAN_POSISI[0] => $deret(3.48, 3.48), M::PERAN_POSISI[1] => $deret(3.48, 3.48),
                    M::PERAN_POSISI[2] => $deret(3.48, 3.48), M::PERAN_POSISI[3] => $deret(3.45, 3.45),
                ]),
            ],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'satuan' => 'kN',
                'tipe_beban' => M::ARAH_PULL,
                'standar' => '100kN',
                'suhu_sertifikat_standar' => '23,45',
                'kapasitas' => '100',
                'resolusi_uut' => '0,01',
                'resolusi_standar' => '0,001',
                'kapasitas_standar' => '100',
                'misalignment' => ['1' => '8,237', '2' => '8,234', '3' => '8,237', '4' => '8,238'],
                'preload' => self::preloadHp([0.01, 0.0, 0.02], [68.86, 70.89, 71.86]),
            ]],
        ];

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)->assertCreated()->json('data.id');

        // Kiriman yang sama dalam bentuk kanonik. Preload bentuk HP wajib
        // DIPAKAI, bukan diabaikan: kalau diabaikan, komponen zero error jatuh
        // ke nol dan U95 yang terbit lebih kecil dari seharusnya — tanpa error.
        $kanonik = $payload;
        unset($kanonik['spesifikasi_alat'][M::KUNCI_SESI]['preload']);
        $kanonik['spesifikasi_alat'][M::KUNCI_SESI]['preload_zero'] = [0.01, 0.0, 0.02];
        $kanonik['spesifikasi_alat'][M::KUNCI_SESI]['preload_max'] = [68.86, 70.89, 71.86];
        $idKanonik = $this->actingAs($teknisi)->postJson('/api/calibrations', $kanonik)->assertCreated()->json('data.id');

        $u = static fn (int $sesi): array => UncertaintyCalculation::where('calibration_session_id', $sesi)
            ->orderBy('titik_ke')->pluck('ketidakpastian_diperluas')->map(fn ($v) => (float) $v)->all();
        $this->assertNotEmpty($u($idKanonik));
        $this->assertEqualsWithDelta($u($idKanonik), $u($id), 1e-12, 'Preload bentuk HP diabaikan server — U95 beda dari bentuk kanonik.');

        $this->assertSame(24, RawMeasurement::where('calibration_session_id', $id)->count());
        $this->assertSame(
            2,
            UncertaintyCalculation::where('calibration_session_id', $id)->count(),
            'Lembar Load Cell dari HP tidak menghasilkan satu titik terhitung pun.',
        );

        $blok = CalibrationSession::findOrFail($id)->spesifikasi_alat[M::KUNCI_SESI];
        $this->assertSame('kN', $blok['satuan'], 'Isian spesifikasi Gaya lain ikut hilang.');
        $this->assertArrayHasKey('preload', $blok, 'Bentuk tabel preload hilang — draft yang dibuka ulang kehilangan preload-nya.');
    }

    public function test_proving_ring_dari_bentuk_hp_terhitung(): void
    {
        [$alat, $teknisi] = $this->siapkan('DEMO-PR-001');

        $titik = [
            [30.0, [237, 237, 238], [238, 237, 238]],
            [90.0, [726, 726, 725], [725, 725, 724]],
            [120.0, [934, 935, 935], [936, 936, 937]],
        ];

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-09-05',
            'suhu_awal' => '28,6', 'suhu_akhir' => '28,6',
            'kelembaban_awal' => '68', 'kelembaban_akhir' => '68',
            'measurements' => array_map(
                static fn (array $t): array => self::titikHp($t[0], [
                    M::PERAN_UP => array_map('floatval', $t[1]),
                    M::PERAN_DOWN => array_map('floatval', $t[2]),
                ]),
                $titik,
            ),
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'satuan' => 'kgf',
                'tipe_beban' => M::ARAH_PUSH,
                'standar' => '3000kN',
                'suhu_sertifikat_standar' => '28,6',
                'kapasitas' => '500',
                'kapasitas_dial_mm' => '25',
                'resolusi_dial_mm' => '0,002',
                'resolusi_standar' => '0,1',
                'kapasitas_standar' => '3000',
                'misalignment' => ['1' => '8,237', '2' => '8,234', '3' => '8,237', '4' => '8,238'],
                'preload' => self::preloadHp([0.0, 0.0, 0.0], [219.0, 219.0, 219.0]),
            ]],
        ])->assertCreated()->json('data.id');

        $this->assertSame(
            18,
            RawMeasurement::where('calibration_session_id', $id)->count(),
            'Pembacaan UP/DOWN Proving Ring tidak tersimpan.',
        );
        $this->assertSame(
            3,
            UncertaintyCalculation::where('calibration_session_id', $id)->count(),
            'Lembar Proving Ring dari HP tidak menghasilkan satu titik terhitung pun.',
        );

        // Koma desimal di kotak dial: `(float) "0,002"` di PHP itu 0.0, bukan
        // 0.002 — dan komponen daya baca budget lahir dari angka ini.
        $blok = CalibrationSession::findOrFail($id)->spesifikasi_alat[M::KUNCI_SESI];
        $this->assertEqualsWithDelta(0.002, (float) $blok['resolusi_dial_mm'], 1e-12);
    }
}
