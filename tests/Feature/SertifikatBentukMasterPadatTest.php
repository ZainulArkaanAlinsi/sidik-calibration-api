<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use App\Support\AnakTimbanganMentah;
use App\Support\TataLetakSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mode padat bentuk master (K-50-15) harus menyisakan ruang untuk isi khas
 * master yang dipasang hook profil, tetap satu halaman dengan sisa ≥ 6 px
 * (`SertifikatPunyaMarginTest::MARGIN_MIN`).
 *
 * Kasus terberat: Anak Timbangan 40 keping (batas `BATAS_KEPING_SATU_HALAMAN`)
 * berbentuk master `139`: judul `1. ACCURACY` (`C14`), kolom `ID Number`
 * (`C15`), baris satuan (`F16:L16`, U dalam mg), dan `End of Certificate`
 * (`G68`). Isi itu disuntikkan ke snapshot dalam bentuk yang sama dengan yang
 * akan dibekukan hook profilnya.
 */
class SertifikatBentukMasterPadatTest extends TestCase
{
    use RefreshDatabase;

    private const NOMINAL = [1.0, 2.0, 5.0, 10.0, 20.0, 50.0, 100.0, 200.0];

    private const SISA_MIN_PX = 6;

    public function test_anak_timbangan_40_keping_bentuk_master_lengkap_muat_satu_halaman(): void
    {
        $this->seed(DatabaseSeeder::class);

        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();
        $keping = [];

        for ($i = 0; $i < 40; $i++) {
            $n = self::NOMINAL[$i % count(self::NOMINAL)];
            $keping[] = [
                'titik_ukur' => $n, 'no_identitas' => 'K'.($i + 1),
                'at_s1' => [$n, $n, $n], 'at_t1' => [$n + 0.0001, $n + 0.0001, $n + 0.0001],
                'at_t2' => [$n + 0.0001, $n + 0.0001, $n + 0.0001], 'at_s2' => [$n, $n, $n],
            ];
        }

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $contoh->equipment_id,
            'standard_id' => $contoh->standard_id,
            'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
            'tanggal_kalibrasi' => '2026-09-11',
            'suhu_awal' => 23.1, 'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
            'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => [
                'kelas_uut' => 'F1', 'kelas_standar' => 'E2', 'timbangan' => 'Analytical Balance',
                'meter_lingkungan' => 'Thermobarometer', 'kapasitas_min_g' => 1, 'kapasitas_g' => 200,
                'suhu_awal' => 23.1, 'suhu_akhir' => 23.0, 'kelembaban_awal' => 55.0, 'kelembaban_akhir' => 56.0,
                'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            ]],
            'measurements' => $keping,
        ])->assertCreated()->json('data.id');

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->denganOrderNumber($id)->actingAs($admin)
            ->postJson("/api/calibrations/{$id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        $sertifikat = CalibrationSession::findOrFail($id)->certificate()->firstOrFail();
        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);
        $snap = $bahan['snapshot'];
        $snap['tata_letak'] = [...$snap['tata_letak'], ...TataLetakSertifikat::normalisasi([
            'kolom_hasil' => [
                ['kolom' => 'tambahan:id', 'judul' => 'ID Number'],
                ['kolom' => 'standar', 'judul' => 'Nominal'],
                ['kolom' => 'uut', 'judul' => 'Conventional Mass'],
                ['kolom' => 'u95', 'judul' => 'Uncertainty ±', 'satuan' => 'mg', 'faktor' => 1000],
            ],
            'akhir_sertifikat' => '------- End of Certificate -------',
        ])];
        $snap['catatan_blok'] = TataLetakSertifikat::normalisasiBlok([[
            'posisi' => TataLetakSertifikat::POSISI_ATAS_HASIL, 'judul' => '1. ACCURACY',
        ]]);
        foreach ($snap['hasil'] as $i => $b) {
            $snap['hasil'][$i]['tambahan'] = ['id' => 'K'.($i + 1)];
        }
        $bahan['snapshot'] = $snap;

        $html = view('sertifikat.pdf', [...$bahan, 'paksaPadat' => true])->render();
        $this->assertStringContainsString('1. ACCURACY', $html);
        $this->assertStringContainsString('End of Certificate', $html);
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th></th>\s*<th>g</th>\s*<th>g</th>\s*<th>mg</th>#u', $html);

        $sisip = str_replace('</body>', sprintf('<div style="height:%dpx"></div></body>', self::SISA_MIN_PX), $html);
        $pdf = Pdf::loadHTML($sisip);
        $pdf->output();

        $this->assertSame(1, (int) $pdf->getDomPDF()->getCanvas()->get_page_count(),
            'Anak Timbangan 40 keping bentuk master tidak menyisakan '.self::SISA_MIN_PX.' px di satu halaman.');
    }
}
