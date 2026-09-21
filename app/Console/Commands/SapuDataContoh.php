<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hapus sesi & sertifikat yang lahir dari SEEDER, sebelum lab memakai sistem
 * ini dengan data sungguhan.
 *
 * ## Kenapa ini perlu ada
 *
 * 21 Sep 2026 ketahuan bahwa SELURUH 25 sertifikat di produksi berasal dari
 * data contoh - nol dari pekerjaan kalibrasi pelanggan. Yang jadi masalah bukan
 * barisnya, tapi bahwa mereka MENGHABISKAN NOMOR SERTIFIKAT RESMI:
 * `CAL/2026/08/0001-0004` dan `CAL/2026/09/0001-0020`. Untuk lab terakreditasi,
 * nomor yang hilang dari urutan itu pertanyaan audit, bukan detail teknis.
 *
 * Penomorannya dihitung per bulan (`GenerateCertificate::nomorBerikutnya()`
 * memakai awalan `CAL/Y/m/`), jadi kerusakannya terbatas di Agustus &
 * September 2026 dan tidak menjalar ke bulan berikutnya. Tapi selama barisnya
 * masih ada, dua bulan itu tetap tidak bisa dijelaskan.
 *
 * ## Kenapa perintah, bukan SQL ketik tangan
 *
 * `CalibrationSession` dan `Certificate` TIDAK memakai `SoftDeletes`. Ini
 * penghapusan permanen: satu baris saja ikut terbawa, tidak ada jalan kembali
 * dari aplikasi. Perintah bisa dibaca, diuji, dan dijalankan ulang dengan hasil
 * yang sama; SQL yang diketik di terminal tidak.
 *
 * Alurnya mengikuti `docs/aturan-akses-database.md` butir 4: rencana tertulis
 * -> cadangan terverifikasi -> dry-run ditunjukkan -> eksekusi atas instruksi
 * terpisah. Karena itu `--hapus` HARUS disebut; tanpa itu perintah ini cuma
 * melapor.
 *
 *   php artisan sertifikat:sapu-data-contoh
 *   php artisan sertifikat:sapu-data-contoh --hapus --cadangan="C:\cadangan"
 */
class SapuDataContoh extends Command
{
    protected $signature = 'sertifikat:sapu-data-contoh
        {--hapus : benar-benar hapus; tanpa ini perintah HANYA menampilkan}
        {--cadangan= : direktori cadangan, WAJIB bersama --hapus}
        {--tanpa-berkas : lanjut walau PDF tidak bisa ikut dicadangkan}';

    protected $description = 'Hapus sesi & sertifikat bawaan seeder sebelum sistem dipakai sungguhan';

    /**
     * Nomor sesi yang dibuat APLIKASI, bukan seeder.
     *
     * `CalibrationController` selalu memakai `nomorSesiBerikutnya()` dan tidak
     * pernah menerima `nomor_sesi` dari request, jadi format ini satu-satunya
     * yang bisa lahir dari pemakaian nyata. Apa pun di luar pola ini berasal
     * dari seeder - nomornya disalin dari workbook master lab.
     */
    private const POLA_NOMOR_APLIKASI = '/^KAL\/\d{4}\/\d{2}\/\d{4}$/';

