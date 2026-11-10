<?php

namespace Tests\Feature;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Studio\Draf\PemetaanSuntingan;
use App\Services\Studio\Draf\ProyeksiDraf;
use App\Support\JsonKanonik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FixtureDrafAcuan;
use Tests\TestCase;

/**
 * `GET /api/studio/workbook/{workbook}/draf/{versi}` (rencana A1, B4): muat ulang
 * satu versi `data` milik paket workbook itu. Status APA PUN boleh dibaca; hanya
 * PUT yang menuntut draf. Tidak menulis apa pun. Fixture: `FixtureDrafAcuan`.
 * (GET sesudah PUT identik dengan respons PUT: `StudioDrafAcuanPerbaruiTest`.)
 */
class StudioDrafAcuanMuatTest extends TestCase
{
    use FixtureDrafAcuan;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDrafAcuan();
    }

    public function test_get_draf_bentuk_lengkap_dan_sama_dengan_respons_post_selain_status(): void
    {
        $d = $this->buatDraf();

        $res = $this->bacaDraf($d['id'])->assertOk();

        $res->assertJsonPath('data.status', 'draf_dimuat')
            ->assertJsonPath('data.versi.id', $d['id'])
            ->assertJsonPath('data.versi.nomor_versi', 2)
            ->assertJsonPath('data.versi.status', 'draf')
            ->assertJsonPath('data.versi.dasar_versi_id', $this->v1->id)
            ->assertJsonPath('data.versi.dasar_versi_id_sekarang', $this->v1->id)
            ->assertJsonPath('data.versi.dasar_basi', false)
            ->assertJsonPath('data.versi.alasan', 'Koreksi CMC pita sesuai lampiran akreditasi');
        $this->assertEqualsCanonicalizing(['id', 'nomor_versi', 'status', 'dasar_versi_id', 'dasar_versi_id_sekarang', 'dasar_basi', 'alasan', 'dibuat_pada'], array_keys($res->json('data.versi')));
        $this->assertSame(
            PaketAcuanVersi::query()->findOrFail($d['id'])->created_at->toIso8601String(),
            $res->json('data.versi.dibuat_pada'),
        );
        $this->assertSame(
            ['isi' => $d['hash'], 'dasar' => PenentuVersiAcuan::sha256($this->v1), 'workbook' => $this->wb->sha256, 'peta' => $this->peta->sha256],
            $res->json('data.hash'),
        );
        $this->assertSame(
            array_diff_key($d['respons']->json('data'), ['status' => 1]),
            array_diff_key($res->json('data'), ['status' => 1]),
        );

        // Proyeksi == hitung ulang dari isi tersimpan; nilai selalu string.
        $versi = PaketAcuanVersi::query()->findOrFail($d['id']);
        $this->assertSame(ProyeksiDraf::dari($versi->isiTerurai(), PemetaanSuntingan::untukMaster($this->peta, $this->wb)), $res->json('data.suntingan'));
        $this->assertSame(['0.84', '0.87', '0.91', '0.91'], array_column($res->json('data.suntingan'), 'nilai'));
        $this->assertStringContainsString('"nilai":"0.84"', (string) $res->getContent());
    }

    public function test_get_tidak_menulis_apa_pun(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);
        $v1 = $this->atributVersi($this->v1->id);

        $this->bacaDraf($d['id'])->assertOk();
        $this->bacaDraf($d['id'])->assertOk();
        $this->bacaDraf($this->v1->id)->assertOk();

        $this->assertSame($sebelum, $this->hitung(), 'versi, suntingan, log, audit_logs, paket, hitungan');
        $this->assertSame($atribut, $this->atributVersi($d['id']));
        $this->assertSame($v1, $this->atributVersi($this->v1->id));
    }

    /** @return iterable<string, array{string}> */
    public static function statusBisaDibaca(): iterable
    {
        foreach ([PaketAcuanVersi::STATUS_AKTIF, PaketAcuanVersi::STATUS_PENSIUN, PaketAcuanVersi::STATUS_DIAJUKAN, PaketAcuanVersi::STATUS_DITOLAK, PaketAcuanVersi::STATUS_DIBUANG] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('statusBisaDibaca')]
    public function test_versi_status_apa_pun_boleh_dibaca_dengan_hash_isi_dari_teks(string $status): void
    {
        $isi = $this->isiV1();
        $isi['cmc'][$this->indeksPita($isi, 'C')]['u95_um'] = 0.95;
        $teks = JsonKanonik::serialisasi($isi);
        $versi = $this->buatVersi($this->paket, 2, $status, $teks);

        $res = $this->bacaDraf($versi->id)->assertOk();

        $res->assertJsonPath('data.versi.status', $status)->assertJsonPath('data.hash.isi', JsonKanonik::sha256($teks));
        // dasar_basi hanya bermakna untuk DRAF: versi bukan-draf (dasar_versi_id null vs dasar sekarang v1) tidak "basi".
        $res->assertJsonPath('data.versi.dasar_versi_id', null)->assertJsonPath('data.versi.dasar_basi', false);
        $this->assertSame(['0.83', '0.87', '0.95', '0.91'], array_column($res->json('data.suntingan'), 'nilai'));
    }

    public function test_versi_aktif_dibaca_dengan_hash_isi_sha256_kolom(): void
    {
        $this->bacaDraf($this->v1->id)
            ->assertOk()
            ->assertJsonPath('data.versi.status', 'aktif')
            ->assertJsonPath('data.hash.isi', $this->v1->isi_sha256)
            // v1 hasil impor awal: tak punya dasar (null), dasar sekarang = dirinya sendiri; BUKAN draf basi.
            ->assertJsonPath('data.versi.dasar_versi_id', null)
            ->assertJsonPath('data.versi.dasar_versi_id_sekarang', $this->v1->id)
            ->assertJsonPath('data.versi.dasar_basi', false);
    }

    public function test_versi_lab_lain_paket_lain_jenis_bentuk_dan_id_aneh_semua_404_identik(): void
    {
        ['wb' => $wbB, 'v1' => $v1B] = $this->siapkanLab('b');
        $paketLain = PaketAcuan::create(['organization_id' => $this->lab->id, 'kode_profil' => 'dial_indicator', 'nama' => 'Dial', 'skema_versi' => 1]);
        $versiPaketLain = $this->buatVersi($paketLain, 1, PaketAcuanVersi::STATUS_DRAF, (string) $this->v1->isi);
        $bentuk = $this->buatVersi($this->paket, 2, PaketAcuanVersi::STATUS_DRAF, (string) $this->v1->isi, ['jenis' => PaketAcuanVersi::JENIS_BENTUK]);
        $this->sebagaiStudio($this->studio);
        $sebelum = $this->hitung();

        $acuan = $this->bacaDraf(999999);
        $acuan->assertNotFound();

        foreach ([$v1B->id, $versiPaketLain->id, $bentuk->id, 'abc', '0', '-1', '1e3', '1.5', str_repeat('9', 30)] as $id) {
            $res = $this->bacaDraf($id);
            $res->assertNotFound();
            $this->assertSame($acuan->getContent(), $res->getContent(), "versi {$id} tak boleh dibedakan dari tak ada");
        }

        $this->assertSame($sebelum, $this->hitung());

        // Workbook lab lain (dengan versi lab sendiri atau lab itu) 404 juga.
        $this->bacaDraf($v1B->id, $wbB)->assertNotFound();
        $this->bacaDraf($this->v1->id, $wbB)->assertNotFound();
    }

    public function test_isi_tidak_stabil_409_dan_bukan_json_409(): void
    {
        $d = $this->buatDraf();
        $teks = (string) PaketAcuanVersi::query()->findOrFail($d['id'])->isi;

        PaketAcuanVersi::query()->whereKey($d['id'])->update(['isi' => substr($teks, 0, -1).',"zz":{}}']);
        $this->bacaDraf($d['id'])->assertStatus(409)->assertJsonPath('kode', 'isi_tidak_stabil');

        PaketAcuanVersi::query()->whereKey($d['id'])->update(['isi' => 'bukan json {']);
        $this->bacaDraf($d['id'])->assertStatus(409)->assertJsonPath('kode', 'isi_tidak_stabil');
    }

    public function test_profil_tak_terpetakan_422(): void
    {
        ['wb' => $wbPh, 'user' => $userPh] = $this->siapkanLab('ph', null, 'Master Olah Data pH Meter', false, 'ph_meter');
        $this->sebagaiStudio($userPh);

        $this->bacaDraf(1, $wbPh)->assertStatus(422)->assertJsonPath('kode', 'profil_belum_terpetakan');
    }

    public function test_workbook_diarsipkan_404_tetapi_tidak_aktif_masih_bisa_membaca_draf(): void
    {
        $d = $this->buatDraf();

        // Membaca riwayat draf tidak menuntut master aktif (hanya menulis/dasar yang menuntut).
        $this->wb->forceFill(['aktif' => false])->save();
        $this->bacaDraf($d['id'])->assertOk()->assertJsonPath('data.status', 'draf_dimuat');

        $this->wb->forceFill(['aktif' => true, 'diarsipkan_pada' => now()])->save();
        $this->bacaDraf($d['id'])->assertNotFound();
    }

    public function test_hash_dasar_adalah_dasar_sekarang_bukan_dasar_asal_draf(): void
    {
        $d = $this->buatDraf();
        $isi = $this->isiV1();
        $isi['cmc'][$this->indeksPita($isi, 'B')]['u95_um'] = 0.99;
        $teks = JsonKanonik::serialisasi($isi);
        $this->v1->forceFill(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => now()->subDay()->toDateString()])->save();
        $v2 = $this->buatVersi($this->paket, 3, PaketAcuanVersi::STATUS_AKTIF, $teks, ['berlaku_mulai' => now()->toDateString()]);

        $res = $this->bacaDraf($d['id'])->assertOk();

        $this->assertSame(JsonKanonik::sha256($teks), $res->json('data.hash.dasar'));
        $this->assertSame($this->v1->id, $res->json('data.versi.dasar_versi_id'), 'asal draf tetap v1');
        $this->assertNotSame($v2->id, $res->json('data.versi.dasar_versi_id'));
    }

    public function test_hash_isi_get_cocok_dengan_hash_yang_dipakai_put(): void
    {
        $d = $this->buatDraf();
        $hash = $this->bacaDraf($d['id'])->assertOk()->json('data.hash.isi');

        $this->kirimPut($d['id'], $this->badanPut($hash))->assertOk()->assertJsonPath('data.status', 'draf_diperbarui');
    }

    public function test_daun_pemetaan_hilang_disaring_bukan_500(): void
    {
        $d = $this->buatDraf();
        $isi = PaketAcuanVersi::query()->findOrFail($d['id'])->isiTerurai();
        unset($isi['cmc'][$this->indeksPita($isi, 'D')]);
        $isi['cmc'] = array_values($isi['cmc']);
        $isi['cmc'][$this->indeksPita($isi, 'C')]['u95_um'] = 'n/a';
        PaketAcuanVersi::query()->whereKey($d['id'])->update(['isi' => JsonKanonik::serialisasi($isi)]);

        $this->bacaDraf($d['id'])->assertOk()->assertJsonPath('data.suntingan.0.sel', 'DATABASE!T5');
        $this->assertSame(['DATABASE!T5', 'DATABASE!T6'], array_column($this->bacaDraf($d['id'])->json('data.suntingan'), 'sel'));
    }

    public function test_workbook_tanpa_paket_di_labnya_404_sama_dengan_tak_ada(): void
    {
        ['wb' => $wbC, 'user' => $userC] = $this->siapkanLab('c', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $this->sebagaiStudio($userC);
        $acuan = $this->getJson('/api/studio/workbook/'.$wbC->id.'/draf/999999');

        // Versi lab A (id sungguhan) lewat workbook lab C yang tak punya paket: 404 yang sama.
        $res = $this->bacaDraf($this->v1->id, $wbC);

        $acuan->assertNotFound();
        $res->assertNotFound();
        $this->assertSame($acuan->getContent(), $res->getContent());
    }

    public function test_dasar_basi_true_bila_versi_berlaku_sudah_berganti_sesudah_draf_dibuat(): void
    {
        $d = $this->buatDraf();
        $isi = $this->isiV1();
        $isi['cmc'][$this->indeksPita($isi, 'B')]['u95_um'] = 0.99;
        $this->v1->forceFill(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => now()->subDay()->toDateString()])->save();
        $v2 = $this->buatVersi($this->paket, 3, PaketAcuanVersi::STATUS_AKTIF, JsonKanonik::serialisasi($isi), ['berlaku_mulai' => now()->toDateString()]);

        $res = $this->bacaDraf($d['id'])->assertOk();

        $res->assertJsonPath('data.versi.dasar_versi_id', $this->v1->id)
            ->assertJsonPath('data.versi.dasar_versi_id_sekarang', $v2->id)
            ->assertJsonPath('data.versi.dasar_basi', true);
        // Sebaliknya: draf dari v2 terhadap v2 tidak basi.
        $d2 = $this->kirim($this->badan(['base_isi_sha256' => PenentuVersiAcuan::sha256($v2)]))->assertCreated();
        $this->bacaDraf($d2->json('data.versi.id'))->assertOk()
            ->assertJsonPath('data.versi.dasar_versi_id', $v2->id)
            ->assertJsonPath('data.versi.dasar_basi', false);
    }

    public function test_dasar_basi_untuk_dasar_berkas_json_dan_perpindahan_antara_berkas_dan_versi(): void
    {
        ['wb' => $wb, 'user' => $user, 'lab' => $lab] = $this->siapkanLab('j', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $paket = PaketAcuan::create(['organization_id' => $lab->id, 'kode_profil' => 'micrometer', 'nama' => 'Micrometer', 'skema_versi' => 1]);
        $this->buatVersi($paket, 1, PaketAcuanVersi::STATUS_PENSIUN, $this->teksDasar(), ['berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31']);
        $this->sebagaiStudio($user);
        $draf = $this->kirim($this->badan(['base_isi_sha256' => JsonKanonik::sha256($this->teksDasar())], $wb), $wb)->assertCreated();
        $id = $draf->json('data.versi.id');

        // Dasar berkas (null) dan masih berkas: tidak basi.
        $this->bacaDraf($id, $wb)->assertOk()
            ->assertJsonPath('data.versi.dasar_versi_id', null)
            ->assertJsonPath('data.versi.dasar_versi_id_sekarang', null)
            ->assertJsonPath('data.versi.dasar_basi', false);

        // Versi aktif muncul sesudah draf dari berkas: basi.
        $aktif = $this->buatVersi($paket, 3, PaketAcuanVersi::STATUS_AKTIF, $this->teksDasar(), ['berlaku_mulai' => now()->toDateString()]);
        $this->bacaDraf($id, $wb)->assertOk()
            ->assertJsonPath('data.versi.dasar_versi_id_sekarang', $aktif->id)
            ->assertJsonPath('data.versi.dasar_basi', true);
    }
}
