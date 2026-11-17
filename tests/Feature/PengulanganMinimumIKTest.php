<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\Calibration\Profiles\TimerStopwatchProfile;
use App\Services\CalibrationValidator;
use App\Support\MicrometerMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pengulangan MINIMUM menurut Instruksi Kerja: Micrometer 5, Timer/Stopwatch 3
 * (audit sertifikat 16 Nov 2026, G5/D1; `docs/permintaan-user-7.md` §55
 * pertanyaan terbuka no. 4).
 *
 * Sebelum ini server menerima 2–10 untuk keduanya dan menerbitkan sertifikat
 * dari pengulangan di bawah IK tanpa satu pun tanda. Sekarang:
 *
 *  - skema titik sesi, `?pengulangan=` lembar kerja, dan jepitan kolom memakai
 *    batas profil (`minPengulanganIK()`);
 *  - draf tetap tersimpan setengah jadi dan hitungannya tidak dibuang;
 *  - approve ditolak (ERROR `pengulangan_kurang_dari_ik`) selama ada titik aktif
 *    di bawah batas;
 *  - sesi yang SUDAH disetujui tidak berubah — cuma dilaporkan (INFO);
 *  - `kalibrasi:hitung-ulang` melaporkan hal yang sama dari sumber yang sama.
 *
 * Profil lain TIDAK berubah (batasnya tetap 2, tanpa penegakan tambahan).
 */
class PengulanganMinimumIKTest extends TestCase
{
    use RefreshDatabase;

    private const KODE = 'pengulangan_kurang_dari_ik';

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Bahan sesi
    // ------------------------------------------------------------------

