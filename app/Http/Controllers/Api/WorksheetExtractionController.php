<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\WorksheetVisionExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * AI Vision baca tabel lembar kerja pH dari foto — GANTINYA OCR di HP.
 *
 * Endpoint: POST /api/raw-measurements/extract-from-photo (SPEC-vision-prompt.md §8).
 * Satu foto = satu tabel (Before ATAU After adjustment). Balik { baris: [...] }
 * + skor keyakinan per sel. Hasilnya SENGAJA nggak disimpen: teknisi
 * konfirmasi/koreksi dulu (sel "low" ditandai), baru submit lewat
 * POST/PUT /calibrations (alur yang udah ada). Kalau AI gagal/nolak, teknisi
 * TETAP bisa isi manual — kamera mempercepat, bukan syarat.
 */
class WorksheetExtractionController extends Controller
{
    public function extract(Request $request, WorksheetVisionExtractor $extractor): JsonResponse
    {
        // Nama field ngikut yang dikirim mobile (ApiWorksheetVisionService):
        // `foto`, `jumlah_titik`, `jumlah_pengulangan`, `calibration_session_id`.
        $data = $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'jumlah_titik' => ['sometimes', 'nullable', 'integer', 'between:1,20'],
            'jumlah_pengulangan' => ['sometimes', 'nullable', 'integer', 'between:1,50'],
            // Opsional: sesi baru belum punya id. Kalau dikirim → batasin akses & tautin log.
            'calibration_session_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'foto.required' => 'Fotonya wajib ada.',
            'foto.image' => 'File-nya harus berupa gambar.',
            'foto.max' => 'Foto maksimal 8 MB.',
        ]);

        $user = $request->user();
        $sesi = $this->sesiTervalidasi($data['calibration_session_id'] ?? null, $user);

        $file = $request->file('foto');

        try {
            $hasil = $extractor->extract(
                (string) file_get_contents($file->getRealPath()),
                (string) $file->getMimeType(),
                $data['jumlah_titik'] ?? null,
                $data['jumlah_pengulangan'] ?? null,
            );
        } catch (RuntimeException $e) {
            // Salah setup server (API key kosong) — bukan salah teknisi.
            report($e);

            return response()->json([
                'message' => 'Fitur pindai AI belum aktif di server. Gunakan input manual dulu.',
            ], 503);
        }

        // Catat SETIAP percobaan (sukses/gagal) buat audit & bahan tuning prompt (SPEC §7).
        WorksheetExtractionLog::create([
            'calibration_session_id' => $sesi?->id,
            'user_id' => $user->id,
            'model' => $hasil['model'],
            'status' => $hasil['status'],
            'raw_model_response' => $hasil['raw'],
            'extracted' => $hasil['data'],
            'input_tokens' => $hasil['usage']['input_tokens'],
            'output_tokens' => $hasil['usage']['output_tokens'],
            'error' => $hasil['error'],
        ]);

        if (! $hasil['ok']) {
            // 422: kebaca tapi hasil nggak kepakai. Mobile nampilin pesan +
            // tombol "Isi manual" / "Foto ulang".
            return response()->json([
                'message' => $hasil['error'] ?? 'Gagal membaca lembar kerja dari foto.',
                'fallback_manual' => true,
            ], 422);
        }

        // `baris` di TOP-LEVEL (bukan dibungkus `data`) — sesuai bentuk yang
        // di-parse ApiWorksheetVisionService (docs/permintaan-backend-2026-07-24.md §4).
        // `meta` tambahan; key asing diabaikan mobile.
        return response()->json([
            'baris' => $hasil['data']['baris'],
            'meta' => [
                'model' => $hasil['model'],
                // Ringkasan buat mobile: ada nggak sel yang perlu dicek manual.
                'perlu_dicek' => $this->adaKeyakinanRendah($hasil['data']['baris'] ?? []),
            ],
        ]);
    }

    /**
     * Kalau session dikirim: pastikan seorganisasi & (buat teknisi) miliknya
     * sendiri. Kalau nggak dikirim: ekstraksi tetap jalan tanpa tautan sesi
     * (cuma baca foto, nggak buka data apa pun).
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

    /** @param array<int, array<string, mixed>> $baris */
    private function adaKeyakinanRendah(array $baris): bool
    {
        foreach ($baris as $b) {
            foreach (['ph_keyakinan', 'suhu_keyakinan'] as $kolom) {
                if (in_array('low', (array) ($b[$kolom] ?? []), true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
