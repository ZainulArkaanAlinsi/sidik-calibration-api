<?php

namespace App\Services\Ocr;

/**
 * Tata letak lembar kerja siap pindai — SATU sumber angka buat dua perintah.
 *
 * `ocr:rangka-geometri` yang menaruh kotak selnya, `ocr:cetak-lembar` yang
 * menggambar kepala lembar dan judul tabelnya. Dua-duanya harus sepakat soal
 * di mana blok kepala berhenti dan berapa tinggi kepala tiap tabel; kalau
 * angkanya ditulis dua kali, cukup satu yang diubah buat bikin judul tabel
 * menimpa barisnya sendiri — persis yang kejadian di lembar Conductivity.
 *
 * Semua konstanta di sini ditulis di ruang piksel 1654x2339 @200dpi (A4).
 *
 * ## Dua macam konstanta, dan cuma SATU yang diskalakan
 *
 * Yang menggambarkan MARGIN & JATAH RUANG halaman ([KIRI], [KANAN],
 * [Y_ISIAN], [JARAK_TABEL], …) diskalakan lewat [x]/[y] kalau ukuran
 * referensinya lain — kertas yang lebih lebar memang pantas dapat margin yang
 * lebih lebar.
 *
 * Yang menggambarkan TIPOGRAFI ([LEBAR_LABEL], [PITCH_MIN], [PITCH_MAKS],
 * [LEBAR_LABEL_BARIS], [TINGGI_SEL_MAKS], [SISA_BAWAH]) TIDAK. Semua lembar
 * dicetak di 200 dpi dengan font 8 pt yang sama, jadi satu label `Equipment
 * Name` makan lebar milimeter yang sama di kertas mana pun. Menskalakannya
 * ikut ukuran halaman itu salah dua arah sekaligus: di lembar lanskap
 * (2339x1654) kotak labelnya jadi 41 % lebih lebar dari tulisan yang mau
 * ditaruh — ruang tulis hilang percuma — sementara jarak antar barisnya
 * menyusut 29 % sampai barisnya saling menempel dan blok isiannya meluber
 * menimpa judul tabel di bawahnya.
 *
 * Buat lembar A4 potret faktor skalanya persis 1,0, jadi enam lembar yang udah
 * terverifikasi nggak bergeser sepiksel pun.
 */
class TataLetakLembar
{
    /** Ukuran referensi tempat semua konstanta di kelas ini ditulis. */
    private const LEBAR_ACUAN = 1654;

    private const TINGGI_ACUAN = 2339;

    /** Baris pertama blok kepala: judul lembar. */
    private const Y_JUDUL = 150;

    private const Y_DOKUMEN = 210;

    private const Y_GARIS = 255;

    private const Y_ISIAN = 300;

    /**
     * Jatah tinggi buat isian identitas — DIPATOK, bukan ikut jumlah field.
     *
     * Kalau blok kepala boleh melar ngikut jumlah isian, alat yang formulirnya
     * panjang (Spectrophotometer: 28 isian) bakal ngedorong gridnya turun
     * sampai tinggi selnya nggak bisa ditulisi. Yang mengalah jaraknya, bukan
     * gridnya: isian rapat masih kebaca, sel 3 mm nggak bisa diisi.
     *
     * Angkanya turun dari 480 waktu kondisi lingkungan pindah ke tabelnya
     * sendiri: empat sampai enam isian keluar dari daftar dua kolom ini, jadi
     * barisnya berkurang tiga (Spectrophotometer: 12 baris jadi 9) dan ruang
     * yang dibebaskan itu yang dipakai tabelnya. Batas bawah grid nggak
     * bergeser sepiksel pun — geometri selnya tetap sama.
     */
    private const TINGGI_ISIAN = 350;

    /** Baris pertama tabel kondisi lingkungan, tepat di bawah isian identitas. */
    private const Y_LINGKUNGAN = 670;

    /** Tinggi satu baris tabel kondisi lingkungan (~7 mm, cukup ditulisi tangan). */
    private const TINGGI_BARIS_LINGKUNGAN = 55;

    /** Dua baris: `First` & `End`. Semua lembar bentuknya begini. */
    private const TINGGI_LINGKUNGAN = 2 * self::TINGGI_BARIS_LINGKUNGAN;

