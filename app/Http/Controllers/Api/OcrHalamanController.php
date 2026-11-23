<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\Vision\GoogleVisionOcr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OCR awan untuk SATU foto halaman lembar kerja lewat Google Cloud Vision
 * (DOCUMENT_TEXT_DETECTION). Endpoint: `POST /api/ocr/halaman`.
 *
 * ## Apa yang dikembalikan
 *
 * Teks MENTAH beserta koordinatnya (blok dan kata, kotak dinormalkan 0..1) dan
 * keyakinan bila Google memberikannya. Tidak ada nilai sel yang diputuskan di
 * sini: memetakan teks ke sel formulir adalah tahap berikutnya, dan hasil
 * akhirnya tetap USULAN yang dikonfirmasi teknisi (sel kosong tetap kosong,
 * tulisan tangan tidak pernah hijau). Hasilnya tidak disimpan.
 *
 * ## Gerbang (semuanya harus terbuka)
 *
 *  1. env `GOOGLE_VISION_AKTIF` (bawaan MATI) — menyalakannya keputusan pemilik;
 *  2. saklar Super Admin `ocr_cloud` (`fitur:ocr_cloud` di rute) — kill switch
 *     seketika tanpa deploy.
 *
 * Sengaja TIDAK menumpang `VISION_AKTIF`: menyalakan OCR awan tidak boleh ikut
 * menyalakan jalur LLM yang mengirim foto ke Gemini/OpenAI (K-50-11).
 *
 * ## Audit
 *
 * Tiap percobaan masuk `worksheet_extraction_logs`: waktu, unit, ukuran gambar,
 * durasi, status. TIDAK ada gambar dan TIDAK ada teks hasil di log.
 *
 * ## Batas per lembar
 *
 * Dihitung per sesi kerja (`calibration_session_id`) dari baris log yang sampai
 * ke Google. Foto yang dibuat SEBELUM sesi ada (alur kamera dari beranda) tidak
 * punya kunci per lembar, jadi hanya terkena limiter per akun `ocr-halaman`.
 */
