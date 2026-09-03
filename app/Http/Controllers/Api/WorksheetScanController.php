<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorksheetScanRequest;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Models\WorksheetScan;
use App\Models\WorksheetScanCell;
use App\Services\Ocr\PemrosesScanLembarKerja;
use App\Services\Ocr\TemplateLembarKerja;
use App\Services\Ocr\ValidasiSel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OCR TEMPLATE LOKAL — pindai lembar kerja tanpa layanan AI pihak ketiga.
 *
 * Bedanya sama `WorksheetExtractionController` (jalur AI Vision) bukan cuma
 * penyedia:
 *
 *  - Fotonya NGGAK pernah keluar dari infrastruktur lab. OCR-nya jalan di HP;
 *    yang nyampe ke server teks per sel + koordinat + skor mutu foto.
 *  - Nggak ada biaya per pindai.
 *  - Pemetaan angka ke sel pakai KUNCI EKSPLISIT dari template, bukan tebakan
 *    bentuk tabel. Kalau ada satu penjagaan yang gagal, seluruh lembar ditolak —
 *    bukan diisi sebagian.
 *
 * Yang NGGAK berubah: hasil pindai tetap usulan. `raw_measurements` lahir dari
 * `POST/PUT /calibrations` seperti biasa, sesudah teknisi mbetulin sel merah &
 * ngecek sel kuning. Kamera mempercepat input, bukan jadi syaratnya — kalau
 * pindai gagal, teknisi tetap bisa ngetik manual.
 */
class WorksheetScanController extends Controller
{
    public function __construct(
        private readonly TemplateLembarKerja $template,
        private readonly PemrosesScanLembarKerja $pemroses,
    ) {}

    /**
     * Daftar template yang dikenal + mana yang geometrinya udah siap dipindai.
     *
     * Dipakai HP buat mutusin tombol kamera nyala atau nggak — jangan hardcode
     * daftarnya di aplikasi, soalnya alat baru nambah terus.
     */
    public function templates(Request $request): JsonResponse
    {
        // Alat semu pembawa `organization_id`, sama seperti `template()` di
        // bawah. Tanpa ini `daftar()` membangun kedua puluh empat lembar tanpa
        // konteks, dan saringan organisasi di profil mati untuk semuanya
        // sekaligus — lihat [konteksOrganisasi] & `daftar()`.
        return response()->json([
            'data' => $this->template->daftar($this->konteksOrganisasi($request)),
        ]);
    }

    /**
     * Definisi lengkap satu template: kunci sel, aturan angka, dan geometri
     * (koordinat sel di lembar cetak).
     */
    public function template(Request $request, string $kode): JsonResponse
    {
        $data = $request->validate([
            'equipment_id' => ['sometimes', 'nullable', 'integer'],
            'jumlah_pengulangan' => ['sometimes', 'nullable', 'integer', 'between:2,10'],
        ]);

        $template = $this->template->untukKode(
            $kode,
            $this->alat($request, $data['equipment_id'] ?? null)
                ?? $this->konteksOrganisasi($request),
            $data['jumlah_pengulangan'] ?? null,
        );

        abort_if($template === null, 404, 'Template lembar kerja nggak dikenal.');

        return response()->json(['data' => $template]);
    }

