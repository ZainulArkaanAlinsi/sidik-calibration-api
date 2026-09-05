<?php

namespace App\Console\Commands;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Ocr\LetakLabelLembar;
use App\Services\Ocr\TataLetakLembar;
use App\Services\Ocr\TemplateLembarKerja;
use App\Services\QrCodeGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Cetak lembar kerja SIAP PINDAI dari berkas geometri.
 *
 * ## Ini membalik arah kerja fitur OCR
 *
 * Rencana awalnya (`BuatRangkaGeometriOcr` + SPEC §10 tahap 2): cetak formulir
 * dulu, ukur koordinat tiap selnya dari kertas, isi ke JSON, adu ke ≥20 foto
 * nyata, baru `terverifikasi: true`. Buat pH aja itu 60 sel; lab ini bakal punya
 * 48 jenis alat, dan tiap revisi formulir ngulang semuanya dari nol.
 *
 * Yang lebih penting: hasil ukurannya tetap PERKIRAAN. Meleset 2 mm nggak
 * kelihatan waktu diperiksa, tapi cukup buat mindahin potongan sel ke angka
 * tetangganya — kegagalan paling mahal di fitur ini, dan yang paling nggak
 * bergejala.
 *
 * Perintah ini kebalikannya: **koordinatnya jadi sumber, kertasnya yang
 * ngikut.** Tiap kotak digambar persis di `x/y/w/h` yang tertulis di berkas
 * geometri, jadi geometrinya eksak menurut definisi. Yang tersisa buat lab cuma
 * satu: mencetak berkas ini dan memakainya, bukan formulir lama.
 *
 * ## Yang TIDAK dilakukan perintah ini
 *
 * Nggak nyetel `terverifikasi: true`. Koordinatnya emang eksak di ruang PDF,
 * tapi rantainya belum kebukti ujung-ke-ujung: kamera, warp, dan potong-selnya
 * belum pernah diadu ke lembar cetak beneran. Yang nyetel flag itu orang, sesudah
 * foto nyata dibaca dengan benar — sama seperti sebelumnya.
 */
class CetakLembarKerjaOcr extends Command
{
    protected $signature = 'ocr:cetak-lembar
        {kode : kode profil alat, misal ph_meter}
        {--versi=1 : versi FORMULIR CETAK}
        {--keluar= : path file PDF hasilnya}
        {--html= : simpan juga HTML mentahnya — buat bikin aset uji & ngintip tata letak}';

    protected $description = 'Cetak lembar kerja bermarker dari berkas geometri OCR (koordinatnya jadi eksak)';

    public function __construct(
        private readonly TataLetakLembar $tataLetak,
        private readonly LetakLabelLembar $letakLabel,
    ) {
        parent::__construct();
    }

