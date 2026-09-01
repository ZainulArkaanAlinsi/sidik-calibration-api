<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Salin seluruh isi disk `arsip` ke disk lain dengan KUNCI YANG SAMA PERSIS.
 *
 * ## Kenapa ini ada
 *
 * Produksi jalan dengan `ARSIP_DRIVER=local`, dan disk container Render itu
 * sementara — kehapus tiap deploy. Obatnya memindahkan berkas ke R2
 * (`ARSIP_DRIVER=s3`), dan `docs/CHECKLIST-DEPLOY-VPS.md` menyebut langkah
 * ketiganya begini: *"salin berkas lama ke bucket dengan kunci yang SAMA
 * PERSIS dengan isi kolom `pdf_path`, `tanda_tangan_path`, dan `path`"*.
 *
 * Langkah itu yang paling gampang salah, dan salahnya paling mahal. Kolom di
 * database menyimpan KUNCI, bukan URL — jadi begitu `ARSIP_DRIVER` digeser,
 * aplikasi mencari `certificates/abc.pdf` di bucket. Kalau waktu menyalin
 * kuncinya berubah walau sedikit — kelebihan prefiks folder, hilang satu
 * tingkat direktori, nama file ter-normalisasi — seluruh berkas lama jadi
 * tidak ketemu, dan gejalanya persis sama dengan disk yang kehapus: 404 di
 * mana-mana, tanpa satu pun error yang menyebut sebabnya.
 *
 * Perintah ini menyalin kunci apa adanya, memverifikasi ukurannya sesudah
 * mendarat, dan menolak diam-diam menimpa. Yang dibeli: langkah manual yang
 * rawan salah ketik jadi satu perintah yang bisa diulang dan dicoba kering
 * dulu.
 *
 * ## Prefix bucket ikut, dan itu bukan detail
 *
 * Begitu ARSIP_DRIVER=s3, disk `arsip` memakai ARSIP_PREFIX sebagai `root` —
 * dan di S3 `root` artinya PREFIX KUNCI, bukan folder. Jadi yang menulis
 * (perintah ini) dan yang membaca (aplikasi sesudah saklarnya digeser) harus
 * memakai prefix yang sama, kalau nggak hasil pindahnya nggak ketemu sama
 * sekali. Nilainya dihitung sekali di `config/filesystems.php`
 * (`filesystems.arsip_prefiks`) dan dibaca dua tempat dari sana.
 *
 * ## Tiga keadaan buat berkas yang sudah ada di tujuan
 *
 * Bedanya penting, karena "sudah ada" saja bukan kabar baik:
 *
 *   - ukurannya SAMA dengan sumber  → dilewat, jalan ulang jadi murah;
 *   - ukurannya BEDA                → BENTROK: berkasnya nggak disentuh, tapi
 *                                     perintahnya keluar gagal. Ini sisa
 *                                     pindah yang mati di tengah, dan kalau
 *                                     dibaca "sudah beres" operator menggeser
 *                                     ARSIP_DRIVER di atas berkas kepotong;
 *   - bentroknya sudah diperiksa    → `--timpa` yang membereskan.
 *
 * ## Urutan pakainya
 *
 *   1. Isi keempat `AWS_*` di Render (bucket R2 sudah dibuat).
 *   2. `php artisan arsip:pindah` — coba kering, cuma melaporkan.
 *   3. `php artisan arsip:pindah --jalankan` — salin beneran.
 *   4. BARU setel `ARSIP_DRIVER=s3` — dan cuma kalau langkah 3 keluar sukses.
 *
 * Digeser sebelum langkah 3 selesai, berkas lama tidak ketemu.
 */
class PindahArsip extends Command
{
    protected $signature = 'arsip:pindah
        {--tujuan=s3 : Disk tujuan seperti tertulis di config/filesystems.php}
        {--jalankan : Salin beneran. Tanpa ini cuma coba kering.}
        {--timpa : Timpa berkas yang sudah ada di tujuan.}';

    protected $description = 'Salin isi disk arsip ke disk lain dengan kunci yang sama persis.';

