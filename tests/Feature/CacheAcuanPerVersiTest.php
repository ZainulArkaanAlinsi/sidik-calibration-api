<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\TabelStandarMicrometer;
use App\Support\JsonKanonik;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use ReflectionProperty;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Cache data acuan dikunci (versi, sha256), bukan per kelas (03-SDD §4).
 *
 * Yang dicegah: worker `queue:work` yang hidup sampai satu jam tetap menghitung
 * dengan isi versi LAMA sesudah lingkup berganti — tanpa satu pun error. Semua
 * kasus di sini berjalan dalam SATU proses dan memakai instance tabel yang
 * sama, persis keadaan worker.
 */
class CacheAcuanPerVersiTest extends TestCase
{
    use RefreshDatabase;

    /** Nilai terkoreksi balok 2,5 mm di berkas JSON. */
    private const NILAI_BERKAS = 2.50014;

    /** Nilai yang sengaja digeser di versi lain. */
    private const NILAI_LAIN = 2.50099;

    private PaketAcuanVersi $v1;

    private PaketAcuanVersi $v2;

    private SumberAcuan $sumber;

    protected function setUp(): void
    {
        parent::setUp();

        config(['acuan.sumber' => 'db']);
        SumberAcuan::lupakanCache();

        $organisasi = Organization::factory()->create();

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $organisasi->id])
            ->assertSuccessful();

        $this->v1 = PaketAcuanVersi::query()->sole();
        $isiLain = self::isiDenganBalok($this->v1, self::NILAI_LAIN);

        $this->v2 = PaketAcuanVersi::create([
            'paket_acuan_id' => $this->v1->paket_acuan_id,
            'organization_id' => $organisasi->id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => 2,
            'status' => PaketAcuanVersi::STATUS_DIAJUKAN,
            'isi' => $isiLain,
            'isi_sha256' => JsonKanonik::sha256($isiLain),
            'skema_versi' => 1,
            'dasar_versi_id' => $this->v1->id,
        ]);

        $this->assertNotSame($this->v1->isi_sha256, $this->v2->isi_sha256);
        $this->sumber = app(SumberAcuan::class);
    }

    /** Isi kanonik `$versi` dengan balok 2,5 mm diganti `$nilai`. */
    private static function isiDenganBalok(PaketAcuanVersi $versi, float $nilai): string
    {
        $isi = $versi->isiTerurai();
        $isi['balok_ukur']['2.5'] = $nilai;

        return JsonKanonik::serialisasi($isi);
    }

    public function test_pergantian_lingkup_langsung_terbaca_oleh_instance_yang_sama(): void
    {
        // Satu instance untuk semua langkah — seperti calculator yang hidup di worker.
        $tabel = new TabelStandarMicrometer;
        $nilai = fn (): ?float => $tabel->nilaiTerkoreksi(2.5);

        $this->assertSame(self::NILAI_BERKAS, $nilai());
        $this->assertSame(self::NILAI_BERKAS, $this->sumber->denganVersi($this->v1, $nilai));
        $this->assertSame(self::NILAI_LAIN, $this->sumber->denganVersi($this->v2, $nilai));
        $this->assertSame(self::NILAI_BERKAS, $this->sumber->denganVersi($this->v1, $nilai));
        $this->assertSame(self::NILAI_BERKAS, $nilai(), 'di luar lingkup kembali ke berkas JSON');

        // Instance baru di dalam lingkup juga membaca versi, bukan cache kelas.
        $this->assertSame(
            self::NILAI_LAIN,
            $this->sumber->denganVersi($this->v2, fn () => (new TabelStandarMicrometer)->nilaiTerkoreksi(2.5)),
        );
    }

    public function test_lingkup_bersarang_yang_terdalam_menang_lalu_kembali(): void
    {
        $tabel = new TabelStandarMicrometer;

        $hasil = $this->sumber->denganVersi($this->v1, function () use ($tabel): array {
            $sebelum = $tabel->nilaiTerkoreksi(2.5);
            $dalam = $this->sumber->denganVersi($this->v2, fn () => $tabel->nilaiTerkoreksi(2.5));
            $sesudah = $tabel->nilaiTerkoreksi(2.5);

            return [$sebelum, $dalam, $sesudah, $this->sumber->versiDalamLingkup('micrometer')?->id];
        });

        $this->assertSame([self::NILAI_BERKAS, self::NILAI_LAIN, self::NILAI_BERKAS, $this->v1->id], $hasil);
        $this->assertNull($this->sumber->versiDalamLingkup('micrometer'));
    }

    public function test_exception_di_dalam_lingkup_tetap_melepas_lingkup(): void
    {
        $tabel = new TabelStandarMicrometer;

        try {
            $this->sumber->denganVersi($this->v2, function () use ($tabel): void {
                $this->assertSame(self::NILAI_LAIN, $tabel->nilaiTerkoreksi(2.5));

                throw new DomainException('gagal di tengah hitungan');
            });
            $this->fail('exception harus diteruskan, bukan ditelan');
        } catch (DomainException $e) {
            $this->assertSame('gagal di tengah hitungan', $e->getMessage());
        }

        $this->assertNull($this->sumber->versiDalamLingkup('micrometer'));
        $this->assertSame(self::NILAI_BERKAS, $tabel->nilaiTerkoreksi(2.5));

        // Bersarang: exception di lingkup dalam tidak merusak lingkup luar.
        $luar = $this->sumber->denganVersi($this->v1, function () use ($tabel): ?float {
            try {
                $this->sumber->denganVersi($this->v2, fn () => throw new DomainException('dalam'));
            } catch (DomainException) {
                // diteruskan sampai sini, lingkup v2 sudah dilepas
            }

            return $tabel->nilaiTerkoreksi(2.5);
        });

        $this->assertSame(self::NILAI_BERKAS, $luar);
        $this->assertNull($this->sumber->versiDalamLingkup('micrometer'));
    }

    public function test_sumber_json_mengabaikan_lingkup(): void
    {
        config(['acuan.sumber' => 'json']);

        $nilai = $this->sumber->denganVersi($this->v2, fn () => (new TabelStandarMicrometer)->nilaiTerkoreksi(2.5));

        $this->assertSame(self::NILAI_BERKAS, $nilai);
    }

    /**
     * Lingkup dikunci per `kode_profil`. Versi milik profil lain (di sini
     * paket Dial Indicator berisi tabel yang digeser) tidak boleh mengubah
     * tabel Micrometer — keputusan P1 butir 2.
     */
    public function test_lingkup_profil_lain_tidak_mengubah_micrometer(): void
    {
        $paketLain = PaketAcuan::create([
            'organization_id' => $this->v1->organization_id,
            'kode_profil' => 'dial_indicator',
            'nama' => 'Dial Indicator',
            'skema_versi' => 1,
        ]);

        $versiLain = PaketAcuanVersi::create([
            'paket_acuan_id' => $paketLain->id,
            'organization_id' => $this->v1->organization_id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => 1,
            'status' => PaketAcuanVersi::STATUS_AKTIF,
            'isi' => $this->v2->isi,
            'isi_sha256' => $this->v2->isi_sha256,
            'skema_versi' => 1,
        ]);

        $nilai = $this->sumber->denganVersi($versiLain, fn () => (new TabelStandarMicrometer)->nilaiTerkoreksi(2.5));

        $this->assertSame(self::NILAI_BERKAS, $nilai);
    }

    /** Draf (sha256 masih null) yang disunting dalam proses yang sama langsung terbaca. */
    public function test_draf_tanpa_sha256_yang_disunting_langsung_terbaca(): void
    {
        $draf = $this->v2;
        $draf->isi_sha256 = null;
        $tabel = new TabelStandarMicrometer;

        $this->assertSame(self::NILAI_LAIN, $this->sumber->denganVersi($draf, fn () => $tabel->nilaiTerkoreksi(2.5)));

        $draf->isi = self::isiDenganBalok($draf, 2.50123);

        $this->assertSame(2.50123, $this->sumber->denganVersi($draf, fn () => $tabel->nilaiTerkoreksi(2.5)));
    }

    public function test_isi_yang_tidak_cocok_dengan_sha256_ditolak(): void
    {
        $rusak = $this->v2;
        $rusak->isi_sha256 = $this->v1->isi_sha256;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('tidak cocok dengan isi_sha256');

        $this->sumber->denganVersi($rusak, fn () => (new TabelStandarMicrometer)->nilaiTerkoreksi(2.5));
    }

    public function test_versi_bentuk_ditolak_sebagai_lingkup_data(): void
    {
        $bentuk = $this->v2->replicate();
        $bentuk->jenis = PaketAcuanVersi::JENIS_BENTUK;

        $this->expectException(InvalidArgumentException::class);

        $this->sumber->denganVersi($bentuk, fn () => null);
    }

    /** Worker panjang tidak boleh menumpuk isi versi tanpa batas. */
    public function test_cache_dibatasi_dan_tetap_benar_sesudah_penggusuran(): void
    {
        $paket = PaketAcuan::query()->sole();
        $tabel = new TabelStandarMicrometer;

        for ($i = 1; $i <= SumberAcuan::BATAS_CACHE + 8; $i++) {
            $nilaiBaru = 2.5 + $i / 100000;
            $versi = new PaketAcuanVersi([
                'paket_acuan_id' => $paket->id,
                'organization_id' => $paket->organization_id,
                'jenis' => PaketAcuanVersi::JENIS_DATA,
                'nomor_versi' => 100 + $i,
                'status' => PaketAcuanVersi::STATUS_DRAF,
                'isi' => self::isiDenganBalok($this->v1, $nilaiBaru),
                'skema_versi' => 1,
            ]);

            $this->assertSame($nilaiBaru, $this->sumber->denganVersi($versi, fn () => $tabel->nilaiTerkoreksi(2.5)));
        }

        $cache = (new ReflectionProperty(SumberAcuan::class, 'cache'))->getValue();
        $this->assertLessThanOrEqual(SumberAcuan::BATAS_CACHE, count($cache));

        // Sesudah penggusuran, versi lama dan berkas tetap terbaca benar.
        $this->assertSame(self::NILAI_LAIN, $this->sumber->denganVersi($this->v2, fn () => $tabel->nilaiTerkoreksi(2.5)));
        $this->assertSame(self::NILAI_BERKAS, $tabel->nilaiTerkoreksi(2.5));
    }

    /**
     * Calculator yang hidup lintas lingkup (seperti di worker) ikut berganti
     * angka: komponen budget yang memakai balok 2,5 mm berubah di v2 dan
     * kembali sama di v1.
     */
    public function test_calculator_yang_sama_mengikuti_lingkup(): void
    {
        $kalk = new MicrometerCalculator;
        $u = fn (): float => $kalk->ketidakpastianTumpukan([2.5]);

        // ketidakpastianBalok tidak bergantung nilai terkoreksi; yang diuji di
        // sini bahwa calculator tidak menyimpan tabelnya per isi.
        $this->assertSame($u(), $this->sumber->denganVersi($this->v1, $u));

        $tabelDalamKalk = (new ReflectionProperty(MicrometerCalculator::class, 'tabel'))->getValue($kalk);
        $this->assertInstanceOf(TabelStandarMicrometer::class, $tabelDalamKalk);
        $this->assertSame(self::NILAI_LAIN, $this->sumber->denganVersi($this->v2, fn () => $tabelDalamKalk->nilaiTerkoreksi(2.5)));
        $this->assertSame(self::NILAI_BERKAS, $tabelDalamKalk->nilaiTerkoreksi(2.5));
    }
}
