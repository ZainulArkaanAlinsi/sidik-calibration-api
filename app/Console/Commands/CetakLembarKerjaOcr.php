<?php

namespace App\Console\Commands;

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
        {--keluar= : path file PDF hasilnya}';

    protected $description = 'Cetak lembar kerja bermarker dari berkas geometri OCR (koordinatnya jadi eksak)';

    public function handle(QrCodeGenerator $qr, TemplateLembarKerja $template): int
    {
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
            $dariTemplate[$t['tabel_id']] = [
                'baris' => array_column($t['baris'], 'label', 'baris_ke'),
                'kolom' => array_column($t['kolom'], 'label', 'field_id'),
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

            $tabel[] = [
                'judul' => $t['judul'] ?? '',
                'judul_x' => min(array_column($sel, 'x')) - 300,
                'judul_y' => min(array_column($sel, 'y')) - 60,
                'sel' => array_values($sel),
                'label_baris' => $this->labelBaris($sel, $dariTemplate[$t['tabel_id']]['baris'] ?? []),
                'label_kolom' => $this->labelKolom($sel, $dariTemplate[$t['tabel_id']]['kolom'] ?? []),
            ];
        }

        if ($kosong !== []) {
            $this->error('Tabel ini belum punya koordinat sel: '.implode(', ', $kosong));
            $this->line('Isi dulu `sel` di '.$path.', atau jalanin `ocr:rangka-geometri` ulang.');

            return self::FAILURE;
        }

        $isiQr = (string) ($geometri['qr']['isi'] ?? "{$kode}|v{$versi}");

        $pdf = Pdf::loadView('ocr.lembar-kerja', [
            'judul' => $geometri['judul'] ?? $kode,
            'kodeDokumen' => $geometri['kode_dokumen'] ?? '',
            'templateId' => $kode,
            'versi' => $versi,
            'lebar' => $lebar,
            'tinggi' => $tinggi,
            'mm' => $mm,
            'marker' => $geometri['marker'] ?? [],
            'qr' => $geometri['qr'] ?? ['kotak' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0]],
            'qrGambar' => $qr->dataUri($isiQr),
            'tabel' => $tabel,
        ])->setPaper('a4');

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
     * Label nilai standar di kiri tiap baris.
     *
     * Ini juga yang dibaca HP sebagai JANGKAR: kalau grid kegeser satu baris,
     * label yang kebaca nggak cocok sama yang diharapkan template — penjagaan
     * yang baca ISI, bukan cuma ngukur geometri.
     *
     * @param  array<string, mixed>  $tabel
     * @param  array<string, array<string, mixed>>  $sel
     * @return list<array<string, mixed>>
     */
    private function labelBaris(array $sel, array $labelTemplate): array
    {
        $perBaris = [];

        foreach ($sel as $kunci => $kotak) {
            $bagian = explode('|', (string) $kunci);
            if (count($bagian) !== 4) {
                continue;
            }

            $baris = (int) $bagian[1];

            if (! isset($perBaris[$baris]) || $kotak['x'] < $perBaris[$baris]['x']) {
                $perBaris[$baris] = $kotak;
            }
        }

        ksort($perBaris);
        $label = [];

        foreach ($perBaris as $baris => $kotak) {
            $label[] = [
                // Nilai standarnya (`4,00`), bukan "Baris 1". Ini yang dibaca
                // HP sebagai jangkar, jadi teksnya harus yang bisa diadu ke
                // template — nomor baris nggak bisa mbuktiin apa-apa.
                'teks' => (string) ($labelTemplate[$baris] ?? $baris),
                'x' => max(0, $kotak['x'] - 300),
                'y' => $kotak['y'] + $kotak['h'] / 3,
                'w' => 290,
            ];
        }

        return $label;
    }

    /**
     * Label kolom pengulangan (`Repeat 1` / `X1`) di atas tiap kolom.
     *
     * @param  array<string, mixed>  $tabel
     * @param  array<string, array<string, mixed>>  $sel
     * @return list<array<string, mixed>>
     */
    private function labelKolom(array $sel, array $labelTemplate): array
    {
        $perRepeat = [];

        foreach ($sel as $kunci => $kotak) {
            $bagian = explode('|', (string) $kunci);
            if (count($bagian) !== 4) {
                continue;
            }

            $repeat = (int) $bagian[2];
            $field = $bagian[3];

            $perRepeat[$repeat]['kiri'] = min($perRepeat[$repeat]['kiri'] ?? PHP_INT_MAX, $kotak['x']);
            $perRepeat[$repeat]['kanan'] = max($perRepeat[$repeat]['kanan'] ?? 0, $kotak['x'] + $kotak['w']);
            $perRepeat[$repeat]['atas'] = min($perRepeat[$repeat]['atas'] ?? PHP_INT_MAX, $kotak['y']);
            $perRepeat[$repeat]['field'][$field] = $kotak;
        }

        ksort($perRepeat);
        $label = [];

        foreach ($perRepeat as $repeat => $r) {
            // Satu `X{n}` memayungi seluruh kolom repeat itu — sel pH & °C di
            // bawahnya berbagi satu nomor, persis lembar cetaknya.
            $label[] = [
                'teks' => 'X'.$repeat,
                'x' => $r['kiri'],
                'y' => $r['atas'] - 80,
                'w' => $r['kanan'] - $r['kiri'],
            ];

            // Nama kolomnya (pH / °C) cuma dicetak kalau satu repeat emang
            // punya lebih dari satu kolom; kalau cuma satu, `X{n}` udah cukup.
            if (count($r['field']) < 2) {
                continue;
            }

            foreach ($r['field'] as $field => $kotak) {
                $label[] = [
                    'teks' => (string) ($labelTemplate[$field] ?? $field),
                    'x' => $kotak['x'],
                    'y' => $r['atas'] - 40,
                    'w' => $kotak['w'],
                ];
            }
        }

        return $label;
    }
}
