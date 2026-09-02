<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Support\ImporPelanggan\BacaBerkas;
use App\Support\ImporPelanggan\BarisMasukan;
use App\Support\ImporPelanggan\Laporan;
use App\Support\ImporPelanggan\Pemilah;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Masukkan pelanggan historis lab dari CSV ke `customers`.
 *
 * ## Kenapa ini yang paling berdampak, bukan direktori nasional
 *
 * Yang bikin teknisi mengetik ulang nama & alamat bukan PT yang belum pernah
 * dilayani — itu justru jarang. Yang sering pelanggan LAMA lab yang belum
 * pernah masuk `customers` karena arsipnya masih di Excel dan buku order.
 * Begitu mereka masuk, sebagian besar keluhan "ngetik ulang" hilang tanpa satu
 * rupiah pun keluar buat Places.
 *
 * ## Kenapa tiga keranjang, bukan langsung tulis
 *
 * Alamat yang salah di sini TIDAK BISA ditarik. `certificates.snapshot`
 * membekukan data pelanggan sebagaimana saat sertifikat terbit — itu perilaku
 * yang benar, tapi konsekuensinya memperbaiki `customers` besok tidak
 * memperbaiki sertifikat yang sudah dipegang pelanggan. Jadi baris yang
 * meragukan berhenti di laporan, bukan di database.
 *
 * ## Yang perintah ini TIDAK lakukan
 *
 *   - **Tidak pernah meng-update baris yang sudah ada.** Pelanggan yang sudah
 *     ada dilewati, dicatat. Kalau dia meng-update, satu jalan ulang dengan
 *     berkas lama menimpa alamat yang sudah dibetulkan admin di panel — dan
 *     yang menimpa kelihatan seperti impor yang berhasil.
 *   - **Tidak menebak alamat.** Baris tanpa alamat masuk dengan `alamat` kosong.
 *   - **Tidak menggabungkan apa pun.** Penggabungan kembar butuh orang yang
 *     memutuskan mana yang dipertahankan; itu Milestone C, bukan ini.
 */
class ImporPelanggan extends Command
{
    protected $signature = 'customers:impor
        {berkas : Path CSV daftar pelanggan (export dari Excel lab)}
        {--organization= : ID organisasi tujuan (wajib)}
        {--sumber=admin : Nilai kolom `sumber` untuk baris hasil impor}
        {--oleh= : ID user yang bertanggung jawab atas impor ini}
        {--uji-coba : Jalan tanpa menulis apa pun, cuma laporan}
        {--laporan= : Path CSV hasil tinjauan}';

    protected $description = 'Impor pelanggan historis lab dari CSV, dengan pemilahan kembar';

