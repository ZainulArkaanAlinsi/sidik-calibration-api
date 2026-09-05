<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `ocr:akurasi-kamera` — akurasi jalur foto tabel, dihitung dari pasangan
 * (tebakan mesin, angka yang dikirim teknisi).
 *
 * ## Kenapa berkas ini ada
 *
 * Sebelum HP mengirim `measurements[].ocr[]`, tebakan mesin tertimpa waktu
 * teknisi mengetik ulang selnya — jadi akurasi jalur kamera tidak bisa dihitung
 * sama sekali. Yang dijaga di sini bukan cuma perintahnya jalan, tapi tiga hal
 * yang kalau salah membuat angkanya BOHONG dan tetap kelihatan wajar:
 *
 *  1. baris manual tidak boleh ikut dihitung — dia bikin akurasi naik sendiri;
 *  2. "50,02" dan "50.02" itu bacaan yang sama benarnya;
 *  3. pembacaan tanpa skor tidak boleh dihitung hijau — nol hijau palsu di situ
 *     artinya "belum terukur", bukan "tidak ada yang salah".
 */
class AkurasiKameraTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    /**
     * @param  list<float>  $pembacaan
     * @param  list<array<string, mixed>|null>  $ocr
     */
    private function kirim(array $pembacaan, array $ocr, string $metode = 'ocr'): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => $metode,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [[
                'titik_ukur' => 50.0,
                'satuan' => 'mm',
                'pembacaan' => $pembacaan,
                'ocr' => $ocr,
            ]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    private function jalankan(array $opsi = []): string
    {
        Artisan::call('ocr:akurasi-kamera', $opsi);

        return Artisan::output();
    }

    public function test_tebakan_mesin_tersimpan_dan_barisnya_lahir_belum_terverifikasi(): void
    {
        // Angka yang dikirim (50,02) BEDA dari yang dibaca mesin (5O.O2) —
        // persis kejadian yang dulu tidak meninggalkan jejak: teknisi
        // membetulkannya di kotak yang sama, dan buktinya hilang.
        $sesi = $this->kirim(
            [50.02],
            [['confidence' => 0.97, 'raw_text' => '5O.O2']],
        );

        $baris = $sesi->rawMeasurements()->where('tahap', 'sesudah_adjustment')->firstOrFail();

        $this->assertSame('5O.O2', $baris->ocr_raw_text);
        $this->assertSame(0.97, (float) $baris->ocr_confidence);
        $this->assertSame('ocr', $baris->input_source);
        $this->assertFalse(
            $baris->is_verified,
            'Angka dari kamera wajib nunggu mata teknisi sebelum sesinya bisa disetujui.',
        );
    }

    public function test_hijau_palsu_ketangkep_waktu_mesin_yakin_tapi_salah(): void
    {
        // `5O.O2` disubstitusi jadi 50.02 oleh NormalisasiAngka, jadi yang ini
        // COCOK. Yang kedua beneran salah baca, dan skornya di atas ambang
        // hijau — inilah bentuk kegagalan yang tidak ada yang lihat sampai
        // sertifikatnya terbit.
        $this->kirim(
            [50.02, 50.01],
            [
                ['confidence' => 0.97, 'raw_text' => '5O.O2'],
                ['confidence' => 0.99, 'raw_text' => '58.01'],
            ],
        );

        $keluaran = $this->jalankan();

        $this->assertStringContainsString('HIJAU PALSU', $keluaran);
        $this->assertStringContainsString('58.01', $keluaran);
        $this->assertStringNotContainsString(
            '5O.O2',
            $keluaran,
            'Yang kebaca benar nggak boleh nongol di daftar hijau palsu.',
        );
    }

    public function test_koma_dan_titik_dihitung_sebagai_bacaan_yang_sama(): void
    {
        $this->kirim([50.02], [['confidence' => 0.9, 'raw_text' => '50,02']]);

        $keluaran = $this->jalankan();

        $this->assertStringContainsString('100.0%', $keluaran);
        $this->assertStringNotContainsString('HIJAU PALSU', $keluaran);
    }

    public function test_baris_manual_nggak_ikut_dihitung(): void
    {
        // Dua pembacaan, cuma satu yang dari kamera. Kalau baris manual ikut
        // terhitung sebagai "cocok", akurasinya naik sendiri tiap teknisi
        // ngetik tangan — angka yang makin bagus justru waktu kameranya makin
        // jarang dipakai.
        $this->kirim(
            [50.02, 50.01],
            [null, ['confidence' => 0.99, 'raw_text' => '58.01']],
            metode: 'manual',
        );

        $keluaran = $this->jalankan();

        $this->assertMatchesRegularExpression(
            '/Pembacaan hasil kamera 30 hari terakhir: 1\b/',
            $keluaran,
            'Yang dihitung cuma baris yang mesinnya beneran menebak sesuatu.',
        );
    }

    public function test_pembacaan_tanpa_skor_nggak_dihitung_hijau(): void
    {
        // ML Kit cuma menyetel `confidence` di sebagian versi & perangkat.
        // Bacaan yang salah tanpa skor TIDAK boleh dilaporkan sebagai nol hijau
        // palsu tanpa penjelasan — nol di situ artinya belum terukur.
        $this->kirim([50.02], [['raw_text' => '58.01']]);

        $keluaran = $this->jalankan();

        $this->assertStringNotContainsString('HIJAU PALSU', $keluaran);
        $this->assertStringContainsString('belum terukur, bukan nol', $keluaran);
        $this->assertStringContainsString('skor keyakinan', $keluaran);
    }

    public function test_kategori_yang_nggak_cocok_nggak_ngasih_angka_karangan(): void
    {
        $this->kirim([50.02], [['confidence' => 0.9, 'raw_text' => '58.01']]);

        $keluaran = $this->jalankan(['--kategori' => 'suhu']);

        $this->assertStringContainsString('nggak ada yang bisa diukur', $keluaran);
        $this->assertStringNotContainsString('HIJAU PALSU', $keluaran);
    }
}
