<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\AutoclaveCalculator;
use App\Services\Calibration\AutoclaveInputBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `resolusi_alat = 0` di jalur Autoklaf ditolak di batas masuk.
 *
 * ## Kenapa berkas ini ada
 *
 * Empat aturan dengan pola yang sama persis dengan BUG-002 (`min:0` yang
 * seharusnya `gt:0`), tapi **akibatnya berbeda** — dan yang ini lebih mahal.
 *
 * Nilainya mengalir lewat `AutoclaveInputBuilder` ke `EnclosureCalculator`, dan
 * di sana dia jadi salah satu komponen budget ketidakpastian:
 *
 *     'u' => ($resolusiAlat / 2.0) / $sqrt3,     // EnclosureCalculator:566
 *
 * Nol bikin `u` komponen itu nol — komponen daya-baca alat **hilang** dari
 * budget, dan U95 yang tercetak jadi LEBIH KECIL dari yang seharusnya.
 * Sertifikat yang mengklaim ketidakpastian lebih baik daripada yang alatnya
 * sanggup. Beda dari BUG-002 yang cuma mengarang desimal; yang ini mengubah
 * angka ketidakpastiannya sendiri.
 *
 * ## Kenapa nol pasti salah, bukan cuma mencurigakan
 *
 * `config/autoclave.php` menyimpan cadangan yang diambil dari master
 * (`INPUT DATA E16 / H16`): suhu 0,01 °C dan tekanan 0,001. Dua-duanya
 * positif, dan komentarnya menyebut cadangan itu dipakai "kalau teknisi nggak
 * ngisi" — kasus `null`, yang ditangani `??` di `AutoclaveInputBuilder`.
 *
 * Jadi nol eksplisit bukan "belum diisi" (itu `null`) dan bukan resolusi nyata
 * (master tidak punya nol). Dia cuma satu hal: angka yang menghapus komponen
 * dari budget.
 *
 * ## Yang TIDAK dijawab berkas ini
 *
 * Apakah ada sesi tersimpan yang U95-nya terlanjur dihitung tanpa komponen itu.
 * Perbaikan validasi menutup pintu buat sesi BARU dan tidak menyentuh satu pun
 * dokumen yang sudah terbit; memeriksa yang lama butuh query ke database
 * produksi — ditulis di temuan BUG-024.
 */
class ResolusiAlatAutoklafNolDitolakTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $org->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     * @return array<string, mixed>
     */
    private function payload(array $tambahan = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.4,
            'suhu_akhir' => 24.5,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 56,
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
            ...$tambahan,
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function jalurDanBlok(): iterable
    {
        foreach (['/api/calibrations/autoclave', '/api/calibrations/autoclave/preview'] as $jalur) {
            foreach (['suhu', 'tekanan'] as $blok) {
                yield "{$jalur} · {$blok}" => [$jalur, $blok];
            }
        }
    }

    /**
     * Keempat kombinasi jalur × blok, karena aturannya memang ditulis empat
     * kali: dua di `AutoclaveStoreRequest`, dua di `AutoclaveCalculationRequest`.
     * Memperbaiki tiga dari empat sama dengan tidak memperbaiki.
     */
    #[DataProvider('jalurDanBlok')]
    public function test_resolusi_alat_nol_ditolak_422(string $jalur, string $blok): void
    {
        $payload = $this->payload();
        $payload[$blok]['resolusi_alat'] = 0;

        $this->actingAs($this->teknisi)
            ->postJson($jalur, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                "{$blok}.resolusi_alat" => 'Resolusi alat harus lebih besar dari nol.',
            ]);
    }

    /**
     * JANGAN kebablasan: tidak mengirim `resolusi_alat` itu jalur NORMAL —
     * cadangan dari `config/autoclave.php` yang dipakai. Kalau test ini merah,
     * perbaikannya memblokir sesi yang selama ini sah.
     */
    public function test_tanpa_resolusi_alat_tetap_jalan_pakai_cadangan_config(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/autoclave/preview', $this->payload())
            ->assertOk();
    }

    public function test_resolusi_alat_wajar_tetap_diterima(): void
    {
        $payload = $this->payload();
        $payload['suhu']['resolusi_alat'] = 0.01;
        $payload['tekanan']['resolusi_alat'] = 0.001;

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/autoclave/preview', $payload)
            ->assertOk();
    }

    /**
     * ALASANNYA, dibuktikan dengan angka.
     *
     * Dihitung LANGSUNG lewat `AutoclaveInputBuilder` + `AutoclaveCalculator`,
     * menembus lapisan validasi — supaya akibat dari nol tetap bisa ditunjukkan
     * sesudah pintunya ditutup. Tanpa test ini, `gt:0` cuma aturan tanpa alasan
     * yang tercatat, dan aturan begitu yang dilonggarkan lagi nanti.
     */
    public function test_nol_bikin_u95_lebih_kecil_dari_resolusi_master(): void
    {
        $perakit = app(AutoclaveInputBuilder::class);
        $kalkulator = app(AutoclaveCalculator::class);

        $hitung = static function (float $resolusi) use ($perakit, $kalkulator): float {
            $payload = [
                'set_point' => 121.0,
                'suhu' => [
                    'disk' => [
                        [121.27, 121.26, 121.26, 121.26, 121.28],
                        [121.30, 121.26, 121.26, 121.25, 121.25],
                        [121.26, 121.26, 121.28, 121.35, 121.28],
                    ],
                    'indikator' => [121, 121, 121, 121, 121],
                    'suhu_ruang' => [25, 25, 25, 25, 25],
                    'resolusi_alat' => $resolusi,
                ],
            ];

            return (float) $kalkulator->hitung($perakit->dari($payload))['suhu']['u95'];
        };

        $dariMaster = $hitung((float) config('autoclave.resolusi_alat.suhu'));
        $dariNol = $hitung(0.0);

        $this->assertGreaterThan(
            0.0,
            (float) config('autoclave.resolusi_alat.suhu'),
            'Cadangan resolusi di config sendiri nol — kalau ini merah, nol bisa masuk lewat pintu belakang.',
        );

        $this->assertLessThan(
            $dariMaster,
            $dariNol,
            'resolusi_alat = 0 TIDAK memperkecil U95 — dugaan di BUG-024 keliru dan '
            .'penjelasan di berkas ini perlu ditulis ulang, bukan dibiarkan.',
        );
    }
}
