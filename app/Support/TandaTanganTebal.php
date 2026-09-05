<?php

namespace App\Support;

/**
 * Menebalkan goresan tanda tangan sampai setara pena sungguhan waktu tercetak.
 *
 * ## Cacat yang bikin ini ada
 *
 * Tanda tangan PT Sidik tercetak hitam pekat — RGB-nya benar-benar 0 — tapi di
 * sertifikat dia kelihatan pudar dan keabuan. Bukan warnanya yang salah:
 * goresannya yang terlalu tipis.
 *
 * Diukur dari sertifikat CAL-012/CAL/524 yang benar-benar terbit:
 *
 *   tebal goresan waktu tercetak  0,217 mm
 *   pena beneran di kertas        0,30 - 0,50 mm
 *
 * Goresan setipis itu, waktu dikecilkan dari 2248 px ke 25 mm, jatuh di bawah
 * satu piksel cetak — jadi separuhnya di-antialias menjadi abu. Cuma 41% piksel
 * tintanya yang benar-benar mendarat pekat. Yang kelihatan: tanda tangan yang
 * "kurang bold", padahal tintanya sudah sehitam mungkin.
 *
 * ## Kenapa MENEBALKAN, bukan memotong atau menggeser
 *
 * Yang dicoba duluan memangkas ekor turunnya — ekor itu menyumbang 10% tinta
 * tapi memakan 38% tinggi, dan tinggi persis yang dijatah kotak tanda tangan.
 * Hasilnya memang jauh lebih besar, tapi tanda tangannya jadi CACAT: potongan
 * itu kelihatan, dan ini dokumen terkendali. Ditolak pemilik proyek, dan
 * benar begitu.
 *
 * Menebalkan tidak memotong apa pun. Bentuknya, loop-nya, ekornya, semuanya
 * utuh — sama persis seperti tanda tangan yang sama ditulis pakai pena yang
 * lebih besar. Nol piksel tinta hilang.
 *
 * ## Kenapa target BOBOT, bukan faktor pengali tetap
 *
 * Menebalkan "1,6x" itu benar untuk tanda tangan INI, dan salah untuk yang
 * lain: tanda tangan yang sudah digoreskan pakai spidol tebal bakal jadi
 * gumpalan. Yang dipatok di sini hasil akhirnya — 0,35 mm, tengah-tengah
 * rentang pena — dan tebal yang sudah ada DIUKUR dulu. Yang sudah cukup tebal
 * tidak disentuh sama sekali.
 */
final class TandaTanganTebal
{
    /**
     * Bobot goresan yang dituju waktu tercetak, milimeter.
     *
     * Di dalam rentang pena tulis biasa (0,30 - 0,50 mm), dan angkanya BUKAN
     * dipilih dari teori: ini hasil ukur contoh yang disetujui pemilik proyek.
     *
     * Catatan supaya tidak terulang: waktu contohnya disodorkan, labelnya
     * tertulis "0,35 mm" — itu hasil hitungan (0,217 x 1,6), bukan hasil ukur.
     * Gambar yang benar-benar dilihat dan dipilih ternyata 0,432 mm. Yang
     * mengikat gambarnya, bukan labelnya.
     *
     * Sengaja tidak dipasang di batas atas rentang: loop kecil pada tanda
     * tangan yang rapat bisa saling menutup jadi gumpalan hitam, dan itu
     * merusak dengan cara yang berbeda dari terlalu tipis.
     */
    public const BOBOT_MM = 0.43;

    /**
     * Resolusi kerja.
     *
     * Menentukan SEBERAPA HALUS bobotnya bisa disetel, bukan cuma kualitas.
     * Tiap putaran pelebaran menambah tebal sekitar 3,6 piksel; di 600 dpi itu
     * lompatan 0,15 mm, terlalu kasar untuk mendarat dekat 0,43 mm. Di 900 dpi
     * lompatannya 0,10 mm, dan targetnya bisa didekati.
     *
     * Efek sampingnya bagus: gambar yang ditanam ke PDF menyusut dari 2248 px
     * (125 KB) jadi ratusan piksel — PDF-nya ikut mengecil.
     */
    private const DPI = 900;

