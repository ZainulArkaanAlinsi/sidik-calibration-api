<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\DirektoriLokal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Muat berkas direktori perusahaan ke tabel rujukan `direktori_lokal`.
 *
 * ## Bukan `customers:impor`, dan bedanya bukan soal rapi
 *
 * `customers:impor` memasukkan pelanggan yang BENERAN pernah dilayani lab —
 * tiap baris punya penanggung jawab, ikut tersalin ke HP teknisi, dan boleh
 * tercetak di sertifikat. Perintah ini memuat PETUNJUK: sepuluh ribu perusahaan
 * hasil impor direktori pihak ketiga yang tidak satu pun pernah jadi pelanggan.
 *
 * Karena itu dia sengaja TIDAK punya keranjang `perlu_tinjau`. Tinjauan manusia
 * per baris masuk akal buat 500 pelanggan lab; buat sepuluh ribu petunjuk yang
 * memang tidak diklaim benar, itu upacara yang tidak ada yang akan menyelesaikannya.
 * Penjagaannya pindah ke tempat yang lebih tepat: baris ini baru jadi data lab
 * setelah ada teknisi yang MEMILIHNYA di layar.
 *
 * ## Idempoten lewat `(sumber, ref)`
 *
 * Impor ulang berkas yang sama menimpa baris yang sama, bukan menggandakannya.
 * Yang dibeli: memperbarui satu sumber cukup menjalankan ulang perintahnya —
 * tidak perlu mengosongkan tabel dulu, dan sumber lain tidak ikut tersentuh.
 */
class ImporDirektoriLokal extends Command
{
    protected $signature = 'direktori:impor-lokal
        {berkas : Path CSV (kolom: ref, nama, alamat, kota, provinsi)}
        {--sumber= : Penanda sumber, salah satu dari: '.self::DAFTAR_SUMBER.'}
        {--uji-coba : Baca dan laporkan tanpa menulis apa pun}
        {--lewati-kalau-terisi : Keluar diam-diam kalau sumbernya sudah berisi (dipakai entrypoint saat boot)}';

    protected $description = 'Muat direktori perusahaan ke tabel rujukan direktori_lokal';

    private const DAFTAR_SUMBER = 'jababeka, indonetwork';

    /** Ditulis per potong, bukan sekaligus: sepuluh ribu baris sekali `upsert` bikin paket query raksasa. */
    private const POTONG = 500;

    public function handle(): int
    {
        $sumber = (string) $this->option('sumber');

        if (! in_array($sumber, DirektoriLokal::SUMBER, true)) {
            $this->error('--sumber wajib salah satu dari: '.implode(', ', DirektoriLokal::SUMBER).'.');

            return self::FAILURE;
        }

        // Diperiksa SEBELUM berkasnya dibaca, dan urutan itu yang jadi
        // seluruh gunanya. Dipanggil `docker/entrypoint.sh` tiap container
        // nyala — termasuk tiap Render membangunkan service yang ketiduran —
        // dan semua yang di entrypoint jalan SEBELUM server menerima request,
        // di dalam jendela health check 15 menit yang pernah kehabisan waktu
        // pada deploy 1 Sep 2026.
        //
        // Dengan penjagaan di sini, boot kedua dan seterusnya cuma membayar
        // SATU query `COUNT`, bukan membaca 1,3 MB CSV lalu menembakkan 22
        // paket `upsert` ke database yang ada di seberang jaringan.
        //
        // Sengaja memeriksa ISI, bukan menyimpan penanda "sudah pernah
        // jalan": database produksi yang direset (atau dipindah) bikin
        // penanda berbohong, sementara hitungan baris selalu jujur — dan
        // jalur ini memulihkan dirinya sendiri tanpa ada yang perlu masuk
        // shell. Itu penting justru karena paket gratis Render TIDAK
        // menyediakan shell sama sekali.
        if ($this->option('lewati-kalau-terisi')) {
            $terisi = DirektoriLokal::query()->where('sumber', $sumber)->count();

            if ($terisi > 0) {
                $this->line("Direktori `{$sumber}` sudah berisi {$terisi} baris — impor dilewati.");

                return self::SUCCESS;
            }
        }

        try {
            $baris = $this->baca((string) $this->argument('berkas'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($baris === []) {
            $this->warn('Berkas tidak memuat satu baris pun yang bisa dipakai.');

            return self::SUCCESS;
        }

        $sudahAda = DirektoriLokal::query()->where('sumber', $sumber)->count();

        $this->line(sprintf('Terbaca %d baris dari berkas. Di database sekarang: %d baris sumber `%s`.',
            count($baris), $sudahAda, $sumber));

        if ($this->option('uji-coba')) {
            $this->contoh($baris);
            $this->comment('--uji-coba: tidak ada yang ditulis.');

            return self::SUCCESS;
        }

        return $this->tulis($baris, $sumber, $sudahAda);
    }

    /**
     * @return list<array{ref: string, nama: string, alamat: ?string, kota: ?string, provinsi: ?string}>
     */
    private function baca(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Berkas tidak ketemu atau tidak bisa dibaca: {$path}");
        }

        $aliran = fopen($path, 'r');

        if ($aliran === false) {
            throw new RuntimeException("Berkas tidak bisa dibuka: {$path}");
        }

        // Escape KOSONG = RFC 4180, yang dipakai Excel. Dengan escape `\`
        // bawaan PHP, alamat yang kebetulan berakhir backslash menelan kutip
        // penutupnya dan kolom sesudahnya ikut melebur — tanpa satu pun error.
        // Alasan lengkapnya di `App\Support\ImporPelanggan\BacaBerkas`.
        $kepala = fgetcsv($aliran, 0, ',', '"', '');

        if ($kepala === false) {
            fclose($aliran);

            throw new RuntimeException("Berkas kosong: {$path}");
        }

        $kolom = array_flip(array_map(
            static fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")),
            $kepala,
        ));

        foreach (['ref', 'nama'] as $wajib) {
            if (! isset($kolom[$wajib])) {
                fclose($aliran);

                throw new RuntimeException(
                    "Berkas {$path} tidak punya kolom `{$wajib}`. Yang terbaca: ".implode(', ', $kepala),
                );
            }
        }

        $ambil = static function (array $sel, array $kolom, string $nama): ?string {
            $nilai = isset($kolom[$nama]) ? ($sel[$kolom[$nama]] ?? null) : null;
            $nilai = $nilai === null ? null : trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $nilai));

            return ($nilai === null || $nilai === '') ? null : $nilai;
        };

        $hasil = [];

        while (($sel = fgetcsv($aliran, 0, ',', '"', '')) !== false) {
            $nama = $ambil($sel, $kolom, 'nama');
            $ref = $ambil($sel, $kolom, 'ref');

            // Baris tanpa nama atau tanpa ref dilewat diam-diam, bukan
            // ditolak berisik: berkas direktori memang selalu punya baris
            // kosong di ujungnya, dan melaporkan tiap satunya menenggelamkan
            // ringkasan yang justru perlu dibaca.
            if ($nama === null || $ref === null) {
                continue;
            }

            // Nama kepanjangan DIPOTONG di sini, tidak seperti `customers:impor`
            // yang menolak barisnya. Bedanya disengaja: di sana nama adalah
            // identitas badan hukum yang akan tercetak, jadi potongan berarti
            // salah. Di sini dia kata kunci pencarian — terpotong masih ketemu,
            // dan hilang sama sekali berarti PT-nya tidak akan pernah muncul.
            $hasil[] = [
                'ref' => mb_substr($ref, 0, 64),
                'nama' => mb_substr($nama, 0, 255),
                'alamat' => ($a = $ambil($sel, $kolom, 'alamat')) === null ? null : mb_substr($a, 0, 255),
                'kota' => ($k = $ambil($sel, $kolom, 'kota')) === null ? null : mb_substr($k, 0, 128),
                'provinsi' => ($p = $ambil($sel, $kolom, 'provinsi')) === null ? null : mb_substr($p, 0, 64),
            ];
        }

        fclose($aliran);

        return $hasil;
    }

