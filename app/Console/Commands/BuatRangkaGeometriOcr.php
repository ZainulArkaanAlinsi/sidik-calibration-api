<?php

namespace App\Console\Commands;

use App\Services\Calibration\CalibrationProfileRegistry;
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

    public function __construct(private readonly TataLetakLembar $tataLetak)
    {
        parent::__construct();
    }

    public function handle(CalibrationProfileRegistry $registry, TemplateLembarKerja $template): int
    {
        $kode = (string) $this->argument('kode');

        if ($registry->untukKode($kode) === null) {
            $this->error("Kode alat `{$kode}` nggak dikenal.");
            $this->line('Yang ada: '.implode(', ', array_column($template->daftar(), 'template_id')));

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
        $rangka = $this->rangka($kode, $versi, $definisi);

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
     * @return array<string, mixed>
     */
    private function rangka(string $kode, int $versi, array $definisi): array
    {
        $lebar = (int) $this->option('lebar');
        $tinggi = (int) $this->option('tinggi');

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
                'kotak' => ['x' => $lebar - 260, 'y' => 130, 'w' => 170, 'h' => 170],
            ],
            // 'kolom' = Repeat berjajar ke kanan (bentuk pH), 'baris' = Repeat
            // turun ke bawah (bentuk Conductivity). Dibaca dari profil alatnya,
            // bukan dipatok di sini — dulu selalu 'kolom', jadi lembar
            // Conductivity digambar terbalik dari kertasnya tanpa ada yang
            // ngasih tahu.
            'sumbu_pengulangan' => $this->sumbu($definisi),
            'jangkar' => $this->jangkar($definisi),
            'tabel' => $this->tabel($definisi, $lebar, $tinggi),
        ];
    }

    /**
     * Arah nomor Repeat di lembar cetak, diambil dari tabel PERTAMA.
     *
     * Satu lembar nggak pernah mencampur dua orientasi — kalau suatu saat ada
     * yang begitu, ini titik yang harus dilonggarin jadi per tabel.
     *
     * @param  array<string, mixed>  $definisi
     */
    private function sumbu(array $definisi): string
    {
        $sumbu = $definisi['tabel'][0]['sumbu_pengulangan'] ?? 'kolom';

        return in_array($sumbu, ['baris', 'kolom'], true) ? $sumbu : 'kolom';
    }

    /**
     * Label Repeat yang TERCETAK di kepala kolom — ikut dibaca OCR buat mastiin
     * kolomnya nggak kegeser.
     *
     * @param  array<string, mixed>  $definisi
     * @return list<array<string, mixed>>
     */
    private function jangkar(array $definisi): array
    {
        $tabel = $definisi['tabel'][0] ?? null;

        if ($tabel === null) {
            return [];
        }

        $hasil = [];

        foreach ($tabel['pengulangan'] as $repeat) {
            $hasil[] = [
                'field_id' => 'label_repeat',
                'repeat_no' => (int) $repeat,
                'teks' => (string) $repeat,
                'kotak' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0],
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $definisi
     * @return list<array<string, mixed>>
     */
    private function tabel(array $definisi, int $lebar, int $tinggi): array
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

        $jumlahTabel = max(1, count($definisi['tabel']));
        $kepalaTabel = $this->tataLetak->kepalaTabel($tinggi);
        $jarakTabel = $this->tataLetak->jarakTabel($tinggi);

        // Grid mengisi SELURUH ruang di bawah blok kepala sampai batas penanda
        // sudut bawah. Dulu jatahnya cuma 45% halaman paling bawah: separuh
        // atas kertas kosong melompong sementara selnya sendiri kekecilan, dan
        // di lembar Conductivity baris terakhirnya malah kedorong ke halaman
        // dua.
        $ruang = $this->tataLetak->bawahGrid($tinggi) - $this->tataLetak->atasGrid($tinggi)
            - $jumlahTabel * $kepalaTabel
            - ($jumlahTabel - 1) * $jarakTabel;

        // Tinggi selnya SATU angka buat semua tabel, dibagi menurut jumlah
        // baris. Tabel yang barisnya sedikit nggak dapat sel raksasa cuma
        // karena jatah tingginya dibagi rata per tabel.
        $tinggiSel = max(1, min(
            intdiv($ruang, max(1, array_sum($barisTabel))),
            $this->tataLetak->tinggiSelMaks($tinggi),
        ));

        $kursor = $this->tataLetak->atasGrid($tinggi);

        foreach ($definisi['tabel'] as $i => $tabel) {
            $sel = [];
            $jumlahBaris = $barisTabel[$i];

            $jumlahKolom = max(1, count($tabel['kolom']) * ($keBawah
                ? count($tabel['baris'])
                : count($tabel['pengulangan'])));

            // Kolom pertama di kertas dipakai label — titik ukur (4,00 / 7,00 /
            // 10,01) di bentuk pH, nomor Repeat di bentuk Conductivity — jadi
            // grid selnya mulai dari 25% lebar.
            $kiri = (int) ($lebar * 0.25);
            $lebarSel = intdiv((int) ($lebar * 0.68), $jumlahKolom);
            $atasTabel = $kursor + $kepalaTabel;
            $kursor = $atasTabel + $jumlahBaris * $tinggiSel + $jarakTabel;

            $kolomKe = 0;

            // Kuncinya TIDAK berubah bentuk: `tabel|baris_ke|repeat|field`
            // tetap sama apa pun orientasinya. Yang berubah cuma di kotak mana
            // kunci itu digambar — supaya sel yang dipotong HP tetap ketemu
            // kunci yang sama dengan yang dikirim layar.
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
                'sel' => $sel,
            ];
        }

        return $hasil;
    }
}
