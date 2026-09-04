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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Blok suhu Autoklaf yang dikirim kosong ditolak — bukan dicetak sebagai 0.
 *
 * ## Kenapa berkas ini ada
 *
 * `AutoclaveCalculator::hitungSuhu()` menjaga daftar disk-nya kosong:
 *
 * ```php
 * if ($disk === [] || $indikator === []) {
 *     throw new InvalidArgumentException('Data suhu kosong: ...');
 * }
 * ```
 *
 * Tapi tiap SEL boleh `null` — `AutoclaveStoreRequest` mengizinkannya, dan itu
 * benar: sel kosong di tengah lembar memang wajar. Jadi tiga disk berisi lima
 * `null` **lolos** penjagaan itu. Loopnya `continue` di semua iterasi,
 * `$sensor` tetap `[]`, dan Kestabilan, Keseragaman & Variasi keluar **0**.
 *
 * Nol bukan "tidak ada" — nol itu angka yang sangat bagus. Autoklaf dengan
 * kestabilan 0 °C artinya sempurna, dan angka itu ikut ke sertifikat
 * terakreditasi tanpa satu pun error di jalan. Persis pola yang aturan repo ini
 * larang: *sel kosong yang dibaca nol jangan pernah ditiru; blokir titiknya
 * dengan alasan yang kebaca.*
 *
 * ## Saudara yang sudah benar
 *
 * `pastikanAdaBacaanUut()` di trait yang sama sudah melakukan persis ini untuk
 * blok TEKANAN — *"blok tekanan butuh SATU angka UUT"* — lengkap dengan
 * alasannya: *"ditolak di sini (422) biar teknisi dapat pesan yang jelas, bukan
 * 500 dari kalkulator."* Yang buat suhu tidak pernah ikut ditulis.
 *
 * ## Dua lapis, dan kenapa dua
 *
 * 1. `pastikanAdaBacaanSuhu()` — 422 yang dibaca teknisi.
 * 2. Penjagaan kalkulator — buat pemanggil yang tidak lewat FormRequest,
 *    `HitungUlangSesi` salah satunya.
 *
 * Ditambah pemeriksaan `CalibrationValidator` buat baris LAMA yang terlanjur
 * tersimpan sebelum kedua lapis itu ada.
 */
class BlokSuhuAutoklafKosongDitolakTest extends TestCase
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

    /** @return iterable<string, array{string}> */
    public static function jalur(): iterable
    {
        yield 'simpan' => ['/api/calibrations/autoclave'];
        yield 'preview' => ['/api/calibrations/autoclave/preview'];
    }

    /**
     * INTI bug-nya. Aturannya memang ditulis dua kali — satu di
     * `AutoclaveStoreRequest`, satu di `AutoclaveCalculationRequest` —
     * jadi memperbaiki satu dari dua sama dengan tidak memperbaiki: teknisi
     * melihat pratinjau berisi nol, lalu kirimannya lolos juga.
     */
    #[DataProvider('jalur')]
    public function test_disk_yang_isinya_null_semua_ditolak_422(string $jalur): void
    {
        $payload = $this->payload();
        $payload['suhu']['disk'] = [
            [null, null, null, null, null],
            [null, null, null, null, null],
            [null, null, null, null, null],
        ];

        $this->actingAs($this->teknisi)
            ->postJson($jalur, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['suhu.disk']);
    }

    /** Indikator kosong sama saja — tanpa dia tidak ada acuan buat simpangan. */
    #[DataProvider('jalur')]
    public function test_indikator_yang_kosong_semua_ditolak_422(string $jalur): void
    {
        $payload = $this->payload();
        $payload['suhu']['indikator'] = [null, null, null, null, null];

        $this->actingAs($this->teknisi)
            ->postJson($jalur, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['suhu.disk']);
    }

    /**
     * Lapis kedua: kalkulatornya sendiri menolak, bukan memulangkan nol.
     *
     * Sengaja menembus lapisan validasi — `HitungUlangSesi` memang memanggilnya
     * langsung, dan kalau lapis ini hilang, hitung-ulang sesi lama bisa menulis
     * nol ke tempat yang sebelumnya kosong.
     */
    public function test_kalkulator_nolak_blok_suhu_yang_semua_selnya_null(): void
    {
        $payload = $this->payload();
        $payload['suhu']['disk'] = [
            [null, null, null, null, null],
            [null, null, null, null, null],
        ];

        $input = app(AutoclaveInputBuilder::class)->dari($payload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data suhu kosong');

        app(AutoclaveCalculator::class)->hitung($input);
    }

    /**
     * Bukti dengan ANGKA bahwa yang dicegah memang nol karangan — bukan sekadar
     * "ditolak".
     *
     * Sebelum diperbaiki, jalur ini memulangkan blok suhu dengan Kestabilan,
     * Keseragaman & Variasi = 0. Test di atas membuktikan sekarang dia melempar;
     * yang ini membuktikan jalur yang BENAR tetap menghasilkan angka bukan-nol,
     * jadi "0" tadi memang karangan dan bukan nilai yang wajar.
     */
    public function test_blok_suhu_yang_beneran_keisi_ngasih_angka_bukan_nol(): void
    {
        $input = app(AutoclaveInputBuilder::class)->dari($this->payload());
        $hasil = app(AutoclaveCalculator::class)->hitung($input);

        $this->assertNotEmpty($hasil['suhu']['sensor']);
        $this->assertGreaterThan(0.0, $hasil['suhu']['kestabilan']);
        $this->assertGreaterThan(0.0, $hasil['suhu']['keseragaman']);
    }

    /**
     * JANGAN kebablasan #1: sesi TEKANAN-SAJA tetap sah.
     *
     * Itu skenario nyata yang sudah dijanjikan handoff frontend, dan
     * `AutoclaveCalculator::hitung()` menuliskannya eksplisit. Kalau test ini
     * merah, perbaikannya memblokir jalur yang selama ini benar.
     */
    public function test_sesi_tekanan_saja_tetap_diterima(): void
    {
        $payload = $this->payload();
        unset($payload['suhu']);

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/autoclave', $payload)
            ->assertSuccessful();
    }

    /**
     * JANGAN kebablasan #2: sel kosong DI TENGAH lembar tetap sah.
     *
     * Ini yang paling gampang dirusak penjagaan yang terlalu rakus. Teknisi
     * memang tidak selalu mengisi kelima titik waktu, dan memaksa lembar penuh
     * berarti dia mengarang angka biar lolos — persis kebalikan dari yang
     * dijaga.
     */
    public function test_sel_kosong_sebagian_tetap_diterima(): void
    {
        $payload = $this->payload();
        $payload['suhu']['disk'] = [
            [121.27, null, 121.26, null, 121.28],
            [121.30, 121.26, null, null, null],
            [null, null, 121.28, 121.35, null],
        ];
        $payload['suhu']['indikator'] = [121, null, 121, null, 121];

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/autoclave', $payload)
            ->assertSuccessful();
    }

    /** JANGAN kebablasan #3: lembar lengkap tentu tetap diterima. */
    public function test_lembar_lengkap_tetap_diterima(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/autoclave', $this->payload())
            ->assertSuccessful();
    }
}