    /**
     * Batas putaran pelebaran.
     *
     * Jaring pengaman, bukan setelan: goresan yang butuh lebih dari ini berarti
     * gambarnya memang jauh lebih halus daripada yang masuk akal buat tanda
     * tangan, dan menebalkannya terus cuma bikin gumpalan.
     */
    private const MAKS_PUTARAN = 8;

    /**
     * Ambang "piksel ini bertinta", pada skala GD 0..127.
     *
     * Bukan angka bebas: harus SEBANDING dengan ambang yang dipakai waktu
     * bobotnya diukur di luar (12,5% dari pekat penuh). Percobaan pertama
     * memakai 32 — 25% pada skala ini, dua kali lebih ketat — jadi goresannya
     * terukur lebih tipis dari kenyataan, pelebarannya jalan sekali lagi dari
     * seharusnya, dan hasilnya 0,508 mm bukan 0,43 mm.
     *
     * Kegagalan itu tidak menghasilkan error apa pun: keduanya "ambang", dan
     * dua-duanya kelihatan masuk akal sampai hasilnya diukur.
     */
    private const AMBANG_TINTA = 16;

    /**
     * PNG yang goresannya sudah setara pena, atau isi aslinya kalau tidak bisa
     * diolah.
     *
     * Gagal olah TIDAK melempar dan TIDAK mengembalikan null: sertifikat lebih
     * baik terbit dengan tanda tangan tipis daripada tanpa tanda tangan sama
     * sekali. Yang rusak di sini cuma penampilan; yang hilang di sana dokumen.
     *
     * @param  float  $lebarCetakMm  lebar gambar waktu tercetak di sertifikat
     */
    public static function pena(?string $isi, float $lebarCetakMm): ?string
    {
        if (! filled($isi) || $lebarCetakMm <= 0) {
            return $isi;
        }

        $sumber = @imagecreatefromstring($isi);

        if ($sumber === false) {
            return $isi;
        }

        try {
            return self::olah($sumber, $lebarCetakMm) ?? $isi;
        } finally {
            imagedestroy($sumber);
        }
    }

    private static function olah(\GdImage $sumber, float $lebarCetakMm): ?string
    {
        $lebar = (int) round($lebarCetakMm / 25.4 * self::DPI);

        if ($lebar < 8) {
            return null;
        }

        $tinggi = (int) max(1, round($lebar * imagesy($sumber) / imagesx($sumber)));
        $kerja = imagecreatetruecolor($lebar, $tinggi);

        try {
            // Alpha DIPERTAHANKAN, bukan dicampur ke latar. Tanpa dua baris ini
            // tanda tangan mendarat sebagai kotak hitam pekat yang menutupi
            // garis dan nama di bawahnya.
            imagealphablending($kerja, false);
            imagesavealpha($kerja, true);
            imagefill($kerja, 0, 0, imagecolorallocatealpha($kerja, 0, 0, 0, 127));
            imagecopyresampled($kerja, $sumber, 0, 0, 0, 0, $lebar, $tinggi, imagesx($sumber), imagesy($sumber));

            $tinta = self::bacaTinta($kerja, $lebar, $tinggi);

            if (self::sampaiBobot($tinta, $lebar, $tinggi) > 0) {
                self::tulisTinta($kerja, $lebar, $tinggi, $tinta);
            }

            ob_start();
            imagepng($kerja);

            return (string) ob_get_clean();
        } finally {
            imagedestroy($kerja);
        }
    }

