<?php

namespace Tests\Unit;

use App\Support\TandaTanganTebal;
use PHPUnit\Framework\TestCase;

/**
 * Goresan tanda tangan wajib tercetak setebal pena, tanpa bentuknya berubah.
 *
 * ## Cacat yang dijaga
 *
 * Tanda tangan PT Sidik tercetak hitam pekat (RGB benar-benar 0) tapi kelihatan
 * pudar keabuan di sertifikat. Bukan warnanya: goresannya 0,217 mm sementara
 * pena sungguhan 0,30 - 0,50 mm. Setipis itu, waktu dikecilkan dari 2248 px ke
 * 25 mm, separuh goresannya di-antialias jadi abu.
 *
 * ## Dua kegagalan yang SUDAH terjadi waktu ini dibangun
 *
 * Dua-duanya lolos begitu saja sampai hasilnya diukur, dan dua-duanya punya
 * test sendiri di bawah:
 *
 * 1. Jari-jari pelebaran dihitung sekali di depan dari selisih tebal. Penapis
 *    kotak melebarkan goresan MIRING lebih dari dua kali jari-jarinya, jadi
 *    hasilnya 0,508 mm — 18% lewat dari yang disetujui.
 * 2. Ambang "piksel ini bertinta" dipasang 32 pada skala GD 0..127, padahal
 *    acuannya 12,5%. Dua kali lebih ketat, goresannya terukur lebih tipis dari
 *    kenyataan, dan pelebarannya jalan sekali lagi dari seharusnya.
 */
class TandaTanganTebalTest extends TestCase
{
    /**
     * Coretan mirip tanda tangan: melengkung, dan tepinya BER-ANTIALIAS.
     *
     * Versi pertama helper ini menggambar satu garis lurus dengan
     * `imagesetthickness` — tepinya keras, alpha-nya cuma 0 atau 127. Dengan
     * fixture sebersih itu, KEDUA cacat yang berkas ini klaim dijaga tetap
     * lolos waktu sengaja dikembalikan: ambang 16 vs 32 tidak ada bedanya kalau
     * tidak ada piksel setengah pekat, dan pemilihan putaran tidak ada bedanya
     * kalau goresannya tidak miring-melengkung.
     *
     * Jadi digambar besar lalu DIKECILKAN, persis seperti tanda tangan pindai
     * yang asli — pengecilan itu yang melahirkan piksel setengah pekat, dan
     * piksel setengah pekat itu yang jadi pokok persoalannya.
     */
    private function coretan(int $sisi, int $tebal, array $rgb = [0, 0, 0]): string
    {
        $skala = 4;
        $besar = imagecreatetruecolor($sisi * $skala, $sisi * $skala);
        imagealphablending($besar, false);
        imagesavealpha($besar, true);
        imagefill($besar, 0, 0, imagecolorallocatealpha($besar, 0, 0, 0, 127));

        $tinta = imagecolorallocatealpha($besar, $rgb[0], $rgb[1], $rgb[2], 0);
        imagesetthickness($besar, $tebal * $skala);

        // Lengkung, bukan lurus: goresan miring dengan sudut yang berubah-ubah
        // itu yang bikin penapis kotak melebar lebih dari dua kali jari-jarinya.
        $t = $sisi * $skala;
        $x0 = (int) ($t * 0.1);
        $y0 = (int) ($t * 0.6);

        for ($i = 1; $i <= 40; $i++) {
            $u = $i / 40;
            $x1 = (int) ($t * (0.1 + 0.8 * $u));
            $y1 = (int) ($t * (0.6 - 0.35 * sin($u * M_PI * 1.6)));
            imageline($besar, $x0, $y0, $x1, $y1, $tinta);
            $x0 = $x1;
            $y0 = $y1;
        }

        $kecil = imagecreatetruecolor($sisi, $sisi);
        imagealphablending($kecil, false);
        imagesavealpha($kecil, true);
        imagefill($kecil, 0, 0, imagecolorallocatealpha($kecil, 0, 0, 0, 127));
        imagecopyresampled($kecil, $besar, 0, 0, 0, 0, $sisi, $sisi, $sisi * $skala, $sisi * $skala);

        ob_start();
        imagepng($kecil);
        $isi = (string) ob_get_clean();
        imagedestroy($besar);
        imagedestroy($kecil);

        return $isi;
    }

