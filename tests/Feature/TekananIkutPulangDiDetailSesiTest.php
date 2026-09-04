<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kondisi lingkungan yang DISIMPAN harus ikut pulang lewat `CalibrationResource`.
 *
 * ## Kenapa berkas ini ada
 *
 * Tekanan udara masuk 20 Agt 2026 sebagai parameter kondisi lingkungan KETIGA,
 * sejajar suhu & kelembaban (sensor elektrokimia Gas Detector membaca tekanan
 * parsial, jadi konsentrasinya bergerak mengikuti tekanan ruangan). Kolomnya
 * dibuat, model menyimpannya, kalkulator memakainya — tapi `CalibrationResource`
 * tidak pernah ikut ditambah. `grep -c tekanan` di berkas itu memulangkan NOL.
 *
 * Yang rusak karenanya, dan tidak menimbulkan error:
 *
 * **`tekanan_awal`/`tekanan_akhir` — ada korban nyata hari ini.** Mobile
 * mengisi ulang lembar yang dibuka lagi lewat `isiAngka('tekanan_awal', …)`
 * (`lembar_kerja_state.dart:1812`), sejajar `suhu_awal`/`kelembaban_awal` di
 * baris atasnya. Karena kuncinya tidak pernah dikirim, sesi Gas Detector yang
 * di-reject admin lalu dibuka lagi mendarat dengan kolom Tekanan KOSONG
 * sementara Suhu & Kelembaban di tabel yang sama terisi normal. Teknisi
 * mengira angkanya hilang dan mengetik ulang — kalau ketikan ulangnya beda,
 * isi database dan isi layar mulai berbeda diam-diam.
 *
 * **`tekanan_udara`/`tekanan_ketidakpastian` — belum ada yang membacanya.**
 * Sama seperti `suhu_ketidakpastian` & `kelembaban_ketidakpastian`: dikirim
 * Resource, nol konsumen di mobile hari ini. Yang diperbaiki bukan layar yang
 * kosong, tapi kelompok field yang cuma terkirim separuh — lembar Gas Detector
 * mendeklarasikan TIGA U95 bersaudara dan Resource mengirim dua. Selama
 * timpangnya dibiarkan, yang menyalin blok ini berikutnya menyalin timpangnya.
 *
 * Sertifikatnya sendiri selalu benar — kalkulator membaca langsung dari
 * database, bukan dari Resource. Itu justru yang bikin cacat ini sunyi.
 */
class TekananIkutPulangDiDetailSesiTest extends TestCase
{
    use RefreshDatabase;

    /** Sesi Gas Detector dari seeder; angkanya dijaga `GasDetectorSesiTest`. */
    private function sesiGasDetector(): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', '2602.03.A')->firstOrFail();
    }

    private function adminSeorganisasi(CalibrationSession $sesi): User
    {
        return User::factory()->admin()->create([
            'organization_id' => $sesi->organization_id,
        ]);
    }

    /**
     * Empat kolom tekanan pulang dengan nilai yang sama persis dengan yang
     * tersimpan — bukan sekadar "key-nya ada".
     */
    public function test_keempat_kolom_tekanan_pulang_dengan_nilai_yang_tersimpan(): void
    {
        $sesi = $this->sesiGasDetector();

        // Prasyarat: seedernya memang mengisi tekanan. Kalau ini yang merah,
        // yang salah seedernya, bukan Resource-nya.
        $this->assertNotNull($sesi->tekanan_awal, 'Sesi contoh Gas Detector nggak punya tekanan — test ini jadi nggak nguji apa-apa.');

        $data = $this->actingAs($this->adminSeorganisasi($sesi))
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data');

        foreach (['tekanan_awal', 'tekanan_akhir', 'tekanan_udara', 'tekanan_ketidakpastian'] as $kolom) {
            $this->assertArrayHasKey(
                $kolom,
                $data,
                "`{$kolom}` tersimpan di `calibration_sessions` tapi nggak pernah dikirim balik "
                .'`CalibrationResource`. Sesi yang dibuka ulang kehilangan angka yang sudah diketik teknisi.',
            );
            $this->assertEqualsWithDelta(
                (float) $sesi->{$kolom},
                (float) $data[$kolom],
                1e-6,
                "`{$kolom}` pulang dengan nilai yang beda dari yang tersimpan.",
            );
        }
    }

    /**
     * Ketiga U95 kondisi lingkungan yang dideklarasikan lembar kerja Gas
     * Detector harus pulang semua — bukan dua dari tiga.
     *
     * Daftarnya diambil dari PROFILNYA, bukan ditulis tangan di sini: parameter
     * kondisi keempat yang ditambahkan nanti ikut terjaga tanpa berkas ini
     * disentuh. Itu persis cara tekanan lolos waktu dia yang ketiga.
     */
    public function test_semua_u95_kondisi_yang_dideklarasikan_lembar_kerja_ikut_pulang(): void
    {
        $sesi = $this->sesiGasDetector();

        $profil = app(CalibrationProfileRegistry::class)->untukKode('gas_detector');
        $this->assertNotNull($profil, 'Profil gas_detector nggak ketemu di registry.');

        $kodeU95 = [];
        foreach ($profil->bentukLembarKerja(untukAdmin: true)['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                if (str_ends_with((string) ($field['kode'] ?? ''), '_ketidakpastian')) {
                    $kodeU95[] = $field['kode'];
                }
            }
        }

        // Penjaga lantai: kalau lembarnya berhenti mendeklarasikan U95, sapuan
        // ini diam-diam berhenti memeriksa apa pun dan tetap menulis "OK".
        $this->assertGreaterThanOrEqual(
            3,
            count($kodeU95),
            'Lembar Gas Detector mendeklarasikan kurang dari 3 field U95 kondisi — sapuan ini jadi kosong.',
        );

        $data = $this->actingAs($this->adminSeorganisasi($sesi))
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data');

        foreach ($kodeU95 as $kode) {
            $this->assertArrayHasKey(
                $kode,
                $data,
                "Lembar kerja mendeklarasikan field `{$kode}` buat admin, tapi `CalibrationResource` "
                .'nggak pernah mengirimnya. Kelompok yang terkirim separuh itu yang disalin '
                .'pemakai berikutnya — dan nggak ada satu pun error yang menandainya.',
            );
        }
    }

    /**
     * Sesi sembilan alat lain (tanpa pembacaan tekanan) tetap pulang dengan
     * `null`, bukan 0 dan bukan hilang.
     *
     * Nol bukan "tidak diukur" — 0 hPa itu ruang hampa. Kalau kolomnya
     * mendarat sebagai 0, layar akan menampilkan angka yang tidak pernah
     * dibaca siapa pun sebagai kondisi lingkungan yang sah.
     */
    public function test_sesi_tanpa_tekanan_pulang_null_bukan_nol(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::whereNull('tekanan_awal')->firstOrFail();

        $data = $this->actingAs($this->adminSeorganisasi($sesi))
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data');

        foreach (['tekanan_awal', 'tekanan_akhir', 'tekanan_udara', 'tekanan_ketidakpastian'] as $kolom) {
            $this->assertArrayHasKey($kolom, $data);
            $this->assertNull(
                $data[$kolom],
                "`{$kolom}` mendarat sebagai ".var_export($data[$kolom], true)
                .' buat sesi yang nggak mengukur tekanan. Harus null — 0 hPa itu ruang hampa, bukan "nggak diukur".',
            );
        }
    }
}
