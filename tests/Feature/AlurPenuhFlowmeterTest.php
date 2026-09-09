<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Support\FlowmeterMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alur penuh **Flowmeter Ultrasonic** dengan payload BENTUK HP.
 *
 * ## Kenapa berkas ini ada, dan kenapa bentuk payloadnya yang menentukan
 *
 * Empat berkas test lain sudah menjaga potongannya — angka lawan master, lantai
 * CMC, gerbang penerbitan, kolom sertifikat. Tidak satu pun mengirimkan payload
 * yang bentuknya SAMA dengan yang benar-benar disusun aplikasi teknisi.
 *
 * Celah itu bukan teoretis. `docs/permintaan-user-7.md` §19 mencatatnya sendiri
 * untuk Micrometer: tiga cacat server ketahuan justru dari sisi HP, dan
 * ketiganya lolos 3.128 test backend **karena test backend memakai payload yang
 * ditulis backend sendiri**.
 *
 * Flowmeter kejadian KEEMPAT dari pola itu, dan yang menemukannya
 * `flowmeter_lembar_test.dart` di repo mobile — bukan suite ini. Waktu itu
 * ketahuan dua hal:
 *
 *  1. Kelima tabel `hasil` tidak menyatakan `simpan_ke`, jadi HP tidak tahu ke
 *     mana angkanya harus dikirim dan membuang seluruh barisnya sebagai
 *     "kosong". Payloadnya sampai ke server dengan `measurements` **KOSONG** —
 *     di lembar yang penuh di layar dan tombol kirimnya jalan mulus.
 *  2. Kedua tabel geometri pipa mengirim bentuk tabel bersarang, sementara
 *     `FlowmeterMentah::blokSesi()` menunggu deret datar.
 *
 * Berkas ini yang menahan keduanya supaya tidak kembali.
 */
class AlurPenuhFlowmeterTest extends TestCase
{
    use RefreshDatabase;

    private const TOL = 5e-6;

    /**
     * Payload persis bentuk yang disusun `LembarKerjaState.toSubmission()`.
     *
     * Deret UUT **bersarang** (ulangan → durasi); deret lain datar. Geometri
     * pipa dikirim sebagai bentuk TABEL, bukan deret datar — itu yang
     * dihasilkan `simpan_ke: 'spesifikasi_alat.…'` di sisi HP.
     *
     * @return array<string, mixed>
     */
    private static function payloadFlowrate(): array
    {
        return [
            'spesifikasi_alat' => [
                'flowmeter' => [
                    'mode' => FlowmeterMentah::MODE_FLOWRATE,
                    'satuan' => 'LPM',
                    'kapasitas' => 9999,
                    'resolusi' => 0.001,
                    // Bentuk TABEL, bukan deret datar — lihat docblock.
                    'diameter_pipa_mm' => ['baris' => [['pembacaan' => [50.81, 50.82, 50.81]]]],
                    'ketebalan_pipa_mm' => ['baris' => [['pembacaan' => [2.32, 2.31, 2.32]]]],
                    'material_pipa' => 'Carbon Steel',
                    'jenis_fluida' => 'Air',
                    'path_configuration' => 'Z',
                ],
            ],
            'measurements' => [
                [
                    'titik_ukur' => 0,
                    'flow_uut_pembacaan' => [
                        [101.255, 101.276, 101.289],
                        [102.654, 102.625, 102.678],
                        [101.986, 101.910, 101.945],
                    ],
                    'flow_std_pembacaan' => [101.998, 101.897, 101.123],
                    'flow_suhu_awal' => [24.5, 24.5, 24.5],
                    'flow_suhu_akhir' => [24.5, 24.6, 24.6],
                ],
                [
                    'titik_ukur' => 0,
                    'flow_uut_pembacaan' => [
                        [309.785, 309.776, 309.779],
                        [311.376, 311.374, 311.398],
                        [310.772, 310.778, 310.745],
                    ],
                    'flow_std_pembacaan' => [308.711, 310.255, 310.251],
                    'flow_suhu_awal' => [24.6, 24.6, 24.6],
                    'flow_suhu_akhir' => [24.6, 24.6, 24.6],
                ],
            ],
        ];
    }

