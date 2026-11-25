<?php

namespace Tests\Unit;

use App\Models\ParameterMetodePenetapan;
use App\Services\Acuan\ParameterKeputusanMetode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Pembaca keputusan metode terhadap PENETAPAN di database (Studio Tahap 1,
 * 25 Nov 2026): `parameter_metode_penetapan` menggantikan `penetapan[]` di berkas
 * sebagai tempat keputusan baru dicatat.
 *
 * Yang dijaga, dan kenapa tiap butir bukan hiasan:
 *
 *  - Hanya status `disetujui` yang menggeser angka. `menunggu_tm` dan `ditarik`
 *    adalah catatan, bukan keputusan; kalau bocor ke hitungan, angka sertifikat
 *    bergeser sebelum Manajer Teknis memutuskan.
 *  - Aturan penentu sama dengan #298: `berlaku_mulai <= tanggal sesi`, nomor
 *    terbesar menang, tanpa baris = bawaan kode. Sesi yang lebih tua dari
 *    `berlaku_mulai` tidak boleh ikut bergeser.
 *  - Satu query untuk seluruh registri per request, bukan satu per pembacaan
 *    (hitung ulang ribuan sesi tidak boleh jadi ribuan query).
 *  - Cache dibuang tiap penetapan berubah, supaya persetujuan langsung terbaca
 *    oleh instance yang sudah hidup.
 *  - Entri berkas (rekaman sejarah) dan baris DB hidup berdampingan.
 *
 * Tanggal sengaja jauh di depan (2099): aturan "tidak berlaku surut" milik
 * penulis penetapan, bukan pembaca, dan pembaca tidak membandingkan dengan jam.
 */
class ParameterKeputusanMetodePenetapanDbTest extends TestCase
{
    use PenetapanParameterUji;
    use RefreshDatabase;

    private const KUNCI = 'spectrophotometer.max_stdev_holmium';

    private const KUNCI_LAIN = 'conductivity.nilai_acuan_larutan_1412';

