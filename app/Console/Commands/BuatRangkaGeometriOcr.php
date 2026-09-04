<?php

namespace App\Console\Commands;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Ocr\LetakLabelLembar;
use App\Services\Ocr\TataLetakLembar;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Console\Command;

/**
 * Bikin RANGKA file geometri OCR buat satu jenis alat.
 *
 * ## Kenapa perlu
 *
 * Semua bagian template OCR lain diturunin otomatis dari profil alatnya
 * (`TemplateLembarKerja`). Satu-satunya yang nggak bisa ditebak dari kode:
 * koordinat tiap sel di lembar CETAK. Itu harus diukur dari formulir asli.
 *
 * Masalahnya jumlah selnya. Lembar pH aja 60 sel; lab ini bakal punya sampai 48
 * jenis alat. Ngetik 60+ objek JSON dengan kunci `tabel|baris|repeat|field`
 * pakai tangan itu bukan kerjaan teliti — itu kerjaan yang PASTI salah ketik,
 * dan salah ketik satu kunci artinya satu sel diam-diam nggak pernah kebaca.
 *
 * Jadi perintah ini yang nulis KUNCI & KERANGKANYA (dijamin cocok sama template
 * yang dipakai server), dan orang tinggal ngisi ANGKANYA sambil ngukur kertas.
 *
 * ## Yang dihasilkan BUKAN template siap pakai
 *
 * Kotak selnya diisi grid rata sebagai tebakan awal, dan `terverifikasi` dikunci
 * `false`. Selama masih `false`, `TemplateLembarKerja::kesiapan()` nolak semua
 * pindai buat alat itu. Itu disengaja: grid rata hampir pasti meleset dari
 * kertas aslinya, dan koordinat yang meleset dikit persis kegagalan yang paling
 * mahal di fitur ini — angka mendarat di baris sebelah tanpa gejala apa pun.
 *
 * Alur pakainya:
 *   1. `php artisan ocr:rangka-geometri ph_meter`
 *   2. Foto/scan formulir kosong yang asli, ukur koordinat tiap sel (piksel, di
 *      ukuran referensi yang tertulis di file).
 *   3. Betulin angkanya di file, adu ulang ke beberapa foto nyata.
 *   4. Baru setel `"terverifikasi": true`.
 */
class BuatRangkaGeometriOcr extends Command
{
    protected $signature = 'ocr:rangka-geometri
        {kode : kode profil alat, misal ph_meter}
        {--versi=1 : versi FORMULIR CETAK, bukan versi kode}
        {--lebar=1654 : lebar citra referensi (px)}
        {--tinggi=2339 : tinggi citra referensi (px)}
        {--timpa : timpa file yang udah ada}';

    protected $description = 'Bikin rangka file geometri OCR (koordinat sel) buat satu jenis alat';

    public function __construct(
        private readonly TataLetakLembar $tataLetak,
        private readonly LetakLabelLembar $letakLabel,
    ) {
        parent::__construct();
    }

