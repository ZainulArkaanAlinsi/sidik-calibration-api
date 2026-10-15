<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Studio\Workbook\JenisWorkbook;
use App\Services\Studio\Workbook\KunciJenisWorkbook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Merapikan isi Studio menurut K-49-13 (15 Okt 2026): satu master AKTIF per
 * jenis, versi lama jadi riwayat, olah data/pekerjaan dikeluarkan (arsip).
 * Tidak ada yang dihapus — baris, lembar, dan berkas asli tetap; tiap
 * perubahan tercatat di `audit_logs` (pelaku `--oleh`) dan alasannya
 * ditambahkan ke `catatan` workbook.
 *
 * `--oleh` WAJIB untuk perubahan (tinjau C-3 #2): tanpa itu `audit_logs.changed_by`
 * kosong — jejak perubahan status master tanpa pelaku. Hanya `--kering` dan
 * `--daftar` (tidak menyimpan apa pun) yang boleh tanpa `--oleh`.
 *
 *   php artisan studio:rapikan-workbook --daftar
 *   php artisan studio:rapikan-workbook 102 --riwayat-dari=139 --alasan="kembaran csv dari xlsm yang sama" --oleh=admin@lab.test
 *   php artisan studio:rapikan-workbook 140 --arsipkan --alasan="olah data per alat IMTE-LQ-197, bukan master" --oleh=admin@lab.test
 *   php artisan studio:rapikan-workbook 128 --aktifkan --alasan="master yang dipakai lab" --oleh=7
 *   php artisan studio:rapikan-workbook 99 --kunci-jenis=flowmeter_flowrate:ultrasonic --alasan="…" --oleh=7
 *   php artisan studio:rapikan-workbook 140 --arsipkan --alasan="…" --kering   # pratinjau, --oleh boleh kosong
 *
 *  - `--riwayat-dari=Y`: workbook ini (dan versi-versi lain di jenisnya)
 *    jadi riwayat master Y; Y diaktifkan bila belum.
 *  - `--aktifkan`: workbook ini jadi master aktif jenisnya (yang aktif jadi
 *    riwayatnya); juga memulihkan arsip.
 *  - `--arsipkan`: keluarkan dari Studio (daftar, detail, hitung → 404); bila
 *    ia master aktif, versi berikutnya di jenisnya naik.
 *  - `--kunci-jenis=K`: pindahkan ke jenis K dan jadikan master aktif di sana.
 *  - `--kering`: tampilkan hasilnya tanpa menyimpan (transaksi dibatalkan).
 */
class RapikanWorkbookStudio extends Command
{
    protected $signature = 'studio:rapikan-workbook
        {workbook? : id workbook yang dirapikan}
        {--daftar : Tampilkan semua workbook lab: id, sumber, kunci_jenis, versi, status}
        {--riwayat-dari= : id master yang menggantikan; workbook ini (dan jenisnya) jadi riwayatnya}
        {--aktifkan : Jadikan master aktif jenisnya (yang aktif jadi riwayat); memulihkan arsip}
        {--arsipkan : Keluarkan dari Studio (arsip; baris & berkas TIDAK dihapus)}
        {--kunci-jenis= : Pindahkan ke jenis ini dan jadikan master aktif di sana}
        {--alasan= : Wajib untuk perubahan; masuk catatan workbook & jejak audit}
        {--organisasi= : organization_id untuk --daftar bila lebih dari satu organisasi}
        {--oleh= : WAJIB untuk perubahan (kecuali --kering): id user atau email orang lab di organisasi workbook — pelaku di jejak audit}
        {--kering : Tampilkan hasil tanpa menyimpan}';

    protected $description = 'Rapikan Studio: satu master aktif per jenis, riwayat, arsip (tanpa menghapus apa pun)';

    public function handle(JenisWorkbook $jenis): int
    {
        if ((bool) $this->option('daftar')) {
            return $this->daftar();
        }

        $id = (string) $this->argument('workbook');
        $w = ctype_digit($id) ? WorkbookMaster::query()->find((int) $id) : null;

        if ($w === null) {
            $this->error("Workbook '{$id}' tidak ada. Lihat --daftar.");

            return self::FAILURE;
        }

        $aksi = array_filter([
            'riwayat-dari' => trim((string) $this->option('riwayat-dari')) !== '',
            'aktifkan' => (bool) $this->option('aktifkan'),
            'arsipkan' => (bool) $this->option('arsipkan'),
            'kunci-jenis' => trim((string) $this->option('kunci-jenis')) !== '',
        ]);

        if (count($aksi) !== 1) {
            $this->error('Pilih tepat satu: --riwayat-dari=<id>, --aktifkan, --arsipkan, atau --kunci-jenis=<kunci>.');

            return self::FAILURE;
        }

        $alasan = trim((string) $this->option('alasan'));

        if ($alasan === '') {
            $this->error('--alasan wajib: perubahan status master tercatat beserta alasannya.');

            return self::FAILURE;
        }

        $kering = (bool) $this->option('kering');
        $oleh = $this->penanggungJawab((int) $w->organization_id, $kering);

        if ($oleh === false) {
            return self::FAILURE;
        }

        if ($oleh !== null) {
            // Diaudit mengambil pelaku dari Auth::id(); `--oleh` sudah diadu ke lab workbook.
            Auth::setUser($oleh);
        }

        $sebelum = $this->potret((int) $w->organization_id);
        DB::beginTransaction();

        try {
            match (array_key_first($aksi)) {
                'riwayat-dari' => $jenis->jadikanRiwayat($w, $this->pengganti($w), $alasan),
                'aktifkan' => $jenis->aktifkan($w, null, $alasan),
                'arsipkan' => $jenis->arsipkan($w, $alasan),
                'kunci-jenis' => $jenis->aktifkan($w, $this->kunciTujuan(), $alasan),
            };
            $sesudah = $this->potret((int) $w->organization_id);
            $kering ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->cetakPerubahan($sebelum, $sesudah);
        $this->info($kering ? 'KERING: tidak ada yang disimpan.' : 'Tersimpan.');

        return self::SUCCESS;
    }

    private function daftar(): int
    {
        $org = $this->organisasi();

        if ($org === null) {
            return self::FAILURE;
        }

        $baris = WorkbookMaster::query()->where('organization_id', $org->id)
            ->orderBy('kunci_jenis')->orderByDesc('versi')
            ->get(['id', 'sumber', 'kunci_jenis', 'versi', 'aktif', 'digantikan_oleh_id', 'diarsipkan_pada', 'nama']);

        $this->table(
            ['id', 'sumber', 'kunci_jenis', 'versi', 'status', 'digantikan_oleh', 'nama'],
            $baris->map(static fn (WorkbookMaster $w): array => [
                $w->id, $w->sumber, $w->kunci_jenis, $w->versi, JenisWorkbook::status($w),
                $w->digantikan_oleh_id !== null ? '#'.$w->digantikan_oleh_id : '-', $w->nama,
            ])->all(),
        );

        $status = $baris->map(static fn (WorkbookMaster $w): string => JenisWorkbook::status($w))->countBy()->sortKeys();
        $this->info("Organisasi #{$org->id}: ".$baris->count().' workbook — '.$status->map(static fn (int $n, string $s): string => "{$s} {$n}")->implode(', ').'.');

        return self::SUCCESS;
    }

    private function pengganti(WorkbookMaster $w): WorkbookMaster
    {
        $id = trim((string) $this->option('riwayat-dari'));
        $p = ctype_digit($id)
            ? WorkbookMaster::query()->where('organization_id', $w->organization_id)->whereKey((int) $id)->first()
            : null;

        if ($p === null) {
            throw new \RuntimeException("--riwayat-dari '{$id}' bukan workbook di lab yang sama.");
        }

        return $p;
    }

    private function kunciTujuan(): string
    {
        $ketik = (string) $this->option('kunci-jenis');

        return KunciJenisWorkbook::normalkan($ketik)
            ?? throw new \RuntimeException("--kunci-jenis '{$ketik}' tidak sah (huruf kecil, angka, ':', '_', '.', '-').");
    }

    /** @return array<int, array{nama: string, kunci: string, versi: int, status: string, ganti: ?int}> */
    private function potret(int $organisasiId): array
    {
        $hasil = [];

        foreach (WorkbookMaster::query()->where('organization_id', $organisasiId)->get(['id', 'nama', 'kunci_jenis', 'versi', 'aktif', 'digantikan_oleh_id', 'diarsipkan_pada']) as $w) {
            $hasil[$w->id] = ['nama' => (string) $w->nama, 'kunci' => (string) $w->kunci_jenis, 'versi' => (int) $w->versi, 'status' => JenisWorkbook::status($w), 'ganti' => $w->digantikan_oleh_id];
        }

        return $hasil;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sebelum
     * @param  array<int, array<string, mixed>>  $sesudah
     */
    private function cetakPerubahan(array $sebelum, array $sesudah): void
    {
        $baris = [];
        $teks = static fn (array $r): string => "{$r['status']} · {$r['kunci']} · v{$r['versi']}".($r['ganti'] !== null ? " → #{$r['ganti']}" : '');

        foreach ($sesudah as $id => $r) {
            if (($sebelum[$id] ?? null) != $r) {
                $baris[] = [$id, $r['nama'], isset($sebelum[$id]) ? $teks($sebelum[$id]) : '-', $teks($r)];
            }
        }

        if ($baris === []) {
            $this->warn('Tidak ada yang berubah.');

            return;
        }

        $this->table(['id', 'nama', 'sebelum', 'sesudah'], $baris);
    }

    private function organisasi(): ?Organization
    {
        $opsi = (string) $this->option('organisasi');

        if ($opsi !== '') {
            $org = ctype_digit($opsi) ? Organization::query()->find((int) $opsi) : null;

            if ($org === null) {
                $this->error("Organisasi '{$opsi}' tidak ada.");
            }

            return $org;
        }

        $jumlah = Organization::query()->count();

        if ($jumlah === 1) {
            return Organization::query()->first();
        }

        $this->error($jumlah === 0 ? 'Belum ada organisasi.' : "Ada {$jumlah} organisasi — sebutkan --organisasi=<id>.");

        return null;
    }

    /**
     * Pola `studio:impor-workbook --oleh`: orang lab di organisasi workbook.
     * Wajib kecuali `--kering` (tinjau C-3 #2: jejak audit tanpa pelaku).
     *
     * @return User|null|false `false` = gagal, `null` = kosong pada mode kering
     */
    private function penanggungJawab(int $organisasiId, bool $kering): User|null|false
    {
        $opsi = trim((string) $this->option('oleh'));

        if ($opsi === '') {
            if ($kering) {
                $this->warn('--oleh tidak diisi (kering: tidak ada yang disimpan).');

                return null;
            }

            $this->error('--oleh wajib: perubahan status master tercatat di jejak audit beserta pelakunya (id user atau email orang lab). Pakai --kering untuk pratinjau tanpa menyimpan.');

            return false;
        }

        $user = User::query()
            ->where('organization_id', $organisasiId)
            ->where(ctype_digit($opsi) ? 'id' : 'email', ctype_digit($opsi) ? (int) $opsi : $opsi)
            ->first();

        if ($user === null || ! in_array($user->role, User::rolesInternal(), true)) {
            $this->error("--oleh '{$opsi}' bukan orang lab di organisasi #{$organisasiId}.");

            return false;
        }

        return $user;
    }
}
