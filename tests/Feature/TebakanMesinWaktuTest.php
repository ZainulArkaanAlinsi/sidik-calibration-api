<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Support\WaktuMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tebakan mesin lembar WAKTU (Timer/Stopwatch) — empat kotak, satu penunjukan.
 *
 * ## Kenapa lembar ini beda dari lima jalur lain
 *
 * Satu penunjukan stopwatch ditulis di EMPAT kotak (jam/menit/detik/milidetik),
 * tapi yang tersimpan SATU baris `raw_measurements` dalam milidetik. Jadi empat
 * tebakan mesin nggak punya empat kolom buat ditaruh.
 *
 * Yang diukur karena itu **penunjukannya**, bukan teks per kotak — dan
 * penunjukan yang dilihat kamera cuma bisa diadu ke penunjukan yang dikirim
 * teknisi kalau dua-duanya disusun dengan cara yang SAMA. Karena itu jalurnya
 * `waktuKeMilidetik` yang sama, bukan salinan aturannya.
 *
 * ## Tiga penolakan yang dijaga di sini
 *
 * Ketiganya gagal TANPA error kalau salah, dan dua di antaranya menghasilkan
 * angka yang bentuknya wajar:
 *
 *  1. kotak berisi yang nggak ketebak → nggak boleh dicampur jadi satu angka;
 *  2. tebakan bukan angka (`1S`) → `(int) '1S'` di PHP itu `1`, diam dan salah;
 *  3. keyakinan → kotak TERLEMAH, bukan rata-rata yang bikin tiga kotak yakin
 *     menutupi satu kotak ragu.
 */
class TebakanMesinWaktuTest extends TestCase
{
    use RefreshDatabase;

    private const SESI_TIMER = '015-CAL-424';