    public function handle(CalibrationProfileRegistry $registry, TemplateLembarKerja $template): int
    {
        $kode = (string) $this->argument('kode');

        if ($registry->untukKode($kode) === null) {
            $this->error("Kode alat `{$kode}` nggak dikenal.");
            // `kodeTersedia()`, bukan `daftar()`: yang dibutuhkan cuma nama-namanya.
            // `daftar()` membangun dua puluh empat lembar lengkap berikut query
            // masternya, dan sekarang menuntut konteks organisasi yang perintah
            // CLI ini memang tidak punya.
            $this->line('Yang ada: '.implode(', ', $template->kodeTersedia()));

            return self::FAILURE;
        }

        $versi = (int) $this->option('versi');
        $folder = TemplateLembarKerja::folderTemplate();
        $berkas = $folder.'/'.$kode.'-v'.$versi.'.json';

        if (file_exists($berkas) && ! $this->option('timpa')) {
            // Nolak nimpa secara default: file yang udah ada mungkin hasil ukur
            // manual berjam-jam, dan grid rata bakal ngehapusnya tanpa jejak.
            $this->error("File {$berkas} udah ada. Pakai --timpa kalau emang mau ditimpa.");

            return self::FAILURE;
        }

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $definisi = $template->untukKode($kode);

        // Lembar bersumbu CAMPURAN ditolak sebelum satu koordinat pun ditulis —
        // lihat `sumbuCampuran()`.
        $campur = $this->sumbuCampuran($definisi);

        if ($campur !== []) {
            $this->error(
                "Lembar `{$kode}` mencampur dua orientasi tabel (".implode(' + ', $campur).').'
            );
            $this->line(
                'Berkas geometri di sini menurunkan tinggi sel, kotak jangkar, dan urutan kunci '
                .'sel SEKALI buat seluruh lembar, jadi yang terbit bakal bertentangan dengan '
                .'bentuk lembarnya sendiri — dan salahnya nggak kelihatan sampai ada yang '
                .'memindainya.'
            );
            $this->line(
                'Jalur kamera per-tabel (`FOTO TABEL INI`) nggak butuh berkas ini: dia '
                .'menjangkar ke tulisan yang tercetak, bukan ke koordinat.'
            );

            return self::FAILURE;
        }

        // Batas bawah blok isian identitas — dihitung dari bentuk lembar yang
        // SAMA dengan yang dipakai `ocr:cetak-lembar`, biar grid selnya nggak
        // pernah mulai di atas isian yang masih menulis. Lihat
        // `TataLetakLembar::atasGrid()`.
        $bentuk = $registry->untukKode($kode)?->bentukLembarKerja() ?? [];
        $rangka = $this->rangka($kode, $versi, $definisi, $bentuk);

        // Catatan yang ditulis tangan di berkas lama — misalnya kenapa cetakan
        // lab yang beredar TIDAK boleh dipakai sebagai acuan ukur — ikut
        // dibawa. Kalau nggak, satu `--timpa` menghapus keterangan yang nggak
        // ada di kode mana pun dan nggak ada yang sadar sampai keputusannya
        // diulang salah.
        if (file_exists($berkas)) {
            /** @var array<string, mixed> $lama */
            $lama = json_decode((string) file_get_contents($berkas), true) ?: [];

            if (isset($lama['_catatan_formulir'])) {
                $rangka = array_merge([
                    '_catatan' => $rangka['_catatan'],
                    '_catatan_formulir' => $lama['_catatan_formulir'],
                ], $rangka);
            }
        }

        file_put_contents(
            $berkas,
            json_encode($rangka, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->info("Rangka ditulis: {$berkas}");
        $this->line('Sel: '.count($definisi['sel']).' — semuanya masih koordinat TEBAKAN.');
        $this->warn('`terverifikasi` masih false, jadi alat ini belum bisa dipindai. '
            .'Ukur dari formulir cetak asli dulu, baru setel true.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $definisi
     * @param  array<string, mixed>  $bentuk  hasil `CalibrationProfile::bentukLembarKerja()`
     * @return array<string, mixed>
     */
    private function rangka(string $kode, int $versi, array $definisi, array $bentuk = []): array
    {
        $lebar = (int) $this->option('lebar');
        $tinggi = (int) $this->option('tinggi');

        $bawahKepala = $bentuk === [] ? 0 : (int) $this->tataLetak->kepala(
            $bentuk,
            $lebar,
            $tinggi,
            (string) ($definisi['kode_dokumen'] ?? ''),
            $kode,
            $versi,
        )['bawah'];

        $tabel = $this->tabel($definisi, $lebar, $tinggi, $bawahKepala);

        return [
            '_catatan' => 'RANGKA hasil `php artisan ocr:rangka-geometri`. Koordinatnya grid rata, '
                .'bukan hasil ukur. Ukur dari formulir cetak asli, adu ke foto nyata, baru setel '
                .'terverifikasi = true.',
            'template_id' => $kode,
            'versi' => $versi,
            'kode_dokumen' => $definisi['kode_dokumen'],
            'terverifikasi' => false,
            // Semua koordinat di file ini relatif ke ukuran INI. Foto dari HP
            // diperbaiki perspektifnya ke ukuran ini dulu, baru selnya dipotong.
            'ukuran_referensi' => ['w' => $lebar, 'h' => $tinggi, 'dpi' => 200],
            // Empat penanda sudut yang dipasang di formulir cetak. Titik acuan
            // homography — tanpa ini nggak ada yang bisa mastiin kertasnya rata.
            'marker' => [
                ['id' => 0, 'x' => 90, 'y' => 90, 'ukuran' => 90],
                ['id' => 1, 'x' => $lebar - 90, 'y' => 90, 'ukuran' => 90],
                ['id' => 2, 'x' => $lebar - 90, 'y' => $tinggi - 90, 'ukuran' => 90],
                ['id' => 3, 'x' => 90, 'y' => $tinggi - 90, 'ukuran' => 90],
            ],
            'qr' => [
                // Isi QR yang dicetak di lembar. Dicocokin sama `template_id` &
                // `versi` waktu pindai — QR beda = lembar beda, berhenti.
                'isi' => $kode.'|v'.$versi,
                // QR-nya NGGAK boleh nyentuh penanda sudut. Kotak lama
                // (`lebar - 260`, y 130) melar sampai `lebar - 90`, sementara
                // penanda kanan-atas mulai di `lebar - 135`: 45x5 px tinta QR
                // masuk ke dalam kotak penandanya. Deteksi sudut di HP cuma
                // ambang gelap + titik berat gumpalan, jadi tinta asing di
                // dalam penanda menggeser titik acuan homography-nya — seluruh
                // grid ikut geser, dan gejalanya cuma angka yang mendarat di
                // sel tetangga.
                //
                // Sekarang QR-nya naik ke sisi kanan blok judul: 40 px dari
                // penanda, dan berhenti di atas garis kop (y 255) supaya garis
                // itu bisa lurus sampai batas kanan halaman.
                'kotak' => ['x' => $lebar - 345, 'y' => 55, 'w' => 170, 'h' => 170],
            ],
            // 'kolom' = Repeat berjajar ke kanan (bentuk pH), 'baris' = Repeat
            // turun ke bawah (bentuk Conductivity). Dibaca dari profil alatnya,
            // bukan dipatok di sini — dulu selalu 'kolom', jadi lembar
            // Conductivity digambar terbalik dari kertasnya tanpa ada yang
            // ngasih tahu.
            'sumbu_pengulangan' => $this->sumbu($definisi),
            // Jangkar diturunkan dari kotak sel yang BARUSAN dihitung, bukan
            // dari definisi tabelnya: letak label cuma bisa ditentukan sesudah
            // gridnya ada.
            'jangkar' => $this->jangkar($tabel, $lebar, $tinggi, $this->sumbu($definisi) === 'baris'),
            'tabel' => $tabel,
        ];
    }

    /**
     * Arah nomor Repeat di lembar cetak, diambil dari tabel PERTAMA.
     *
     * Seluruh berkas ini menganggap satu lembar punya SATU orientasi: tinggi
     * sel, kotak jangkar, dan kunci selnya diturunkan sekali buat semua tabel.
     *
     * @param  array<string, mixed>  $definisi
     */
    private function sumbu(array $definisi): string
    {
        $sumbu = $definisi['tabel'][0]['sumbu_pengulangan'] ?? 'kolom';

        return in_array($sumbu, ['baris', 'kolom'], true) ? $sumbu : 'kolom';
    }

    /**
     * Lembar yang tabelnya BEDA-BEDA orientasi ditolak, bukan digambar
     * memakai orientasi tabel pertama.
     *
     * Lembar Timbangan yang pertama begitu: blok Accuracy berjajar ke kanan,
     * blok Repeatability turun ke bawah. Digambar apa adanya, yang terbit
     * kertas yang BERTENTANGAN dengan bentuk lembarnya sendiri — sepuluh
     * pengulangan keterulangan tercetak melintang padahal bentuknya menyatakan
     * menurun. Kertas begitu lebih berbahaya daripada tidak ada kertas: teknisi
     * mengisi kotak yang letaknya tidak sama dengan yang dipetakan HP, dan yang
     * keluar bukan error melainkan angka di baris yang salah.
     *
     * Ditolak di sini, bukan dibiarkan lolos dengan catatan, karena berkas
     * geometri yang salah tidak punya gejala sampai ada yang memindainya.
     *
     * Melonggarkannya jadi per tabel berarti menurunkan tinggi sel, kotak
     * jangkar, dan urutan kunci sel per tabel — bukan satu baris di sini.
     *
     * @param  array<string, mixed>  $definisi
     * @return list<string> sumbu unik yang ketemu, buat pesan errornya
     */
    private function sumbuCampuran(array $definisi): array
    {
        $sumbu = [];

        foreach ($definisi['tabel'] ?? [] as $t) {
            $s = $t['sumbu_pengulangan'] ?? 'kolom';
            $sumbu[in_array($s, ['baris', 'kolom'], true) ? $s : 'kolom'] = true;
        }

        return count($sumbu) > 1 ? array_keys($sumbu) : [];
    }

    /**
     * Label Repeat yang TERCETAK di lembar — ikut dibaca OCR buat mastiin
     * barisnya nggak kegeser.
     *
     * **Ini penjagaan yang paling menentukan.** Yang lain ngukur geometri:
     * marker ketemu, residual kecil, grid ke-snap. Yang ini BACA ISINYA —
     * kalau gridnya kegeser satu baris, label yang kebaca di posisi Repeat 2
     * bakal `X3`, dan seluruh lembar ditolak sebelum satu angka pun dipetakan.
     *
     * Kotaknya dihitung [LetakLabelLembar], kelas yang sama yang dipakai
     * `ocr:cetak-lembar` waktu menggambar labelnya. Dua tempat yang menghitung
     * sendiri-sendiri berarti kotak yang dipotong HP pelan-pelan meleset dari
     * tulisan yang tercetak — dan jangkarnya berubah dari penjaga jadi sumber
     * penolakan.
     *
     * Diambil dari tabel PERTAMA saja. Lembar yang punya dua/tiga tabel
     * (pH, Spectrophotometer) barisnya digambar dari grid yang sama, jadi
     * geseran yang kena tabel kedua kena tabel pertama juga.
     *
     * @param  list<array<string, mixed>>  $tabel  hasil [tabel()]
     * @return list<array<string, mixed>>
     */
    private function jangkar(array $tabel, int $lebar, int $tinggi, bool $keBawah): array
    {
        $sel = $tabel[0]['sel'] ?? [];

        if ($sel === []) {
            return [];
        }

        // Di lembar bentuk pH nomor Repeat berdiri DI ATAS kelompok kolomnya;
        // di lembar Conductivity dia turun ke kiri tiap baris. Yang dibaca HP
        // harus yang benar-benar tercetak, jadi sumbunya ikut lembarnya.
        $letak = $keBawah
            ? $this->letakLabel->perBaris(
                $sel,
                2,
                // Margin blok TABELNYA, bukan margin halaman: tabel yang
                // berbagi pita berdiri di kolom label masing-masing.
                (int) ($tabel[0]['kiri'] ?? $this->tataLetak->kiri($lebar)),
                $this->tataLetak->jarakLabelBaris($lebar),
            )
            : $this->letakLabel->perKelompokKolom(
                $sel,
                2,
                $this->tataLetak->jarakBarisKepala($tinggi),
            );

        $hasil = [];

        foreach ($letak as $repeat => $kotak) {
            $hasil[] = [
                'field_id' => 'label_repeat',
                'repeat_no' => $repeat,
                // Teksnya `X1`, bukan `1` — itu yang tercetak di kertas
                // (`CetakLembarKerjaOcr::labelBaris`/`labelKolom`). Nulis `1`
                // di sini bikin tiap lembar ditolak walau bacaannya benar.
                'teks' => 'X'.$repeat,
                'kotak' => $this->letakLabel->berNapas($kotak),
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $definisi
     * @return list<array<string, mixed>>
     */
    private function tabel(array $definisi, int $lebar, int $tinggi, int $bawahKepala = 0): array
    {
        $hasil = [];
        $keBawah = $this->sumbu($definisi) === 'baris';

        // Yang turun ke bawah itu SIAPA — di lembar pH barisnya titik ukur, di
        // lembar Conductivity barisnya nomor Repeat. Sisanya sama: satu kolom
        // per (yang melintang × field).
        $barisTabel = array_map(
            fn (array $tabel): int => max(1, count($keBawah ? $tabel['pengulangan'] : $tabel['baris'])),
            $definisi['tabel'],
        );

        // Yang menghabiskan tinggi halaman jumlah PITA-nya, bukan jumlah
        // tabelnya: tabel yang berbagi satu pita berdiri berdampingan, jadi
        // kepala tabel & jatah barisnya dihitung sekali buat pita itu.
        $pita = $this->pita($definisi);
        $jumlahPita = max(1, count($pita));

        $barisPita = array_map(
            static fn (array $isi): int => max(array_map(
                static fn (int $i): int => $barisTabel[$i],
                $isi,
            )),
            $pita,
        );

        $kepalaTabel = $this->tataLetak->kepalaTabel($tinggi);
        $jarakTabel = $this->tataLetak->jarakTabel($tinggi);

        // Grid mengisi SELURUH ruang di bawah blok kepala sampai batas penanda
        // sudut bawah. Dulu jatahnya cuma 45% halaman paling bawah: separuh
        // atas kertas kosong melompong sementara selnya sendiri kekecilan, dan
        // di lembar Conductivity baris terakhirnya malah kedorong ke halaman
        // dua.
        $ruang = $this->tataLetak->bawahGrid($tinggi) - $this->tataLetak->atasGrid($tinggi, $bawahKepala)
            - $jumlahPita * $kepalaTabel
            - ($jumlahPita - 1) * $jarakTabel;

        // Tinggi selnya SATU angka buat semua tabel, dibagi menurut jumlah
        // baris. Tabel yang barisnya sedikit nggak dapat sel raksasa cuma
        // karena jatah tingginya dibagi rata per tabel.
        $tinggiSel = max(1, min(
            intdiv($ruang, max(1, array_sum($barisPita))),
            $this->tataLetak->tinggiSelMaks($tinggi),
        ));

        $kursor = $this->tataLetak->atasGrid($tinggi, $bawahKepala);

        // Batas kiri & kanan SEMUA pita — sama dengan margin blok kepala di
        // atasnya. Dulu angkanya pecahan lebar kertas (25% mulai, 68% lebar).
        // Dua angka itu nggak nyambung ke margin mana pun: gridnya kebetulan
        // berhenti di 1533 sementara isian identitas di atasnya berhenti di
        // 1534, dan di lembar Spectrophotometer malah lewat jadi 1535 — tepi
        // kanan yang goyang 2 px per lembar.
        $kiriPita = $this->tataLetak->kiri($lebar);
        $kananPita = $this->tataLetak->kananGrid($lebar);
        $jarakPita = $this->tataLetak->jarakPita($lebar);

        foreach ($pita as $p => $isiPita) {
            $jumlahSlot = max(1, count($isiPita));
            $lebarSlot = intdiv($kananPita - $kiriPita - ($jumlahSlot - 1) * $jarakPita, $jumlahSlot);
            $atasTabel = $kursor + $kepalaTabel;

            foreach (array_values($isiPita) as $slot => $i) {
                $tabel = $definisi['tabel'][$i];
                $sel = [];

                $jumlahKolom = max(1, count($tabel['kolom']) * ($keBawah
                    ? count($tabel['baris'])
                    : count($tabel['pengulangan'])));

                $kiriSlot = $kiriPita + $slot * ($lebarSlot + $jarakPita);

                // Slot terakhir berhenti PERSIS di batas kanan, bukan di
                // kelipatan lebar slot: sisa pembagian lebar pita mesti
                // diserap di sini, kalau nggak tepi kanan grid meleset
                // beberapa piksel dari isian identitas di atasnya.
                $kanan = $slot === $jumlahSlot - 1 ? $kananPita : $kiriSlot + $lebarSlot;

                // Kolom pertama tiap tabel dipakai label — titik ukur (4,00 /
                // 7,00 / 10,01) di bentuk pH, nomor Repeat di bentuk
                // Conductivity — jadi grid selnya mulai sesudah kolom label.
                $lebarSel = intdiv(
                    $kanan - $kiriSlot - $this->tataLetak->lebarLabelBaris($lebar),
                    $jumlahKolom,
                );

                // Sisa pembagian ditaruh di KIRI, bukan dibiarkan menggantung
                // di kanan. Lebar selnya bilangan bulat, jadi 8 kolom nggak
                // pernah pas ngisi ruangnya: tepi kanan grid berhenti 4 px
                // sebelum tepi kanan isian identitas di atasnya — sedikit, tapi
                // di kertas itu dua garis vertikal yang jelas nggak sejajar.
                // Kolom labelnya yang menyerap sisanya, dan label baris tetap
                // digambar dari margin slotnya.
                $kiri = $kanan - $jumlahKolom * $lebarSel;

                $kolomKe = 0;

                // Kuncinya TIDAK berubah bentuk: `tabel|baris_ke|repeat|field`
                // tetap sama apa pun orientasinya. Yang berubah cuma di kotak
                // mana kunci itu digambar — supaya sel yang dipotong HP tetap
                // ketemu kunci yang sama dengan yang dikirim layar.
                $melintang = $keBawah ? $tabel['baris'] : $tabel['pengulangan'];
                $menurun = $keBawah ? $tabel['pengulangan'] : $tabel['baris'];

                foreach ($melintang as $luar) {
                    foreach ($tabel['kolom'] as $kolom) {
                        foreach ($menurun as $dalamKe => $dalam) {
                            $baris = $keBawah ? $luar : $dalam;
                            $repeat = $keBawah ? $dalam : $luar;

                            $kunci = $tabel['tabel_id'].'|'.$baris['baris_ke'].'|'
                                .(int) $repeat.'|'.$kolom['field_id'];

                            $sel[$kunci] = [
                                'x' => $kiri + $kolomKe * $lebarSel,
                                'y' => $atasTabel + $dalamKe * $tinggiSel,
                                'w' => $lebarSel,
                                'h' => $tinggiSel,
                            ];
                        }

                        $kolomKe++;
                    }
                }

                $hasil[] = [
                    'tabel_id' => $tabel['tabel_id'],
                    'judul' => $tabel['judul'],
                    // Margin kiri blok tabel INI: judul tabel & kolom labelnya
                    // berdiri di sini. Ikut ditulis ke berkas karena perintah
                    // cetak cuma pegang berkas ini — tanpa angkanya, judul
                    // tabel kanan mendarat di margin halaman, numpuk sama judul
                    // tabel kiri yang sepita dengannya.
                    'kiri' => $kiriSlot,
                    'sel' => $sel,
                ];
            }

            $kursor = $atasTabel + $barisPita[$p] * $tinggiSel + $jarakTabel;
        }

        return $hasil;
    }

    /**
     * Kelompok tabel yang digambar BERDAMPINGAN dalam satu pita mendatar.
     *
     * Bawaannya satu tabel satu pita — susunan lama, semua tabel bertumpuk ke
     * bawah. Yang butuh lain cuma lembar Spectrophotometer: 24 baris di tiga
     * tabel, dibagi rata satu halaman, jatuh ke sel setinggi 34 px (4,3 mm) —
     * di atas ambang mutu pindai, tapi nggak ada tulisan tangan yang muat di
     * situ. Dua tabel panjang gelombangnya masing-masing cuma butuh 3 kolom,
     * jadi separuh lebar kertasnya nganggur; pita menukar lebar nganggur itu
     * jadi tinggi sel.
     *
     * Nomornya dari profil alatnya (`pita_cetak`), bukan ditebak di sini:
     * tabel mana yang muat berdampingan itu keputusan bentuk lembarnya.
     *
     * @param  array<string, mixed>  $definisi
     * @return list<list<int>>
     */
    private function pita(array $definisi): array
    {
        $pita = [];

        foreach ($definisi['tabel'] as $i => $tabel) {
            $nomor = (int) ($tabel['pita_cetak'] ?? 0);
            $pita[$nomor > 0 ? 'pita-'.$nomor : 'tabel-'.$i][] = $i;
        }

        return array_values($pita);
    }
}
