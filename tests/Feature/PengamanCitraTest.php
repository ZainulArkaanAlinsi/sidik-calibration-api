<?php

namespace Tests\Feature;

use App\Support\PengamanCitra;
use Tests\Support\CitraSintetis;
use Tests\TestCase;

/**
 * Pengaman decode citra (`App\Support\PengamanCitra`) dan sapuan yang menjamin tidak ada jalan lain.
 *
 * ## Cacat yang dijaga
 *
 * `imagecreatefromstring` memakan memori ~4 byte PER PIKSEL. JPEG 6000x8000 hanya ~750 KB (jauh di bawah batas
 * unggah 8 MB) tapi butuh ~190 MB; `memory_limit` produksi 128 MB. Habis memori itu fatal: 500 polos tanpa
 * `fallback_manual`, dan `@` tidak menangkapnya. Ditemukan di tiga tempat sekaligus (potong sel lama, potong
 * sel OCR awan, penebal tanda tangan), dan perbaikan satu tempat saja berarti yang berikutnya lahir lagi.
 * Karena itu aturannya ditegakkan oleh tes sapuan, bukan oleh ingatan.
 *
 * Citra "besar" di sini selalu sintetis: berkas 8x8 dengan header yang ditambal (`CitraSintetis`). Tidak ada
 * yang mengalokasi 48 MP sungguhan. Keputusan menolak diambil dari header, jadi alasan `TERLALU_BESAR` hanya bisa
 * keluar kalau decode TIDAK dicoba (jalur decode tidak pernah menghasilkan alasan itu).
 */
class PengamanCitraTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD tidak ada.');
        }
    }

    public function test_citra_normal_didekode_dan_ukurannya_utuh(): void
    {
        $gambar = PengamanCitra::dekode(CitraSintetis::jpegSungguhan(400, 600), $alasan);

        $this->assertInstanceOf(\GdImage::class, $gambar);
        $this->assertNull($alasan);
        $this->assertSame(400, imagesx($gambar));
        $this->assertSame(600, imagesy($gambar));
    }

    public function test_png_normal_juga_didekode(): void
    {
        $sumber = imagecreatetruecolor(50, 20);
        ob_start();
        imagepng($sumber);
        $png = (string) ob_get_clean();

        $gambar = PengamanCitra::dekode($png, $alasan);

        $this->assertInstanceOf(\GdImage::class, $gambar);
        $this->assertNull($alasan);
        $this->assertSame([50, 20], [imagesx($gambar), imagesy($gambar)]);
    }

    public function test_foto_hp_12_mp_lolos_dan_48_mp_ditolak(): void
    {
        // 12 MP (4000x3000) dan 15,9 MP (4608x3456) = foto HP biasa. Keputusan dari header; decode 12 MP
        // sungguhan diukur 50 MB (lihat komentar `maks_piksel_citra` di config/ocr.php).
        foreach ([[4000, 3000], [3000, 4000], [4608, 3456]] as [$lebar, $tinggi]) {
            $this->assertNull(PengamanCitra::tolak(CitraSintetis::jpegMengaku($lebar, $tinggi)), "{$lebar}x{$tinggi} harus lolos");
        }

        // 48 MP (8000x6000 dan 6000x8000) dan 108 MP: ditolak.
        foreach ([[8000, 6000], [6000, 8000], [12000, 9000]] as [$lebar, $tinggi]) {
            $this->assertSame(
                PengamanCitra::TERLALU_BESAR,
                PengamanCitra::tolak(CitraSintetis::jpegMengaku($lebar, $tinggi)),
                "{$lebar}x{$tinggi} harus ditolak",
            );
        }
    }

    public function test_citra_terlalu_besar_tidak_didekode_dan_alasannya_terlalu_besar(): void
    {
        foreach ([CitraSintetis::jpegMengaku(6000, 8000), CitraSintetis::pngMengaku(8000, 6000)] as $isi) {
            $gambar = PengamanCitra::dekode($isi, $alasan);

            $this->assertNull($gambar);
            $this->assertSame(PengamanCitra::TERLALU_BESAR, $alasan);
        }
    }

    public function test_header_png_dengan_sisi_raksasa_ditolak_tanpa_meluap(): void
    {
        // 2^31 x 2^31 meluap di int 64-bit kalau dikali; hasilnya harus tetap "terlalu besar", bukan angka kecil.
        $isi = CitraSintetis::pngMengaku(2147483647, 2147483647);

        $this->assertSame(PengamanCitra::TERLALU_BESAR, PengamanCitra::tolak($isi));
    }

    public function test_batas_inklusif_tepat_di_batas_lolos_sepiksel_di_atasnya_ditolak(): void
    {
        $isi = CitraSintetis::jpegSungguhan(400, 600);

        config(['ocr.maks_piksel_citra' => 400 * 600]);
        $this->assertInstanceOf(\GdImage::class, PengamanCitra::dekode($isi));

        config(['ocr.maks_piksel_citra' => 400 * 600 - 1]);
        $this->assertNull(PengamanCitra::dekode($isi, $alasan));
        $this->assertSame(PengamanCitra::TERLALU_BESAR, $alasan);
    }

    public function test_bukan_citra_ditolak_sebagai_bukan_citra(): void
    {
        foreach (['', 'ini bukan gambar', "\xFF\xD8\xFF", '%PDF-1.4 bukan citra'] as $isi) {
            $gambar = PengamanCitra::dekode($isi, $alasan);

            $this->assertNull($gambar, 'isi: '.bin2hex(substr($isi, 0, 6)));
            $this->assertSame(PengamanCitra::BUKAN_CITRA, $alasan);
        }
    }

    public function test_sisi_nol_ditolak_sebagai_bukan_citra(): void
    {
        // `getimagesizefromstring` melaporkan sisi 0 apa adanya (bukan false), jadi pengaman yang harus menolaknya.
        foreach ([[0, 100], [100, 0], [0, 0]] as [$lebar, $tinggi]) {
            foreach ([CitraSintetis::jpegMengaku($lebar, $tinggi), CitraSintetis::pngMengaku($lebar, $tinggi)] as $isi) {
                $this->assertNull(PengamanCitra::dekode($isi, $alasan), "{$lebar}x{$tinggi}");
                $this->assertSame(PengamanCitra::BUKAN_CITRA, $alasan, "{$lebar}x{$tinggi}");
            }
        }
    }

    public function test_batas_bawaan_di_config_sama_dengan_konstanta_cadangan(): void
    {
        $this->assertSame(PengamanCitra::BATAS_PIKSEL_BAWAAN, config('ocr.maks_piksel_citra'));
        $this->assertSame(16_000_000, PengamanCitra::batasPiksel());
    }

    public function test_config_rusak_jatuh_ke_batas_bawaan_bukan_menolak_semua_atau_membuka_semua(): void
    {
        foreach ([null, 0, -5, 'bukan angka'] as $rusak) {
            config(['ocr.maks_piksel_citra' => $rusak]);

            $this->assertSame(PengamanCitra::BATAS_PIKSEL_BAWAAN, PengamanCitra::batasPiksel(), var_export($rusak, true));
        }
    }

    /**
     * Sapuan permanen. Pemanggil baru yang mendekode citra langsung (GD `imagecreatefrom*` atau `Imagick`)
     * membuat tes ini merah, sebelum sempat jadi 500 di produksi. Jalan keluarnya: lewat `PengamanCitra::dekode()`.
     *
     * Yang dipindai KODE saja (token PHP), bukan komentar: docblok boleh menyebut nama fungsinya.
     * Pustaka lain yang mendekode citra (mis. Intervention) tidak dipakai repo ini; kalau suatu hari masuk,
     * tambahkan namanya di `temukanDekodeCitra()` bersama pemakaiannya.
     */
    public function test_tidak_ada_decode_citra_di_luar_pengaman(): void
    {
        $pengaman = realpath(app_path('Support/PengamanCitra.php'));
        $this->assertNotFalse($pengaman);

        $dipindai = 0;
        $pelanggaran = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $berkas) {
            /** @var \SplFileInfo $berkas */
            if ($berkas->getExtension() !== 'php' || $berkas->getRealPath() === $pengaman) {
                continue;
            }

            $dipindai++;

            foreach ($this->temukanDekodeCitra((string) file_get_contents($berkas->getPathname())) as [$baris, $nama]) {
                $pelanggaran[] = str_replace('\\', '/', substr($berkas->getPathname(), strlen(base_path()) + 1))
                    .":{$baris} memanggil {$nama}";
            }
        }

        $this->assertGreaterThan(150, $dipindai, 'Sapuan tidak menemukan berkas app/: pola direktorinya salah.');
        $this->assertSame(
            [],
            $pelanggaran,
            "Decode citra hanya boleh lewat App\\Support\\PengamanCitra::dekode() (batas piksel dicek dari header).\n"
            .'Memori GD ~4 byte per piksel: JPEG 750 KB bisa berisi 48 MP dan mematikan PHP (fatal, bukan galat).',
        );
    }

    /** Pemindai harus benar-benar menangkap yang dilarang; kalau tidak, sapuan di atas hijau palsu. */
    public function test_pemindai_menangkap_decode_langsung_dan_mengabaikan_komentar(): void
    {
        $this->assertSame([], $this->temukanDekodeCitra(
            "<?php\n// imagecreatefromstring(\$x) dan new Imagick() cuma disebut di komentar\n/** @see imagecreatefrompng */\n"
            .'$a = getimagesizefromstring($x); $b = imagecreatetruecolor(1, 1);',
        ));

        $kode = "<?php\n\$a = @imagecreatefromstring(\$x);\n\$b = new \\Imagick();\n\$c = 'imagecreatefromjpeg';\n"
            ."use Imagick;\n\$d = \\IMAGECREATEFROMPNG(\$x);\n\$e = call_user_func('ImageCreateFromWebp', \$x);";

        $ditemukan = $this->temukanDekodeCitra($kode);

        $this->assertSame([2, 3, 4, 5, 6, 7], array_column($ditemukan, 0), json_encode($ditemukan));
    }

    /**
     * @return list<array{0: int, 1: string}> baris dan nama untuk tiap decode citra di `$kode`
     */
    private function temukanDekodeCitra(string $kode): array
    {
        $hasil = [];
        $polaNama = '/^\\\\?(imagecreatefrom\w*|imagick(pixel)?)$/i';

        foreach (token_get_all($kode) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$jenis, $teks, $baris] = $token;

            if (in_array($jenis, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) && preg_match($polaNama, $teks)) {
                $hasil[] = [$baris, $teks];
            } elseif ($jenis === T_CONSTANT_ENCAPSED_STRING && preg_match('/imagecreatefrom|imagick/i', $teks)) {
                // Nama fungsi dalam string: `call_user_func('imagecreatefromstring', ...)`, `array_map('...')`.
                $hasil[] = [$baris, $teks];
            }
        }

        return $hasil;
    }
}