    public function handle(
        QrCodeGenerator $qr,
        TemplateLembarKerja $template,
        CalibrationProfileRegistry $registry,
    ): int {
        $kode = (string) $this->argument('kode');
        $versi = (int) $this->option('versi');

        $path = database_path("ocr-templates/{$kode}-v{$versi}.json");

        if (! File::exists($path)) {
            $this->error("Berkas geometri nggak ketemu: {$path}");
            $this->line("Bikin rangkanya dulu: php artisan ocr:rangka-geometri {$kode}");

            return self::FAILURE;
        }

        /** @var array<string, mixed> $geometri */
        $geometri = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $lebar = (int) ($geometri['ukuran_referensi']['w'] ?? 1654);
        $tinggi = (int) ($geometri['ukuran_referensi']['h'] ?? 2339);
        $dpi = (int) ($geometri['ukuran_referensi']['dpi'] ?? 200);

        // Piksel ruang template → milimeter halaman. Satu-satunya tempat
        // konversi ini terjadi; sisanya semua bicara piksel, sama seperti HP
        // sesudah warp.
        $mm = static fn (float $px): string => number_format($px / $dpi * 25.4, 3, '.', '');

        // Label baris & kolom diambil dari TEMPLATE, bukan dari berkas geometri:
        // yang tercetak di kertas harus sama persis dengan yang diharapkan
        // server waktu mencocokkan jangkar. Dua sumber = dua kesempatan geser.
        $dariTemplate = [];

        foreach ($template->untukKode($kode)['tabel'] ?? [] as $t) {
            // Lembar yang tulisan kertasnya beda dari titik yang dihitung
            // (Conductivity: kepala kolomnya `84 / 1413 / 5000 / 80000`, yang
            // dihitung `25 / 1412 / 111`) mencetak tulisan KERTASNYA. Yang
            // dicetak harus sama dengan yang dipegang teknisi, bukan dengan
            // yang dipakai mesin hitung.
            // Slot dan baris TIDAK satu lawan satu: satu slot bisa menaungi dua
            // baris (titik tengah Conductivity dikirim dua varian satuan buat
            // botol yang sama), dan satu slot bisa tidak menaungi baris mana
            // pun (`80000` — tercetak di kertas, tapi larutannya tidak ada).
            // Jadi dicocokkan lewat `titik_ukur`, bukan lewat urutan.
            $slot = array_values($t['slot_cetak'] ?? []);
            $labelBaris = [];
            $terpakai = [];

            foreach ($t['baris'] as $b) {
                $labelBaris[$b['baris_ke']] = $b['label'];

                foreach ($slot as $j => $s) {
                    if (! in_array((float) $b['titik_ukur'], array_map('floatval', $s['titik_ukur'] ?? []), true)) {
                        continue;
                    }

                    // Slot yang sama kena dua kali = dua varian satuan buat
                    // botol yang sama. Di kertas itu SATU kolom dengan kotak
                    // "ceklis salah satu"; di lembar cetak kotaknya nggak bisa
                    // dicentang, jadi dua-duanya digambar dan yang kedua pakai
                    // nama varian (`1.413 mS`). Tanpa ini dua kolom bersebelahan
                    // sama-sama berkepala `1413 µS` dan teknisi nggak punya cara
                    // tahu yang mana yang mana.
                    $labelBaris[$b['baris_ke']] = isset($terpakai[$j])
                        ? ($s['varian'] ?? $s['label'])
                        : $s['label'];

                    $terpakai[$j] = true;

                    break;
                }
            }

            $dariTemplate[$t['tabel_id']] = [
                'baris' => $labelBaris,
                'kolom' => array_column($t['kolom'], 'label', 'field_id'),
                'ke_bawah' => ($t['sumbu_pengulangan'] ?? 'kolom') === 'baris',
                // Penomoran pengulangan seperti tercetak di kertas ACUAN.
                // Kosong = pakai `X1`..`Xn` bawaan lembar cetak ini.
                'label_pengulangan' => $t['label_pengulangan'] ?? [],
            ];
        }

        $tabel = [];
        $kosong = [];

        foreach ($geometri['tabel'] ?? [] as $t) {
            $sel = $t['sel'] ?? [];

            if ($sel === []) {
                $kosong[] = $t['tabel_id'] ?? '?';

                continue;
            }

            // Margin kiri blok tabel ini. Ditulis di berkas geometri sama
            // perintah rangka — tabel yang berdampingan dalam satu pita punya
            // kolom labelnya sendiri-sendiri, dan cuma yang menghitung gridnya
            // yang tahu di mana blok kanan mulai. Lembar lama yang berkasnya
            // belum punya angka ini tetap memakai margin halaman.
            $kiriTabel = (int) ($t['kiri'] ?? $this->tataLetak->kiri($lebar));

            $tabel[] = [
                'judul' => $t['judul'] ?? '',
                // Judul tabel berdiri di margin kiri halaman, sejajar judul
                // lembar dan isian identitas. Dulu diturunkan dari sel paling
                // kiri (`- 300`), jadi letaknya ikut bergeser tiap lebar
                // gridnya berubah dan nggak pernah pas di margin.
                'judul_x' => $kiriTabel,
                // Judulnya duduk di baris paling atas kepala tabel, DI ATAS dua
                // baris label kolom. Dulu jaraknya cuma 60 px sementara label
                // fieldnya sendiri 40 px di atas sel: judul "Before adjustment
                // Reading" numpuk sama tulisan "Reading" di kolom pertama.
                'judul_y' => min(array_column($sel, 'y')) - $this->tataLetak->kepalaTabel($tinggi),
                'sel' => array_values($sel),
                // Di lembar yang Repeat-nya turun ke bawah, yang berdiri di
                // kiri justru nomor Repeat dan yang berdiri di atas justru
                // nama larutannya — kebalikan bentuk pH. Yang ditukar cuma
                // PERAN kedua label; kunci selnya sama sekali tidak berubah.
                'label_baris' => $this->labelBaris(
                    $sel,
                    $dariTemplate[$t['tabel_id']]['baris'] ?? [],
                    $dariTemplate[$t['tabel_id']]['ke_bawah'] ?? false,
                    $kiriTabel,
                    $this->tataLetak->jarakLabelBaris($lebar),
                    $dariTemplate[$t['tabel_id']]['label_pengulangan'] ?? [],
                ),
                'label_kolom' => $this->labelKolom(
                    $sel,
                    $dariTemplate[$t['tabel_id']]['kolom'] ?? [],
                    $dariTemplate[$t['tabel_id']]['baris'] ?? [],
                    $dariTemplate[$t['tabel_id']]['ke_bawah'] ?? false,
                    $this->tataLetak->jarakBarisKepala($tinggi),
                ),
            ];
        }

        if ($kosong !== []) {
            $this->error('Tabel ini belum punya koordinat sel: '.implode(', ', $kosong));
            $this->line('Isi dulu `sel` di '.$path.', atau jalanin `ocr:rangka-geometri` ulang.');

            return self::FAILURE;
        }

        $isiQr = (string) ($geometri['qr']['isi'] ?? "{$kode}|v{$versi}");

        $bentuk = $registry->untukKode($kode)?->bentukLembarKerja() ?? [];

        $kepala = $this->tataLetak->kepala(
            $bentuk,
            $lebar,
            $tinggi,
            (string) ($geometri['kode_dokumen'] ?? ''),
            $kode,
            $versi,
        );

        $bawahGrid = 0;

        foreach ($tabel as $t) {
            foreach ($t['sel'] as $kotak) {
                $bawahGrid = max($bawahGrid, (int) $kotak['y'] + (int) $kotak['h']);
            }
        }

        $data = [
            'judul' => $kepala['judul'],
            'lebar' => $lebar,
            'tinggi' => $tinggi,
            'mm' => $mm,
            'marker' => $geometri['marker'] ?? [],
            'qr' => $geometri['qr'] ?? ['kotak' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0]],
            'qrGambar' => $qr->dataUri($isiQr),
            'kepala' => $kepala,
            'lingkungan' => $this->tataLetak->kondisiLingkungan($bentuk, $lebar, $tinggi),
            'catatan' => $this->tataLetak->catatan($bentuk, $lebar, $tinggi, $bawahGrid),
            'tabel' => $tabel,
        ];

