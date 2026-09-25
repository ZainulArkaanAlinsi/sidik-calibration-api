<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Dorong ulang sertifikat yang tersangkut di `menunggu_generate` tanpa PDF.
 *
 * ## Kenapa ini WAJIB ada, bukan pelengkap
 *
 * Penerbitan sertifikat dikerjakan di antrean supaya render PDF (~17 detik di
 * CPU kecil Render) tidak menahan request approve sampai mobile kehabisan
 * waktu. Tapi antrean memindahkan satu kegagalan yang tidak berbunyi: kalau
 * `queue:work` mati — OOM di jatah 512 MB, container dibangun ulang, job hilang
 * waktu deploy — approve tetap 200 OK, sesi tetap `disetujui`, dan sertifikatnya
 * berhenti di `menunggu_generate` SELAMANYA tanpa satu pun error di mana pun.
 *
 * Yang bikin itu jalan buntu beneran: `CertificateController::retry()` cuma
 * menerima sertifikat berstatus `gagal`. Yang tersangkut di `menunggu_generate`
 * ditolak 422, jadi admin tidak punya tombol apa pun untuk memulihkannya —
 * satu-satunya jalan keluar adalah masuk ke database manual.
 *
 * Itu bukan hipotesis. 21 Sep 2026 ditemukan **9 sertifikat produksi** dalam
 * keadaan persis ini, yang tertua sejak 15 Sep, dengan `failed_jobs` nol.
 *
 * Perintah ini yang membuat keadaan macet punya yang menemukannya.
 *
 *   php artisan sertifikat:sapu-tertunda
 *   php artisan sertifikat:sapu-tertunda --kosongan     # lihat dulu, jangan dorong
 *   php artisan sertifikat:sapu-tertunda --menit=60 --batas=5
 *
 * ## Kenapa ambangnya 15 menit
 *
 * `GenerateCertificate::$timeout` 600 detik (10 menit). Ambang di bawah itu
 * berarti penyapu bisa mendorong ulang job yang sebenarnya MASIH merender, dan
 * dua job yang sama jalan bersamaan adalah justru keadaan yang penjagaan nomor
 * & token di `GenerateCertificate::handle()` ada untuk mencegah akibat
 * terburuknya. Lima belas menit memberi jarak aman tanpa bikin pemulihan terasa
 * lama.
 *
 * ## Kenapa `issued_by` & `berlaku_sampai` diwariskan dari barisnya
 *
 * `updateOrCreate` di `GenerateCertificate::handle()` menulis ULANG kedua kolom
 * itu tiap job jalan. Mendorong ulang dengan nilai kosong berarti:
 *
 *  - `issued_by` jadi `null` — catatan siapa yang menyetujui HILANG, padahal
 *    tidak ada manusia yang melakukan apa pun di sapuan ini. Jejak yang menyebut
 *    orang yang salah (atau tidak menyebut siapa pun) lebih buruk daripada tidak
 *    ada jejak.
 *  - `berlaku_sampai` dihitung ulang dari default organisasi — dan itu MENIMPA
 *    tanggal yang dipilih admin waktu approve, pada dokumen yang akan dicetak.
 *
 * Sapuan ini pemulihan teknis, bukan penerbitan baru. Dia tidak boleh mengubah
 * satu pun keputusan yang sudah diambil manusia.
 */
class SapuSertifikatTertunda extends Command
{
    protected $signature = 'sertifikat:sapu-tertunda
        {--menit=15 : umur minimum (menit) sebelum sebuah sertifikat dianggap tersangkut}
        {--batas=20 : maksimum sertifikat yang didorong ulang sekali jalan}
        {--kosongan : tampilkan temuannya saja, jangan dorong ke antrean}';

    protected $description = 'Dorong ulang sertifikat yang tersangkut di menunggu_generate tanpa PDF';