    /** Kolom nama baris (`First` / `End`) di tabel kondisi lingkungan. */
    private const LEBAR_NAMA_LINGKUNGAN = 160;

    /**
     * Kolom judul yang memayungi dua barisnya.
     *
     * `Env. Condition` 8 pt makan ~170 px sebaris; 300 px nyisain napas kiri
     * kanan. Kekecilan sedikit aja tulisannya membungkus jadi dua baris dan
     * meluber keluar kotaknya — di dompdf luberannya ke KIRI, jadi judulnya
     * mendarat di luar halaman, bukan di dalam sel tetangganya.
     */
    private const LEBAR_JUDUL_LINGKUNGAN = 300;

    /** Bagian lebar kelompok yang jadi kotak satuan; sisanya kotak isi. */
    private const PERSEN_SATUAN_LINGKUNGAN = 38;

    /** Awalan label yang menandai isian kondisi lingkungan di profil alat. */
    private const AWALAN_LINGKUNGAN = 'Env. Condition';

    private const JARAK_KE_GRID = 40;

    private const PITCH_MAKS = 62;

    private const PITCH_MIN = 40;

    private const KIRI = 90;

    /**
     * Batas kanan SATU-SATUNYA: garis kop, isian identitas, dan grid sel
     * berhenti di angka yang sama.
     *
     * Dulu garis kop punya batasnya sendiri (1354) karena QR duduk di
     * bawahnya, jadi garisnya berhenti 2 cm sebelum isian di kanannya —
     * kelihatan seperti garis yang kurang panjang, bukan seperti pilihan.
     * Sekarang QR-nya yang naik ke atas garis, dan batas kanannya satu.
     */
    private const KANAN = 1534;

    private const LEBAR_LABEL = 340;

    /**
     * Sisa ruang tulis minimal per kolom isian (~34 mm @200dpi).
     *
     * Yang nentuin blok isian identitas boleh dipecah jadi tiga kolom atau
     * nggak. Isian terpanjang yang beneran ditulis tangan di situ nama alat
     * dan nomor seri — dua-duanya muat di 34 mm dengan tulisan normal.
     *
     * Lembar A4 potret cuma nyisain 94 px (12 mm) kalau dipecah tiga, jauh di
     * bawah ambang ini, jadi keenam lembar yang udah terverifikasi tetap dua
     * kolom. Lembar lanskap dapat 274 px (35 mm) dan lolos.
     */
    private const LEBAR_TULIS_MIN = 270;

    /**
     * Kolom label baris di kiri grid (`X1`, `10,01`, `1,33659`).
     *
     * Dulu grid mulai di 25% lebar kertas (413 px) dan labelnya digambar 300
     * px sebelum itu — jadi labelnya mulai di 113, bukan di margin 90, dan
     * sisanya 1 cm ruang kosong yang nggak dipakai siapa pun. Label
     * terpanjang di semua lembar `1,33659` (7 huruf ≈ 85 px), jadi 200 px
     * kelebihan cukup buat label yang lebih panjang tanpa nyuri lebar sel.
     */
    private const LEBAR_LABEL_BARIS = 200;

    /**
     * Jarak label baris ke garis grid di kanannya (~3 mm @200dpi).
     *
     * Ini yang dikurangi dari lebar labelnya, BUKAN `padding` di tampilan:
     * dompdf menghitung `width` sebagai kotak isi, jadi padding kanan cuma
     * menambah lebar total dan tulisannya tetap mentok di garis sel.
     */
    private const JARAK_LABEL_BARIS = 24;

    /** Jarak antar baris tulisan di kepala tabel: judul, kelompok, nama field. */
    private const JARAK_BARIS_KEPALA = 45;

    /** Tinggi kepala tiap tabel: judul + label kelompok + label field. */
    private const KEPALA_TABEL = 3 * self::JARAK_BARIS_KEPALA;

    /** Napas antar tabel, biar baris terakhir tabel atas nggak nempel judul di bawahnya. */
    private const JARAK_TABEL = 50;

    /**
     * Napas mendatar antar tabel yang digambar BERDAMPINGAN dalam satu pita.
     *
     * Kolom label tabel kanan berdiri persis sesudah sel terakhir tabel kiri.
     * Labelnya rata kanan jadi tulisannya sendiri nggak nempel, tapi tanpa
     * jarak ini kotak labelnya mulai di garis sel tetangganya — dan begitu ada
     * label yang lebih panjang dari jatahnya, tulisannya numpuk di garis itu.
     */
    private const JARAK_PITA = 40;