class OcrHalamanController extends Controller
{
    public function baca(Request $request, GoogleVisionOcr $ocr): JsonResponse
    {
        $data = $request->validate([
            // Pagar kasar saja (20 MB). Batas OCR awan sebenarnya `GOOGLE_VISION_MAKS_BYTE`
            // dicek di bawah supaya jawabannya membawa `kode` dan tercatat di log; kalau
            // `max` di sini sama dengan batas itu, cabang tersebut tidak pernah terjangkau.
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
            'calibration_session_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'foto.required' => 'Fotonya wajib ada.',
            'foto.image' => 'File-nya harus berupa gambar.',
            'foto.max' => 'Foto maksimal 20 MB.',
        ]);

        $user = $request->user();
        $sesi = $this->sesiTervalidasi($data['calibration_session_id'] ?? null, $user);

        $file = $request->file('foto');
        $ukuran = (int) $file->getSize();

        if (! (bool) config('services.google_vision.aktif', false)) {
            return $this->tolak(
                $sesi, $user, 'dimatikan', $ukuran,
                'OCR awan dimatikan di server (`GOOGLE_VISION_AKTIF=false`).',
                'OCR awan belum diaktifkan di server. Foto dibaca di HP atau isi manual.',
                503, 'ocr_cloud_belum_aktif',
            );
        }

        if ($ukuran > (int) config('services.google_vision.maks_byte')) {
            return $this->tolak(
                $sesi, $user, 'ditolak', $ukuran,
                'Ukuran foto melewati batas OCR awan.',
                'Fotonya terlalu besar untuk OCR awan. Foto ulang dengan resolusi lebih kecil.',
                422, 'foto_terlalu_besar',
            );
        }

        if ($sesi !== null && $this->sudahMencapaiBatas($sesi)) {
            return $this->tolak(
                $sesi, $user, 'dibatasi', $ukuran,
                'Batas panggilan OCR awan per lembar tercapai.',
                'Batas pembacaan awan untuk lembar ini sudah habis. Isi manual atau baca di HP.',
                429, 'batas_per_lembar',
            );
        }

        if ($sesi !== null && $this->terlaluBanyakGagal($sesi)) {
            // Idempoten: bila penulisan sinyal di kegagalan terakhir sempat gagal, panggilan
            // berikutnya yang ditolak di sini melengkapinya, supaya admin tidak kehilangan sinyal.
            $this->catatPenghentian($sesi, $user);

            return $this->tolak(
                $sesi, $user, 'dibatasi', $ukuran,
                'Batas kegagalan OCR awan per lembar tercapai.',
                'OCR awan gagal berulang untuk lembar ini, jadi dihentikan. Foto dibaca di HP atau isi manual.',
                429, 'gagal_berulang_per_lembar',
            );
        }

        $hasil = $ocr->bacaHalaman((string) file_get_contents($file->getRealPath()));

        // Catat SETIAP percobaan yang sampai sini. `belum_disetel` tidak pernah
        // sampai ke Google, jadi `unit_vision` NULL dan tidak memakan jatah lembar.
        WorksheetExtractionLog::create([
            'calibration_session_id' => $sesi?->id,
            'user_id' => $user->id,
            'model' => GoogleVisionOcr::MODEL,
            'status' => $hasil['ok'] ? 'sukses' : $hasil['status'],
            'error' => $hasil['error'],
            'unit_vision' => $hasil['status'] === 'belum_disetel' ? null : $hasil['unit'],
            'ukuran_gambar_byte' => $ukuran,
            'durasi_ms' => $hasil['durasi_ms'],
        ]);

        // Sinyal untuk admin (log server dan riwayat audit). Gagal melapor TIDAK boleh mengubah
        // jawaban ke teknisi: pembacaan ini sudah punya jalur cadangan ke HP atau isian manual.
        try {
            $this->laporkanKeAdmin($hasil, $sesi, $user);
        } catch (Throwable $e) {
            report($e);
        }

        if (! $hasil['ok']) {
            // Kalimat TETAP per kode, bukan `$hasil['error']`: teks itu untuk log server dan
            // bisa memuat nama env atau potongan jawaban Google yang tidak untuk teknisi.
            [$kode, $mesin, $pesan] = match ($hasil['status']) {
                'belum_disetel' => [503, 'ocr_cloud_belum_disetel', 'OCR awan belum disetel di server. Foto dibaca di HP atau isi manual.'],
                'ditolak' => [422, 'gambar_tidak_terbaca', 'Google tidak bisa membaca gambar ini. Foto ulang lebih jelas, atau isi manual.'],
                default => [502, 'ocr_cloud_gagal', 'OCR awan sedang tidak bisa dipakai. Foto dibaca di HP atau isi manual.'],
            };

            return response()->json([
                'message' => $pesan,
                'kode' => $mesin,
                'fallback_manual' => true,
            ], $kode);
        }

        return response()->json([
            'halaman' => $hasil['halaman'],
            'blok' => $hasil['blok'],
            'kata' => $hasil['kata'],
            'meta' => [
                'sumber' => 'google_vision',
                'fitur' => GoogleVisionOcr::FITUR,
                'unit' => $hasil['unit'],
                'jumlah_halaman' => $hasil['jumlah_halaman'],
                // Selalu USULAN: tidak ada nilai sel yang diputuskan di sini.
                'usulan' => true,
                'terpotong' => $hasil['terpotong'],
            ],
        ]);
    }

    /**
     * Batas per lembar (berhasil dan gagal) dihitung dari baris log, TANPA kunci: dua
     * permintaan yang masuk bersamaan pada sesi yang sama bisa sama-sama membaca "belum
     * penuh" dan sama-sama lolos, jadi total panggilan bisa melewati `maks_per_sesi` atau
     * `maks_gagal_per_sesi` sebanyak jumlah permintaan yang berebut. Diterima sadar: kunci
     * per sesi butuh `Cache::lock` yang tahan beberapa worker, dan taruhannya beberapa unit
     * tagihan, bukan data. Kelebihannya sebanyak permintaan yang masih berjalan bersamaan
     * per akun (baris log baru ditulis SETELAH panggilan selesai, bisa puluhan detik);
     * limiter `ocr-halaman` (6/menit) membatasi laju, bukan kelebihan. Jangan membaca
     * batas ini sebagai tepat.
     *
     * Nilai config di bawah 1 (env kosong atau 0) dipaksa 1: `count >= 0` selalu benar dan
     * akan menolak SEMUA panggilan bersesi tanpa satu pun panggilan terjadi.
     */
    private function sudahMencapaiBatas(CalibrationSession $sesi): bool
    {
        $maks = max(1, (int) config('services.google_vision.maks_per_sesi'));

        return WorksheetExtractionLog::query()
            ->where('calibration_session_id', $sesi->id)
            ->where('model', GoogleVisionOcr::MODEL)
            // Hanya panggilan yang menghasilkan unit tertagih. Galat Google (waktu habis,
            // 4xx/5xx) bernilai 0 dan tidak boleh mengunci lembar dengan pesan "jatah habis".
            ->where('unit_vision', '>', 0)
            ->count() >= $maks;
    }