    /**
     * Kepekatan tiap piksel, 0 (bening) sampai 127 (pekat penuh).
     *
     * Dibaca sekali ke array datar lalu diolah di situ. Bolak-balik lewat
     * `imagecolorat`/`imagesetpixel` tiap lintasan penapis berarti jutaan
     * panggilan fungsi PHP, dan itu yang bikin lambat — bukan aritmetikanya.
     *
     * Disimpan sebagai STRING biner satu bita per piksel, bukan array PHP.
     * Array integer 300 ribu elemen memakan puluhan megabita di PHP, dan tiap
     * lintasan penapis bikin dua salinan lagi — di kotak 512 MB yang dipakai
     * bareng dompdf itu selisih yang menentukan.
     */
    private static function bacaTinta(\GdImage $img, int $lebar, int $tinggi): string
    {
        $tinta = '';

        for ($y = 0; $y < $tinggi; $y++) {
            for ($x = 0; $x < $lebar; $x++) {
                // GD menyimpan 0 = pekat, 127 = bening. Dibalik supaya "lebih
                // besar = lebih bertinta", biar penapis maksimumnya kebaca.
                $tinta .= chr(127 - ((imagecolorat($img, $x, $y) >> 24) & 0x7F));
            }
        }

        return $tinta;
    }

    private static function tulisTinta(\GdImage $img, int $lebar, int $tinggi, string $tinta): void
    {
        // Warnanya diambil dari piksel paling bertinta, BUKAN dipatok hitam.
        // Tanda tangan biru (pena biru itu wajar di dokumen lab) nggak boleh
        // berubah jadi hitam cuma gara-gara lewat sini.
        $warna = self::warnaTinta($img, $lebar, $tinggi, $tinta);

        for ($y = 0; $y < $tinggi; $y++) {
            for ($x = 0; $x < $lebar; $x++) {
                $a = 127 - ord($tinta[$y * $lebar + $x]);
                imagesetpixel($img, $x, $y, ($a << 24) | $warna);
            }
        }
    }

    /** Warna RGB tinta, dirata-rata dari piksel yang paling pekat. */
    private static function warnaTinta(\GdImage $img, int $lebar, int $tinggi, string $tinta): int
    {
        $r = $g = $b = $n = 0;

        for ($y = 0; $y < $tinggi; $y += 2) {
            for ($x = 0; $x < $lebar; $x += 2) {
                if (ord($tinta[$y * $lebar + $x]) < 120) {
                    continue;
                }

                $c = imagecolorat($img, $x, $y);
                $r += ($c >> 16) & 0xFF;
                $g += ($c >> 8) & 0xFF;
                $b += $c & 0xFF;
                $n++;
            }
        }

        if ($n === 0) {
            return 0;
        }

        return (intdiv($r, $n) << 16) | (intdiv($g, $n) << 8) | intdiv($b, $n);
    }

    /**
     * Lebarkan selangkah demi selangkah sampai goresannya mencapai bobot pena.
     *
     * Diukur ulang tiap putaran, bukan dihitung sekali di depan. Percobaan
     * pertama memang menghitung jari-jarinya langsung dari selisih tebal
     * ("kurang 3,4 px, berarti jari-jari 2") — dan hasilnya **0,508 mm**, 45%
     * lewat dari target 0,35 mm.
     *
     * Sebabnya penapis kotak melebarkan goresan MIRING lebih dari dua kali
     * jari-jarinya: goresan diagonal yang dipotong mendatar tumbuh sepanjang
     * diagonal elemen penstrukturnya, bukan sepanjang sisinya. Selisihnya
     * bergantung sudut goresan, jadi tidak ada satu angka pengali yang benar
     * untuk semua tanda tangan.
     *
     * Mengukur ulang tiap putaran menghilangkan tebakan itu sama sekali:
     * berhenti waktu benar-benar sampai, bukan waktu rumusnya bilang sampai.
     *
     * @return int berapa putaran yang dijalankan; 0 artinya sudah cukup tebal
     */
    private static function sampaiBobot(string &$tinta, int $lebar, int $tinggi): int
    {
        $target = self::BOBOT_MM / 25.4 * self::DPI;

        for ($putaran = 0; $putaran < self::MAKS_PUTARAN; $putaran++) {
            $tebal = self::tebalGoresan($tinta, $lebar, $tinggi);

            // Tidak ada goresan yang bisa diukur (gambar kosong, atau semuanya
            // bidang lebar): tidak ada yang perlu ditebalkan.
            if ($tebal === null || $tebal >= $target) {
                return $putaran;
            }

            $sebelum = $tinta;
            $tinta = self::lebarkan($tinta, $lebar, $tinggi, 1);
            $sesudah = self::tebalGoresan($tinta, $lebar, $tinggi);

            // Putaran ini melewati target — dan MELEWATI lebih jauh daripada
            // kurangnya sebelum ini. Dikembalikan.
            //
            // Tanpa perbandingan ini, "berhenti begitu sampai" selalu berhenti
            // di sisi yang kelewat: satu putaran menambah ~0,10 mm, jadi
            // targetnya nyaris tidak pernah kena persis dan yang dipilih selalu
            // yang lebih tebal.
            if ($sesudah !== null && $sesudah > $target && ($sesudah - $target) > ($target - $tebal)) {
                $tinta = $sebelum;

                return $putaran;
            }
        }

        return self::MAKS_PUTARAN;
    }

