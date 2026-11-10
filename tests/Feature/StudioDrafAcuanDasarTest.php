<?php

namespace Tests\Feature;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Support\JsonKanonik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FixtureDrafAcuan;
use Tests\TestCase;

/**
 * `GET /api/studio/workbook/{workbook}/draf-dasar` (rencana A1, B4; tinjau B3 🟡1):
 * hash `dasar`/`peta`/`workbook` + proyeksi sel dari dasar + ringkasan draf yang
 * sudah ada, SEBELUM draf pertama. Tidak menulis. Fixture: `FixtureDrafAcuan`.
 */
class StudioDrafAcuanDasarTest extends TestCase
{
    use FixtureDrafAcuan;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDrafAcuan();
    }

    public function test_bentuk_respons_dan_nilai_hash(): void
    {
        $res = $this->bacaDasar()->assertOk();

        $this->assertSame(['hash', 'dasar_versi_id', 'suntingan', 'draf', 'draf_total', 'draf_terpotong'], array_keys($res->json('data')));
        $this->assertSame($this->v1->id, $res->json('data.dasar_versi_id'));
        $this->assertSame([0, false], [$res->json('data.draf_total'), $res->json('data.draf_terpotong')]);
        $this->assertSame(
            ['dasar' => PenentuVersiAcuan::sha256($this->v1), 'peta' => $this->peta->sha256, 'workbook' => $this->wb->sha256],
            $res->json('data.hash'),
        );
        $this->assertSame(
            [['DATABASE!T5', 'cmc[kode=A].u95_um', '0.83'], ['DATABASE!T6', 'cmc[kode=B].u95_um', '0.87'], ['DATABASE!T7', 'cmc[kode=C].u95_um', '0.91'], ['DATABASE!T8', 'cmc[kode=D].u95_um', '0.91']],
            array_map(static fn (array $s): array => [$s['sel'], $s['jalur'], $s['nilai']], $res->json('data.suntingan')),
        );
        $this->assertStringContainsString('"nilai":"0.83"', (string) $res->getContent());
        $this->assertSame([], $res->json('data.draf'));
    }

    public function test_tidak_menulis_apa_pun_dan_tidak_membuat_paket(): void
    {
        $sebelum = $this->hitung();
        $v1 = $this->atributVersi($this->v1->id);

        $this->bacaDasar()->assertOk();
        $this->bacaDasar()->assertOk();

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($v1, $this->atributVersi($this->v1->id));
    }

    public function test_hash_dari_dasar_langsung_dipakai_post_pertama_tanpa_menebak(): void
    {
        $dasar = $this->bacaDasar()->assertOk()->json('data');

        $this->postJson('/api/studio/workbook/'.$this->wb->id.'/draf', [
            'workbook_sha256' => $dasar['hash']['workbook'],
            'peta_sel_sha256' => $dasar['hash']['peta'],
            'base_isi_sha256' => $dasar['hash']['dasar'],
            'alasan' => 'Koreksi pertama dari hash yang diberikan server',
            'suntingan' => [['sel' => $dasar['suntingan'][0]['sel'], 'nilai' => '0.84']],
        ])->assertCreated()->assertJsonPath('data.hash.dasar', $dasar['hash']['dasar']);
    }

    public function test_draf_yang_ada_terdaftar_terbaru_dulu_dengan_hash_isi_dan_dasar_tak_berubah(): void
    {
        $a = $this->buatDraf();
        $b = $this->buatDraf([['sel' => 'DATABASE!T5', 'nilai' => '0.85']]);

        $res = $this->bacaDasar()->assertOk();

        $this->assertSame(
            [
                ['id' => $b['id'], 'nomor_versi' => 3, 'hash' => ['isi' => $b['hash']], 'dasar_versi_id' => $this->v1->id, 'alasan' => 'Koreksi CMC pita sesuai lampiran akreditasi', 'dibuat_pada' => PaketAcuanVersi::query()->findOrFail($b['id'])->created_at->toIso8601String()],
                ['id' => $a['id'], 'nomor_versi' => 2, 'hash' => ['isi' => $a['hash']], 'dasar_versi_id' => $this->v1->id, 'alasan' => 'Koreksi CMC pita sesuai lampiran akreditasi', 'dibuat_pada' => PaketAcuanVersi::query()->findOrFail($a['id'])->created_at->toIso8601String()],
            ],
            $res->json('data.draf'),
        );
        $this->assertSame([2, false], [$res->json('data.draf_total'), $res->json('data.draf_terpotong')]);
        $this->assertSame(PenentuVersiAcuan::sha256($this->v1), $res->json('data.hash.dasar'), 'draf tidak menggeser dasar');
        $this->assertSame('0.83', $res->json('data.suntingan.0.nilai'), 'proyeksi dari DASAR, bukan dari draf');
    }

    public function test_daftar_draf_maksimal_dua_puluh_terbaru_hanya_status_draf(): void
    {
        $teks = (string) $this->v1->isi;

        for ($n = 2; $n <= 23; $n++) {
            $this->buatVersi($this->paket, $n, PaketAcuanVersi::STATUS_DRAF, $teks);
        }
        $this->buatVersi($this->paket, 24, PaketAcuanVersi::STATUS_DIAJUKAN, $teks);
        $this->buatVersi($this->paket, 25, PaketAcuanVersi::STATUS_PENSIUN, $teks, ['berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31']);

        $draf = $this->bacaDasar()->assertOk()->json('data.draf');

        $this->assertCount(20, $draf);
        $this->assertSame(range(23, 4), array_column($draf, 'nomor_versi'));
        $res = $this->bacaDasar()->assertOk();
        $this->assertSame([22, true], [$res->json('data.draf_total'), $res->json('data.draf_terpotong')], 'diajukan/pensiun tak dihitung; 22 draf > 20 ditampilkan');
    }

    public function test_paket_belum_ada_422_dan_paket_tidak_dibuat(): void
    {
        ['wb' => $wbC, 'user' => $userC] = $this->siapkanLab('c', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $this->sebagaiStudio($userC);
        $sebelum = $this->hitung();

        $this->bacaDasar($wbC)->assertStatus(422)->assertJsonPath('kode', 'paket_belum_ada');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame(0, PaketAcuan::query()->where('organization_id', $wbC->organization_id)->count());
    }

    public function test_profil_tak_terpetakan_422(): void
    {
        ['wb' => $wbPh, 'user' => $userPh] = $this->siapkanLab('ph', null, 'Master Olah Data pH Meter', false, 'ph_meter');
        $this->sebagaiStudio($userPh);

        $this->bacaDasar($wbPh)->assertStatus(422)->assertJsonPath('kode', 'profil_belum_terpetakan');
    }

    public function test_varian_tak_dikenal_tidak_punya_sel_yang_bisa_disunting(): void
    {
        ['wb' => $wbX, 'user' => $userX] = $this->siapkanLab('x', null, 'Master Micrometer Baru');
        $this->sebagaiStudio($userX);

        $this->bacaDasar($wbX)->assertOk()->assertJsonPath('data.suntingan', []);
    }

    public function test_workbook_tidak_aktif_422_dan_diarsipkan_404(): void
    {
        $this->wb->forceFill(['aktif' => false])->save();
        $sebelum = $this->hitung();
        $this->bacaDasar()->assertStatus(422)->assertJsonPath('kode', 'workbook_tidak_aktif');

        $this->wb->forceFill(['aktif' => true, 'diarsipkan_pada' => now()])->save();
        $sebelum = $this->hitung();
        $this->bacaDasar()->assertNotFound();

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_lab_lain_dan_tak_ada_404_identik_dan_data_lab_lain_tidak_bocor(): void
    {
        ['wb' => $wbB] = $this->siapkanLab('b');
        $this->sebagaiStudio($this->studio);
        $sebelum = $this->hitung();

        $lain = $this->bacaDasar($wbB);
        $takAda = $this->getJson('/api/studio/workbook/999999/draf-dasar');

        $lain->assertNotFound();
        $takAda->assertNotFound();
        $this->assertSame($takAda->getContent(), $lain->getContent());
        $this->assertStringNotContainsString($wbB->sha256, (string) $lain->getContent());
        // Satu-satunya tulisan: jejak akses lintas lab milik PEMANGGIL (PenjagaOrganisasi).
        $this->assertSame($sebelum['audit'] + 1, $this->hitung()['audit']);
        $this->assertSame($sebelum, array_replace($this->hitung(), ['audit' => $sebelum['audit']]));
    }

    /** @return iterable<string, array{string}> */
    public static function idWorkbookAneh(): iterable
    {
        foreach (['abc', '0', '-5', '1e3', '1.5', '99999999999999999999999999999'] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('idWorkbookAneh')]
    public function test_id_workbook_aneh_404_bukan_500(string $id): void
    {
        $this->getJson("/api/studio/workbook/{$id}/draf-dasar")->assertNotFound();
        $this->getJson("/api/studio/workbook/{$id}/draf/1")->assertNotFound();
    }

    public function test_dasar_dari_berkas_json_saat_paket_hanya_punya_versi_pensiun_dan_diajukan(): void
    {
        ['wb' => $wb, 'user' => $user, 'lab' => $lab] = $this->siapkanLab('j', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $paket = PaketAcuan::create(['organization_id' => $lab->id, 'kode_profil' => 'micrometer', 'nama' => 'Micrometer', 'skema_versi' => 1]);
        $this->buatVersi($paket, 1, PaketAcuanVersi::STATUS_PENSIUN, $this->teksDasar(), ['berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31']);
        $this->buatVersi($paket, 2, PaketAcuanVersi::STATUS_DIAJUKAN, $this->teksDasar());
        $this->sebagaiStudio($user);

        $res = $this->bacaDasar($wb)->assertOk();

        $this->assertSame(JsonKanonik::sha256($this->teksDasar()), $res->json('data.hash.dasar'), 'hash berkas JSON kanonik');
        $this->assertSame(['0.83', '0.87', '0.91', '0.91'], array_column($res->json('data.suntingan'), 'nilai'));
        $this->assertSame([], $res->json('data.draf'));
    }

    /** @return iterable<string, array{string}> */
    public static function dasarTidakStabil(): iterable
    {
        yield 'objek kosong' => [',"zz":{}'];
        yield 'objek berkunci numerik' => [',"zz":{"0":1.5,"1":2.5}'];
        yield 'bilangan di luar int64' => [',"zz":99999999999999999999'];
    }

    #[DataProvider('dasarTidakStabil')]
    public function test_dasar_tidak_stabil_kanonik_409_isi_tidak_stabil_seperti_post(string $sisip): void
    {
        $teks = substr($this->teksDasar(), 0, -1).$sisip.'}';
        ['wb' => $wb, 'user' => $user] = $this->siapkanLab('t', $teks);
        $this->sebagaiStudio($user);
        $sebelum = $this->hitung();

        $this->bacaDasar($wb)->assertStatus(409)->assertJsonPath('kode', 'isi_tidak_stabil');
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_terpotong_tepat_di_batas_dua_puluh_dan_dua_puluh_satu(): void
    {
        $teks = (string) $this->v1->isi;

        for ($n = 2; $n <= 21; $n++) {
            $this->buatVersi($this->paket, $n, PaketAcuanVersi::STATUS_DRAF, $teks);
        }
        $pas = $this->bacaDasar()->assertOk();
        $this->assertSame([20, 20, false], [$pas->json('data.draf_total'), count($pas->json('data.draf')), $pas->json('data.draf_terpotong')]);

        $this->buatVersi($this->paket, 22, PaketAcuanVersi::STATUS_DRAF, $teks);
        $lebih = $this->bacaDasar()->assertOk();
        $this->assertSame([21, 20, true], [$lebih->json('data.draf_total'), count($lebih->json('data.draf')), $lebih->json('data.draf_terpotong')]);
    }

    public function test_dasar_versi_id_null_untuk_dasar_berkas_dan_id_versi_berlaku_bila_ada(): void
    {
        ['wb' => $wb, 'user' => $user, 'lab' => $lab] = $this->siapkanLab('j', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $paket = PaketAcuan::create(['organization_id' => $lab->id, 'kode_profil' => 'micrometer', 'nama' => 'Micrometer', 'skema_versi' => 1]);
        $this->buatVersi($paket, 1, PaketAcuanVersi::STATUS_PENSIUN, $this->teksDasar(), ['berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31']);
        $this->sebagaiStudio($user);

        $this->bacaDasar($wb)->assertOk()->assertJsonPath('data.dasar_versi_id', null);

        $aktif = $this->buatVersi($paket, 2, PaketAcuanVersi::STATUS_AKTIF, $this->teksDasar(), ['berlaku_mulai' => now()->toDateString()]);
        $this->bacaDasar($wb)->assertOk()->assertJsonPath('data.dasar_versi_id', $aktif->id);
    }
}
