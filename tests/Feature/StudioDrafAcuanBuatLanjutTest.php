<?php

namespace Tests\Feature;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Studio\Draf\DrafAcuanDitolak;
use App\Services\Studio\Draf\SuntinganDrafService;
use App\Services\Studio\PetaSel\PetaSel;
use App\Support\JsonKanonik;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Tests\Concerns\FixtureDrafAcuan;
use Tests\Concerns\PerekamKunci;
use Tests\TestCase;

/**
 * Lanjutan `POST /api/studio/workbook/{workbook}/draf` (rencana A1, B4: menutup
 * temuan tinjau B3). Perilaku inti POST: `StudioDrafAcuanBuatTest`. Di sini:
 * `rincian.hash_terbaru` pada 409; workbook tidak aktif (422) dan dibaca ulang di
 * dalam transaksi; bodi harus JSON; batas persis 128/129 dan 256/257; kunci paket
 * dan versi (perekam global-scope); dasar dari berkas JSON saat tak ada versi
 * berlaku. Fixture: `FixtureDrafAcuan`.
 */
class StudioDrafAcuanBuatLanjutTest extends TestCase
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

    // ---- 409 membawa hash_terbaru ----------------------------------------------------

    public function test_workbook_peta_dan_dasar_salah_409_membawa_tiga_hash_terbaru_tanpa_isi_lain(): void
    {
        $terbaru = ['workbook' => $this->wb->sha256, 'peta' => $this->peta->sha256, 'dasar' => PenentuVersiAcuan::sha256($this->v1)];
        $sebelum = $this->hitung();

        foreach ([['workbook_sha256', 'workbook_berubah'], ['peta_sel_sha256', 'peta_berubah'], ['base_isi_sha256', 'dasar_berubah']] as [$kolom, $kode]) {
            $res = $this->kirim($this->badan([$kolom => str_repeat('0', 64)]));

            $res->assertStatus(409)->assertJsonPath('kode', $kode);
            $this->assertSame(['hash_terbaru' => $terbaru], $res->json('rincian'), $kode);
        }

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_dasar_berubah_memberi_dasar_terbaru_yang_langsung_bisa_dipakai(): void
    {
        $isi = $this->isiV1();
        $isi['cmc'][$this->indeksPita($isi, 'B')]['u95_um'] = 0.99;
        $teks = JsonKanonik::serialisasi($isi);
        $this->v1->forceFill(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => now()->subDay()->toDateString()])->save();
        $this->buatVersi($this->paket, 2, PaketAcuanVersi::STATUS_AKTIF, $teks, ['berlaku_mulai' => now()->toDateString()]);

        $res = $this->kirim($this->badan())->assertStatus(409)->assertJsonPath('kode', 'dasar_berubah');
        $baru = $res->json('rincian.hash_terbaru');

        $this->assertSame(JsonKanonik::sha256($teks), $baru['dasar']);
        $this->kirim($this->badan(['workbook_sha256' => $baru['workbook'], 'peta_sel_sha256' => $baru['peta'], 'base_isi_sha256' => $baru['dasar']]))->assertCreated();
    }

    public function test_bentrok_nomor_versi_409_draf_berubah_membawa_hash_terbaru(): void
    {
        $sebelum = $this->hitung();
        PaketAcuanVersi::creating(static function (): never {
            throw new UniqueConstraintViolationException('sqlite', 'insert into paket_acuan_versi', [], new \PDOException('UNIQUE constraint failed'));
        });

        try {
            $res = $this->kirim($this->badan())->assertStatus(409)->assertJsonPath('kode', 'draf_berubah');
        } finally {
            Event::forget('eloquent.creating: '.PaketAcuanVersi::class);
        }

        $this->assertSame(
            ['hash_terbaru' => ['workbook' => $this->wb->sha256, 'peta' => $this->peta->sha256, 'dasar' => PenentuVersiAcuan::sha256($this->v1)]],
            $res->json('rincian'),
        );
        $this->assertSame($sebelum, $this->hitung());
    }

    // ---- workbook tidak aktif, dibaca ulang di dalam transaksi ---------------------------

    public function test_workbook_tidak_aktif_422_fail_closed_dan_tak_menulis(): void
    {
        $this->wb->forceFill(['aktif' => false])->save();
        $sebelum = $this->hitung();

        $this->kirim($this->badan())->assertStatus(422)->assertJsonPath('kode', 'workbook_tidak_aktif');

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_diubah_sesudah_authorize_sha256_baru_409_workbook_berubah(): void
    {
        $sebelum = $this->hitung();
        $baru = str_repeat('c', 64);
        $this->ubahWorkbookSetelahDibaca(['sha256' => $baru]);

        $res = $this->kirim($this->badan());

        $res->assertStatus(409)->assertJsonPath('kode', 'workbook_berubah')->assertJsonPath('rincian.hash_terbaru.workbook', $baru);
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_diarsipkan_sesudah_authorize_404(): void
    {
        $sebelum = $this->hitung();
        $this->ubahWorkbookSetelahDibaca(['diarsipkan_pada' => now()]);

        $this->kirim($this->badan())->assertNotFound();
        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_dinonaktifkan_sesudah_authorize_422(): void
    {
        $sebelum = $this->hitung();
        $this->ubahWorkbookSetelahDibaca(['aktif' => null]);

        $this->kirim($this->badan())->assertStatus(422)->assertJsonPath('kode', 'workbook_tidak_aktif');
        $this->assertSame($sebelum, $this->hitung());
    }

    // ---- bodi harus JSON ----------------------------------------------------------------------

    public function test_bodi_form_ditolak_415_agar_nilai_tak_dipangkas_diam_diam(): void
    {
        $sebelum = $this->hitung();
        $badan = $this->badan(['suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => ' 0.84']]]);
        $url = '/api/studio/workbook/'.$this->wb->id.'/draf';

        $this->call('POST', $url, $badan)->assertStatus(415)->assertJsonPath('kode', 'harus_json');
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($badan))
            ->assertStatus(415)->assertJsonPath('kode', 'harus_json');
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'text/plain'], (string) json_encode($badan))
            ->assertStatus(415)->assertJsonPath('kode', 'harus_json');

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_workbook_lab_lain_dengan_bodi_form_tetap_404_bukan_415(): void
    {
        ['wb' => $wbB] = $this->siapkanLab('b');
        $this->sebagaiStudio($this->studio);

        $this->call('POST', '/api/studio/workbook/'.$wbB->id.'/draf', ['a' => 'b'])->assertNotFound();
        $this->call('POST', '/api/studio/workbook/999999/draf', ['a' => 'b'])->assertNotFound();
    }

    public function test_query_string_tidak_menjadi_cadangan_input(): void
    {
        $badan = $this->badan();
        unset($badan['suntingan']);
        $sebelum = $this->hitung();

        $this->postJson('/api/studio/workbook/'.$this->wb->id.'/draf?'.http_build_query(['suntingan' => [['sel' => ' DATABASE!T5 ', 'nilai' => ' 0.84']]]), $badan)
            ->assertStatus(422)
            ->assertJsonValidationErrors('suntingan');

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_batas_persis_nilai_128_129_dan_sel_256_257_pada_lapisan_request(): void
    {
        $kirim = fn (string $sel, string $nilai) => $this->kirim($this->badan(['suntingan' => [['sel' => $sel, 'nilai' => $nilai]]]));

        // 128 / 256 lolos lapisan request lalu ditolak server (ada `kode`); 129 / 257 ditolak validasi (`errors`, tanpa `kode`).
        $kirim('DATABASE!T5', str_repeat('9', 128))->assertStatus(422)->assertJsonPath('kode', 'suntingan_ditolak');
        $kirim('DATABASE!T5', str_repeat('9', 129))->assertStatus(422)->assertJsonValidationErrors('suntingan.0.nilai')->assertJsonMissingPath('kode');
        $kirim(str_repeat('A', 256), '1')->assertStatus(422)->assertJsonPath('kode', 'sel_tidak_didukung');
        $kirim(str_repeat('A', 257), '1')->assertStatus(422)->assertJsonValidationErrors('suntingan.0.sel')->assertJsonMissingPath('kode');

        $this->assertSame(0, PaketAcuanVersi::query()->where('status', 'draf')->count());
    }

    // ---- kunci: paket dulu, lalu versi (dibuktikan perekam; SQLite membuang FOR UPDATE) --------------

    public function test_post_mengunci_paket_sebelum_versi_apa_pun_dan_mengunci_versi_untuk_penomoran(): void
    {
        $this->pasangPerekamKunci();

        $this->kirim($this->badan())->assertCreated();

        $kunciPaket = PerekamKunci::pertama(PaketAcuan::class, true);
        $versiPertama = PerekamKunci::pertama(PaketAcuanVersi::class);
        // Kueri penomoran: where paket_acuan_id + jenis (tanpa status/id).
        $kunciNomor = PerekamKunci::pertama(PaketAcuanVersi::class, true, ['paket_acuan_id', 'jenis']);

        $this->assertNotNull($kunciPaket, 'baris paket_acuan dikunci (lockForUpdate)');
        $this->assertNotNull($versiPertama);
        $this->assertNotNull($kunciNomor, 'penomoran versi dihitung di bawah kunci (lockForUpdate + max)');
        $this->assertLessThan($versiPertama, $kunciPaket, 'paket dikunci SEBELUM dasar() menyentuh versi mana pun');
        $this->assertLessThan($kunciNomor, $kunciPaket);
    }

    public function test_pembacaan_pertama_dalam_transaksi_post_adalah_select_paket_terkunci_sebelum_workbook_dasar_atau_versi(): void
    {
        $this->pasangPerekamKunci();

        $sql = $this->sqlDalamTransaksi(fn () => $this->kirim($this->badan())->assertCreated());

        // MySQL REPEATABLE READ: snapshot dibentuk SELECT konsisten pertama -> yang pertama HARUS SELECT paket FOR UPDATE.
        $this->assertNotSame([], $sql);
        $this->assertMatchesRegularExpression('/^select .* from [`"]paket_acuan[`"]( |$)/', $sql[0], 'kueri pertama di dalam transaksi = paket_acuan');
        $this->assertStringNotContainsString('workbook_master', $sql[0]);
        $this->assertSame(PerekamKunci::pertama(PaketAcuan::class), PerekamKunci::pertama(PaketAcuan::class, true), 'pembacaan paket pertama = yang terkunci');

        $urutan = array_map(static fn (string $q): string => preg_match('/from [`"]([a-z_]+)[`"]/', $q, $m) === 1 ? $m[1] : '?', $sql);
        $this->assertSame('paket_acuan', $urutan[0]);
        $this->assertContains('workbook_master', $urutan, 'workbook memang dibaca ulang di dalam transaksi');
        $this->assertGreaterThan(0, array_search('workbook_master', $urutan, true), 'workbook dibaca ulang SESUDAH paket terkunci');
    }

    public function test_semua_sumber_json_peta_asli_lolos_pastikan_stabil(): void
    {
        $service = app(SuntinganDrafService::class);
        $periksa = new ReflectionMethod($service, 'pastikanStabil');
        $diperiksa = 0;

        foreach (glob(database_path('data/peta-sel/*.json')) ?: [] as $berkas) {
            if (basename($berkas) === 'manifest-xlsm.json') {
                continue; // bukan peta: manifest sha256 master .xlsm
            }

            $peta = PetaSel::untuk(basename($berkas, '.json'));
            $this->assertNotNull($peta, basename($berkas));
            $sumber = database_path('data/'.$peta->isi['sumber_json']);
            $this->assertFileExists($sumber, basename($berkas));

            $kanonik = JsonKanonik::dariTeks((string) file_get_contents($sumber));
            $dasar = [
                'isi' => (array) json_decode($kanonik, true, 512, JSON_THROW_ON_ERROR),
                'sha256' => JsonKanonik::sha256($kanonik),
                'versi' => null, 'paket' => null, 'label' => 'berkas '.$peta->isi['sumber_json'],
            ];

            try {
                $periksa->invoke($service, $dasar);
            } catch (DrafAcuanDitolak $e) {
                $this->fail("sumber_json {$peta->isi['sumber_json']} (peta {$peta->kodeProfil}) tidak stabil kanonik: {$e->kode}");
            }
            $diperiksa++;
        }

        $this->assertSame(25, $diperiksa, 'jumlah peta asli di database/data/peta-sel (tanpa manifest-xlsm.json); ubah angka ini bila peta ditambah');
    }

    // ---- dasar dari berkas JSON (tak ada versi berlaku) --------------------------------------------------

    public function test_dasar_dari_berkas_json_saat_paket_hanya_punya_versi_pensiun_dan_diajukan(): void
    {
        ['wb' => $wb, 'user' => $user, 'lab' => $lab] = $this->siapkanLab('j', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $paket = PaketAcuan::create(['organization_id' => $lab->id, 'kode_profil' => 'micrometer', 'nama' => 'Micrometer', 'skema_versi' => 1]);
        $pensiun = $this->buatVersi($paket, 1, PaketAcuanVersi::STATUS_PENSIUN, $this->teksDasar(), ['berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31']);
        $diajukan = $this->buatVersi($paket, 2, PaketAcuanVersi::STATUS_DIAJUKAN, $this->teksDasar());
        $this->sebagaiStudio($user);
        $sebelum = $this->hitung();
        $lama = [$pensiun->id => $this->atributVersi($pensiun->id), $diajukan->id => $this->atributVersi($diajukan->id)];
        $hashBerkas = JsonKanonik::sha256($this->teksDasar());

        $res = $this->kirim($this->badan(['base_isi_sha256' => $hashBerkas], $wb), $wb);

        $res->assertCreated()
            ->assertJsonPath('data.status', 'draf_dibuat')
            ->assertJsonPath('data.versi.dasar_versi_id', null)
            ->assertJsonPath('data.versi.nomor_versi', 3)
            ->assertJsonPath('data.hash.dasar', $hashBerkas)
            ->assertJsonPath('data.suntingan.0.nilai', '0.84');

        $draf = PaketAcuanVersi::query()->findOrFail($res->json('data.versi.id'));
        $harap = json_decode($this->teksDasar(), true, 512, JSON_THROW_ON_ERROR);
        $harap['cmc'][$this->indeksPita($harap, 'A')]['u95_um'] = 0.84;
        $this->assertSame(JsonKanonik::serialisasi($harap), (string) $draf->isi);
        $this->assertNull($draf->dasar_versi_id);
        $this->assertNull($draf->isi_sha256);

        $log = PaketAcuanLog::query()->where('paket_acuan_versi_id', $draf->id)->sole();
        $this->assertSame($hashBerkas, $log->sumber['dasar_sha256']);
        $this->assertStringStartsWith('berkas database/data/', $log->sumber['dasar']);

        $this->assertSame($sebelum['versi'] + 1, $this->hitung()['versi']);
        foreach ($lama as $id => $atribut) {
            $this->assertSame($atribut, $this->atributVersi($id), 'versi pensiun/diajukan tak tersentuh');
        }
    }

    public function test_dasar_dari_berkas_json_hash_salah_409_dengan_hash_berkas_sebagai_terbaru(): void
    {
        ['wb' => $wb, 'user' => $user, 'lab' => $lab] = $this->siapkanLab('j', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $paket = PaketAcuan::create(['organization_id' => $lab->id, 'kode_profil' => 'micrometer', 'nama' => 'Micrometer', 'skema_versi' => 1]);
        $this->buatVersi($paket, 1, PaketAcuanVersi::STATUS_PENSIUN, $this->teksDasar(), ['berlaku_mulai' => '2019-01-01', 'berlaku_sampai' => '2019-12-31']);
        $this->sebagaiStudio($user);

        $this->kirim($this->badan(['base_isi_sha256' => str_repeat('0', 64)], $wb), $wb)
            ->assertStatus(409)
            ->assertJsonPath('kode', 'dasar_berubah')
            ->assertJsonPath('rincian.hash_terbaru.dasar', JsonKanonik::sha256($this->teksDasar()));
    }

    public function test_stabilitas_dasar_berkas_json_menolak_objek_kosong_dan_meloloskan_isi_stabil(): void
    {
        // Cabang "dasar dari berkas" (versi null) tak bisa dijangkau lewat HTTP: berkas dasar dikunci
        // `skemaAcuan()['sumber_json']` dan isinya stabil. Diuji langsung pada penjaganya.
        $service = app(SuntinganDrafService::class);
        $periksa = new ReflectionMethod($service, 'pastikanStabil');
        $dasar = static fn (string $teks): array => [
            'isi' => json_decode(JsonKanonik::dariTeks($teks), true, 512, JSON_THROW_ON_ERROR),
            'sha256' => JsonKanonik::sha256(JsonKanonik::dariTeks($teks)),
            'versi' => null, 'paket' => null, 'label' => 'berkas uji',
        ];

        $periksa->invoke($service, $dasar('{"a":[1],"b":1.5,"c":{"x":2}}'));
        $this->addToAssertionCount(1);

        foreach (['{"a":{},"b":1.5}', '{"a":{"0":1,"1":2}}'] as $takStabil) {
            try {
                $periksa->invoke($service, $dasar($takStabil));
                $this->fail("dasar {$takStabil} harus ditolak");
            } catch (DrafAcuanDitolak $e) {
                $this->assertSame(['isi_tidak_stabil', 409], [$e->kode, $e->status]);
            }
        }
    }

    public function test_profil_workbook_bergeser_sesudah_paket_terkunci_409_workbook_berubah_bukan_peta_berubah(): void
    {
        $sebelum = $this->hitung();
        // Sesudah authorize() membaca: profil berpindah ke keluarga lain (peta `volumetric`, bukan `micrometer`).
        $this->ubahWorkbookSetelahDibaca(['kode_profil' => null, 'keluarga' => 'volumetric_fixed']);

        $this->kirim($this->badan())->assertStatus(409)->assertJsonPath('kode', 'workbook_berubah');

        $this->assertSame($sebelum, $this->hitung());
    }

    public function test_urutan_galat_post_lab_tanpa_paket_hash_workbook_atau_peta_salah_409_mendahului_paket_belum_ada(): void
    {
        ['wb' => $wbC, 'user' => $userC] = $this->siapkanLab('c', null, 'Master_Olah_Data_Micrometer_25-50mm', false);
        $this->sebagaiStudio($userC);
        $sebelum = $this->hitung();

        // Lab tanpa paket + hash workbook salah -> 409 workbook_berubah (BUKAN 422 paket_belum_ada).
        $this->kirim($this->badan(['workbook_sha256' => str_repeat('0', 64)], $wbC), $wbC)
            ->assertStatus(409)->assertJsonPath('kode', 'workbook_berubah');
        $this->kirim($this->badan(['peta_sel_sha256' => str_repeat('0', 64)], $wbC), $wbC)
            ->assertStatus(409)->assertJsonPath('kode', 'peta_berubah');
        // Hash benar -> baru paket_belum_ada.
        $this->kirim($this->badan([], $wbC), $wbC)
            ->assertStatus(422)->assertJsonPath('kode', 'paket_belum_ada');

        $this->assertSame($sebelum, $this->hitung());
    }
}
