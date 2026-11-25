<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Models\WorksheetScan;
use App\Models\WorksheetScanCell;
use App\Services\Ocr\NormalisasiAngka;
use App\Services\Ocr\ValidasiSel;
use App\Services\Vision\GoogleVisionOcr;
use App\Services\Vision\JatahOcrAwan;
use App\Services\Vision\PotongSelAngka;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * OCR awan atas POTONGAN SEL ANGKA satu hasil pindai lewat Google Cloud Vision
 * (DOCUMENT_TEXT_DETECTION). Endpoint: `POST /api/worksheet-scans/{worksheetScan}/ocr-awan`.
 *
 * Keputusan pemilik (25 Nov 2026): ke Google HANYA potongan sel angka. Bukan foto utuh, bukan sel
 * identitas (nama, alamat, nomor seri pelanggan, tanda tangan), bukan tanggal, bukan centang, bukan
 * teks bebas. `POST /api/ocr/halaman` (foto utuh) tidak berubah dan tidak dipakai di sini.
 *
 * ## Apa yang dikirim ke Google
 *
 * Gambar TIDAK datang dari klien. Server memotong sendiri dari citra hasil pindai yang tersimpan
 * (`PotongSelAngka`) memakai kotak di `worksheet_scan_cells`, jadi klien tidak bisa memasukkan gambar
 * lain (foto utuh, dokumen identitas) lewat endpoint ini. Klien hanya boleh menyebut KUNCI sel, dan
 * kunci itu tetap disaring ulang di sini ([alasanTidakDikirim]).
 *
 * Penyaringan itu GAGAL-TUTUP: sebuah sel hanya dikirim bila terbukti sel angka, yaitu barisnya
 * (`worksheet_scans.hasil['tabel'][].baris[].tipe`) bertipe `angka`. Sel `isian` (kondisi lingkungan),
 * `centang`, baris `jam`, dan apa pun yang tidak ketemu di peta tipe itu TIDAK dikirim. Identitas dan
 * tanggal formulir bukan baris sel sama sekali, jadi tidak pernah masuk daftar.
 *
 * ## Gerbang (semuanya harus terbuka)
 *
 *  1. grup `fitur:pindai_lembar` dan saklar Super Admin `ocr_cloud_sel` (`fitur:` di rute);
 *  2. env `GOOGLE_VISION_AKTIF` (bawaan MATI, dicek di sini);
 *  3. hasil pindai seorganisasi, dan untuk teknisi miliknya sendiri (organisasi lain 404, teknisi lain 403).
 *
 * Saklar Super Admin hanya bisa MEMATIKAN (daftar yang disimpan adalah yang dimatikan); yang menjamin
 * "bawaan mati" adalah env di butir 2.
 *
 * ## Hasil = USULAN
 *
 * Tiap sel dijawab `{kunci, teks_awan, keyakinan, status, ...}`. Status paling tinggi `kuning`: teks
 * tulisan tangan tidak pernah hijau. Teks dari Google dikembalikan APA ADANYA (`teks_awan`); bila bisa
 * dibaca sebagai angka murni, bentuk angkanya menyertai terpisah (`teks_normal`, `nilai_awan`) dan
 * `dinormalkan` bilang apakah bentuknya berbeda dari `teks_awan`. Tidak ada koreksi diam-diam: teks
 * yang tidak lolos `NormalisasiAngka` tetap dikirim mentah dan berstatus merah. Sel kosong di kertas
 * tetap kosong. Endpoint ini TIDAK menulis apa pun ke `worksheet_scan_cells`.
 *
 * ## Batas
 *
 * Per permintaan dan per hasil pindai: `config('ocr.awan_sel')`. Per lembar (sesi kerja): jatah dan
 * batas kegagalan yang sama dengan jalur foto utuh (`JatahOcrAwan`), satu baris log per panggilan
 * Google (satu panggilan = paling banyak 16 sel). Tiap percobaan masuk `worksheet_extraction_logs`
 * tanpa gambar dan tanpa teks hasil.
 */
class OcrSelAwanController extends Controller
{
    /** Hasil tulisan tangan tidak pernah hijau (`config/ocr.php` `tulisan_tangan.status_maks`). */
    private const STATUS_TERTINGGI = ValidasiSel::KUNING;

    /** Lama hitungan sel per hasil pindai di cache. Hitungan lunak, bukan sumber kebenaran. */
    private const HARI_HITUNGAN_SEL = 30;

