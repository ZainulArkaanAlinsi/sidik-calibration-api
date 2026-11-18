<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Isi `customers.nama_normal` yang masih kosong (NULL atau '').
 *
 * ## Kenapa ada
 *
 * `nama_normal` adalah kunci penjaga PT kembar (`POST /customers/cepat`,
 * pencarian longgar). Baris yang kolomnya kosong tidak pernah cocok dengan
 * apa pun, jadi PT yang sama bisa didaftarkan dua kali tanpa peringatan.
 * Baris lahir kosong lewat jalur yang tidak melewati model (impor/seed
 * langsung ke tabel); model sendiri mengisinya tiap kali menyimpan.
 *
 * ## Aturannya SATU, dan bukan salinan
 *
 * Nilainya dari `Customer::normalkanNama()` — fungsi yang dipakai model waktu
 * menyimpan dan migrasi pengisi pertamanya. Aturan kedua di sini akan
 * menghasilkan dua jenis nilai di satu kolom, dan penjaga kembar berbohong
 * untuk sebagian data.
 *
 * ## Yang perintah ini TIDAK lakukan
 *
 * - **Tanpa `--jalankan` tidak menulis apa pun** (bawaan dry-run). Tulis harus
 *   diketik sengaja.
 * - **Hanya mengisi yang kosong.** Baris yang sudah terisi tidak disentuh, juga
 *   kalau nilainya berbeda dari hasil normalisasi sekarang.
 * - **Tidak menggabungkan dan tidak menghapus PT kembar.** Yang terdeteksi cuma
 *   dihitung; keputusannya urusan orang.
 * - **Tidak mencetak nama, alamat, atau id pelanggan** — hanya hitungan.
 *   Keluaran perintah ini ditempel ke laporan.
 *
 * Penyimpanan lewat model (bukan `UPDATE` massal) supaya tiap baris yang terisi
 * tercatat `Diaudit` (`changed_by` kosong = dijalankan dari konsol).
 * Baris yang sudah dihapus lunak ikut diisi: dokumen lama bisa menunjuk ke sana.
 */
class IsiNamaNormalPelanggan extends Command
{
    protected $signature = 'pelanggan:isi-nama-normal
        {--dry-run : Hanya hitung, tidak menulis (bawaan)}
        {--jalankan : Tulis hasilnya}';

    protected $description = 'Isi customers.nama_normal yang kosong (dry-run bawaan; --jalankan menulis)';

    public function handle(): int
    {
        if ($this->option('jalankan') && $this->option('dry-run')) {
            $this->error('Pilih salah satu: --dry-run atau --jalankan.');

            return self::INVALID;
        }

        $jalankan = (bool) $this->option('jalankan');

        $kosong = fn (): Builder => Customer::withTrashed()
            ->where(fn (Builder $q) => $q->whereNull('nama_normal')->orWhere('nama_normal', ''));

        $total = (int) $kosong()->count();

        // Lintasan 1 — SEMUA hitungan, sebelum ada yang ditulis: dry-run dan
        // --jalankan harus melaporkan angka yang sama persis (kalau kembar dihitung
        // sambil menulis, baris yang baru terisi ikut dihitung sebagai "kembar").
        /** @var array<int, array{0: int, 1: string}> $rencana id => [organisasi, nama_normal] */
        $rencana = [];
        $tetapKosong = 0;

        $kosong()->orderBy('id')->chunkById(200, function ($daftar) use (&$rencana, &$tetapKosong): void {
            /** @var Customer $pelanggan */
            foreach ($daftar as $pelanggan) {
                $normal = Customer::normalkanNama((string) $pelanggan->nama);

                if ($normal === '') {
                    // Nama tanpa huruf/angka (mis. cuma tanda baca): tidak ada yang bisa diisi.
                    $tetapKosong++;

                    continue;
                }

                $rencana[(int) $pelanggan->getKey()] = [(int) $pelanggan->organization_id, $normal];
            }
        });

        $kunciKembar = array_count_values(array_map(fn (array $r) => $r[0].'|'.$r[1], $rencana));
        $kemungkinanKembar = 0;

        foreach ($rencana as [$organisasi, $normal]) {
            // Kembar dengan baris yang SUDAH terisi, atau dengan baris kosong lain
            // yang akan jadi sama.
            $denganYangAda = Customer::withTrashed()
                ->where('organization_id', $organisasi)
                ->where('nama_normal', $normal)
                ->exists();

            if ($denganYangAda || $kunciKembar[$organisasi.'|'.$normal] > 1) {
                $kemungkinanKembar++;
            }
        }

        $akanTerisi = count($rencana);
        $terisi = 0;

        // Lintasan 2 — menulis, hanya dengan --jalankan.
        if ($jalankan) {
            Customer::withTrashed()
                ->whereIn('id', array_keys($rencana))
                ->orderBy('id')
                ->chunkById(200, function ($daftar) use ($rencana, &$terisi): void {
                    /** @var Customer $pelanggan */
                    foreach ($daftar as $pelanggan) {
                        // `saving` di model menurunkan ulang `nama_normal` dari `nama`;
                        // diisi eksplisit juga supaya jelas apa yang ditulis.
                        $pelanggan->nama_normal = $rencana[(int) $pelanggan->getKey()][1];
                        $pelanggan->save();
                        $terisi++;
                    }
                });
        }

        $this->line('Mode: '.($jalankan ? 'JALANKAN (menulis)' : 'dry-run (tidak menulis)'));
        $this->line("nama_normal kosong: {$total}");
        $this->line("bisa diisi: {$akanTerisi}");
        $this->line("tetap kosong (nama tanpa huruf/angka): {$tetapKosong}");
        $this->line("kemungkinan kembar dengan PT lain setelah diisi: {$kemungkinanKembar}");

        if ($jalankan) {
            $this->info("terisi: {$terisi}");
        } else {
            $this->comment('Dry-run: tidak ada yang ditulis. Tambahkan --jalankan untuk menulis.');
        }

        return self::SUCCESS;
    }
}