    /**
     * Terima hasil pindai dari HP → vonis per sel.
     */
    public function store(WorksheetScanRequest $request): JsonResponse
    {
        $user = $request->user();
        $sesi = $this->sesiTervalidasi($request->input('calibration_session_id'), $user);
        // Urutannya sengaja: alat yang ditunjuk, lalu alat sesinya, baru
        // konteks organisasi. Yang terakhir cuma memasok saringan lab — dia
        // TIDAK boleh mendahului alat sungguhan, karena varian satuan per titik
        // dibaca dari alat itu.
        $alat = $this->alat($request, $request->input('equipment_id'))
            ?? $sesi?->equipment
            ?? $this->konteksOrganisasi($request);

        $template = $this->template->untukKode(
            $request->string('template_id')->toString(),
            $alat,
            $request->input('jumlah_pengulangan'),
        );

        $hasil = $template === null
            ? [
                'ok' => false,
                'status' => PemrosesScanLembarKerja::TEMPLATE_TIDAK_DIKENALI,
                'alasan_gagal' => PemrosesScanLembarKerja::TEMPLATE_TIDAK_DIKENALI,
                'pesan' => 'Template lembar kerja `'.$request->string('template_id')->toString().'` nggak dikenal sistem.',
                'sel' => [],
                'tabel' => [],
                'ringkasan' => ['total_sel' => 0, 'hijau' => 0, 'kuning' => 0, 'merah' => 0, 'kosong' => 0],
            ]
            : $this->pemroses->proses($request->payload(), $template);

        $scan = DB::transaction(fn (): WorksheetScan => $this->simpan($request, $user, $sesi, $template, $hasil));

        // Semua kegagalan dicatat terstruktur — ini bahan buat nyetel ambang &
        // nyari tau kenapa teknisi di satu lokasi selalu gagal mindai.
        if (! $hasil['ok']) {
            Log::warning('ocr.scan_ditolak', [
                'scan_id' => $scan->id,
                'template_id' => $request->string('template_id')->toString(),
                'alasan' => $hasil['alasan_gagal'],
                'kualitas' => $request->input('kualitas'),
                'geometri' => $request->input('geometri'),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'scan_id' => $scan->id,
                'status' => $hasil['status'],
                'message' => $hasil['pesan'],
                // Teknisi nggak boleh kejebak: gagal pindai = balik ke jalur
                // ngetik, bukan buntu.
                'fallback_manual' => true,
            ], 422);
        }

        // Scan yang LOLOS tapi banyak merahnya juga dicatat. Kalau cuma yang
        // ditolak yang kelihatan di log, pindai yang "berhasil" dengan separuh
        // sel merah bakal lewat tanpa jejak — padahal itu bentuk kegagalan yang
        // paling sering bikin teknisi diam-diam balik ngetik manual.
        if ($hasil['ringkasan']['merah'] > 0) {
            Log::info('ocr.scan_banyak_merah', [
                'scan_id' => $scan->id,
                'template_id' => $scan->template_id,
                'ringkasan' => $hasil['ringkasan'],
                'kualitas' => $request->input('kualitas'),
                'perangkat' => $request->input('perangkat'),
            ]);
        }

        return response()->json($this->bentukHasil($scan, $hasil), 201);
    }

    /**
     * Hasil pindai yang udah kesimpen — buat layar review yang dibuka lagi.
     */
    public function show(Request $request, WorksheetScan $worksheetScan): JsonResponse
    {
        $this->pastikanBolehLihat($request, $worksheetScan);

        return response()->json($this->bentukHasil($worksheetScan, [
            'status' => $worksheetScan->status,
            'tabel' => $worksheetScan->hasil['tabel'] ?? [],
            'ringkasan' => $worksheetScan->hasil['ringkasan'] ?? [],
        ]));
    }

