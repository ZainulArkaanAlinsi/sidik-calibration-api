<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Models\WorkbookMaster;
use App\Services\Studio\Hitung\GagalHitung;
use App\Services\Studio\Hitung\HitungWorkbook;
use App\Services\Studio\Hitung\PagarMemori;
use App\Services\Studio\Workbook\ProteksiLembar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sidik Studio tahap C — Lembar Hitung interaktif "persis Excel":
 * `POST /api/studio/workbook/{workbook}/hitung` (kontrak docs/kontrak-api.md §16).
 *
 * Izin: admin, teknisi, super_admin (grup rute saudara `role:admin,teknisi,super_admin`
 * di ekor routes/api.php — super admin tidak bisa POST di grup luar). Viewer &
 * pelanggan ditolak gerbang; workbook lab lain → 404 (`PenjagaOrganisasi`).
 *
 * Rute ini TIDAK menulis apa pun ke DB atau disk data lab: hitung "bagaimana
 * jika" yang stateless (satu-satunya tulis = cache model hitung turunan berkas
 * asli). Super admin boleh — dikonfirmasi pemilik K-49-15 (15 Okt 2026):
 * kendali Studio ada di super_admin.
 *
 * Proteksi lembar Excel (K-49-15): sel TERKUNCI di lembar yang dilindungi
 * hanya boleh disunting super_admin; selain itu 422 `sel_terkunci`
 * (`ProteksiLembar`). Server yang memutuskan, bukan tombol di klien.
 */
class HitungWorkbookController extends Controller
{
    public function __invoke(Request $request, WorkbookMaster $workbook, HitungWorkbook $hitung): JsonResponse
    {
        // Organisasi dulu, baru validasi: workbook lab lain → 404 apa pun isinya.
        // Workbook yang diarsipkan (dikeluarkan dari Studio, K-49-13) juga 404;
        // riwayat tetap boleh dihitung.
        WorkbookController::pastikanDiStudio($request, $workbook);

        $maks = (int) config('studio.hitung.maks_edit', 300);
        $data = $request->validate([
            'edit' => ['present', 'array', "max:{$maks}"],
            'edit.*' => ['array:lembar_id,a,isi'],
            'edit.*.lembar_id' => ['required', 'integer'],
            'edit.*.a' => ['required', 'string', 'max:12'],
            'edit.*.isi' => ['present', 'nullable', 'string', 'max:32767'],
            'waktu_acuan' => ['sometimes', 'nullable', 'date'],
        ], [
            'edit.present' => 'Daftar edit wajib ada (boleh kosong).',
            'edit.max' => "Maksimal {$maks} edit per hitung.",
            'edit.*.lembar_id.required' => 'lembar_id wajib.',
            'edit.*.a.required' => 'Alamat sel wajib.',
            'edit.*.isi.present' => 'isi wajib ada ("" = kosongkan).',
            'edit.*.isi.max' => 'Isi sel maksimal 32767 karakter.',
            'waktu_acuan.date' => 'waktu_acuan harus tanggal-waktu ISO-8601.',
        ]);

        // Pagar keras di bawah RAM container (tinjau C-3 #1); pagar lunak `PagarMemori` di 0,8×.
        // Request web: TEPAT nilai config (juga menurunkan bila php.ini lebih besar/-1),
        // supaya ledakan jadi PHP Fatal satu request, bukan OOM-kill container. Konsol
        // (test, artisan): hanya menaikkan — memory_limit berlaku seproses.
        $this->aturMemori((string) config('studio.hitung.memori', '384M'), ! app()->runningInConsole());
        // Jaring kedua di atas batas kooperatif 20 s. Hanya untuk request web: di
        // konsol (test, artisan) set_time_limit berlaku seproses dan mematikan
        // pekerjaan lain yang kebetulan lebih lama.
        if (! app()->runningInConsole()) {
            @set_time_limit((int) ceil((float) config('studio.hitung.batas_detik', 20)) + 40);
        }

        try {
            $hasil = $hitung->jalankan(
                $workbook,
                array_values($data['edit']),
                $data['waktu_acuan'] ?? null,
                ProteksiLembar::bolehMelewati($request->user()),
            );
        } catch (GagalHitung $e) {
            return response()->json(array_filter([
                'message' => $e->getMessage(),
                'kode' => $e->kode,
                'errors' => $e->errors !== [] ? $e->errors : null,
            ] + $e->tambahan, static fn ($v): bool => $v !== null), $e->status);
        }

        return response()->json(['data' => $hasil], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function aturMemori(string $target, bool $tepat): void
    {
        $ingin = PagarMemori::byte($target);

        if ($ingin <= 0) {
            return;
        }

        $sekarang = PagarMemori::byte((string) ini_get('memory_limit'));

        // -1 = tanpa batas: di web tetap dipasang batasnya; di konsol dibiarkan.
        if ($tepat ? $sekarang !== $ingin : ($sekarang !== -1 && $sekarang < $ingin)) {
            ini_set('memory_limit', $target);
        }
    }
}