    /**
     * @param  list<array{ref: string, nama: string, alamat: ?string, kota: ?string, provinsi: ?string}>  $baris
     */
    private function tulis(array $baris, string $sumber, int $sudahAda): int
    {
        $sekarang = now();

        // `upsert` massal, dan di sini itu SAH — beda dari `customers:impor`
        // yang wajib lewat `save()` per baris. Bedanya: tabel ini tidak diaudit
        // dan `nama_normal`-nya dihitung di bawah dengan fungsi yang SAMA
        // dengan model (`Customer::normalkanNama`), jadi tidak ada event model
        // yang terlewat. Sepuluh ribu `save()` berarti sepuluh ribu query.
        $muatan = array_map(static fn (array $b) => [
            ...$b,
            'sumber' => $sumber,
            'nama_normal' => Customer::normalkanNama($b['nama']),
            'created_at' => $sekarang,
            'updated_at' => $sekarang,
        ], $baris);

        try {
            DB::transaction(function () use ($muatan): void {
                foreach (array_chunk($muatan, self::POTONG) as $potong) {
                    DirektoriLokal::query()->upsert(
                        $potong,
                        ['sumber', 'ref'],
                        ['nama', 'nama_normal', 'alamat', 'kota', 'provinsi', 'updated_at'],
                    );
                }
            });
        } catch (Throwable $e) {
            $this->error("Impor dibatalkan, tidak ada baris yang berubah: {$e->getMessage()}");

            return self::FAILURE;
        }

        $sesudah = DirektoriLokal::query()->where('sumber', $sumber)->count();

        $this->newLine();
        $this->table(['Sumber', 'Sebelum', 'Sesudah', 'Baru'], [[
            $sumber, $sudahAda, $sesudah, $sesudah - $sudahAda,
        ]]);

        $this->info(sprintf('%d baris sumber `%s` tersimpan (%d di antaranya baru).',
            count($muatan), $sumber, $sesudah - $sudahAda));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{ref: string, nama: string, alamat: ?string, kota: ?string, provinsi: ?string}>  $baris
     */
    private function contoh(array $baris): void
    {
        $this->newLine();
        $this->table(
            ['ref', 'nama', 'alamat'],
            array_map(
                static fn (array $b) => [$b['ref'], $b['nama'], mb_substr((string) $b['alamat'], 0, 60)],
                array_slice($baris, 0, 5),
            ),
        );
    }
}
