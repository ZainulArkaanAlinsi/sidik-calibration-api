<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Blok `kesehatan` di `GET /api/health` (paket OPS-KODE, butir 1).
 *
 * ## Untuk siapa
 *
 * Pemantau uptime luar yang memukul endpoint ini tiap 5 menit dan membaca dua
 * hal: kode HTTP dan kata kunci `"status":"ok"`. Karena itu kelas ini cuma
 * menjawab satu pertanyaan — sehat atau tidak, dan kalau tidak, kenapa (kode
 * pendek dari daftar tertutup).
 *
 * ## Aturan keputusan
 *
 *  - `db_gagal`, `penyimpanan_gagal` -> `degraded` + HTTP 503. Dua-duanya
 *    berarti server TIDAK bisa melayani pekerjaan inti (simpan data, simpan
 *    PDF), jadi pemantau kode status ikut menangkapnya.
 *  - `antrean_macet`, `sertifikat_menggantung` -> `degraded` + HTTP 200. Server
 *    masih melayani teknisi di lokasi; mengembalikan 5xx akan membuat klien
 *    mobile (yang membaca health untuk "sambungan jalan?") menganggap server
 *    mati. Kata kunci `status` cukup bagi pemantau.
 *
 * ## Batas yang membuatnya aman dibiarkan tanpa auth
 *
 * Sama dengan blok tetangganya: yang keluar hanya kata dari daftar tertutup
 * (`ok`/`gagal`/`dilewati`, kode alasan) dan angka. Nama tabel, jalur berkas,
 * dan pesan galat mentah TIDAK pernah ikut — pesan galat hanya ke log internal.
 *
 * ## Murah
 *
 * Dipukul tiap 5 menit (dan oleh siapa pun yang mengetuk URL ini): satu
 * `select 1`, satu agregat `jobs`, satu hitung `failed_jobs`, satu hitung
 * `certificates`, dan satu tulis-hapus berkas kecil di disk lokal. Semuanya
 * agregat — tidak ada baris yang ditarik ke PHP, jadi ongkosnya tidak tumbuh
 * bersama isi tabel. Di MySQL tiap agregat membawa petunjuk
 * `MAX_EXECUTION_TIME` supaya satu query lambat tidak menggantung endpoint.
 *
 * ## Yang SENGAJA tidak dilakukan
 *
 *  - Disk arsip di object storage (`s3`/R2) tidak ditulisi: satu tulis per
 *    ketukan ke bucket berbayar dari endpoint publik adalah tuas kuota, dan
 *    aturan blok-blok `health` lain adalah "nol request ke penyedia". Dilaporkan
 *    jujur sebagai `dilewati`.
 *  - Tidak menulis ke database sama sekali.
 *  - Antrean selain `database` (mis. `sync` di CI) tidak diperiksa: tidak ada
 *    yang bisa dihitung, dan tidak ada yang boleh dituduh macet.
 */
final class KesehatanLayanan
{
    public const ALASAN_DB = 'db_gagal';

    public const ALASAN_PENYIMPANAN = 'penyimpanan_gagal';

    public const ALASAN_ANTREAN = 'antrean_macet';

    public const ALASAN_SERTIFIKAT = 'sertifikat_menggantung';

    /** Alasan yang membuat endpoint menjawab 503, bukan 200. */
    private const ALASAN_BERAT = [self::ALASAN_DB, self::ALASAN_PENYIMPANAN];

    /** @var array<string, mixed>|null */
    private ?array $hasil = null;

    /**
     * Isi blok `kesehatan` untuk JSON.
     *
     * @return array<string, mixed>
     */
    public function blok(): array
    {
        return $this->periksa();
    }

    /** `ok` hanya kalau tidak ada satu pun alasan. */
    public function status(): string
    {
        return $this->periksa()['alasan'] === [] ? 'ok' : 'degraded';
    }

    /** 503 hanya untuk `db_gagal`/`penyimpanan_gagal`; yang lain 200. */
    public function kodeHttp(): int
    {
        return array_intersect($this->periksa()['alasan'], self::ALASAN_BERAT) === [] ? 200 : 503;
    }