    /**
     * Micrometer varian B (25-50 mm), tiga titik pertama kertas. `$jumlah` =
     * berapa pembacaan terisi di tiap titik; `$perTitik` menimpa titik tertentu
     * (indeks 0-based).
     *
     * @param  array<int, int>  $perTitik
     * @return array<string, mixed>
     */
    private function payloadMicrometer(Equipment $alat, int $jumlah, array $perTitik = []): array
    {
        $deret = [
            [25.001, 25.0, 25.001, 25.0, 25.001, 25.0, 25.001],
            [27.502, 27.501, 27.502, 27.501, 27.502, 27.501, 27.502],
            [31.003, 31.002, 31.003, 31.002, 31.003, 31.002, 31.003],
        ];
        $nominal = [25.0, 27.5, 31.0];

        $measurements = [];

        foreach ($nominal as $i => $n) {
            $measurements[] = [
                'titik_ukur' => $n,
                'pembacaan' => array_slice($deret[$i], 0, $perTitik[$i] ?? $jumlah),
            ];
        }

        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 20.5, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 41, 'kelembaban_akhir' => 40,
            'measurements' => $measurements,
            'spesifikasi_alat' => [
                'rentang_ukur' => '25-50', 'kapasitas' => '50', 'resolusi' => '0.001',
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 50.0,
                    'resolusi_mm' => 0.001,
                    'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0],
                ],
            ],
        ];
    }

    private function alatMicrometer(): Equipment
    {
        return Equipment::where('serial_number', 'ZQ-100')->firstOrFail();
    }

    /** @return array{Equipment, int|null} */
    private function alatTimer(): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', '015-CAL-424')->firstOrFail();
        $alat = Equipment::findOrFail($sesi->equipment_id);
        $alat->update(['range_min' => 0, 'range_max' => 5000]);

        return [$alat, $sesi->standard_id];
    }

    /**
     * Dua titik timer (60 s dan 300 s); `$std`/`$uut` = jumlah ulangan tiap sisi
     * di titik 60 s, titik 300 s selalu tiga-tiga.
     *
     * @return array<string, mixed>
     */
    private function payloadTimer(Equipment $alat, ?int $standar, int $std, int $uut, array $tambahan = []): array
    {
        $titik = static function (float $detik, int $nStd, int $nUut): array {
            $ms = $detik * 1000;
            $offStd = [123, 211, 45, 80, 90, 100, 110, 120, 130, 140];
            $offUut = [131, 219, 61, 88, 98, 108, 118, 128, 138, 148];

            return [
                'titik_ukur' => $detik,
                'satuan' => 's',
                'standar' => array_map(static fn (int $o): float => $ms + $o, array_slice($offStd, 0, $nStd)),
                'uut' => array_map(static fn (int $o): float => $ms + $o, array_slice($offUut, 0, $nUut)),
            ];
        };

        return [
            'equipment_id' => $alat->id,
            'standard_id' => $standar,
            'tanggal_kalibrasi' => '2026-09-01',
            'measurements' => [$titik(60, $std, $uut), $titik(300, 3, 3)],
            ...$tambahan,
        ];
    }

    private function simpan(array $payload): int
    {
        return (int) $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');
    }

    /** @return list<array<string, mixed>> temuan berkode IK pada hasil validator */
    private function temuanIK(int $sesiId): array
    {
        $sesi = CalibrationSession::findOrFail($sesiId);

        return array_values(array_filter(
            app(CalibrationValidator::class)->periksa($sesi)['temuan'],
            static fn (array $t): bool => $t['kode'] === self::KODE,
        ));
    }

    // ------------------------------------------------------------------
    // Skema: satu angka per profil, profil lain tidak berubah
    // ------------------------------------------------------------------

    public function test_batas_ik_hanya_micrometer_lima_dan_timer_tiga(): void
    {
        $batas = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $batas[$profil->kode()] = $profil->minPengulanganIK();
        }

        $this->assertSame(5, $batas['micrometer']);
        $this->assertSame(3, $batas['timer_stopwatch']);

        // Semua profil lain TIDAK punya penegakan tambahan: batasnya tetap
        // dua (simpangan baku butuh dua angka), jadi tidak ada sesi alat lain
        // yang tiba-tiba ditahan penerbitannya.
        $lain = array_diff_key($batas, ['micrometer' => 1, 'timer_stopwatch' => 1]);

        $this->assertNotEmpty($lain);

        foreach ($lain as $kode => $min) {
            $this->assertSame(CalibrationProfile::MIN_KOLOM_PENGULANGAN, $min, "{$kode}: batas pengulangannya bergeser.");
        }
    }

    public function test_skema_titik_sesi_memuat_batas_ik_sebagai_min(): void
    {
        $registry = app(CalibrationProfileRegistry::class);
        $mikro = $registry->untukKode('micrometer')->batasTitik($this->alatMicrometer(), [
            MicrometerMentah::KUNCI_SESI => ['kapasitas_mm' => 50.0],
        ]);
        $timer = $registry->untukKode('timer_stopwatch')->batasTitik($this->alatTimer()[0]);

        $this->assertTrue($mikro['didukung']);
        $this->assertSame(5, $mikro['tabel'][0]['pengulangan']['min']);
        $this->assertSame(MicrometerProfile::PENGULANGAN, $mikro['tabel'][0]['pengulangan']['bawaan']);

        $this->assertTrue($timer['didukung']);
        $this->assertSame(3, $timer['tabel'][0]['pengulangan']['min']);
        $this->assertSame(TimerStopwatchProfile::PENGULANGAN, $timer['tabel'][0]['pengulangan']['bawaan']);

        // Profil jalur datar lain: tetap 2.
        $ph = $registry->untukKode('ph_meter')->batasTitik();
        $this->assertTrue($ph['didukung']);
        $this->assertSame(2, $ph['tabel'][0]['pengulangan']['min']);
    }

    public function test_pesan_titik_tidak_terhitung_menyebut_ambang_yang_ditegakkan(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        $this->assertSame(5, $registry->untukKode('micrometer')->minPengulanganPerTitik());
        $this->assertSame(3, $registry->untukKode('timer_stopwatch')->minPengulanganPerTitik());
    }

    // ------------------------------------------------------------------
    // GET /calibrations/lembar-kerja?pengulangan=
    // ------------------------------------------------------------------

    /** @return array<string, array{string, int, int}> */
    public static function batasLembar(): array
    {
        return [
            'micrometer' => ['micrometer', 5, 5],
            'timer' => ['timer_stopwatch', 3, 3],
        ];
    }

    #[DataProvider('batasLembar')]
    public function test_lembar_kerja_menolak_pengulangan_di_bawah_ik(string $profil, int $min, int $bawaan): void
    {
        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/lembar-kerja?profil={$profil}&pengulangan=".($min - 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pengulangan');

        // Tepat di batas, dan di batas atas: lolos dan kolomnya ikut.
        foreach ([$min, 10] as $n) {
            $this->actingAs($this->teknisi)
                ->getJson("/api/calibrations/lembar-kerja?profil={$profil}&pengulangan={$n}")
                ->assertOk()
                ->assertJsonPath('data.jumlah_pengulangan', $n);
        }

        // Tanpa parameter: bentuk bawaan tidak berubah sama sekali.
        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/lembar-kerja?profil={$profil}")
            ->assertOk()
            ->assertJsonPath('data.jumlah_pengulangan', $bawaan);
    }

    public function test_batas_umum_dua_sampai_sepuluh_tetap_berlaku_untuk_profil_lain(): void
    {
        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=ph_meter&pengulangan=2')
            ->assertOk()
            ->assertJsonPath('data.jumlah_pengulangan', 2);

        // Batas umum juga tetap menjaga micrometer & timer di bawah 2 dan di atas 10.
        foreach (['micrometer', 'timer_stopwatch'] as $profil) {
            foreach ([1, 11] as $n) {
                $this->actingAs($this->teknisi)
                    ->getJson("/api/calibrations/lembar-kerja?profil={$profil}&pengulangan={$n}")
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('pengulangan');
            }
        }
    }

    public function test_jepitan_kolom_memakai_batas_profil_tapi_bawaannya_tidak_berubah(): void
    {
        $bentuk = ['jumlah_pengulangan' => 5, 'bagian' => [['tabel' => [['pengulangan' => [1, 2, 3, 4, 5]]]]]];

        // Pemanggil lama (tanpa batas profil): jepit ke 2.
        $lama = CalibrationProfile::setelKolomPengulangan($bentuk, 1);
        $this->assertSame(2, $lama['jumlah_pengulangan']);

        // Dengan batas profil: dijepit naik ke 5; di atas batas dipertahankan.
        $this->assertSame(5, CalibrationProfile::setelKolomPengulangan($bentuk, 3, 5)['jumlah_pengulangan']);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            CalibrationProfile::setelKolomPengulangan($bentuk, 7, 5)['bagian'][0]['tabel'][0]['pengulangan'],
        );
    }

    // ------------------------------------------------------------------
    // Titik sesi: pengulangan tabel
    // ------------------------------------------------------------------

    public function test_titik_sesi_timer_menolak_pengulangan_di_bawah_ik(): void
    {
        [$alat, $std] = $this->alatTimer();
        $set = static fn (int $n): array => [
            'revisi' => 0,
            'titik' => [
                ['id' => 'profil-utama-1', 'tabel' => 'utama', 'nominal' => 60, 'asal' => 'profil', 'aktif' => true],
                ['id' => 'profil-utama-2', 'tabel' => 'utama', 'nominal' => 300, 'asal' => 'profil', 'aktif' => true],
            ],
            'pengulangan' => ['utama' => $n],
        ];

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, 3, 3, [
                'status' => CalibrationSession::STATUS_DRAFT, 'titik_sesi' => $set(2),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('titik_sesi');

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, 3, 3, [
                'status' => CalibrationSession::STATUS_DRAFT, 'titik_sesi' => $set(3),
            ]))
            ->assertCreated();
    }

    // ------------------------------------------------------------------
    // Validator: draf tersimpan, approve ditolak
    // ------------------------------------------------------------------

    public function test_micrometer_tiga_pembacaan_tersimpan_tapi_approve_ditolak(): void
    {
        $id = $this->simpan($this->payloadMicrometer($this->alatMicrometer(), 3));
        $sesi = CalibrationSession::findOrFail($id);

        // Tersimpan utuh: tidak ada pembacaan yang dibuang, hitungannya ada.
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);
        $this->assertSame(9, RawMeasurement::where('calibration_session_id', $id)
            ->where('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)->count());
        $this->assertCount(3, $sesi->uncertaintyCalculations);

        $temuan = $this->temuanIK($id);
        $this->assertCount(1, $temuan);
        $this->assertSame(CalibrationValidator::ERROR, $temuan[0]['tingkat']);
        $this->assertSame(5, $temuan[0]['konteks']['minimum']);
        $this->assertSame([1, 2, 3], array_column($temuan[0]['konteks']['titik'], 'titik_ke'));
        $this->assertSame([3, 3, 3], array_column($temuan[0]['konteks']['titik'], 'terisi'));
        $this->assertStringContainsString('minimal 5 pembacaan per titik', $temuan[0]['pesan']);

        $hasil = $this->denganOrderNumber($id)->sebagaiPemeriksaLain($sesi)
            ->postJson("/api/calibrations/{$id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertStatus(422)
            ->json();

        $this->assertFalse($hasil['validasi']['boleh_terbit']);
        $this->assertContains(self::KODE, array_column($hasil['validasi']['temuan'], 'kode'));
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertSame(0, Certificate::where('calibration_session_id', $id)->count());
    }

    public function test_micrometer_lima_pembacaan_lolos_batas_ik(): void
    {
        $id = $this->simpan($this->payloadMicrometer($this->alatMicrometer(), 5));

        $this->assertSame([], $this->temuanIK($id));
    }

    public function test_micrometer_keping_balok_bukan_pengulangan(): void
    {
        // Titik ke-3 (31,0 mm) ditumpuk dari DUA keping balok: barisnya di
        // `raw_measurements` = 4 pembacaan + 2 keping = 6 baris. Kalau keping
        // ikut terhitung, titik ini lolos (6 >= 5) padahal pembacaannya 4.
        $id = $this->simpan($this->payloadMicrometer($this->alatMicrometer(), 5, [2 => 4]));

        $this->assertGreaterThanOrEqual(2, RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 3)->where('peran_sensor', MicrometerMentah::PERAN_BALOK)->count());

        $temuan = $this->temuanIK($id);

        $this->assertCount(1, $temuan);
        $this->assertSame([3], array_column($temuan[0]['konteks']['titik'], 'titik_ke'));
        $this->assertSame([4], array_column($temuan[0]['konteks']['titik'], 'terisi'));
    }

    public function test_timer_dua_ulangan_ditahan_tiga_lolos(): void
    {
        [$alat, $std] = $this->alatTimer();

        $kurang = $this->simpan($this->payloadTimer($alat, $std, 2, 2));
        $temuan = $this->temuanIK($kurang);

        $this->assertCount(1, $temuan);
        $this->assertSame(CalibrationValidator::ERROR, $temuan[0]['tingkat']);
        $this->assertSame(3, $temuan[0]['konteks']['minimum']);
        $this->assertSame([1], array_column($temuan[0]['konteks']['titik'], 'titik_ke'));
        $this->assertSame([2], array_column($temuan[0]['konteks']['titik'], 'terisi'));

        // Hitungan titik 60 s tetap ada — draf setengah jadi tidak kehilangan angka.
        $this->assertCount(2, CalibrationSession::findOrFail($kurang)->uncertaintyCalculations);

        $this->assertSame([], $this->temuanIK($this->simpan($this->payloadTimer($alat, $std, 3, 3))));
    }

    public function test_timer_sisi_timpang_diukur_dari_sisi_yang_lebih_sedikit(): void
    {
        [$alat, $std] = $this->alatTimer();

        // Standar 3, UUT 2: pasangannya cuma 2.
        $id = $this->simpan($this->payloadTimer($alat, $std, 3, 2));
        $temuan = $this->temuanIK($id);

        $this->assertCount(1, $temuan);
        $this->assertSame([2], array_column($temuan[0]['konteks']['titik'], 'terisi'));
    }

    public function test_timer_approve_ditolak_dengan_sebab_yang_terbaca(): void
    {
        [$alat, $std] = $this->alatTimer();
        $id = $this->simpan($this->payloadTimer($alat, $std, 2, 2));
        $sesi = CalibrationSession::findOrFail($id);

        $res = $this->denganOrderNumber($id)->sebagaiPemeriksaLain($sesi)
            ->postJson("/api/calibrations/{$id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertStatus(422);

        $ik = collect($res->json('validasi.temuan'))->firstWhere('kode', self::KODE);

        $this->assertNotNull($ik, 'Approve harus ditolak oleh temuan IK.');
        $this->assertSame(CalibrationValidator::ERROR, $ik['tingkat']);
        $this->assertStringContainsString('titik ke-1', $ik['pesan']);
        $this->assertStringContainsString('minimal 3 pembacaan per titik', $ik['pesan']);
    }

    public function test_titik_nonaktif_tidak_ikut_diperiksa(): void
    {
        [$alat, $std] = $this->alatTimer();
        $titik = [
            ['id' => 'profil-utama-1', 'tabel' => 'utama', 'nominal' => 60, 'asal' => 'profil', 'aktif' => false,
                'alasan' => 'Stopwatch macet di titik ini'],
            ['id' => 'profil-utama-2', 'tabel' => 'utama', 'nominal' => 300, 'asal' => 'profil', 'aktif' => true],
        ];

        $id = $this->simpan($this->payloadTimer($alat, $std, 2, 2, [
            'titik_sesi' => ['revisi' => 0, 'titik' => $titik, 'pengulangan' => []],
        ]));

        $this->assertSame([], $this->temuanIK($id), 'Titik yang sengaja dinonaktifkan tidak boleh menahan terbit.');
    }

    // ------------------------------------------------------------------
    // Sesi lama: dilaporkan, tidak diubah, tidak dihapus
    // ------------------------------------------------------------------

    public function test_sesi_yang_sudah_disetujui_hanya_dilaporkan_sebagai_info(): void
    {
        $id = $this->simpan($this->payloadMicrometer($this->alatMicrometer(), 3));

        // Disetujui SEBELUM batas ditegakkan: status maju lewat query builder
        // (jalur approve sendiri sekarang menolaknya).
        DB::table('calibration_sessions')->where('id', $id)->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        $sebelum = CalibrationSession::findOrFail($id);
        $hitunganSebelum = $sebelum->uncertaintyCalculations->map->only(['titik_ke', 'koreksi', 'ketidakpastian_diperluas'])->all();
        $mentahSebelum = RawMeasurement::where('calibration_session_id', $id)->count();

        $temuan = $this->temuanIK($id);

        $this->assertCount(1, $temuan);
        $this->assertSame(CalibrationValidator::INFO, $temuan[0]['tingkat']);
        $this->assertStringContainsString('sudah disetujui', $temuan[0]['pesan']);

        // Tidak ada ERROR berkode IK, jadi sapuan tidak menyebutnya rusak.
        $periksa = app(CalibrationValidator::class)->periksa(CalibrationSession::findOrFail($id));
        $this->assertSame([], array_values(array_filter(
            $periksa['temuan'],
            static fn (array $t): bool => $t['kode'] === self::KODE && $t['tingkat'] === CalibrationValidator::ERROR,
        )));

        // Tidak ada yang diubah atau dihapus oleh pelaporannya.
        $sesudah = CalibrationSession::findOrFail($id);
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesudah->status);
        $this->assertSame($hitunganSebelum, $sesudah->uncertaintyCalculations->map->only(['titik_ke', 'koreksi', 'ketidakpastian_diperluas'])->all());
        $this->assertSame($mentahSebelum, RawMeasurement::where('calibration_session_id', $id)->count());
    }

    // ------------------------------------------------------------------
    // kalibrasi:hitung-ulang
    // ------------------------------------------------------------------

    public function test_hitung_ulang_melaporkan_pengulangan_di_bawah_ik_tanpa_mengubah_hitungan(): void
    {
        $id = $this->simpan($this->payloadMicrometer($this->alatMicrometer(), 3));
        $sebelum = CalibrationSession::findOrFail($id)->uncertaintyCalculations
            ->map->only(['titik_ke', 'koreksi', 'ketidakpastian_diperluas'])->all();

        // Dry-run pun melapor — validator di ujung perintah baru jalan sesudah ditulis.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id], '--dry-run' => true])
            ->expectsOutputToContain('pengulangan di bawah batas Instruksi Kerja (minimal 5 pembacaan per titik)')
            ->expectsOutputToContain('titik 1: 3 pembacaan')
            ->expectsOutputToContain('titik 2: 3 pembacaan')
            ->expectsOutputToContain('titik 3: 3 pembacaan')
            ->expectsOutputToContain('belum bisa disetujui')
            ->assertSuccessful();

        // Hitung ulang sungguhan: hitungan sama, pembacaan mentah utuh.
        //
        // Dibandingkan dengan toleransi 1e-7, bukan `assertSame`: yang tersimpan
        // lewat API melewati kolom `decimal(20,8)` (dibulatkan di MySQL), sedangkan
        // SQLite uji menyimpan float penuh hasil hitung ulang. Beda ~1e-13 itu
        // artefak mesin uji, bukan perubahan hitungan.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])->assertSuccessful();

        $sesudah = CalibrationSession::findOrFail($id)->uncertaintyCalculations
            ->map->only(['titik_ke', 'koreksi', 'ketidakpastian_diperluas'])->all();

        $this->assertSame(array_column($sebelum, 'titik_ke'), array_column($sesudah, 'titik_ke'));
        $this->assertEqualsWithDelta(array_column($sebelum, 'koreksi'), array_column($sesudah, 'koreksi'), 1e-7);
        $this->assertEqualsWithDelta(
            array_column($sebelum, 'ketidakpastian_diperluas'),
            array_column($sesudah, 'ketidakpastian_diperluas'),
            1e-7,
        );
        $this->assertSame(9, RawMeasurement::where('calibration_session_id', $id)
            ->where('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)->count());
    }

    public function test_hitung_ulang_diam_untuk_sesi_yang_memenuhi_batas(): void
    {
        $id = $this->simpan($this->payloadMicrometer($this->alatMicrometer(), 5));

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id], '--dry-run' => true])
            ->doesntExpectOutputToContain('batas Instruksi Kerja')
            ->assertSuccessful();
    }

    public function test_hitung_ulang_menyebut_sesi_disetujui_sebagai_temuan_mutu(): void
    {
        [$alat, $std] = $this->alatTimer();
        $id = $this->simpan($this->payloadTimer($alat, $std, 2, 2));

        DB::table('calibration_sessions')->where('id', $id)->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id], '--dry-run' => true])
            ->expectsOutputToContain('pengulangan di bawah batas Instruksi Kerja (minimal 3 pembacaan per titik)')
            ->expectsOutputToContain('titik 1: 2 pembacaan')
            ->expectsOutputToContain('sudah disetujui sebelum batas ditegakkan')
            ->assertSuccessful();
    }
}
