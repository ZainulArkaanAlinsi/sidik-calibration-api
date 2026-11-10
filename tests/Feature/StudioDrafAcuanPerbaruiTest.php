<?php

namespace Tests\Feature;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\AlurVersiAcuan;
use App\Services\Studio\Draf\PemetaanSuntingan;
use App\Services\Studio\Draf\ProyeksiDraf;
use App\Support\JsonKanonik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\FixtureDrafAcuan;
use Tests\Concerns\PerekamKunci;
use Tests\TestCase;

/**
 * `PUT /api/studio/workbook/{workbook}/draf/{versi}` (rencana A1, B4): suntingan
 * angka ke draf yang SUDAH ada. Fixture: `Tests\Concerns\FixtureDrafAcuan`
 * (micrometer varian B; sel sah `DATABASE!T5..T8` -> `cmc[kode=A..D].u95_um`).
 *
 * Yang dikunci: hanya kolom `isi` yang ditulis; CAS `expected_isi_sha256`;
 * draf beku tak pernah ditimpa; retry idempoten; jejak (suntingan + log `sunting`);
 * rollback total; organisasi/paket/jenis; workbook dibaca ulang; kunci paket -> versi
 * (dibuktikan lewat perekam global-scope karena SQLite membuang `FOR UPDATE`).
 * Gerbang akses/sakelar: `StudioDrafAcuanGerbangTest`. Bukti kunci/unik
 * sesungguhnya antar-koneksi = gate MySQL.
 */
