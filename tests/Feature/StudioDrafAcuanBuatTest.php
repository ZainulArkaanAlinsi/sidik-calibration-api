<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Studio\Draf\PemetaanSuntingan;
use App\Services\Studio\Draf\ProyeksiDraf;
use App\Services\Studio\Draf\SuntinganDrafService;
use App\Services\Studio\PetaSel\DrafAcuanMaster;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\WorkbookScalarEditPreparer;
use App\Support\JsonKanonik;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * `POST /api/studio/workbook/{workbook}/draf` (rencana A1, B3): buat DRAF data
 * acuan dari dasar + suntingan angka. Fixture sintetis terisolasi per lab:
 * workbook master micrometer (varian B) + paket + versi 1 AKTIF dari
 * `database/data/tabel-standar-micrometer.json` (byte kanonik). Peta sel =
 * `database/data/peta-sel/micrometer.json` ASLI: empat sel angka sah
 * `DATABASE!T5..T8` -> `cmc[kode=A..D].u95_um` (0.83 / 0.87 / 0.91 / 0.91).
 *
 * Gerbang akses/sakelar/throttle: `StudioDrafAcuanGerbangTest`. Di sini
 * perilaku: hash, kunci, tulis, rollback, isolasi organisasi, presisi.
 * `lockForUpdate` tidak bisa diamati di SQLite (tata bahasa SQLite membuangnya);
 * yang dikunci di sini hanya URUTAN kueri (paket dikunci lebih dulu). Bukti
 * kunci/unik sesungguhnya = gate MySQL.
 */
class StudioDrafAcuanBuatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $studio;

    private WorkbookMaster $wb;

    private PaketAcuan $paket;

    private PaketAcuanVersi $v1;

    private PetaSel $peta;

    protected function setUp(): void
    {
        parent::setUp();
        SumberAcuan::lupakanCache();
        config(['studio.peta_sel.direktori' => database_path('data/peta-sel'), 'studio.draf_acuan_aktif' => true]);

        $this->peta = PetaSel::untuk('micrometer') ?? $this->fail('peta micrometer tidak ada');
        ['lab' => $this->lab, 'user' => $this->studio, 'wb' => $this->wb, 'paket' => $this->paket, 'v1' => $this->v1] = $this->siapkanLab('a');
        $this->sebagaiStudio($this->studio);
    }

    // ---- perkakas ---------------------------------------------------------

    private function teksDasar(): string
    {
        return JsonKanonik::dariTeks((string) file_get_contents(database_path('data/tabel-standar-micrometer.json')));
    }

    /** @return array{lab: Organization, user: User, wb: WorkbookMaster, paket: ?PaketAcuan, v1: ?PaketAcuanVersi} */
    private function siapkanLab(string $kode, ?string $teks = null, string $nama = 'Master_Olah_Data_Micrometer_25-50mm', bool $denganPaket = true, ?string $isiSha = null, string $kodeProfil = 'micrometer'): array
    {
        $lab = Organization::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
            'email' => "studio.{$kode}@sidik.test",
            'organization_id' => $lab->id,
        ]);
        $wb = WorkbookMaster::create([
            'organization_id' => $lab->id,
            'kode_profil' => $kodeProfil,
            'nama' => $nama,
            'sumber' => WorkbookMaster::SUMBER_CSV,
            'sha256' => hash('sha256', "wb|{$kode}|{$nama}"),
            'ukuran' => 100,
            'jumlah_lembar' => 1,
            'aktif' => true,
        ]);
        $paket = null;
        $v1 = null;

        if ($denganPaket) {
            $teks ??= $this->teksDasar();
            $paket = PaketAcuan::create(['organization_id' => $lab->id, 'kode_profil' => 'micrometer', 'nama' => 'Micrometer', 'skema_versi' => 1]);
            $v1 = PaketAcuanVersi::create([
                'paket_acuan_id' => $paket->id,
                'organization_id' => $lab->id,
                'jenis' => PaketAcuanVersi::JENIS_DATA,
                'nomor_versi' => 1,
                'status' => PaketAcuanVersi::STATUS_AKTIF,
                'isi' => $teks,
                'isi_sha256' => $isiSha ?? JsonKanonik::sha256($teks),
                'skema_versi' => 1,
                'berlaku_mulai' => '2020-01-01',
            ]);
        }

        return ['lab' => $lab, 'user' => $user, 'wb' => $wb, 'paket' => $paket, 'v1' => $v1];
    }

    /** @param  array<string, mixed>  $ganti */
    private function badan(array $ganti = [], ?WorkbookMaster $wb = null, ?PaketAcuanVersi $dasar = null): array
    {
        $wb ??= $this->wb;
        $dasar ??= $this->v1;

        return array_replace([
            'workbook_sha256' => $wb->sha256,
            'peta_sel_sha256' => $this->peta->sha256,
            'base_isi_sha256' => PenentuVersiAcuan::sha256($dasar),
            'alasan' => 'Koreksi CMC pita sesuai lampiran akreditasi',
            'suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.84']],
        ], $ganti);
    }

    /** @param  array<string, mixed>  $badan */
    private function kirim(array $badan, ?WorkbookMaster $wb = null): TestResponse
    {
        return $this->postJson('/api/studio/workbook/'.($wb ?? $this->wb)->id.'/draf', $badan);
    }

    /** @return array<string, int> */
    private function hitung(): array
    {
        return [
            'paket' => PaketAcuan::withTrashed()->count(),
            'versi' => PaketAcuanVersi::count(),
            'suntingan' => PaketAcuanSuntingan::count(),
            'log' => PaketAcuanLog::count(),
            'audit' => DB::table('audit_logs')->count(),
            'hitungan' => DB::table('uncertainty_calculations')->count(),
        ];
    }

    /** @return array<array-key, mixed> */
    private function isiV1(): array
    {
        return json_decode((string) $this->v1->isi, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<array-key, mixed>  $isi */
    private function indeksPita(array $isi, string $kode): int
    {
        foreach ($isi['cmc'] as $i => $c) {
            if (($c['kode'] ?? null) === $kode) {
                return $i;
            }
        }

        $this->fail("pita {$kode} tidak ada");
    }

    private function drafTunggal(): PaketAcuanVersi
    {
        return PaketAcuanVersi::query()->where('status', PaketAcuanVersi::STATUS_DRAF)->sole();
    }

    // ---- sakelar & gerbang, dengan workbook sungguhan ----------------------

    public function test_sakelar_mati_503_dan_tidak_menulis(): void
    {
        config(['studio.draf_acuan_aktif' => false]);
        $sebelum = $this->hitung();

        $this->kirim($this->badan())->assertStatus(503)->assertJsonPath('kode', 'belum_tersedia');
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_token_lab_biasa_ke_rute_draf_ditolak_dan_tidak_menulis(): void
    {
        $sebelum = $this->hitung();
        Sanctum::actingAs($this->studio, ['internal']);

        $this->kirim($this->badan())->assertForbidden();
        $this->assertSame($sebelum, $this->hitung());
    }

    // ---- isolasi organisasi -------------------------------------------------

    public function test_workbook_lab_lain_dan_tak_ada_sama_sama_404_dan_tidak_menulis(): void
    {
        ['wb' => $wbB, 'v1' => $v1B] = $this->siapkanLab('b');
        $sebelum = $this->hitung();
        $isiB = PaketAcuanVersi::query()->findOrFail($v1B->id)->getAttributes();

        $lain = $this->kirim($this->badan(wb: $wbB), $wbB);
        $takAda = $this->postJson('/api/studio/workbook/999999/draf', $this->badan());

        $lain->assertNotFound();
        $takAda->assertNotFound();
        $this->assertSame($takAda->getContent(), $lain->getContent(), 'lab lain tak boleh dibedakan dari tak ada');
        // Satu-satunya tulisan: jejak akses lintas lab milik PEMANGGIL (PenjagaOrganisasi), bukan data lab B.
        $this->assertSame($sebelum['audit'] + 1, $this->hitung()['audit']);
        $this->assertSame($sebelum, array_replace($this->hitung(), ['audit' => $sebelum['audit']]));
        $this->assertSame($isiB, PaketAcuanVersi::query()->findOrFail($v1B->id)->getAttributes());
    }

    #[DataProvider('idWorkbookAneh')]
    public function test_id_workbook_aneh_404_bukan_500(string $id): void
    {
        $this->postJson("/api/studio/workbook/{$id}/draf", $this->badan())->assertNotFound();
    }

    /** @return iterable<string, array{string}> */
    public static function idWorkbookAneh(): iterable
    {
        foreach (['abc', '0', '-5', '1e3', '1.5', str_repeat('9', 30), '%20'] as $id) {
            yield $id => [$id];
        }
    }

    public function test_organisasi_dicek_sebelum_validasi_bodi_kosong_ke_workbook_lab_lain_tetap_404(): void
    {
        ['wb' => $wbB] = $this->siapkanLab('b');

        $this->postJson('/api/studio/workbook/'.$wbB->id.'/draf', [])->assertNotFound();
        $this->postJson('/api/studio/workbook/'.$this->wb->id.'/draf', [])->assertStatus(422);
    }

    public function test_workbook_diarsipkan_404(): void
    {
        $this->wb->forceFill(['diarsipkan_pada' => now()])->save();
        $sebelum = $this->hitung();

        $this->kirim($this->badan())->assertNotFound();
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_profil_tak_terpetakan_422_tanpa_menulis(): void
    {
        ['wb' => $wbPh, 'user' => $userPh] = $this->siapkanLab('ph', null, 'Master Olah Data pH Meter', false, null, 'ph_meter');
        $this->sebagaiStudio($userPh);
        $sebelum = $this->hitung();

        $this->kirim($this->badan(wb: $wbPh, dasar: $this->v1), $wbPh)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'profil_belum_terpetakan');
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_tanpa_profil_dan_keluarga_422_belum_terpetakan(): void
    {
        $this->wb->forceFill(['kode_profil' => null, 'keluarga' => null])->save();

        $this->kirim($this->badan())->assertStatus(422)->assertJsonPath('kode', 'profil_belum_terpetakan');
    }

    public function test_peta_dipilih_seperti_impor_keluarga_workbook_dipetakan_ke_paketnya(): void
    {
        // kode_profil null + keluarga volumetric_fixed -> paket `volumetric` (PetaProfilWorkbook::kodePaket),
        // BUKAN `$workbook->kode_profil` mentah (null -> "belum terpetakan"). Lab ini tak punya paketnya,
        // jadi jawaban yang benar 422 `paket_belum_ada` -- bukti peta `volumetric` terpilih dan hash-nya diterima.
        ['wb' => $wb, 'user' => $user] = $this->siapkanLab('v', null, 'Master Fixed Volumetric 10 ml', false);
        $wb->forceFill(['kode_profil' => null, 'keluarga' => 'volumetric_fixed'])->save();
        $this->sebagaiStudio($user);
        $volumetric = PetaSel::untuk('volumetric') ?? $this->fail('peta volumetric tidak ada');

        $this->kirim($this->badan(['peta_sel_sha256' => $volumetric->sha256, 'base_isi_sha256' => str_repeat('0', 64)], $wb), $wb)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'paket_belum_ada');
    }

    public function test_peta_ber_varian_lewati_tidak_berlaku_untuk_master_di_luar_variannya(): void
    {
        // Peta flowmeter hanya untuk Ultrasonic; master Gravimetri (profil sama) = belum terpetakan, bukan ditolak.
        ['wb' => $wb, 'user' => $user] = $this->siapkanLab('f', null, 'Master Flowmeter Totalizer Gravimetri', false, null, 'flowmeter_totalizer');
        $this->sebagaiStudio($user);
        $flow = PetaSel::untuk('flowmeter') ?? $this->fail('peta flowmeter tidak ada');
        $this->assertTrue($flow->tidakBerlakuUntuk($wb->nama));

        $this->kirim($this->badan(['peta_sel_sha256' => $flow->sha256, 'base_isi_sha256' => str_repeat('0', 64)], $wb), $wb)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'profil_belum_terpetakan');
    }

    public function test_varian_tak_dikenal_tidak_punya_sel_yang_bisa_disunting(): void
    {
        ['wb' => $wbX, 'user' => $userX] = $this->siapkanLab('x', null, 'Master Micrometer Baru');
        $this->sebagaiStudio($userX);
        $sebelum = $this->hitung();

        $this->kirim($this->badan(wb: $wbX, dasar: PaketAcuanVersi::query()->where('organization_id', $wbX->organization_id)->sole()), $wbX)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sel_tidak_didukung');
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_paket_belum_ada_422_paket_tidak_dibuat_dan_paket_lab_lain_tidak_tersentuh(): void
    {
        ['v1' => $v1B] = $this->siapkanLab('b');
        ['wb' => $wbC, 'user' => $userC] = $this->siapkanLab('c', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $this->sebagaiStudio($userC);
        $sebelum = $this->hitung();

        $this->kirim($this->badan(wb: $wbC, dasar: $v1B), $wbC)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'paket_belum_ada');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame(0, PaketAcuan::query()->where('organization_id', $wbC->organization_id)->count());
        $this->assertSame(0, PaketAcuanVersi::query()->where('organization_id', $wbC->organization_id)->count());
    }

    // ---- hash yang dilihat klien --------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function hashSalah(): iterable
    {
        yield 'workbook' => ['workbook_sha256', 'workbook_berubah'];
        yield 'peta' => ['peta_sel_sha256', 'peta_berubah'];
        yield 'dasar' => ['base_isi_sha256', 'dasar_berubah'];
    }

    #[DataProvider('hashSalah')]
    public function test_hash_salah_409_dan_hitungan_baris_tidak_berubah(string $kolom, string $kode): void
    {
        $sebelum = $this->hitung();

        $this->kirim($this->badan([$kolom => str_repeat('0', 64)]))
            ->assertStatus(409)
            ->assertJsonPath('kode', $kode);

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_format_hash_dan_alasan_divalidasi(): void
    {
        foreach ([
            ['workbook_sha256' => strtoupper($this->wb->sha256)],
            ['peta_sel_sha256' => 'abc'],
            ['base_isi_sha256' => null],
            ['alasan' => 'pendek'],
            ['alasan' => str_repeat('a', 501)],
        ] as $ganti) {
            $this->kirim($this->badan($ganti))->assertStatus(422)->assertJsonValidationErrors(array_key_first($ganti));
        }

        $this->assertSame(0, PaketAcuanSuntingan::count());
    }

    public function test_dasar_berubah_sesudah_klien_memuat_409_lalu_hash_baru_membuat_draf_di_atas_versi_baru(): void
    {
        $lama = PenentuVersiAcuan::sha256($this->v1);
        $isi = $this->isiV1();
        $isi['cmc'][$this->indeksPita($isi, 'B')]['u95_um'] = 0.99;
        $teks = JsonKanonik::serialisasi($isi);

        $this->v1->forceFill(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => now()->subDay()->toDateString()])->save();
        $v2 = PaketAcuanVersi::create([
            'paket_acuan_id' => $this->paket->id, 'organization_id' => $this->lab->id, 'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => 2, 'status' => PaketAcuanVersi::STATUS_AKTIF, 'isi' => $teks, 'isi_sha256' => JsonKanonik::sha256($teks),
            'skema_versi' => 1, 'berlaku_mulai' => now()->toDateString(),
        ]);
        $sebelum = $this->hitung();

        $this->kirim($this->badan(['base_isi_sha256' => $lama]))->assertStatus(409)->assertJsonPath('kode', 'dasar_berubah');
        $this->assertSame($sebelum, $this->hitung());

        $this->kirim($this->badan(['base_isi_sha256' => JsonKanonik::sha256($teks)]))
            ->assertCreated()
            ->assertJsonPath('data.versi.dasar_versi_id', $v2->id)
            ->assertJsonPath('data.versi.nomor_versi', 3)
            ->assertJsonPath('data.hash.dasar', JsonKanonik::sha256($teks));
    }

    // ---- sukses: isi, jejak, respons ----------------------------------------

    public function test_sukses_menyimpan_byte_kanonik_dengan_jejak_lengkap_dan_hanya_satu_daun_berubah(): void
    {
        $sebelum = $this->hitung();
        $res = $this->kirim($this->badan());

        $res->assertCreated()
            ->assertJsonPath('data.status', 'draf_dibuat')
            ->assertJsonPath('data.versi.nomor_versi', 2)
            ->assertJsonPath('data.versi.status', 'draf')
            ->assertJsonPath('data.versi.dasar_versi_id', $this->v1->id);

        $draf = $this->drafTunggal();
        $this->assertSame($draf->id, $res->json('data.versi.id'));

        // Isi = byte kanonik dari dasar + satu daun; isi_sha256 TETAP null; nomor, dasar, pelaku, alasan.
        $harap = $this->isiV1();
        $harap['cmc'][$this->indeksPita($harap, 'A')]['u95_um'] = 0.84;
        $this->assertSame(JsonKanonik::serialisasi($harap), (string) $draf->isi, 'byte kanonik utuh');
        $this->assertNull($draf->isi_sha256, 'isi_sha256 dikunci saat diajukan, bukan di draf');
        $this->assertSame(
            [PaketAcuanVersi::JENIS_DATA, PaketAcuanVersi::STATUS_DRAF, 2, $this->v1->id, $this->studio->id, 1, $this->paket->id, $this->lab->id],
            [$draf->jenis, $draf->status, $draf->nomor_versi, $draf->dasar_versi_id, $draf->dibuat_oleh, $draf->skema_versi, $draf->paket_acuan_id, $draf->organization_id],
        );
        $this->assertSame('Koreksi CMC pita sesuai lampiran akreditasi', $draf->alasan);
        $this->assertSame(['workbook_master', 'peta_sel', 'dasar'], array_column($draf->rujukan, 'jenis'));
        $this->assertStringContainsString($this->wb->sha256, $draf->rujukan[0]['catatan']);
        $this->assertStringContainsString('varian B', $draf->rujukan[1]['catatan']);

        // Daun lain dan tipe int/float tak bergeser: hanya SATU selisih terhadap dasar.
        $selisih = DrafAcuanMaster::selisih($this->isiV1(), $draf->isiTerurai());
        $this->assertSame(['cmc[0]/u95_um' => ['0.83', '0.84']], $selisih);
        $this->assertSame(
            JsonKanonik::sha256(JsonKanonik::serialisasi(array_diff_key($this->isiV1(), ['cmc' => 1]))),
            JsonKanonik::sha256(JsonKanonik::serialisasi(array_diff_key($draf->isiTerurai(), ['cmc' => 1]))),
            'bagian yang tidak disunting identik (hash)',
        );

        // Baris suntingan: sumber ketik, pelaku, alasan, nilai lama/baru kanonik.
        $baris = PaketAcuanSuntingan::query()->where('paket_acuan_versi_id', $draf->id)->sole();
        $this->assertSame(
            ['cmc[0]', null, 'u95_um', '0.83', '0.84', 'ketik', $this->studio->id, 'Koreksi CMC pita sesuai lampiran akreditasi'],
            [$baris->lembar, $baris->kunci_baris, $baris->kolom, $baris->nilai_lama, $baris->nilai_baru, $baris->sumber, $baris->user_id, $baris->alasan],
        );
        $this->assertSame(PaketAcuanSuntingan::SUMBER_KETIK, $baris->sumber);

        // Log `dibuat`: pelaku, alasan, sumber (termasuk sumber_ui), rincian.
        $log = PaketAcuanLog::query()->sole();
        $this->assertSame(
            [$draf->id, PaketAcuanLog::AKSI_DIBUAT, null, 'draf', $this->studio->id, 'Koreksi CMC pita sesuai lampiran akreditasi', JsonKanonik::sha256((string) $draf->isi)],
            [$log->paket_acuan_versi_id, $log->aksi, $log->status_dari, $log->status_ke, $log->user_id, $log->alasan, $log->isi_sha256],
        );
        $this->assertSame('studio', $log->sumber['sumber_ui']);
        $this->assertSame(
            [$this->wb->id, $this->wb->sha256, $this->wb->nama, 'micrometer', $this->peta->sha256, PenentuVersiAcuan::sha256($this->v1), 'B'],
            [$log->sumber['workbook_master_id'], $log->sumber['workbook_sha256'], $log->sumber['workbook_nama'], $log->sumber['peta_sel'], $log->sumber['peta_sel_sha256'], $log->sumber['dasar_sha256'], $log->sumber['varian']],
        );
        $this->assertSame(1, $log->rincian['sel_berubah']);
        $this->assertSame([['sel' => 'DATABASE!T5', 'jalur' => 'cmc[kode=A].u95_um']], $log->rincian['sel']);

        $this->assertSame(
            ['paket' => $sebelum['paket'], 'versi' => $sebelum['versi'] + 1, 'suntingan' => $sebelum['suntingan'] + 1, 'log' => $sebelum['log'] + 1, 'hitungan' => $sebelum['hitungan']],
            array_diff_key($this->hitung(), ['audit' => 1]),
        );
    }

    public function test_respons_nilai_string_kanonik_hash_benar_dan_sama_dengan_baca_ulang_dari_db(): void
    {
        $res = $this->kirim($this->badan());
        $res->assertCreated();
        $draf = $this->drafTunggal();

        $this->assertSame(JsonKanonik::sha256((string) $draf->isi), $res->json('data.hash.isi'));
        $this->assertSame(PenentuVersiAcuan::sha256($draf), $res->json('data.hash.isi'));
        $this->assertSame(
            ['dasar' => PenentuVersiAcuan::sha256($this->v1), 'workbook' => $this->wb->sha256, 'peta' => $this->peta->sha256],
            array_diff_key($res->json('data.hash'), ['isi' => 1]),
        );

        $suntingan = $res->json('data.suntingan');
        $this->assertSame(
            [['DATABASE!T5', 'cmc[kode=A].u95_um', '0.84'], ['DATABASE!T6', 'cmc[kode=B].u95_um', '0.87'], ['DATABASE!T7', 'cmc[kode=C].u95_um', '0.91'], ['DATABASE!T8', 'cmc[kode=D].u95_um', '0.91']],
            array_map(static fn (array $s): array => [$s['sel'], $s['jalur'], $s['nilai']], $suntingan),
        );
        foreach ($suntingan as $s) {
            $this->assertIsString($s['nilai'], 'nilai selalu STRING, bukan float JSON');
        }
        $this->assertStringContainsString('"nilai":"0.84"', (string) $res->getContent());

        $dariDb = ProyeksiDraf::dari($draf->isiTerurai(), PemetaanSuntingan::untukMaster($this->peta, $this->wb));
        $this->assertSame($dariDb, $suntingan, 'respons == baca ulang dari DB');
    }

    public function test_keempat_sel_sekaligus_noop_dilewati_dan_tipe_float_dipertahankan(): void
    {
        $res = $this->kirim($this->badan(['suntingan' => [
            ['sel' => 'DATABASE!T5', 'nilai' => '1'],       // 0.83 -> 1.0 (float, bukan int)
            ['sel' => 'DATABASE!T6', 'nilai' => '0.870'],   // sama dengan 0.87: dilewati
            ['sel' => 'DATABASE!T7', 'nilai' => '0.92'],
            ['sel' => 'DATABASE!T8', 'nilai' => '123456789012345'], // 15 digit
        ]]));

        $res->assertCreated();
        $draf = $this->drafTunggal();
        $isi = $draf->isiTerurai();

        $this->assertSame(1.0, $isi['cmc'][$this->indeksPita($isi, 'A')]['u95_um']);
        $this->assertSame(0.87, $isi['cmc'][$this->indeksPita($isi, 'B')]['u95_um']);
        $this->assertSame(0.92, $isi['cmc'][$this->indeksPita($isi, 'C')]['u95_um']);
        $this->assertSame(123456789012345.0, $isi['cmc'][$this->indeksPita($isi, 'D')]['u95_um']);
        $this->assertSame(['1.0', '0.87', '0.92', '123456789012345.0'], array_column($res->json('data.suntingan'), 'nilai'));
        $this->assertSame(3, PaketAcuanSuntingan::count(), 'T6 tidak berubah -> tanpa baris');
        $this->assertSame(3, PaketAcuanLog::query()->sole()->rincian['sel_berubah']);

        // Tipe daun lain (int vs float) identik dengan dasar.
        $v1 = $this->isiV1();
        foreach (['konstanta', 'balok_ukur', 'standar', 'ketidakpastian_balok_um'] as $kunci) {
            $this->assertSame($v1[$kunci], $isi[$kunci], "{$kunci} tak berubah (tipe int/float ikut)");
        }
    }

    public function test_minus_nol_terdefinisi_menjadi_nol_kanonik(): void
    {
        $res = $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '-0']]]));

        $res->assertCreated();
        $this->assertSame('0.0', $res->json('data.suntingan.0.nilai'));
        $this->assertSame('0.0', PaketAcuanSuntingan::query()->sole()->nilai_baru);
        $this->assertSame(0.0, $this->drafTunggal()->isiTerurai()['cmc'][0]['u95_um']);
    }

    // ---- penomoran, retry, nol perubahan --------------------------------------

    public function test_dua_post_berturut_nomor_berurutan_tanpa_celah_dan_dasar_tetap_versi_berlaku(): void
    {
        $a = $this->kirim($this->badan())->assertCreated();
        $b = $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.85']]]))->assertCreated();

        $this->assertSame([2, 3], [$a->json('data.versi.nomor_versi'), $b->json('data.versi.nomor_versi')]);
        $this->assertNotSame($a->json('data.versi.id'), $b->json('data.versi.id'));
        $this->assertSame([1, 2, 3], PaketAcuanVersi::query()->orderBy('nomor_versi')->pluck('nomor_versi')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([$this->v1->id, $this->v1->id], [$a->json('data.versi.dasar_versi_id'), $b->json('data.versi.dasar_versi_id')], 'draf tidak berlaku, dasar tetap v1');
        $this->assertSame('0.83', PaketAcuanSuntingan::query()->where('paket_acuan_versi_id', $b->json('data.versi.id'))->sole()->nilai_lama);
    }

    public function test_isi_sama_dengan_draf_yang_ada_200_draf_sudah_ada_tanpa_baris_atau_audit_baru(): void
    {
        $a = $this->kirim($this->badan())->assertCreated();
        $sebelum = $this->hitung();

        $b = $this->kirim($this->badan(['alasan' => 'Alasan lain yang juga cukup panjang']));

        $b->assertOk()->assertJsonPath('data.status', 'draf_sudah_ada')->assertJsonPath('data.versi.id', $a->json('data.versi.id'));
        $this->assertSame($a->json('data.hash.isi'), $b->json('data.hash.isi'));
        $this->assertSame($a->json('data.suntingan'), $b->json('data.suntingan'));
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_nol_perubahan_422_tanpa_menulis(): void
    {
        $sebelum = $this->hitung();

        foreach (['0.83', '0.830', '0.8300000000'] as $nilai) {
            $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => $nilai]]]))
                ->assertStatus(422)
                ->assertJsonPath('kode', 'tidak_ada_perubahan');
        }

        $this->assertSame($sebelum, $this->hitung());
    }

    // ---- presisi & sel -------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function nilaiDitolak(): iterable
    {
        foreach ([
            'huruf' => 'abc', 'eksponen' => '1e3', 'tanpa nol depan' => '.5', 'plus' => '+1', '11 pecahan' => '0.12345678901',
            '17 digit signifikan' => '12345678.123456789', '16 digit melewati 2^53' => '9007199254740993', 'spasi depan' => ' 0.84',
            'spasi belakang' => '0.84 ', 'koma' => '0,84', 'titik buntut' => '2.', 'nol depan' => '01.5', 'NAN' => 'NAN', 'rumus' => '=1+2',
            'tanggal' => '2026-11-09', 'teks angka besar' => str_repeat('9', 60),
        ] as $nama => $nilai) {
            yield $nama => [$nilai];
        }
    }

    #[DataProvider('nilaiDitolak')]
    public function test_nilai_tak_sah_ditolak_422_suntingan_ditolak_dan_tidak_menulis(string $nilai): void
    {
        $sebelum = $this->hitung();

        $res = $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '0.9'], ['sel' => 'DATABASE!T5', 'nilai' => $nilai]]]));

        $res->assertStatus(422)->assertJsonPath('kode', 'suntingan_ditolak')->assertJsonPath('rincian.indeks', 1)->assertJsonPath('rincian.sel', 'DATABASE!T5');
        $this->assertStringContainsString('suntingan ke-2', (string) $res->json('message'));
        $this->assertSame($sebelum, $this->hitung(), 'satu nilai buruk membatalkan seluruh gelombang');
    }

    public function test_nilai_lima_belas_digit_dan_nol_koma_satu_lolos(): void
    {
        $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.1'], ['sel' => 'DATABASE!T6', 'nilai' => '123456789.123456']]]))
            ->assertCreated()
            ->assertJsonPath('data.suntingan.0.nilai', '0.1')
            ->assertJsonPath('data.suntingan.1.nilai', '123456789.123456');
    }

    /** @return iterable<string, array{mixed}> */
    public static function nilaiBukanString(): iterable
    {
        yield 'null' => [null];
        yield 'float JSON' => [0.84];
        yield 'int JSON' => [1];
        yield 'bool' => [true];
        yield 'array' => [['0.84']];
        yield 'kosong' => [''];
    }

    #[DataProvider('nilaiBukanString')]
    public function test_nilai_bukan_string_atau_kosong_ditolak_validasi(mixed $nilai): void
    {
        $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => $nilai]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('suntingan.0.nilai');
        $this->assertSame(0, PaketAcuanSuntingan::count());
    }

    public function test_sel_di_luar_pemetaan_teks_tanggal_dan_lainnya_422_tanpa_membocorkan_jalur(): void
    {
        $sel = ['DATABASE!T9', 'database!t5', 'DATABASE!$T$5', 'T5', 'X!A1', 'DATABASE!T5:T6'];

        foreach ($this->peta->isi['ekstrak'] as $e) {
            if (in_array($e['tipe'], ['teks', 'tanggal', 'peta_angka', 'daftar_angka', 'bilangan_bulat'], true) && isset($e['sel'])) {
                $sel[] = $e['sel'];
            }
        }
        $this->assertGreaterThan(8, count($sel), 'peta micrometer punya sel teks/tanggal/peta_angka');
        $sebelum = $this->hitung();

        foreach ($sel as $s) {
            $res = $this->kirim($this->badan(['suntingan' => [['sel' => $s, 'nilai' => '1']]]));
            $res->assertStatus(422)->assertJsonPath('kode', 'sel_tidak_didukung');
            $this->assertStringContainsString($s, (string) $res->json('message'));
            $this->assertStringNotContainsString('cmc[', (string) $res->getContent(), 'jalur internal tidak bocor');
            $this->assertStringNotContainsString('u95_um', (string) $res->getContent());
        }

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_sel_ganda_422_dan_satu_sel_di_luar_pemetaan_membatalkan_seluruh_gelombang(): void
    {
        $sebelum = $this->hitung();

        $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.9'], ['sel' => 'DATABASE!T5', 'nilai' => '0.8']]]))
            ->assertStatus(422)->assertJsonPath('kode', 'suntingan_ditolak')->assertJsonPath('rincian.indeks', 1);
        $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.9'], ['sel' => 'DATABASE!T9', 'nilai' => '0.8']]]))
            ->assertStatus(422)->assertJsonPath('kode', 'sel_tidak_didukung');

        $this->assertSame($sebelum, $this->hitung());
    }

    // ---- bentuk request ------------------------------------------------------

    public function test_kunci_tambahan_di_mana_pun_422_dan_jalur_tipe_bulat_tidak_diterima_dari_klien(): void
    {
        $sebelum = $this->hitung();
        $item = ['sel' => 'DATABASE!T5', 'nilai' => '0.84'];

        foreach ([
            ['jalur' => 'cmc[kode=A].u95_um'],
            ['tipe' => 'angka'],
            ['bulat' => 10],
            ['suntingan' => [$item + ['jalur' => 'cmc[kode=A].u95_um']]],
            ['suntingan' => [$item + ['tipe' => 'angka']]],
            ['suntingan' => [$item + ['bulat' => 10]]],
            ['suntingan' => [['sel' => 'DATABASE!T5']]],
            ['suntingan' => [['nilai' => '0.84']]],
            ['suntingan' => ['a' => $item]],
            ['suntingan' => []],
            ['suntingan' => ['teks']],
            ['suntingan' => array_map(static fn (int $i): array => ['sel' => "DATABASE!T{$i}", 'nilai' => '1'], range(1, 301))],
        ] as $ganti) {
            $this->kirim($this->badan($ganti))->assertStatus(422);
        }

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_tiga_ratus_suntingan_lolos_validasi_batas_dan_sisanya_ditolak_server(): void
    {
        $banyak = array_map(static fn (int $i): array => ['sel' => "DATABASE!Z{$i}", 'nilai' => '1'], range(1, 300));

        $this->kirim($this->badan(['suntingan' => $banyak]))->assertStatus(422)->assertJsonPath('kode', 'sel_tidak_didukung');
    }

    public function test_spasi_di_nilai_tidak_dipangkas_diam_diam(): void
    {
        $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => " 0.84\n"]]]))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'suntingan_ditolak');
        $this->kirim($this->badan(['suntingan' => [['sel' => ' DATABASE!T5', 'nilai' => '0.84']]]))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sel_tidak_didukung');
        $this->assertSame(0, PaketAcuanSuntingan::count());
    }

    // ---- versi lain, pensiun, aktif tak tersentuh ----------------------------------

    public function test_versi_aktif_pensiun_dan_diajukan_tidak_berubah_dan_penentu_versi_tetap(): void
    {
        $teks = (string) $this->v1->isi;
        $pensiun = PaketAcuanVersi::create([
            'paket_acuan_id' => $this->paket->id, 'organization_id' => $this->lab->id, 'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => 2, 'status' => PaketAcuanVersi::STATUS_PENSIUN, 'isi' => $teks, 'isi_sha256' => JsonKanonik::sha256($teks),
            'skema_versi' => 1, 'berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31',
        ]);
        $diajukan = PaketAcuanVersi::create([
            'paket_acuan_id' => $this->paket->id, 'organization_id' => $this->lab->id, 'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => 3, 'status' => PaketAcuanVersi::STATUS_DIAJUKAN, 'isi' => $teks, 'isi_sha256' => JsonKanonik::sha256($teks),
            'skema_versi' => 1, 'lock_versi' => 4,
        ]);
        $foto = fn () => PaketAcuanVersi::query()->whereIn('id', [$this->v1->id, $pensiun->id, $diajukan->id])->orderBy('id')->get()->map->getAttributes()->all();
        $penentu = fn () => app(PenentuVersiAcuan::class)->untukProfil((int) $this->lab->id, 'micrometer', now())?->id;
        $sebelum = $foto();
        $this->assertSame($this->v1->id, $penentu());

        $res = $this->kirim($this->badan())->assertCreated();

        $this->assertSame(4, $res->json('data.versi.nomor_versi'), 'nomor = 1 + max seluruh versi paket');
        $this->assertSame($sebelum, $foto(), 'aktif / pensiun / diajukan: isi, isi_sha256, status, lock_versi, updated_at identik');
        $this->assertSame($this->v1->id, $penentu(), 'versi berlaku tak bergeser; draf tak pernah dipulangkan');
    }

    // ---- isi dasar tidak stabil, daun hilang ----------------------------------------

    /** @return iterable<string, array{string, ?string}> */
    public static function dasarTidakStabil(): iterable
    {
        yield 'objek kosong' => ['sisip', ',"zz":{}'];
        yield 'objek berkunci numerik' => ['sisip', ',"zz":{"0":1.5,"1":2.5}'];
        yield 'bilangan di luar int64' => ['sisip', ',"zz":99999999999999999999'];
        yield 'bukan byte kanonik (baris baru)' => ['akhiran', "\n"];
        yield 'kolom hash tidak cocok dengan teks' => ['hash', null];
    }

    #[DataProvider('dasarTidakStabil')]
    public function test_dasar_tidak_stabil_kanonik_409_isi_tidak_stabil_tanpa_menulis(string $jenis, ?string $tambah): void
    {
        $teks = $this->teksDasar();
        $teks = match ($jenis) {
            'sisip' => substr($teks, 0, -1).$tambah.'}',
            'akhiran' => $teks.$tambah,
            default => $teks,
        };
        ['wb' => $wb, 'user' => $user, 'v1' => $v1] = $this->siapkanLab('t', $teks, isiSha: $jenis === 'hash' ? str_repeat('a', 64) : null);
        $this->sebagaiStudio($user);
        $sebelum = $this->hitung();

        $this->kirim($this->badan(wb: $wb, dasar: $v1), $wb)
            ->assertStatus(409)
            ->assertJsonPath('kode', 'isi_tidak_stabil');
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_daun_pemetaan_hilang_atau_bukan_angka_tidak_500_entri_tersaring_dan_sel_itu_422(): void
    {
        $isi = $this->isiV1();
        unset($isi['cmc'][$this->indeksPita($isi, 'D')]);                        // T8: daun hilang
        $isi['cmc'] = array_values($isi['cmc']);
        $isi['cmc'][$this->indeksPita($isi, 'C')]['u95_um'] = 'n/a';             // T7: bukan angka
        $teks = JsonKanonik::serialisasi($isi);
        ['wb' => $wb, 'user' => $user, 'v1' => $v1] = $this->siapkanLab('h', $teks);
        $this->sebagaiStudio($user);

        $sebelum = $this->hitung();
        foreach (['DATABASE!T7', 'DATABASE!T8'] as $sel) {
            $this->kirim($this->badan(['suntingan' => [['sel' => $sel, 'nilai' => '1']]], $wb, $v1), $wb)
                ->assertStatus(422)->assertJsonPath('kode', 'sel_tidak_didukung');
        }
        $this->assertSame($sebelum, $this->hitung());

        // Sel yang daunnya ada tetap bisa disunting, dan proyeksi hanya memuat entri yang tersisa.
        $res = $this->kirim($this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.84']]], $wb, $v1), $wb);
        $res->assertCreated();
        $this->assertSame(['DATABASE!T5', 'DATABASE!T6'], array_column($res->json('data.suntingan'), 'sel'));
    }

    // ---- kegagalan di tengah: rollback total ------------------------------------------

    /** @return iterable<string, array{class-string}> */
    public static function modelGagal(): iterable
    {
        yield 'log' => [PaketAcuanLog::class];
        yield 'baris suntingan' => [PaketAcuanSuntingan::class];
    }

    /** @param  class-string  $model */
    #[DataProvider('modelGagal')]
    public function test_kegagalan_di_tengah_membatalkan_seluruh_tulis(string $model): void
    {
        $sebelum = $this->hitung();
        $model::creating(static function (): never {
            throw new RuntimeException('gagal paksa');
        });

        try {
            $this->kirim($this->badan())->assertStatus(500);
        } finally {
            Event::forget("eloquent.creating: {$model}");
        }

        $this->assertSame($sebelum, $this->hitung(), 'nol baris versi/suntingan/log/audit tersisa');
        $this->assertSame(0, PaketAcuanVersi::query()->where('status', 'draf')->count());

        // Tidak ada sisa: permintaan yang sama sekarang berhasil sebagai nomor 2 (tanpa celah).
        $this->kirim($this->badan())->assertCreated()->assertJsonPath('data.versi.nomor_versi', 2);
    }

    public function test_proyeksi_gagal_membatalkan_seluruh_tulis_dan_dijawab_500_proyeksi_gagal(): void
    {
        $this->app->bind(SuntinganDrafService::class, fn ($app) => new class($app->make(DrafAcuanMaster::class), $app->make(WorkbookScalarEditPreparer::class), $app->make(CalibrationProfileRegistry::class)) extends SuntinganDrafService
        {
            protected function proyeksi(array $isi, array $pemetaan): array
            {
                throw new UnexpectedValueException('daun hilang');
            }
        });
        $sebelum = $this->hitung();

        $this->kirim($this->badan())->assertStatus(500)->assertJsonPath('kode', 'proyeksi_gagal');
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_bentrok_nomor_versi_unik_dijawab_409_draf_berubah_tanpa_menulis(): void
    {
        $sebelum = $this->hitung();
        PaketAcuanVersi::creating(static function (): never {
            throw new UniqueConstraintViolationException('sqlite', 'insert into paket_acuan_versi', [], new \PDOException('UNIQUE constraint failed'));
        });

        try {
            $this->kirim($this->badan())->assertStatus(409)->assertJsonPath('kode', 'draf_berubah');
        } finally {
            Event::forget('eloquent.creating: '.PaketAcuanVersi::class);
        }

        $this->assertSame($sebelum, $this->hitung());
    }

    // ---- urutan kunci ------------------------------------------------------------------

    public function test_baris_paket_dikunci_sebelum_dasar_dan_penomoran_dihitung(): void
    {
        $kueri = [];
        DB::listen(static function ($q) use (&$kueri): void {
            $kueri[] = $q->sql;
        });

        $this->kirim($this->badan())->assertCreated();

        $paket = fn (string $sql): bool => str_starts_with($sql, 'select') && preg_match('/from "paket_acuan"( |$)/', $sql) === 1;
        $versi = fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "paket_acuan_versi"');

        $pertamaVersi = array_key_first(array_filter($kueri, $versi));
        $this->assertNotNull($pertamaVersi);

        // Tiga select paket SEBELUM kueri versi pertama: kunci paket (#1), lalu
        // dasar() membaca paket (#2) dan penentu versi berlaku membaca paket (#3).
        // Kunci yang dipindah ke sesudah dasar() menyisakan hanya dua.
        $urutPaket = array_keys(array_filter($kueri, $paket));
        $this->assertGreaterThanOrEqual(3, count($urutPaket));
        $this->assertLessThan($pertamaVersi, $urutPaket[2], 'paket dikunci sebelum dasar() menyentuh versi');

        // Penomoran (max) terjadi sesudah kunci dan sesudah dasar() membaca versi berlaku.
        $max = array_key_first(array_filter($kueri, static fn (string $sql): bool => str_contains($sql, 'max("nomor_versi")')));
        $this->assertNotNull($max);
        $this->assertGreaterThan($urutPaket[2], $max);
        $this->assertGreaterThan($pertamaVersi, $max);
    }

    // ---- kesetaraan konvensi baris suntingan dengan jalur impor ---------------------------

    public function test_pecah_jalur_sama_persis_dengan_konvensi_baris_impor_buat(): void
    {
        $dasar = app(DrafAcuanMaster::class)->dasar((int) $this->lab->id, $this->peta);
        $isi = $this->isiV1();
        $isi['balok_ukur']['19.0'] = 19.5;                                     // lembar + kolom bertitik
        $isi['cmc'][0]['u95_um'] = 9.9;                                        // lembar `cmc[0]`
        $isi['cmc'][0]['titik'][0]['tumpukan_mm'][0] = 7.7;                    // kunci_baris bertingkat
        $isi['kunci_sangat_panjang_'.str_repeat('x', 90)]['dalam_'.str_repeat('y', 150)]['kolom_'.str_repeat('z', 90)] = 1.5; // potongan 64/128/64
        $isi['hanya_satu'] = 3.25;                                             // jalur satu segmen

        $versi = app(DrafAcuanMaster::class)->buat($this->wb, $this->peta, ['varian' => 'B', 'isi' => $isi, 'jalur' => []], $dasar, $this->studio->id);
        $this->assertSame('draf_dibuat', $versi['status']);

        $baris = PaketAcuanSuntingan::query()->where('paket_acuan_versi_id', $versi['versi_id'])->orderBy('id')->get();
        $selisih = DrafAcuanMaster::selisih($dasar['isi'], $isi);
        $this->assertGreaterThanOrEqual(6, count($selisih));
        $this->assertCount(count($selisih), $baris);

        $i = 0;
        foreach (array_keys($selisih) as $jalur) {
            $this->assertSame(
                SuntinganDrafService::pecahJalur($jalur),
                ['lembar' => $baris[$i]->lembar, 'kunci_baris' => $baris[$i]->kunci_baris, 'kolom' => $baris[$i]->kolom],
                "jalur {$jalur}",
            );
            $i++;
        }
    }
}