    /** Tebal goresan hasil cetak, milimeter — diukur mendatar seperti di produksi. */
    private function tebalMm(string $png, float $lebarMm): float
    {
        $img = imagecreatefromstring($png);
        $w = imagesx($img);
        $h = imagesy($img);
        $perMm = $w / $lebarMm;

        $deret = [];

        for ($y = 0; $y < $h; $y += max(1, intdiv($h, 60))) {
            $panjang = 0;

            for ($x = 0; $x < $w; $x++) {
                $pekat = 127 - ((imagecolorat($img, $x, $y) >> 24) & 0x7F);

                if ($pekat > 16) {
                    $panjang++;

                    continue;
                }

                if ($panjang > 0) {
                    $deret[] = $panjang;
                    $panjang = 0;
                }
            }

            if ($panjang > 0) {
                $deret[] = $panjang;
            }
        }

        imagedestroy($img);

        $batas = max(4, intdiv($w, 4));
        $deret = array_values(array_filter($deret, fn (int $p): bool => $p <= $batas));
        sort($deret);

        return $deret === [] ? 0.0 : $deret[intdiv(count($deret), 2)] / $perMm;
    }

    /** Kotak tinta relatif terhadap kanvas — dipakai membuktikan bentuknya utuh. */
    private function rasioTinta(string $png): float
    {
        $img = imagecreatefromstring($png);
        $w = imagesx($img);
        $h = imagesy($img);
        $x0 = $w;
        $y0 = $h;
        $x1 = -1;
        $y1 = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ((127 - ((imagecolorat($img, $x, $y) >> 24) & 0x7F)) <= 8) {
                    continue;
                }

                $x0 = min($x0, $x);
                $y0 = min($y0, $y);
                $x1 = max($x1, $x);
                $y1 = max($y1, $y);
            }
        }

        imagedestroy($img);

        return $x1 <= $x0 ? 0.0 : ($y1 - $y0) / ($x1 - $x0);
    }

    // ------------------------------------------------------------ jalur utama

    /**
     * Goresan tipis DITEBALKAN sampai mendekati bobot pena.
     *
     * Pitanya sengaja tidak simetris: -15% / +10%.
     *
     * Kurang tebal cuma berarti agak tipis. Kelewat tebal berarti menggumpal —
     * dan itu kegagalan yang SUDAH dua kali terjadi waktu kelas ini dibangun,
     * jadi sisi itu yang dijaga rapat. Batas atasnya diukur, bukan dikira-kira:
     * kode yang benar menghasilkan paling tebal 1,05x BOBOT pada fixture di
     * sini, sementara kedua cacatnya mendarat di 1,12x dan 1,18x.
     */
    public function test_goresan_tipis_ditebalkan_ke_bobot_pena(): void
    {
        $tipis = $this->coretan(900, 6);
        $sebelum = $this->tebalMm($tipis, 24.9);

        $sesudah = $this->tebalMm(TandaTanganTebal::pena($tipis, 24.9), 24.9);

        $this->assertGreaterThan($sebelum, $sesudah, 'Goresannya nggak ditebalkan sama sekali.');
        $this->assertGreaterThan(TandaTanganTebal::BOBOT_MM * 0.85, $sesudah, 'Masih kelewat tipis.');
        $this->assertLessThan(
            TandaTanganTebal::BOBOT_MM * 1.10,
            $sesudah,
            'Kelewat tebal — tersangka pertama AMBANG_TINTA yang nggak sebanding dengan '
            .'ambang pengukurnya, bikin goresannya terukur lebih tipis dari kenyataan.',
        );
    }

    /**
     * TIDAK melewati target jauh-jauh.
     *
     * Kegagalan yang sudah terjadi: jari-jari dihitung sekali di depan, hasilnya
     * 0,508 mm untuk target 0,43 — 18% kelewat, dan mulai kelihatan menggumpal.
     * Penjaga ini yang menahan siapa pun mengganti pemilihan putaran balik ke
     * "berhenti begitu lewat".
     */
    public function test_nggak_kelewat_tebal(): void
    {
        // Tiga ketebalan, bukan satu: tiap cacat cuma kelihatan di sebagian.
        // Yang 6px membedakan ambang tinta yang salah, yang 12px membedakan
        // "berhenti begitu lewat". Satu fixture saja bikin test ini hijau
        // sementara salah satu cacatnya utuh — itu yang kejadian di versi
        // pertamanya.
        foreach ([6, 8, 12] as $tebal) {
            $hasil = TandaTanganTebal::pena($this->coretan(900, $tebal), 24.9);

            $this->assertLessThan(
                TandaTanganTebal::BOBOT_MM * 1.10,
                $this->tebalMm($hasil, 24.9),
                "Goresan {$tebal}px kelewat tebal sesudah diolah — bakal menggumpal.",
            );
        }
    }

    /**
     * Yang SUDAH setebal pena tidak disentuh.
     *
     * Ini yang membedakan target bobot dari pengali tetap: tanda tangan yang
     * digoreskan pakai spidol tebal nggak boleh ikut digemukkan jadi gumpalan.
     */
    public function test_yang_sudah_tebal_nggak_digemukkan(): void
    {
        // 40 px pada kanvas 900 px yang dicetak 24,9 mm = 1,1 mm, jauh di atas pena.
        $tebal = $this->coretan(900, 40);
        $sebelum = $this->tebalMm($tebal, 24.9);

        $sesudah = $this->tebalMm(TandaTanganTebal::pena($tebal, 24.9), 24.9);

        $this->assertEqualsWithDelta($sebelum, $sesudah, $sebelum * 0.1);
    }

    /**
     * BENTUKNYA utuh — ini syarat yang bikin cara ini dipilih.
     *
     * Percobaan sebelumnya memangkas ekor tanda tangan supaya muat lebih besar,
     * dan pemilik proyek menolaknya: potongannya kelihatan, dan ini dokumen
     * terkendali. Menebalkan boleh justru karena tidak menghilangkan apa pun.
     */
    public function test_bentuknya_nggak_berubah(): void
    {
        $asli = $this->coretan(900, 8);

        $this->assertEqualsWithDelta(
            $this->rasioTinta($asli),
            $this->rasioTinta(TandaTanganTebal::pena($asli, 24.9)),
            0.06,
            'Perbandingan tinggi-lebar tintanya bergeser — ada yang terpotong atau melar.',
        );
    }

    // ---------------------------------------------------------------- penjaga

    /**
     * Alpha TIDAK boleh rata jadi buram.
     *
     * Kalau `imagealphablending`/`imagesavealpha` lupa disetel, tanda tangan
     * mendarat sebagai KOTAK hitam pekat yang menutupi garis tanda tangan dan
     * nama penanda tangan di bawahnya. Sertifikatnya tetap terbit, tetap satu
     * halaman, dan tetap tanpa error — cuma tidak terbaca.
     */
    public function test_latarnya_tetap_tembus_pandang(): void
    {
        $hasil = TandaTanganTebal::pena($this->coretan(900, 8), 24.9);

        $img = imagecreatefromstring($hasil);
        $sudut = (imagecolorat($img, 2, imagesy($img) - 3) >> 24) & 0x7F;
        imagedestroy($img);

        $this->assertSame(127, $sudut, 'Pojok gambarnya nggak bening — latarnya kecetak jadi kotak.');
    }

    /**
     * Warna tinta dipertahankan.
     *
     * Pena biru itu wajar di dokumen lab, dan tanda tangan yang berubah jadi
     * hitam sesudah lewat sini bukan lagi tanda tangan yang diunggah admin.
     */
    public function test_tanda_tangan_biru_tetap_biru(): void
    {
        $hasil = TandaTanganTebal::pena($this->coretan(900, 8, [20, 40, 200]), 24.9);

        $img = imagecreatefromstring($hasil);
        $tengah = imagecolorat($img, intdiv(imagesx($img), 2), intdiv(imagesy($img), 2));
        imagedestroy($img);

        $r = ($tengah >> 16) & 0xFF;
        $b = $tengah & 0xFF;

        $this->assertGreaterThan($r + 80, $b, 'Tinta birunya berubah jadi kehitaman.');
    }

    /**
     * Berkas rusak TIDAK menggagalkan sertifikat.
     *
     * Yang dikembalikan isi aslinya, bukan null dan bukan lemparan: sertifikat
     * lebih baik terbit dengan tanda tangan tipis daripada tidak terbit.
     */
    public function test_berkas_rusak_dikembalikan_apa_adanya(): void
    {
        foreach (['ini-bukan-gambar', ''] as $rusak) {
            $this->assertSame($rusak, TandaTanganTebal::pena($rusak, 24.9));
        }

        $this->assertNull(TandaTanganTebal::pena(null, 24.9));
    }

    /** Lebar cetak yang tidak masuk akal dilewat, bukan bikin pembagian nol. */
    public function test_lebar_cetak_nol_atau_minus_dilewat(): void
    {
        $png = $this->coretan(200, 4);

        foreach ([0.0, -5.0] as $lebar) {
            $this->assertSame($png, TandaTanganTebal::pena($png, $lebar));
        }
    }
}
