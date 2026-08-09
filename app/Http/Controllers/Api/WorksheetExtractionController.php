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
 * AI Vision baca tabel lembar kerja dari foto — GANTINYA OCR di HP.
 *
 * Berlaku buat SEMUA jenis alat (pH, Turbidimeter, Chlorine, Refractometer),
 * bukan cuma pH: bentuk tabelnya sama, yang beda cuma petunjuk `satuan` /
 * `titik_nominal` / `desimal` yang dikirim mobile dari bentuk lembar kerja
 * profil alatnya. Buat Refractometer isinya `satuan=n20D` &
 * `titik_nominal=[1.33659, 1.39986]` — petunjuk nominal itu yang bikin model
 * nggak salah baca "1,3362" jadi "13362".
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
            // Petunjuk buat AI biar nangkap angka lebih akurat: satuan pembacaan,
            // nilai nominal tiap kolom (kiri→kanan), & jumlah desimal tipikalnya.
            // Mobile ngambil ini dari bentuk lembar kerja (larutan_standar/satuan).
            'satuan' => ['sometimes', 'nullable', 'string', 'max:16'],
            'titik_nominal' => ['sometimes', 'nullable', 'array', 'max:20'],
            'titik_nominal.*' => ['numeric'],
            'desimal' => ['sometimes', 'nullable', 'array', 'max:20'],
            'desimal.*' => ['integer', 'between:0,8'],
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
                $data['satuan'] ?? null,
                isset($data['titik_nominal'])
                    ? array_map('floatval', array_values($data['titik_nominal']))
                    : null,
                isset($data['desimal'])
                    ? array_map('intval', array_values($data['desimal']))
                    : null,
            );
        } catch (RuntimeException $e) {
            // Salah setup server (API key kosong) — bukan salah teknisi.
            report($e);

            // Ikut dicatat: kriteria SPEC-vision-ai-worksheet-extraction.md §5
            // minta log ditulis buat SETIAP percobaan, sukses maupun gagal.
            // Sebelumnya jalur ini return duluan, jadi kalau API key kelupaan
            // diisi di server, tombol foto teknisi mati tanpa nyisain jejak
            // apa pun di DB — yang kelihatan cuma "kok nggak jalan".
            WorksheetExtractionLog::create([
                'calibration_session_id' => $sesi?->id,
                'user_id' => $user->id,
                'model' => (string) config('services.anthropic.model'),
                'status' => 'belum_disetel',
                'error' => $e->getMessage(),
            ]);

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
            // Buat mastiin prompt caching beneran kena (SPEC-vision-prompt.md §5).
            // Harus > 0 mulai panggilan ke-2; kalau selalu 0/null berarti prefix
            // yang di-cache masih di bawah ambang minimum model — API-nya nggak
            // ngasih error buat kasus itu, jadi ini satu-satunya caranya kelihatan.
            'cache_read_input_tokens' => $hasil['usage']['cache_read_input_tokens'],
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