    protected function setUp(): void
    {
        parent::setUp();

        ParameterKeputusanMetode::lupakan();
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    private function gerbang(): ParameterKeputusanMetode
    {
        return app(ParameterKeputusanMetode::class);
    }

    private function baris(
        string $kunci,
        int $nomor,
        string $nilai,
        string $berlakuMulai,
        string $status = ParameterMetodePenetapan::STATUS_DISETUJUI,
    ): ParameterMetodePenetapan {
        return ParameterMetodePenetapan::query()->create([
            'kunci' => $kunci,
            'nomor' => $nomor,
            'nilai' => $nilai,
            'berlaku_mulai' => $berlakuMulai,
            'dicatat_pada' => '2099-01-01 08:00:00',
            'status' => $status,
            'keputusan_oleh' => $status === ParameterMetodePenetapan::STATUS_DISETUJUI ? 'Manajer Teknis uji' : null,
            'tanggal_keputusan' => $status === ParameterMetodePenetapan::STATUS_DISETUJUI ? '2099-01-01' : null,
            'catatan' => 'uji',
        ]);
    }

    public function test_tanpa_baris_jawabannya_bawaan_kode(): void
    {
        $putusan = $this->gerbang()->putusan(self::KUNCI, '2099-12-31');

        $this->assertSame('semua_titik', $putusan->nilai);
        $this->assertTrue($putusan->bawaan);
        $this->assertSame(0, $putusan->nomorPenetapan);
    }

    public function test_baris_disetujui_berlaku_mulai_berlaku_mulainya_dan_tidak_sebelumnya(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-06-10');

        $sebelum = $this->gerbang()->putusan(self::KUNCI, '2099-06-09');
        $this->assertSame('semua_titik', $sebelum->nilai, 'sesi sehari sebelum berlaku_mulai tidak boleh bergeser');
        $this->assertTrue($sebelum->bawaan);

        $tepat = $this->gerbang()->putusan(self::KUNCI, '2099-06-10');
        $this->assertSame('tanpa_titik_pertama', $tepat->nilai);
        $this->assertFalse($tepat->bawaan);
        $this->assertSame(1, $tepat->nomorPenetapan);
        $this->assertSame('2099-06-10', $tepat->berlakuMulai);

        $sesudah = $this->gerbang()->putusan(self::KUNCI, '2099-07-01');
        $this->assertSame('tanpa_titik_pertama', $sesudah->nilai);
    }

    public function test_baris_menunggu_tm_dan_ditarik_tidak_menggeser_angka(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-06-10', ParameterMetodePenetapan::STATUS_MENUNGGU_TM);
        $this->baris(self::KUNCI, 2, 'tanpa_titik_pertama', '2099-06-10', ParameterMetodePenetapan::STATUS_DITARIK);

        $putusan = $this->gerbang()->putusan(self::KUNCI, '2099-12-31');

        $this->assertSame('semua_titik', $putusan->nilai);
        $this->assertTrue($putusan->bawaan);
        $this->assertSame(0, $putusan->nomorPenetapan);
    }

    public function test_nomor_terbesar_yang_sudah_berlaku_menang(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-03-01');
        $this->baris(self::KUNCI, 2, 'semua_titik', '2099-06-01');
        // Belum berlaku pada tanggal uji: tidak boleh menang walau nomornya terbesar.
        $this->baris(self::KUNCI, 3, 'tanpa_titik_pertama', '2099-09-01');

        $gerbang = $this->gerbang();

        $this->assertSame('tanpa_titik_pertama', $gerbang->putusan(self::KUNCI, '2099-04-01')->nilai);
        $this->assertSame(1, $gerbang->putusan(self::KUNCI, '2099-04-01')->nomorPenetapan);

        $kedua = $gerbang->putusan(self::KUNCI, '2099-07-01');
        $this->assertSame('semua_titik', $kedua->nilai);
        $this->assertSame(2, $kedua->nomorPenetapan);
        $this->assertFalse($kedua->bawaan, 'pilihan sama dengan bawaan tetap dicatat sebagai penetapan');

        $ketiga = $gerbang->putusan(self::KUNCI, '2099-10-01');
        $this->assertSame('tanpa_titik_pertama', $ketiga->nilai);
        $this->assertSame(3, $ketiga->nomorPenetapan);
    }

    public function test_penetapan_satu_parameter_tidak_menyentuh_parameter_lain(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-01-01');

        $lain = $this->gerbang()->putusan(self::KUNCI_LAIN, '2099-12-31');

        $this->assertSame('konstanta_1412', $lain->nilai);
        $this->assertTrue($lain->bawaan);
    }

    public function test_tanpa_lingkup_tanggal_tetap_bawaan_walau_ada_baris_disetujui(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2000-01-01');

        $putusan = $this->gerbang()->putusan(self::KUNCI);

        $this->assertSame('semua_titik', $putusan->nilai);
        $this->assertTrue($putusan->bawaan, 'pembaca yang lupa membuka lingkup tanggal tidak boleh menggeser angka');
    }

    public function test_lingkup_tanggal_memakai_baris_db(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-06-10');
        $gerbang = $this->gerbang();

        $dalam = $gerbang->untukTanggal('2099-06-10', fn () => $gerbang->nilai(self::KUNCI));
        $luar = $gerbang->untukTanggal('2099-06-09', fn () => $gerbang->nilai(self::KUNCI));

        $this->assertSame('tanpa_titik_pertama', $dalam);
        $this->assertSame('semua_titik', $luar);
    }

    public function test_cache_dibuang_saat_penetapan_berubah_pada_instance_yang_sudah_hidup(): void
    {
        $gerbang = $this->gerbang();

        $this->assertSame('semua_titik', $gerbang->nilai(self::KUNCI, '2099-12-31'), 'pembacaan pertama mengisi cache (kosong)');

        $baris = $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-06-10', ParameterMetodePenetapan::STATUS_MENUNGGU_TM);
        $this->assertSame('semua_titik', $gerbang->nilai(self::KUNCI, '2099-12-31'), 'menunggu_tm belum menggeser');

        $baris->status = ParameterMetodePenetapan::STATUS_DISETUJUI;
        $baris->keputusan_oleh = 'Manajer Teknis uji';
        $baris->tanggal_keputusan = '2099-01-01';
        $baris->save();

        $this->assertSame('tanpa_titik_pertama', $gerbang->nilai(self::KUNCI, '2099-12-31'), 'instance yang sama harus melihat persetujuan');

        $baris->status = ParameterMetodePenetapan::STATUS_DITARIK;
        $baris->save();

        $this->assertSame('semua_titik', $gerbang->nilai(self::KUNCI, '2099-12-31'), 'instance yang sama harus melihat penarikan');
    }

    public function test_seluruh_registri_dibaca_dengan_satu_query_penetapan(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-06-10');
        $this->baris(self::KUNCI_LAIN, 1, 'polinomial_suhu', '2099-06-10');
        $gerbang = $this->gerbang();

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach (['2099-01-01', '2099-06-10', '2099-12-31'] as $tanggal) {
            $gerbang->semua($tanggal);
            $gerbang->putusan(self::KUNCI, $tanggal);
            $gerbang->putusan(self::KUNCI_LAIN, $tanggal);
            $gerbang->barisJejakProfil('spectrophotometer');
        }

        $query = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'parameter_metode_penetapan'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $query, 'penetapan harus dimuat sekali per instance, bukan per pembacaan');
    }

