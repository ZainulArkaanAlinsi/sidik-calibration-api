<?php

namespace Tests\Unit;

use App\Exceptions\SertifikatLebihSatuHalamanException;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use App\Services\PraPeriksaSatuHalaman;
use App\Services\QrCodeGenerator;
use App\Services\SertifikatSatuHalaman;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Vonis "terbukti tidak muat satu halaman" gerbang pra-approve — LOGIKA-nya saja,
 * dengan dompdf dipalsukan (hanya "berapa halaman hasilnya" yang diprogram).
 * Apakah lembar sungguhan muat dijaga `PraApproveSatuHalamanTest` dengan dompdf
 * beneran; yang diuji di sini yang gampang salah tanpa suara:
 *
 *  - vonis menolak HANYA bila jalur terbit (`isi()`) memang akan melempar;
 *  - batasnya `<= 1` halaman persis seperti jalur terbit (satu halaman lolos,
 *    dua halaman ditolak);
 *  - kasus yang muat (hampir semua) cuma membayar SATU render.
 */
class PraPeriksaSatuHalamanTest extends TestCase
{
    /**
     * Penghitung halaman palsu. `$halaman(paksaPadat, longgar, ujiCadangan, ukuranTabel)`
     * dipanggil tiap `cetak()`; jejak panggilannya bisa dibaca lewat `->panggilan`.
     */
    private function renderer(callable $halaman): SertifikatSatuHalaman
    {
        $tampilan = new class(app(QrCodeGenerator::class)) extends DataTampilanSertifikat
        {
            public function untuk(Certificate $sertifikat, bool $web = false): array
            {
                return ['snapshot' => []];
            }
        };

        return new class($tampilan, $halaman) extends SertifikatSatuHalaman
        {
            /** @var list<array<string, mixed>> */
            public array $panggilan = [];

            /** @param  callable(bool, int, bool, ?float): int  $halaman */
            public function __construct(DataTampilanSertifikat $tampilan, private $halaman)
            {
                parent::__construct($tampilan);
            }

            protected function cetak(
                array $bahan,
                bool $paksaPadat,
                int $longgar = 0,
                bool $ujiCadangan = false,
                ?float $ukuranTabel = null,
                string $keluargaUkuran = 'rapat',
            ): array {
                $this->panggilan[] = compact('paksaPadat', 'longgar', 'ujiCadangan', 'ukuranTabel');

                return ['isi' => 'PDF', 'halaman' => ($this->halaman)($paksaPadat, $longgar, $ujiCadangan, $ukuranTabel)];
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();
    }

    /** Muat dengan mode terpadat: satu render, dan tidak menyentuh tangga lengkap. */
    public function test_yang_muat_cuma_membayar_satu_render(): void
    {
        $r = $this->renderer(fn (bool $paksaPadat): int => $paksaPadat ? 1 : 2);

        $this->assertNull($r->terbuktiTidakMuat(new Certificate));
        $this->assertCount(1, $r->panggilan, 'Jalan cepat harus satu render — request approve jalan di CPU 0,1.');
        $this->assertTrue($r->panggilan[0]['paksaPadat'], 'Yang dirender harus mode terpadat (pilihan terakhir jalur terbit).');
        $this->assertSame(0, $r->panggilan[0]['longgar']);
        $this->assertFalse($r->panggilan[0]['ujiCadangan'], 'Kotak uji 6 px tidak ikut: jalur terbit menjadikannya hanya syarat tingkat, bukan syarat akhir.');
        $this->assertNull($r->panggilan[0]['ukuranTabel']);
    }

    /** Tepat di batas: 1 halaman lolos, 2 halaman ditolak (`<= 1` seperti `paling()`). */
    public function test_batas_tepat_satu_halaman_lolos_dua_halaman_ditolak(): void
    {
        $satu = $this->renderer(fn (): int => 1);
        $this->assertNull($satu->terbuktiTidakMuat(new Certificate));
        $this->assertCount(1, $satu->panggilan);

        $dua = $this->renderer(fn (): int => 2);
        $e = $dua->terbuktiTidakMuat(new Certificate);

        $this->assertInstanceOf(SertifikatLebihSatuHalamanException::class, $e);
        $this->assertSame(2, $e->halamanNormal);
        $this->assertSame(2, $e->halamanPadat);
    }

    /**
     * Mode terpadat tidak muat, tapi tingkat lain di tangga muat: jalur terbit
     * akan menerbitkannya, jadi gerbang TIDAK boleh menolak. (Di produksi ini
     * kasus profil yang ukuran tabelnya mengikuti master.)
     */
    public function test_tingkat_lain_yang_muat_menyelamatkan_dan_tidak_ditolak(): void
    {
        $r = $this->renderer(fn (bool $paksaPadat, int $longgar): int => (! $paksaPadat && $longgar === -2) ? 1 : 2);

        $this->assertNull($r->terbuktiTidakMuat(new Certificate));
        $this->assertGreaterThan(1, count($r->panggilan), 'Vonis tidak muat harus lewat tangga lengkap sebelum menolak.');

        // Dan itu persis yang dilakukan jalur terbit.
        $jalurTerbit = $this->renderer(fn (bool $paksaPadat, int $longgar): int => (! $paksaPadat && $longgar === -2) ? 1 : 2);
        $this->assertSame('PDF', $jalurTerbit->isi(new Certificate));
    }

    /** Semua tingkat habis: ditolak, membawa jumlah halaman mode padat untuk pesan. */
    public function test_semua_tingkat_habis_ditolak_membawa_halaman_padat(): void
    {
        $r = $this->renderer(fn (bool $paksaPadat): int => $paksaPadat ? 3 : 2);

        $e = $r->terbuktiTidakMuat(new Certificate);

        $this->assertInstanceOf(SertifikatLebihSatuHalamanException::class, $e);
        $this->assertSame(2, $e->halamanNormal);
        $this->assertSame(3, $e->halamanPadat);
    }

    /** Vonis gerbang == nasib `isi()` pada lembar yang sama, di seluruh kombinasi kecil. */
    public function test_vonis_gerbang_selalu_sama_dengan_jalur_terbit(): void
    {
        foreach ([1, 2] as $normal) {
            foreach ([1, 2] as $padat) {
                foreach ([1, 2] as $tingkat) {
                    $f = fn (bool $paksaPadat, int $longgar, bool $uji): int => $paksaPadat ? $padat : ($longgar === 1 ? $tingkat : $normal);

                    $ditolakGerbang = $this->renderer($f)->terbuktiTidakMuat(new Certificate) !== null;

                    try {
                        $this->renderer($f)->isi(new Certificate);
                        $ditolakJalurTerbit = false;
                    } catch (SertifikatLebihSatuHalamanException) {
                        $ditolakJalurTerbit = true;
                    }

                    $this->assertSame(
                        $ditolakJalurTerbit,
                        $ditolakGerbang,
                        "normal={$normal} padat={$padat} tingkat={$tingkat}: gerbang dan jalur terbit berbeda vonis.",
                    );
                }
            }
        }
    }

    /** Sakelar mati = tidak ada render sama sekali dan tidak ada akses database. */
    public function test_sakelar_mati_tidak_merender_apa_pun(): void
    {
        config(['sertifikat.pra_approve_satu_halaman' => false]);
        $this->app->bind(SertifikatSatuHalaman::class, fn () => throw new \LogicException('Renderer tidak boleh disentuh saat sakelar mati.'));

        $this->assertNull(app(PraPeriksaSatuHalaman::class)->periksa(new CalibrationSession, new User, 'UJI/1'));
    }

    /**
     * OPT-IN: bawaannya MATI, dan HANYA nilai hidup yang jelas (`true`/`1`/`on`/`yes`)
     * yang menyalakannya. Kosong, salah ketik, atau nilai asing tetap mati — bawaan aman
     * = perilaku lama, tanpa render tambahan di request approve.
     */
    public function test_sakelar_env_bawaan_mati_dan_hanya_nilai_hidup_yang_jelas_menyalakan(): void
    {
        $kunci = 'SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN';
        $awal = [$_SERVER[$kunci] ?? null, $_ENV[$kunci] ?? null];

        try {
            $kasus = [
                ['true', true], ['TRUE', true], ['1', true], ['on', true], ['yes', true], [' ON ', true],
                ['false', false], ['0', false], ['off', false], ['no', false], ['', false],
                ['maybe', false], ['tru', false], ['enabled', false], ['2', false],
            ];

            foreach ($kasus as [$nilai, $diharapkan]) {
                $_SERVER[$kunci] = $_ENV[$kunci] = $nilai;

                $this->assertSame(
                    $diharapkan,
                    (require base_path('config/sertifikat.php'))['pra_approve_satu_halaman'],
                    "SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN='{$nilai}'",
                );
            }

            unset($_SERVER[$kunci], $_ENV[$kunci]);
            $this->assertFalse((require base_path('config/sertifikat.php'))['pra_approve_satu_halaman'], 'Tanpa env: bawaannya MATI.');
        } finally {
            if ($awal[0] === null) {
                unset($_SERVER[$kunci]);
            } else {
                $_SERVER[$kunci] = $awal[0];
            }

            if ($awal[1] === null) {
                unset($_ENV[$kunci]);
            } else {
                $_ENV[$kunci] = $awal[1];
            }
        }
    }

    public function test_pesan_menyebut_sesi_tidak_disetujui_dan_nomor_belum_terpakai(): void
    {
        $pesan = PraPeriksaSatuHalaman::pesan(new SertifikatLebihSatuHalamanException(7, 2, 3));

        $this->assertStringContainsString('3 halaman', $pesan);
        $this->assertStringContainsString('TIDAK disetujui', $pesan);
        $this->assertStringContainsString('nomor sertifikat belum terpakai', $pesan);
    }
}
