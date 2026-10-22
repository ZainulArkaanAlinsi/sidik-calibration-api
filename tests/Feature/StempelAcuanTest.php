<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\PaketAcuanVersi;
use App\Models\UncertaintyCalculation;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\TabelStandarMicrometer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SesiDataAcuan;
use Tests\TestCase;

/**
 * FR-20 — setiap jalur yang MENYIMPAN `uncertainty_calculations` menstempel
 * `paket_acuan_versi_id` dengan versi yang benar-benar jadi lingkup
 * hitungannya; preview tidak menyimpan apa pun.
 *
 * Jalur simpan yang ada (grep 8 Okt 2026): `CalibrationController::
 * isiUlangPengukuran()` (dipanggil `store` & `update`) dan
 * `HitungUlangSesi`. Seeder menulis langsung tanpa stempel — sengaja.
 *
 * Invarian yang dijaga: angka dari versi DB ⇔ hasil berstempel versi itu.
 * Sesi Dial Indicator memakai balok ukur Micrometer tapi tidak pernah membuka
 * lingkup micrometer: stempelnya null dan angkanya sama dengan sumber JSON
 * walau versi 2 Micrometer yang berbeda sedang aktif.
 */
class StempelAcuanTest extends TestCase
{
    use RefreshDatabase;
    use SesiDataAcuan;

    /** `balok_ukur['19.0']` di JSON = 19,00011; titik 1 varian B = 6 + 19. */
    private const BALOK_19_LAIN = 19.00211;

    /** `balok_ukur['2.5']` di JSON = 2,50014; titik 2 & 3 Dial Indicator. */
    private const BALOK_2_5_LAIN = 2.50099;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanAcuan();