    public function handle(): int
    {
        $hapus = (bool) $this->option('hapus');
        $dirCadangan = (string) ($this->option('cadangan') ?? '');

        $sesi = CalibrationSession::query()->orderBy('id')->get(['id', 'nomor_sesi', 'status']);

        $contoh = $sesi->reject(
            fn (CalibrationSession $s): bool => (bool) preg_match(self::POLA_NOMOR_APLIKASI, (string) $s->nomor_sesi)
        )->values();

        $dipertahankan = $sesi->count() - $contoh->count();

        if ($contoh->isEmpty()) {
            $this->components->info('Tidak ada sesi bawaan seeder. Tidak ada yang perlu dihapus.');

            return self::SUCCESS;
        }

        // Penjagaan kedua, dan sengaja ada DUA.
        //
        // Yang pertama (pola nomor) memilih berdasarkan "bukan buatan
        // aplikasi". Yang kedua menuntut tiap baris yang terpilih BENAR-BENAR
        // ketemu di seeder repo ini. Kalau ada satu saja yang tidak dikenali,
        // perintah berhenti total - bukan melewatinya diam-diam.
        //
        // Alasannya: yang paling berbahaya di sini bukan gagal menghapus, tapi
        // menghapus sesuatu yang ternyata pekerjaan orang.
        $dikenal = $this->nomorSesiDiSeeder();
        $asing = $contoh->filter(function (CalibrationSession $s) use ($dikenal): bool {
            $n = (string) $s->nomor_sesi;

            return ! in_array($n, $dikenal, true) && ! str_starts_with($n, 'DEMO');
        });

        if ($asing->isNotEmpty()) {
            $this->components->error('BERHENTI - ada sesi yang tidak dikenali sebagai data contoh:');
            $this->table(['ID', 'Nomor sesi', 'Status'], $asing->map(
                fn (CalibrationSession $s): array => [$s->id, $s->nomor_sesi, $s->status]
            )->values()->all());
            $this->line('Nomornya tidak ketemu di seeder mana pun dan bukan berawalan DEMO.');
            $this->line('Periksa dulu asalnya. Tidak ada yang dihapus.');

            return self::FAILURE;
        }

        $idSesi = $contoh->pluck('id')->all();
        $idSertifikat = Certificate::whereIn('calibration_session_id', $idSesi)->pluck('id')->all();

        $jumlah = [
            'calibration_sessions' => count($idSesi),
            'certificates' => count($idSertifikat),
            'raw_measurements' => DB::table('raw_measurements')->whereIn('calibration_session_id', $idSesi)->count(),
            'uncertainty_calculations' => DB::table('uncertainty_calculations')->whereIn('calibration_session_id', $idSesi)->count(),
            'certificate_email_logs' => DB::table('certificate_email_logs')->whereIn('certificate_id', $idSertifikat)->count(),
        ];

        $this->newLine();
        $this->components->info("Sesi bawaan seeder: {$jumlah['calibration_sessions']} - dipertahankan: {$dipertahankan}");
        $this->table(['Tabel', 'Baris yang akan hilang'], collect($jumlah)->map(
            fn (int $n, string $t): array => [$t, $n]
        )->values()->all());

        $nomor = Certificate::whereIn('id', $idSertifikat)->orderBy('nomor')->pluck('nomor');
        if ($nomor->isNotEmpty()) {
            $this->line('Nomor sertifikat yang dibebaskan: '.$nomor->first().' .. '.$nomor->last());
        }

        if (! $hapus) {
            $this->newLine();
            $this->components->warn('Tidak ada yang dihapus. Tambahkan --hapus DAN --cadangan untuk eksekusi.');

            return self::SUCCESS;
        }

        if ($dirCadangan === '') {
            $this->components->error('--cadangan wajib diisi saat --hapus. Tanpa SoftDeletes, ini tidak bisa ditarik balik.');

            return self::FAILURE;
        }

        // Disk `arsip` di mesin ini mungkin BUKAN disk produksi. Kalau
        // drivernya `local` sementara server memakai object storage, menyalin
        // dari sini menghasilkan cadangan KOSONG yang terlihat berhasil - dan
        // itu kegagalan paling mahal yang bisa terjadi di perintah ini.
        $driverArsip = (string) config('filesystems.disks.arsip.driver');
        if ($driverArsip === 'local' && ! $this->option('tanpa-berkas')) {
            $this->components->error('Disk `arsip` di sini drivernya `local`, jadi PDF produksi TIDAK terjangkau.');
            $this->line('Cadangan berkasnya akan kosong padahal barisnya terhapus permanen.');
            $this->line('Sediakan kredensial arsip produksi, atau sebut --tanpa-berkas kalau memang');
            $this->line('menerima PDF-nya ditinggal yatim di storage.');

            return self::FAILURE;
        }

        if (! $this->cadangkan($dirCadangan, $idSesi, $idSertifikat, $jumlah)) {
            return self::FAILURE;
        }

        // Satu transaksi: gagal di tengah berarti batal seluruhnya, bukan
        // separuh terhapus. Sertifikat WAJIB duluan - FK
        // `certificates.calibration_session_id` ber-ON DELETE NO ACTION, jadi
        // menghapus sesi lebih dulu ditolak database.
        DB::transaction(function () use ($idSertifikat, $idSesi): void {
            Certificate::whereIn('id', $idSertifikat)->delete();
            CalibrationSession::whereIn('id', $idSesi)->delete();
        });

        $sisaSesi = CalibrationSession::whereIn('id', $idSesi)->count();
        $sisaSertifikat = Certificate::whereIn('id', $idSertifikat)->count();

        if ($sisaSesi !== 0 || $sisaSertifikat !== 0) {
            $this->components->error("Masih tersisa: {$sisaSesi} sesi, {$sisaSertifikat} sertifikat.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Selesai. Cadangan ada di: '.$dirCadangan);

        return self::SUCCESS;
    }

    /**
     * Ekspor SEMUA baris yang akan hilang, lalu buktikan jumlahnya cocok.
     *
     * Diperiksa ulang sesudah ditulis, bukan diasumsikan: cadangan yang
     * ternyata kurang satu baris baru ketahuan waktu dibutuhkan - dan waktu itu
     * sudah terlambat.
     *
     * @param  list<int>  $idSesi
     * @param  list<int>  $idSertifikat
     * @param  array<string, int>  $jumlah
     */
    private function cadangkan(string $dir, array $idSesi, array $idSertifikat, array $jumlah): bool
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            $this->components->error("Direktori cadangan tidak bisa dibuat: {$dir}");

            return false;
        }

        $isi = [
            'dibuat_pada' => now()->toIso8601String(),
            'calibration_sessions' => DB::table('calibration_sessions')->whereIn('id', $idSesi)->get(),
            'certificates' => DB::table('certificates')->whereIn('id', $idSertifikat)->get(),
            'raw_measurements' => DB::table('raw_measurements')->whereIn('calibration_session_id', $idSesi)->get(),
            'uncertainty_calculations' => DB::table('uncertainty_calculations')->whereIn('calibration_session_id', $idSesi)->get(),
            'certificate_email_logs' => DB::table('certificate_email_logs')->whereIn('certificate_id', $idSertifikat)->get(),
        ];

        $berkas = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'cadangan-data-contoh-'.now()->format('Ymd-His').'.json';

        if (file_put_contents($berkas, json_encode($isi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            $this->components->error("Gagal menulis cadangan ke {$berkas}");

            return false;
        }

        $kembali = json_decode((string) file_get_contents($berkas), true);

        foreach ($jumlah as $tabel => $n) {
            $ada = is_array($kembali[$tabel] ?? null) ? count($kembali[$tabel]) : -1;
            if ($ada !== $n) {
                $this->components->error("Cadangan TIDAK cocok untuk {$tabel}: diharapkan {$n}, terbaca {$ada}.");
                $this->line('Tidak ada yang dihapus.');

                return false;
            }
        }

        $this->components->info('Cadangan terverifikasi: '.$berkas);

        return true;
    }

    /**
     * Seluruh literal string yang tertulis di seeder & data seeder repo ini.
     *
     * Dicocokkan lewat NILAI, bukan nama kunci - dan itu disengaja. Nomor
     * bergaya sesi lab tersebar di delapan kunci berbeda (`nomor_sertifikat`,
     * `nomor_sesi`, `nomor_order`, `nomor`, bahkan `serial_number`), dan daftar
     * nama kunci yang diketik di sini akan basi diam-diam begitu alat baru
     * mendarat dengan kunci kesembilan.
     *
     * Versi pertama perintah ini memang mencari kunci `nomor_sesi` saja, dan
     * langsung ditangkap testnya: seeder master menyimpan nomornya di
     * `nomor_sertifikat`, jadi SELURUH sesi contoh tertolak sebagai "tidak
     * dikenal". Arah gagalnya benar - berhenti, bukan menghapus - tapi
     * penjagaan yang menolak semuanya sama tidak bergunanya dengan yang
     * menerima semuanya.
     *
     * @return list<string>
     */
    private function nomorSesiDiSeeder(): array
    {
        $ketemu = [];

        foreach ([database_path('seeders'), database_path('data')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $berkas */
            $berkas = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($berkas as $f) {
                if (! $f->isFile() || ! in_array($f->getExtension(), ['php', 'json'], true)) {
                    continue;
                }

                $isi = (string) file_get_contents($f->getPathname());

                preg_match_all('/"([^"\n]{3,60})"|\'([^\'\n]{3,60})\'/', $isi, $cocok, PREG_SET_ORDER);

                foreach ($cocok as $m) {
                    $nilai = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                    if ($nilai !== '') {
                        $ketemu[$nilai] = true;
                    }
                }
            }
        }

        return array_keys($ketemu);
    }
}
