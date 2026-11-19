<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\SiapHitungSesi;
use App\Support\TimbanganMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2-13 (QA ronde 2): jalur hitung ulang (`kalibrasi:hitung-ulang` dan simulasi
 * versi data acuan, keduanya lewat `SiapHitungSesi`) tidak boleh menjawab BEDA
 * dari jalur simpan/preview untuk titik Timbangan yang sama.
 *
 * ## Akar
 *
 * `SiapHitungSesi` menuntut KEEMPAT pembacaan (z1, m, m', z2) untuk semua varian
 * Timbangan. Master kg & gram hanya punya TIGA baris per titik akurasi (z, m, m');
 * baris z' (z2) cuma ada di master substitusi. Jalur simpan (`TimbanganCalculator::
 * titikAkurasi`) menerima titik selama ada SATU pembacaan nol dan SATU pembacaan
 * berbeban. Akibatnya sesi gram/kg dengan z2 kosong dihitung, bisa terbit, tetapi
 * hitung ulangnya "dilewat" — dan simulasi versi acuan diam-diam melewatkan sesinya.
 *
 * ## Bukti kesetaraan dari master (tanpa mengubah satu angka pun)
 *
 * Master gram `PERHITUNGAN FC`: titik akurasi = tiga baris `z_k, m_k, m_k'`
 * (K52:K54, K55:K57, K58:K60 ...), kolom Average N memakai `z` tunggal. Koreksi:
 * R52 = 3,2000000000032e-05, R55 = 0,00012199999999928934, R58 = 4,000000000559112e-06
 * — sama dengan `database/data/sesi-master-timbangan.json` `gram._harap.koreksi[0..2]`
 * yang dihitung ulang di sini TANPA baris z2. Master kg: K50:K52, K53:K55, ... juga
 * tiga baris. Master substitusi: empat baris (z, m, m', z'), dan Average P66 = 801,95
 * = rata-rata M66 (801,9) dan M69 (802).
 */
class HitungUlangTimbanganTanpaZ2Test extends TestCase
{
    use RefreshDatabase;

    private const NOMOR_GRAM = '019-CAL-425';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function sesiGram(): CalibrationSession
    {
        return CalibrationSession::with(['equipment', 'rawMeasurements.standard', 'uncertaintyCalculations'])
            ->where('nomor_sesi', self::NOMOR_GRAM)
            ->firstOrFail();
    }

    /** Teknisi gram yang mengirim TIGA pembacaan per titik: baris z2 tidak pernah ada. */
    private function buangZ2(CalibrationSession $sesi): CalibrationSession
    {
        $sesi->rawMeasurements()->where('peran_sensor', 'z2')->delete();

        return $this->sesiGram();
    }

    /** @return array<int, array<string, mixed>> hasil hitung ulang per `titik_ke` */
    private function hitungUlang(CalibrationSession $sesi): array
    {
        $siap = SiapHitungSesi::dari($sesi);
        $perGrup = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment)->hitungPerGrup($siap, $sesi->equipment);

        return collect($perGrup['hitungan'] ?? [])->keyBy(fn (array $h): int => (int) $h['titik_ke'])->all();
    }

    public function test_sesi_gram_tanpa_z2_dihitung_ulang_dan_sama_dengan_yang_tersimpan_dan_master(): void
    {
        $sesi = $this->sesiGram();
        $tersimpan = $sesi->uncertaintyCalculations->keyBy(fn ($u): int => (int) $u->titik_ke);
        $this->assertGreaterThanOrEqual(9, $tersimpan->count(), 'Sesi contoh gram kehilangan titik hasil.');

        $sesi = $this->buangZ2($sesi);
        $this->assertSame(0, $sesi->rawMeasurements->where('peran_sensor', 'z2')->count());

        $siap = SiapHitungSesi::dari($sesi);
        $this->assertCount(
            $sesi->rawMeasurements->pluck('titik_ke')->unique()->count(),
            $siap,
            'Hitung ulang melewati titik gram yang hanya punya tiga pembacaan (z, m, m\').',
        );

        $baru = $this->hitungUlang($sesi);
        $this->assertSame($tersimpan->keys()->sort()->values()->all(), collect($baru)->keys()->sort()->values()->all());

        $master = json_decode((string) file_get_contents(database_path('data/sesi-master-timbangan.json')), true);

        foreach ($baru as $ke => $h) {
            $lama = $tersimpan[$ke];
            $this->assertEqualsWithDelta((float) $lama->koreksi, (float) $h['koreksi'], 1e-8, "titik {$ke}: koreksi hitung ulang beda dari yang tersimpan");
            $this->assertEqualsWithDelta((float) $lama->ketidakpastian_diperluas, (float) $h['ketidakpastian_diperluas'], 1e-8, "titik {$ke}: U95 hitung ulang beda dari yang tersimpan");

            // Titik 9 gram = kerusakan master (T6), dibahas di TimbanganSesiContohCocokMasterTest.
            if ($ke !== 9) {
                $this->assertEqualsWithDelta((float) $master['gram']['_harap']['koreksi'][$ke - 1], (float) $h['koreksi'], 5e-6, "titik {$ke}: koreksi beda dari master (PERHITUNGAN FC!R)");
            }
        }
    }

    public function test_perintah_hitung_ulang_tidak_lagi_melewati_sesi_gram_tanpa_z2(): void
    {
        $this->buangZ2($this->sesiGram());

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [self::NOMOR_GRAM], '--dry-run' => true])
            ->doesntExpectOutputToContain('dilewat')
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();
    }

    public function test_sesi_kg_dari_jalur_simpan_tanpa_z2_dan_hitung_ulangnya_menjawab_sama(): void
    {
        $alat = Equipment::where('serial_number', 'TB-100')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 26.1, 'suhu_akhir' => 26.0,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 52,
            // TIGA pembacaan per titik, persis master kg: z2 tidak dikirim.
            'measurements' => [
                ['titik_ukur' => 10, 'nominal' => [10.0], 'z1' => 0, 'm' => 10, 'm_aksen' => 10],
                ['titik_ukur' => 20, 'nominal' => [20.0], 'z1' => 0, 'm' => 20.02, 'm_aksen' => 20.02],
                ['titik_ukur' => 30, 'nominal' => [20.0, 10.0], 'z1' => 0, 'm' => 30, 'm_aksen' => 30],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '100', 'kapasitas' => '100', 'resolusi' => '0.02',
                'varian_master' => 'kg', 'tipe_display' => 'Digital',
                'tipe_timbangan' => 'Non-Analytical', 'satuan' => 'kg',
                'keterulangan' => [
                    'mid' => ['nominal' => 50, 'zi' => array_fill(0, 10, 0), 'mi' => array_fill(0, 10, 50.02)],
                    'maks' => ['nominal' => 100, 'zi' => array_fill(0, 10, 0), 'mi' => array_fill(0, 10, 100.02)],
                ],
                'eksentrisitas' => ['beban' => 20, 'baca' => [
                    'center' => 20, 'front' => 20, 'back' => 20.02, 'left' => 20, 'right' => 20,
                ]],
            ],
        ])->assertCreated();

        $sesi = CalibrationSession::with(['equipment', 'rawMeasurements.standard', 'uncertaintyCalculations'])
            ->latest('id')->firstOrFail();

        $this->assertSame(0, $sesi->rawMeasurements->where('peran_sensor', 'z2')->count(), 'Jalur simpan tidak boleh mengarang z2.');
        $tersimpan = $sesi->uncertaintyCalculations->keyBy(fn ($u): int => (int) $u->titik_ke);
        $this->assertCount(3, $tersimpan, 'Jalur simpan menghitung ketiga titik tiga-pembacaan.');

        $baru = $this->hitungUlang($sesi);

        $this->assertCount(3, $baru, 'Jalur hitung ulang harus menjawab sama dengan jalur simpan: tiga titik terhitung.');

        foreach ($baru as $ke => $h) {
            $this->assertEqualsWithDelta((float) $tersimpan[$ke]->koreksi, (float) $h['koreksi'], 1e-8, "titik {$ke}: koreksi");
            $this->assertEqualsWithDelta((float) $tersimpan[$ke]->ketidakpastian_diperluas, (float) $h['ketidakpastian_diperluas'], 1e-8, "titik {$ke}: U95");
        }
    }

    public function test_titik_tanpa_nol_sama_sekali_atau_tanpa_beban_sama_sekali_tetap_dilewati(): void
    {
        $sesi = $this->sesiGram();
        // Titik 3: kedua nol hilang. Titik 4: kedua pembacaan berbeban hilang.
        $sesi->rawMeasurements()->where('titik_ke', 3)->whereIn('peran_sensor', ['z1', 'z2'])->delete();
        $sesi->rawMeasurements()->where('titik_ke', 4)->whereIn('peran_sensor', ['m', 'm_aksen'])->delete();

        $ke = collect(SiapHitungSesi::dari($this->sesiGram()))->pluck('titik_ke')->sort()->values()->all();

        $this->assertSame([1, 2, 5, 6, 7, 8, 9, 10], $ke);
    }

    /**
     * Penjaga drift: aturan "cukup untuk dihitung" di `SiapHitungSesi` harus SAMA
     * dengan yang dipakai kalkulator (`TimbanganCalculator::titikAkurasi`). Empat
     * pembacaan x ada/tidak = 16 kombinasi; titik dihitung kalkulator <=> gerbang lolos.
     */
    public function test_gerbang_hitung_ulang_sama_dengan_aturan_kalkulator_untuk_enam_belas_kombinasi(): void
    {
        $sesi = $this->sesiGram();
        $alat = $sesi->equipment;
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);
        $dasar = collect(SiapHitungSesi::dari($sesi))->firstWhere('titik_ke', 1);
        $this->assertNotNull($dasar);

        $diperiksa = 0;

        for ($bit = 0; $bit < 16; $bit++) {
            $baca = [
                'z1' => ($bit & 1) ? 0.0 : null,
                'z2' => ($bit & 2) ? 0.0 : null,
                'm' => ($bit & 4) ? 5.0 : null,
                'm_aksen' => ($bit & 8) ? 5.0 : null,
            ];

            $titik = $dasar;
            $titik['konteks'] = [...$dasar['konteks'], ...$baca];

            $dihitungKalkulator = count($profil->hitungPerGrup([$titik], $alat)['hitungan'] ?? []) === 1;
            $gerbang = TimbanganMentah::cukupUntukHitung(['nominal' => [5.0], ...$baca]);

            $this->assertSame($dihitungKalkulator, $gerbang, 'Kombinasi '.json_encode($baca).': gerbang hitung ulang beda dari kalkulator.');
            $diperiksa++;
        }

        $this->assertSame(16, $diperiksa);
    }
}
