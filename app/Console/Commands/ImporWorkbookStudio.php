<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\ImporWorkbook;
use App\Services\Studio\Workbook\KunciJenisWorkbook;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use App\Services\Studio\Workbook\PenyapuTempWorkbook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Impor workbook master alat ke Sidik Studio, mode "Asli Excel" baca-saja (S1b).
 *
 *   php artisan studio:impor-workbook --csv --oleh=admin@lab.test
 *   php artisan studio:impor-workbook --csv --kering                         # pratinjau, --oleh boleh kosong
 *   php artisan studio:impor-workbook --csv --ganti --oleh=admin@lab.test   # ganti impor csv lama bernama sama
 *   php artisan studio:impor-workbook --xlsm="C:\Users\...\Downloads" --oleh=7  # folder, rekursif
 *   php artisan studio:impor-workbook --xlsm="C:\...\Master Olah Data*.xlsm" --ganti --oleh=7
 *   php artisan studio:impor-workbook --xlsm="C:\...\Micrometer 0-25 2027.xlsm" --gantikan=106 --oleh=7
 *
 * Satu master AKTIF per jenis (K-49-13, 15 Okt 2026): impor di jenis yang
 * sudah punya master aktif MENGGANTINYA — yang lama jadi riwayat tersembunyi
 * (tidak dihapus). Jenis = `kunci_jenis` dari nama (`KunciJenisWorkbook`).
 * Bila nama master baru berbeda dari yang lama, sebutkan jenisnya:
 * `--gantikan=<id workbook>` (jenis workbook itu) atau `--kunci-jenis=<kunci>`
 * — keduanya hanya untuk SATU workbook per perintah. Isi (sha256) yang sudah
 * ada di lab tidak pernah diimpor dua kali. csv tidak menggantikan xlsm aktif.
 * Merapikan yang sudah ada: `studio:rapikan-workbook`.
 *
 * `--oleh=<id user|email>`: penanggung jawab impor — mengisi `diimpor_oleh` dan
 * pelaku di `audit_logs` (pola `customers:impor --oleh`). Orang lab (bukan
 * pelanggan) di organisasi tujuan. WAJIB kecuali `--kering` (tinjau C-3 #2):
 * impor mengubah data (baris baru, master lama jadi riwayat, `--ganti`) dan
 * jejak audit tanpa pelaku tidak diterima. Tidak pernah ditebak.
 *
 * `--ganti`: baris lama dengan SUMBER yang sama (csv↔csv, xlsm↔xlsm), nama
 * sama, dan lab yang sama DIGANTIKAN hasil impor baru, satu transaksi per
 * workbook — dipakai sesudah `KanonikWorkbook::FORMAT` naik. Baris lama dan
 * lembarnya TIDAK dihapus (ADR-49-07): jadi riwayat penggantinya
 * (`digantikan_oleh_id`), tercatat `diubah` di audit. Untuk xlsm,
 * berkas yang byte-nya sudah pernah diimpor ikut diekstrak ulang (sha xlsm =
 * byte berkas, tidak ikut format). Tidak pernah menyeberang sumber.
 *
 * Data pelanggan (K-49-12, pemilik 13 Okt 2026): workbook ditampilkan APA
 * ADANYA, termasuk isi DATABASE / SERTIFIKAT / konsep dan blok identitas
 * pelanggan di INPUT DATA, untuk sumber csv DAN xlsm. Penahan lama
 * `--saya-paham-data-pelanggan` dicabut. Batasnya di penyimpanan: DB dan disk
 * privat di luar repo, dan API mengurung isinya per organisasi (404 lab lain).
 *
 * `--csv`: tiap folder yang berisi CSV di bawah `--akar` (bawaan
 * `Project-PT-Sidik/alat-alat-Pt-Sidik`, rekursif) = satu workbook; nama =
 * nama folder.
 *
 * `--xlsm`: berkas `.xlsm` ATAU `.xlsx`. Sandi diminta SEKALI lewat prompt
 * tersembunyi (`secret()`) — HANYA bila ada berkas yang bersandi (paket OLE);
 * `.xlsx`/`.xlsm` tanpa sandi tidak memintanya (tahap C). Sandi dipakai untuk
 * semua berkas, tidak pernah dicetak, dilog, atau disimpan. Sandi salah pada
 * satu berkas dilaporkan lalu lanjut ke berkas berikutnya. Berkas asli bersandi
 * disimpan apa adanya; berkas tanpa sandi disimpan terenkripsi kunci aplikasi
 * (`.enc`) di disk privat (K-49-11, tahap C). Berkas kunci Excel `~$*`
 * dilewati.
 *
 * Idempoten per sha256 per lab. `--kering` membaca & meringkas tanpa menulis
 * apa pun (DB maupun disk).
 */
class ImporWorkbookStudio extends Command
{
    protected $signature = 'studio:impor-workbook
        {--csv : Impor semua folder CSV ekspor workbook master}
        {--xlsm=* : Berkas .xlsm/.xlsx, folder (rekursif), atau pola glob}
        {--akar= : Akar folder CSV (bawaan base_path("Project-PT-Sidik/alat-alat-Pt-Sidik"))}
        {--organisasi= : organization_id tujuan; wajib bila ada lebih dari satu organisasi}
        {--oleh= : WAJIB kecuali --kering: penanggung jawab impor, id user atau email orang lab di organisasi tujuan}
        {--ganti : Ekstrak ulang di tempat baris lama ber-sumber sama (csv/xlsm) & nama sama (sesudah format kanonik naik)}
        {--gantikan= : id workbook yang digantikan master baru ini (jenisnya dipakai; satu workbook saja)}
        {--kunci-jenis= : kunci jenis master ini, mis. "micrometer:micrometer-0-25mm" (satu workbook saja)}
        {--kering : Baca & ringkas saja, tanpa menulis}';

    protected $description = 'Impor workbook master alat (CSV repo, .xlsm/.xlsx bersandi atau tanpa sandi) ke Sidik Studio — baca-saja, idempoten';

    public function handle(ImporWorkbook $impor, PenyapuTempWorkbook $penyapu): int
    {
        $csv = (bool) $this->option('csv');
        /** @var list<string> $xlsm */
        $xlsm = array_values(array_filter((array) $this->option('xlsm'), static fn ($p): bool => is_string($p) && $p !== ''));

        if ($csv === ($xlsm !== [])) {
            $this->error('Pilih salah satu: --csv ATAU --xlsm=<berkas|folder|pola>.');

            return self::FAILURE;
        }

        $organisasi = $this->organisasi();

        if ($organisasi === null) {
            return self::FAILURE;
        }

        $kering = (bool) $this->option('kering');
        $oleh = $this->penanggungJawab($organisasi, $kering);

        if ($oleh === false) {
            return self::FAILURE;
        }

        $this->line("Organisasi #{$organisasi->id} {$organisasi->nama}".($kering ? ' — KERING (tidak menulis apa pun)' : ''));

        // `Diaudit` mengambil pelaku dari `Auth::id()`, yang di baris perintah
        // selalu kosong. `--oleh` sudah diadu ke organisasinya di atas, jadi ini
        // penerusan, bukan klaim baru (pola `customers:impor`).
        if ($oleh !== null) {
            Auth::setUser($oleh);
        }

        $ganti = (bool) $this->option('ganti');
        $kunci = $this->kunciJenis($organisasi, $ganti);

        if ($kunci === false) {
            return self::FAILURE;
        }

        return $csv
            ? $this->imporCsv($impor, $organisasi, $oleh?->id, $kering, $ganti, $kunci)
            : $this->imporXlsm($impor, $penyapu, $organisasi, $oleh?->id, $xlsm, $kering, $ganti, $kunci);
    }

    /**
     * `--gantikan` / `--kunci-jenis` → kunci jenis untuk satu workbook.
     *
     * @return string|null|false `false` = gagal, `null` = dari nama
     */
    private function kunciJenis(Organization $organisasi, bool $ganti): string|null|false
    {
        $gantikan = trim((string) $this->option('gantikan'));
        $ketik = trim((string) $this->option('kunci-jenis'));

        if ($gantikan === '' && $ketik === '') {
            return null;
        }

        if ($ganti) {
            $this->error('--ganti (ekstrak ulang di tempat) tidak bisa digabung dengan --gantikan/--kunci-jenis.');

            return false;
        }

        $kunci = null;

        if ($ketik !== '') {
            $kunci = KunciJenisWorkbook::normalkan($ketik);

            if ($kunci === null) {
                $this->error("--kunci-jenis '{$ketik}' tidak sah (huruf kecil, angka, ':', '_', '.', '-'; maks ".KunciJenisWorkbook::PANJANG_MAKS.').');

                return false;
            }
        }

        if ($gantikan !== '') {
            $lama = ctype_digit($gantikan)
                ? WorkbookMaster::query()->where('organization_id', $organisasi->id)->whereKey((int) $gantikan)->first()
                : null;

            if ($lama === null || $lama->diarsipkan_pada !== null) {
                $this->error("--gantikan '{$gantikan}' bukan workbook di Studio organisasi #{$organisasi->id}.");

                return false;
            }

            if ($kunci !== null && $kunci !== $lama->kunci_jenis) {
                $this->error("--kunci-jenis '{$kunci}' bertentangan dengan jenis workbook #{$lama->id} ({$lama->kunci_jenis}).");

                return false;
            }

            $kunci = (string) $lama->kunci_jenis;
        }

        $this->line("Jenis master: {$kunci}");

        return $kunci;
    }

    private function imporCsv(ImporWorkbook $impor, Organization $organisasi, ?int $userId, bool $kering, bool $ganti, ?string $kunci = null): int
    {
        $akar = (string) ($this->option('akar') ?: base_path('Project-PT-Sidik/alat-alat-Pt-Sidik'));

        if (! is_dir($akar)) {
            $this->error("Akar CSV tidak ada: {$akar}");

            return self::FAILURE;
        }

        $folder = $this->folderCsv($akar);

        if ($folder === []) {
            $this->warn("Tidak ada folder berisi CSV di bawah {$akar}.");

            return self::SUCCESS;
        }

        if ($kunci !== null && count($folder) !== 1) {
            $this->error('--gantikan/--kunci-jenis hanya untuk SATU workbook; '.count($folder).' folder CSV ditemukan (arahkan --akar ke satu folder).');

            return self::FAILURE;
        }

        $baris = [];
        $gagal = 0;

        foreach ($folder as $jalur) {
            $relatif = ltrim(str_replace('\\', '/', substr($jalur, strlen($akar))), '/');

            try {
                $baris[] = $impor->imporCsv($jalur, basename($jalur), $relatif, $organisasi, $userId, $kering, $ganti, $kunci);
            } catch (GagalBacaWorkbook $e) {
                // Utuh, tidak dipotong: pesan struktur_master_berubah menyebut
                // satu per satu apa yang hilang/bergeser.
                $gagal++;
                $this->error("[{$relatif}] {$e->kode}: ".$e->getMessage());
            } catch (Throwable $e) {
                $gagal++;
                $this->error("[{$relatif}] gagal: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        $this->cetak($baris);

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $pola
     */
    private function imporXlsm(ImporWorkbook $impor, PenyapuTempWorkbook $penyapu, Organization $organisasi, ?int $userId, array $pola, bool $kering, bool $ganti, ?string $kunci = null): int
    {
        // Sisa impor lama yang mati di tengah jalan (> 1 jam) disapu dulu.
        $sapu = $penyapu->sapu();

        if ($sapu['dihapus'] > 0 || $sapu['gagal'] > 0) {
            $this->warn("Temp workbook yatim: {$sapu['dihapus']} disapu, {$sapu['gagal']} gagal.");
        }

        // Tahap C: folder gambar sisa `--ganti`/impor batal (> 1 jam, tanpa baris).
        $gambar = $penyapu->sapuGambarYatim();

        if ($gambar['dihapus'] > 0 || $gambar['gagal'] > 0) {
            $this->warn("Folder gambar yatim: {$gambar['dihapus']} disapu, {$gambar['gagal']} gagal.");
        }

        $berkas = $this->berkasXlsm($pola);

        if ($berkas === []) {
            $this->warn('Tidak ada berkas .xlsm/.xlsx yang cocok.');

            return self::SUCCESS;
        }

        $this->line(count($berkas).' berkas ditemukan.');

        if ($kunci !== null && count($berkas) !== 1) {
            $this->error('--gantikan/--kunci-jenis hanya untuk SATU workbook; '.count($berkas).' berkas cocok.');

            return self::FAILURE;
        }

        // Workbook lab nyata butuh lebih dari 128M bawaan CLI.
        $this->naikkanMemori('1024M');

        // Sandi hanya ditanya bila ada berkas bersandi (paket OLE).
        $perluSandi = array_filter($berkas, static fn (string $f): bool => PembacaXlsmWorkbook::bersandi($f)) !== [];
        $sandi = $perluSandi ? (string) $this->secret('Sandi workbook (tidak ditampilkan, tidak disimpan)') : '';

        $baris = [];
        $gagal = 0;

        try {
            foreach ($berkas as $jalur) {
                $label = basename($jalur);

                try {
                    $baris[] = $impor->imporXlsm($jalur, $sandi, $organisasi, $userId, $kering, $ganti, $kunci);
                } catch (GagalBacaWorkbook $e) {
                    $gagal++;
                    // Pesan GagalBacaWorkbook tidak pernah memuat sandi; tetap
                    // disensor sebagai jaring kedua.
                    $this->error("[{$label}] {$e->kode}: ".$this->sensor($e->getMessage(), $sandi));
                } catch (Throwable $e) {
                    $gagal++;
                    $this->error("[{$label}] gagal: ".$this->sensor(mb_substr($e->getMessage(), 0, 200), $sandi));
                }
            }
        } finally {
            // Upaya terbaik: PHP tidak menjamin string terhapus dari memori.
            unset($sandi);
        }

        $this->cetak($baris);

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $baris */
    private function cetak(array $baris): void
    {
        if ($baris === []) {
            return;
        }

        $this->table(
            ['status', 'id', 'nama', 'sumber', 'kunci_jenis', 'versi', 'jenis', 'menggantikan', 'lembar', 'sel', 'rumus', 'dikecualikan', 'diganti'],
            array_map(static fn (array $b): array => [
                $b['status'], $b['id'] ?? '-', $b['nama'], $b['sumber'], $b['kunci_jenis'], $b['versi'] ?? '-', $b['status_jenis'] ?? '-',
                $b['menggantikan'] !== null ? '#'.$b['menggantikan'] : '-',
                $b['lembar'], $b['sel'], $b['rumus'], $b['dikecualikan'], $b['diganti'],
            ], $baris),
        );

        foreach ($baris as $b) {
            if (($b['peta_sel'] ?? null) === null) {
                continue;
            }

            $acuan = $b['acuan'] ?? null;
            $this->line(sprintf(
                '  %s — peta sel: %s%s',
                $b['nama'],
                $b['peta_sel'],
                $acuan === null ? '' : '; acuan: '.$acuan['status']
                    .(isset($acuan['versi_id']) ? ' #'.$acuan['versi_id'] : '')
                    .(isset($acuan['isi_sha256']) ? ' (isi sha256 '.$acuan['isi_sha256'].', dasar: '.$acuan['dasar'].')' : ''),
            ));
        }

        $jumlah = static fn (string $k): int => (int) array_sum(array_column($baris, $k));
        $perStatus = array_count_values(array_column($baris, 'status'));
        ksort($perStatus);

        $this->info(sprintf(
            'Total: %d workbook (%s) — %d lembar, %d sel, %d rumus, %d lembar dikecualikan; kode_profil null: %d; baris lama diganti: %d.',
            count($baris),
            implode(', ', array_map(static fn (string $s, int $n): string => "{$s} {$n}", array_keys($perStatus), $perStatus)),
            $jumlah('lembar'),
            $jumlah('sel'),
            $jumlah('rumus'),
            $jumlah('dikecualikan'),
            count(array_filter($baris, static fn (array $b): bool => $b['kode_profil'] === null)),
            $jumlah('diganti'),
        ));
    }

    /** @return list<string> folder yang langsung memuat ≥ 1 CSV, urut */
    private function folderCsv(string $akar): array
    {
        $hasil = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile() && strtolower($info->getExtension()) === 'csv') {
                $hasil[$info->getPath()] = true;
            }
        }

        $hasil = array_keys($hasil);
        sort($hasil, SORT_STRING);

        return $hasil;
    }

    /**
     * @param  list<string>  $pola
     * @return list<string>
     */
    private function berkasXlsm(array $pola): array
    {
        $hasil = [];

        foreach ($pola as $p) {
            if (is_dir($p)) {
                $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS));

                foreach ($iter as $info) {
                    /** @var \SplFileInfo $info */
                    if ($info->isFile()) {
                        $hasil[] = $info->getPathname();
                    }
                }

                continue;
            }

            foreach (is_file($p) ? [$p] : (glob($p) ?: []) as $f) {
                $hasil[] = $f;
            }
        }

        $hasil = array_values(array_unique(array_filter($hasil, static function (string $f): bool {
            return is_file($f)
                && preg_match('/\.(xlsm|xlsx)$/i', $f) === 1
                && ! str_starts_with(basename($f), '~$');
        })));
        sort($hasil, SORT_STRING);

        return $hasil;
    }

    private function organisasi(): ?Organization
    {
        $opsi = $this->option('organisasi');

        if ($opsi !== null && $opsi !== '') {
            if (! ctype_digit((string) $opsi)) {
                $this->error("--organisasi harus organization_id (angka), diberi '{$opsi}'.");

                return null;
            }

            $org = Organization::query()->find((int) $opsi);

            if ($org === null) {
                $this->error("Organisasi #{$opsi} tidak ada.");
            }

            return $org;
        }

        $jumlah = Organization::query()->count();

        if ($jumlah === 1) {
            return Organization::query()->first();
        }

        $this->error($jumlah === 0
            ? 'Belum ada organisasi.'
            : "Ada {$jumlah} organisasi — sebutkan tujuan dengan --organisasi=<id>.");

        return null;
    }

    /**
     * `--oleh`: id user atau email, orang lab di organisasi tujuan. Wajib
     * kecuali `--kering` (tinjau C-3 #2).
     *
     * @return User|null|false `false` = gagal, `null` = kosong pada mode kering
     */
    private function penanggungJawab(Organization $organisasi, bool $kering): User|null|false
    {
        $opsi = trim((string) $this->option('oleh'));

        if ($opsi === '') {
            // Tidak ditebak ke admin mana pun: penanggung jawab yang dikarang
            // lebih buruk daripada yang kosong — jadi impor yang menulis ditolak.
            if ($kering) {
                $this->warn('--oleh tidak diisi (kering: tidak ada yang ditulis).');

                return null;
            }

            $this->error('--oleh wajib: impor mengubah data Studio dan tercatat di jejak audit beserta pelakunya (id user atau email orang lab). Pakai --kering untuk pratinjau tanpa menulis.');

            return false;
        }

        $user = User::query()
            ->where('organization_id', $organisasi->id)
            ->where(ctype_digit($opsi) ? 'id' : 'email', ctype_digit($opsi) ? (int) $opsi : $opsi)
            ->first();

        if ($user === null) {
            $this->error("--oleh '{$opsi}' tidak ada di organisasi #{$organisasi->id}.");

            return false;
        }

        if (! in_array($user->role, User::rolesInternal(), true)) {
            $this->error("--oleh '{$opsi}' bukan orang lab (peran {$user->role}).");

            return false;
        }

        return $user;
    }

    private function naikkanMemori(string $target): void
    {
        $sekarang = (string) ini_get('memory_limit');

        if ($sekarang === '-1') {
            return;
        }

        $byte = static function (string $nilai): int {
            $angka = (int) $nilai;

            return match (strtoupper(substr(trim($nilai), -1))) {
                'G' => $angka * 1024 ** 3,
                'M' => $angka * 1024 ** 2,
                'K' => $angka * 1024,
                default => $angka,
            };
        };

        if ($byte($sekarang) < $byte($target)) {
            ini_set('memory_limit', $target);
        }
    }

    private function sensor(string $pesan, #[\SensitiveParameter] string $sandi): string
    {
        return $sandi === '' ? $pesan : str_replace($sandi, '***', $pesan);
    }
}