    public function baca(
        Request $request,
        WorksheetScan $worksheetScan,
        GoogleVisionOcr $ocr,
        PotongSelAngka $pemotong,
        JatahOcrAwan $jatah,
        NormalisasiAngka $normalisasi,
    ): JsonResponse {
        $user = $request->user();

        $this->pastikanBolehLihat($user, $worksheetScan);

        $sesi = $this->sesiScan($worksheetScan, $user);

        if (! (bool) config('services.google_vision.aktif', false)) {
            return $this->tolak(
                $sesi, $user, 'dimatikan',
                'OCR awan dimatikan di server (`GOOGLE_VISION_AKTIF=false`).',
                'OCR awan belum diaktifkan di server. Baca di HP atau isi manual.',
                503, 'ocr_cloud_belum_aktif',
            );
        }

        $maksPermintaan = max(1, (int) config('ocr.awan_sel.maks_sel_per_permintaan', 48));

        $data = $request->validate([
            'kunci' => ['sometimes', 'array', 'min:1', 'max:'.$maksPermintaan],
            'kunci.*' => ['string', 'max:160'],
        ], [
            'kunci.array' => 'Daftar kunci sel harus berupa larik.',
            'kunci.min' => 'Daftar kunci sel tidak boleh kosong. Hilangkan kolomnya untuk membaca semua sel yang perlu dicek.',
            'kunci.max' => "Satu permintaan paling banyak {$maksPermintaan} sel.",
            'kunci.*.string' => 'Kunci sel harus berupa teks.',
            'kunci.*.max' => 'Kunci sel terlalu panjang.',
        ]);

        $pilihan = $this->pilihSel($worksheetScan, $data['kunci'] ?? null);
        $dilewati = $pilihan['dilewati'];
        $kandidat = $pilihan['sel'];

        if ($kandidat === []) {
            return $this->jawab([], $dilewati, $pilihan['otomatis'], 0, 0, $this->sisaSelScan($worksheetScan));
        }

        // Batas per permintaan lalu per hasil pindai. Sel yang tidak muat dilaporkan di `dilewati`,
        // tidak dibuang diam-diam.
        $sisaScan = $this->sisaSelScan($worksheetScan);

        if ($sisaScan < 1) {
            return $this->tolak(
                $sesi, $user, 'dibatasi',
                'Batas sel OCR awan per hasil pindai tercapai.',
                'Batas pembacaan awan untuk hasil pindai ini sudah habis. Isi manual atau baca di HP.',
                429, 'batas_sel_per_scan',
            );
        }

        $muat = min($maksPermintaan, $sisaScan);

        foreach (array_slice($kandidat, $muat) as $s) {
            $dilewati[] = ['kunci' => $s->kunci, 'alasan' => $sisaScan < $maksPermintaan ? 'melebihi_batas_scan' : 'melebihi_batas_permintaan'];
        }

        $kandidat = array_slice($kandidat, 0, $muat);

        if ($sesi !== null && $jatah->mencapaiBatas($sesi)) {
            return $this->tolak(
                $sesi, $user, 'dibatasi',
                'Batas panggilan OCR awan per lembar tercapai.',
                'Batas pembacaan awan untuk lembar ini sudah habis. Isi manual atau baca di HP.',
                429, 'batas_per_lembar',
            );
        }

        if ($sesi !== null && $jatah->terlaluBanyakGagal($sesi)) {
            // Idempoten: melengkapi sinyal admin bila penulisan di kegagalan terakhir sempat gagal.
            $jatah->catatPenghentian($sesi, $user);

            return $this->tolak(
                $sesi, $user, 'dibatasi',
                'Batas kegagalan OCR awan per lembar tercapai.',
                'OCR awan gagal berulang untuk lembar ini, jadi dihentikan. Baca di HP atau isi manual.',
                429, 'gagal_berulang_per_lembar',
            );
        }

        $potong = $pemotong->potong($worksheetScan, $kandidat);

        if ($potong['galat'] !== null) {
            [$kode, $mesin, $pesan] = match ($potong['galat']) {
                'gd_tidak_ada' => [503, 'citra_tidak_bisa_dipotong', 'Server belum bisa memotong citra hasil pindai. Baca di HP atau isi manual.'],
                'citra_rusak' => [422, 'citra_rusak', 'Citra hasil pindai rusak. Pindai ulang lembarnya.'],
                default => [422, 'citra_tidak_tersimpan', 'Citra hasil pindai tidak tersimpan di server. Pindai ulang lembarnya.'],
            };

            return $this->tolak($sesi, $user, 'ditolak', 'Potongan sel tidak bisa dibuat: '.$potong['galat'].'.', $pesan, $kode, $mesin);
        }

        foreach ($potong['gagal'] as $kunci => $alasan) {
            $dilewati[] = ['kunci' => $kunci, 'alasan' => $alasan];
        }

        $kunciTerpotong = array_keys($potong['potongan']);

        // Satu panggilan Google = satu baris log = satu jatah lembar. Sisa jatah dihitung SEBELUM
        // mengirim supaya satu permintaan besar tidak melewati `maks_per_sesi`.
        $kelompok = array_chunk($kunciTerpotong, GoogleVisionOcr::MAKS_GAMBAR_PER_PANGGILAN);
        $bataPanggilan = $sesi !== null ? $jatah->sisaPanggilan($sesi) : count($kelompok);

        foreach (array_slice($kelompok, $bataPanggilan) as $sisa) {
            foreach ($sisa as $kunci) {
                $dilewati[] = ['kunci' => $kunci, 'alasan' => 'jatah_lembar_habis'];
            }
        }

        $kelompok = array_slice($kelompok, 0, $bataPanggilan);

        $hasil = [];
        $unit = 0;
        $panggilan = 0;
        $gagal = null;

        foreach ($kelompok as $i => $kunciKelompok) {
            $isi = array_map(static fn (string $k): string => $potong['potongan'][$k], $kunciKelompok);
            $balasan = $ocr->bacaSel($isi);
            $panggilan++;

            // Catat SETIAP panggilan. `belum_disetel` tidak pernah sampai ke Google, jadi `unit_vision`
            // NULL dan tidak memakan jatah lembar. TIDAK ada gambar dan TIDAK ada teks hasil di log.
            WorksheetExtractionLog::create([
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
            $jatah->laporkanGagal($balasan, $sesi, $user);

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

            foreach ($kunciKelompok as $j => $kunci) {
                $hasil[] = $this->susunHasil($kunci, $balasan['sel'][$j] ?? null, $normalisasi);
            }
        }

        if ($unit > 0) {
            Cache::put(
                $this->kunciHitunganSel($worksheetScan),
                $this->terpakaiSelScan($worksheetScan) + $unit,
                now()->addDays(self::HARI_HITUNGAN_SEL),
            );
        }

        if ($hasil === [] && $gagal !== null) {
            // Kalimat TETAP per kode, bukan `$gagal['error']`: teks itu untuk log server.
            [$kode, $mesin, $pesan] = match ($gagal['status']) {
                'belum_disetel' => [503, 'ocr_cloud_belum_disetel', 'OCR awan belum disetel di server. Baca di HP atau isi manual.'],
                'ditolak' => [422, 'gambar_tidak_terbaca', 'Google tidak bisa membaca potongan sel ini. Baca di HP atau isi manual.'],
                default => [502, 'ocr_cloud_gagal', 'OCR awan sedang tidak bisa dipakai. Baca di HP atau isi manual.'],
            };

            return response()->json([
                'message' => $pesan,
                'kode' => $mesin,
                'fallback_manual' => true,
            ], $kode);
        }

        return $this->jawab(
            $hasil, $dilewati, $pilihan['otomatis'], $unit, $panggilan,
            $this->sisaSelScan($worksheetScan),
            sebagian: $gagal !== null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $hasil
     * @param  list<array{kunci: string, alasan: string}>  $dilewati
     * @param  array<string, int>  $otomatis
     */
    private function jawab(
        array $hasil,
        array $dilewati,
        array $otomatis,
        int $unit,
        int $panggilan,
        int $sisaSelScan,
        bool $sebagian = false,
    ): JsonResponse {
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
                'sebagian' => $sebagian,
                // Sel yang tidak dipilih karena bukan sel angka, kosong di kertas, atau sudah hijau di HP.
                'dilewati_otomatis' => $otomatis,
                'sisa_sel_scan' => $sisaSelScan,
            ],
        ]);
    }

    /**
     * Satu sel hasil Google → bentuk jawaban. `teks_awan` selalu teks mentah dari Google.
     *
     * @param  array{ok: bool, teks: string, keyakinan: ?float, jumlah_kata: int}|null  $g
     * @return array<string, mixed>
     */
    private function susunHasil(string $kunci, ?array $g, NormalisasiAngka $normalisasi): array
    {
        $dasar = [
            'kunci' => $kunci,
            'teks_awan' => null,
            'keyakinan' => null,
            'status' => ValidasiSel::MERAH,
            'teks_normal' => null,
            'nilai_awan' => null,
            'dinormalkan' => false,
            'alasan' => [],
        ];

        if ($g === null || ! $g['ok']) {
            // Google menolak gambar sel ini (panggilan lain di batch yang sama bisa berhasil).
            return [...$dasar, 'alasan' => ['google_gagal_sel']];
        }

        if ($g['jumlah_kata'] === 0 || trim($g['teks']) === '') {
            // Google tidak melihat tulisan: sel tetap kosong, bukan angka karangan.
            return [...$dasar, 'teks_awan' => '', 'status' => ValidasiSel::KOSONG];
        }

        $norm = $normalisasi->proses($g['teks']);
        $dasar['teks_awan'] = $g['teks'];
        $dasar['keyakinan'] = $g['keyakinan'];

        if (! $norm['ok']) {
            return [...$dasar, 'alasan' => $norm['alasan']];
        }

        $dasar['teks_normal'] = $norm['teks_normal'];
        $dasar['nilai_awan'] = $norm['nilai'];
        // Bentuk angkanya (koma desimal, spasi tepi dipangkas) bisa beda dari teks Google. Klien
        // wajib menampilkan keduanya bila `dinormalkan` true; tidak ada yang ditukar diam-diam.
        $dasar['dinormalkan'] = $norm['teks_normal'] !== $g['teks'];

        if ($g['keyakinan'] === null) {
            return [...$dasar, 'alasan' => ['keyakinan_tidak_ada']];
        }

        if ($g['keyakinan'] < (float) config('ocr.ambang.kuning', 0.60)) {
            return [...$dasar, 'alasan' => ['keyakinan_rendah']];
        }

        return [...$dasar, 'status' => self::STATUS_TERTINGGI];
    }

    /**
     * Sel mana yang boleh dikirim. Tanpa `$diminta`: sel angka yang masih perlu dicek (bukan kosong
     * dan bukan hijau di HP). Dengan `$diminta`: persis kunci itu, tetapi tiap kunci tetap lewat
     * [alasanTidakDikirim], jadi menyebut kunci sel identitas atau centang tidak mengirimnya.
     *
     * @param  list<string>|null  $diminta
     * @return array{sel: list<WorksheetScanCell>, dilewati: list<array{kunci: string, alasan: string}>, otomatis: array<string, int>}
     */
    private function pilihSel(WorksheetScan $scan, ?array $diminta): array
    {
        $tipeBaris = $this->petaTipeBaris($scan);

        /** @var Collection<string, WorksheetScanCell> $semua */
        $semua = $scan->cells()
            ->select(['id', 'worksheet_scan_id', 'kunci', 'tabel_id', 'baris_ke', 'status', 'kotak'])
            ->orderBy('id')
            ->get()
            ->keyBy('kunci');

        $sel = [];
        $dilewati = [];
        $otomatis = [];

        if ($diminta !== null) {
            foreach (array_values(array_unique($diminta)) as $kunci) {
                $s = $semua->get($kunci);
                $alasan = $s === null ? 'tidak_ada_di_hasil_pindai' : $this->alasanTidakDikirim($s, $tipeBaris);

                if ($alasan !== null) {
                    $dilewati[] = ['kunci' => $kunci, 'alasan' => $alasan];

                    continue;
                }

                $sel[] = $s;
            }

            return ['sel' => $sel, 'dilewati' => $dilewati, 'otomatis' => $otomatis];
        }

        foreach ($semua as $s) {
            $alasan = $this->alasanTidakDikirim($s, $tipeBaris)
                ?? ($s->status === ValidasiSel::HIJAU ? 'sudah_hijau_lokal' : null);

            if ($alasan !== null) {
                $otomatis[$alasan] = ($otomatis[$alasan] ?? 0) + 1;

                continue;
            }

            $sel[] = $s;
        }

        return ['sel' => $sel, 'dilewati' => $dilewati, 'otomatis' => $otomatis];
    }

    /**
     * Kenapa sebuah sel TIDAK boleh dikirim ke Google; null = boleh. GAGAL-TUTUP: sel hanya lolos
     * bila terbukti sel angka (baris bertipe `angka`). Yang tidak dikenal tidak dikirim.
     *
     *  - isian (kondisi lingkungan) dan centang: bukan potongan angka tabel, bukan urusan jalur ini;
     *  - baris `jam` (Autoklaf): bukan angka desimal, formatnya masih ditanyakan ke lab;
     *  - kosong di HP: sel kosong tetap kosong, tidak ada alasan membayar untuk "membacanya".
     *
     * Identitas, alamat, nomor seri, tanda tangan, dan tanggal tidak punya baris `worksheet_scan_cells`
     * sama sekali; penyaring ini juga tidak akan meloloskan mereka seandainya suatu hari punya.
     *
     * @param  array<string, string>  $tipeBaris
     */
    private function alasanTidakDikirim(WorksheetScanCell $s, array $tipeBaris): ?string
    {
        if ($s->tabel_id === 'isian') {
            return 'isian_tidak_dikirim';
        }

        if ($s->tabel_id === 'centang') {
            return 'centang_tidak_dikirim';
        }

        if (($tipeBaris[$s->tabel_id.'|'.$s->baris_ke] ?? null) !== 'angka') {
            return 'bukan_sel_angka';
        }

        if ($s->status === ValidasiSel::KOSONG) {
            return 'kosong_lokal';
        }

        return null;
    }

    /**
     * `tabel_id|baris_ke` → `tipe` baris, dari `worksheet_scans.hasil['tabel']` (disimpan waktu pindai).
     *
     * @return array<string, string>
     */
    private function petaTipeBaris(WorksheetScan $scan): array
    {
        $peta = [];
        $tabel = is_array($scan->hasil) ? ($scan->hasil['tabel'] ?? []) : [];

        foreach (is_array($tabel) ? $tabel : [] as $t) {
            if (! is_array($t) || ! isset($t['tabel_id'])) {
                continue;
            }

            foreach ((array) ($t['baris'] ?? []) as $b) {
                if (is_array($b) && isset($b['baris_ke'], $b['tipe']) && is_string($b['tipe'])) {
                    $peta[(string) $t['tabel_id'].'|'.(int) $b['baris_ke']] = $b['tipe'];
                }
            }
        }

        return $peta;
    }

    private function kunciHitunganSel(WorksheetScan $scan): string
    {
        return 'ocr_sel_awan.sel.'.$scan->id;
    }

    /**
     * Sisa sel yang boleh dikirim untuk hasil pindai ini. Hitungan LUNAK (cache), tidak dikunci dan
     * bisa hilang bersama cache: ia pagar biaya per hasil pindai, bukan catatan resmi. Catatan
     * resminya baris `worksheet_extraction_logs` (tanpa kunci per hasil pindai).
     */
    private function sisaSelScan(WorksheetScan $scan): int
    {
        $maks = max(0, (int) config('ocr.awan_sel.maks_sel_per_scan', 80));

        return max(0, $maks - $this->terpakaiSelScan($scan));
    }

    private function terpakaiSelScan(WorksheetScan $scan): int
    {
        return max(0, (int) Cache::get($this->kunciHitunganSel($scan), 0));
    }

    private function tolak(
        ?CalibrationSession $sesi,
        User $user,
        string $status,
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
            'ukuran_gambar_byte' => 0,
        ]);

        return response()->json([
            'message' => $pesan,
            'kode' => $kodeMesin,
            'fallback_manual' => true,
        ], $kode);
    }

    /** Sesi kerja hasil pindai ini, hanya bila seorganisasi dengan pemakai. */
    private function sesiScan(WorksheetScan $scan, User $user): ?CalibrationSession
    {
        if ($scan->calibration_session_id === null) {
            return null;
        }

        return CalibrationSession::query()
            ->where('organization_id', $user->organization_id)
            ->find($scan->calibration_session_id);
    }

    /** Aturan yang sama dengan `WorksheetScanController`: organisasi lain 404 tanpa pesan, teknisi lain 403. */
    private function pastikanBolehLihat(User $user, WorksheetScan $scan): void
    {
        abort_if($scan->organization_id !== $user->organization_id, 404);

        abort_if(
            $user->role === User::ROLE_TEKNISI && $scan->user_id !== $user->id,
            403,
            'Hasil pindai ini punya teknisi lain.',
        );
    }
}