    private function kirim(): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        $alat = Equipment::where('serial_number', 'FM-FLW-DEMO-01')->firstOrFail();
        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $contoh->standard_id,
                'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'suhu_awal' => 24.2,
                'suhu_akhir' => 24.4,
                'kelembaban_awal' => 56,
                'kelembaban_akhir' => 54,
            ] + self::payloadFlowrate())
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /**
     * Pembacaan yang dikirim HP benar-benar TERSIMPAN — dan ini penjaga
     * utamanya.
     *
     * Sebelum jalur `simpan_ke` ada, payload dari HP sampai ke sini dengan
     * `measurements` kosong dan sesinya tersimpan tanpa satu pun baris.
     */
    public function test_pembacaan_bentuk_hp_tersimpan_sebagai_raw_measurements(): void
    {
        $sesi = $this->kirim();

        $peran = $sesi->rawMeasurements->pluck('peran_sensor')->unique()->sort()->values()->all();

        $this->assertSame(
            [
                FlowmeterMentah::PERAN_STD,
                FlowmeterMentah::PERAN_SUHU_AKHIR,
                FlowmeterMentah::PERAN_SUHU_AWAL,
                FlowmeterMentah::PERAN_UUT,
            ],
            $peran,
            'Baris mentahnya nggak lahir dengan kosakata `flow_*`. Kalau kosong sama sekali, '
            .'payload HP nggak pernah nyampe jalur simpan — lembar penuh di layar, nol baris di sini.',
        );

        // Deret UUT BERSARANG: 2 titik x 3 ulangan x 3 durasi = 18 baris.
        $this->assertCount(
            18,
            $sesi->rawMeasurements->where('peran_sensor', FlowmeterMentah::PERAN_UUT),
            'Durasinya meleleh. 18 baris = 2 titik x 3 ulangan x 3 durasi; kalau cuma 6, '
            .'deretnya diratakan dan simpangan bakunya berubah jadi sebaran antar-ulangan.',
        );

        // Dan `sensor_ke` benar-benar membedakan durasinya.
        $titik1 = $sesi->rawMeasurements
            ->where('peran_sensor', FlowmeterMentah::PERAN_UUT)
            ->where('titik_ke', 1)
            ->where('pembacaan_ke', 1)
            ->sortBy('sensor_ke')
            ->pluck('pembacaan')
            ->map(static fn ($x): float => (float) $x)
            ->values()
            ->all();

        $this->assertEqualsWithDelta([101.255, 101.276, 101.289], $titik1, 1e-6);
    }

    /**
     * Geometri pipa bentuk TABEL ikut mendarat — dan tanpa itu setiap titik
     * diblokir dengan alasan "diameter dan ketebalan pipa belum terisi",
     * padahal teknisi sudah mengisinya.
     */
    public function test_geometri_pipa_bentuk_tabel_diratakan(): void
    {
        $blok = FlowmeterMentah::blokSesi($this->kirim()->spesifikasi_alat);

        $this->assertNotNull($blok);
        $this->assertEqualsWithDelta([50.81, 50.82, 50.81], $blok['diameter_pipa_mm'], 1e-9);
        $this->assertEqualsWithDelta([2.32, 2.31, 2.32], $blok['ketebalan_pipa_mm'], 1e-9);
    }

    /**
     * Angkanya sampai ke `uncertainty_calculations`, dan cocok dengan yang
     * sudah diadu ke workbook master.
     *
     * Titik 2 mendarat di lantai CMC (1,2 % x 310,6426 = 3,7277 Lpm) — master
     * lab sendiri menerbitkan 3,2512 di situ, yaitu 1,047 % pada pita
     * terakreditasi 1,2 %.
     */
    public function test_angkanya_sampai_ke_hitungan_dan_cocok_master(): void
    {
        $hitungan = $this->kirim()->uncertaintyCalculations->keyBy('titik_ke');

        $this->assertCount(
            2,
            $hitungan,
            'Sesinya tersimpan tapi nggak menghasilkan titik — jalur hitungnya putus di antara '
            .'controller dan profilnya.',
        );

        $kolom = [
            'uut' => 'rata_rata',
            'koreksi' => 'koreksi',
            'u95' => 'ketidakpastian_diperluas',
        ];

        foreach ([
            1 => ['uut' => 101.95755555555554, 'koreksi' => -1.278888888888872, 'u95' => 1.9309242290111408],
            2 => ['uut' => 310.6425555555555, 'koreksi' => -3.8145555555555006, 'u95' => 3.727710666666666],
        ] as $titikKe => $harap) {
            $baris = $hitungan[$titikKe];

            foreach ($harap as $nama => $nilai) {
                $this->assertEqualsWithDelta(
                    $nilai,
                    (float) $baris->{$kolom[$nama]},
                    abs($nilai) * self::TOL,
                    "Titik {$titikKe} kolom {$nama} bergeser dari angka yang sudah diadu ke master.",
                );
            }
        }
    }
}