    public function test_entri_berkas_dan_baris_db_hidup_berdampingan(): void
    {
        // Entri berkas nomor 1 (rekaman sejarah), baris DB nomor 2 menggantikannya mulai tanggal lain.
        $this->pakaiPenetapan(self::KUNCI, 'tanpa_titik_pertama', '2099-03-01');
        $this->baris(self::KUNCI, 2, 'semua_titik', '2099-09-01');
        $gerbang = $this->gerbang();

        $this->assertSame('semua_titik', $gerbang->putusan(self::KUNCI, '2099-02-01')->nilai);

        $berkas = $gerbang->putusan(self::KUNCI, '2099-04-01');
        $this->assertSame('tanpa_titik_pertama', $berkas->nilai);
        $this->assertSame(1, $berkas->nomorPenetapan);

        $db = $gerbang->putusan(self::KUNCI, '2099-10-01');
        $this->assertSame('semua_titik', $db->nilai);
        $this->assertSame(2, $db->nomorPenetapan);
        $this->assertFalse($db->bawaan);
    }

    public function test_semua_memuat_penetapan_db_dan_pilihan_yang_berlaku(): void
    {
        $this->baris(self::KUNCI, 1, 'tanpa_titik_pertama', '2099-06-10');

        $hasil = collect($this->gerbang()->semua('2099-07-01'))->firstWhere('kunci', self::KUNCI);

        $this->assertSame('tanpa_titik_pertama', $hasil['berlaku']['nilai']);
        $this->assertSame(1, $hasil['berlaku']['penetapan']);
        $this->assertSame('2099-06-10', $hasil['berlaku']['berlaku_mulai']);
        $this->assertCount(1, $hasil['penetapan']);
        $this->assertSame('db', $hasil['penetapan'][0]['sumber']);
        $this->assertSame('Manajer Teknis uji', $hasil['penetapan'][0]['diputuskan_oleh']);
    }

    public function test_baris_disetujui_dengan_nilai_di_luar_registri_gagal_keras(): void
    {
        // Dimasukkan langsung: penulis penetapan menolak nilai seperti ini, tetapi data yang
        // sudah ada di tabel (impor, tangan, registri yang kemudian berubah) tidak boleh
        // dibaca diam-diam sebagai bawaan.
        $this->baris(self::KUNCI, 1, 'pilihan_yang_tidak_ada', '2099-01-01');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pilihan_yang_tidak_ada');

        $this->gerbang()->putusan(self::KUNCI, '2099-12-31');
    }

    public function test_baris_milik_kunci_yang_tidak_ada_di_registri_diam_saja(): void
    {
        $this->baris('parameter.yang_tidak_ada', 1, 'apa_saja', '2099-01-01');

        $gerbang = $this->gerbang();

        $this->assertSame('semua_titik', $gerbang->nilai(self::KUNCI, '2099-12-31'));
        $this->assertCount(7, $gerbang->semua('2099-12-31'));
    }

    public function test_tabel_belum_ada_dibaca_sebagai_bawaan_dan_diperingatkan_sekali(): void
    {
        // Sambungan kosong (tanpa migrasi) menggantikan sambungan bawaan hanya selama tes ini,
        // tanpa DROP TABLE: di MySQL DDL menutup transaksi RefreshDatabase dan merusak tes lain.
        config(['database.connections.tanpa_tabel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);

        $bendera = new ReflectionProperty(ParameterKeputusanMetode::class, 'sudahMemperingatkanTabel');
        $bendera->setValue(null, false);

        $asli = DB::getDefaultConnection();
        Log::spy();

        try {
            DB::setDefaultConnection('tanpa_tabel');

            $gerbang = $this->gerbang();

            $this->assertSame('semua_titik', $gerbang->nilai(self::KUNCI, '2099-12-31'));

            ParameterKeputusanMetode::lupakan();
            $this->assertSame('semua_titik', $this->gerbang()->nilai(self::KUNCI, '2099-12-31'));
        } finally {
            DB::setDefaultConnection($asli);
            DB::purge('tanpa_tabel');
        }

        Log::shouldHaveReceived('warning')->once();
    }
}
