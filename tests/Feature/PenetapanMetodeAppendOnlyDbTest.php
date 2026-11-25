<?php

namespace Tests\Feature;

use App\Models\ParameterMetodePenetapan;
use App\Models\ParameterMetodePenetapanJejak;
use App\Models\User;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Acuan\PenetapanParameterMetode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Penetapan keputusan metode append-only di tingkat DATABASE (tinjauan PR #304,
 * 25 Nov 2026). Penjaga model (`updating`/`deleting` melempar) tidak menahan
 * query builder; trigger dari `2026_11_25_110000_kunci_append_only_penetapan_metode`
 * yang menahannya, dan tes ini membuktikannya LEWAT query builder, bukan model.
 *
 * Yang dikunci:
 *
 *  - Isi baris penetapan (`kunci`, `nomor`, `nilai`, `berlaku_mulai`,
 *    `dicatat_pada`, `catatan`, `created_at`, `id`) tidak bisa diubah, termasuk
 *    perubahan huruf besar-kecil (kolasi MySQL tidak membedakannya) dan dari/ke NULL.
 *  - Transisi status yang sah (yang ditulis `PenetapanParameterMetode`) lolos.
 *  - Keputusan yang sudah dicatat (`keputusan_oleh`, `tanggal_keputusan`) dibekukan
 *    begitu status keluar dari `menunggu_tm`; `ditarik` adalah status akhir.
 *  - Penetapan tidak bisa dihapus, jejak tidak bisa diubah atau dihapus.
 *  - Jalur normal (service catat, setujui, tarik) tetap jalan dengan trigger terpasang.
 *
 * MySQL dengan binary log tanpa `log_bin_trust_function_creators` menolak
 * `CREATE TRIGGER`; migrasinya sengaja tidak gagal. Di sana tes ini dilewati
 * (SKIP, tercatat di keluaran), bukan lulus palsu, dan hanya bila penyebabnya
 * memang itu. Di SQLite dan di MySQL CI (trust menyala) trigger WAJIB ada.
 */
class PenetapanMetodeAppendOnlyDbTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'spectrophotometer.max_stdev_holmium';

    private const TRIGGER = ['pmp_tolak_hapus', 'pmp_tolak_ubah_isi', 'pmpj_tolak_hapus', 'pmpj_tolak_ubah'];

    private int $nomor = 100;

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

    /** @return list<string> */
    private function namaTrigger(): array
    {
        $baris = DB::connection()->getDriverName() === 'sqlite'
            ? DB::select("SELECT name AS nama FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'pmp%'")
            : DB::select('SELECT TRIGGER_NAME AS nama FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE ?', ['pmp%']);

        $nama = array_map(static fn (object $b): string => (string) $b->nama, $baris);
        sort($nama);

        return $nama;
    }

    /**
     * Gagal keras bila trigger tidak ada, kecuali satu keadaan yang sah dan
     * sudah diketahui (MySQL ber-binlog tanpa trust): itu dilewati terbuka.
     */
    private function pastikanTriggerTerpasang(): void
    {
        if ($this->namaTrigger() === self::TRIGGER) {
            return;
        }

        $this->assertNotSame('sqlite', DB::connection()->getDriverName(), 'di SQLite migrasi melempar bila trigger gagal, jadi tidak mungkin tanpa trigger');

        $server = DB::selectOne('SELECT @@GLOBAL.log_bin AS log_bin, @@GLOBAL.log_bin_trust_function_creators AS percaya');
        $this->assertSame(
            [1, 0],
            [(int) $server->log_bin, (int) $server->percaya],
            'trigger tidak terpasang tetapi bukan karena binlog tanpa log_bin_trust_function_creators: periksa hak TRIGGER',
        );

        $this->markTestSkipped('MySQL ber-binlog tanpa log_bin_trust_function_creators: CREATE TRIGGER ditolak server (migrasi sengaja tidak gagal). Cek di MySQL yang menyalakannya, seperti CI.');
    }

    /** @param array<string, mixed> $ganti */
    private function sisipPenetapan(array $ganti = []): int
    {
        return (int) DB::table('parameter_metode_penetapan')->insertGetId(array_merge([
            'kunci' => self::KUNCI,
            'nomor' => ++$this->nomor,
            'nilai' => 'semua_titik',
            'berlaku_mulai' => '2030-06-10',
            'dicatat_pada' => '2030-01-01 08:00:00',
            'status' => ParameterMetodePenetapan::STATUS_MENUNGGU_TM,
            'keputusan_oleh' => null,
            'tanggal_keputusan' => null,
            'catatan' => 'uji',
            'created_at' => '2030-01-01 08:00:00',
            'updated_at' => '2030-01-01 08:00:00',
        ], $ganti));
    }

    /** @return array<string, mixed> */
    private function baris(string $tabel, int $id): array
    {
        return (array) DB::table($tabel)->where('id', $id)->first();
    }

    /** Aksi HARUS ditolak trigger, dan isi baris tidak boleh bergeser. */
    private function assertDitolak(Closure $aksi, string $tabel, int $id, string $label): void
    {
        $sebelum = $this->baris($tabel, $id);

        try {
            $aksi();
            $this->fail("{$label}: lolos, padahal harus ditolak trigger");
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage(), "{$label}: ditolak, tetapi bukan oleh trigger append-only");
        }

        $this->assertSame($sebelum, $this->baris($tabel, $id), "{$label}: baris bergeser walau ditolak");
    }

    public function test_keempat_trigger_terpasang(): void
    {
        $this->pastikanTriggerTerpasang();

        $this->assertSame(self::TRIGGER, $this->namaTrigger());
    }

    public function test_isi_baris_penetapan_tidak_bisa_diubah_lewat_query_builder(): void
    {
        $this->pastikanTriggerTerpasang();

        $id = $this->sisipPenetapan();
        $idTanpaCatatan = $this->sisipPenetapan(['catatan' => null]);

        $perubahan = [
            'id' => ['id', $id + 1000],
            'kunci' => ['kunci', 'conductivity.nilai_acuan_larutan_1412'],
            'nomor' => ['nomor', 999],
            'nilai' => ['nilai', 'tanpa_titik_pertama'],
            'nilai, hanya huruf besar-kecil' => ['nilai', 'SEMUA_TITIK'],
            'berlaku_mulai' => ['berlaku_mulai', '2030-06-11'],
            'dicatat_pada' => ['dicatat_pada', '2030-01-02 08:00:00'],
            'catatan diganti' => ['catatan', 'diganti'],
            'catatan dikosongkan' => ['catatan', null],
            'catatan, hanya huruf besar-kecil' => ['catatan', 'UJI'],
            'created_at' => ['created_at', '2030-02-01 00:00:00'],
        ];

        foreach ($perubahan as $label => [$kolom, $nilai]) {
            $this->assertDitolak(
                fn () => DB::table('parameter_metode_penetapan')->where('id', $id)->update([$kolom => $nilai]),
                'parameter_metode_penetapan',
                $id,
                $label,
            );
        }

        // Dari NULL ke isi juga perubahan (perbandingan aman terhadap NULL).
        $this->assertDitolak(
            fn () => DB::table('parameter_metode_penetapan')->where('id', $idTanpaCatatan)->update(['catatan' => 'baru']),
            'parameter_metode_penetapan',
            $idTanpaCatatan,
            'catatan dari NULL',
        );

        // Beberapa kolom sekaligus, dan lewat Eloquent builder (bukan instance model).
        $this->assertDitolak(
            fn () => DB::table('parameter_metode_penetapan')->where('id', $id)->update(['status' => 'disetujui', 'nilai' => 'tanpa_titik_pertama']),
            'parameter_metode_penetapan',
            $id,
            'status sah dicampur nilai',
        );
        $this->assertDitolak(
            fn () => ParameterMetodePenetapan::query()->update(['nilai' => 'tanpa_titik_pertama']),
            'parameter_metode_penetapan',
            $id,
            'update massal lewat Model::query()',
        );
        $this->assertDitolak(
            fn () => DB::table('parameter_metode_penetapan')->update(['berlaku_mulai' => '2030-12-31']),
            'parameter_metode_penetapan',
            $id,
            'update massal tanpa where',
        );
    }

    public function test_transisi_status_yang_sah_lolos_lewat_query_builder(): void
    {
        $this->pastikanTriggerTerpasang();

        $tabel = DB::table('parameter_metode_penetapan');
        $disetujui = $this->sisipPenetapan();
        $langsungDitarik = $this->sisipPenetapan();

        // menunggu_tm -> disetujui: status + keputusan + tanggal + updated_at.
        $this->assertSame(1, (clone $tabel)->where('id', $disetujui)->update([
            'status' => 'disetujui',
            'keputusan_oleh' => 'Manajer Teknis uji',
            'tanggal_keputusan' => '2030-01-02',
            'updated_at' => '2030-01-02 09:00:00',
        ]));
        // disetujui -> ditarik: hanya status (+ updated_at).
        $this->assertSame(1, (clone $tabel)->where('id', $disetujui)->update(['status' => 'ditarik', 'updated_at' => '2030-01-03 09:00:00']));
        // menunggu_tm -> ditarik.
        $this->assertSame(1, (clone $tabel)->where('id', $langsungDitarik)->update(['status' => 'ditarik']));

        $baris = $this->baris('parameter_metode_penetapan', $disetujui);
        $this->assertSame('ditarik', $baris['status']);
        $this->assertSame('Manajer Teknis uji', $baris['keputusan_oleh'], 'keputusan tetap tercatat sesudah ditarik');
        $this->assertSame('semua_titik', $baris['nilai']);
        $this->assertSame('uji', $baris['catatan']);
    }

    public function test_keputusan_yang_sudah_dicatat_dibekukan_dan_ditarik_adalah_status_akhir(): void
    {
        $this->pastikanTriggerTerpasang();

        $disetujui = $this->sisipPenetapan([
            'status' => 'disetujui',
            'keputusan_oleh' => 'Manajer Teknis uji',
            'tanggal_keputusan' => '2030-01-02',
        ]);
        $ditarik = $this->sisipPenetapan(['status' => 'ditarik']);
        $tabel = 'parameter_metode_penetapan';

        foreach ([
            'keputusan_oleh diganti' => ['keputusan_oleh' => 'Orang Lain'],
            'keputusan_oleh dikosongkan' => ['keputusan_oleh' => null],
            'keputusan_oleh, hanya huruf besar-kecil' => ['keputusan_oleh' => 'MANAJER TEKNIS UJI'],
            'tanggal_keputusan diganti' => ['tanggal_keputusan' => '2030-01-03'],
            'tanggal_keputusan dikosongkan' => ['tanggal_keputusan' => null],
        ] as $label => $ubah) {
            $this->assertDitolak(
                fn () => DB::table($tabel)->where('id', $disetujui)->update($ubah),
                $tabel,
                $disetujui,
                "disetujui: {$label}",
            );
        }

        foreach (['disetujui', 'menunggu_tm'] as $kembali) {
            $this->assertDitolak(
                fn () => DB::table($tabel)->where('id', $ditarik)->update(['status' => $kembali]),
                $tabel,
                $ditarik,
                "ditarik tidak bisa kembali ke {$kembali}",
            );
        }

        $this->assertDitolak(
            fn () => DB::table($tabel)->where('id', $ditarik)->update(['keputusan_oleh' => 'Orang Lain']),
            $tabel,
            $ditarik,
            'ditarik: keputusan_oleh tidak bisa diisi belakangan',
        );
    }

    public function test_penetapan_tidak_bisa_dihapus_lewat_query_builder(): void
    {
        $this->pastikanTriggerTerpasang();

        $id = $this->sisipPenetapan();
        $tabel = 'parameter_metode_penetapan';

        foreach ([
            'DB::table()->where()->delete()' => fn () => DB::table($tabel)->where('id', $id)->delete(),
            'DB::table()->delete() tanpa where' => fn () => DB::table($tabel)->delete(),
            'Model::query()->delete() massal' => fn () => ParameterMetodePenetapan::query()->delete(),
        ] as $label => $aksi) {
            $this->assertDitolak($aksi, $tabel, $id, $label);
        }

        $this->assertSame(1, DB::table($tabel)->count());
    }

    public function test_jejak_tidak_bisa_diubah_atau_dihapus_lewat_query_builder(): void
    {
        $this->pastikanTriggerTerpasang();

        $pengguna = User::factory()->create();
        $penetapan = $this->sisipPenetapan();
        $lain = $this->sisipPenetapan();

        $idJejak = (int) DB::table('parameter_metode_penetapan_jejak')->insertGetId([
            'penetapan_id' => $penetapan,
            'dari_status' => null,
            'ke_status' => 'menunggu_tm',
            'oleh_user_id' => $pengguna->id,
            'oleh_email' => 'pelaku@sidik.test',
            'terjadi_pada' => '2030-01-01 08:00:00',
            'catatan' => 'dicatat',
            'created_at' => '2030-01-01 08:00:00',
            'updated_at' => '2030-01-01 08:00:00',
        ]);
        $tabel = 'parameter_metode_penetapan_jejak';

        foreach ([
            'penetapan_id dipindah' => ['penetapan_id' => $lain],
            'dari_status' => ['dari_status' => 'disetujui'],
            'ke_status' => ['ke_status' => 'disetujui'],
            'oleh_user_id dikosongkan' => ['oleh_user_id' => null],
            'oleh_email' => ['oleh_email' => 'orang-lain@sidik.test'],
            'terjadi_pada' => ['terjadi_pada' => '2030-01-02 08:00:00'],
            'catatan' => ['catatan' => 'ditulis ulang'],
            'updated_at' => ['updated_at' => '2030-01-02 08:00:00'],
        ] as $label => $ubah) {
            $this->assertDitolak(fn () => DB::table($tabel)->where('id', $idJejak)->update($ubah), $tabel, $idJejak, "jejak: {$label}");
        }

        $this->assertDitolak(fn () => ParameterMetodePenetapanJejak::query()->update(['catatan' => 'massal']), $tabel, $idJejak, 'jejak: update massal');
        $this->assertDitolak(fn () => DB::table($tabel)->where('id', $idJejak)->delete(), $tabel, $idJejak, 'jejak: delete');
        $this->assertDitolak(fn () => ParameterMetodePenetapanJejak::query()->delete(), $tabel, $idJejak, 'jejak: delete massal');

        // Menambah baris jejak tetap bebas: append-only, bukan baca-saja.
        DB::table($tabel)->insert([
            'penetapan_id' => $penetapan,
            'dari_status' => 'menunggu_tm',
            'ke_status' => 'disetujui',
            'oleh_user_id' => $pengguna->id,
            'oleh_email' => 'pelaku@sidik.test',
            'terjadi_pada' => '2030-01-02 08:00:00',
            'catatan' => 'disetujui',
            'created_at' => '2030-01-02 08:00:00',
            'updated_at' => '2030-01-02 08:00:00',
        ]);
        $this->assertSame(2, DB::table($tabel)->count());
    }

    public function test_jalur_service_catat_setujui_tarik_tetap_jalan_dengan_trigger_terpasang(): void
    {
        $this->pastikanTriggerTerpasang();

        $pelaku = User::factory()->create();
        $layanan = app(PenetapanParameterMetode::class);
        $berlakuMulai = CarbonImmutable::today()->addDays(30)->toDateString();

        $baris = $layanan->catat($pelaku, self::KUNCI, 'tanpa_titik_pertama', $berlakuMulai, 'Jawaban TM.');
        $this->assertSame(ParameterMetodePenetapan::STATUS_MENUNGGU_TM, $baris->status);

        $baris = $layanan->setujui($pelaku, $baris->id, 'Manajer Teknis uji', PenetapanParameterMetode::hariIni());
        $this->assertSame(ParameterMetodePenetapan::STATUS_DISETUJUI, $baris->status);

        $baris = $layanan->tarik($pelaku, $baris->id, 'Dibatalkan dalam uji.');
        $this->assertSame(ParameterMetodePenetapan::STATUS_DITARIK, $baris->status);

        $dibaca = $this->baris('parameter_metode_penetapan', $baris->id);
        $this->assertSame('tanpa_titik_pertama', $dibaca['nilai']);
        $this->assertSame('Jawaban TM.', $dibaca['catatan']);
        $this->assertSame('Manajer Teknis uji', $dibaca['keputusan_oleh']);
        $this->assertSame(
            [[null, 'menunggu_tm'], ['menunggu_tm', 'disetujui'], ['disetujui', 'ditarik']],
            DB::table('parameter_metode_penetapan_jejak')->where('penetapan_id', $baris->id)->orderBy('id')->get()
                ->map(static fn (object $j): array => [$j->dari_status, $j->ke_status])->all(),
        );
    }

    /**
     * `down()` migrasi pembuat tabel tidak boleh membuang jejak audit tanpa bekas.
     * Penjaganya melempar SEBELUM DDL apa pun (jadi aman di MySQL, tanpa commit implisit).
     */
    public function test_rollback_migrasi_tabel_ditolak_selama_ada_baris(): void
    {
        $this->sisipPenetapan();

        $migrasi = require database_path('migrations/2026_11_25_100000_create_parameter_metode_penetapan_tables.php');

        try {
            $migrasi->down();
            $this->fail('down() menjatuhkan tabel yang masih berisi jejak audit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback ditolak', $e->getMessage());
        }

        $this->assertSame(1, DB::table('parameter_metode_penetapan')->count());
    }
}