        // HTML mentahnya disimpan kalau diminta. Ini yang dipakai bikin aset
        // uji: dirender browser pada ukuran referensi, hasilnya citra lembar
        // yang piksel-per-pikselnya sama dengan ruang yang dipakai HP sesudah
        // warp — jadi kotak sel & kotak jangkar bisa diadu ke tinta yang
        // beneran tercetak, bukan ke citra karangan.
        if ($this->option('html')) {
            File::ensureDirectoryExists(dirname((string) $this->option('html')));
            File::put((string) $this->option('html'), view('ocr.lembar-kerja', $data)->render());
            $this->line('  HTML: '.$this->option('html'));
        }

        $pdf = Pdf::loadView('ocr.lembar-kerja', $data)
            // Halaman dibikin sepersis ukuran referensinya, bukan `a4`.
            // 1654x2339 @200dpi itu 595,44x842,04 pt — A4 dompdf 595,28x841,89
            // pt, jadi lembarnya kelebihan 0,16 pt dan dompdf mendorong elemen
            // TERAKHIR ke halaman dua. Yang hilang dari halaman satu kemarin
            // satu sel: pojok kanan bawah tabel `sesudah_adjustment`.
            ->setPaper([0, 0, $lebar / $dpi * 72, $tinggi / $dpi * 72]);