    /** Sisa ruang di bawah grid, buat penanda sudut bawah. */
    private const SISA_BAWAH = 190;

    /**
     * Batas tinggi satu sel (~19 mm).
     *
     * Tanpa batas, lembar yang barisnya cuma empat (Chlorine, Refractometer)
     * dapat sel setinggi 33 mm buat menampung satu angka — kelihatan seperti
     * formulir yang belum jadi, dan angka yang ditulis di tengah kotak sebesar
     * itu jauh dari label barisnya. Sisa ruangnya dibiarkan kosong di bawah.
     */
    private const TINGGI_SEL_MAKS = 150;

    /**
     * Baris paling atas yang boleh dipakai grid sel.
     *
     * Dihitung dari BAWAH tabel kondisi lingkungan, bukan dari bawah isian
     * identitas: tabel itu yang paling bawah di blok kepala. Angkanya tetap 820
     * seperti sebelum tabelnya ada, jadi berkas geometri yang udah tercetak
     * nggak berubah.
     */
    public function atasGrid(int $tinggi, int $bawahKepala = 0): int
    {
        // Lantai kedua: grid nggak boleh mulai di atas blok isian yang masih
        // menulis. Angka 820 di atas itu JATAH — benar selama blok isiannya
        // muat di jatah itu, dan diam-diam salah kalau nggak.
        //
        // Lembar Viscometer yang bikin ini ketahuan: 28 isian di dua kolom =
        // 14 baris, dan judul tabel `Before Adjustment` kecetak menimpa baris
        // isian terakhir. Nggak ada test yang kena — semua penjaga tata letak
        // ngukur SEL, sementara yang tabrakan label sama judul.
        //
        // Buat enam lembar yang udah terverifikasi blok isiannya berhenti jauh
        // di atas 820, jadi `max()` di sini balik ke angka yang sama persis.
        return max(
            $this->y(self::Y_LINGKUNGAN + self::TINGGI_LINGKUNGAN + self::JARAK_KE_GRID, $tinggi),
            $bawahKepala > 0 ? $bawahKepala + self::JARAK_KE_GRID : 0,
        );
    }

    /** Baris paling bawah yang boleh dipakai grid sel. */
    public function bawahGrid(int $tinggi): int
    {
        // FISIK: yang harus muat di situ penanda sudut (90 px + napas),
        // ukurannya sama di kertas mana pun. Diskalakan ikut tinggi halaman,
        // lembar lanskap cuma nyisain 134 px buat penanda yang butuh 135.
        return $tinggi - self::SISA_BAWAH;
    }

    public function kepalaTabel(int $tinggi): int
    {
        return self::KEPALA_TABEL;
    }

    /**
     * Jarak satu baris tulisan di kepala tabel.
     *
     * Dipakai buat menaruh label kelompok (`1413 µS`) dan nama field
     * (`Reading`) di atas gridnya. Angkanya diambil dari sini, bukan ditulis
     * ulang di perintah cetak: judul tabel duduk setinggi `kepalaTabel()`, dan
     * dua baris di bawahnya harus persis mengisi jarak itu.
     */
    public function jarakBarisKepala(int $tinggi): int
    {
        return self::JARAK_BARIS_KEPALA;
    }

    /** Margin kiri halaman — judul, isian, dan label baris berdiri di sini. */
    public function kiri(int $lebar): int
    {
        return $this->x(self::KIRI, $lebar);
    }

    /** Lebar kolom label di kiri grid. */
    public function lebarLabelBaris(int $lebar): int
    {
        return self::LEBAR_LABEL_BARIS;
    }

    /** Jarak label baris ke garis sel di kanannya. */
    public function jarakLabelBaris(int $lebar): int
    {
        return self::JARAK_LABEL_BARIS;
    }

    /** Kolom sel paling kiri: sesudah kolom label, bukan di 25% lebar kertas. */
    public function kiriGrid(int $lebar): int
    {
        return $this->kiri($lebar) + $this->lebarLabelBaris($lebar);
    }

    /** Batas kanan grid — sama dengan batas kanan isian identitas di atasnya. */
    public function kananGrid(int $lebar): int
    {
        return $this->x(self::KANAN, $lebar);
    }