    /**
     * Potongan gambar sel aslinya.
     *
     * Ini yang bikin layar review kepakai: teknisi nggak perlu percaya angka
     * hasil baca mesin, dia bandingin sama coretan aslinya di layar yang sama.
     * Tanpa ini, "cek sel kuning" artinya buka lembar kertasnya lagi — dan kalau
     * harus buka kertasnya, mending ngetik dari awal.
     */
    public function crop(Request $request, WorksheetScan $worksheetScan, string $kunci): Response
    {
        $this->pastikanBolehLihat($request, $worksheetScan);

        /** @var WorksheetScanCell|null $sel */
        $sel = $worksheetScan->cells()->where('kunci', $kunci)->first();

        abort_if($sel === null, 404, 'Sel nggak ketemu di hasil pindai ini.');

        $disk = Storage::disk((string) config('ocr.penyimpanan.disk', 'local'));
        $path = $worksheetScan->citra_warp_path;

        abort_if($path === null || ! $disk->exists($path), 404, 'Citra hasil pindai nggak tersimpan.');
        abort_if(! extension_loaded('gd'), 503, 'Server belum bisa motong citra (ekstensi GD nggak ada).');

        $kotak = $sel->kotak ?? [];
        $gambar = @imagecreatefromstring((string) $disk->get($path));

        abort_if($gambar === false, 422, 'Citra hasil pindai rusak.');

        // Margin biar teknisi lihat sedikit di luar kotak selnya — angka yang
        // ketulis mepet garis jadi nggak kepotong pas dibandingin.
        $margin = 6;
        $potong = @imagecrop($gambar, [
            'x' => max(0, (int) ($kotak['x'] ?? 0) - $margin),
            'y' => max(0, (int) ($kotak['y'] ?? 0) - $margin),
            'width' => max(1, (int) ($kotak['w'] ?? 1) + $margin * 2),
            'height' => max(1, (int) ($kotak['h'] ?? 1) + $margin * 2),
        ]);

        abort_if($potong === false, 422, 'Kotak sel di luar batas citra.');

        ob_start();
        imagejpeg($potong, null, 85);
        $isi = (string) ob_get_clean();

        imagedestroy($gambar);
        imagedestroy($potong);

        return response($isi, 200, [
            'Content-Type' => 'image/jpeg',
            // Citra data pelanggan — jangan disimpen proxy/CDN mana pun.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Angka yang AKHIRNYA dipakai teknisi → ground truth.
     *
     * Ini jantung feedback loop-nya, dan sengaja dipisah dari submit lembar
     * kerja: yang masuk sini cuma angka yang udah lewat mata orang. Ngumpulin
     * dataset dari tebakan mentah cuma bikin mesin belajar dari kesalahannya
     * sendiri.
     */
    public function koreksi(Request $request, WorksheetScan $worksheetScan): JsonResponse
    {
        $this->pastikanBolehLihat($request, $worksheetScan);

        $data = $request->validate([
            'koreksi' => ['required', 'array', 'min:1', 'max:600'],
            'koreksi.*.kunci' => ['required', 'string', 'max:120'],
            'koreksi.*.nilai_final' => ['sometimes', 'nullable', 'numeric'],
        ]);

        $sel = $worksheetScan->cells()->get()->keyBy('kunci');
        $tidakDikenal = [];
        $cocok = 0;
        $meleset = 0;

        DB::transaction(function () use ($data, $sel, $request, &$tidakDikenal, &$cocok, &$meleset): void {
            foreach ($data['koreksi'] as $k) {
                /** @var WorksheetScanCell|null $baris */
                $baris = $sel->get($k['kunci']);

                if ($baris === null) {
                    $tidakDikenal[] = $k['kunci'];

                    continue;
                }

                $final = array_key_exists('nilai_final', $k) && $k['nilai_final'] !== null
                    ? (float) $k['nilai_final']
                    : null;

                // "Cocok" dibandingin di angka, bukan di teks: "4,10" & "4.1"
                // itu bacaan yang sama benarnya.
                $sama = $baris->nilai !== null && $final !== null
                    && abs((float) $baris->nilai - $final) < 1e-9;

                if ($final !== null) {
                    $sama ? $cocok++ : $meleset++;
                }

                $baris->forceFill([
                    'nilai_final' => $final,
                    'cocok' => $final === null && $baris->nilai === null ? true : $sama,
                    'dikoreksi_oleh' => $request->user()->id,
                    'dikoreksi_pada' => now(),
                ])->save();
            }
        });

        return response()->json([
            'data' => [
                'scan_id' => $worksheetScan->id,
                'tercatat' => count($data['koreksi']) - count($tidakDikenal),
                'kunci_tidak_dikenal' => $tidakDikenal,
                // Angka mentah buat dashboard akurasi. Yang dipantau ketat:
                // sel HIJAU yang ternyata dikoreksi (false green) — lihat
                // docs/SPEC-ocr-template-lokal.md §7.
                'cocok' => $cocok,
                'meleset' => $meleset,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $template
     * @param  array<string, mixed>  $hasil
     */
    private function simpan(
        WorksheetScanRequest $request,
        User $user,
        ?CalibrationSession $sesi,
        ?array $template,
        array $hasil,
    ): WorksheetScan {
        $disk = (string) config('ocr.penyimpanan.disk', 'local');
        $folder = (string) config('ocr.penyimpanan.folder', 'worksheet-scans').'/'.now()->format('Y/m');

        $scan = WorksheetScan::create([
            'organization_id' => $user->organization_id,
            'calibration_session_id' => $sesi?->id,
            'user_id' => $user->id,
            'template_id' => $request->string('template_id')->toString(),
            'template_versi' => $request->integer('template_versi'),
            'pipeline_versi' => (string) config('ocr.pipeline_versi'),
            'aturan_versi' => (string) config('ocr.aturan_versi'),
            'status' => $hasil['status'],
            'alasan_gagal' => $hasil['alasan_gagal'],
            'pesan' => $hasil['pesan'],
            'total_sel' => $hasil['ringkasan']['total_sel'],
            'sel_hijau' => $hasil['ringkasan']['hijau'],
            'sel_kuning' => $hasil['ringkasan']['kuning'],
            'sel_merah' => $hasil['ringkasan']['merah'],
            'sel_kosong' => $hasil['ringkasan']['kosong'],
            'kualitas' => $request->input('kualitas'),
            'geometri' => $request->input('geometri'),
            'perangkat' => $request->input('perangkat'),
            // Kiriman mentah disimpen APA ADANYA, termasuk buat scan yang
            // ditolak. Justru yang ditolak yang paling berguna waktu ambangnya
            // mau disetel ulang — tanpa itu, satu-satunya bukti yang tersisa
            // adalah teknisi yang bilang "kameranya nggak jalan".
            'payload' => $request->payload(),
            'hasil' => [
                'tabel' => $hasil['tabel'],
                'ringkasan' => $hasil['ringkasan'],
                'template_versi' => $template['versi'] ?? null,
                'kode_dokumen' => $template['kode_dokumen'] ?? null,
            ],
            'citra_path' => $request->file('citra')?->store($folder, $disk) ?: null,
            'citra_warp_path' => $request->file('citra_warp')?->store($folder, $disk) ?: null,
            'diambil_pada' => $request->input('diambil_pada'),
        ]);

        foreach ($hasil['sel'] as $s) {
            $scan->cells()->create([
                'kunci' => $s['kunci'],
                'tabel_id' => $s['tabel_id'],
                'baris_ke' => $s['baris_ke'],
                'repeat_no' => $s['repeat_no'],
                'field_id' => $s['field_id'],
                'titik_ukur' => $s['titik_ukur'],
                'standard_id' => $s['standard_id'],
                'teks_mentah' => $s['teks_mentah'],
                'teks_normal' => $s['teks_normal'],
                'nilai' => $s['nilai'],
                'confidence_ocr' => $s['confidence_ocr'],
                'confidence_akhir' => $s['confidence_akhir'],
                'status' => $s['status'],
                'alasan' => $s['alasan'],
                'normalisasi' => $s['normalisasi'],
                'kotak' => $s['kotak'],
            ]);
        }

        return $scan;
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return array<string, mixed>
     */
    private function bentukHasil(WorksheetScan $scan, array $hasil): array
    {
        return [
            'scan_id' => $scan->id,
            'status' => $hasil['status'],
            'template' => [
                'id' => $scan->template_id,
                'versi' => $scan->template_versi,
                'kode_dokumen' => $scan->hasil['kode_dokumen'] ?? null,
            ],
            'pipeline_versi' => $scan->pipeline_versi,
            'aturan_versi' => $scan->aturan_versi,
            'ringkasan' => $hasil['ringkasan'],
            'tabel' => $hasil['tabel'],
            // Ditegasin ke layar: sel merah nahan tombol simpan, sel kuning
            // nggak. Dihitung di server biar aturannya nggak beda-beda antar
            // versi APK.
            'boleh_auto_isi' => ($hasil['ringkasan']['merah'] ?? 0) === 0,
            'wajib_dicek' => ($hasil['ringkasan']['kuning'] ?? 0) > 0,
            'status_sel' => [
                ValidasiSel::HIJAU => 'valid, keyakinan tinggi — keisi otomatis',
                ValidasiSel::KUNING => 'keisi, wajib dicek teknisi',
                ValidasiSel::MERAH => 'nggak kebaca / nggak valid — isi manual',
                ValidasiSel::KOSONG => 'sel kosong di lembarnya',
            ],
        ];
    }

    /**
     * Alat buat template yang bentuknya ikut alat pelanggan. Dibatasi ke
     * organisasi pemakainya — id alat lab lain nggak boleh bisa dipakai buat
     * nengok bentuk lembar kerjanya.
     */
    private function alat(Request $request, mixed $equipmentId): ?Equipment
    {
        if ($equipmentId === null) {
            return null;
        }

        return Equipment::query()
            ->where('organization_id', $request->user()->organization_id)
            ->find((int) $equipmentId);
    }

    /**
     * Alat SEMU yang cuma membawa `organization_id` pemanggil.
     *
     * ## Kenapa null tidak boleh diteruskan
     *
     * `CalibrationProfile::masterStandarTertaut()` menyaring master standar
     * dengan `when($equipment?->organization_id !== null, ...)`. `when(false,
     * ...)` TIDAK memasang `where` apa pun, jadi `$alat` null berarti profilnya
     * memilih baris `standards` milik SEMUA lab — dan baris tabel template
     * membawa `standard_id` hasil pilihan itu. Teknisi lab A yang memanggil
     * `GET /api/worksheet-templates/{kode}` tanpa `equipment_id` menerima
     * template yang barisnya menunjuk kalibrator lab B, dan HP memakai id itu
     * apa adanya waktu mengirim hasil pindainya.
     *
     * Bentuk kebocoran yang SAMA sudah ditutup di
     * `CalibrationController::lembarKerja()` (lihat `LembarKerjaTidakBocorLintasLabTest`).
     * Ini pintu keduanya — lolos karena saringannya ada di profil, bukan di
     * controller, jadi tiap pemanggil baru harus mengingatnya sendiri.
     *
     * Sengaja TIDAK disimpan: dia cuma konteks saringan, bukan alat.
     */
    private function konteksOrganisasi(Request $request): Equipment
    {
        return new Equipment([
            'organization_id' => $request->user()->organization_id,
        ]);
    }

    /**
     * Sama aturannya kayak jalur AI Vision: sesi harus seorganisasi, dan teknisi
     * cuma boleh mindai lembar pekerjaannya sendiri.
     */
    private function sesiTervalidasi(mixed $sesiId, User $user): ?CalibrationSession
    {
        if ($sesiId === null) {
            return null;
        }

        $sesi = CalibrationSession::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail((int) $sesiId);

        abort_if(
            $user->role === User::ROLE_TEKNISI && $sesi->teknisi_id !== $user->id,
            403,
            'Cuma teknisi yang ngerjain sesi ini yang boleh mindai lembarnya.',
        );

        return $sesi;
    }

    private function pastikanBolehLihat(Request $request, WorksheetScan $scan): void
    {
        $user = $request->user();

        abort_if($scan->organization_id !== $user->organization_id, 404, 'Hasil pindai nggak ketemu.');

        abort_if(
            $user->role === User::ROLE_TEKNISI && $scan->user_id !== $user->id,
            403,
            'Hasil pindai ini punya teknisi lain.',
        );
    }
}