    public function handle(): int
    {
        $tujuan = (string) $this->option('tujuan');
        $jalankan = (bool) $this->option('jalankan');

        if ($tujuan === 'arsip') {
            $this->error('Tujuannya nggak boleh disk `arsip` itu sendiri.');

            return self::FAILURE;
        }

        if (! array_key_exists($tujuan, config('filesystems.disks', []))) {
            $this->error("Disk `{$tujuan}` nggak ada di config/filesystems.php.");

            return self::FAILURE;
        }

        $sumber = Storage::disk('arsip');
        $ke = Storage::disk($tujuan);

        // Prefix WAJIB ikut, dan ini kesalahan yang paling gampang dibuat di
        // perintah ini — termasuk oleh yang menulisnya.
        //
        // Disk `arsip` memakai ARSIP_PREFIX sebagai `root` begitu drivernya
        // s3, dan di S3 `root` itu artinya PREFIX KUNCI, bukan folder (lihat
        // config/filesystems.php). Jadi sesudah saklarnya digeser, aplikasi
        // mencari `produksi/certificates/abc.pdf`.
        //
        // Menulis ke disk `s3` mentah berarti menulis `certificates/abc.pdf` —
        // tanpa prefix. Perintahnya selesai dengan kode 0, dan SELURUH arsip
        // yang dipindahkan nggak ketemu. Gejalanya identik dengan disk yang
        // kehapus, dan itu persis kerusakan yang perintah ini dibikin buat
        // mencegah.
        //
        // Nilainya dihitung sekali di config/filesystems.php dan dibaca dua
        // tempat dari sana, supaya yang menulis dan yang membaca nggak bisa
        // berbeda pendapat.
        $prefiks = config("filesystems.disks.{$tujuan}.driver") === 's3'
            ? (string) config('filesystems.arsip_prefiks', '')
            : '';

        $tujuanKunci = fn (string $kunci): string => $prefiks === '' ? $kunci : $prefiks.'/'.$kunci;

        if ($prefiks !== '') {
            $this->line("Prefix bucket: `{$prefiks}/` (dari ARSIP_PREFIX).");
        }

        // Disk tujuan diuji SEKARANG, bukan waktu berkas pertama gagal
        // mendarat. Kredensial R2 yang belum diisi bikin tiap `put()` balik
        // `false` tanpa suara (disk ini disetel `throw => false`), dan tanpa
        // uji ini perintahnya melaporkan ratusan kegagalan yang penyebabnya
        // satu.
        $uji = '.uji-pindah-arsip';

        if ($jalankan && $ke->put($tujuanKunci($uji), 'uji') === false) {
            $this->error("Disk `{$tujuan}` nggak bisa ditulis. Cek kredensialnya (AWS_* buat R2).");

            return self::FAILURE;
        }

        $jalankan && $ke->delete($tujuanKunci($uji));

        $berkas = $sumber->allFiles();

        if ($berkas === []) {
            $this->warn('Disk `arsip` kosong — nggak ada yang perlu disalin.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d berkas dari `arsip` ke `%s`.',
            $jalankan ? 'Menyalin' : '[COBA KERING] Bakal menyalin',
            count($berkas),
            $tujuan,
        ));

        $salin = 0;
        $lewat = 0;
        $gagal = 0;
        $bentrok = 0;

        foreach ($berkas as $kunci) {
            $ukuranSumber = (int) $sumber->size($kunci);
            $tujuanKe = $tujuanKunci($kunci);

            // Yang bikin sebuah berkas boleh dilewat itu UKURANNYA yang sama,
            // bukan sekadar keberadaannya.
            //
            // Bedanya kelihatan waktu pindah sebelumnya mati di tengah — dan
            // itu skenario yang wajar, bukan yang aneh: jaringan putus, proses
            // kena OOM, jatah 512 MB Render kehabisan. Yang ditinggalkan bukan
            // berkas yang HILANG, tapi berkas yang KEPOTONG.
            //
            // Dibaca sebagai "sudah ada", jalan ulang bakal melewatinya,
            // melaporkan `0 gagal`, dan menutup dengan "Sekarang aman menyetel
            // ARSIP_DRIVER". Operator menggeser saklarnya sambil merasa sudah
            // memverifikasi, dan yang diunduh pelanggan PDF yang kepotong —
            // persis kerusakan yang perintah ini dibikin buat mencegah.
            if ($ke->exists($tujuanKe) && ! $this->option('timpa')) {
                if ((int) $ke->size($tujuanKe) === $ukuranSumber) {
                    $this->line("  lewat (sudah sama): {$tujuanKe}");
                    $lewat++;

                    continue;
                }

                $this->error(sprintf(
                    '  BENTROK: %s sudah ada di tujuan tapi ukurannya beda (%d B di sana, %d B di sumber).',
                    $tujuanKe,
                    (int) $ke->size($tujuanKe),
                    $ukuranSumber,
                ));
                $bentrok++;

                continue;
            }

            if (! $jalankan) {
                $this->line("  salin: {$kunci} -> {$tujuanKe}");
                $salin++;

                continue;
            }

            $isi = $sumber->get($kunci);

            if ($isi === null) {
                $this->error("  GAGAL dibaca: {$kunci}");
                $gagal++;

                continue;
            }

            // Kunci dipakai APA ADANYA. Ini inti perintahnya — lihat docblock.
            if ($ke->put($tujuanKe, $isi) === false) {
                $this->error("  GAGAL ditulis: {$tujuanKe}");
                $gagal++;

                continue;
            }

            // Diperiksa sesudah mendarat, bukan dipercaya dari nilai balik.
            // Alasannya sama seperti di `BerkasPdfSertifikat`: `put()` balik
            // `true` buat penulisan yang terpotong, dan berkas terpotong di
            // bucket itu kerusakan yang baru ketahuan waktu pelanggan mengunduh.
            if ((int) $ke->size($tujuanKe) !== strlen($isi)) {
                // Yang kepotong DIHAPUS, bukan ditinggal. Dua alasannya:
                // selama dia ada, aplikasi bisa menyajikannya sebagai berkas
                // yang sah; dan tidak-ada jauh lebih gampang dilihat daripada
                // ada-tapi-salah.
                $ke->delete($tujuanKe);

                $this->error("  GAGAL: ukuran nggak cocok sesudah disalin, yang kepotong dihapus: {$tujuanKe}");
                $gagal++;

                continue;
            }

            $salin++;
        }

        $this->newLine();
        $this->info("Selesai: {$salin} disalin, {$lewat} dilewat, {$bentrok} bentrok, {$gagal} gagal.");

        if (! $jalankan) {
            $this->warn('Ini COBA KERING. Tambahkan --jalankan buat menyalin beneran.');
        }

        if ($bentrok > 0) {
            $this->error('Ada berkas yang isinya beda di tujuan — JANGAN geser ARSIP_DRIVER dulu.');
            $this->line('  Periksa dulu yang mana yang benar. Kalau yang di sumber, jalankan ulang dengan --timpa.');

            return self::FAILURE;
        }

        if ($gagal > 0) {
            $this->error('Ada yang gagal — JANGAN geser ARSIP_DRIVER dulu.');

            return self::FAILURE;
        }

        if ($jalankan) {
            $this->info("Sekarang aman menyetel ARSIP_DRIVER={$tujuan}.");
        }

        return self::SUCCESS;
    }
}