    /** Batas kegagalan per lembar; di bawah 1 dipaksa 1 (lihat catatan di [sudahMencapaiBatas]). */
    private function batasGagal(): int
    {
        return max(1, (int) config('services.google_vision.maks_gagal_per_sesi'));
    }

    /**
     * Sinyal untuk admin bahwa konfigurasi OCR awan kemungkinan salah, supaya tidak baru
     * ketahuan dari teknisi yang mengeluh. Semuanya TANPA pesan Google dan tanpa rahasia: yang
     * dipakai hanya teks yang sudah dicatat di log (kalimat tetap, atau status HTTP + kode
     * status Google).
     *
     *  - `belum_disetel` (kredensial kosong/rusak, token ditolak): log server level error,
     *    dibatasi satu kali per 10 menit supaya log tidak banjir bila fitur nyala dan salah setel;
     *  - kegagalan tanpa sesi kerja (alur kamera dari beranda tidak punya batas per lembar):
     *    log server level warning, dibatasi satu kali per 10 menit per organisasi;
     *  - kegagalan Google yang membuat satu lembar mencapai batas kegagalan: log server level
     *    warning dan SATU baris `audit_logs` per sesi (`ocr_dihentikan`) yang terbaca admin lewat
     *    riwayat perubahan ([catatPenghentian]). Ringkasan terkini (khusus admin) ada di
     *    `GET /api/admin/ocr-cloud`.
     *
     * @param  array<string, mixed>  $hasil
     */
    private function laporkanKeAdmin(array $hasil, ?CalibrationSession $sesi, User $user): void
    {
        if ($hasil['ok']) {
            return;
        }

        if ($hasil['status'] === 'belum_disetel') {
            if (Cache::add('ocr_cloud.log_belum_disetel', 1, 600)) {
                Log::error('OCR awan aktif tetapi belum disetel benar di server.', [
                    'sebab' => $hasil['error'],
                    'organization_id' => $user->organization_id,
                ]);
            }

            return;
        }

        if ($sesi === null) {
            if (Cache::add('ocr_cloud.log_gagal_tanpa_sesi.'.$user->organization_id, 1, 600)) {
                Log::warning('OCR awan gagal pada foto tanpa sesi kerja.', [
                    'sebab' => $hasil['error'],
                    'organization_id' => $user->organization_id,
                ]);
            }

            return;
        }

        $this->catatPenghentian($sesi, $user);
    }

