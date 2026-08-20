<?php

namespace App\Console\Commands;

use App\Models\WorksheetScan;
use App\Models\WorksheetScanCell;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Buang citra hasil pindai lembar kerja yang umurnya lewat batas.
 *
 * ## Kenapa perlu ada perintahnya
 *
 * `config/ocr.php` sudah menuliskan dua jendela retensi sejak awal
 * (`umur_citra_hari` 90, `umur_crop_hari` 365) — tapi sampai sekarang **tidak
 * ada satu baris kode pun yang membacanya**. Angka itu jadi janji tertulis yang
 * tidak pernah ditepati: foto lembar kerja pelanggan menumpuk di server tanpa
 * batas, dan yang membaca `config/ocr.php` percaya sebaliknya.
 *
 * Untuk lab terakreditasi, selisih antara kebijakan tertulis dan perilaku
 * sistem itu temuan audit — bukan cuma ruang disk.
 *
 * ## Yang dibuang, yang ditinggal
 *
 * Yang dibuang cuma CITRA-nya. Baris `worksheet_scans` & `worksheet_scan_cells`
 * TETAP: di situ ada teks yang kebaca, skor, status, dan koreksi teknisi — itu
 * dataset akurasi dan jejak audit, ukurannya kecil, dan tidak memuat wajah
 * dokumen pelanggan. Yang hilang cuma kemampuan melihat ulang potongan selnya.
 *
 * Kolom path ikut dikosongkan di baris yang sama. Path yang menunjuk ke berkas
 * yang sudah tidak ada lebih buruk daripada `null`: layar koreksi akan
 * menjanjikan gambar yang tidak akan pernah muncul, dan yang menelusuri nanti
 * mengira berkasnya hilang karena bug.
 */
class BersihkanCitraPindai extends Command
{
    protected $signature = 'ocr:bersihkan-citra
        {--kering : Cuma laporkan yang bakal dibuang, jangan hapus apa pun}
        {--umur-citra= : Paksa umur citra lembar (hari); kosong = pakai config/ocr.php}
        {--umur-crop= : Paksa umur potongan sel (hari); kosong = pakai config/ocr.php}';

    protected $description = 'Buang citra pindai lembar kerja yang lewat batas retensi (config/ocr.php)';

    public function handle(): int
    {
        $umurCitra = $this->umur('umur-citra', 'ocr.penyimpanan.umur_citra_hari', 90);
        $umurCrop = $this->umur('umur-crop', 'ocr.penyimpanan.umur_crop_hari', 365);
        $kering = (bool) $this->option('kering');

        $disk = Storage::disk((string) config('ocr.penyimpanan.disk', 'local'));

        $batasCitra = Carbon::now()->subDays($umurCitra);
        $batasCrop = Carbon::now()->subDays($umurCrop);

        $this->info(sprintf(
            'Batas: citra lembar sebelum %s (%d hari), potongan sel sebelum %s (%d hari).%s',
            $batasCitra->toDateString(),
            $umurCitra,
            $batasCrop->toDateString(),
            $umurCrop,
            $kering ? ' [KERING — nggak ada yang dihapus]' : '',
        ));

        $citra = $this->bersihkanScan($disk, $batasCitra, $kering);
        $crop = $this->bersihkanCrop($disk, $batasCrop, $kering);

        $this->info("Citra lembar: {$citra['berkas']} berkas dibuang dari {$citra['baris']} pindai.");
        $this->info("Potongan sel: {$crop['berkas']} berkas dibuang dari {$crop['baris']} sel.");

        if ($citra['hilang'] > 0 || $crop['hilang'] > 0) {
            // Path ada di DB tapi berkasnya nggak ada. Bukan kegagalan — bisa
            // dihapus manual atau disk-nya diganti — tapi tetap dilaporkan
            // supaya nggak dikira perintah ini yang menghilangkannya.
            $this->warn("Path menunjuk berkas yang sudah tidak ada: {$citra['hilang']} citra, {$crop['hilang']} potongan. Kolomnya ikut dikosongkan.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{baris: int, berkas: int, hilang: int}
     */
    private function bersihkanScan(mixed $disk, Carbon $batas, bool $kering): array
    {
        $baris = 0;
        $berkas = 0;
        $hilang = 0;

        WorksheetScan::query()
            ->where('created_at', '<', $batas)
            ->where(function ($q): void {
                $q->whereNotNull('citra_path')->orWhereNotNull('citra_warp_path');
            })
            // Dicicil: satu lab bisa punya puluhan ribu pindai, dan menarik
            // semuanya sekaligus bikin perintah terjadwal ini mati kehabisan
            // memori — persis di jam yang tidak ada orang melihat.
            ->chunkById(200, function ($pindai) use ($disk, $kering, &$baris, &$berkas, &$hilang): void {
                foreach ($pindai as $satu) {
                    $baris++;

                    foreach (['citra_path', 'citra_warp_path'] as $kolom) {
                        $path = $satu->{$kolom};

                        if ($path === null) {
                            continue;
                        }

                        if ($disk->exists($path)) {
                            $berkas++;

                            if (! $kering) {
                                $disk->delete($path);
                            }
                        } else {
                            $hilang++;
                        }
                    }

                    if (! $kering) {
                        $satu->forceFill(['citra_path' => null, 'citra_warp_path' => null])->save();
                    }
                }
            });

        return ['baris' => $baris, 'berkas' => $berkas, 'hilang' => $hilang];
    }

    /**
     * Potongan per sel disimpan lebih lama: ukurannya kecil dan tidak
     * menampilkan identitas pelanggan, jadi dia yang jadi bahan dataset regresi.
     *
     * Catatan jujur: sampai sekarang `crop_path` TIDAK PERNAH diisi — potongan
     * sel dibuat mendadak oleh `GET /worksheet-scans/{id}/sel/{kunci}/crop` dari
     * citra warp, bukan disimpan sebagai berkas. Jadi jendela kedua ini belum
     * punya apa-apa untuk dibersihkan. Tetap ditulis di sini supaya begitu
     * potongan mulai disimpan, retensinya sudah jalan — bukan ditambahkan
     * belakangan setelah berkasnya menumpuk setahun.
     *
     * @return array{baris: int, berkas: int, hilang: int}
     */
    private function bersihkanCrop(mixed $disk, Carbon $batas, bool $kering): array
    {
        $baris = 0;
        $berkas = 0;
        $hilang = 0;

        WorksheetScanCell::query()
            ->whereNotNull('crop_path')
            ->where('created_at', '<', $batas)
            ->chunkById(500, function ($sel) use ($disk, $kering, &$baris, &$berkas, &$hilang): void {
                foreach ($sel as $satu) {
                    $baris++;

                    if ($disk->exists($satu->crop_path)) {
                        $berkas++;

                        if (! $kering) {
                            $disk->delete($satu->crop_path);
                        }
                    } else {
                        $hilang++;
                    }

                    if (! $kering) {
                        $satu->forceFill(['crop_path' => null])->save();
                    }
                }
            });

        return ['baris' => $baris, 'berkas' => $berkas, 'hilang' => $hilang];
    }

    private function umur(string $opsi, string $kunciConfig, int $bawaan): int
    {
        $nilai = $this->option($opsi);

        return ($nilai === null || $nilai === '')
            ? (int) config($kunciConfig, $bawaan)
            : (int) $nilai;
    }
}