        // Payload Micrometer bertanggal 2025-05-02 harus jatuh di rentang v1.
        $this->assertTrue($this->v1->berlaku_mulai->lte(CarbonImmutable::parse('2025-05-02')));
    }

    public function test_semua_jalur_simpan_menstempel(): void
    {
        $v1 = $this->v1->id;

        // store
        $id = $this->simpanSesi($this->payloadMicrometer(['status' => 'draft']));
        $this->assertSame([$v1, $v1, $v1], $this->stempelSesi($id), 'store');
        $this->assertNotNull(UncertaintyCalculation::where('calibration_session_id', $id)->value('formula_version_id'));

        // update (teknisi menyunting draft-nya)
        $ubah = $this->payloadMicrometer(['status' => 'draft']);
        $ubah['measurements'][0]['pembacaan'] = [25.002, 25.001, 25.002, 25.001, 25.002];

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$id}", $ubah)
            ->assertSuccessful();

        $this->assertSame([$v1, $v1, $v1], $this->stempelSesi($id), 'update');

        // preview: tidak ada baris baru di mana pun
        $jumlahSesi = CalibrationSession::count();
        $jumlahHasil = UncertaintyCalculation::count();

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payloadMicrometer())
            ->assertSuccessful()
            ->assertJsonCount(3, 'data.titik');

        $this->assertSame($jumlahSesi, CalibrationSession::count(), 'preview tidak membuat sesi');
        $this->assertSame($jumlahHasil, UncertaintyCalculation::count(), 'preview tidak menyimpan hasil');

        // hitung ulang: stempel dihapus dulu supaya yang terlihat memang tulisan perintahnya
        UncertaintyCalculation::where('calibration_session_id', $id)->update(['paket_acuan_versi_id' => null]);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])
            ->expectsOutputToContain('data acuan: versi #'.$v1)
            ->assertSuccessful();

        $this->assertSame([$v1, $v1, $v1], $this->stempelSesi($id), 'hitung ulang');
    }

    public function test_dry_run_hitung_ulang_tidak_menulis_stempel(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer());
        UncertaintyCalculation::where('calibration_session_id', $id)->update(['paket_acuan_versi_id' => null]);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id], '--dry-run' => true])
            ->expectsOutputToContain('hasil tersimpan tanpa stempel')
            ->assertSuccessful();

        $this->assertSame([null, null, null], $this->stempelSesi($id));
    }

    /**
     * Sumber `db`/v1 dan sumber `json` menghasilkan angka IDENTIK — v1 adalah
     * berkas JSON yang dikanonikkan. Yang beda cuma stempelnya.
     */
    public function test_angka_sumber_db_v1_identik_dengan_sumber_json_dan_json_tanpa_stempel(): void
    {
        config(['acuan.sumber' => 'json']);
        $json = $this->simpanSesi($this->payloadMicrometer());

        config(['acuan.sumber' => 'db']);
        $db = $this->simpanSesi($this->payloadMicrometer());

        $this->assertSame([null, null, null], $this->stempelSesi($json), 'sumber json → stempel null');
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($db));

        $this->assertCount(3, $this->angkaSesi($db));
        $this->assertSame($this->angkaSesi($json), $this->angkaSesi($db));

        // Jalur hitung ulang juga identik antar sumber. Diadu hitung-ulang
        // lawan hitung-ulang: perintah itu menulis presisi penuh, sedangkan
        // `store` membulatkan ke presisi kolom (`bulatkanHitungan`) — beda
        // lama yang bukan urusan data acuan.
        config(['acuan.sumber' => 'json']);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$json]])->assertSuccessful();

        config(['acuan.sumber' => 'db']);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$db]])->assertSuccessful();
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($db));
        $this->assertSame($this->angkaSesi($json), $this->angkaSesi($db));

        // Hitung ulang dengan sumber json melepas stempelnya — stempel yang
        // menunjuk versi yang tidak dipakai lebih buruk daripada null.
        config(['acuan.sumber' => 'json']);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$db]])
            ->expectsOutputToContain('DATA_ACUAN_SUMBER bukan db')
            ->assertSuccessful();
        $this->assertSame([null, null, null], $this->stempelSesi($db));
        $this->assertSame($this->angkaSesi($json), $this->angkaSesi($db));
    }

    /**
     * Paritas versi 1 Dial Indicator (22 Okt 2026): sesudah paketnya diimpor,
     * sesi Dial berstempel versi dial_indicator dan angkanya IDENTIK dengan
     * jalur JSON — pindah ke SumberAcuan tidak menggeser angka apa pun.
     */
    public function test_dial_indicator_v1_db_identik_dengan_json(): void
    {
        $this->artisan('acuan:impor-awal', ['profil' => 'dial_indicator', '--organisasi' => $this->teknisi->organization_id])
            ->assertSuccessful();
        $v1Dial = PaketAcuanVersi::query()
            ->whereHas('paketAcuan', fn ($q) => $q->where('kode_profil', 'dial_indicator'))
            ->sole();

        config(['acuan.sumber' => 'json']);
        $json = $this->simpanSesi($this->payloadDialIndicator());

        config(['acuan.sumber' => 'db']);
        $db = $this->simpanSesi($this->payloadDialIndicator());

        $this->assertSame([null, null, null], $this->stempelSesi($json));
        $this->assertSame([$v1Dial->id, $v1Dial->id, $v1Dial->id], $this->stempelSesi($db));
        $this->assertSame($this->angkaSesi($json), $this->angkaSesi($db));

        config(['acuan.sumber' => 'json']);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$json]])->assertSuccessful();

        config(['acuan.sumber' => 'db']);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$db]])->assertSuccessful();
        $this->assertSame([$v1Dial->id, $v1Dial->id, $v1Dial->id], $this->stempelSesi($db));
        $this->assertSame($this->angkaSesi($json), $this->angkaSesi($db));
    }

    /**
     * Versi 2 Micrometer yang berbeda AKTIF pada tanggal sesi Dial Indicator:
     * sesi Dial tetap membaca JSON, stempel null, angka sama persis.
     */
    public function test_sesi_dial_indicator_tidak_membuka_lingkup_micrometer(): void
    {
        config(['acuan.sumber' => 'json']);
        $json = $this->simpanSesi($this->payloadDialIndicator());

        config(['acuan.sumber' => 'db']);
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2024-04-30']);
        $v2 = $this->versiBalokBeda(2, PaketAcuanVersi::STATUS_AKTIF, '2024-05-01', ['2.5' => self::BALOK_2_5_LAIN]);

        // Prasyarat supaya test ini bermakna: v2 memang berlaku pada tanggal
        // sesi Dial, dan di lingkup v2 balok 2,5 mm memang terbaca lain.
        $this->assertSame($v2->id, app(PenentuVersiAcuan::class)->untukProfil(
            (int) $this->teknisi->organization_id, 'micrometer', CarbonImmutable::parse('2024-05-06'),
        )?->id);
        $this->assertSame(
            self::BALOK_2_5_LAIN,
            app(SumberAcuan::class)->denganVersi($v2, fn () => (new TabelStandarMicrometer)->nilaiTerkoreksi(2.5)),
        );

        $db = $this->simpanSesi($this->payloadDialIndicator());

        $this->assertSame([null, null, null], $this->stempelSesi($db));
        $this->assertCount(3, $this->angkaSesi($db));
        $this->assertSame($this->angkaSesi($json), $this->angkaSesi($db));

        // Hitung ulang lawan hitung ulang (lihat test di atas soal pembulatan).
        config(['acuan.sumber' => 'json']);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$json]])->assertSuccessful();

        config(['acuan.sumber' => 'db']);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$db]])
            ->expectsOutputToContain('data acuan: berkas JSON') // Dial ber-skema (22 Okt 2026), paketnya belum diimpor
            ->assertSuccessful();
        $this->assertSame([null, null, null], $this->stempelSesi($db));
        $this->assertSame($this->angkaSesi($json), $this->angkaSesi($db));
    }

    public function test_sesi_sebelum_berlaku_mulai_v1_tanpa_stempel(): void
    {
        $sebelum = $this->v1->berlaku_mulai->copy()->subDay()->toDateString();

        $id = $this->simpanSesi($this->payloadMicrometer(['tanggal_kalibrasi' => $sebelum]));

        $this->assertSame([null, null, null], $this->stempelSesi($id));
    }

    /**
     * Preview dan simpan menghitung di lingkup versi yang SAMA: dengan v2 yang
     * berbeda aktif, angka preview = angka sesi tersimpan berstempel v2, dan
     * keduanya beda dari v1.
     */
    public function test_preview_memakai_versi_yang_sama_dengan_simpan(): void
    {
        $v1Sesi = $this->simpanSesi($this->payloadMicrometer());

        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2025-04-30']);
        $v2 = $this->versiBalokBeda(2, PaketAcuanVersi::STATUS_AKTIF, '2025-05-01', ['19.0' => self::BALOK_19_LAIN]);

        $preview = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payloadMicrometer())
            ->assertSuccessful()
            ->json('data.titik');

        $v2Sesi = $this->simpanSesi($this->payloadMicrometer());
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($v2Sesi));

        $tersimpan = $this->angkaSesi($v2Sesi);

        foreach ($preview as $i => $titik) {
            $this->assertSame($tersimpan[$i]['titik_ke'], $titik['titik_ke']);

            foreach (['koreksi', 'ketidakpastian_diperluas', 'rata_rata'] as $kolom) {
                $this->assertEqualsWithDelta((float) $tersimpan[$i][$kolom], (float) $titik[$kolom], 1e-9, "preview {$kolom} titik {$titik['titik_ke']}");
            }
        }

        // Titik 1 (6 + 19 mm) memang bergeser ±0,002 mm di v2.
        $this->assertEqualsWithDelta(
            0.002,
            abs((float) $this->angkaSesi($v1Sesi)[0]['koreksi'] - (float) $tersimpan[0]['koreksi']),
            1e-6,
        );
    }
}