    /**
     * Diperiksa sekali per instans; instansnya per request (tidak didaftarkan
     * sebagai singleton), jadi tidak ada hasil basi yang menyeberang request.
     *
     * @return array<string, mixed>
     */
    private function periksa(): array
    {
        if ($this->hasil !== null) {
            return $this->hasil;
        }

        $alasan = [];

        $dbHidup = $this->dbHidup();
        $penyimpanan = $this->penyimpanan();

        if (! $dbHidup) {
            $alasan[] = self::ALASAN_DB;
        }

        if ($penyimpanan === 'gagal') {
            $alasan[] = self::ALASAN_PENYIMPANAN;
        }

        $antrean = ['menunggu' => null, 'gagal' => null, 'tertua_detik' => null];
        $sertifikat = null;

        // Database mati: query berikutnya cuma menumpuk galat yang sama dan
        // memperlambat jawaban. Angkanya `null` = tidak diketahui.
        if ($dbHidup) {
            $hasilAntrean = $this->antrean();
            $antrean = $hasilAntrean['angka'];

            if ($hasilAntrean['macet']) {
                $alasan[] = self::ALASAN_ANTREAN;
            }

            $sertifikat = $this->sertifikatTertunda();

            if ($sertifikat !== null && $sertifikat > 0) {
                $alasan[] = self::ALASAN_SERTIFIKAT;
            }
        }

        return $this->hasil = [
            'db' => $dbHidup ? 'ok' : 'gagal',
            'penyimpanan' => $penyimpanan,
            'antrean' => $antrean,
            'sertifikat_tertunda' => $sertifikat,
            'alasan' => $alasan,
        ];
    }

