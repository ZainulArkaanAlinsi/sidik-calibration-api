<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\TidsProfile;
use App\Services\CertificateSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesi TIDS yang BAHANNYA KURANG nggak boleh menerbitkan U95, dan nggak boleh
 * kebocoran belakangan.
 *
 * ## Judulnya berubah 28 Agt 2026, dan itu bukan pelemahan
 *
 * Sampai tanggal itu TIDS satu-satunya profil yang budget-nya sengaja
 * dikosongkan — workbook master lab belum ada, jadi tiap sesi ditahan tanpa
 * syarat. Dua workbook TIDS akhirnya turun, `TidsCalculator` lahir, dan sesi
 * yang bahannya lengkap SEKARANG menerbitkan angka (dipatok
 * `Tests\Unit\TidsMasterTest` lawan dua sesi contoh master).
 *
 * Yang tersisa di sini justru penjagaan yang lebih tajam: sesi yang tipe
 * sensor standar / dryblock / kalibratornya belum jelas tetap NOL angka —
 * dan `test_u95_terbit_kalau_bahannya_lengkap` di bawah yang bikin "nol" itu
 * nggak bisa lagi hijau karena alasan yang salah.
 *
 * ## Yang sudah dijaga di tempat lain
 *
 * `TidsLembarKerjaTest` sudah membuktikan jalur simpan & pratinjau, dan
 * `TidsMasterTest` mengadu angkanya ke workbook master.
 *
 * ## Yang DIJAGA DI SINI: tiga pintu belakang
 *
 * Penahannya hidup di `TidsProfile::hitungPerGrup()`. Artinya dia cuma bekerja
 * kalau jalur yang dipakai MELEWATI profil TIDS. Tiga jalur di bawah ini
 * nggak lewat situ, dan kalau salah satunya bocor, hasilnya sama persis
 * dengan yang paling dihindari: sertifikat terakreditasi mencetak U95 yang
 * nggak pernah dihitung siapa pun.
 *
 *  1. **Sertifikat.** `CertificateSnapshotBuilder` membaca
 *     `uncertaintyCalculations` langsung dari basis data. Kalau dia mengisi
 *     angka dari tempat lain waktu barisnya kosong (CMC, toleransi alat,
 *     apa pun), blokir di profil jadi tidak ada artinya.
 *
 *  2. **Baris lama yang ketinggalan.** Sebuah sesi bisa lahir waktu alatnya
 *     belum diakui TIDS — `nama_alat_kemampuan` kosong dulu jatuh ke pH, dan
 *     pH menghitung dengan senang hati. Begitu nama alatnya dibetulkan jadi
 *     nama lampiran akreditasi, sesi itu jadi sesi TIDS yang PUNYA baris U95
 *     hasil rumus pH. Yang harus terjadi waktu sesinya disimpan ulang: baris
 *     lamanya HILANG, bukan dibiarkan karena "hitungannya sudah ada".
 *
 *  3. **Jalur CMC generik.** Baris CMC TIDS sudah ter-seed (0,86 / 1,4 /
 *     3,1 °C). `GumCalculator::hitungTitik()` nggak butuh komponen budget buat
 *     memulangkan angka — kalau profilnya balik `null` dia memakai CMC sebagai
 *     U95. Sengaja diseed di sini justru supaya jalur itu SUKSES kalau
 *     blokirnya lepas.
 */
class TidsU95TidakBocorTest extends TestCase
{
    use RefreshDatabase;