    public function jarakTabel(int $tinggi): int
    {
        return $this->y(self::JARAK_TABEL, $tinggi);
    }

    /** Jarak mendatar antar tabel yang berbagi satu pita. */
    public function jarakPita(int $lebar): int
    {
        return $this->x(self::JARAK_PITA, $lebar);
    }

    public function tinggiSelMaks(int $tinggi): int
    {
        return self::TINGGI_SEL_MAKS;
    }

    /**
     * Blok kepala lembar: judul, baris dokumen, dan isian identitas bergaris.
     *
     * Isiannya diambil dari `bentukLembarKerja()` profil alatnya — sumber yang
     * sama dengan yang dipakai layar. Jadi yang tercetak di kertas nggak bisa
     * beda dari yang diminta aplikasi tanpa ada yang mengubah profilnya.
     *
     * @param  array<string, mixed>  $bentuk  hasil `CalibrationProfile::bentukLembarKerja()`
     * @return array<string, mixed>
     */
    public function kepala(
        array $bentuk,
        int $lebar,
        int $tinggi,
        string $kodeDokumen,
        string $templateId,
        int $versi,
    ): array {
        $isian = $this->isian($bentuk);

        $kiri = $this->kiri($lebar);
        $kanan = $this->x(self::KANAN, $lebar);

        // Lebar label FISIK: tulisannya 8 pt di 200 dpi, sama di kertas mana
        // pun. Dulu ini diskalakan ikut lebar halaman, dan di lembar lanskap
        // kotaknya melar 481 px buat tulisan yang butuh 340 — 18 mm ruang
        // tulis hilang tanpa ada yang memakainya.
        $lebarLabel = self::LEBAR_LABEL;

        $jarakKolom = $this->x(40, $lebar);

        // Berapa kolom: DUA di A4 potret, TIGA kalau kertasnya cukup lebar.
        //
        // Di potret tiga kolom nyisain 12 mm buat menulis — nggak cukup buat
        // nama alat atau nomor seri, dan itu yang bikin angkanya dipatok dua
        // sejak awal. Tapi patokan itu ikut kebawa ke lembar lanskap, yang
        // lebarnya 41 % lebih tapi TINGGINYA 29 % lebih pendek: dua kolom di
        // situ artinya 14 baris isian yang nggak muat, dan blok kepalanya
        // meluber menimpa judul tabel.
        //
        // Jadi yang dipatok bukan jumlah kolomnya, tapi SYARATNYA: sisa ruang
        // tulis minimal [LEBAR_TULIS_MIN]. Lembar potret nggak lolos syarat
        // itu dan tetap dua kolom, persis kayak sebelumnya.
        $jumlahKolom = 2;
        $lebarKolom = intdiv($kanan - $kiri - $jarakKolom, 2);

        $lebarKolomTiga = intdiv($kanan - $kiri - 2 * $jarakKolom, 3);

        if ($lebarKolomTiga - $lebarLabel - $this->x(20, $lebar) >= self::LEBAR_TULIS_MIN) {
            $jumlahKolom = 3;
            $lebarKolom = $lebarKolomTiga;
        }

        $jumlahBaris = (int) max(1, ceil(count($isian) / $jumlahKolom));

        // Pitch juga FISIK — jarak antar baris tulisan ditentukan tinggi
        // hurufnya, bukan tinggi kertasnya.
        $pitch = min(
            self::PITCH_MAKS,
            max(
                self::PITCH_MIN,
                intdiv($this->y(self::TINGGI_ISIAN, $tinggi), $jumlahBaris),
            ),
        );

        $yIsian = $this->y(self::Y_ISIAN, $tinggi);
        $kotak = [];

        foreach (array_values($isian) as $i => $label) {
            $kolomKe = intdiv($i, $jumlahBaris);
            $barisKe = $i % $jumlahBaris;

            $x = $kiri + $kolomKe * ($lebarKolom + $jarakKolom);
            $y = $yIsian + $barisKe * $pitch;

            $kotak[] = [
                'teks' => $label,
                'x' => $x,
                'y' => $y,
                'w' => $lebarLabel,
                // Garisnya berhenti sebelum kolom berikutnya, dan turun sedikit
                // dari label supaya tulisan tangan punya ruang di atasnya.
                'garis_x' => $x + $lebarLabel + $this->x(10, $lebar),
                'garis_y' => $y + $pitch - 16,
                'garis_w' => $lebarKolom - $lebarLabel - $this->x(20, $lebar),
            ];
        }

        $dokumen = array_filter([
            $kodeDokumen,
            $bentuk['kode_metode'] ?? null,
            $templateId.' v'.$versi,
        ]);

        return [
            'judul' => (string) ($bentuk['judul'] ?? $templateId),
            'judul_x' => $kiri,
            'judul_y' => $this->y(self::Y_JUDUL, $tinggi),
            'dokumen' => implode(' · ', $dokumen),
            'dokumen_y' => $this->y(self::Y_DOKUMEN, $tinggi),
            'garis_y' => $this->y(self::Y_GARIS, $tinggi),
            'garis_w' => $kanan - $kiri,
            'isian' => $kotak,
            // Baris paling bawah blok isian. Dipakai [atasGrid] biar grid
            // selnya nggak pernah mulai di atas isian yang masih menulis —
            // lihat penjelasan di method itu.
            'bawah' => $yIsian + $jumlahBaris * $pitch,
        ];
    }

