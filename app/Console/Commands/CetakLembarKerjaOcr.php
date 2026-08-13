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
                // Di lembar yang Repeat-nya turun ke bawah, yang berdiri di
                // kiri justru nomor Repeat dan yang berdiri di atas justru
                // nama larutannya — kebalikan bentuk pH. Yang ditukar cuma
                // PERAN kedua label; kunci selnya sama sekali tidak berubah.
                'label_baris' => $this->labelBaris(
                    $sel,
                    $dariTemplate[$t['tabel_id']]['baris'] ?? [],
                    $dariTemplate[$t['tabel_id']]['ke_bawah'] ?? false,
                ),
                'label_kolom' => $this->labelKolom(
                    $sel,
                    $dariTemplate[$t['tabel_id']]['kolom'] ?? [],
                    $dariTemplate[$t['tabel_id']]['baris'] ?? [],
                    $dariTemplate[$t['tabel_id']]['ke_bawah'] ?? false,
                ),
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
     * @param  array<string, array<string, mixed>>  $sel
     * @param  array<int, string>  $labelTemplate
     * @param  bool  $keBawah  lembar yang Repeat-nya turun: yang berdiri di
     *                         kiri nomor Repeat, bukan nilai standarnya
     * @return list<array<string, mixed>>
     */
    private function labelBaris(array $sel, array $labelTemplate, bool $keBawah = false): array
    {
        $perBaris = [];

        // Bagian ke-1 kunci = nomor baris titik, ke-2 = nomor Repeat. Yang mana
        // yang jadi baris di kertas tergantung orientasinya.
        $indeks = $keBawah ? 2 : 1;

        foreach ($sel as $kunci => $kotak) {
            $bagian = explode('|', (string) $kunci);
            if (count($bagian) !== 4) {
                continue;
            }

            $baris = (int) $bagian[$indeks];

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
                // Lembar Repeat-ke-bawah menulis `X1..X5` di kiri; yang lain
                // menulis nilai standarnya (`4,00`).
                'teks' => $keBawah
                    ? 'X'.$baris
                    : (string) ($labelTemplate[$baris] ?? $baris),
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
     * @param  array<string, array<string, mixed>>  $sel
     * @param  array<string, string>  $labelTemplate  dikunci NAMA FIELD
     *                                                (`pembacaan` / `suhu`),
     *                                                bukan nomor repeat
     * @param  array<int, string>  $labelBaris  nama larutan per nomor baris —
     *                                          kepala kolom lembar yang
     *                                          Repeat-nya turun ke bawah
     * @return list<array<string, mixed>>
     */
    private function labelKolom(
        array $sel,
        array $labelTemplate,
        array $labelBaris = [],
        bool $keBawah = false,
    ): array {
        $perRepeat = [];

        // Yang memayungi kolom: nomor Repeat di bentuk pH, nomor baris titik di
        // bentuk Conductivity.
        $indeks = $keBawah ? 1 : 2;

        foreach ($sel as $kunci => $kotak) {
            $bagian = explode('|', (string) $kunci);
            if (count($bagian) !== 4) {
                continue;
            }

            $repeat = (int) $bagian[$indeks];
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
                // Bentuk Conductivity: yang di atas kolom itu NAMA LARUTAN
                // seperti tercetak di kertas (`1413 µS`), bukan `X{n}`.
                'teks' => $keBawah
                    ? (string) ($labelBaris[$repeat] ?? $repeat)
                    : 'X'.$repeat,
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
