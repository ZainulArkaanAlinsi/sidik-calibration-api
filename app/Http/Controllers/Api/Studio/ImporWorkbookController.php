<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\WorkbookMaster;
use App\Services\Studio\Hitung\PagarMemori;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\ImporWorkbook;
use App\Services\Studio\Workbook\KunciJenisWorkbook;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use App\Services\Studio\Workbook\PenyapuTempWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use SensitiveParameter;

/**
 * `POST /api/studio/workbook/impor` — unggah master ke Sidik Studio lewat API
 * (K-50-12; Render tanpa shell, jadi `studio:impor-workbook` tidak terjangkau).
 *
 * Semantiknya SAMA dengan perintah artisan karena memakai `ImporWorkbook` yang
 * sama: satu master AKTIF per jenis; impor di jenis yang sudah punya master
 * aktif MENGGANTINYA dan yang lama jadi riwayat (tidak dihapus); isi (sha256)
 * yang sudah ada di lab tidak diimpor dua kali (`status: sudah_ada`, 200).
 * Pelaku audit = pemilik token (`Auth::id()`), `diimpor_oleh` ikut dia, dan
 * `alasan` (wajib) tercatat di `catatan` baris baru → baris audit `dibikin`.
 *
 * Berkas asli disimpan di disk awet (`studio.disk_berkas`, bawaan `arsip` = R2
 * di produksi) supaya `/hitung` tetap jalan sesudah deploy.
 *
 * `sandi` (opsional, hanya untuk .xlsm bersandi) dipakai DI DALAM proses ini
 * lalu dibuang: tidak dilog, tidak disimpan, tidak dikembalikan, dan tidak
 * muncul di pesan galat (`#[SensitiveParameter]` + `GagalBacaWorkbook` tanpa
 * sandi). Berkas tanpa sandi tidak memintanya.
 *
 * Pagar: ukuran <= `studio.impor.maks_kb`; memori & waktu request web dipatok
 * (`studio.impor`); SATU impor sekali jalan (kunci cache) di atas throttle
 * `studio-impor`; temp privat dihapus di `finally`.
 *
 * Galat 422 (kode stabil): `sandi_diperlukan`, `sandi_workbook_salah`,
 * `berkas_bukan_ooxml`, `berkas_rusak`, `profil_enkripsi_ditolak`, `gagal_muat`,
 * `jenis_tidak_dikenal` (gantikan/kunci_jenis tidak sah), `format_tidak_didukung`.
 */
class ImporWorkbookController extends Controller
{
    private const EKSTENSI = ['xlsm', 'xlsx', 'csv'];

    public function __invoke(Request $request, ImporWorkbook $impor, PenyapuTempWorkbook $penyapu, PembacaXlsmWorkbook $pembaca): JsonResponse
    {
        $maks = (int) config('studio.impor.maks_kb', 30720);
        $data = $request->validate([
            'berkas' => ['required', 'file', "max:{$maks}"],
            'sandi' => ['sometimes', 'nullable', 'string', 'max:256'],
            'gantikan' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Alias lama; `gantikan` yang dipakai klien Studio.
            'gantikan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'kunci_jenis' => ['sometimes', 'nullable', 'string', 'max:'.KunciJenisWorkbook::PANJANG_MAKS],
            'alasan' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'berkas.required' => 'Berkas master wajib diunggah.',
            'berkas.file' => 'Berkas gagal diunggah (rusak atau melebihi batas server).',
            'berkas.max' => 'Berkas maksimal '.intdiv($maks, 1024).' MB.',
            'alasan.required' => 'Alasan impor wajib diisi (tercatat di jejak audit).',
            'alasan.min' => 'Alasan impor minimal 5 huruf.',
        ]);

        /** @var UploadedFile $berkas */
        $berkas = $request->file('berkas');
        $organisasi = Organization::query()->findOrFail($request->user()->organization_id);
        $kunci = $this->kunciJenis($organisasi, $data['gantikan'] ?? $data['gantikan_id'] ?? null, $data['kunci_jenis'] ?? null);

        if ($kunci instanceof JsonResponse) {
            return $kunci;
        }

        $batas = (int) config('studio.impor.batas_detik', 120);
        $kunciCache = Cache::lock('studio-impor', $batas + 60);

        if (! $kunciCache->get()) {
            return response()->json([
                'kode' => 'impor_berjalan',
                'message' => 'Masih ada impor lain yang berjalan. Tunggu selesai, lalu coba lagi.',
            ], 429, ['Retry-After' => '30']);
        }

        $memoriAsal = (string) ini_get('memory_limit');

        try {
            $this->pagarProses((string) config('studio.impor.memori', '448M'), $batas);
            $penyapu->sapu();

            return $this->jalankan($impor, $pembaca, $organisasi, $berkas, (string) ($data['sandi'] ?? ''), (int) $request->user()->id, $kunci, (string) $data['alasan']);
        } catch (GagalBacaWorkbook $e) {
            return response()->json([
                'kode' => $e->kode,
                'message' => $e->getMessage(),
            ], 422);
        } finally {
            $kunciCache->release();

            if (! app()->runningInConsole()) {
                @ini_set('memory_limit', $memoriAsal);
            }
        }
    }

