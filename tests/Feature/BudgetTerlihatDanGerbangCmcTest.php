<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\CalibrationValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Budget ketidakpastian kelompok Waktu dan Frekuensi harus TERBACA di API, dan
 * gerbang `u95_meledak_dari_cmc` harus HIDUP buat ketiga alatnya.
 *
 * ## Dua cacat yang ditutup berkas ini, dan keduanya lahir dari satu sebab
 *
 * `PutaranCalculator` & `WaktuCalculator` menyimpan komponen budget sebagai
 * `u`/`ci`/`vi`, dan profilnya menaruh bentuk itu apa adanya di
 * `type_b_components`. Tapi jalur per-titik (`GumCalculator`) memakai bentuk
 * lain — `nilai`/`u_baku`/`ci`/`vi` — dan seluruh sistem membaca yang kedua.
 *
 *  1. **Budget-nya tak terbaca.** `CalibrationResource::petakanTitik()` memetakan
 *     `'nilai' => $k['nilai'] ?? null`, jadi SETIAP komponen pulang
 *     `{"sumber": "resolusi_standar", "nilai": null}`. Nol error; yang hilang
 *     seluruh rincian U95 dari layar teknisi dan admin — satu-satunya tempat
 *     angkanya bisa ditelusuri sebelum disetujui.
 *
 *  2. **Gerbang ERROR-nya mati.** `CalibrationValidator::cmcTitik()` mencari
 *     CMC titik ini di `type_b_components` lewat `sumber`
 *     `perbandingan_cmc`/`lantai_cmc`/`cmc`. Ketiga alat baru tidak pernah
 *     menerbitkan satu pun dari ketiganya, jadi `cmcTitik()` selalu `null` dan
 *     `u95_meledak_dari_cmc` tidak pernah bisa berbunyi.
 *
 * Yang kedua bukan teori. Diukur sebelum perbaikan: sesi Tachometer dengan satu
 * pembacaan `60` diketik `6000` terbit ber-U95 **3298,42 rpm lawan CMC 1,5 rpm
 * — 2199x lipat**, dan lolos tanpa satu pun temuan. Gerbang itu justru lahir
 * dari kejadian `CAL/2026/08/0043` yang "cuma" 212x.
 */
class BudgetTerlihatDanGerbangCmcTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function sesiKelompok(): array
    {
        return [
            'Tachometer' => ['0140-CAL-424'],
            'Centrifuge' => ['0133-CAL-324'],
            'Timer/Stopwatch' => ['015-CAL-424'],
        ];
    }

    /**
     * Tiap komponen budget pulang ber-`nilai` yang beneran angka — kecuali baris
     * `jejak_titik`, yang memang catatan teks tanpa sumbangan ke `uc`.
     */
    #[DataProvider('sesiKelompok')]
    public function test_komponen_budget_punya_nilai(string $nomorSesi): void
    {
        $this->seed(DatabaseSeeder::class);

        $titik = $this->titikApi($nomorSesi);
        $komponen = $titik[0]['type_b_components'] ?? [];

        $this->assertNotEmpty($komponen, "Sesi {$nomorSesi} nggak punya komponen budget sama sekali.");

        foreach ($komponen as $k) {
            if (($k['sumber'] ?? null) === 'jejak_titik') {
                continue;
            }

            $this->assertNotNull(
                $k['nilai'] ?? null,
                sprintf(
                    'Sesi %s komponen `%s` pulang ber-`nilai` null — budget-nya nggak kebaca di layar. '
                    .'Kalkulatornya nyimpen `u`, API-nya baca `nilai`.',
                    $nomorSesi, $k['sumber'] ?? '?',
                ),
            );
        }
    }

    /** Dan salah satunya WAJIB pembanding CMC — itu yang menghidupkan gerbangnya. */
    #[DataProvider('sesiKelompok')]
    public function test_ada_baris_pembanding_cmc(string $nomorSesi): void
    {
        $this->seed(DatabaseSeeder::class);

        $sumber = array_column($this->titikApi($nomorSesi)[0]['type_b_components'] ?? [], 'sumber');

        $this->assertContains(
            'perbandingan_cmc', $sumber,
            sprintf(
                'Sesi %s nggak punya baris `perbandingan_cmc`. Tanpa dia `CalibrationValidator::cmcTitik()` '
                .'pulang null dan gerbang ERROR `u95_meledak_dari_cmc` mati buat alat ini. Yang ada: %s',
                $nomorSesi, implode(', ', $sumber),
            ),
        );
    }

    /**
     * Satu digit salah ketik di lembar Tachometer HARUS menahan penerbitan.
     *
     * `60` diketik `6000` — persis bentuk kesalahan `CAL/2026/08/0043`, dan
     * satu-satunya penjagaan yang bisa menangkapnya. Pembacaan 6000 rpm sendiri
     * lolos semua penjagaan lain: dia di dalam rentang alat (0–100000 rpm),
     * kelipatan resolusi standar, dan alat ini memang tidak divonis PASS/FAIL.
     */
    public function test_satu_digit_salah_ketik_menahan_penerbitan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = $this->simpanSesiTachometer([
            ['titik_ukur' => 60, 'pembacaan' => [6000, 59.9, 60.0, 60.1, 60.0]],
            ['titik_ukur' => 80, 'pembacaan' => [79.9, 80.1, 80.0, 80.0, 80.1]],
            ['titik_ukur' => 100, 'pembacaan' => [99.9, 100.1, 100.0, 100.0, 100.1]],
        ]);

        $hasil = app(CalibrationValidator::class)->periksa($sesi);
        $kode = array_column($hasil['temuan'], 'kode');

        $this->assertContains(
            'u95_meledak_dari_cmc', $kode,
            'Salah ketik yang bikin U95 ribuan kali CMC lolos tanpa temuan. Yang ada: '
            .implode(', ', array_unique($kode)),
        );
        $this->assertFalse($hasil['boleh_terbit'], 'U95 meledak mestinya nahan penerbitan.');
    }

    /** Dan sesi NORMAL tidak ikut ke-flag — ambangnya longgar sengaja. */
    public function test_sesi_normal_tidak_ke_flag(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = $this->simpanSesiTachometer([
            ['titik_ukur' => 60, 'pembacaan' => [59.9, 60.1, 60.0, 60.0, 60.1]],
            ['titik_ukur' => 80, 'pembacaan' => [79.9, 80.1, 80.0, 80.0, 80.1]],
            ['titik_ukur' => 100, 'pembacaan' => [99.9, 100.1, 100.0, 100.0, 100.1]],
        ]);

        $this->assertNotContains(
            'u95_meledak_dari_cmc',
            array_column(app(CalibrationValidator::class)->periksa($sesi)['temuan'], 'kode'),
            'Sesi normal ke-flag U95 meledak — ambangnya kekencangan.',
        );
    }

    /**
     * Titik hasil `POST /calibrations/preview`, lewat jalur API yang sama dengan
     * yang dibaca aplikasi.
     *
     * @return list<array<string, mixed>>
     */
    private function titikApi(string $nomorSesi): array
    {
        $sesi = CalibrationSession::query()->where('nomor_sesi', $nomorSesi)->firstOrFail();
        $teknisi = User::query()->where('role', User::ROLE_TEKNISI)->firstOrFail();

        $measurements = $nomorSesi === '015-CAL-424'
            ? [['titik_ukur' => 60, 'standar' => [60123, 60211, 60045], 'uut' => [60131, 60219, 60061]]]
            : [
                ['titik_ukur' => 60, 'pembacaan' => [59.9, 60.1, 60.0, 60.0, 60.1]],
                ['titik_ukur' => 80, 'pembacaan' => [79.9, 80.1, 80.0, 80.0, 80.1]],
                ['titik_ukur' => 100, 'pembacaan' => [99.9, 100.1, 100.0, 100.0, 100.1]],
            ];

        return $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', [
                'equipment_id' => $sesi->equipment_id,
                'standard_id' => $sesi->standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'measurements' => $measurements,
            ])
            ->assertOk()
            ->json('data.titik');
    }

    /**
     * @param  list<array<string, mixed>>  $measurements
     */
    private function simpanSesiTachometer(array $measurements): CalibrationSession
    {
        $contoh = CalibrationSession::query()->where('nomor_sesi', '0140-CAL-424')->firstOrFail();
        $teknisi = User::query()->where('role', User::ROLE_TEKNISI)->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $contoh->equipment_id,
                'standard_id' => $contoh->standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'measurements' => $measurements,
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::with([
            'equipment', 'teknisi', 'standard', 'rawMeasurements', 'uncertaintyCalculations',
        ])->findOrFail($id);
    }
}