    /** Ejaan yang MENGIKAT — persis lampiran akreditasi LK-285-IDN no. 2. */
    private const NAMA_LAMPIRAN = 'Temperatur Indikator dengan Sensor';

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    private function alatTids(): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'suhu-dan-kelembapan'])->id,
            'nama_alat' => 'Temperature Indicator',
            'nama_alat_kemampuan' => self::NAMA_LAMPIRAN,
            'range_min' => -20, 'range_max' => 600,
            'satuan' => '°C', 'resolusi' => 0.1, 'toleransi' => null,
        ]);
    }

    /**
     * Diseed SENGAJA: justru karena barisnya ada, jalur CMC generik bakal
     * sukses memulangkan angka kalau profilnya berhenti menahan.
     */
    private function seedCmcTids(): void
    {
        foreach ([[-20.0, 150.0, 0.86], [150.0, 400.0, 1.4], [400.0, 600.0, 3.1]] as [$min, $maks, $u]) {
            CalibrationCapability::factory()->create([
                'nama_alat' => self::NAMA_LAMPIRAN,
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => '°C',
                'ketidakpastian_terbaik' => $u,
                'satuan_ketidakpastian' => '°C',
                'metode' => TidsProfile::KODE_METODE,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Equipment $alat, Standard $standar): array
    {
        return [
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 23.5,
            'kelembaban' => 55.0,
            'measurements' => [
                ['titik_ukur' => 100.0, 'satuan' => '°C', 'pembacaan' => [100.1, 100.2, 100.0, 100.1, 100.2]],
            ],
        ];
    }

    /**
     * PINTU 1 — sertifikat nggak boleh mengarang U95 waktu barisnya kosong.
     *
     * Blokir di profil cuma menahan LAHIRNYA baris. Kalau pembuat sertifikat
     * mengisi kolomnya dari tempat lain waktu kosong, blokir itu nggak ada
     * artinya: yang sampai ke pelanggan tetap angka yang nggak pernah
     * dihitung.
     */
    public function test_sertifikat_tids_nggak_nyodorin_u95_dari_tempat_lain(): void
    {
        $alat = $this->alatTids();
        $standar = Standard::factory()->create(['nama' => 'Temperature Calibrator Constant 40T']);
        $this->seedCmcTids();

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $standar))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertSame(0, $sesi->uncertaintyCalculations()->count(), 'Prasyarat: barisnya harus kosong.');

        $sertifikat = Certificate::factory()->create(['calibration_session_id' => $sesi->id]);
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi->fresh(), $sertifikat);

        // Ditelusuri SELURUH snapshot, bukan cuma kunci yang kebetulan diingat.
        // Kalau nanti bentuk snapshotnya berubah, penelusurannya ikut.
        $angka = [];
        array_walk_recursive(
            $snapshot,
            static function ($nilai, $kunci) use (&$angka): void {
                if (is_string($kunci) && preg_match('/u95|ketidakpastian|uncertainty/i', $kunci)) {
                    $angka[$kunci] = $nilai;
                }
            },
        );

        // Yang dilarang cuma ANGKA. `u95_per_titik => false` itu saklar
        // ("lembar ini nyetak U95 per titik atau nggak") dan jawabannya memang
        // `false` — melarangnya bikin test ini nuntut hal yang salah.
        foreach ($angka as $kunci => $nilai) {
            $this->assertFalse(
                is_numeric($nilai),
                "Snapshot sertifikat mengisi `{$kunci}` = ".var_export($nilai, true)
                .' padahal sesi TIDS ini nol baris ketidakpastian. Angka itu nggak pernah dihitung siapa pun.',
            );
        }

        $this->assertNotSame([], $angka, 'Nol kunci ber-U95 di snapshot — penelusurannya yang salah, bukan hasilnya.');

        // Dan CMC-nya sendiri jangan sampai ikut kecetak sebagai U95 sesi:
        // 0,86 °C itu lantai kemampuan lab, bukan hasil sesi ini.
        $rata = json_encode($snapshot);
        $this->assertIsString($rata);
        $this->assertStringNotContainsString('0.86', $rata, 'CMC bocor ke snapshot sertifikat sebagai angka sesi.');
    }

    /**
     * PINTU 2 — baris U95 dari rumus LAIN nggak boleh selamat di sesi TIDS.
     *
     * Ini kejadian yang paling mungkin di produksi: sesinya lahir waktu
     * `nama_alat_kemampuan` masih kosong (jatuh ke pH, yang menghitung dengan
     * senang hati), lalu nama alatnya dibetulkan. Sesi itu sekarang sesi TIDS
     * yang punya U95 hasil rumus pH — dan nggak ada satu pun error yang
     * menandainya.
     */
    public function test_baris_u95_lama_ilang_waktu_sesi_tids_disimpan_ulang(): void
    {
        $alat = $this->alatTids();
        $standar = Standard::factory()->create();
        $this->seedCmcTids();

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $standar))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);

        // Tanam baris "warisan" — persis bentuk yang ditinggalkan rumus lain.
        UncertaintyCalculation::create([
            'calibration_session_id' => $sesi->id,
            'standard_id' => $standar->id,
            'titik_ke' => 1,
            'titik_ukur' => 100.0,
            'rata_rata' => 100.12,
            'error' => 0.12,
            'koreksi' => -0.12,
            'standar_deviasi' => 0.08,
            'jumlah_pengulangan' => 5,
            'type_a' => 0.036,
            'type_b' => 0.4,
            'ketidakpastian_gabungan' => 0.43,
            'faktor_cakupan_k' => 2.0,
            'ketidakpastian_diperluas' => 0.86,
            'metode' => 'gum-ph',
            'calculated_at' => now(),
        ]);
        $this->assertSame(1, $sesi->uncertaintyCalculations()->count(), 'Prasyarat: barisnya berhasil ditanam.');

        // Teknisi memang DITAHAN di sini — sesi yang nunggu approval cuma boleh
        // diubah admin. Dikunci sekalian, karena itu perilaku yang bener dan
        // gampang kegeser waktu ada yang ngoprek jalur update.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload($alat, $standar))
            ->assertStatus(422);

        // Yang PUNYA hak ngedit: admin. Jadi lewat dia serangan sesungguhnya.
        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload($alat, $standar))
            ->assertOk();

        $this->assertSame(
            0,
            $sesi->fresh()->uncertaintyCalculations()->count(),
            'Baris U95 dari rumus lain selamat di sesi TIDS — sertifikatnya bakal mencetak angka '
            .'yang lahir dari rumus alat yang beda.',
        );
    }

    /**
     * PINTU 3 — jalur CMC generik memang HIDUP, dan memang sedang ditahan.
     *
     * Test ini yang membuktikan dua test di atas bukan kebetulan. Kalau CMC-nya
     * ternyata nggak ter-seed, "nol baris U95" jadi hasil yang benar karena
     * alasan yang salah — dan blokir di profil bisa dicabut tanpa satu pun
     * test berubah merah.
     *
     * Jadi di sini dibuktikan barisnya ADA dan memuat titik 100 °C, sementara
     * profilnya tetap memulangkan `belum_dihitung` untuk titik yang sama.
     */
    public function test_cmc_tids_beneran_ada_jadi_penahannya_bukan_kebetulan(): void
    {
        $this->seedCmcTids();

        $memuat = CalibrationCapability::where('nama_alat', self::NAMA_LAMPIRAN)
            ->where('range_min', '<=', 100.0)
            ->where('range_max', '>=', 100.0)
            ->exists();

        $this->assertTrue(
            $memuat,
            'CMC TIDS yang memuat 100 °C nggak ada — "nol baris U95" jadi benar karena alasan yang salah.',
        );

        $hasil = (new TidsProfile)->hitungPerGrup(
            [['titik_ke' => 1, 'titik_ukur' => 100.0, 'pembacaan' => [100.1, 100.2], 'standard' => Standard::factory()->create()]],
            $this->alatTids(),
        );

        $this->assertSame([], $hasil['hitungan']);
        $this->assertCount(1, $hasil['belum_dihitung']);
    }

    /**
     * SISI SEBALIKNYA — dan tanpa ini tiga test di atas bisa hijau selamanya
     * karena alasan yang salah.
     *
     * Sesi di atas nol U95 karena bahannya memang kurang. Kalau suatu saat
     * `hitungPerGrup()` rusak total dan menahan SEMUA sesi, ketiganya tetap
     * hijau — dan yang rugi teknisi yang lembarnya lengkap tapi angkanya nggak
     * pernah terbit. Test ini mengunci pintu itu: satu sesi TIDS lengkap
     * (dua deret pasangan, No. Termokopel, tipe sensor, dryblock, uji titik es)
     * WAJIB melahirkan baris `uncertainty_calculations` yang isinya sama dengan
     * budget master.
     *
     * Angka acuannya sesi `071-CAL-325` di workbook Recorder — sama dengan yang
     * dipatok `Tests\Unit\TidsMasterTest`, tapi lewat HTTP: yang diuji di sini
     * SAMBUNGANNYA (payload → `susunPasanganStandarUut()` → profil →
     * kalkulator → baris tersimpan), bukan rumusnya.
     */
    public function test_u95_terbit_kalau_bahannya_lengkap(): void
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'suhu-dan-kelembapan'])->id,
            'nama_alat' => 'Multiparameter (Suhu)',
            'nama_alat_kemampuan' => self::NAMA_LAMPIRAN,
            'range_min' => -20, 'range_max' => 600,
            // `INPUT DATA!E16` sesi master.
            'satuan' => '°C', 'resolusi' => 0.01, 'toleransi' => null,
        ]);
        $this->seedCmcTids();

        $recorder = Standard::factory()->create([
            'nama' => 'Temperature Recorder Graphtech GL840',
            'merk' => 'Graphtech',
            'serial_number' => 'C305B1470',
        ]);

        $payload = [
            'equipment_id' => $alat->id,
            'standard_id' => $recorder->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 29.55,
            'kelembaban' => 60.5,
            'tipe_sensor' => 'Type K',
            'alat_bantu' => 'A',
            // `INPUT DATA!N50` & `P50`.
            'titik_es' => [0.2, 0.4],
            'measurements' => [
                ['titik_ukur' => 0.0, 'satuan' => '°C', 'no_probe' => 1,
                    'standar' => [0.0, 0.0, 0.0, 0.0, 0.0], 'uut' => [0.0, 0.0, 0.0, 0.0, 0.0]],
                ['titik_ukur' => 60.0, 'satuan' => '°C', 'no_probe' => 2,
                    'standar' => [60.1, 60.1, 60.1, 60.2, 60.2], 'uut' => [60.3, 60.34, 60.45, 60.47, 60.41]],
                ['titik_ukur' => 100.0, 'satuan' => '°C', 'no_probe' => 3,
                    'standar' => [92.8, 93.4, 94.5, 94.6, 94.6], 'uut' => [92.29, 92.85, 94.05, 94.09, 94.12]],
                ['titik_ukur' => 200.0, 'satuan' => '°C', 'no_probe' => 4,
                    'standar' => [193.0, 193.0, 193.1, 193.1, 193.1], 'uut' => [192.17, 192.19, 192.21, 192.24, 192.25]],
            ],
        ];

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);

        // Dua deret × empat titik × lima ulangan = 40 baris mentah. Sebelum
        // lembar ini pindah ke jalur pasangan, deret standarnya (20 baris)
        // nggak punya tempat sama sekali.
        $this->assertSame(40, $sesi->rawMeasurements()->count());
        $this->assertSame(20, $sesi->rawMeasurements()->where('peran_sensor', 'standar')->count());

        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $this->assertCount(4, $baris, 'Sesi TIDS lengkap wajib melahirkan satu baris per set point.');

        // `SERTIFIKAT!E20:E23` & `L20:L23` sesi `071-CAL-325`.
        $this->assertEqualsWithDelta(-0.27, (float) $baris[0]->titik_ukur, 1e-6, 'Standard Reading titik 1');
        $this->assertEqualsWithDelta(60.215, (float) $baris[1]->titik_ukur, 1e-6, 'Standard Reading titik 2');
        $this->assertEqualsWithDelta(0.755, (float) $baris[2]->koreksi, 1e-6, 'Correction titik 3');
        $this->assertEqualsWithDelta(0.938, (float) $baris[3]->koreksi, 1e-6, 'Correction titik 4');

        // SATU U95 buat seluruh sesi — `AC42`, dicetak sebagai baris di bawah
        // tabel, bukan kolom per titik.
        foreach ($baris as $b) {
            $this->assertEqualsWithDelta(1.62302428, (float) $b->ketidakpastian_diperluas, 5e-5, 'U95 (`AC42`)');
            $this->assertEqualsWithDelta(0.81598032, (float) $b->ketidakpastian_gabungan, 5e-6, 'Uc (`AC37`)');
            $this->assertNull($b->keputusan, 'Lembar TIDS nggak punya kolom PASS/FAIL.');
        }

        // Dan jejak auditnya membawa keduabelas komponen + penyimpangan master
        // yang ditiru — itu yang dibaca manajer teknis waktu memutuskan.
        $sumber = array_column($baris[0]->type_b_components, 'sumber');
        $this->assertContains('keterulangan_pembacaan', $sumber);
        $this->assertContains('drift_uut', $sumber);
        $this->assertContains('tids_recorder_sel_tetap', $sumber);

        // Versi rumus DISTEMPEL. Tanpa stempel ini nggak ada cara tahu
        // sertifikat mana dihitung pakai aturan yang mana sesudah rumusnya
        // berubah — dan buat lab terakreditasi itu jejak audit, bukan kolom
        // pelengkap. Baris `gum-tids` sendiri lahir di sini: sampai sesi TIDS
        // pertama kehitung, `formulas` nggak pernah punya barisnya.
        foreach ($baris as $b) {
            $this->assertNotNull($b->formula_version_id, 'Versi rumus TIDS nggak kestempel.');
        }
        $this->assertSame(
            Formula::KODE_GUM_TIDS,
            FormulaVersion::findOrFail($baris[0]->formula_version_id)->formula->kode,
        );

        // PREVIEW dan STORE wajib memulangkan angka yang SAMA PERSIS. Dua
        // angka berbeda untuk satu pengukuran yang sama itu temuan audit,
        // bukan selisih pembulatan yang bisa dijelaskan.
        $pratinjau = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $payload)
            ->assertOk()
            ->json('data.titik');

        $this->assertCount(4, $pratinjau);

        foreach ($pratinjau as $i => $t) {
            $this->assertEqualsWithDelta(
                (float) $baris[$i]->ketidakpastian_diperluas,
                (float) $t['ketidakpastian_diperluas'],
                1e-9,
                "U95 pratinjau vs tersimpan beda di titik ke-{$i}.",
            );
            $this->assertEqualsWithDelta(
                (float) $baris[$i]->koreksi,
                (float) $t['koreksi'],
                1e-9,
                "Koreksi pratinjau vs tersimpan beda di titik ke-{$i}.",
            );
        }

        // Dan angkanya SAMPAI ke sertifikat. Tiga test di atas menjaga jalur
        // yang menahan; yang ini menjaga jalur yang menerbitkan — dan
        // dua-duanya lewat `CertificateSnapshotBuilder`, jadi kalau dia
        // berhenti membaca `uncertaintyCalculations` cuma test ini yang merah.
        $sertifikat = Certificate::factory()->create(['calibration_session_id' => $sesi->id]);
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi->fresh(), $sertifikat);

        $this->assertStringContainsString(
            '1.62',
            (string) json_encode($snapshot),
            'U95 sesi TIDS nggak nyampe snapshot sertifikat — yang diterima pelanggan bakal kosong.',
        );
    }
}
