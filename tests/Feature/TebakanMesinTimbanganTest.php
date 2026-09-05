<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tebakan mesin lembar TIMBANGAN selamat sampai bisa diukur.
 *
 * ## Kenapa lembar ini paling gampang hilang diam-diam
 *
 * Dua hal menumpuk di sini, dan dua-duanya nggak menghasilkan error:
 *
 *  1. **Kameranya nggak mendarat di `raw_measurements`.** Yang difoto blok
 *     Repeatability, dan blok itu besaran tingkat-SESI — tersimpan sebagai JSON
 *     di `spesifikasi_alat`. Kolom `ocr_raw_text` yang menampung tebakan dua
 *     puluh alat lain nggak pernah kesentuh.
 *  2. **Bentuknya berubah di tengah jalan.** HP mengirim cerminan tabelnya
 *     (`keterulangan.baris[]` berkolom `zero`/`pembacaan`), lalu
 *     `CalibrationRequest::bakukanKeterulanganTimbangan()` menerjemahkannya jadi
 *     `{mid, maks}` berkunci `zi`/`mi` SEBELUM disimpan — dan penerjemah itu
 *     membuang kunci yang nggak dikenalnya.
 *
 * Jadi tebakannya bisa mendarat di HP, terkirim, lalu lenyap tepat di
 * penerjemah — dan `ocr:akurasi-kamera` melaporkan nol sel Timbangan, yang
 * kebaca sebagai "kameranya bagus" padahal artinya nol data.
 */
class TebakanMesinTimbanganTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Equipment, 1: User} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'TB-100')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * @param  array<string, mixed>  $keterulangan
     */
    private function kirim(Equipment $alat, User $teknisi, array $keterulangan): CalibrationSession
    {
        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'ocr',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 26.1, 'suhu_akhir' => 26.0,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 52,
            'measurements' => [
                ['titik_ukur' => 10, 'nominal' => [10.0], 'z1' => 0, 'm' => 10, 'm_aksen' => 10, 'z2' => 0],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '100', 'kapasitas' => '100', 'resolusi' => '0.02',
                'varian_master' => 'kg', 'tipe_display' => 'Digital',
                'tipe_timbangan' => 'Non-Analytical', 'satuan' => 'kg',
                'keterulangan' => $keterulangan,
            ],
        ])->assertCreated()->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /**
     * Bentuk TABEL yang beneran dikirim HP — `baris[]` berkolom `zero`/
     * `pembacaan`, plus tebakan mesinnya sejajar indeks.
     *
     * @param  list<array<string, mixed>|null>  $ocrMi
     * @return array<string, mixed>
     */
    private function baris(array $ocrMi): array
    {
        return [
            'baris' => [
                [
                    'titik_ukur' => 50,
                    'zero' => array_fill(0, 10, 0),
                    'pembacaan' => array_fill(0, 10, 50.02),
                    'pembacaan_ocr' => $ocrMi,
                ],
                [
                    'titik_ukur' => 100,
                    'zero' => array_fill(0, 10, 0),
                    'pembacaan' => array_fill(0, 10, 100.02),
                ],
            ],
        ];
    }

    public function test_tebakan_selamat_lewat_penerjemah_bentuk(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $sesi = $this->kirim($alat, $teknisi, $this->baris([
            ['raw_text' => '5O.O2', 'confidence' => 0.9],
            ...array_fill(0, 9, null),
        ]));

        $ket = $sesi->spesifikasi_alat['keterulangan'];

        // Angka ukurnya tetap dalam bentuk baku yang dibaca kalkulator.
        $this->assertSame(50, $ket['mid']['nominal']);
        $this->assertCount(10, $ket['mid']['mi']);

        // Tebakannya ikut, dengan nama yang sejalan (`mi` → `mi_ocr`).
        $this->assertSame('5O.O2', $ket['mid']['mi_ocr'][0]['raw_text']);
        $this->assertNull($ket['mid']['mi_ocr'][1]);
    }

    public function test_blok_tanpa_tebakan_nggak_menitip_kunci_kosong(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $sesi = $this->kirim($alat, $teknisi, $this->baris(array_fill(0, 10, null)));
        $ket = $sesi->spesifikasi_alat['keterulangan'];

        $this->assertArrayNotHasKey('mi_ocr', $ket['mid']);
        $this->assertArrayNotHasKey('zi_ocr', $ket['mid']);
        $this->assertArrayNotHasKey('mi_ocr', $ket['maks'], 'Baris kedua memang nggak difoto.');
    }

    public function test_sel_timbangan_kehitung_di_ocr_akurasi_kamera(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $this->kirim($alat, $teknisi, $this->baris([
            // Dibaca benar sesudah substitusi O→0.
            ['raw_text' => '5O.O2', 'confidence' => 0.9],
            // Dibaca 58.02 padahal teknisi mengirim 50.02, dan mesinnya yakin.
            ['raw_text' => '58.02', 'confidence' => 0.97],
            ...array_fill(0, 8, null),
        ]));

        Artisan::call('ocr:akurasi-kamera');
        $keluaran = Artisan::output();

        $this->assertStringContainsString('sumbernya `spesifikasi_alat`', $keluaran);
        $this->assertStringContainsString('keterulangan.mid.mi', $keluaran);

        $this->assertStringContainsString('HIJAU PALSU', $keluaran);
        $this->assertStringContainsString('58.02', $keluaran);
    }
}
