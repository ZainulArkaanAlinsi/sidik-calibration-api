<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Support\MicrometerMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur penuh lembar **Micrometer**: payload HP → `raw_measurements` → hitung
 * ulang, dan angkanya sama di ketiga titik itu.
 *
 * ## Kenapa test ini ada
 *
 * Pola "alat yang satu titiknya bukan satu deret datar" sudah menggigit
 * **delapan kali** — Viscometer, Gas Detector, TITS, Enclosure, tiga alat suhu,
 * Timbangan, Timer/Stopwatch. Bentuknya selalu sama dan selalu **tanpa error**:
 * jalur simpan menaruh bentuknya benar, jalur hitung ulang tidak tahu cara
 * menyusunnya balik, dan tiap titik pulang `hitung_ulang_gagal` sampai admin
 * belajar menekan "setujui tetap" tanpa membaca.
 *
 * Micrometer bentuk kesembilan. Test ini ditulis bareng profilnya, bukan
 * ditemukan belakangan.
 */
class MicrometerSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /**
     * Payload sesi ringkas — tiga titik, cukup buat membuktikan jalurnya.
     *
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $ganti = []): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 20.5, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 41, 'kelembaban_akhir' => 40,
            'measurements' => [
                [
                    'titik_ukur' => 25.0,
                    MicrometerMentah::PERAN_BALOK => [25.0],
                    MicrometerMentah::PERAN_PEMBACAAN => [25.001, 25.0, 25.001, 25.0, 25.001],
                ],
                [
                    'titik_ukur' => 40.0,
                    MicrometerMentah::PERAN_BALOK => [40.0],
                    MicrometerMentah::PERAN_PEMBACAAN => [40.002, 40.001, 40.002, 40.001, 40.002],
                ],
                // Titik BERTUMPUK — dua keping di-wringing. Ini bentuk yang
                // menyingkap `ci` master memakai keping pertama, bukan total.
                [
                    'titik_ukur' => 50.0,
                    MicrometerMentah::PERAN_BALOK => [40.0, 9.0],
                    MicrometerMentah::PERAN_PEMBACAAN => [49.003, 49.002, 49.003, 49.002, 49.003],
                ],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '25-50', 'kapasitas' => '50', 'resolusi' => '0.001',
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 50.0,
                    'resolusi_mm' => 0.001,
                    'suhu_balok_c' => 20.55,
                    'suhu_uut_c' => 20.55,
                    'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0],
                    'balok_pra_evaluasi' => [50.0],
                ],
            ],
            ...$ganti,
        ];
    }

    /** @return array{Equipment, User} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'ZQ-100')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * Tumpukan balok ukur dan deret pembacaan disimpan sebagai baris
     * ber-`peran_sensor` yang TERPISAH — bukan satu deret campuran.
     */
    public function test_payload_hp_tersimpan_sebagai_dua_peran(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 3)
            ->get();

        $balok = $baris->where('peran_sensor', MicrometerMentah::PERAN_BALOK)
            ->sortBy('sensor_ke')->pluck('pembacaan')->map('floatval')->all();
        $baca = $baris->where('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)
            ->sortBy('sensor_ke')->pluck('pembacaan')->map('floatval')->all();

        $this->assertSame([40.0, 9.0], array_values($balok), 'tumpukan balok ukur titik 3');
        $this->assertCount(5, $baca);
        $this->assertEqualsWithDelta(49.003, $baca[0], self::TOLERANSI);

        // Satuan simpan SELALU mm — lihat MicrometerMentah::SATUAN.
        $this->assertSame([MicrometerMentah::SATUAN], $baris->pluck('satuan')->unique()->all());
    }

    /**
     * Sesi yang baru disimpan bisa dihitung ULANG dan hasilnya sama — nol titik
     * `hitung_ulang_gagal`.
     *
     * Ini gerbang yang menangkap pola kesembilan itu.
     */
    public function test_sesi_tersimpan_bisa_dihitung_ulang_tanpa_beda(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        // Exit code 0 = nol titik gagal — lihat `HitungUlangSesi::handle()`,
        // yang memulangkan FAILURE begitu satu titik pun tidak bisa disusun
        // ulang. Itu gerbang yang menangkap pola kesembilan ini.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])
            ->assertSuccessful();
    }

    /**
     * Satuan `inch` dikonversi SEKALI di ujung masuk: yang tersimpan mm, dan
     * nominal balok ukur TIDAK ikut dikonversi.
     *
     * Ini yang membedakan kita dari master, yang mengalikan pembacaan 25,4 di
     * dalam rumus sementara kolom standarnya tetap mm — dan karena itu
     * menerbitkan koreksi −61 mm pada balok ukur 2,5 mm.
     */
    public function test_satuan_inch_dikonversi_sekali_dan_hanya_pembacaan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['satuan'] = 'inch';
        // 1 inch = 25,4 mm.
        $payload['measurements'] = [[
            'titik_ukur' => 25.4,
            MicrometerMentah::PERAN_BALOK => [25.0],
            MicrometerMentah::PERAN_PEMBACAAN => [1.0, 1.0],
        ]];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)->get();

        $this->assertEqualsWithDelta(
            25.4,
            (float) $baris->firstWhere('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)->pembacaan,
            self::TOLERANSI,
            'pembacaan 1 inch harus tersimpan 25,4 mm',
        );
        $this->assertEqualsWithDelta(
            25.0,
            (float) $baris->firstWhere('peran_sensor', MicrometerMentah::PERAN_BALOK)->pembacaan,
            self::TOLERANSI,
            'nominal balok ukur JANGAN ikut dikonversi — sertifikatnya selalu mm',
        );
    }

    /**
     * Kapasitas di luar keempat pita CMC memblokir penerbitan U95, dan
     * peringatan sesinya menyebut sebabnya.
     *
     * Ini bentuk yang menjatuhkan master 0-25 mm: satuan `inch` × kapasitas 25
     * = 635 mm, di luar semua pita, dan U95 terbit 0,735 µm tanpa lantai 0,83.
     */
    public function test_kapasitas_di_luar_pita_cmc_diblokir(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['kapasitas_mm'] = 635.0;

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);

        // NOL baris hitungan — bukan baris ber-U95 nol.
        //
        // Bedanya menentukan: baris ber-`ketidakpastian_diperluas` 0 tetap
        // tercetak di sertifikat sebagai `± 0,000`, yaitu klaim pengukuran
        // SEMPURNA — lebih buruk daripada 0,735 µm yang sedang diperbaiki. Dan
        // peringatan sesi tidak menahannya: `CalibrationValidator` membungkus
        // `peringatanSesi()` jadi temuan tingkat PERINGATAN yang boleh dilewati
        // admin lewat `abaikan_peringatan`.
        $this->assertSame(
            0,
            $sesi->uncertaintyCalculations()->count(),
            'sesi tanpa pita CMC tidak boleh melahirkan satu pun baris hitungan',
        );

        $peringatan = collect((new MicrometerProfile)->peringatanSesi($sesi));

        $this->assertTrue(
            $peringatan->contains(fn (array $p): bool => $p['kode'] === 'micrometer_di_luar_cmc'),
            'sesi di luar pita CMC wajib memperingatkan dirinya sendiri',
        );

        // Bentuknya WAJIB `kode` + `pesan` saja — itu yang dibaca
        // `CalibrationValidator::periksaPeringatanProfil()`. Kunci lain
        // (`tingkat`, `judul`) dibuang diam-diam, jadi menaruhnya di sini cuma
        // membohongi pembaca kode.
        $this->assertSame(
            ['kode', 'pesan'],
            array_keys($peringatan->firstWhere('kode', 'micrometer_di_luar_cmc')),
        );
    }

    /**
     * Sesi tanpa blok pra-evaluasi TIDAK dihitung dengan pengulangan nol — dia
     * ditolak dengan alasan yang kebaca.
     */
    public function test_tanpa_blok_pra_evaluasi_titiknya_ditolak_bukan_dihitung(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        unset($payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]);

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        $this->assertSame(
            0, $sesi->uncertaintyCalculations()->count(),
            'tanpa pra-evaluasi tidak boleh ada satu pun titik terhitung',
        );
    }

    /**
     * Blok pra-evaluasi yang ADA tapi cuma berisi SATU pembacaan juga tidak
     * menerbitkan apa-apa.
     *
     * Ini bentuk yang paling licin: simpangan bakunya jatuh ke nol, komponen
     * pengulangan hilang dari budget, U95 mendarat di lantai CMC (0,87 µm) —
     * dan hasilnya kelihatan **wajar**. Persis jebakan "sel kosong dibaca nol"
     * yang aturan proyek larang ditiru.
     */
    public function test_pra_evaluasi_satu_pembacaan_tidak_menerbitkan_u95(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][MicrometerMentah::KUNCI_SESI]['pra_evaluasi'] = [50.0];

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        $this->assertSame(
            0,
            $sesi->uncertaintyCalculations()->count(),
            'pra-evaluasi satu pembacaan tidak punya simpangan baku — jangan terbit',
        );

        // Alasannya diadu di jalur PREVIEW, karena di situ `belum_dihitung`
        // memang dipulangkan ke teknisi — `store()` cuma menyimpan. Tanpa
        // pemeriksaan ini, sesi yang diblokir tampil sebagai tabel kosong tanpa
        // sebab, dan teknisi membacanya sebagai bug.
        $alasan = collect(
            $this->actingAs($teknisi)
                ->postJson('/api/calibrations/preview', $payload)
                ->assertSuccessful()
                ->json('data.belum_dihitung') ?? []
        )->pluck('alasan')->implode(' ');

        $this->assertStringContainsString('Pra-evaluasi', $alasan);
    }
}