    /**
     * Tabel kondisi lingkungan — MELINTANG, bukan empat garis berdiri sendiri.
     *
     * Di kertas lab bagian ini satu tabel kecil: satu kolom nama baris (`First`
     * / `End`), lalu tiap besaran dapat kotak isi + kotak satuan di kanannya.
     * Sebagai isian identitas biasa bentuknya berantakan: empat sampai enam
     * baris berlabel `Env. Condition — First (°C)` tersebar di dua kolom,
     * kadang kepisah kolom kiri dan kanan, dan pasangan awal–akhir yang
     * sebenarnya satu tabel jadi nggak kelihatan sepasang.
     *
     * Jumlah kolomnya ikut profil: Conductivity dua (°C, %RH),
     * Spectrophotometer tiga (jam, °C, %RH).
     *
     * @param  array<string, mixed>  $bentuk  hasil `CalibrationProfile::bentukLembarKerja()`
     * @return array{sel: list<array{x: int, y: int, w: int, h: int}>, teks: list<array{teks: string, x: int, y: int, w: int}>}|array{}
     */
    public function kondisiLingkungan(array $bentuk, int $lebar, int $tinggi): array
    {
        $baris = $this->barisLingkungan($bentuk);

        if ($baris === []) {
            return [];
        }

        // Semua satuan yang muncul, urut kemunculannya di profil: itu urutan
        // kolom di kertas (`Time | Temperature | Humidity`).
        $kolom = [];

        foreach ($baris as $satuan) {
            foreach (array_keys($satuan) as $s) {
                $kolom[$s] = true;
            }
        }

        $kolom = array_keys($kolom);

        $kiri = $this->kiri($lebar);
        $kanan = $this->x(self::KANAN, $lebar);
        $lebarNama = $this->x(self::LEBAR_NAMA_LINGKUNGAN, $lebar);
        $tinggiBaris = $this->y(self::TINGGI_BARIS_LINGKUNGAN, $tinggi);

        $lebarJudul = $this->x(self::LEBAR_JUDUL_LINGKUNGAN, $lebar);
        // Sisa pembagian ditelan kolom judul di kiri, jadi tepi kanan tabelnya
        // jatuh persis di batas kanan grid dan garis kop di atasnya.
        $lebarKelompok = intdiv($kanan - $kiri - $lebarNama - $lebarJudul, count($kolom));
        $lebarJudul = $kanan - $kiri - $lebarNama - count($kolom) * $lebarKelompok;
        $lebarSatuan = intdiv($lebarKelompok * self::PERSEN_SATUAN_LINGKUNGAN, 100);

        $atas = $this->y(self::Y_LINGKUNGAN, $tinggi);

        // Kotak judul membentang dua baris, seperti sel yang di-merge di
        // formulir aslinya. Tanpa itu `First` & `End` berdiri sendirian tanpa
        // ada yang menyebut ini kondisi lingkungan.
        $sel = [['x' => $kiri, 'y' => $atas, 'w' => $lebarJudul, 'h' => $tinggiBaris * count($baris)]];
        $teks = [$this->teksLingkungan(self::AWALAN_LINGKUNGAN, $kiri, $atas, $lebarJudul, $tinggiBaris * count($baris))];

        $y = $atas;

        foreach ($baris as $nama => $satuan) {
            $x = $kiri + $lebarJudul;

            $sel[] = ['x' => $x, 'y' => $y, 'w' => $lebarNama, 'h' => $tinggiBaris];
            $teks[] = $this->teksLingkungan((string) $nama, $x, $y, $lebarNama, $tinggiBaris);

            $x += $lebarNama;

            foreach ($kolom as $s) {
                // Kotak isi dikosongkan walaupun lembar ini nggak punya
                // besarannya: tabel yang selnya bolong di tengah kelihatan
                // seperti tabel yang salah cetak.
                $sel[] = ['x' => $x, 'y' => $y, 'w' => $lebarKelompok - $lebarSatuan, 'h' => $tinggiBaris];
                $sel[] = ['x' => $x + $lebarKelompok - $lebarSatuan, 'y' => $y, 'w' => $lebarSatuan, 'h' => $tinggiBaris];

                if (isset($satuan[$s])) {
                    $teks[] = $this->teksLingkungan(
                        $s,
                        $x + $lebarKelompok - $lebarSatuan,
                        $y,
                        $lebarSatuan,
                        $tinggiBaris,
                    );
                }

                $x += $lebarKelompok;
            }

            $y += $tinggiBaris;
        }

        return ['sel' => $sel, 'teks' => $teks];
    }