class StudioDrafAcuanPerbaruiTest extends TestCase
{
    use FixtureDrafAcuan;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDrafAcuan();
    }

    protected function tearDown(): void
    {
        $this->lepasHookWorkbook();
        parent::tearDown();
    }

    // ---- sukses -------------------------------------------------------------------

    public function test_put_sah_hanya_mengubah_kolom_isi_dan_menulis_jejak_lengkap(): void
    {
        $d = $this->buatDraf();
        $versiSebelum = $this->atributVersi($d['id']);
        $v1Sebelum = $this->atributVersi($this->v1->id);
        $logDibuat = PaketAcuanLog::query()->where('aksi', PaketAcuanLog::AKSI_DIBUAT)->sole();
        $sebelum = $this->hitung();

        $res = $this->kirimPut($d['id'], $this->badanPut($d['hash']));

        $res->assertOk()
            ->assertJsonPath('data.status', 'draf_diperbarui')
            ->assertJsonPath('data.versi.id', $d['id'])
            ->assertJsonPath('data.versi.nomor_versi', 2)
            ->assertJsonPath('data.versi.status', 'draf')
            ->assertJsonPath('data.versi.dasar_versi_id', $this->v1->id)
            ->assertJsonPath('data.versi.alasan', 'Koreksi CMC pita sesuai lampiran akreditasi');

        // Isi = isi lama + SATU daun (T6 -> 0.88), byte kanonik utuh.
        $harap = $this->isiV1();
        $harap['cmc'][$this->indeksPita($harap, 'A')]['u95_um'] = 0.84;
        $harap['cmc'][$this->indeksPita($harap, 'B')]['u95_um'] = 0.88;
        $sesudah = $this->atributVersi($d['id']);
        $this->assertSame(JsonKanonik::serialisasi($harap), $sesudah['isi']);
        $this->assertNotSame($versiSebelum['isi'], $sesudah['isi']);

        // HANYA isi: kolom lain (kecuali updated_at otomatis) identik, termasuk isi_sha256 NULL, status, lock_versi.
        $tanpaIsi = static fn (array $a): array => array_diff_key($a, ['isi' => 1, 'updated_at' => 1]);
        $this->assertSame($tanpaIsi($versiSebelum), $tanpaIsi($sesudah));
        $this->assertNull($sesudah['isi_sha256']);
        $this->assertSame('draf', $sesudah['status']);
        $this->assertSame(0, (int) $sesudah['lock_versi']);
        $this->assertSame($v1Sebelum, $this->atributVersi($this->v1->id), 'versi aktif tak tersentuh');

        // Hash: respons == hash teks tersimpan == hash_sesudah di log; beda dari sebelumnya.
        $hashSesudah = JsonKanonik::sha256($sesudah['isi']);
        $this->assertSame($hashSesudah, $res->json('data.hash.isi'));
        $this->assertNotSame($d['hash'], $hashSesudah);

        // Baris suntingan: dari selisih isi lama/baru, sumber ketik, pelaku, alasan PUT.
        $b = 'cmc['.$this->indeksPita($harap, 'B').']';
        $baris = PaketAcuanSuntingan::query()->where('paket_acuan_versi_id', $d['id'])->orderByDesc('id')->first();
        $this->assertSame(
            [$b, null, 'u95_um', '0.87', '0.88', 'ketik', $this->studio->id, 'Koreksi lanjutan CMC pita B sesuai lampiran'],
            [$baris->lembar, $baris->kunci_baris, $baris->kolom, $baris->nilai_lama, $baris->nilai_baru, $baris->sumber, $baris->user_id, $baris->alasan],
        );

        // Log `sunting`: status draf -> draf, hash sebelum/sesudah, sumber_ui, sumber workbook/peta/dasar.
        $log = PaketAcuanLog::query()->where('aksi', PaketAcuanLog::AKSI_SUNTING)->sole();
        $this->assertSame(
            [$d['id'], 'draf', 'draf', $this->studio->id, 'Koreksi lanjutan CMC pita B sesuai lampiran', $hashSesudah],
            [$log->paket_acuan_versi_id, $log->status_dari, $log->status_ke, $log->user_id, $log->alasan, $log->isi_sha256],
        );
        $this->assertSame(
            ['sel_berubah' => 1, 'hash_sebelum' => $d['hash'], 'hash_sesudah' => $hashSesudah, 'sel' => [['sel' => 'DATABASE!T6', 'jalur' => 'cmc[kode=B].u95_um']]],
            $log->rincian,
        );
        $this->assertSame('studio', $log->sumber['sumber_ui']);
        $this->assertSame(
            [$this->wb->id, $this->wb->sha256, $this->wb->nama, 'micrometer', $this->peta->sha256, 'B', $logDibuat->sumber['dasar'], $logDibuat->sumber['dasar_sha256']],
            [$log->sumber['workbook_master_id'], $log->sumber['workbook_sha256'], $log->sumber['workbook_nama'], $log->sumber['peta_sel'], $log->sumber['peta_sel_sha256'], $log->sumber['varian'], $log->sumber['dasar'], $log->sumber['dasar_sha256']],
        );

        // Hitungan: +1 suntingan, +1 log; tanpa versi/paket/audit_logs/hitungan baru.
        $this->assertSame(
            ['paket' => $sebelum['paket'], 'versi' => $sebelum['versi'], 'suntingan' => $sebelum['suntingan'] + 1, 'log' => $sebelum['log'] + 1, 'audit' => $sebelum['audit'], 'hitungan' => $sebelum['hitungan']],
            $this->hitung(),
        );
    }

    public function test_respons_put_nilai_string_sama_dengan_baca_ulang_dari_db_dan_get_sesudahnya_identik(): void
    {
        $d = $this->buatDraf();
        $put = $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '0.88'], ['sel' => 'DATABASE!T7', 'nilai' => '1']]]));
        $put->assertOk();

        foreach ($put->json('data.suntingan') as $s) {
            $this->assertIsString($s['nilai'], 'nilai selalu STRING');
        }
        $this->assertSame(['0.84', '0.88', '1.0', '0.91'], array_column($put->json('data.suntingan'), 'nilai'));

        $versi = PaketAcuanVersi::query()->findOrFail($d['id']);
        $dariDb = ProyeksiDraf::dari($versi->isiTerurai(), PemetaanSuntingan::untukMaster($this->peta, $this->wb));
        $this->assertSame($dariDb, $put->json('data.suntingan'), 'respons == baca ulang dari DB');

        // GET sesudah PUT: seluruh badan sama kecuali penanda status.
        $get = $this->bacaDraf($d['id'])->assertOk();
        $this->assertSame('draf_dimuat', $get->json('data.status'));
        $this->assertSame(
            array_diff_key($put->json('data'), ['status' => 1]),
            array_diff_key($get->json('data'), ['status' => 1]),
        );
        $this->assertSame(str_replace('draf_diperbarui', 'draf_dimuat', (string) $put->getContent()), (string) $get->getContent(), 'byte JSON identik selain status');
    }

    // ---- CAS expected_isi_sha256 -------------------------------------------------------

    public function test_expected_salah_409_draf_berubah_dengan_hash_terbaru_dan_tak_menulis(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);

        $res = $this->kirimPut($d['id'], $this->badanPut(str_repeat('0', 64)));

        $res->assertStatus(409)->assertJsonPath('kode', 'draf_berubah');
        $this->assertSame(
            ['hash_terbaru' => ['workbook' => $this->wb->sha256, 'peta' => $this->peta->sha256, 'isi' => $d['hash']]],
            $res->json('rincian'),
            'hanya hash -- tidak ada isi lain',
        );
        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']), 'isi + isi_sha256 + updated_at tak berubah');
    }

    public function test_retry_dengan_expected_lama_dan_nilai_yang_sudah_ada_200_tidak_berubah_tanpa_audit_ganda(): void
    {
        $d = $this->buatDraf();
        $badan = $this->badanPut($d['hash']);

        $pertama = $this->kirimPut($d['id'], $badan)->assertOk()->assertJsonPath('data.status', 'draf_diperbarui');
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);

        // Respons pertama "hilang"; klien mengirim ulang body yang sama dengan expected LAMA.
        $kedua = $this->kirimPut($d['id'], $badan);

        $kedua->assertOk()->assertJsonPath('data.status', 'tidak_berubah');
        $this->assertSame($pertama->json('data.hash.isi'), $kedua->json('data.hash.isi'));
        $this->assertSame($pertama->json('data.suntingan'), $kedua->json('data.suntingan'));
        $this->assertSame($sebelum, $this->hitung(), 'tanpa baris suntingan/log ganda');
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    public function test_hash_cocok_tetapi_nilai_sama_dengan_isi_sekarang_200_tidak_berubah(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);

        foreach (['0.84', '0.840'] as $nilai) {
            $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => $nilai]]]))
                ->assertOk()
                ->assertJsonPath('data.status', 'tidak_berubah')
                ->assertJsonPath('data.hash.isi', $d['hash']);
        }

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    public function test_expected_lama_dengan_nilai_berbeda_untuk_sel_yang_sama_409(): void
    {
        $d = $this->buatDraf();
        $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '0.88']]]))->assertOk();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);

        $res = $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '0.89']]]));

        $res->assertStatus(409)->assertJsonPath('kode', 'draf_berubah')->assertJsonPath('rincian.hash_terbaru.isi', $this->hashIsi($d['id']));
        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    public function test_konflik_sel_lain_dengan_expected_lama_409_bukan_tidak_berubah(): void
    {
        $d = $this->buatDraf();
        // Penyimpan A mengubah T6; penyimpan B (masih memegang hash lama) mengubah T7.
        $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '0.88']]]))->assertOk();
        $sebelum = $this->hitung();

        $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T7', 'nilai' => '0.95']]]))
            ->assertStatus(409)
            ->assertJsonPath('kode', 'draf_berubah');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame(0.91, PaketAcuanVersi::query()->findOrFail($d['id'])->isiTerurai()['cmc'][2]['u95_um'], 'T7 tak tertimpa');
    }

    public function test_expected_lama_dengan_nilai_tidak_valid_tetap_409_bukan_422(): void
    {
        $d = $this->buatDraf();
        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertOk();

        // Isi sudah bergeser: penolakan input tak boleh menutupi sinyal "muat ulang dulu".
        $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '1e3']]]))
            ->assertStatus(409)
            ->assertJsonPath('kode', 'draf_berubah');
    }

    // ---- hash workbook / peta, hash_terbaru ----------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function hashGerbangSalah(): iterable
    {
        yield 'workbook' => ['workbook_sha256', 'workbook_berubah'];
        yield 'peta' => ['peta_sel_sha256', 'peta_berubah'];
    }

    #[DataProvider('hashGerbangSalah')]
    public function test_hash_workbook_atau_peta_salah_409_dengan_hash_terbaru_dan_tak_menulis(string $kolom, string $kode): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();

        $res = $this->kirimPut($d['id'], $this->badanPut($d['hash'], [$kolom => str_repeat('0', 64)]));

        $res->assertStatus(409)->assertJsonPath('kode', $kode);
        $this->assertSame(
            ['workbook' => $this->wb->sha256, 'peta' => $this->peta->sha256, 'isi' => $d['hash']],
            $res->json('rincian.hash_terbaru'),
        );
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_hash_terbaru_tidak_membedakan_versi_lab_lain_dari_versi_yang_tak_ada(): void
    {
        ['v1' => $v1B] = $this->siapkanLab('b');
        $this->sebagaiStudio($this->studio);

        $lain = $this->kirimPut($v1B->id, $this->badanPut(str_repeat('0', 64), ['workbook_sha256' => str_repeat('0', 64)]));
        $takAda = $this->kirimPut(999999, $this->badanPut(str_repeat('0', 64), ['workbook_sha256' => str_repeat('0', 64)]));

        $lain->assertStatus(409)->assertJsonPath('kode', 'workbook_berubah')->assertJsonPath('rincian.hash_terbaru.isi', null);
        $this->assertSame($takAda->getContent(), $lain->getContent());
    }

    // ---- draf beku -----------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function statusBeku(): iterable
    {
        foreach ([PaketAcuanVersi::STATUS_DIAJUKAN, PaketAcuanVersi::STATUS_TERJADWAL, PaketAcuanVersi::STATUS_PENSIUN, PaketAcuanVersi::STATUS_DITOLAK, PaketAcuanVersi::STATUS_DITARIK, PaketAcuanVersi::STATUS_DIBUANG] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('statusBeku')]
    public function test_versi_bukan_draf_409_draf_beku_dan_isi_tak_berubah(string $status): void
    {
        $beku = $this->buatVersi($this->paket, 2, $status, (string) $this->v1->isi);
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($beku->id);

        $res = $this->kirimPut($beku->id, $this->badanPut($this->hashIsi($beku->id)));

        $res->assertStatus(409)->assertJsonPath('kode', 'draf_beku')->assertJsonPath('rincian.status', $status);
        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($beku->id), 'isi + isi_sha256 + semua kolom tak berubah');
    }

    public function test_versi_aktif_409_draf_beku_dan_tak_berubah(): void
    {
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($this->v1->id);

        $this->kirimPut($this->v1->id, $this->badanPut($this->hashIsi($this->v1->id)))
            ->assertStatus(409)
            ->assertJsonPath('kode', 'draf_beku')
            ->assertJsonPath('rincian.status', 'aktif');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($this->v1->id));
    }

    public function test_draf_yang_sudah_diajukan_lewat_alur_resmi_beku(): void
    {
        $d = $this->buatDraf();
        app(AlurVersiAcuan::class)->ajukan(PaketAcuanVersi::query()->findOrFail($d['id']), $this->studio, 'Ajukan untuk uji beku');
        $atribut = $this->atributVersi($d['id']);
        $this->assertSame('diajukan', $atribut['status']);
        $this->assertNotNull($atribut['isi_sha256']);
        $sebelum = $this->hitung();

        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertStatus(409)->assertJsonPath('kode', 'draf_beku');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    // ---- lintas organisasi / paket / jenis ----------------------------------------------------

    public function test_versi_lab_lain_paket_lain_jenis_bentuk_dan_id_aneh_semua_404_identik_dan_tak_menulis(): void
    {
        ['wb' => $wbB, 'paket' => $paketB, 'v1' => $v1B] = $this->siapkanLab('b');
        $paketLain = PaketAcuan::create(['organization_id' => $this->lab->id, 'kode_profil' => 'dial_indicator', 'nama' => 'Dial', 'skema_versi' => 1]);
        $versiPaketLain = $this->buatVersi($paketLain, 1, PaketAcuanVersi::STATUS_DRAF, (string) $this->v1->isi);
        $bentuk = $this->buatVersi($this->paket, 2, PaketAcuanVersi::STATUS_DRAF, (string) $this->v1->isi, ['jenis' => PaketAcuanVersi::JENIS_BENTUK]);
        $drafB = $this->buatVersi($paketB, 2, PaketAcuanVersi::STATUS_DRAF, (string) $v1B->isi);
        $this->sebagaiStudio($this->studio);

        $sebelum = $this->hitung();
        $attr = [$drafB->id => $this->atributVersi($drafB->id), $versiPaketLain->id => $this->atributVersi($versiPaketLain->id), $bentuk->id => $this->atributVersi($bentuk->id)];
        $acuan = $this->kirimPut(999999, $this->badanPut(str_repeat('a', 64)));
        $acuan->assertNotFound();

        foreach ([$drafB->id, $versiPaketLain->id, $bentuk->id, 'abc', '0', '-1', '1e3', '1.5', str_repeat('9', 30)] as $id) {
            $res = $this->kirimPut($id, $this->badanPut(str_repeat('a', 64)));
            $res->assertNotFound();
            $this->assertSame($acuan->getContent(), $res->getContent(), "versi {$id} tak boleh dibedakan dari tak ada");
        }

        $this->assertSame($sebelum, $this->hitung());

        foreach ($attr as $id => $a) {
            $this->assertSame($a, $this->atributVersi($id), "versi {$id} tak tersentuh");
        }

        // Workbook lab B + versi lab A -> 404 (workbook lab B tidak terlihat oleh akun lab A).
        $d = $this->buatDraf();
        $this->kirimPut($d['id'], $this->badanPut($d['hash']), $wbB)->assertNotFound();
    }

    // ---- isi tidak stabil, daun hilang ---------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function isiTakStabil(): iterable
    {
        yield 'objek kosong' => [',"zz":{}'];
        yield 'objek berkunci numerik' => [',"zz":{"0":1.5,"1":2.5}'];
        yield 'bilangan di luar int64' => [',"zz":99999999999999999999'];
    }

    #[DataProvider('isiTakStabil')]
    public function test_isi_draf_tidak_stabil_409_isi_tidak_stabil_tanpa_menulis(string $sisip): void
    {
        $d = $this->buatDraf();
        $teks = (string) PaketAcuanVersi::query()->findOrFail($d['id'])->isi;
        PaketAcuanVersi::query()->whereKey($d['id'])->update(['isi' => substr($teks, 0, -1).$sisip.'}']);
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);

        $this->kirimPut($d['id'], $this->badanPut($this->hashIsi($d['id'])))->assertStatus(409)->assertJsonPath('kode', 'isi_tidak_stabil');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    public function test_isi_bukan_json_409_isi_tidak_stabil_bukan_500(): void
    {
        $d = $this->buatDraf();
        PaketAcuanVersi::query()->whereKey($d['id'])->update(['isi' => 'bukan json {']);

        $this->kirimPut($d['id'], $this->badanPut($this->hashIsi($d['id'])))->assertStatus(409)->assertJsonPath('kode', 'isi_tidak_stabil');
    }

    public function test_sel_yang_daunnya_hilang_422_dan_sel_lain_tetap_bisa_disunting(): void
    {
        $d = $this->buatDraf();
        $isi = PaketAcuanVersi::query()->findOrFail($d['id'])->isiTerurai();
        unset($isi['cmc'][$this->indeksPita($isi, 'D')]);
        $isi['cmc'] = array_values($isi['cmc']);
        PaketAcuanVersi::query()->whereKey($d['id'])->update(['isi' => JsonKanonik::serialisasi($isi)]);
        $hash = $this->hashIsi($d['id']);

        $this->kirimPut($d['id'], $this->badanPut($hash, ['suntingan' => [['sel' => 'DATABASE!T8', 'nilai' => '1']]]))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sel_tidak_didukung');

        $res = $this->kirimPut($d['id'], $this->badanPut($hash))->assertOk();
        $this->assertSame(['DATABASE!T5', 'DATABASE!T6', 'DATABASE!T7'], array_column($res->json('data.suntingan'), 'sel'));
    }

    // ---- presisi & sel ---------------------------------------------------------------------------

    public function test_presisi_nol_koma_satu_lima_belas_digit_dan_minus_nol(): void
    {
        $d = $this->buatDraf();
        $res = $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [
            ['sel' => 'DATABASE!T6', 'nilai' => '0.1'],
            ['sel' => 'DATABASE!T7', 'nilai' => '123456789.123456'],
            ['sel' => 'DATABASE!T8', 'nilai' => '-0'],
        ]]))->assertOk();

        $this->assertSame(['0.84', '0.1', '123456789.123456', '0.0'], array_column($res->json('data.suntingan'), 'nilai'));
        $isi = PaketAcuanVersi::query()->findOrFail($d['id'])->isiTerurai();
        $this->assertSame(0.1, $isi['cmc'][$this->indeksPita($isi, 'B')]['u95_um']);
        $this->assertSame(123456789.123456, $isi['cmc'][$this->indeksPita($isi, 'C')]['u95_um']);
        $this->assertSame(0.0, $isi['cmc'][$this->indeksPita($isi, 'D')]['u95_um']);
        $this->assertSame('0.91', PaketAcuanSuntingan::query()->where('nilai_baru', '0.0')->sole()->nilai_lama);
    }

    /** @return iterable<string, array{string}> */
    public static function nilaiDitolak(): iterable
    {
        foreach ([
            '16 digit melewati 2^53' => '9007199254740993', '17 digit signifikan' => '12345678.123456789', 'eksponen' => '1e3',
            'tanpa nol depan' => '.5', 'plus' => '+1', 'spasi depan' => ' 0.88', 'spasi belakang' => '0.88 ', '11 pecahan' => '0.12345678901',
        ] as $nama => $nilai) {
            yield $nama => [$nilai];
        }
    }

    #[DataProvider('nilaiDitolak')]
    public function test_nilai_tak_sah_ditolak_422_dan_tak_menulis(string $nilai): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);

        $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T7', 'nilai' => '0.9'], ['sel' => 'DATABASE!T6', 'nilai' => $nilai]]]))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'suntingan_ditolak')
            ->assertJsonPath('rincian.indeks', 1)
            ->assertJsonPath('rincian.sel', 'DATABASE!T6');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    public function test_sel_di_luar_pemetaan_dan_sel_ganda_422(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();

        $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T9', 'nilai' => '1']]]))
            ->assertStatus(422)->assertJsonPath('kode', 'sel_tidak_didukung');
        $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '1'], ['sel' => 'DATABASE!T6', 'nilai' => '2']]]))
            ->assertStatus(422)->assertJsonPath('kode', 'suntingan_ditolak');

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_kunci_base_isi_sha256_di_put_dan_expected_isi_sha256_di_post_ditolak(): void
    {
        $d = $this->buatDraf();
        $badan = $this->badanPut($d['hash']);
        unset($badan['expected_isi_sha256']);
        $badan['base_isi_sha256'] = $d['hash'];

        $this->kirimPut($d['id'], $badan)->assertStatus(422)->assertJsonValidationErrors(['expected_isi_sha256', 'base_isi_sha256']);

        $post = $this->badan(['expected_isi_sha256' => $d['hash']]);
        $this->kirim($post)->assertStatus(422)->assertJsonValidationErrors('expected_isi_sha256');
    }

    // ---- bentuk request: JSON saja, batas ---------------------------------------------------------

    public function test_bodi_bukan_json_ditolak_415_dan_nilai_tidak_dipangkas_diam_diam(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);
        $badan = $this->badanPut($d['hash'], ['suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => ' 0.88']]]);
        $url = '/api/studio/workbook/'.$this->wb->id.'/draf/'.$d['id'];

        $this->call('PUT', $url, $badan)->assertStatus(415)->assertJsonPath('kode', 'harus_json');
        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($badan))
            ->assertStatus(415)->assertJsonPath('kode', 'harus_json');
        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'text/plain'], (string) json_encode($badan))
            ->assertStatus(415)->assertJsonPath('kode', 'harus_json');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    public function test_workbook_lab_lain_dengan_bodi_bukan_json_tetap_404_bukan_415(): void
    {
        ['wb' => $wbB] = $this->siapkanLab('b');
        $this->sebagaiStudio($this->studio);

        $this->call('PUT', '/api/studio/workbook/'.$wbB->id.'/draf/1', ['a' => 'b'])->assertNotFound();
        $this->call('PUT', '/api/studio/workbook/999999/draf/1', ['a' => 'b'])->assertNotFound();
    }

    public function test_query_string_tidak_menjadi_cadangan_input(): void
    {
        $d = $this->buatDraf();
        $badan = $this->badanPut($d['hash']);
        unset($badan['suntingan']);
        $sebelum = $this->hitung();

        $this->putJson('/api/studio/workbook/'.$this->wb->id.'/draf/'.$d['id'].'?'.http_build_query(['suntingan' => [['sel' => ' DATABASE!T6 ', 'nilai' => ' 0.88']]]), $badan)
            ->assertStatus(422)
            ->assertJsonValidationErrors('suntingan');

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_batas_persis_nilai_128_129_sel_256_257_pada_lapisan_request(): void
    {
        $d = $this->buatDraf();
        $kirim = fn (string $sel, string $nilai) => $this->kirimPut($d['id'], $this->badanPut($d['hash'], ['suntingan' => [['sel' => $sel, 'nilai' => $nilai]]]));

        // 128 / 256 lolos lapisan request lalu ditolak server (kode), 129 / 257 ditolak validasi (errors).
        $kirim('DATABASE!T6', str_repeat('9', 128))->assertStatus(422)->assertJsonPath('kode', 'suntingan_ditolak');
        $kirim('DATABASE!T6', str_repeat('9', 129))->assertStatus(422)->assertJsonValidationErrors('suntingan.0.nilai')->assertJsonMissingPath('kode');
        $kirim(str_repeat('A', 256), '1')->assertStatus(422)->assertJsonPath('kode', 'sel_tidak_didukung');
        $kirim(str_repeat('A', 257), '1')->assertStatus(422)->assertJsonValidationErrors('suntingan.0.sel')->assertJsonMissingPath('kode');
    }

    // ---- workbook dibaca ulang di dalam transaksi ----------------------------------------------------

    public function test_workbook_diubah_sesudah_authorize_sha256_baru_409_workbook_berubah(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $baru = str_repeat('c', 64);
        $this->ubahWorkbookSetelahDibaca(['sha256' => $baru]);

        $res = $this->kirimPut($d['id'], $this->badanPut($d['hash']));

        $res->assertStatus(409)->assertJsonPath('kode', 'workbook_berubah')->assertJsonPath('rincian.hash_terbaru.workbook', $baru);
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_diarsipkan_sesudah_authorize_404(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $this->ubahWorkbookSetelahDibaca(['diarsipkan_pada' => now()]);

        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertNotFound();
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_dinonaktifkan_sesudah_authorize_422_workbook_tidak_aktif(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $this->ubahWorkbookSetelahDibaca(['aktif' => null]);

        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertStatus(422)->assertJsonPath('kode', 'workbook_tidak_aktif');
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_tidak_aktif_ditolak_422_dan_arsip_404(): void
    {
        $d = $this->buatDraf();

        $this->wb->forceFill(['aktif' => false])->save();
        $sebelum = $this->hitung();
        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertStatus(422)->assertJsonPath('kode', 'workbook_tidak_aktif');

        $this->wb->forceFill(['aktif' => true, 'diarsipkan_pada' => now()])->save();
        $sebelum = $this->hitung();
        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertNotFound();

        $this->assertSame($sebelum, $this->hitung());
    }

    // ---- kunci: paket dulu, lalu versi -----------------------------------------------------------------

    public function test_pembacaan_pertama_dalam_transaksi_put_adalah_select_paket_terkunci_sebelum_workbook_dan_versi(): void
    {
        $d = $this->buatDraf();
        $this->pasangPerekamKunci();

        $sql = $this->sqlDalamTransaksi(fn () => $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertOk());

        $this->assertNotSame([], $sql);
        $this->assertMatchesRegularExpression('/^select .* from [`"]paket_acuan[`"]( |$)/', $sql[0], 'kueri pertama di dalam transaksi = paket_acuan');
        $this->assertSame(PerekamKunci::pertama(PaketAcuan::class), PerekamKunci::pertama(PaketAcuan::class, true), 'pembacaan paket pertama = yang terkunci');

        $urutan = array_map(static fn (string $q): string => preg_match('/from [`"]([a-z_]+)[`"]/', $q, $m) === 1 ? $m[1] : '?', $sql);
        $this->assertSame('paket_acuan', $urutan[0]);
        $this->assertGreaterThan(0, array_search('workbook_master', $urutan, true), 'workbook dibaca ulang SESUDAH paket terkunci');
    }

    public function test_put_mengunci_paket_lalu_versi_dibuktikan_perekam_global_scope(): void
    {
        $d = $this->buatDraf();
        $this->pasangPerekamKunci();

        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertOk();

        $kunciPaket = PerekamKunci::pertama(PaketAcuan::class, true);
        $kunciVersi = PerekamKunci::pertama(PaketAcuanVersi::class, true, ['id', 'paket_acuan_id', 'organization_id', 'jenis']);
        $versiPertama = PerekamKunci::pertama(PaketAcuanVersi::class);

        $this->assertNotNull($kunciPaket, 'baris paket_acuan dikunci (lockForUpdate)');
        $this->assertNotNull($kunciVersi, 'baris versi dikunci (lockForUpdate), dicari dengan org + paket + jenis');
        $this->assertLessThan($kunciVersi, $kunciPaket, 'paket dikunci SEBELUM versi (AlurVersiAcuan::kunci)');
        $this->assertLessThan($versiPertama, $kunciPaket, 'tak ada kueri versi sebelum paket terkunci');
    }

    // ---- rollback ------------------------------------------------------------------------------------------

    /** @return iterable<string, array{class-string}> */
    public static function modelGagal(): iterable
    {
        yield 'log' => [PaketAcuanLog::class];
        yield 'baris suntingan' => [PaketAcuanSuntingan::class];
    }

    /** @param  class-string  $model */
    #[DataProvider('modelGagal')]
    public function test_kegagalan_di_tengah_membatalkan_isi_dan_jejak(string $model): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);

        $model::creating(static function (): never {
            throw new RuntimeException('gagal paksa');
        });

        try {
            $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertStatus(500);
        } finally {
            Event::forget("eloquent.creating: {$model}");
        }

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']), 'isi tetap yang lama, isi_sha256 tetap null');

        // Tidak ada sisa: permintaan yang sama kini berhasil.
        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertOk()->assertJsonPath('data.status', 'draf_diperbarui');
    }

    // ---- append-only & versi lain ----------------------------------------------------------------------------

    public function test_suntingan_dan_log_append_only_dan_versi_lain_tak_berubah(): void
    {
        $lain = $this->buatVersi($this->paket, 2, PaketAcuanVersi::STATUS_PENSIUN, (string) $this->v1->isi, ['berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31']);
        $d = $this->buatDraf();
        $lainSebelum = $this->atributVersi($lain->id);
        $v1Sebelum = $this->atributVersi($this->v1->id);

        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertOk();

        $this->assertSame($lainSebelum, $this->atributVersi($lain->id));
        $this->assertSame($v1Sebelum, $this->atributVersi($this->v1->id));

        $baris = PaketAcuanSuntingan::query()->orderByDesc('id')->firstOrFail();
        $log = PaketAcuanLog::query()->where('aksi', PaketAcuanLog::AKSI_SUNTING)->firstOrFail();

        foreach ([[$baris, ['nilai_baru' => 'x']], [$log, ['alasan' => 'x']]] as [$model, $ubah]) {
            try {
                $model->update($ubah);
                $this->fail('update harus ditolak');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            try {
                $model->delete();
                $this->fail('delete harus ditolak');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_profil_workbook_bergeser_sesudah_paket_terkunci_409_workbook_berubah(): void
    {
        $d = $this->buatDraf();
        $sebelum = $this->hitung();
        $atribut = $this->atributVersi($d['id']);
        $this->ubahWorkbookSetelahDibaca(['kode_profil' => null, 'keluarga' => 'volumetric_fixed']);

        $this->kirimPut($d['id'], $this->badanPut($d['hash']))->assertStatus(409)->assertJsonPath('kode', 'workbook_berubah');

        $this->assertSame($sebelum, $this->hitung());
        $this->assertSame($atribut, $this->atributVersi($d['id']));
    }

    public function test_urutan_galat_put_lab_tanpa_paket_hash_workbook_atau_peta_salah_409_mendahului_404(): void
    {
        ['wb' => $wbC, 'user' => $userC] = $this->siapkanLab('c', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $this->sebagaiStudio($userC);
        $sebelum = $this->hitung();

        // Lab tanpa paket + hash workbook/peta salah -> 409 (BUKAN 404 dari "versi tak ada").
        $this->kirimPut(1, $this->badanPut(str_repeat('a', 64), ['workbook_sha256' => str_repeat('0', 64)], $wbC), $wbC)
            ->assertStatus(409)->assertJsonPath('kode', 'workbook_berubah');
        $this->kirimPut(1, $this->badanPut(str_repeat('a', 64), ['peta_sel_sha256' => str_repeat('0', 64)], $wbC), $wbC)
            ->assertStatus(409)->assertJsonPath('kode', 'peta_berubah');
        // Hash benar -> baru 404.
        $this->kirimPut(1, $this->badanPut(str_repeat('a', 64), [], $wbC), $wbC)->assertNotFound();

        $this->assertSame($sebelum, $this->hitung());
    }
}
