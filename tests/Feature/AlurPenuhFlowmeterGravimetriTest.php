<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Calibration\FlowmeterGravimetriCalculator;
use App\Support\FlowmeterMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alur penuh **Flowmeter Totalizer, varian GRAVIMETRI** dengan payload bentuk
 * HP (`POST /api/calibrations`).
 *
 * Kenapa berkas ini ada: `AlurPenuhFlowmeterTest` hanya mengirim varian UFM
 * (`flow_std_pembacaan`), dan sesi gravimetri yang ada di test lain ditanam
 * seeder langsung ke tabel — tidak ada satu test pun yang mengirim
 * `flow_berat_isi` / `flow_berat_kosong` lewat HTTP. Akibatnya jalur simpan
 * (`CalibrationController::susunBlokFlowmeter`) yang hanya memungut empat deret
 * (`std`, suhu awal, suhu akhir, densitas) membuang berat isi dan berat wadah
 * kosong tanpa satu pun error: titiknya tersimpan "belum dihitung" dengan alasan
 * "yang ada 3 dan 0 penimbangan".
 *
 * Ditemukan 20 Nov 2026 waktu memasukkan lembar Flowmeter Air Totalizer pusat
 * (kertas SIDIK-FM-CAL-0538.B) lewat API.
 */
class AlurPenuhFlowmeterGravimetriTest extends TestCase
{
    use RefreshDatabase;

    /** Titik 1 master gravimetri Totalizer (`INPUT DATA!D35:M48`), timbangan 1 — dari FlowmeterGravimetriSeeder. */
    private const UUT = [[140.11], [140.12], [140.09]];

    private const BERAT_ISI = [139.9, 139.8, 139.9];

    private const BERAT_KOSONG = [0.0, 0.0, 0.0];

    private const SUHU_AWAL = [25.5, 25.5, 25.5];

    private const SUHU_AKHIR = [25.4, 25.4, 25.4];

    /** @return array<string, mixed> */
    private static function payloadTotalizerGravimetri(): array
    {
        return [
            'spesifikasi_alat' => [
                'flowmeter' => [
                    'mode' => FlowmeterMentah::MODE_TOTALIZER,
                    'varian_metode' => 'gravimetri',
                    'kode_timbangan' => 1,
                    'satuan' => 'L',
                    'kapasitas' => 9999,
                    'resolusi' => 0.01,
                    'jenis_fluida' => 'Air',
                ],
            ],
            'measurements' => [
                [
                    'titik_ukur' => 0,
                    'flow_uut_pembacaan' => self::UUT,
                    'flow_berat_isi' => self::BERAT_ISI,
                    'flow_berat_kosong' => self::BERAT_KOSONG,
                    'flow_suhu_awal' => self::SUHU_AWAL,
                    'flow_suhu_akhir' => self::SUHU_AKHIR,
                ],
            ],
        ];
    }

    private function kirim(): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        $alat = Equipment::where('serial_number', 'FM-TOT-DEMO-01')->firstOrFail();
        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-FM-TOT-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $contoh->standard_id,
                'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'suhu_awal' => 25.7,
                'suhu_akhir' => 25.8,
                'kelembaban_awal' => 41,
                'kelembaban_akhir' => 40,
            ] + self::payloadTotalizerGravimetri())
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /** Berat isi, berat wadah kosong, dan suhu air tersimpan sebagai baris mentah — bukan dibuang. */
    public function test_berat_isi_dan_wadah_kosong_tersimpan_sebagai_raw_measurements(): void
    {
        $sesi = $this->kirim();

        $this->assertSame(
            self::BERAT_ISI,
            $sesi->rawMeasurements
                ->where('peran_sensor', FlowmeterMentah::PERAN_BERAT_ISI)
                ->sortBy('pembacaan_ke')
                ->map(static fn ($b): float => (float) $b->pembacaan)
                ->values()
                ->all(),
            'Berat isi yang dikirim HP tidak sampai ke raw_measurements — jalur simpan hanya memungut '
            .'std/suhu/densitas dan membuang tiga peran gravimetri.',
        );

        $this->assertCount(
            3,
            $sesi->rawMeasurements->where('peran_sensor', FlowmeterMentah::PERAN_BERAT_KOSONG),
            'Berat wadah kosong ikut dibuang. Kalau wadah sungguhan dipakai, massa bersih kelebihan beratnya.',
        );
    }

    /** Titiknya terhitung (bukan "belum dihitung") dan angkanya sama dengan mesin hitung dipanggil langsung. */
    public function test_titik_gravimetri_terhitung_dan_sama_dengan_mesin_hitung(): void
    {
        $sesi = $this->kirim();

        $hitungan = $sesi->uncertaintyCalculations->keyBy('titik_ke');
        $this->assertCount(
            1,
            $hitungan,
            'Titik gravimetri tidak menghasilkan baris hitungan — penimbangannya tidak sampai ke profil.',
        );

        $langsung = (new FlowmeterGravimetriCalculator)->hitungSesi([[
            'titik_ke' => 1,
            'uut' => self::UUT,
            'berat_isi' => self::BERAT_ISI,
            'berat_kosong' => self::BERAT_KOSONG,
            'waktu_menit' => [],
            'suhu_awal' => self::SUHU_AWAL,
            'suhu_akhir' => self::SUHU_AKHIR,
        ]], [
            'mode' => FlowmeterMentah::MODE_TOTALIZER,
            'satuan' => 'L',
            'resolusi' => 0.01,
            'kode_timbangan' => 1,
        ]);

        $this->assertEmpty($langsung['ditolak'], 'Mesin hitung sendiri menolak data uji ini.');
        $baris = $hitungan[1];

        $this->assertEqualsWithDelta(
            $langsung['titik'][0]['deviasi'],
            (float) $baris->koreksi,
            abs($langsung['titik'][0]['deviasi']) * 1e-6 + 1e-7,
            'Koreksi tersimpan beda dari mesin hitung untuk masukan yang sama.',
        );
        $this->assertEqualsWithDelta(
            $langsung['titik'][0]['u95_sertifikat'],
            (float) $baris->ketidakpastian_diperluas,
            $langsung['titik'][0]['u95_sertifikat'] * 1e-6,
        );
    }
}
