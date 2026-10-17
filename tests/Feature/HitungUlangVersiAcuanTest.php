<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\CalibrationValidator;
use App\Support\JsonKanonik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SesiDataAcuan;
use Tests\TestCase;

/**
 * T1.8 — `kalibrasi:hitung-ulang` dan `CalibrationValidator` memakai versi
 * data acuan dari STEMPEL hasil tersimpan, bukan versi aktif hari ini
 * (BR-10, BR-11; `PenentuVersiAcuan::untukSesiTersimpan`).
 *
 * Skenario tetap: v1 (impor awal) berlaku sampai 2025-05-31, v2 — balok ukur
 * 19 mm digeser 2 µm, jadi titik 1 varian B (6 + 19 mm) bergeser — aktif
 * sejak 2025-06-01.
 *
 * Angka hitung ulang diadu dengan angka hitung ulang (bukan dengan `store`):
 * perintah itu menulis presisi penuh, `store` membulatkan ke presisi kolom.
 */
class HitungUlangVersiAcuanTest extends TestCase
{
    use RefreshDatabase;
    use SesiDataAcuan;

    private const V2_MULAI = '2025-06-01';

    private const V1_SAMPAI = '2025-05-31';

    /** `balok_ukur['19.0']` di JSON = 19,00011. */
    private const BALOK_19_LAIN = 19.00211;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanAcuan();
    }

    /** v1 ditutup, v2 berbeda aktif sejak [V2_MULAI]. */
    private function aktifkanV2(): PaketAcuanVersi
    {
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => self::V1_SAMPAI]);

        return $this->versiBalokBeda(2, PaketAcuanVersi::STATUS_AKTIF, self::V2_MULAI, ['19.0' => self::BALOK_19_LAIN]);
    }

    private function hitungUlang(int $id, ?string $keluaran = null): void
    {
        $perintah = $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]]);

        if ($keluaran !== null) {
            $perintah->expectsOutputToContain($keluaran);
        }

        $perintah->assertSuccessful();
    }

    /**
     * Payload Micrometer + standar balok ukur hasil seeder (`MicrometerSeeder`,
     * "Gauge Block Standard"). Tanpa standar, validator melewati hitung ulang
     * tiap titik (`standar_titik_hilang`) dan test validator di bawah jadi
     * hampa — tidak ada angka yang diadu.
     *
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function payloadBerstandar(array $ganti = []): array
    {
        return $this->payloadMicrometer([
            'standard_id' => Standard::query()
                ->where('organization_id', $this->teknisi->organization_id)
                ->where('nama', 'Gauge Block Standard')
                ->valueOrFail('id'),
            ...$ganti,
        ]);
    }

    /** Validator pada sesi ini benar-benar menghitung ulang ketiga titiknya. */
    private function assertDihitungUlang(array $hasil): void
    {
        $this->assertNotContains('standar_titik_hilang', self::kode($hasil));
        $this->assertNotContains('hitung_ulang_gagal', self::kode($hasil));
    }

    /** @return array{valid: bool, boleh_terbit: bool, temuan: list<array<string, mixed>>, ringkasan: array<string, int>} */
    private function periksa(int $id): array
    {
        return app(CalibrationValidator::class)->periksa(CalibrationSession::findOrFail($id));
    }

    /** @return list<string> */
    private static function kode(array $hasil, ?string $tingkat = null): array
    {
        return collect($hasil['temuan'])
            ->when($tingkat !== null, fn ($c) => $c->where('tingkat', $tingkat))
            ->pluck('kode')
            ->values()
            ->all();
    }

    public function test_sesi_bertanggal_sebelum_v2_tanpa_stempel_dihitung_ulang_dengan_v1(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer()); // 2025-05-02 < V2_MULAI
        $this->hitungUlang($id);
        $angkaV1 = $this->angkaSesi($id);

        // Stempel dicabut: yang memutuskan sekarang tanggal sesi, bukan stempel.
        UncertaintyCalculation::where('calibration_session_id', $id)->update(['paket_acuan_versi_id' => null]);
        $this->aktifkanV2();

        $this->hitungUlang($id, 'hasil tersimpan tanpa stempel — memakai versi yang berlaku pada tanggal kalibrasi (#'.$this->v1->id);

        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id));
        $this->assertSame($angkaV1, $this->angkaSesi($id));
    }

    public function test_stempel_v1_menang_atas_v2_untuk_sesi_bertanggal_sesudah_v2_lalu_v1_ditarik(): void
    {
        $payload = $this->payloadMicrometer(['tanggal_kalibrasi' => '2025-07-01']); // >= V2_MULAI

        // Disimpan waktu v1 masih satu-satunya versi.
        $id = $this->simpanSesi($payload);
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id));
        $this->hitungUlang($id);
        $angkaV1 = $this->angkaSesi($id);

        $v2 = $this->aktifkanV2();

        // Pembanding: sesi yang sama disimpan SEKARANG distempel v2, dan
        // titik 1-nya memang bergeser — jadi kesamaan di bawah bermakna.
        $pembanding = $this->simpanSesi($payload);
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($pembanding));
        $this->hitungUlang($pembanding);
        $angkaV2 = $this->angkaSesi($pembanding);
        $this->assertEqualsWithDelta(0.002, abs($angkaV1[0]['koreksi'] - $angkaV2[0]['koreksi']), 1e-6);

        // Stempel menang: hitung ulang tetap v1 walau tanggalnya masuk rentang v2.
        $this->hitungUlang($id, 'stempel hasil tersimpan (#'.$this->v1->id);
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id));
        $this->assertSame($angkaV1, $this->angkaSesi($id));

        // v1 ditarik → versi yang berlaku pada tanggal sesi (v2), stempel ikut berubah.
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_DITARIK]);

        $this->hitungUlang($id, 'ditarik tidak dipakai lagi — memakai versi yang berlaku pada tanggal kalibrasi (#'.$v2->id);
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($id));
        $this->assertSame($angkaV2, $this->angkaSesi($id));
    }

    public function test_dry_run_tidak_mengganti_stempel_versi_ditarik(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer(['tanggal_kalibrasi' => '2025-07-01']));
        $this->aktifkanV2();
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_DITARIK]);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id], '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id));
    }

    public function test_validator_sesi_berstempel_v1_tidak_berubah_saat_v2_aktif(): void
    {
        $id = $this->simpanSesi($this->payloadBerstandar(['tanggal_kalibrasi' => '2025-07-01']));
        $sebelum = $this->periksa($id);

        $this->assertDihitungUlang($sebelum);

        $this->aktifkanV2();
        $sesudah = $this->periksa($id);
        $this->assertDihitungUlang($sesudah);

        // Tidak ada selisih palsu dari v2 dan tidak ada ERROR baru.
        $this->assertSame($sebelum['temuan'], $sesudah['temuan']);
        $this->assertSame($sebelum['ringkasan'][CalibrationValidator::ERROR], $sesudah['ringkasan'][CalibrationValidator::ERROR]);
        $this->assertNotContains('hitung_ulang_beda', self::kode($sesudah));
        $this->assertEmpty(array_filter(self::kode($sesudah), static fn (string $k): bool => str_starts_with($k, 'versi_acuan')));
    }

    public function test_validator_info_bila_hasil_tanpa_stempel_padahal_ada_versi_berlaku(): void
    {
        config(['acuan.sumber' => 'json']);
        $id = $this->simpanSesi($this->payloadBerstandar());
        $this->assertSame([null, null, null], $this->stempelSesi($id));
        $json = $this->periksa($id);
        $this->assertNotContains('versi_acuan_tanpa_stempel', self::kode($json), 'sumber json: diam');

        config(['acuan.sumber' => 'db']);
        $db = $this->periksa($id);
        $this->assertDihitungUlang($db);

        $this->assertContains('versi_acuan_tanpa_stempel', self::kode($db, CalibrationValidator::INFO));
        $this->assertSame(
            $this->v1->id,
            collect($db['temuan'])->firstWhere('kode', 'versi_acuan_tanpa_stempel')['konteks']['versi_berlaku_id'],
        );
        // v1 = JSON → tidak ada selisih, dan INFO tidak menambah ERROR/PERINGATAN.
        $this->assertNotContains('hitung_ulang_beda', self::kode($db));
        $this->assertSame($json['ringkasan'][CalibrationValidator::ERROR], $db['ringkasan'][CalibrationValidator::ERROR]);
        $this->assertSame($json['ringkasan'][CalibrationValidator::PERINGATAN], $db['ringkasan'][CalibrationValidator::PERINGATAN]);
        $this->assertSame($json['boleh_terbit'], $db['boleh_terbit']);

        // Sesi tanpa versi berlaku (sebelum v1) tetap diam.
        $lama = $this->simpanSesi($this->payloadBerstandar([
            'tanggal_kalibrasi' => $this->v1->berlaku_mulai->copy()->subDay()->toDateString(),
        ]));
        $this->assertNotContains('versi_acuan_tanpa_stempel', self::kode($this->periksa($lama)));
    }

    public function test_validator_peringatan_bila_stempel_menunjuk_versi_ditarik(): void
    {
        $id = $this->simpanSesi($this->payloadBerstandar(['tanggal_kalibrasi' => '2025-07-01']));
        $sebelum = $this->periksa($id);

        $v2 = $this->aktifkanV2();
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_DITARIK, 'catatan_keputusan' => 'Sertifikat balok 19 mm salah salin.']);

        $hasil = $this->periksa($id);
        $this->assertDihitungUlang($hasil);

        $this->assertContains('versi_acuan_ditarik', self::kode($hasil, CalibrationValidator::PERINGATAN));
        $temuan = collect($hasil['temuan'])->firstWhere('kode', 'versi_acuan_ditarik');
        $this->assertSame($this->v1->id, $temuan['konteks']['paket_acuan_versi_id']);
        $this->assertSame($v2->id, $temuan['konteks']['versi_berlaku_id']);
        $this->assertStringContainsString('Sertifikat balok 19 mm salah salin.', $temuan['pesan']);

        // Diperiksa ulang dengan v2: titik 1 (6 + 19 mm) memang beda dari yang tersimpan.
        $this->assertContains(1, collect($hasil['temuan'])->where('kode', 'hitung_ulang_beda')->pluck('konteks.titik_ke')->unique()->all());

        // P1 tidak menambah ERROR.
        $this->assertSame($sebelum['ringkasan'][CalibrationValidator::ERROR], $hasil['ringkasan'][CalibrationValidator::ERROR]);
        $this->assertSame($sebelum['boleh_terbit'], $hasil['boleh_terbit']);
    }

    /**
     * Tinjau P1 S-4: sumber `json` + hasil berstempel → stempel memang dilepas
     * (angkanya dari JSON), tapi keluaran perintah WAJIB memperingatkannya,
     * menyebut versi yang dilepas. Tanpa prompt (non-interaktif).
     */
    public function test_sumber_json_melepas_stempel_dengan_peringatan(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer());
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id));

        config(['acuan.sumber' => 'json']);
        $label = '#'.$this->v1->id.' micrometer v1, aktif';

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id], '--dry-run' => true])
            ->expectsOutputToContain("PERINGATAN: stempel data acuan {$label} bakal DILEPAS — DATA_ACUAN_SUMBER = \"json\", bukan \"db\"")
            ->assertSuccessful();
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id), 'dry-run tidak menulis');

        $this->hitungUlang($id, "PERINGATAN: stempel data acuan {$label} DILEPAS — DATA_ACUAN_SUMBER = \"json\", bukan \"db\"");
        $this->assertSame([null, null, null], $this->stempelSesi($id));

        // Tidak ada lagi yang berstempel → tidak ada peringatan.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])
            ->doesntExpectOutputToContain('PERINGATAN: stempel data acuan')
            ->assertSuccessful();
    }

    /** Sumber `db`: stempel yang dipakai ulang tidak memicu peringatan S-4. */
    public function test_sumber_db_tanpa_peringatan_lepas_stempel(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer());

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])
            ->doesntExpectOutputToContain('PERINGATAN: stempel data acuan')
            ->assertSuccessful();
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id));
    }

    /**
     * T-2: stempel TIDAK SERAGAM antar baris hasil → tidak dipercaya; versi
     * yang berlaku pada tanggal sesi (v2) yang dipilih.
     */
    public function test_stempel_tidak_seragam_memakai_versi_berlaku_pada_tanggal(): void
    {
        $id = $this->simpanSesi($this->payloadBerstandar(['tanggal_kalibrasi' => '2025-07-01']));
        $v2 = $this->aktifkanV2();

        UncertaintyCalculation::query()
            ->where('calibration_session_id', $id)
            ->where('titik_ke', 1)
            ->update(['paket_acuan_versi_id' => null]);
        $this->assertSame([null, $this->v1->id, $this->v1->id], $this->stempelSesi($id));

        $pilihan = app(PenentuVersiAcuan::class)->untukSesiTersimpan(CalibrationSession::findOrFail($id));
        $this->assertSame($v2->id, $pilihan['versi']?->id);
        $this->assertNull($pilihan['stempel']);
        $this->assertStringContainsString('stempel hasil tersimpan tidak seragam (', $pilihan['alasan']);
        $this->assertStringContainsString(
            'memakai versi yang berlaku pada tanggal kalibrasi (#'.$v2->id.' micrometer v2, aktif)',
            $pilihan['alasan'],
        );

        // Validator: diperiksa dengan v2, padahal angka tersimpan dihitung v1 —
        // titik yang memakai balok 19 mm (titik 1 = 6+19, titik 2 = 2,5+6+19)
        // muncul sebagai `hitung_ulang_beda`. Tidak ada temuan `versi_acuan_*`
        // yang menyebut stempel tidak seragam (lihat laporan perbaikan P1).
        $hasil = $this->periksa($id);
        $this->assertDihitungUlang($hasil);
        $this->assertSame([], self::kodeVersiAcuan($hasil));
        $this->assertSame([1, 2], self::titikBeda($hasil));

        $this->hitungUlang($id, 'tidak seragam');
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($id));
        $this->assertSame([], self::titikBeda($this->periksa($id)), 'sesudah hitung ulang konsisten dengan v2');
    }

    /**
     * T-2: stempel yang menunjuk versi milik ORGANISASI LAIN, atau versi PAKET
     * LAIN di organisasi yang sama, tidak dipakai — versi berlaku pada tanggal
     * sesi (v1) yang dipilih. Isi versi asing sengaja dibuat beda (balok 19 mm
     * digeser) supaya pemakaiannya pasti ketahuan dari angkanya.
     */
    public function test_stempel_milik_organisasi_atau_paket_lain_diabaikan(): void
    {
        $id = $this->simpanSesi($this->payloadBerstandar()); // 2025-05-02, distempel v1
        $this->hitungUlang($id);
        $angkaV1 = $this->angkaSesi($id);
        $sebelum = $this->periksa($id);
        $this->assertDihitungUlang($sebelum);

        $labLain = Organization::factory()->create();
        $asing = [
            'organisasi lain' => $this->versiBalokBedaDiPaket(PaketAcuan::create([
                'organization_id' => $labLain->id,
                'kode_profil' => 'micrometer',
                'nama' => 'Micrometer',
                'skema_versi' => 1,
            ])),
            'paket lain' => $this->versiBalokBedaDiPaket(PaketAcuan::create([
                'organization_id' => $this->teknisi->organization_id,
                'kode_profil' => 'paket-uji-lain',
                'nama' => 'Paket uji lain',
                'skema_versi' => 1,
            ])),
        ];

        foreach ($asing as $kasus => $versiAsing) {
            UncertaintyCalculation::where('calibration_session_id', $id)->update(['paket_acuan_versi_id' => $versiAsing->id]);

            $pilihan = app(PenentuVersiAcuan::class)->untukSesiTersimpan(CalibrationSession::findOrFail($id));
            $this->assertSame($this->v1->id, $pilihan['versi']?->id, $kasus);
            $this->assertSame($versiAsing->id, $pilihan['stempel']?->id, $kasus);
            $this->assertStringContainsString('bukan milik paket profil/organisasi sesi ini', $pilihan['alasan'], $kasus);
            $this->assertStringContainsString('memakai versi yang berlaku pada tanggal kalibrasi (#'.$this->v1->id, $pilihan['alasan'], $kasus);

            // Validator: diperiksa dengan v1 → nol selisih; temuannya persis
            // sama dengan saat stempelnya benar (tidak ada temuan yang menyebut
            // stempel asing — lihat laporan perbaikan P1).
            $hasil = $this->periksa($id);
            $this->assertDihitungUlang($hasil);
            $this->assertSame($sebelum['temuan'], $hasil['temuan'], $kasus);

            $this->hitungUlang($id, 'bukan milik paket profil/organisasi sesi ini');
            $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id), $kasus);
            $this->assertSame($angkaV1, $this->angkaSesi($id), $kasus);
        }
    }

    /**
     * T-2: paket yang di-soft-delete tidak punya versi berlaku untuk hitungan
     * BARU, tapi stempel versinya pada hasil tersimpan TETAP dipakai.
     * Distempel v2 (isi ≠ JSON di balok 19 mm), jadi jatuh ke JSON pasti
     * ketahuan sebagai `hitung_ulang_beda` / angka yang bergeser.
     */
    public function test_stempel_pada_paket_soft_delete_tetap_dipakai(): void
    {
        $v2 = $this->aktifkanV2();
        $id = $this->simpanSesi($this->payloadBerstandar(['tanggal_kalibrasi' => '2025-07-01']));
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($id));
        $this->hitungUlang($id);
        $angkaV2 = $this->angkaSesi($id);
        $sebelum = $this->periksa($id);
        $this->assertDihitungUlang($sebelum);

        PaketAcuan::findOrFail($this->v1->paket_acuan_id)->delete();
        $this->assertSoftDeleted('paket_acuan', ['id' => $this->v1->paket_acuan_id]);

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertNull(app(PenentuVersiAcuan::class)->untukSesi($sesi), 'hitungan baru: tidak ada versi berlaku');

        $pilihan = app(PenentuVersiAcuan::class)->untukSesiTersimpan($sesi);
        $this->assertSame($v2->id, $pilihan['versi']?->id);
        $this->assertSame($v2->id, $pilihan['stempel']?->id);
        $this->assertSame('stempel hasil tersimpan (#'.$v2->id.' micrometer v2, aktif).', $pilihan['alasan']);

        $hasil = $this->periksa($id);
        $this->assertDihitungUlang($hasil);
        $this->assertSame($sebelum['temuan'], $hasil['temuan']);
        $this->assertSame([], self::titikBeda($hasil));
        $this->assertSame([], self::kodeVersiAcuan($hasil));

        $this->hitungUlang($id, 'stempel hasil tersimpan (#'.$v2->id.' micrometer v2, aktif)');
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($id));
        $this->assertSame($angkaV2, $this->angkaSesi($id));
    }

    /** Versi `aktif` di paket mana pun: isi v1 dengan balok 19 mm = [BALOK_19_LAIN]. */
    private function versiBalokBedaDiPaket(PaketAcuan $paket): PaketAcuanVersi
    {
        $isi = $this->v1->isiTerurai();
        $isi['balok_ukur']['19.0'] = self::BALOK_19_LAIN;
        $kanonik = JsonKanonik::serialisasi($isi);

        return PaketAcuanVersi::create([
            'paket_acuan_id' => $paket->id,
            'organization_id' => $paket->organization_id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => 1,
            'status' => PaketAcuanVersi::STATUS_AKTIF,
            'isi' => $kanonik,
            'isi_sha256' => JsonKanonik::sha256($kanonik),
            'skema_versi' => $this->v1->skema_versi,
            'berlaku_mulai' => '2024-01-01',
        ]);
    }

    /** @return list<string> kode temuan `versi_acuan_*` */
    private static function kodeVersiAcuan(array $hasil): array
    {
        return array_values(array_filter(self::kode($hasil), static fn (string $k): bool => str_starts_with($k, 'versi_acuan')));
    }

    /** @return list<int> titik yang punya `hitung_ulang_beda`, urut */
    private static function titikBeda(array $hasil): array
    {
        return collect($hasil['temuan'])
            ->where('kode', 'hitung_ulang_beda')
            ->pluck('konteks.titik_ke')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
