<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\TabelStandarTekanan as TabelTekanan;
use App\Services\Calibration\TitikUkurSesi;
use App\Services\CalibrationValidator;
use App\Support\MicrometerMentah;
use App\Support\SiapHitungSesi;
use App\Support\TekananMentah as TM;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Titik ukur SESI (§55 `docs/permintaan-user-7.md`): teknisi menambah,
 * mengubah, dan menonaktifkan titik; set-nya tersimpan server dan sama di
 * semua perangkat. Empat pilot — Timer (saran), Timbangan (saran), Micrometer
 * (dipatok), Tekanan (bebas, maks 14).
 *
 * Yang digigit: sinkron lewat GET, 409 revisi basi, pembacaan titik nonaktif
 * tidak pernah hilang, titik nonaktif tidak dihitung (simpan, hitung ulang,
 * validator), batas per lembar, rentang alat, CMC menahan terbit, dan angka
 * titik aktif TIDAK berubah dibanding sesi tanpa set.
 */
class TitikUkurSesiTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Timer / Stopwatch — nominal saran
    // ------------------------------------------------------------------

    /** @return array{0: Equipment, 1: int|null} */
    private function alatTimer(): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', '015-CAL-424')->firstOrFail();
        $alat = Equipment::findOrFail($sesi->equipment_id);
        $alat->update(['range_min' => 0, 'range_max' => 5000]);

        return [$alat, $sesi->standard_id];
    }

    /** @return array<string, mixed> satu titik waktu, total milidetik */
    private static function titikWaktu(float $detik, array $offsetStd = [123, 211, 45], array $offsetUut = [131, 219, 61]): array
    {
        $ms = $detik * 1000;

        return [
            'titik_ukur' => $detik,
            'satuan' => 's',
            'standar' => array_map(static fn (int $o): float => $ms + $o, $offsetStd),
            'uut' => array_map(static fn (int $o): float => $ms + $o, $offsetUut),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $titik
     * @return array<string, mixed>
     */
    private static function setTitik(int $revisi, array $titik, array $pengulangan = []): array
    {
        return ['revisi' => $revisi, 'titik' => $titik, 'pengulangan' => $pengulangan];
    }

    /** @return array<string, mixed> */
    private static function t(string $id, float $nominal, array $lain = []): array
    {
        return ['id' => $id, 'tabel' => 'utama', 'nominal' => $nominal, 'asal' => 'profil', 'aktif' => true, ...$lain];
    }

    /** @return array<string, mixed> */
    private function payloadTimer(Equipment $alat, ?int $standar, array $measurements, ?array $titikSesi): array
    {
        return array_filter([
            'equipment_id' => $alat->id,
            'standard_id' => $standar,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => '2026-09-01',
            'measurements' => $measurements,
            'titik_sesi' => $titikSesi,
        ], static fn ($v): bool => $v !== null);
    }

    public function test_timer_tambah_nonaktif_sinkron_409_dan_angka_titik_aktif_tetap(): void
    {
        [$alat, $std] = $this->alatTimer();
        $ukur = [self::titikWaktu(60), self::titikWaktu(75, [100, 150, 120], [140, 160, 130]), self::titikWaktu(300, [90, 80, 70], [95, 85, 75])];
        $titik = [
            self::t('profil-utama-1', 60),
            self::t('pel-75', 75, ['asal' => 'pelanggan', 'alasan' => 'Permintaan pelanggan PO-123']),
            self::t('profil-utama-2', 300),
        ];

        // Perangkat A membuat sesi bersama set titiknya.
        $res = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, $ukur, self::setTitik(0, $titik)))
            ->assertCreated();
        $id = $res->json('data.id');
        $sesi = CalibrationSession::findOrFail($id);

        $this->assertSame(1, TitikUkurSesi::dariSesi($sesi)['revisi']);
        $this->assertCount(3, $sesi->uncertaintyCalculations);

        // Angka titik 60 s = master (sama dengan PayloadHpWaktuFrekuensiTest).
        $t60 = $sesi->uncertaintyCalculations->firstWhere('titik_ukur', 60.0);
        $this->assertEqualsWithDelta(60.137, (float) $t60->rata_rata, 5e-6);
        $this->assertEqualsWithDelta(-0.040666666, (float) $t60->koreksi, 5e-6);
        $this->assertEqualsWithDelta(0.81, (float) $t60->ketidakpastian_diperluas, 5e-6);

        // Perangkat B membaca set yang sama lewat GET.
        $get = $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$id}")->assertOk();
        $this->assertSame(1, $get->json('meta.titik_sesi.set.revisi'));
        $this->assertCount(3, $get->json('meta.titik_sesi.set.titik'));
        $this->assertTrue($get->json('meta.titik_sesi.batas.didukung'));
        $this->assertSame('saran', $get->json('meta.titik_sesi.batas.tabel.0.nominal'));

        $mentah300 = RawMeasurement::where('calibration_session_id', $id)->where('titik_ukur', 300)->count();
        $this->assertSame(6, $mentah300);

        // Menonaktifkan tanpa alasan → 422.
        $tanpaAlasan = $titik;
        $tanpaAlasan[2]['aktif'] = false;
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, self::setTitik(1, $tanpaAlasan)))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        // Perangkat A menonaktifkan 300 s dengan alasan.
        $nonaktif = $titik;
        $nonaktif[2]['aktif'] = false;
        $nonaktif[2]['alasan'] = 'Alat pelanggan macet di 300 s';
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, self::setTitik(1, $nonaktif)))
            ->assertOk()
            ->assertJsonPath('meta.titik_sesi.set.revisi', 2);

        $sesi->refresh()->load('uncertaintyCalculations', 'rawMeasurements');
        $this->assertSame(2, TitikUkurSesi::dariSesi($sesi)['revisi']);
        $this->assertCount(2, $sesi->uncertaintyCalculations, 'Titik nonaktif tidak boleh dihitung.');
        $this->assertNull($sesi->uncertaintyCalculations->firstWhere('titik_ukur', 300.0));
        $this->assertSame($mentah300, RawMeasurement::where('calibration_session_id', $id)->where('titik_ukur', 300)->count(),
            'Pembacaan titik nonaktif wajib tetap tersimpan.');

        $t60b = $sesi->uncertaintyCalculations->firstWhere('titik_ukur', 60.0);
        foreach (['rata_rata', 'koreksi', 'ketidakpastian_diperluas'] as $kolom) {
            $this->assertEqualsWithDelta((float) $t60->{$kolom}, (float) $t60b->{$kolom}, 1e-12, "{$kolom} titik aktif berubah");
        }

        // Jejak audit lama → baru + alasan.
        $audit = AuditLog::where('entity_type', 'calibration_sessions')->where('entity_id', $id)
            ->where('note', 'like', TitikUkurSesi::CATATAN_AUDIT.'%')->latest('id')->firstOrFail();
        $this->assertStringContainsString('nonaktifkan 300', $audit->note);
        $this->assertStringContainsString('macet', $audit->note);
        $this->assertTrue($audit->old_data['titik_sesi']['titik'][2]['aktif']);
        $this->assertFalse($audit->new_data['titik_sesi']['titik'][2]['aktif']);

        // Perangkat B yang masih memegang revisi 1 → 409 + set terbaru.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, self::setTitik(1, $titik)))
            ->assertStatus(409)
            ->assertJsonPath('kode', 'titik_sesi_konflik')
            ->assertJsonPath('titik_sesi.revisi', 2);

        // Pembacaan titik nonaktif diubah → 422; dihilangkan → 422.
        $ubah = $ukur;
        $ubah[2] = self::titikWaktu(300, [1, 2, 3], [4, 5, 6]);
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ubah, self::setTitik(2, $nonaktif)))
            ->assertStatus(422)->assertJsonValidationErrors('measurements');
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, array_slice($ukur, 0, 2), self::setTitik(2, $nonaktif)))
            ->assertStatus(422)->assertJsonValidationErrors('measurements');

        // Titik tidak bisa dihapus dari set.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, array_slice($ukur, 0, 2), self::setTitik(2, array_slice($nonaktif, 0, 2))))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        // Nominal titik yang SUDAH punya pembacaan tidak bisa diganti — aktif
        // (60 s) maupun nonaktif (300 s). Barisnya tidak boleh lepas dari titik.
        $mentahSebelum = RawMeasurement::where('calibration_session_id', $id)->orderBy('id')
            ->get(['titik_ukur', 'peran_sensor', 'sensor_ke', 'pembacaan'])->toArray();
        foreach ([0 => 61.0, 2 => 301.0] as $i => $nominalBaru) {
            $ganti = $nonaktif;
            $ganti[$i]['nominal'] = $nominalBaru;
            $ukurGanti = $ukur;
            $ukurGanti[$i]['titik_ukur'] = $nominalBaru;
            $this->actingAs($this->teknisi)
                ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukurGanti, self::setTitik(2, $ganti)))
                ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');
        }
        $this->assertSame($mentahSebelum, RawMeasurement::where('calibration_session_id', $id)->orderBy('id')
            ->get(['titik_ukur', 'peran_sensor', 'sensor_ke', 'pembacaan'])->toArray(), 'Pembacaan tersimpan berubah.');

        // Perubahan set tanpa `measurements` → 422 (hasil hitung harus ikut).
        $justifikasi = $nonaktif;
        $justifikasi[1]['alasan'] = 'Permintaan pelanggan PO-123 rev. 2';
        $tanpaUkur = $this->payloadTimer($alat, $std, $ukur, self::setTitik(2, $justifikasi));
        unset($tanpaUkur['measurements']);
        $this->actingAs($this->teknisi)->putJson("/api/calibrations/{$id}", $tanpaUkur)
            ->assertStatus(422)->assertJsonValidationErrors('measurements');

        // Dua penulis berurutan dari revisi yang sama: yang pertama 200
        // (revisi 3), yang kedua 409 — dan set tidak ditimpa.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, self::setTitik(2, $justifikasi)))
            ->assertOk()->assertJsonPath('meta.titik_sesi.set.revisi', 3);
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, self::setTitik(2, $nonaktif)))
            ->assertStatus(409)->assertJsonPath('titik_sesi.revisi', 3);
        $this->assertSame('Permintaan pelanggan PO-123 rev. 2',
            TitikUkurSesi::dariSesi($sesi->fresh())['titik'][1]['alasan']);

        // Simpan tanpa `titik_sesi` (APK lama) tidak menyentuh set.
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, null))
            ->assertOk();
        $sesi->refresh();
        $this->assertSame(3, TitikUkurSesi::dariSesi($sesi)['revisi']);
        $this->assertCount(2, $sesi->uncertaintyCalculations()->get());

        // Jalur hitung ulang & validator melihat titik aktif saja.
        $sesi->load('rawMeasurements.standard', 'uncertaintyCalculations', 'equipment');
        $siap = SiapHitungSesi::dari($sesi);
        $this->assertSame([60.0, 75.0], collect($siap)->pluck('titik_ukur')->map(fn ($v): float => (float) $v)->sort()->values()->all());

        $temuan = app(CalibrationValidator::class)->periksa($sesi->fresh());
        $kode = array_column($temuan['temuan'], 'kode');
        $this->assertNotContains('titik_tidak_terhitung', $kode, 'Titik nonaktif bukan "data kurang".');
        $this->assertNotContains('hitung_ulang_beda', $kode);
    }

    public function test_timer_titik_baru_tidak_terdaftar_dan_di_luar_rentang_ditolak(): void
    {
        [$alat, $std] = $this->alatTimer();
        $titik = [self::t('profil-utama-1', 60)];

        // Pembacaan di nominal yang tidak ada di set → 422.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, [self::titikWaktu(60), self::titikWaktu(120)], self::setTitik(0, $titik)))
            ->assertStatus(422)->assertJsonValidationErrors('measurements');

        // Nominal di luar rentang alat (0–5000 s) → 422.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, [self::titikWaktu(60)], self::setTitik(0, [
                ...$titik, self::t('x', 9000, ['asal' => 'teknisi']),
            ])))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        // Nominal kembar → 422.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, [self::titikWaktu(60)], self::setTitik(0, [
                ...$titik, self::t('y', 60.0, ['asal' => 'teknisi']),
            ])))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        // Lebih dari 10 titik aktif (BARIS_KERTAS) → 422.
        $sebelas = array_map(fn (int $i): array => self::t("t{$i}", 100.0 + $i, ['asal' => 'teknisi']), range(1, 11));
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, [], self::setTitik(0, $sebelas)))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        // Pelanggan tanpa alasan → 422.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, [self::titikWaktu(60)], self::setTitik(0, [
                ...$titik, self::t('p', 75, ['asal' => 'pelanggan']),
            ])))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');
    }

    public function test_timer_pengulangan_tidak_boleh_membuang_pembacaan(): void
    {
        [$alat, $std] = $this->alatTimer();
        $titik = [self::t('profil-utama-1', 60)];

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, [self::titikWaktu(60)], self::setTitik(0, $titik, ['utama' => 3])))
            ->assertCreated()->json('data.id');

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, [self::titikWaktu(60)], self::setTitik(1, $titik, ['utama' => 2])))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, [self::titikWaktu(60)], self::setTitik(1, $titik, ['utama' => 11])))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, [self::titikWaktu(60)], self::setTitik(1, $titik, ['utama' => 5])))
            ->assertOk();

        $this->assertSame(['utama' => 5], TitikUkurSesi::dariSesi(CalibrationSession::findOrFail($id))['pengulangan']);
    }

    public function test_timer_titik_di_luar_cmc_menahan_terbit_sampai_dijustifikasi(): void
    {
        [$alat, $std] = $this->alatTimer();
        CalibrationCapability::create([
            'organization_id' => $alat->organization_id,
            'equipment_category_id' => $alat->equipment_category_id,
            'nama_alat' => $alat->nama_alat_kemampuan,
            'range_min' => 5, 'range_max' => 3600, 'satuan' => 's',
            'ketidakpastian_terbaik' => 0.81, 'satuan_ketidakpastian' => 's',
        ]);

        $ukur = [self::titikWaktu(60), self::titikWaktu(4000)];
        $titik = [self::t('profil-utama-1', 60), self::t('besar', 4000, ['asal' => 'teknisi'])];

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimer($alat, $std, $ukur, self::setTitik(0, $titik)))
            ->assertCreated()->json('data.id');

        $periksa = fn (): array => app(CalibrationValidator::class)->periksa(CalibrationSession::findOrFail($id));

        $hasil = $periksa();
        $cmc = collect($hasil['temuan'])->firstWhere('kode', 'titik_di_luar_cmc');
        $this->assertNotNull($cmc);
        $this->assertSame(CalibrationValidator::ERROR, $cmc['tingkat']);
        $this->assertFalse($hasil['boleh_terbit']);

        $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$id}")
            ->assertJsonPath('meta.titik_sesi.di_luar_cmc', ['besar']);

        // Dijustifikasi → turun jadi PERINGATAN (admin melanjutkan sadar).
        $titik[1]['justifikasi_cmc'] = 'Disepakati pelanggan, dilaporkan di luar lingkup akreditasi';
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, self::setTitik(1, $titik)))
            ->assertOk();
        $cmc = collect($periksa()['temuan'])->firstWhere('kode', 'titik_di_luar_cmc');
        $this->assertSame(CalibrationValidator::PERINGATAN, $cmc['tingkat']);

        // Dinonaktifkan → temuan hilang.
        $titik[1]['aktif'] = false;
        $titik[1]['alasan'] = 'Di luar lingkup akreditasi';
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTimer($alat, $std, $ukur, self::setTitik(2, $titik)))
            ->assertOk();
        $this->assertNull(collect($periksa()['temuan'])->firstWhere('kode', 'titik_di_luar_cmc'));
    }

    public function test_lembar_kerja_memuat_batas_dan_titik_bawaan(): void
    {
        [$alat] = $this->alatTimer();

        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->assertJsonPath('data.batas_titik.didukung', true)
            ->assertJsonPath('data.batas_titik.tabel.0.maks', 10)
            ->assertJsonPath('data.titik_sesi_bawaan.0.id', 'profil-utama-1')
            ->assertJsonPath('data.titik_sesi_bawaan.0.nominal', 60);
    }

    // ------------------------------------------------------------------
    // Timbangan — nominal saran
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payloadTimbangan(Equipment $alat, ?array $titikSesi): array
    {
        return array_filter([
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 26.1, 'suhu_akhir' => 26.0,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 52,
            'measurements' => [
                ['titik_ukur' => 10, 'nominal' => [10.0], 'z1' => 0, 'm' => 10, 'm_aksen' => 10, 'z2' => 0],
                ['titik_ukur' => 20, 'nominal' => [20.0], 'z1' => 0, 'm' => 20.02, 'm_aksen' => 20, 'z2' => 0],
                ['titik_ukur' => 30, 'nominal' => [20.0, 10.0], 'z1' => 0, 'm' => 30, 'm_aksen' => 30.02, 'z2' => 0],
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
            'titik_sesi' => $titikSesi,
        ], static fn ($v): bool => $v !== null);
    }

    public function test_timbangan_nonaktif_tidak_menggeser_titik_lain_dan_pembacaan_tetap(): void
    {
        $alat = Equipment::where('serial_number', 'TB-100')->firstOrFail();

        // Sesi rujukan TANPA set titik — perilaku lama.
        $idLama = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimbangan($alat, null))
            ->assertCreated()->json('data.id');
        $lama = CalibrationSession::findOrFail($idLama)->uncertaintyCalculations()->get()->keyBy(fn ($t): string => (string) (float) $t->titik_ukur);
        $this->assertCount(3, $lama);

        $titik = [
            ['id' => 'a', 'tabel' => 'akurasi', 'nominal' => 10, 'asal' => 'teknisi'],
            ['id' => 'b', 'tabel' => 'akurasi', 'nominal' => 20, 'asal' => 'teknisi', 'aktif' => false, 'alasan' => 'Keping 20 kg retak'],
            ['id' => 'c', 'tabel' => 'akurasi', 'nominal' => 30, 'asal' => 'teknisi'],
        ];

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTimbangan($alat, self::setTitik(0, $titik)))
            ->assertCreated()->json('data.id');
        $sesi = CalibrationSession::findOrFail($id);
        $baru = $sesi->uncertaintyCalculations()->get();

        $this->assertCount(2, $baru);
        $this->assertSame(5, RawMeasurement::where('calibration_session_id', $id)->where('titik_ukur', 20)->count(),
            'Empat pembacaan + satu slot nominal titik nonaktif tetap tersimpan.');

        foreach ($baru as $t) {
            $rujukan = $lama[(string) (float) $t->titik_ukur];
            foreach (['koreksi', 'ketidakpastian_diperluas', 'standar_deviasi'] as $kolom) {
                $this->assertEqualsWithDelta((float) $rujukan->{$kolom}, (float) $t->{$kolom}, 1e-12,
                    "Timbangan titik {$t->titik_ukur}: {$kolom} berubah karena titik lain dinonaktifkan");
            }
        }
    }

    public function test_timbangan_substitusi_tidak_boleh_menonaktifkan(): void
    {
        $alat = Equipment::where('serial_number', 'TB-100')->firstOrFail();
        $batas = app(CalibrationProfileRegistry::class)->untukAlat($alat)
            ->batasTitik($alat, ['varian_master' => 'substitusi', 'satuan' => 'kg', 'kapasitas' => 2000]);

        $this->assertTrue($batas['didukung']);
        $this->assertFalse($batas['tabel'][0]['boleh_nonaktif']);
        $this->assertNull($batas['tabel'][0]['pengulangan']);
    }

    // ------------------------------------------------------------------
    // Micrometer — nominal dipatok kertas
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payloadMicrometer(Equipment $alat, ?array $titikSesi): array
    {
        return array_filter([
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 20.5, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 41, 'kelembaban_akhir' => 40,
            'measurements' => [
                ['titik_ukur' => 25.0, 'pembacaan' => [25.001, 25.0, 25.001, 25.0, 25.001]],
                ['titik_ukur' => 27.5, 'pembacaan' => [27.502, 27.501, 27.502, 27.501, 27.502]],
                ['titik_ukur' => 31.0, 'pembacaan' => [31.003, 31.002, 31.003, 31.002, 31.003]],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '25-50', 'kapasitas' => '50', 'resolusi' => '0.001',
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 50.0,
                    'resolusi_mm' => 0.001,
                    'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0],
                ],
            ],
            'titik_sesi' => $titikSesi,
        ], static fn ($v): bool => $v !== null);
    }

    public function test_micrometer_dipatok_nonaktif_boleh_tambah_dan_ubah_ditolak(): void
    {
        $alat = Equipment::where('serial_number', 'ZQ-100')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);
        $spek = $this->payloadMicrometer($alat, null)['spesifikasi_alat'];

        $bawaan = $profil->titikSesiBawaan($alat, $spek);
        $this->assertCount(11, $bawaan);
        $this->assertEqualsWithDelta(27.5, $bawaan[1]['nominal'], 1e-9);

        $idLama = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadMicrometer($alat, null))
            ->assertCreated()->json('data.id');
        $lama = CalibrationSession::findOrFail($idLama)->uncertaintyCalculations()->get()->keyBy(fn ($t): string => (string) (float) $t->titik_ukur);

        $set = $bawaan;
        $set[1]['aktif'] = false;
        $set[1]['alasan'] = 'Balok 27,5 sedang dikalibrasi ulang';

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadMicrometer($alat, self::setTitik(0, $set)))
            ->assertCreated()->json('data.id');
        $baru = CalibrationSession::findOrFail($id)->uncertaintyCalculations()->get();

        $this->assertCount(2, $baru);
        $this->assertSame(5, RawMeasurement::where('calibration_session_id', $id)->where('titik_ukur', 27.5)
            ->where('peran_sensor', MicrometerMentah::PERAN_PEMBACAAN)->count());

        foreach ($baru as $t) {
            $rujukan = $lama[(string) (float) $t->titik_ukur];
            foreach (['koreksi', 'ketidakpastian_diperluas'] as $kolom) {
                $this->assertEqualsWithDelta((float) $rujukan->{$kolom}, (float) $t->{$kolom}, 1e-12,
                    "Micrometer titik {$t->titik_ukur}: {$kolom} berubah");
            }
        }

        // Nominal kertas tidak bisa diubah, dan tidak bisa menambah titik.
        $ubah = $bawaan;
        $ubah[0]['nominal'] = 25.5;
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadMicrometer($alat, self::setTitik(0, $ubah)))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        $tambah = [...$bawaan, ['id' => 'ekstra', 'tabel' => 'utama', 'nominal' => 40.0, 'asal' => 'pelanggan', 'alasan' => 'PO pelanggan']];
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadMicrometer($alat, self::setTitik(0, $tambah)))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');
    }

    // ------------------------------------------------------------------
    // Tekanan — nominal bebas, maks 14, pengulangan dipatok 3
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payloadTekanan(Equipment $alat, ?array $titikSesi): array
    {
        return array_filter([
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => '2026-07-16',
            'suhu_awal' => '25,1', 'suhu_akhir' => 25,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 52,
            'measurements' => [
                ['titik_ukur' => '0', TM::PERAN_UP => ['0,01', '0', '0'], TM::PERAN_DOWN => ['0', '0', '0']],
                ['titik_ukur' => '5', TM::PERAN_UP => ['5,01', '5', '5,01'], TM::PERAN_DOWN => ['5', '5', '5,02']],
                ['titik_ukur' => '10', TM::PERAN_UP => ['10,03', '10', '10,02'], TM::PERAN_DOWN => ['10', '10,01', '10']],
            ],
            'spesifikasi_alat' => [
                TM::KUNCI_SESI => [
                    'varian' => TabelTekanan::SPMK, 'satuan' => 'kg/cm2', 'tampilan' => TM::TAMPILAN_ANALOG,
                    'rasio_jarum' => '1/2', 'resolusi' => '0,5', 'kapasitas' => '20', 'media' => 2,
                    'tinggi_standar' => '0,18', 'tinggi_uut' => '0', 'beda_tinggi' => '0,18',
                ],
            ],
            'titik_sesi' => $titikSesi,
        ], static fn ($v): bool => $v !== null);
    }

    public function test_tekanan_nonaktif_maks_empat_belas_dan_pengulangan_dipatok(): void
    {
        $alat = Equipment::where('serial_number', 'DEMO-PG-13G-001')->firstOrFail();
        $titik = [self::t('a', 0, ['asal' => 'teknisi']), self::t('b', 5, ['asal' => 'teknisi']), self::t('c', 10, ['asal' => 'teknisi'])];

        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTekanan($alat, self::setTitik(0, $titik)))
            ->assertCreated()->json('data.id');
        $this->assertCount(3, CalibrationSession::findOrFail($id)->uncertaintyCalculations()->get());

        $titik[1]['aktif'] = false;
        $titik[1]['alasan'] = 'Pelanggan hanya pakai 0 dan 10';
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTekanan($alat, self::setTitik(1, $titik)))
            ->assertOk();

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertSame([0.0, 10.0], $sesi->uncertaintyCalculations()->get()->pluck('titik_ukur')->map(fn ($v): float => (float) $v)->sort()->values()->all());
        $this->assertSame(6, RawMeasurement::where('calibration_session_id', $id)->where('titik_ukur', 5)->count());

        // Budget sesi Tekanan dihitung ulang tanpa titik nonaktif — jalur
        // hitung ulang sepakat dengan yang tersimpan.
        $kode = array_column(app(CalibrationValidator::class)->periksa($sesi)['temuan'], 'kode');
        $this->assertNotContains('hitung_ulang_beda', $kode);
        $this->assertNotContains('titik_tidak_terhitung', $kode);

        // Titik pertama Tekanan dikunci aktif (zero error dibaca darinya).
        $pertamaMati = $titik;
        $pertamaMati[0]['aktif'] = false;
        $pertamaMati[0]['alasan'] = 'Coba matikan titik nol';
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTekanan($alat, self::setTitik(2, $pertamaMati)))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        // ...dan wajib jadi baris pertama kiriman.
        $tertukar = $this->payloadTekanan($alat, self::setTitik(2, $titik));
        $tertukar['measurements'] = array_reverse($tertukar['measurements']);
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $tertukar)
            ->assertStatus(422)->assertJsonValidationErrors('measurements');

        // PUT tanpa `equipment_id`: ditolak di validasi request (kolom wajib),
        // bukan 404/500 dari pencarian alat titik sesi.
        $tanpaAlat = $this->payloadTekanan($alat, self::setTitik(2, $titik));
        unset($tanpaAlat['equipment_id']);
        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $tanpaAlat)
            ->assertStatus(422)->assertJsonValidationErrors('equipment_id');

        $limaBelas = array_map(fn (int $i): array => self::t("t{$i}", (float) $i, ['asal' => 'teknisi']), range(0, 14));
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadTekanan($alat, self::setTitik(0, $limaBelas)))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $this->payloadTekanan($alat, self::setTitik(2, $titik, ['utama' => 4])))
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');
    }

    // ------------------------------------------------------------------
    // Jalur DATAR (turunan generik dari bentuk lembar) — pH
    // ------------------------------------------------------------------

    public function test_jalur_datar_ph_nonaktif_dan_fase_sebelum(): void
    {
        $registry = app(CalibrationProfileRegistry::class);
        $alat = Equipment::all()->first(fn (Equipment $e): bool => $registry->untukAlat($e)->kode() === 'ph_meter');
        $this->assertNotNull($alat, 'seeder tidak punya alat pH');
        $std = CalibrationSession::where('equipment_id', $alat->id)->value('standard_id')
            ?? Standard::where('organization_id', $alat->organization_id)->value('id');
        $this->assertNotNull($std);

        $bawaan = $registry->untukAlat($alat)->titikSesiBawaan($alat, []);
        $nominal = array_column($bawaan, 'nominal');
        $this->assertCount(3, $nominal);

        $payload = fn (?array $set, bool $sebelum = false): array => array_filter([
            'equipment_id' => $alat->id,
            'standard_id' => $std,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => '2026-09-01',
            'suhu_awal' => 22.2, 'suhu_akhir' => 22.4,
            'measurements' => array_map(fn (float $n, int $i): array => array_filter([
                'titik_ukur' => $n,
                'standard_id' => $std,
                'pembacaan' => [$n + 0.02, $n + 0.03, $n + 0.02],
                'pembacaan_sebelum' => $sebelum && $i === 1 ? [$n + 0.1, $n + 0.1] : null,
            ], static fn ($v): bool => $v !== null), $nominal, array_keys($nominal)),
            'titik_sesi' => $set,
        ], static fn ($v): bool => $v !== null);

        $idLama = $this->actingAs($this->teknisi)->postJson('/api/calibrations', $payload(null))->assertCreated()->json('data.id');
        $this->assertSame(3, CalibrationSession::findOrFail($idLama)->uncertaintyCalculations()->count(), 'sesi rujukan pH tidak terhitung');
        $lama = CalibrationSession::findOrFail($idLama)->uncertaintyCalculations()->get()->keyBy(fn ($t): string => (string) (float) $t->titik_ukur);

        $set = $bawaan;
        $set[0]['aktif'] = false;
        $set[0]['alasan'] = 'Buffer pertama kedaluwarsa';

        // As-found di titik yang tidak memilih fase "sebelum" → 422.
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $payload(self::setTitik(0, $set), sebelum: true))
            ->assertStatus(422)->assertJsonValidationErrors('measurements');

        $set[1]['fase'] = ['sebelum', 'sesudah'];
        $res = $this->actingAs($this->teknisi)->postJson('/api/calibrations', $payload(self::setTitik(0, $set), sebelum: true))
            ->assertCreated();
        $id = $res->json('data.id');
        $baru = CalibrationSession::findOrFail($id)->uncertaintyCalculations()->get();

        $this->assertCount(2, $baru, json_encode([$res->json('meta.belum_dihitung'), count($lama)]));
        $this->assertSame(3, RawMeasurement::where('calibration_session_id', $id)->where('titik_ukur', $nominal[0])->count());

        foreach ($baru as $t) {
            $rujukan = $lama[(string) (float) $t->titik_ukur];
            foreach (['koreksi', 'ketidakpastian_diperluas', 'keputusan'] as $kolom) {
                $this->assertEquals($rujukan->{$kolom}, $t->{$kolom}, "pH titik {$t->titik_ukur}: {$kolom} berubah");
            }
        }
    }

    // ------------------------------------------------------------------
    // Sapuan profil + lembar tanpa dukungan
    // ------------------------------------------------------------------

    public function test_semua_profil_punya_batas_titik_yang_sah(): void
    {
        $alat = Equipment::where('serial_number', 'TB-100')->firstOrFail();

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $batas = $profil->batasTitik(new Equipment(['organization_id' => $alat->organization_id]), []);

            $this->assertIsBool($batas['didukung'], $profil->kode());

            if (in_array($profil->kode(), ['tits', 'centrifuge', 'tachometer'], true)) {
                $this->assertFalse($batas['didukung'], $profil->kode().' belum diverifikasi — harus tidak didukung');
            }

            if (! $batas['didukung']) {
                $this->assertNotEmpty($batas['alasan'], $profil->kode().': tidak didukung tanpa alasan');

                continue;
            }

            foreach ($batas['tabel'] as $t) {
                $this->assertContains($t['nominal'], ['dipatok', 'saran', 'bebas'], $profil->kode());
                $this->assertGreaterThanOrEqual($t['min'], $t['maks'], $profil->kode());
                $this->assertContains('sesudah', $t['fase'], $profil->kode());
                $this->assertLessThanOrEqual($t['maks'], count($t['bawaan']), $profil->kode().': bawaan melebihi maks');
            }
        }
    }

    public function test_lembar_tanpa_dukungan_menolak_titik_sesi(): void
    {
        $alat = Equipment::where('serial_number', 'ZQ-100')->firstOrFail();
        $kiriman = $this->payloadMicrometer($alat, self::setTitik(0, [self::t('a', 25.0)]));
        // Tanpa kapasitas, nominal Micrometer tidak bisa ditentukan.
        unset($kiriman['spesifikasi_alat']);
        $alat->update(['range_max' => null]);

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $kiriman)
            ->assertStatus(422)->assertJsonValidationErrors('titik_sesi');
    }
}