    /**
     * Tebal goresan, dalam piksel: median panjang deretan tinta mendatar.
     *
     * Median, bukan rata-rata. Satu garis pemindaian yang kebetulan menyusuri
     * goresan mendatar menghasilkan deretan ratusan piksel, dan rata-rata
     * langsung tertarik ke situ — lalu penebalannya dianggap tidak perlu
     * padahal perlu.
     */
    private static function tebalGoresan(string $tinta, int $lebar, int $tinggi): ?float
    {
        $deret = [];
        $langkah = (int) max(1, intdiv($tinggi, 120));

        for ($y = 0; $y < $tinggi; $y += $langkah) {
            $baris = $y * $lebar;
            $panjang = 0;

            for ($x = 0; $x < $lebar; $x++) {
                if (ord($tinta[$baris + $x]) > self::AMBANG_TINTA) {
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

        // Deretan sepanjang seperempat gambar itu goresan mendatar yang
        // disusuri membujur, bukan tebal pena. Dibuang supaya mediannya
        // menggambarkan goresan yang dipotong tegak lurus.
        $batas = max(4, intdiv($lebar, 4));
        $deret = array_values(array_filter($deret, fn (int $p): bool => $p <= $batas));

        if ($deret === []) {
            return null;
        }

        sort($deret);

        return (float) $deret[intdiv(count($deret), 2)];
    }

    /**
     * Penapis maksimum, dipisah jadi dua lintasan.
     *
     * Bentuk kotak dua lintasan (mendatar lalu membujur) memberi hasil yang
     * sama dengan penapis kotak sekali jalan, tapi biayanya O(w*h*r) bukan
     * O(w*h*r^2). Di jari-jari 4 itu selisih delapan kali lipat, dan di kotak
     * 0,1 CPU selisih itu yang menentukan render sertifikatnya kerasa atau
     * tidak.
     */
    private static function lebarkan(string $tinta, int $lebar, int $tinggi, int $jariJari): string
    {
        $mendatar = str_repeat("\0", $lebar * $tinggi);

        for ($y = 0; $y < $tinggi; $y++) {
            $baris = $y * $lebar;

            for ($x = 0; $x < $lebar; $x++) {
                $maks = 0;
                $dari = max(0, $x - $jariJari);
                $sampai = min($lebar - 1, $x + $jariJari);

                for ($i = $dari; $i <= $sampai; $i++) {
                    $v = ord($tinta[$baris + $i]);

                    if ($v > $maks) {
                        $maks = $v;
                    }
                }

                $mendatar[$baris + $x] = chr($maks);
            }
        }

        $hasil = str_repeat("\0", $lebar * $tinggi);

        for ($y = 0; $y < $tinggi; $y++) {
            $dari = max(0, $y - $jariJari);
            $sampai = min($tinggi - 1, $y + $jariJari);

            for ($x = 0; $x < $lebar; $x++) {
                $maks = 0;

                for ($i = $dari; $i <= $sampai; $i++) {
                    $v = ord($mendatar[$i * $lebar + $x]);

                    if ($v > $maks) {
                        $maks = $v;
                    }
                }

                $hasil[$y * $lebar + $x] = chr($maks);
            }
        }

        return $hasil;
    }
}