    public function handle(): int
    {
        $menit = max(1, (int) $this->option('menit'));
        $batas = max(1, (int) $this->option('batas'));
        $kosongan = (bool) $this->option('kosongan');

        // Sengaja LINTAS organisasi. Ini perintah pemeliharaan yang jalan dari
        // scheduler, bukan dari request seseorang — tidak ada organisasi yang
        // sedang "aktif" untuk disaring. Batasnya dijaga `--batas`, bukan scope.
        $tersangkut = Certificate::query()
            ->where('status', Certificate::STATUS_MENUNGGU_GENERATE)
            ->whereNull('pdf_path')
            ->where('updated_at', '<', now()->subMinutes($menit))
            // HANYA sesi yang sudah disetujui.
            //
            // `GenerateCertificate::handle()` berhenti di baris pertamanya
            // kalau sesinya bukan `disetujui`, dan berhenti TANPA SUARA -
            // job-nya selesai "sukses" tanpa mengerjakan apa pun. Tanpa
            // saringan ini, sertifikat yang sesinya dikembalikan ke
            // `menunggu_approval` didorong ulang tiap sepuluh menit
            // SELAMANYA, dan tiap dorongan menulis satu peringatan ke log.
            //
            // 21 Sep 2026 ada 6 baris produksi dalam keadaan itu. Mereka bukan
            // korban render yang putus: sistem memang menolak menerbitkan
            // sertifikat untuk sesi yang belum disetujui, dan penolakan itu
            // benar. Yang salah kalau penyapu terus mengetuk pintu yang
            // memang sengaja dikunci - lalu peringatan yang tidak berarti
            // menenggelamkan peringatan yang berarti.
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('calibration_sessions')
                    ->whereColumn('calibration_sessions.id', 'certificates.calibration_session_id')
                    ->where('calibration_sessions.status', CalibrationSession::STATUS_DISETUJUI);
            })
            ->orderBy('updated_at')
            ->limit($batas)
            ->get();

        if ($tersangkut->isEmpty()) {
            $this->components->info("Tidak ada sertifikat yang tersangkut lebih dari {$menit} menit.");

            return self::SUCCESS;
        }

        $baris = [];
        $gagal = 0;

        foreach ($tersangkut as $sertifikat) {
            $baris[] = [
                $sertifikat->id,
                $sertifikat->nomor ?? '(belum bernomor)',
                $sertifikat->calibration_session_id,
                $sertifikat->updated_at?->diffForHumans() ?? '-',
            ];

            if ($kosongan) {
                continue;
            }

            // Per baris, bukan untuk seluruh sapuan: satu dispatch yang ditolak
            // antrean dulu menghentikan perintahnya di tengah, dan baris-baris
            // sesudahnya tidak pernah dicoba. Barisnya tetap `menunggu_generate`,
            // jadi sapuan berikutnya mencobanya lagi. Dijaga `ChaosTerbitSertifikatTest`.
            try {
                GenerateCertificate::dispatch(
                    $sertifikat->calibration_session_id,
                    $sertifikat->issued_by,
                    $sertifikat->berlaku_sampai?->format('Y-m-d'),
                );
            } catch (\Throwable $e) {
                $gagal++;

                Log::error('Sertifikat tersangkut gagal didorong ulang ke antrean.', [
                    'certificate_id' => $sertifikat->id,
                    'calibration_session_id' => $sertifikat->calibration_session_id,
                    'galat' => $e->getMessage(),
                ]);

                continue;
            }

            // Dicatat karena sapuan ini jalan tanpa ada yang menontonnya. Kalau
            // satu sertifikat muncul di sini berulang kali, yang rusak bukan
            // job-nya yang kebetulan gagal sekali — dan itu cuma kelihatan dari
            // log.
            Log::warning('Sertifikat tersangkut didorong ulang ke antrean.', [
                'certificate_id' => $sertifikat->id,
                'calibration_session_id' => $sertifikat->calibration_session_id,
                'tersangkut_sejak' => $sertifikat->updated_at?->toIso8601String(),
            ]);
        }

        $this->newLine();
        $this->table(['ID', 'Nomor', 'Sesi', 'Diam sejak'], $baris);

        if ($kosongan) {
            $this->components->warn($tersangkut->count().' sertifikat tersangkut. Tidak didorong (--kosongan).');

            return self::SUCCESS;
        }

        if ($gagal > 0) {
            $this->components->error(
                "{$gagal} sertifikat gagal didorong ulang ke antrean; "
                .($tersangkut->count() - $gagal).' berhasil. Yang gagal dicoba lagi di sapuan berikutnya.',
            );

            return self::FAILURE;
        }

        $this->components->info($tersangkut->count().' sertifikat didorong ulang ke antrean.');

        return self::SUCCESS;
    }
}
