<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\Ocr\FormulirAsli;
use App\Services\Ocr\TemplateLembarKerja;
use App\Services\Vision\GoogleVisionOcr;
use App\Services\Vision\HitunganSelUnggah;
use App\Services\Vision\JatahOcrAwan;
use App\Services\Vision\PemilahPotonganSel;
use App\Services\Vision\SusunHasilSelAwan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * OCR awan atas POTONGAN SEL ANGKA yang DIUNGGAH HP lewat Google Cloud Vision (DOCUMENT_TEXT_DETECTION).
 * Endpoint: `POST /api/ocr/sel-awan`. Kontrak: `docs/kontrak-api.md` bagian 22B.
 *
 * ## Kenapa ada, dan kenapa bukan `OcrSelAwanController`
 *
 * Aplikasi Lab tidak menyimpan hasil pindai di server (foto dan halaman utuh tidak pernah meninggalkan HP,
 * K-50-11), jadi `POST /api/worksheet-scans/{id}/ocr-awan` (`OcrSelAwanController`) tidak punya `scan_id`
 * untuk dipanggil. Di sini HP memotong sendiri sel angka dari fotonya dan mengunggah HANYA potongan itu.
 * Controller itu dan `POST /api/ocr/halaman` TIDAK berubah.
 *
 * ## Yang dikirim ke Google
 *
 * Gambarnya DATANG dari klien, jadi seluruh penjagaannya di server dan gagal-tutup:
 *  - kunci sel dicocokkan ke templat yang disusun SERVER (bukan klaim klien) dan hanya baris bertipe
 *    `angka` yang lolos ([PemilahPotonganSel]); isian, centang, jam, kunci asing, dan kunci kembar tidak;
 *  - tiap potongan dibatasi byte dan piksel (`config('ocr.awan_sel')`), jadi bukan foto halaman. Piksel
 *    dibaca dari HEADER lewat `PengamanCitra`; potongan TIDAK didekode di sini, byte-nya diteruskan apa adanya;
 *  - penolakan per sel (`dilewati[]`), bukan seluruh permintaan; tanpa sel yang lolos = `422`, Google tidak dihubungi.
 *
 * ## Gerbang (semuanya harus terbuka)
 *
 *  1. grup `fitur:pindai_lembar` dan saklar Super Admin `ocr_cloud_sel` (`fitur:` di rute);
 *  2. env `GOOGLE_VISION_AKTIF` (bawaan MATI, dicek di sini; tanpa env baru);
 *  3. sesi kerja (bila disebut) seorganisasi, dan untuk teknisi miliknya sendiri.
 *
 * ## Tidak ada yang disimpan
 *
 * Potongan tidak ditulis ke disk, database, atau log; satu-satunya jejaknya berkas sementara unggahan PHP.
 * Yang tercatat hanya `worksheet_extraction_logs` (status, unit, byte, durasi), tanpa gambar dan tanpa teks.
 *
 * ## Hasil = USULAN
 *
 * Bentuk jawaban per sel sama dengan 22A ([SusunHasilSelAwan]). Status paling tinggi `kuning`. Endpoint ini
 * tidak menulis nilai sel apa pun.
 *
 * ## Tidak pernah 500 polos
 *
 * Setiap kegagalan, termasuk galat tak terduga, dijawab `{message, kode, fallback_manual: true}` supaya
 * teknisi tidak buntu. Dua jawaban yang dihasilkan middleware bersama (`fitur_dimatikan`, 401/403 peran)
 * tidak membawa `fallback_manual`; klien memperlakukan SEMUA jawaban bukan-2xx sebagai "jatuh ke HP".
 */
class OcrSelUnggahController extends Controller
{
    public function __construct(
        private readonly GoogleVisionOcr $ocr,
        private readonly FormulirAsli $formulirAsli,
        private readonly TemplateLembarKerja $templatCetak,
        private readonly PemilahPotonganSel $pemilah,
        private readonly SusunHasilSelAwan $penyusun,
        private readonly JatahOcrAwan $jatah,
        private readonly HitunganSelUnggah $hitungan,
    ) {}