    /**
     * Tulis sinyal "OCR awan dihentikan untuk lembar ini" bila batas kegagalan sudah tercapai.
     * IDEMPOTEN (satu baris `audit_logs` per sesi) dan tidak pernah melempar: dipanggil dari dua
     * tempat, yaitu sesudah kegagalan yang mencapai batas dan dari cabang penolakan `429`, supaya
     * sinyal yang gagal ditulis di kali pertama tetap terlengkapi. Dua permintaan yang berebut
     * bisa menghasilkan baris ganda (tidak ada unique); itu kosmetik dan diterima.
     */
    private function catatPenghentian(CalibrationSession $sesi, User $user): void
    {
        try {
            $sudahTercatat = AuditLog::query()
                ->where('entity_type', 'ocr_cloud_sesi')
                ->where('entity_id', $sesi->id)
                ->where('action', AuditLog::ACTION_OCR_DIHENTIKAN)
                ->exists();
            if ($sudahTercatat) {
                return;
            }

            $sebab = [];
            $perJenis = [];
            $baris = WorksheetExtractionLog::query()
                ->where('calibration_session_id', $sesi->id)
                ->where('model', GoogleVisionOcr::MODEL)
                ->where('unit_vision', 0)
                ->selectRaw('status, error, count(*) as jumlah')
                ->groupBy('status', 'error')
                ->get();
            foreach ($baris as $b) {
                $n = (int) $b->jumlah;
                $sebab[(string) $b->error] = ($sebab[(string) $b->error] ?? 0) + $n;
                $perJenis[(string) $b->status] = ($perJenis[(string) $b->status] ?? 0) + $n;
            }

            $maks = $this->batasGagal();
            if (array_sum($perJenis) < $maks) {
                return;
            }

            // `ditolak` = Google tidak bisa membaca FOTO ini (kualitas gambar), bukan konfigurasi
            // yang salah. Petunjuk "periksa kredensial dan jaringan" hanya bila ada kegagalan
            // `gagal` (waktu habis, 4xx/5xx, token), supaya tiga foto buram tidak menjadi alarm palsu.
            $note = ($perJenis['gagal'] ?? 0) > 0
                ? "OCR awan dihentikan untuk lembar ini setelah {$maks} kegagalan Google. "
                    .'Periksa kredensial, izin API Vision, dan jaringan server (lihat GET /api/admin/ocr-cloud).'
                : "OCR awan dihentikan untuk lembar ini karena Google tidak bisa membaca fotonya {$maks} kali. "
                    .'Kemungkinan kualitas foto, bukan konfigurasi.';

            AuditLog::create([
                'organization_id' => $user->organization_id,
                'entity_type' => 'ocr_cloud_sesi',
                'entity_id' => $sesi->id,
                'action' => AuditLog::ACTION_OCR_DIHENTIKAN,
                'new_data' => ['batas' => $maks, 'jenis' => $perJenis, 'sebab' => $sebab],
                'changed_by' => $user->id,
                'note' => $note,
            ]);

            Log::warning('OCR awan dihentikan untuk satu lembar karena kegagalan Google berulang.', [
                'calibration_session_id' => $sesi->id,
                'organization_id' => $user->organization_id,
                'batas' => $maks,
                'jenis' => $perJenis,
                'sebab' => $sebab,
            ]);
        } catch (Throwable $e) {
            // Melapor tidak boleh mengubah jawaban ke teknisi (ada jalur cadangan ke HP / isian manual).
            report($e);
        }
    }

    /**
     * Sudah berapa kali panggilan untuk lembar ini sampai ke Google tetapi gagal. Hanya
     * `unit_vision` = 0: penolakan lokal (foto terlalu besar, jatah habis, env mati) bernilai
     * NULL dan tidak ikut, jadi salah kirim di sisi kita tidak menghabiskan jatah gagal.
     */
    private function terlaluBanyakGagal(CalibrationSession $sesi): bool
    {
        $maks = $this->batasGagal();

        return WorksheetExtractionLog::query()
            ->where('calibration_session_id', $sesi->id)
            ->where('model', GoogleVisionOcr::MODEL)
            ->where('unit_vision', 0)
            ->count() >= $maks;
    }

    private function tolak(
        ?CalibrationSession $sesi,
        User $user,
        string $status,
        int $ukuran,
        string $error,
        string $pesan,
        int $kode,
        string $kodeMesin,
    ): JsonResponse {
        WorksheetExtractionLog::create([
            'calibration_session_id' => $sesi?->id,
            'user_id' => $user->id,
            'model' => GoogleVisionOcr::MODEL,
            'status' => $status,
            'error' => $error,
            'ukuran_gambar_byte' => $ukuran,
        ]);

        return response()->json([
            'message' => $pesan,
            'kode' => $kodeMesin,
            'fallback_manual' => true,
        ], $kode);
    }

    /**
     * Sesi yang dikirim: pastikan seorganisasi & (buat teknisi) miliknya
     * sendiri. Id yang tidak ketemu di organisasi itu 404; sesi teknisi lain 403.
     */
    private function sesiTervalidasi(?int $sesiId, User $user): ?CalibrationSession
    {
        if ($sesiId === null) {
            return null;
        }

        $sesi = CalibrationSession::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($sesiId);

        abort_if(
            $user->role === User::ROLE_TEKNISI && $sesi->teknisi_id !== $user->id,
            403,
            'Cuma teknisi yang ngerjain sesi ini yang boleh mindai lembarnya.',
        );

        return $sesi;
    }
}