    /** Penunjukan final: 0 j + 1 m + 0 s + 123 ms = 60 123 ms. */
    private const FINAL = ['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 123];

    /**
     * @param  array<string, mixed>  $tambahan
     */
    private function kirim(array $tambahan, string $metode = 'ocr'): int
    {
        $sesi = CalibrationSession::where('nomor_sesi', self::SESI_TIMER)->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        return $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $sesi->equipment_id,
            'standard_id' => $sesi->standard_id,
            'input_method' => $metode,
            'tanggal_kalibrasi' => '2026-09-01',
            'measurements' => [[
                'titik_ukur' => 60,
                'standar' => [self::FINAL],
                'uut' => [['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 131]],
                ...$tambahan,
            ]],
        ])->assertCreated()->json('data.id');
    }

    private function barisStandar(int $sesi): RawMeasurement
    {
        return RawMeasurement::where('calibration_session_id', $sesi)
            ->where('peran_sensor', WaktuMentah::PERAN_STANDAR)
            ->orderBy('pembacaan_ke')
            ->firstOrFail();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_empat_tebakan_disusun_jadi_satu_penunjukan(): void
    {
        // Kamera membaca milidetiknya 723, bukan 123 — jadi penunjukan yang
        // dilihatnya 60 723 ms, sementara teknisi mengirim 60 123 ms.
        $sesi = $this->kirim([
            'standar_ocr' => [[
                'jam' => ['raw_text' => '0'],
                'menit' => ['raw_text' => '1'],
                'detik' => ['raw_text' => '0'],
                'milidetik' => ['raw_text' => '723'],
            ]],
        ]);

        $baris = $this->barisStandar($sesi);

        $this->assertSame(60123.0, (float) $baris->pembacaan, 'Yang dikirim teknisi tetap menang.');
        $this->assertSame(
            60723.0,
            (float) $baris->ocr_raw_text,
            'Penunjukan yang dilihat kamera harus disusun dengan cara yang sama '
            .'dengan nilai finalnya — bukan teks satu kotak.',
        );
    }

    public function test_keyakinan_diambil_dari_kotak_terlemah(): void
    {
        $sesi = $this->kirim([
            'standar_ocr' => [[
                'jam' => ['raw_text' => '0', 'confidence' => 0.99],
                'menit' => ['raw_text' => '1', 'confidence' => 0.98],
                'detik' => ['raw_text' => '0', 'confidence' => 0.97],
                // Kotak yang paling nggak diyakini — dan justru kotak ini yang
                // paling sering salah baca.
                'milidetik' => ['raw_text' => '123', 'confidence' => 0.42],
            ]],
        ]);

        $this->assertSame(
            0.42,
            (float) $this->barisStandar($sesi)->ocr_confidence,
            'Rata-rata bakal bikin tiga kotak yakin menutupi satu kotak ragu.',
        );
    }

    public function test_kotak_berisi_yang_nggak_ketebak_bikin_tebakannya_dibuang(): void
    {
        // Kamera cuma dapat tiga kotak; `milidetik` diketik teknisi. Menyusun
        // dari sebagian berarti mencampur tebakan mesin dengan ketikan orang
        // jadi satu angka yang nggak pernah dilihat siapa pun.
        $sesi = $this->kirim([
            'standar_ocr' => [[
                'jam' => ['raw_text' => '0'],
                'menit' => ['raw_text' => '1'],
                'detik' => ['raw_text' => '0'],
            ]],
        ]);

        $this->assertNull($this->barisStandar($sesi)->ocr_raw_text);
    }

    public function test_tebakan_yang_bukan_angka_ditolak_bukan_dibulatkan(): void
    {
        // `(int) '1S'` di PHP itu 1 — diam, dan salah. Kalau lolos, penunjukan
        // yang tercatat 60 123 ms: sama persis dengan angka teknisi, jadi
        // kelihatan COCOK padahal mesinnya nggak bisa baca kotak itu.
        $sesi = $this->kirim([
            'standar_ocr' => [[
                'jam' => ['raw_text' => '0'],
                'menit' => ['raw_text' => '1S'],
                'detik' => ['raw_text' => '0'],
                'milidetik' => ['raw_text' => '123'],
            ]],
        ]);

        $this->assertNull($this->barisStandar($sesi)->ocr_raw_text);
    }

    public function test_baris_bermetadata_nunggu_verifikasi_walau_sesinya_manual(): void
    {
        $sesi = $this->kirim([
            'standar_ocr' => [[
                'jam' => ['raw_text' => '0'],
                'menit' => ['raw_text' => '1'],
                'detik' => ['raw_text' => '0'],
                'milidetik' => ['raw_text' => '123'],
            ]],
        ], metode: 'manual');

        $baris = $this->barisStandar($sesi);

        $this->assertFalse((bool) $baris->is_verified);
        $this->assertSame('ocr', $baris->input_source);

        // Sisi UUT nggak difoto — dia tetap manual & langsung terverifikasi.
        $uut = RawMeasurement::where('calibration_session_id', $sesi)
            ->where('peran_sensor', WaktuMentah::PERAN_UUT)
            ->firstOrFail();

        $this->assertTrue((bool) $uut->is_verified);
        $this->assertSame('manual', $uut->input_source);
    }

    public function test_kotak_yang_nggak_dikenal_ditolak_422(): void
    {
        $sesi = CalibrationSession::where('nomor_sesi', self::SESI_TIMER)->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $sesi->equipment_id,
            'standard_id' => $sesi->standard_id,
            'tanggal_kalibrasi' => '2026-09-01',
            'measurements' => [[
                'titik_ukur' => 60,
                'standar' => [self::FINAL],
                'uut' => [self::FINAL],
                // Salah ketik yang sama dengan yang sudah dijaga di deret
                // nilainya — tebakan yang mendarat di kotak yang nggak ada
                // bakal hilang tanpa gejala.
                'standar_ocr' => [['milidetk' => ['raw_text' => '123']]],
            ]],
        ])->assertStatus(422);
    }

    public function test_sel_waktu_kehitung_di_ocr_akurasi_kamera(): void
    {
        $this->kirim([
            'standar_ocr' => [[
                'jam' => ['raw_text' => '0', 'confidence' => 0.95],
                'menit' => ['raw_text' => '1', 'confidence' => 0.95],
                'detik' => ['raw_text' => '0', 'confidence' => 0.95],
                // Salah baca, dan kotak terlemahnya masih di atas ambang hijau.
                'milidetik' => ['raw_text' => '723', 'confidence' => 0.93],
            ]],
        ]);

        Artisan::call('ocr:akurasi-kamera');
        $keluaran = Artisan::output();

        $this->assertStringContainsString('HIJAU PALSU', $keluaran);
        $this->assertStringContainsString('60723', $keluaran);
    }
}