    /**
     * Isian kondisi lingkungan, dikelompokkan jadi baris tabel.
     *
     * Nama barisnya diambil dari belakang tanda pisah di labelnya
     * (`Env. Condition — First` jadi `First`), jadi yang menentukan isi tabel
     * tetap profil alatnya, bukan daftar kode yang ditulis ulang di sini.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, array<string, true>> nama baris → satuan yang dipunyainya
     */
    private function barisLingkungan(array $bentuk): array
    {
        $baris = [];

        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            if (($bagian['di_kertas'] ?? true) === false) {
                continue;
            }

            foreach ($bagian['field'] ?? [] as $field) {
                if (($field['di_kertas'] ?? true) === false) {
                    continue;
                }

                $label = trim((string) ($field['label'] ?? ''));

                if (! str_starts_with($label, self::AWALAN_LINGKUNGAN)) {
                    continue;
                }

                $nama = trim((string) (explode('—', $label)[1] ?? ''));
                $satuan = trim((string) ($field['satuan'] ?? ''));

                // Isian jam nggak punya satuan di profil — di kertas kolomnya
                // tetap butuh kepala, dan `jam` itu yang tercetak.
                if ($satuan === '' && ($field['tipe'] ?? null) === 'waktu') {
                    $satuan = 'jam';
                }

                if ($nama === '' || $satuan === '') {
                    continue;
                }

                $baris[$nama][$satuan] = true;
            }
        }

