<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tebakan mesin di lembar MATRIKS (Autoklaf) ikut tersimpan dan terukur.
 *
 * ## Kenapa Autoklaf butuh jalurnya sendiri
 *
 * Dua puluh alat lain menyimpan angka ukurnya di `raw_measurements`, dan di
 * situlah kolom `ocr_raw_text` menunggu. **Autoklaf nggak pernah menulis satu
 * baris pun ke tabel itu** — hasil ukurnya snapshot JSON `hasil_autoclave`.
 *
 * Akibatnya, kalau blok tebakannya cuma dikirim tanpa tempat menyimpan, seluruh
 * lembar Autoklaf hilang dari pengukuran akurasi — dan diamnya bakal kebaca
 * sebagai "kameranya bagus di Autoklaf", padahal artinya nol data. Itu bentuk
 * kebohongan yang sama dengan hijau palsu, cuma satu tingkat lebih tinggi.
 */
class TebakanMesinAutoclaveTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $org->id,
                'kode' => 'autoklaf',
            ])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * @param  array<string, mixed>  $ocr
     */
    private function kirim(array $ocr = []): CalibrationSession
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', [
                'equipment_id' => $this->alat->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'input_method' => 'ocr',
                'suhu_awal' => 24.4, 'suhu_akhir' => 24.5,
                'kelembaban_awal' => 55, 'kelembaban_akhir' => 56,
                'set_point' => 121.0,
                'suhu' => [
                    'disk' => [
                        [121.27, 121.26, 121.26, 121.26, 121.28],
                        [121.30, 121.26, 121.26, 121.25, 121.25],
                        [121.26, 121.26, 121.28, 121.35, 121.28],
                    ],
                    'indikator' => [121, 121, 121, 121, 121],
                    'suhu_ruang' => [25, 25, 25, 25, 25],
                ],
                'tekanan' => [
                    'uut_setting' => 0.112,
                    'satuan' => 'MPa',
                    'display' => 'Digital',
                    'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                ],
                ...($ocr === [] ? [] : ['ocr' => $ocr]),
            ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_blok_ocr_tersimpan_tanpa_mencemari_lembar(): void
    {
        $sesi = $this->kirim([
            // disk[0] itu DERET per Repeat, jadi tebakannya satu tingkat lebih dalam.
            'suhu' => ['disk' => [[['raw_text' => '121.27', 'confidence' => 0.9]]]],
        ]);

        $hasil = $sesi->hasil_autoclave;

        $this->assertSame(
            '121.27',
            $hasil['ocr']['suhu']['disk'][0][0]['raw_text'],
        );
        $this->assertArrayNotHasKey(
            'ocr',
            $hasil['lembar'],
            '`lembar` diumpankan ke kalkulator waktu sesi dihitung ulang — kunci asing di situ nggak punya tempat.',
        );
        // Angka ukurnya sendiri nggak boleh bergeser sedikit pun.
        $this->assertSame(121.27, (float) $hasil['lembar']['suhu']['disk'][0][0]);
    }

    public function test_tanpa_blok_ocr_snapshotnya_persis_seperti_sebelumnya(): void
    {
        $this->assertArrayNotHasKey('ocr', $this->kirim()->hasil_autoclave);
    }

    public function test_sel_autoclave_kehitung_di_ocr_akurasi_kamera(): void
    {
        $this->kirim([
            'suhu' => [
                // Dibaca benar (setelah substitusi O→0), jadi COCOK.
                'disk' => [[['raw_text' => '121.27']]],
                // Dibaca 181 padahal teknisi mengirim 121, dan mesinnya yakin.
                'indikator' => [['raw_text' => '181', 'confidence' => 0.95]],
            ],
        ]);

        Artisan::call('ocr:akurasi-kamera');
        $keluaran = Artisan::output();

        $this->assertStringContainsString('autoklaf / suhu.disk.0', $keluaran);
        $this->assertStringContainsString(
            'sumbernya `hasil_autoclave`',
            $keluaran,
            'Sel Autoklaf nggak lewat gerbang is_verified — pembagi di baris atas nggak boleh dikira menghitungnya.',
        );

        $this->assertStringContainsString('HIJAU PALSU', $keluaran);
        $this->assertStringContainsString('181', $keluaran);
    }

    public function test_tebakan_dipasangkan_ke_jalur_yang_sama_bukan_ke_deret_lain(): void
    {
        // Repeat 4 disk 2 (nilainya 121.35) — indeks yang sengaja bukan nol,
        // supaya deret yang dirapatkan atau jalur yang tertukar ketahuan.
        $this->kirim([
            'suhu' => ['disk' => [null, null, [null, null, null, ['raw_text' => '121.35', 'confidence' => 0.9]]]],
        ]);

        Artisan::call('ocr:akurasi-kamera');
        $keluaran = Artisan::output();

        $this->assertStringContainsString('autoklaf / suhu.disk.2', $keluaran);
        $this->assertStringContainsString('100.0%', $keluaran);
        $this->assertStringNotContainsString('HIJAU PALSU', $keluaran);
    }
}