        $keluar = (string) ($this->option('keluar')
            ?: storage_path("app/lembar-kerja-{$kode}-v{$versi}.pdf"));

        File::ensureDirectoryExists(dirname($keluar));
        File::put($keluar, $pdf->output());

        $jumlahSel = array_sum(array_map(static fn (array $t): int => count($t['sel']), $tabel));

        $this->info("Lembar kerja siap pindai: {$keluar}");
        $this->line("  {$jumlahSel} sel, ".count($tabel).' tabel, ukuran referensi '."{$lebar}x{$tinggi} @{$dpi}dpi");
        $this->newLine();
        $this->warn('Koordinatnya eksak, tapi `terverifikasi` SENGAJA belum disetel true.');
        $this->line('  Cetak berkas ini, foto pakai HP, dan pastikan angkanya mendarat di sel');
        $this->line('  yang benar dulu. Flag itu pernyataan bahwa rantainya kebukti — bukan');
        $this->line('  bahwa koordinatnya rapi.');

        return self::SUCCESS;
    }

    /**
     * Label di kiri tiap baris.
     *
     * LETAKNYA dari [LetakLabelLembar] — sama persis dengan yang ditulis ke
     * kotak jangkar di berkas geometri, karena dua-duanya manggil kode yang
     * sama. Dua tempat yang menghitung sendiri-sendiri berarti kotak yang
     * dipotong HP pelan-pelan meleset dari tulisan yang tercetak.
     *
     * Yang ditentukan di sini cuma TEKSNYA: lembar bentuk pH menulis nilai
     * standarnya (`4,00`), lembar yang Repeat-nya turun ke bawah menulis
     * `X1..X5` — dan yang kedua itulah yang dibaca HP sebagai jangkar.
     *
     * @param  array<string, array<string, mixed>>  $sel
     * @param  array<int, string>  $labelTemplate  nomor baris → teks yang tercetak di kertas
     * @param  bool  $keBawah  true = Repeat turun ke bawah (bentuk Conductivity)
     * @param  int  $kiri  margin kiri halaman
     * @param  int  $jarak  jarak label ke garis sel di kanannya
     * @return list<array<string, mixed>>
     */
    private function labelBaris(
        array $sel,
        array $labelTemplate,
        bool $keBawah = false,
        int $kiri = 90,
        int $jarak = 24,
        array $labelPengulangan = [],
    ): array {
        // Bagian ke-1 kunci = nomor baris titik, ke-2 = nomor Repeat. Yang mana
        // yang jadi baris di kertas tergantung orientasinya.
        $letak = $this->letakLabel->perBaris($sel, $keBawah ? 2 : 1, $kiri, $jarak);
        $label = [];

        foreach ($letak as $baris => $kotak) {
            $label[] = [
                // Di lembar ke-bawah yang kertas ACUANNYA bukan cetakan kita,
                // penomorannya ikut kertas itu — master Timbangan menomori
                // `1`..`10` polos. `X1` bawaan cuma dipakai kalau lembarnya
                // tidak menyebut apa-apa; dua kertas untuk satu tabel dengan
                // penomoran berbeda bikin teknisi menghitung baris sendiri.
                'teks' => $keBawah
                    ? (string) ($labelPengulangan[$baris] ?? 'X'.$baris)
                    : (string) ($labelTemplate[$baris] ?? $baris),
                'x' => $kotak['x'],
                'y' => $kotak['y'],
                'w' => $kotak['w'],
                // Di lembar yang Repeat-nya turun ke bawah, label kiri INI
                // yang jadi jangkar — dan jangkar dicetak lebih besar supaya
                // kebaca mesin. Lihat `.label-jangkar` di tampilannya.
                'jangkar' => $keBawah,
            ];
        }

        return $label;
    }

    /**
     * Label yang memayungi tiap kelompok kolom.
     *
     * Sama seperti [labelBaris]: letaknya dari [LetakLabelLembar], teksnya
     * ditentukan di sini. Bentuk pH menulis `X1..X5` di atas tiap kelompok
     * Repeat; bentuk Conductivity menulis NAMA LARUTAN seperti tercetak di
     * kertas (`1413 µS`).
     *
     * @param  array<string, array<string, mixed>>  $sel
     * @param  array<string, string>  $labelTemplate  field_id → label kolom
     * @param  array<int, string>  $labelBaris  nomor baris → teks yang tercetak di kertas
     * @param  int  $jarakBaris  tinggi satu baris kepala tabel
     * @return list<array<string, mixed>>
     */
    private function labelKolom(
        array $sel,
        array $labelTemplate,
        array $labelBaris = [],
        bool $keBawah = false,
        int $jarakBaris = 45,
    ): array {
        // Yang memayungi kolom: nomor Repeat di bentuk pH, nomor baris titik di
        // bentuk Conductivity.
        $indeks = $keBawah ? 1 : 2;
        $letak = $this->letakLabel->perKelompokKolom($sel, $indeks, $jarakBaris);

        // Nama field (pH / °C) cuma dicetak kalau satu kelompok emang punya
        // lebih dari satu kolom; kalau cuma satu, `X{n}` udah cukup.
        $fieldPerKelompok = [];

        foreach ($sel as $kunci => $kotak) {
            $bagian = explode('|', (string) $kunci);

            if (count($bagian) !== 4) {
                continue;
            }

            $fieldPerKelompok[(int) $bagian[$indeks]][$bagian[3]] = $kotak;
        }

        $label = [];

        foreach ($letak as $nomor => $kotak) {
            $field = $fieldPerKelompok[$nomor] ?? [];

            // Satu `X{n}` memayungi seluruh kolom repeat itu — sel pH & °C di
            // bawahnya berbagi satu nomor, persis lembar cetaknya.
            $label[] = [
                'teks' => $keBawah
                    ? (string) ($labelBaris[$nomor] ?? $nomor)
                    : 'X'.$nomor,
                'x' => $kotak['x'],
                'y' => $kotak['y'],
                'w' => $kotak['w'],
                // Kebalikan `labelBaris`: di lembar bentuk pH yang jadi
                // jangkar justru label kepala kolom ini.
                'jangkar' => ! $keBawah,
            ];

            if (count($field) < 2) {
                continue;
            }

            // Sebaris DI BAWAH label kelompoknya, dan letaknya diturunkan dari
            // label itu — bukan dari kotak sel yang kesimpan di
            // `$fieldPerKelompok`. Yang kesimpan di situ sel TERAKHIR yang
            // kebaca per field, dan di lembar yang Repeat-nya turun ke bawah
            // itu barisnya X5: `Reading` & `°C` kecetak di tengah grid, di
            // dalam kotak yang mestinya diisi teknisi.
            foreach ($field as $namaField => $kotakField) {
                $label[] = [
                    'teks' => (string) ($labelTemplate[$namaField] ?? $namaField),
                    'x' => $kotakField['x'],
                    'y' => $kotak['y'] + $jarakBaris,
                    'w' => $kotakField['w'],
                ];
            }
        }

        return $label;
    }
}
