<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use App\Support\GambarKhasSertifikat;
use App\Support\TataLetakSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * G2 (R4): diagram posisi termokopel autoclave & enclosure dicetak persis
 * master — hanya untuk sertifikat BARU (`tata_letak.gambar_khas`). Snapshot
 * lama beku dijaga `SertifikatBekuTidakBerubahTest`.
 *
 * Yang DIJAGA di sini: tiap profil bergambar mencetak gambarnya tepat SATU kali
 * di HTML nyata; profil lain tidak; kunci dihapus -> gambar hilang (mutan).
 * Gambar di print area Flowmeter / Gas Detector / Caliper adalah TANDA TANGAN
 * penandatangan master (bukan diagram) dan tidak boleh masuk registry.
 */
class SertifikatGambarKhasTest extends TestCase
{
    use RefreshDatabase;

    /** sha256 aset diagram (diambil dari `media/` master) — bukan tanda tangan. */
    private const SIDIK_JARI_ASET = [
        'posisi-termokopel-autoclave' => '48b26e5bb588f1089110f112e2e9969e0e91567f9a4a44e4f5aebcf7c364d40f',
        'posisi-termokopel-enclosure' => '7407cc5b3710173866e7157d2d85ed3257f6439e22b36e74014fda7891dbb055',
    ];

    /** Sesi seeder -> berkas yang wajib tercetak sekali. */
    private const BERGAMBAR = [
        '2406.51.S' => 'posisi-termokopel-autoclave',
        '2405.03.AV' => 'posisi-termokopel-enclosure',
        '2406.25.AI' => 'posisi-termokopel-enclosure',
    ];

    /** Sesi seeder yang masternya tidak punya diagram — tidak boleh bergambar. */
    private const TANPA_GAMBAR = [
        '001-CAL-126', // Jangka Sorong: gambar master = tanda tangan
        '2602.03.A', // Multi Gas Detector: tanda tangan
        'DEMO-FM-FLW-001', 'DEMO-FM-TOT-001', // Flowmeter: tanda tangan
        '0392-CAL-324', // Dial indicator
    ];

    public function test_aset_dan_registry_cocok(): void
    {
        foreach (self::SIDIK_JARI_ASET as $kunci => $sha) {
            $this->assertArrayHasKey($kunci, GambarKhasSertifikat::BERKAS);
            $this->assertSame($sha, hash_file('sha256', GambarKhasSertifikat::jalur($kunci)), "{$kunci}: aset berubah (bukan lagi diagram master?)");
        }

        $this->assertSame(array_keys(self::SIDIK_JARI_ASET), array_keys(GambarKhasSertifikat::BERKAS));

        foreach (GambarKhasSertifikat::PER_VARIAN as $varian => $entri) {
            $this->assertSame($entri, GambarKhasSertifikat::normalisasi($entri), $varian);
        }

        $this->assertSame(['autoclave', 'enclosure_constant_yokogawa', 'enclosure_recorder'], array_keys(GambarKhasSertifikat::PER_VARIAN));
        $this->assertNull(GambarKhasSertifikat::untuk('jangka_sorong'));
        $this->assertNull(TataLetakSertifikat::BAWAAN['gambar_khas']);
    }

    public function test_normalisasi_menolak_kunci_atau_berkas_asing(): void
    {
        $e = GambarKhasSertifikat::PER_VARIAN['autoclave'][0];

        foreach ([
            [[...$e, 'berkas' => 'tanda-tangan-manajer']],
            [[...$e, 'ekstra' => 1]],
            [[...$e, 'kiri' => 0.9, 'lebar' => 0.5]],
            [[...$e, 'teks' => ['']]],
            [],
        ] as $buruk) {
            try {
                GambarKhasSertifikat::normalisasi($buruk);
                $this->fail('Seharusnya dilempar.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(
            [...GambarKhasSertifikat::PER_VARIAN['autoclave']],
            TataLetakSertifikat::normalisasi(['gambar_khas' => GambarKhasSertifikat::PER_VARIAN['autoclave']])['gambar_khas'],
        );
    }

    public function test_tiap_profil_bergambar_mencetak_gambar_tepat_sekali_dan_mutan_menghilangkannya(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
        $tampilan = app(DataTampilanSertifikat::class);
        $simpan = getenv('SIMPAN_RENDER_G2') ?: null;
        $dicetak = [];

        foreach (self::BERGAMBAR + array_fill_keys(self::TANPA_GAMBAR, null) as $nomor => $berkas) {
            $sesi = CalibrationSession::query()->where('nomor_sesi', 'like', $nomor.'%')->firstOrFail();
            $sertifikat = $this->terbitkan($sesi);
            $bahan = $tampilan->untuk($sertifikat);
            $html = view('sertifikat.pdf', $bahan)->render();
            $jumlah = substr_count($html, 'data-gambar-khas="');

            if ($berkas === null) {
                $this->assertSame(0, $jumlah, "{$nomor}: master tanpa diagram tidak boleh bergambar.");
                $this->assertArrayNotHasKey('gambar_khas', $sertifikat->snapshot['tata_letak'] ?? []);

                continue;
            }

            $dicetak[] = $nomor;
            $this->assertSame(1, $jumlah, "{$nomor}: gambar harus tercetak tepat sekali.");
            $this->assertSame(1, substr_count($html, 'data-gambar-khas="'.$berkas.'"'));
            $this->assertSame(1, substr_count($html, base64_encode(file_get_contents(GambarKhasSertifikat::jalur($berkas)))), "{$nomor}: bytes PNG");
            $this->assertSame(1, substr_count($html, 'Thermocouple Position :'), "{$nomor}: label");
            $this->assertSame($berkas, $sertifikat->snapshot['tata_letak']['gambar_khas'][0]['berkas']);
            $this->assertSame(1, $this->halamanPdf($bahan), "{$nomor}: harus satu halaman");

            if ($simpan !== null) {
                @mkdir($simpan, 0777, true);
                file_put_contents($simpan.'/'.$nomor.'.html', $html);
                file_put_contents($simpan.'/'.$nomor.'.pdf', Pdf::loadView('sertifikat.pdf', $bahan)->output());
            }

            // Mutan: kunci dihapus dari snapshot -> gambar & label hilang.
            $snap = $bahan['snapshot'];
            unset($snap['tata_letak']['gambar_khas']);
            $tanpa = view('sertifikat.pdf', [...$bahan, 'snapshot' => $snap])->render();
            $this->assertSame(0, substr_count($tanpa, 'data-gambar-khas="'), "{$nomor}: mutan masih bergambar");
            $this->assertSame(0, substr_count($tanpa, 'Thermocouple Position :'), "{$nomor}: mutan masih berlabel");
        }

        $this->assertSame(array_keys(self::BERGAMBAR), $dicetak);
    }

    /** @param array<string, mixed> $bahan */
    private function halamanPdf(array $bahan): int
    {
        // Urutan produksi (`SertifikatSatuHalaman`): normal dulu, padat bila meluap.
        foreach ([false, true] as $paksaPadat) {
            $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat]);
            $pdf->output();
            $halaman = (int) $pdf->getDomPDF()->getCanvas()->get_page_count();

            if ($halaman <= 1) {
                return $halaman;
            }
        }

        return $halaman;
    }

    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $ada = $sesi->certificate()->first();

        if ($ada !== null) {
            return $ada;
        }

        $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }
}