    private function dbHidup(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable $e) {
            $this->catat('db', $e);

            return false;
        }
    }

    /**
     * Tulis-lalu-hapus satu berkas kecil di disk `arsip` — satu-satunya bukti
     * bahwa disk itu BENAR-BENAR bisa ditulis aplikasi (bukan sekadar ada).
     *
     * @return 'ok'|'gagal'|'dilewati'
     */
    private function penyimpanan(): string
    {
        // Hanya disk lokal. Lihat docblock kelas: object storage tidak ditulisi
        // dari endpoint publik.
        if (config('filesystems.disks.arsip.driver') !== 'local') {
            return 'dilewati';
        }

        try {
            $disk = Storage::disk('arsip');
            $nama = '.kesehatan-'.bin2hex(random_bytes(8)).'.tmp';
            $isi = 'ok';

            // `throw` disk arsip sengaja false (config/filesystems.php), jadi
            // kegagalan tulis bisa berupa `false`, bukan pengecualian — dua-
            // duanya dihitung gagal.
            if ($disk->put($nama, $isi) !== true) {
                return 'gagal';
            }

            $terbaca = $disk->get($nama) === $isi;
            $terhapus = $disk->delete($nama);

            return $terbaca && $terhapus ? 'ok' : 'gagal';
        } catch (Throwable $e) {
            $this->catat('penyimpanan', $e);

            return 'gagal';
        }
    }

    /**
     * @return array{angka: array{menunggu: int|null, gagal: int|null, tertua_detik: int|null}, macet: bool}
     */
    private function antrean(): array
    {
        $tidakDiketahui = ['menunggu' => null, 'gagal' => null, 'tertua_detik' => null];
        $koneksi = (string) config('queue.default');

        // CI/lokal memakai `sync`: tidak ada antrean untuk dihitung.
        if (config("queue.connections.{$koneksi}.driver") !== 'database') {
            return ['angka' => $tidakDiketahui, 'macet' => false];
        }

        try {
            $sekarang = now()->getTimestamp();

            // SATU query untuk jumlah baris DAN umur job tertua. "Tertua yang
            // belum jalan" = belum direservasi worker; yang sedang dikerjakan
            // bukan antre. Job yang dijadwalkan ke depan (`available_at` di masa
            // depan) tidak perlu disaring lagi di SQL: umurnya negatif dan
            // dipotong ke 0 di bawah, jadi tidak pernah menua-nuakan hasil.
            $jobs = $this->agregat(
                DB::connection(config("queue.connections.{$koneksi}.connection"))
                    ->table((string) config("queue.connections.{$koneksi}.table", 'jobs')),
                'count(*) as menunggu, min(case when reserved_at is null then available_at end) as tertua',
            );

            $gagalQuery = DB::connection(config('queue.failed.database'))
                ->table((string) config('queue.failed.table', 'failed_jobs'));

            // Hanya yang baru (config/kesehatan.php: gagal_jendela_jam).
            $jendela = (int) config('kesehatan.gagal_jendela_jam');
            if ($jendela > 0) {
                $gagalQuery->where('failed_at', '>=', now()->subHours($jendela));
            }

            $gagal = (int) $this->agregat($gagalQuery, 'count(*) as n')->n;
        } catch (Throwable $e) {
            $this->catat('antrean', $e);

            // Antrean `database` yang tidak bisa dibaca (tabel hilang,
            // koneksi putus) tidak bisa memproses apa pun: macet.
            return ['angka' => $tidakDiketahui, 'macet' => true];
        }

        $tertua = $jobs->tertua === null ? 0 : max(0, $sekarang - (int) $jobs->tertua);

        return [
            'angka' => [
                'menunggu' => (int) $jobs->menunggu,
                'gagal' => $gagal,
                'tertua_detik' => $tertua,
            ],
            'macet' => $tertua > (int) config('kesehatan.antrean_tertua_detik')
                || $gagal >= (int) config('kesehatan.gagal_batas'),
        ];
    }

    /**
     * Sertifikat yang tersangkut menunggu PDF. Definisinya SAMA dengan
     * `sertifikat:sapu-tertunda` (status, tanpa PDF, sesi disetujui): sertifikat
     * yang sesinya sengaja ditarik dari `disetujui` ditolak penyapu dan tidak
     * akan pernah beres sendiri — kalau dihitung di sini, status `degraded`
     * menetap dan peringatannya berhenti berarti.
     *
     * `DB::table`, bukan model: ini hitungan LINTAS organisasi tanpa pengguna
     * yang sedang masuk, dan yang keluar hanya satu angka — tidak ada baris,
     * nomor, atau nama yang bisa bocor.
     *
     * `null` = tidak terbaca (tabel belum ada); tidak dituduh menggantung.
     */
    private function sertifikatTertunda(): ?int
    {
        try {
            $menit = (int) config('kesehatan.sertifikat_menggantung_menit');

            $q = DB::table('certificates')
                ->where('status', 'menunggu_generate')
                ->whereNull('pdf_path')
                ->where('updated_at', '<', now()->subMinutes($menit))
                ->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('calibration_sessions')
                        ->whereColumn('calibration_sessions.id', 'certificates.calibration_session_id')
                        ->where('calibration_sessions.status', 'disetujui');
                });

            return (int) $this->agregat($q, 'count(*) as n')->n;
        } catch (Throwable $e) {
            $this->catat('sertifikat', $e);

            return null;
        }
    }

    /**
     * Jalankan agregat dan ambil satu baris. Di MySQL/TiDB ditambah petunjuk
     * `MAX_EXECUTION_TIME` (2 detik); driver lain mengabaikannya karena
     * petunjuk tidak ditulis sama sekali.
     *
     * @param  list<mixed>  $bindings
     */
    private function agregat(Builder $query, string $ekspresi, array $bindings = []): object
    {
        $petunjuk = $query->getConnection()->getDriverName() === 'mysql'
            ? '/*+ MAX_EXECUTION_TIME(2000) */ '
            : '';

        return $query->selectRaw($petunjuk.$ekspresi, $bindings)->first();
    }

    /**
     * Galat ke log internal saja. Respons publik tidak pernah memuatnya.
     * Pesan dipotong: pesan driver bisa panjang dan memuat jalur berkas.
     */
    private function catat(string $bagian, Throwable $e): void
    {
        try {
            Log::warning('Health: pemeriksaan gagal.', [
                'bagian' => $bagian,
                'galat' => $e::class,
                'pesan' => mb_substr($e->getMessage(), 0, 300),
            ]);
        } catch (Throwable) {
            // Log yang gagal tidak boleh menjatuhkan endpoint diagnosis.
        }
    }
}