    private function jalankan(ImporWorkbook $impor, PembacaXlsmWorkbook $pembaca, Organization $organisasi, UploadedFile $berkas, #[SensitiveParameter] string $sandi, int $userId, ?string $kunci, string $alasan): JsonResponse
    {
        $asli = (string) $berkas->getClientOriginalName();
        $ekstensi = strtolower(pathinfo($asli, PATHINFO_EXTENSION));
        $stem = $this->namaAman(pathinfo($asli, PATHINFO_FILENAME));

        if ($stem === '' || ! in_array($ekstensi, self::EKSTENSI, true)) {
            return response()->json([
                'kode' => 'format_tidak_didukung',
                'message' => 'Nama atau jenis berkas tidak didukung. Pakai .xlsm, .xlsx, atau .csv.',
            ], 422);
        }

        // Temp privat (di luar web root, dihapus di `finally`): berkas unggahan
        // diberi NAMA ASLI-nya karena nama workbook diturunkan dari nama berkas,
        // persis seperti impor lewat perintah.
        return $pembaca->denganTempPrivat(function (string $dir) use ($impor, $organisasi, $berkas, $sandi, $userId, $kunci, $alasan, $stem, $ekstensi): JsonResponse {
            if ($ekstensi === 'csv') {
                $folder = $dir.DIRECTORY_SEPARATOR.'csv';
                mkdir($folder, 0700);
                $berkas->move($folder, $stem.'.csv');

                try {
                    $ringkasan = $impor->imporCsv($folder, $stem, $stem.'.csv', $organisasi, $userId, false, false, $kunci, $alasan);
                } catch (RuntimeException $e) {
                    if ($e instanceof GagalBacaWorkbook) {
                        throw $e;
                    }

                    return response()->json([
                        'kode' => GagalBacaWorkbook::BERKAS_RUSAK,
                        'message' => 'CSV tidak bisa dibaca: '.mb_substr($e->getMessage(), 0, 160),
                    ], 422);
                }

                return $this->jawab($ringkasan);
            }

            $nama = $stem.'.'.$ekstensi;
            $jalur = $dir.DIRECTORY_SEPARATOR.$nama;
            $berkas->move($dir, $nama);

            if ($sandi === '' && PembacaXlsmWorkbook::bersandi($jalur)) {
                return response()->json([
                    'kode' => 'sandi_diperlukan',
                    'message' => 'Workbook ini bersandi. Isi sandi workbook-nya.',
                ], 422);
            }

            return $this->jawab($impor->imporXlsm($jalur, $sandi, $organisasi, $userId, false, false, $kunci, $alasan));
        });
    }

    /** @param  array<string, mixed>  $ringkasan */
    private function jawab(array $ringkasan): JsonResponse
    {
        return response()->json(
            ['data' => $ringkasan],
            $ringkasan['status'] === 'sudah_ada' ? 200 : 201,
            [],
            JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * `gantikan_id` / `kunci_jenis` -> kunci jenis (aturan sama dengan
     * `--gantikan` / `--kunci-jenis` di perintah artisan).
     */
    private function kunciJenis(Organization $organisasi, mixed $gantikan, mixed $ketik): string|JsonResponse|null
    {
        $kunci = null;

        if (is_string($ketik) && trim($ketik) !== '') {
            $kunci = KunciJenisWorkbook::normalkan($ketik);

            if ($kunci === null) {
                return $this->jenisTidakSah('kunci_jenis tidak sah (huruf kecil, angka, ":", "_", ".", "-").');
            }
        }

        if ($gantikan !== null && $gantikan !== '') {
            $lama = WorkbookMaster::query()
                ->where('organization_id', $organisasi->id)
                ->whereKey((int) $gantikan)
                ->first();

            if ($lama === null || $lama->diarsipkan_pada !== null) {
                return $this->jenisTidakSah("gantikan {$gantikan} bukan workbook di Studio lab ini.");
            }

            if ($kunci !== null && $kunci !== $lama->kunci_jenis) {
                return $this->jenisTidakSah("kunci_jenis bertentangan dengan jenis workbook #{$lama->id} ({$lama->kunci_jenis}).");
            }

            $kunci = (string) $lama->kunci_jenis;
        }

        return $kunci;
    }

    private function jenisTidakSah(string $pesan): JsonResponse
    {
        return response()->json(['kode' => 'jenis_tidak_dikenal', 'message' => $pesan], 422);
    }

    /** Stem nama berkas yang aman dipakai sebagai nama workbook & nama berkas temp. */
    private function namaAman(string $stem): string
    {
        $stem = trim((string) preg_replace('/[^\p{L}\p{N} ._()+\-]/u', '', basename(str_replace('\\', '/', $stem))));
        $stem = ltrim($stem, '.');

        return mb_substr($stem, 0, 120);
    }

    /**
     * Pagar keras request web (bukan konsol: di test/artisan `memory_limit` &
     * waktu berlaku seproses). Pola `HitungWorkbookController::aturMemori`.
     */
    private function pagarProses(string $memori, int $detik): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $ingin = PagarMemori::byte($memori);

        if ($ingin > 0 && PagarMemori::byte((string) ini_get('memory_limit')) !== $ingin) {
            ini_set('memory_limit', $memori);
        }

        @set_time_limit($detik);
    }
}
