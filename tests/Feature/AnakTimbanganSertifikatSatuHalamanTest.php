<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use App\Services\DataTampilanSertifikat;
use App\Support\AnakTimbanganMentah;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sertifikat Anak Timbangan tetap SATU halaman di jumlah keping lapangan.
 *
 * Pemilik proyek 6 Okt 2026: sertifikat apa pun wajib pas satu halaman. Satu
 * set anak timbangan di lapangan bisa 15 keping atau lebih (kertas cuma 10
 * baris, HP bisa menambah sampai 60), sementara penjaga lintas alat
 * (`SertifikatSemuaAlatSatuHalamanTest`) cuma memeriksa sesi contoh 14 keping.
 */
class AnakTimbanganSertifikatSatuHalamanTest extends TestCase
{
    use RefreshDatabase;

    private const NOMINAL = [1.0, 2.0, 5.0, 10.0, 20.0, 50.0, 100.0, 200.0];

    /** @return array<string, array{int}> */
    public static function jumlahKeping(): array
    {
        return ['15 keping' => [15], '30 keping' => [30], '40 keping' => [40]];
    }

    /**
     * Lebih dari batas = sertifikat dua halaman, jadi kirimannya ditolak di
     * server (HP berhenti di batas yang sama). Draft tetap boleh tersimpan.
     */
    public function test_lebih_dari_batas_keping_kiriman_ditolak_draft_boleh(): void
    {
        $this->seed(DatabaseSeeder::class);

        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();
        $jumlah = AnakTimbanganProfile::BATAS_KEPING_SATU_HALAMAN + 1;

        $keping = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $n = self::NOMINAL[$i % count(self::NOMINAL)];
            $keping[] = [
                'titik_ukur' => $n,
                'no_identitas' => 'K'.($i + 1),
                'at_s1' => [$n, $n, $n], 'at_t1' => [$n, $n, $n],
                'at_t2' => [$n, $n, $n], 'at_s2' => [$n, $n, $n],
            ];
        }
        $payload = [
            'equipment_id' => $contoh->equipment_id,
            'standard_id' => $contoh->standard_id,
            'tanggal_kalibrasi' => '2026-09-11',
            'suhu_awal' => 23.1, 'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
            'measurements' => $keping,
        ];

        $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('measurements');

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [...$payload, 'status' => CalibrationSession::STATUS_DRAFT])
            ->assertCreated();
    }

    #[DataProvider('jumlahKeping')]
    public function test_sertifikat_muat_satu_halaman(int $jumlah): void
    {
        $this->seed(DatabaseSeeder::class);

        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $alat = Equipment::findOrFail($contoh->equipment_id);
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $keping = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $n = self::NOMINAL[$i % count(self::NOMINAL)];
            $keping[] = [
                'titik_ukur' => $n,
                'no_identitas' => 'K'.($i + 1),
                'at_s1' => [$n, $n, $n],
                'at_t1' => [$n + 0.0001, $n + 0.0001, $n + 0.0001],
                'at_t2' => [$n + 0.0001, $n + 0.0001, $n + 0.0001],
                'at_s2' => [$n, $n, $n],
            ];
        }

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $contoh->standard_id,
                'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
                'tanggal_kalibrasi' => '2026-09-11',
                'suhu_awal' => 23.1, 'suhu_akhir' => 23.0,
                'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
                'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => [
                    'kelas_uut' => 'F1',
                    'kelas_standar' => 'E2',
                    'timbangan' => 'Analytical Balance',
                    'meter_lingkungan' => 'Thermobarometer',
                    'kapasitas_min_g' => 1,
                    'kapasitas_g' => 200,
                    'suhu_awal' => 23.1, 'suhu_akhir' => 23.0,
                    'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
                    'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
                ]],
                'measurements' => $keping,
            ])
            ->assertCreated()
            ->json('data.id');

        // Satu halaman yang muat karena keping DITAHAN bukan bukti apa-apa.
        $this->assertSame(
            $jumlah,
            UncertaintyCalculation::where('calibration_session_id', $id)->count(),
            'Tidak semua keping terhitung — sertifikatnya bukan sertifikat '.$jumlah.' keping.',
        );

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $sertifikat = CalibrationSession::findOrFail($id)->certificate()->first();
        $this->assertInstanceOf(Certificate::class, $sertifikat);

        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);

        // U95 PER KEPING, bukan satu baris di bawah tabel: tiap keping punya
        // budget sendiri (CAL/2026/10/0001 sempat mencetak U95 keping 1 g untuk
        // keping 500 g). Kolom tambahan ini juga wajib tetap muat satu halaman.
        $this->assertTrue($bahan['snapshot']['u95_per_titik'] ?? false, 'U95 Anak Timbangan harus dicetak per keping.');
        $halaman = $this->halaman($bahan, false);
        if ($halaman > 1) {
            $halaman = $this->halaman($bahan, true);
        }

        $this->assertSame(1, $halaman, "Sertifikat Anak Timbangan {$jumlah} keping meluap ke {$halaman} halaman.");
    }

    private function halaman(array $bahan, bool $paksaPadat): int
    {
        $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat]);
        $pdf->output();

        return (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
    }
}