    public function baca(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! (bool) config('services.google_vision.aktif', false)) {
            return $this->tolak(
                null, $user, 'dimatikan',
                'OCR awan dimatikan di server (`GOOGLE_VISION_AKTIF=false`).',
                'OCR awan belum diaktifkan di server. Baca di HP atau isi manual.',
                503, 'ocr_cloud_belum_aktif',
            );
        }

        try {
            return $this->proses($request, $user);
        } catch (Throwable $e) {
            // Galat tak terduga (DB, cache, templat) tidak boleh menjadi 500 polos: teknisi kehilangan
            // jalan keluar "isi manual". Pesan galat TIDAK diteruskan ke klien dan tidak memuat isi gambar.
            report($e);

            return $this->galat(502, 'ocr_cloud_gagal', 'OCR awan sedang tidak bisa dipakai. Baca di HP atau isi manual.');
        }
    }

    private function proses(Request $request, User $user): JsonResponse
    {
        $maksSel = max(1, (int) config('ocr.awan_sel.maks_sel_per_permintaan', 48));

        $validator = Validator::make($request->all(), [
            'template_id' => ['required', 'string', 'max:80'],
            'kertas' => ['sometimes', 'nullable', 'string', 'in:cetak,asli'],
            'kode_dokumen' => ['sometimes', 'nullable', 'string', 'max:40'],
            'equipment_id' => ['sometimes', 'nullable', 'integer'],
            'jumlah_pengulangan' => ['sometimes', 'nullable', 'integer', 'between:2,10'],
            'calibration_session_id' => ['sometimes', 'nullable', 'integer'],
            'sel' => ['required', 'array', 'min:1', 'max:'.$maksSel],
            'sel.*.kunci' => ['required', 'string', 'max:160'],
            // Sengaja TANPA `max` / `image`: ukuran dan isi dinilai per sel ([PemilahPotonganSel]) supaya satu
            // potongan buruk tidak menggagalkan sel lain.
            'sel.*.gambar' => ['required', 'file'],
        ], [
            'template_id.required' => 'Kode templat wajib diisi.',
            'template_id.string' => 'Kode templat harus berupa teks.',
            'template_id.max' => 'Kode templat terlalu panjang.',
            'kertas.in' => 'Jenis kertas harus `cetak` atau `asli`.',
            'kode_dokumen.max' => 'Kode dokumen terlalu panjang.',
            'equipment_id.integer' => 'Id alat harus berupa angka.',
            'jumlah_pengulangan.integer' => 'Jumlah pengulangan harus berupa angka.',
            'jumlah_pengulangan.between' => 'Jumlah pengulangan antara 2 dan 10.',
            'calibration_session_id.integer' => 'Id sesi kerja harus berupa angka.',
            'sel.required' => 'Daftar sel wajib diisi.',
            'sel.array' => 'Daftar sel harus berupa larik.',
            'sel.min' => 'Daftar sel tidak boleh kosong.',
            'sel.max' => "Satu permintaan paling banyak {$maksSel} sel.",
            'sel.*.kunci.required' => 'Tiap sel wajib punya kunci.',
            'sel.*.kunci.string' => 'Kunci sel harus berupa teks.',
            'sel.*.kunci.max' => 'Kunci sel terlalu panjang.',
            'sel.*.gambar.required' => 'Tiap sel wajib membawa berkas gambar potongannya.',
            'sel.*.gambar.file' => 'Gambar sel harus berupa berkas yang terunggah utuh.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Permintaan tidak lengkap atau tidak sesuai. Periksa isiannya, atau isi manual.',
                'kode' => 'permintaan_tidak_valid',
                'errors' => $validator->errors()->toArray(),
                'fallback_manual' => true,
            ], 422);
        }

        $data = $validator->validated();

        $sesi = null;

        if (($data['calibration_session_id'] ?? null) !== null) {
            $sesi = CalibrationSession::query()
                ->where('organization_id', $user->organization_id)
                ->find((int) $data['calibration_session_id']);

            // Sesi organisasi lain dan sesi yang tidak ada dijawab SAMA persis: tidak membocorkan keberadaannya.
            if ($sesi === null) {
                return $this->galat(404, 'sesi_tidak_ditemukan', 'Sesi kerja tidak ditemukan. Baca di HP atau isi manual.');
            }

            if ($user->role === User::ROLE_TEKNISI && $sesi->teknisi_id !== $user->id) {
                return $this->galat(403, 'bukan_sesi_anda', 'Sesi kerja ini milik teknisi lain. Baca di HP atau isi manual.');
            }
        }

        // Urutan sama dengan `WorksheetScanController::store`: alat yang ditunjuk, alat sesinya, baru konteks
        // organisasi. Konteks itu wajib: tanpanya saringan master standar di profil mati dan templat memuat
        // kalibrator lab lain.
        $alat = $this->alat($user, $data['equipment_id'] ?? null)
            ?? $sesi?->equipment
            ?? new Equipment(['organization_id' => $user->organization_id]);

        $kodeTemplat = (string) $data['template_id'];
        $jumlahPengulangan = isset($data['jumlah_pengulangan']) ? (int) $data['jumlah_pengulangan'] : null;

        $templat = ($data['kertas'] ?? 'cetak') === 'asli'
            ? $this->formulirAsli->cari($kodeTemplat, $alat, $jumlahPengulangan, $data['kode_dokumen'] ?? null)
            : $this->templatCetak->untukKode($kodeTemplat, $alat, $jumlahPengulangan);

        // `cari()` bisa menjawab teks (alat punya peta tapi tak ada yang bisa dipilih): kalimat tetap saja.
        if (! is_array($templat)) {
            return $this->galat(422, 'templat_tidak_dikenal', 'Templat lembar kerja tidak dikenal. Baca di HP atau isi manual.');
        }

        ['potongan' => $potongan, 'dilewati' => $dilewati] = $this->pilih($data['sel'], $templat);

        if ($potongan === []) {
            return response()->json([
                'message' => 'Tidak ada potongan sel angka yang boleh dikirim. Baca di HP atau isi manual.',
                'kode' => 'tidak_ada_potongan_valid',
                'dilewati' => $dilewati,
                'fallback_manual' => true,
            ], 422);
        }

        $sisaSel = $this->hitungan->sisa($sesi, $user);

        if ($sisaSel < 1) {
            return $this->tolak(
                $sesi, $user, 'dibatasi',
                'Batas sel OCR awan unggahan per lembar tercapai.',
                'Batas pembacaan awan untuk lembar ini sudah habis. Isi manual atau baca di HP.',
                429, 'batas_sel_per_lembar',
            );
        }

        if ($sesi !== null && $this->jatah->mencapaiBatas($sesi)) {
            return $this->tolak(
                $sesi, $user, 'dibatasi',
                'Batas panggilan OCR awan per lembar tercapai.',
                'Batas pembacaan awan untuk lembar ini sudah habis. Isi manual atau baca di HP.',
                429, 'batas_per_lembar',
            );
        }

        if ($sesi !== null && $this->jatah->terlaluBanyakGagal($sesi)) {
            // Idempoten: melengkapi sinyal admin bila penulisan di kegagalan terakhir sempat gagal.
            $this->jatah->catatPenghentian($sesi, $user);

            return $this->tolak(
                $sesi, $user, 'dibatasi',
                'Batas kegagalan OCR awan per lembar tercapai.',
                'OCR awan gagal berulang untuk lembar ini, jadi dihentikan. Baca di HP atau isi manual.',
                429, 'gagal_berulang_per_lembar',
            );
        }

        $kunciKirim = array_map('strval', array_keys($potongan));

        foreach (array_slice($kunciKirim, $sisaSel) as $kunci) {
            $dilewati[] = ['kunci' => $kunci, 'alasan' => 'melebihi_batas_lembar'];
        }

        $kunciKirim = array_slice($kunciKirim, 0, $sisaSel);

        // Satu panggilan Google = satu baris log = satu jatah lembar. Sisa jatah dihitung SEBELUM mengirim
        // supaya satu permintaan besar tidak melewati `maks_per_sesi`.
        $kelompok = array_chunk($kunciKirim, GoogleVisionOcr::MAKS_GAMBAR_PER_PANGGILAN);
        $batasPanggilan = $sesi !== null ? $this->jatah->sisaPanggilan($sesi) : count($kelompok);

        foreach (array_slice($kelompok, $batasPanggilan) as $sisa) {
            foreach ($sisa as $kunci) {
                $dilewati[] = ['kunci' => $kunci, 'alasan' => 'jatah_lembar_habis'];
            }
        }

        $kelompok = array_slice($kelompok, 0, $batasPanggilan);

        $hasil = [];
        $unit = 0;
        $panggilan = 0;
        $terbaca = 0;
        $gagal = null;

        foreach ($kelompok as $i => $kunciKelompok) {
            $isi = array_map(static fn (string $k): string => $potongan[$k], $kunciKelompok);
            $balasan = $this->ocr->bacaSel($isi);
            $panggilan++;

            // Catat SETIAP panggilan. `belum_disetel` tidak pernah sampai ke Google, jadi `unit_vision` NULL
            // dan tidak memakan jatah lembar. TIDAK ada gambar dan TIDAK ada teks hasil di log.
            $this->catatLog([
                'calibration_session_id' => $sesi?->id,
                'user_id' => $user->id,
                'model' => GoogleVisionOcr::MODEL,
                'status' => $balasan['ok'] ? 'sukses' : $balasan['status'],
                'error' => $balasan['error'],
                'unit_vision' => $balasan['status'] === 'belum_disetel' ? null : $balasan['unit'],
                'ukuran_gambar_byte' => array_sum(array_map('strlen', $isi)),
                'durasi_ms' => $balasan['durasi_ms'],
            ]);

            // Gagal melapor tidak mengubah jawaban ke teknisi (di dalam `laporkanGagal`).
            $this->jatah->laporkanGagal($balasan, $sesi, $user);

            if (! $balasan['ok']) {
                $gagal = $balasan;

                // Berhenti di kegagalan pertama: mengulang ke Google yang sedang bermasalah hanya
                // menghabiskan jatah kegagalan lembar.
                foreach (array_slice($kelompok, $i) as $sisa) {
                    foreach ($sisa as $kunci) {
                        $dilewati[] = ['kunci' => $kunci, 'alasan' => 'google_gagal'];
                    }
                }

                break;
            }

            $unit += $balasan['unit'];
            $terbaca += count($kunciKelompok);

            foreach ($kunciKelompok as $j => $kunci) {
                $hasil[] = $this->penyusun->susun($kunci, $balasan['sel'][$j] ?? null);
            }
        }

        $this->hitungan->catat($sesi, $user, $terbaca);

        if ($hasil === [] && $gagal !== null) {
            // Kalimat TETAP per kode, bukan `$gagal['error']`: teks itu untuk log server.
            [$kode, $mesin, $pesan] = match ($gagal['status']) {
                'belum_disetel' => [503, 'ocr_cloud_belum_disetel', 'OCR awan belum disetel di server. Baca di HP atau isi manual.'],
                'ditolak' => [422, 'gambar_tidak_terbaca', 'Google tidak bisa membaca potongan sel ini. Baca di HP atau isi manual.'],
                default => [502, 'ocr_cloud_gagal', 'OCR awan sedang tidak bisa dipakai. Baca di HP atau isi manual.'],
            };

            return $this->galat($kode, $mesin, $pesan);
        }

        return response()->json([
            'hasil' => $hasil,
            'dilewati' => $dilewati,
            'meta' => [
                'sumber' => 'google_vision',
                'fitur' => GoogleVisionOcr::FITUR,
                'unit' => $unit,
                'jumlah_dikirim' => count($hasil),
                'jumlah_panggilan' => $panggilan,
                // Selalu USULAN: tidak ada nilai sel yang diputuskan atau disimpan di sini.
                'usulan' => true,
                // true = sebagian sel terbaca, sisanya tidak sempat (lihat `dilewati`, alasan `google_gagal`).
                'sebagian' => $gagal !== null,
                'sisa_sel_lembar' => $this->hitungan->sisa($sesi, $user),
            ],
        ]);
    }

    /**
     * Potongan mana yang boleh dikirim, dan kenapa sisanya tidak. Berkas yang ditolak karena kunci TIDAK dibaca.
     *
     * @param  list<array{kunci: string, gambar: UploadedFile}>  $sel
     * @param  array<string, mixed>  $templat
     * @return array{potongan: array<string, string>, dilewati: list<array{kunci: string, alasan: string}>}
     */
    private function pilih(array $sel, array $templat): array
    {
        $peta = $this->pemilah->petaKunci($templat);
        $potongan = [];
        $dilewati = [];
        $terlihat = [];

        foreach ($sel as $s) {
            $kunci = (string) $s['kunci'];

            if (isset($terlihat[$kunci])) {
                // Yang pertama menang. Dua gambar untuk satu sel = klien salah; tidak ada yang ditebak.
                $dilewati[] = ['kunci' => $kunci, 'alasan' => 'kunci_kembar'];

                continue;
            }

            $terlihat[$kunci] = true;

            $alasan = $this->pemilah->alasanKunci($kunci, $peta);

            if ($alasan === null) {
                /** @var UploadedFile $berkas */
                $berkas = $s['gambar'];
                // Ukuran dicek SEBELUM berkasnya dibaca ke memori.
                $alasan = $this->pemilah->alasanUkuran((int) $berkas->getSize());

                if ($alasan === null) {
                    $isi = @file_get_contents((string) $berkas->getRealPath());
                    $alasan = $isi === false ? 'potongan_bukan_citra' : $this->pemilah->alasanIsi($isi);

                    if ($alasan === null && $isi !== false) {
                        $potongan[$kunci] = $isi;
                    }
                }
            }

            if ($alasan !== null) {
                $dilewati[] = ['kunci' => $kunci, 'alasan' => $alasan];
            }
        }

        return ['potongan' => $potongan, 'dilewati' => $dilewati];
    }

    /** Alat seorganisasi; id alat lab lain diperlakukan seperti tanpa alat. */
    private function alat(User $user, mixed $equipmentId): ?Equipment
    {
        if ($equipmentId === null) {
            return null;
        }

        return Equipment::query()
            ->where('organization_id', $user->organization_id)
            ->find((int) $equipmentId);
    }

    /** Penolakan LOKAL (belum menyentuh Google): satu baris log tanpa unit, lalu jawaban dengan `fallback_manual`. */
    private function tolak(
        ?CalibrationSession $sesi,
        User $user,
        string $status,
        string $error,
        string $pesan,
        int $kode,
        string $kodeMesin,
    ): JsonResponse {
        $this->catatLog([
            'calibration_session_id' => $sesi?->id,
            'user_id' => $user->id,
            'model' => GoogleVisionOcr::MODEL,
            'status' => $status,
            'error' => $error,
            'ukuran_gambar_byte' => 0,
        ]);

        return $this->galat($kode, $kodeMesin, $pesan);
    }

    private function galat(int $kode, string $kodeMesin, string $pesan): JsonResponse
    {
        return response()->json([
            'message' => $pesan,
            'kode' => $kodeMesin,
            'fallback_manual' => true,
        ], $kode);
    }

    /**
     * Gagal menulis log TIDAK boleh menghilangkan hasil yang sudah dibayar ke Google atau mengubah penolakan
     * menjadi 500: dilaporkan ke log server, jawaban ke teknisi tetap.
     *
     * @param  array<string, mixed>  $baris
     */
    private function catatLog(array $baris): void
    {
        try {
            WorksheetExtractionLog::create($baris);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