    public function handle(): int
    {
        $organization = $this->organisasi();

        if ($organization === null) {
            return self::FAILURE;
        }

        $sumber = (string) $this->option('sumber');

        if (! in_array($sumber, Customer::SUMBER, true)) {
            $this->error('--sumber harus salah satu dari: '.implode(', ', Customer::SUMBER).'.');

            return self::FAILURE;
        }

        $oleh = $this->pembuat($organization->id);

        if ($oleh === false) {
            return self::FAILURE;
        }

        try {
            $berkas = BacaBerkas::baca((string) $this->argument('berkas'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Terbaca: %d baris, pemisah `%s`, kolom dikenali: %s.',
            count($berkas['baris']),
            $berkas['pemisah'] === "\t" ? '\t' : $berkas['pemisah'],
            implode(', ', array_keys($berkas['kolom'])),
        ));

        $keranjang = Pemilah::pilah($berkas['baris'], $this->sudahAda($organization->id));

        $this->ringkas($keranjang, $berkas['ditolak']);
        $this->tulisLaporan($keranjang, $berkas['ditolak'], $berkas['pemisah']);

        if ($this->option('uji-coba')) {
            $this->comment('--uji-coba: tidak ada yang ditulis. Baca laporannya dulu, baru jalankan tanpa --uji-coba.');

            return self::SUCCESS;
        }

        if ($keranjang['baru'] === []) {
            $this->info('Tidak ada baris baru untuk ditulis.');

            return self::SUCCESS;
        }

        return $this->tulis($keranjang['baru'], $organization->id, $sumber, $oleh);
    }

    private function organisasi(): ?Organization
    {
        $id = $this->option('organization');

        if ($id === null || $id === '') {
            $this->error('--organization wajib diisi. Berkas tidak boleh menentukan organisasinya sendiri: '
                .'satu berkas salah taruh berarti pelanggan lab lain.');

            return null;
        }

        $organization = Organization::find($id);

        if ($organization === null) {
            $this->error("Organisasi {$id} tidak ada.");

            return null;
        }

        return $organization;
    }

    /**
     * @return User|null|false `false` = gagal, `null` = sengaja dikosongkan
     */
    private function pembuat(int $organizationId): User|null|false
    {
        $id = $this->option('oleh');

        if ($id === null || $id === '') {
            // Dibiarkan kosong, bukan ditebak ke admin mana pun — sama seperti
            // baris lama yang lahir sebelum kolom ini ada. Penanggung jawab yang
            // dikarang lebih buruk daripada yang kosong: yang kosong kelihatan
            // sebagai "tidak diketahui", yang dikarang kelihatan sebagai fakta.
            $this->warn('--oleh tidak diisi: baris impor lahir tanpa penanggung jawab.');

            return null;
        }

        $user = User::where('organization_id', $organizationId)->find($id);

        if ($user === null) {
            $this->error("User {$id} tidak ada di organisasi {$organizationId}.");

            return false;
        }

        return $user;
    }

    /**
     * Pelanggan yang sudah ada, TERMASUK yang sudah dihapus.
     *
     * Soft delete cuma mengisi `deleted_at` — barisnya masih ada, dan unique
     * index `customers_organization_id_nama_unique` masih memegangnya. Tanpa
     * `withTrashed()` di sini, pelanggan yang pernah dihapus terbaca sebagai
     * "belum ada", lalu insert-nya ditolak database di tengah jalan.
     *
     * @return list<array{id: int, nama: string, nama_normal: string, terhapus: bool}>
     */
    private function sudahAda(int $organizationId): array
    {
        return Customer::withTrashed()
            ->where('organization_id', $organizationId)
            ->get(['id', 'nama', 'nama_normal', 'deleted_at'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'nama' => (string) $c->nama,
                'nama_normal' => (string) ($c->nama_normal ?? Customer::normalkanNama((string) $c->nama)),
                // Ikut supaya laporannya bisa bilang "sudah DIHAPUS", bukan cuma
                // "sudah ada" — yang kedua bikin admin mencarinya di panel dan
                // tidak menemukan apa-apa.
                'terhapus' => $c->trashed(),
            ])
            ->all();
    }

    /**
     * @param  list<BarisMasukan>  $baru
     */
    private function tulis(array $baru, int $organizationId, string $sumber, ?User $oleh): int
    {
        $ditulis = 0;

        // `Diaudit` mengambil pelakunya dari `Auth::id()`, dan di baris perintah
        // itu selalu kosong. Tanpa ini impor 500 pelanggan mendarat di
        // `audit_logs` sebagai 500 pembuatan "oleh entah siapa" — dan riwayat
        // tanpa penanggung jawab persis yang ditanya asesor. `--oleh` sudah
        // diadu ke organisasinya di atas, jadi yang dipasang di sini bukan
        // klaim baru, cuma penerusan.
        if ($oleh !== null) {
            Auth::setUser($oleh);
        }

        try {
            DB::transaction(function () use ($baru, $organizationId, $sumber, $oleh, &$ditulis): void {
                foreach ($baru as $baris) {
                    // `save()` per baris, bukan `insert()` massal: `nama_normal`
                    // diturunkan di `Customer::booted()` dan riwayatnya dicatat
                    // `Diaudit` — dua-duanya lewat event model, dan insert massal
                    // melewati keduanya tanpa satu pun error.
                    $pelanggan = new Customer;
                    $pelanggan->fill($baris->untukDisimpan());
                    $pelanggan->organization_id = $organizationId;
                    $pelanggan->sumber = $sumber;
                    $pelanggan->dibuat_oleh_user_id = $oleh?->id;
                    $pelanggan->save();

                    $ditulis++;
                }
            });
        } catch (Throwable $e) {
            $this->error("Impor dibatalkan, tidak ada baris yang masuk: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("{$ditulis} pelanggan masuk.");

        return self::SUCCESS;
    }

    /**
     * @param  array{baru: list<BarisMasukan>, kembar_pasti: list<array{baris: BarisMasukan, lawan: string, sebab: string}>, perlu_tinjau: list<array{baris: BarisMasukan, lawan: string, sebab: string}>}  $keranjang
     * @param  list<array{baris: int, alasan: string, isi: string}>  $ditolak
     */
    private function ringkas(array $keranjang, array $ditolak): void
    {
        $this->newLine();
        $this->table(
            ['Keranjang', 'Jumlah', 'Nasibnya'],
            [
                ['baru', count($keranjang['baru']), 'ditulis ke customers'],
                ['kembar_pasti', count($keranjang['kembar_pasti']), 'dilewati, sudah ada'],
                ['perlu_tinjau', count($keranjang['perlu_tinjau']), 'TIDAK ditulis, tunggu keputusan orang'],
                ['ditolak', count($ditolak), 'TIDAK ditulis, barisnya tidak terbaca'],
            ],
        );

        $peringatan = [];

        foreach ([...$keranjang['baru'], ...array_column($keranjang['kembar_pasti'], 'baris'), ...array_column($keranjang['perlu_tinjau'], 'baris')] as $baris) {
            foreach ($baris->peringatan as $satu) {
                $peringatan[] = "baris {$baris->nomorBaris}: {$satu}";
            }
        }

        if ($peringatan !== []) {
            $this->newLine();
            $this->warn(count($peringatan).' peringatan isi:');

            foreach (array_slice($peringatan, 0, 20) as $satu) {
                $this->line("  - {$satu}");
            }

            if (count($peringatan) > 20) {
                $this->line('  ... sisanya di laporan.');
            }
        }
    }

    /**
     * @param  array{baru: list<BarisMasukan>, kembar_pasti: list<array{baris: BarisMasukan, lawan: string, sebab: string}>, perlu_tinjau: list<array{baris: BarisMasukan, lawan: string, sebab: string}>}  $keranjang
     * @param  list<array{baris: int, alasan: string, isi: string}>  $ditolak
     */
    private function tulisLaporan(array $keranjang, array $ditolak, string $pemisah): void
    {
        $path = $this->option('laporan');

        if ($path === null || $path === '') {
            if ($keranjang['perlu_tinjau'] !== [] || $ditolak !== []) {
                $this->warn('Ada baris yang butuh dilihat orang, tapi --laporan tidak diisi. '
                    .'Jalankan lagi dengan --laporan=storage/app/impor-pelanggan.csv untuk merincinya.');
            }

            return;
        }

        try {
            Laporan::tulis((string) $path, $keranjang, $ditolak, $pemisah);
            $this->info("Laporan ditulis: {$path}");
        } catch (Throwable $e) {
            $this->error("Laporan gagal ditulis: {$e->getMessage()}");
        }
    }
}
