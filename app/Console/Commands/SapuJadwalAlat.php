<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Services\SinkronJadwalAlat;
use Illuminate\Console\Command;

/**
 * Sapuan sekali jalan: samain jadwal SEMUA alat sama sertifikat aktifnya.
 *
 * Namanya beda dari servicenya ([SinkronJadwalAlat]) mengikuti kebiasaan repo
 * ini — `CekJatuhTempo` memakai `PengingatJatuhTempo`, `CekStandarKadaluarsa`
 * memakai `PengingatStandar`. Yang dicari orang biasanya signature-nya:
 * `alat:sinkron-jadwal`.
 *
 * ## Buat apa, kalau job-nya sudah menyambung sendiri
 *
 * [\App\Jobs\GenerateCertificate] memanggil servicenya tiap sertifikat terbit,
 * jadi yang ke DEPAN sudah beres. Perintah ini buat yang terlanjur: alat yang
 * sertifikatnya terbit sebelum sambungan itu ada, dan kolomnya masih menyimpan
 * tanggal lama.
 *
 * ## JANGAN dijadwalkan
 *
 * Ini sengaja nggak masuk `routes/console.php`. Dijalankan tiap malam, dia jadi
 * proses yang terus-terusan menyentuh kolom yang diisi admin — dan buat alat
 * yang sertifikatnya memang nggak pernah masuk sistem ini, perubahan yang
 * ditulis tangan bakal terus diadu sama data yang nggak ada. Sekali jalan,
 * ditinjau manusia, selesai.
 *
 * ## Urutannya: lihat dulu, baru tulis
 *
 * Tanpa flag pun tabelnya ditampilkan LEBIH DULU, baru konfirmasi. Konfirmasi
 * "yakin?" yang tidak menyebut apa yang bakal berubah cuma jadi tombol yang
 * ditekan orang tanpa dibaca — dan yang diubah di sini kolom yang menentukan
 * kapan alat pelanggan harus dikalibrasi lagi.
 */
class SapuJadwalAlat extends Command
{
    protected $signature = 'alat:sinkron-jadwal
        {--dry-run : Cuma nampilin yang bakal berubah, nggak nulis apa-apa}';

    protected $description = 'Samain tanggal kalibrasi & jatuh tempo alat dengan sertifikat aktifnya';

    /** Alat diambil per sekian baris — lab bisa punya puluhan ribu. */
    private const UKURAN_CHUNK = 200;

    public function handle(SinkronJadwalAlat $sinkron): int
    {
        $kering = (bool) $this->option('dry-run');

        // Pass pertama: BACA saja. Dikumpulkan yang beneran berubah, karena
        // itu yang perlu dibaca manusia — alat yang tanggalnya sudah benar
        // cuma bikin tabelnya panjang dan yang penting tenggelam.
        $rencana = [];
        $baris = [];

        Equipment::query()
            ->orderBy('id')
            ->chunkById(self::UKURAN_CHUNK, function ($alatList) use ($sinkron, &$rencana, &$baris): void {
                foreach ($alatList as $alat) {
                    $r = $sinkron->rencana($alat);

                    if ($r === null) {
                        continue;
                    }

                    $kalibrasiLama = $alat->tanggal_kalibrasi_terakhir?->toDateString();
                    $tempoLama = $alat->tanggal_jatuh_tempo?->toDateString();

                    if ($kalibrasiLama === $r['tanggal_kalibrasi_terakhir']
                        && $tempoLama === $r['tanggal_jatuh_tempo']) {
                        continue;
                    }

                    $rencana[] = $alat->getKey();
                    $baris[] = [
                        $alat->getKey(),
                        $alat->nama_alat,
                        ($kalibrasiLama ?? '—').' → '.$r['tanggal_kalibrasi_terakhir'],
                        ($tempoLama ?? '—').' → '.$r['tanggal_jatuh_tempo'],
                        $r['nomor'] ?? '—',
                    ];
                }
            });

        if ($baris === []) {
            $this->info('Semua alat sudah sejalan dengan sertifikat aktifnya — nggak ada yang perlu diubah.');

            return self::SUCCESS;
        }

        $this->table(
            ['equipment_id', 'nama_alat', 'tgl_kalibrasi lama→baru', 'jatuh_tempo lama→baru', 'nomor sertifikat sumber'],
            $baris,
        );

        $jumlah = count($baris);

        if ($kering) {
            $this->info("{$jumlah} alat AKAN diubah (dry-run, belum ada yang ditulis).");
            $this->warn('Tinjau tabel di atas bareng admin lab dulu sebelum menjalankan ini tanpa --dry-run.');

            return self::SUCCESS;
        }

        // Default `false`: kalau dipanggil tanpa terminal (cron, CI), Laravel
        // memulangkan default dan perintahnya berhenti tanpa menulis — bukan
        // menganggap diam sebagai "iya".
        if (! $this->confirm("Tulis perubahan ke {$jumlah} alat?", false)) {
            $this->warn('Dibatalkan. Nggak ada yang ditulis.');

            return self::SUCCESS;
        }

        // Pass kedua lewat `untuk()`, BUKAN nulis dari tabel di atas: satu jalur
        // tulis saja, supaya perintah ini nggak bisa pelan-pelan berbeda dari
        // apa yang dilakukan job waktu sertifikat terbit.
        $ditulis = 0;

        Equipment::query()
            ->whereIn('id', $rencana)
            ->orderBy('id')
            ->chunkById(self::UKURAN_CHUNK, function ($alatList) use ($sinkron, &$ditulis): void {
                foreach ($alatList as $alat) {
                    $sinkron->untuk($alat);
                    $ditulis++;
                }
            });

        $this->info("{$ditulis} alat diperbarui.");

        return self::SUCCESS;
    }
}