        return $baris;
    }

    /**
     * Satu tulisan di tengah selnya — mendatar dan tegak.
     *
     * @return array{teks: string, x: int, y: int, w: int}
     */
    private function teksLingkungan(string $teks, int $x, int $y, int $lebar, int $tinggi): array
    {
        return [
            'teks' => $teks,
            'x' => $x,
            'y' => (int) round($y + max(0, ($tinggi - LetakLabelLembar::TINGGI_TEKS) / 2)),
            'w' => $lebar,
        ];
    }

    /**
     * Kotak catatan di sisa ruang bawah grid.
     *
     * Lembar yang barisnya sedikit (Chlorine, Refractometer) menyisakan 5 cm
     * kosong di bawah gridnya sesudah tinggi selnya dibatasi. Ruang itu diisi
     * isian `teks_panjang` yang sengaja nggak muat di blok kepala — di kertas
     * catatan teknisi butuh beberapa baris, bukan satu garis pendek.
     *
     * Balikannya kosong kalau sisanya nggak cukup buat dua baris.
     *
     * @param  array<string, mixed>  $bentuk
     * @param  int  $bawahGrid  baris terbawah yang kepakai sel
     * @return array<string, mixed>
     */
    public function catatan(array $bentuk, int $lebar, int $tinggi, int $bawahGrid): array
    {
        $label = null;

        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                if (($field['tipe'] ?? null) === 'teks_panjang' && ($field['di_kertas'] ?? true) !== false) {
                    $label = trim((string) ($field['label'] ?? ''));
                }
            }
        }

        if ($label === null || $label === '') {
            return [];
        }

        $kiri = $this->kiri($lebar);
        $lebarGaris = $this->x(self::KANAN, $lebar) - $kiri;
        $pitch = $this->y(70, $tinggi);
        $atas = $bawahGrid + $this->y(70, $tinggi);
        $batas = $this->bawahGrid($tinggi);

        $garis = [];

        for ($y = $atas + $pitch; $y <= $batas; $y += $pitch) {
            $garis[] = ['x' => $kiri, 'y' => $y, 'w' => $lebarGaris];
        }

        if (count($garis) < 2) {
            return [];
        }

        return [
            'teks' => $label,
            'x' => $kiri,
            'y' => $atas,
            'garis' => $garis,
        ];
    }

    /**
     * Label isian yang layak ditulis tangan di kertas.
     *
     * Yang dibuang bukan selera: pemilih alat (`master_alat`) itu dropdown di
     * layar dan namanya udah dicetak lagi di `1. Name`; baris berulang
     * (`standar_dicek.*.dipakai`) itu tabel, bukan satu garis; `teks_panjang`
     * butuh kotak, dan kalau dikasih garis satu baris teknisi bakal nulis
     * separuh terus mentok.
     *
     * @param  array<string, mixed>  $bentuk
     * @return list<string> label siap cetak, sudah dibedakan kalau kembar
     */
    private function isian(array $bentuk): array
    {
        $hasil = [];

        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            if (($bagian['di_kertas'] ?? true) === false) {
                continue;
            }

            foreach ($bagian['field'] ?? [] as $field) {
                if (($field['di_kertas'] ?? true) === false) {
                    continue;
                }

                if (($field['hanya_admin'] ?? false) === true) {
                    continue;
                }

                if (($field['sumber'] ?? null) === 'master_alat') {
                    continue;
                }

                if (($field['tipe'] ?? null) === 'teks_panjang') {
                    continue;
                }

                $kode = (string) ($field['kode'] ?? '');

                if ($kode === '' || str_contains($kode, '*')) {
                    continue;
                }

                $label = trim((string) ($field['label'] ?? $kode));
                $satuan = trim((string) ($field['satuan'] ?? ''));

                // Kondisi lingkungan punya tabelnya sendiri di `kondisiLingkungan()`.
                // Sebagai garis satu-satu, empat isian ini berlabel sama persis
                // dua-dua (`Env. Condition — First` buat suhu DAN kelembaban)
                // dan yang membedakan cuma satuan dalam kurung — teknisi harus
                // membaca ujung barisnya buat tahu yang mana yang suhu.
                if (str_starts_with($label, self::AWALAN_LINGKUNGAN)) {
                    continue;
                }

                $hasil[] = [
                    'label' => $satuan === '' ? $label : $label.' ('.$satuan.')',
                    // Judul bagian bisa sepanjang `EQUIPMENT IDENTITY AND
                    // CUSTOMER DATA`; yang dipakai kata pertamanya saja, karena
                    // labelnya cuma dapat jatah satu baris.
                    'bagian' => (string) strtok(trim((string) ($bagian['judul'] ?? '')), ' '),
                ];
            }
        }

        return $this->bedakanYangKembar($hasil);
    }

    /**
     * Label yang muncul lebih dari sekali dikasih nama bagiannya.
     *
     * Di layar tiap isian berdiri di bawah judul bagiannya sendiri, jadi dua
     * `1. Name` — satu nama alat, satu nama pemilik — nggak pernah bingung. Di
     * kertas judul bagiannya nggak ikut tercetak, dan dua baris berlabel sama
     * persis artinya teknisi menebak yang mana yang mana.
     *
     * @param  list<array{label: string, bagian: string}>  $isian
     * @return list<string>
     */
    private function bedakanYangKembar(array $isian): array
    {
        $jumlah = array_count_values(array_column($isian, 'label'));

        return array_map(
            static fn (array $i): string => $jumlah[$i['label']] > 1 && $i['bagian'] !== ''
                ? $i['bagian'].' · '.$i['label']
                : $i['label'],
            $isian,
        );
    }

    private function x(int $nilai, int $lebar): int
    {
        return (int) round($nilai * $lebar / self::LEBAR_ACUAN);
    }

    private function y(int $nilai, int $tinggi): int
    {
        return (int) round($nilai * $tinggi / self::TINGGI_ACUAN);
    }
}
