<?php

namespace App\Http\Controllers\Api;

use App\Events\PerubahanDataOrganisasi;
use App\Http\Controllers\Controller;
use App\Http\Requests\DaftarSesiRequest;
use App\Http\Resources\CalibrationDiarsipkanResource;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\DaftarSesiKalibrasi;
use App\Services\JejakAlasan;
use App\Services\PenjagaOrganisasi;
use App\Support\CatatanAlasan;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Arsip draf lembar kerja: arsipkan, pulihkan, dan daftar arsip (paket CRUD
 * 18 Nov 2026).
 *
 * ## Apa yang TIDAK dilakukan
 *
 * Tidak ada yang dihapus. Arsip hanya mengisi `calibration_sessions.deleted_at`:
 * `raw_measurements`, `uncertainty_calculations`, nomor sesi, dan seluruh isi
 * sesi utuh (AGENTS.md §Peran butir 5, ISO/IEC 17025 7.5.2). Draf belum punya
 * sertifikat, jadi tidak ada nomor sertifikat yang tersentuh.
 *
 * ## Aturan
 *
 * - Hanya status `draft`. `menunggu_approval`/`perlu_revisi`/`disetujui` -> 422
 *   `bukan_draf` (sesi yang pernah dikirim punya jalur koreksinya sendiri).
 *   Draf yang berasal dari lembar yang PERNAH dikirim (`input_asli` atau
 *   `submitted_at` terisi) -> 422 `pernah_dikirim`.
 * - Pembuat draf (teknisi pemilik) atau Master Data/super admin. Teknisi pada
 *   draf orang lain -> 404, sama dengan `GET /calibrations/{id}`.
 * - Pulihkan: hanya Master Data/super admin (gerbang rute `role:admin`).
 * - Alasan, pelaku, waktu hidup di `audit_logs` (aksi `diarsipkan`) dan
 *   dibaca balik lewat `JejakAlasan`; tidak ada kolom baru selain `deleted_at`.
 * - Ditulis lewat UPDATE bersyarat (`status = draft`) + `catatAudit()`,
 *   bukan `$sesi->delete()`: dua kiriman berbarengan (satu mengarsipkan, satu
 *   mengirim ke approval) tidak bisa sama-sama menang, dan satu perubahan
 *   hanya menulis SATU baris audit yang membawa alasannya.
 */
class ArsipDrafSesiController extends Controller
{
    public function arsipkan(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $sesi = $this->pastikanBolehKelola($request, $calibration);
        $data = $request->validate(['alasan' => CatatanAlasan::aturan()]);
        $pemanggil = $request->user();

        $hasil = DB::transaction(function () use ($sesi, $data): array {
            $baris = CalibrationSession::withTrashed()->whereKey($sesi->id)->lockForUpdate()->firstOrFail();

            if ($baris->trashed()) {
                return ['sudah', null];
            }

            if ($baris->status !== CalibrationSession::STATUS_DRAFT) {
                return ['bukan_draf', $baris->status];
            }

            // Draf yang LAHIR dari lembar yang pernah dikirim (dikembalikan lalu
            // disimpan lagi sebagai draf) tetap berstatus `draft`, tetapi
            // `input_asli` (isian pertama teknisi, tulis-sekali) dan
            // `submitted_at` sudah terisi, dan `catatan_revisi` admin menempel di
            // sana. Mengarsipkannya menghilangkan teguran admin dari antrean
            // teknisi; jalur koreksinya tetap pengembalian/revisi.
            if ($baris->input_asli !== null || $baris->submitted_at !== null) {
                return ['pernah_dikirim', null];
            }

            $sekarang = now();

            $berubah = CalibrationSession::query()
                ->whereKey($baris->id)
                ->where('status', CalibrationSession::STATUS_DRAFT)
                ->whereNull('input_asli')
                ->whereNull('submitted_at')
                ->update(['deleted_at' => $sekarang, 'updated_at' => $sekarang]);

            if ($berubah !== 1) {
                // Status bergeser di antara kunci dan tulis -- hanya mungkin di
                // mesin tanpa kunci baris. Jangan menimpa; minta klien memuat ulang.
                return ['status_berubah', null];
            }

            $baris->catatAudit(
                AuditLog::ACTION_DIARSIPKAN,
                ['status' => CalibrationSession::STATUS_DRAFT, 'deleted_at' => null],
                ['deleted_at' => $sekarang->toIso8601String(), 'alasan' => $data['alasan']],
                CatatanAlasan::note(CatatanAlasan::AWALAN_ARSIPKAN, $data['alasan']),
            );

            return ['ok', null];
        });

        if ($hasil[0] === 'bukan_draf') {
            return response()->json([
                'message' => 'Hanya draf yang bisa diarsipkan; sesi ini sudah '.$hasil[1].'.',
                'kode' => 'bukan_draf',
                'status' => $hasil[1],
            ], 422);
        }

        if ($hasil[0] === 'pernah_dikirim') {
            return response()->json([
                'message' => 'Draf ini berasal dari lembar yang pernah dikirim lalu dikembalikan, jadi tidak bisa diarsipkan. '
                    .'Lanjutkan lembarnya dan kirim ulang.',
                'kode' => 'pernah_dikirim',
            ], 422);
        }

        if ($hasil[0] === 'status_berubah') {
            return response()->json([
                'message' => 'Status sesi ini baru saja berubah. Muat ulang lalu coba lagi.',
                'kode' => 'status_berubah',
            ], 409);
        }

        if ($hasil[0] === 'ok') {
            PerubahanDataOrganisasi::siarkanAman($sesi->organization_id, 'kalibrasi', 'diubah', $sesi->id);
        }

        $jejak = JejakAlasan::terakhir(
            (int) $pemanggil->organization_id,
            'calibration_sessions',
            [(int) $sesi->id],
            AuditLog::ACTION_DIARSIPKAN,
        );

        return response()->json([
            'message' => $hasil[0] === 'sudah' ? 'Draf sudah diarsipkan.' : 'Draf diarsipkan.',
            'data' => [
                'id' => $sesi->id,
                'nomor_sesi' => $sesi->nomor_sesi,
                'status' => $sesi->status,
                'diarsipkan' => true,
                'arsip' => $jejak[$sesi->id] ?? null,
            ],
        ]);
    }

    public function pulihkan(Request $request, CalibrationSession $calibration): JsonResponse
    {
        // Org guard saja: gerbang `role:admin` di rute sudah memastikan hanya
        // Master Data/super admin yang sampai sini, dan keduanya melihat draf
        // semua teknisi se-lab.
        PenjagaOrganisasi::pastikanSatu($request, $calibration);

        $data = $request->validate(['alasan' => CatatanAlasan::aturan(wajib: false)]);

        $hasil = DB::transaction(function () use ($calibration, $data): string {
            $baris = CalibrationSession::withTrashed()->whereKey($calibration->id)->lockForUpdate()->firstOrFail();

            if (! $baris->trashed()) {
                return 'tidak_diarsipkan';
            }

            $sebelum = $baris->deleted_at?->toIso8601String();

            CalibrationSession::withTrashed()
                ->whereKey($baris->id)
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null, 'updated_at' => now()]);

            $baris->catatAudit(
                AuditLog::ACTION_DIPULIHKAN,
                ['deleted_at' => $sebelum],
                ['deleted_at' => null, 'alasan' => $data['alasan'] ?? null],
                CatatanAlasan::note(CatatanAlasan::AWALAN_PULIHKAN, $data['alasan'] ?? null),
            );

            return 'ok';
        });

        if ($hasil === 'tidak_diarsipkan') {
            return response()->json([
                'message' => 'Sesi ini tidak sedang diarsipkan.',
                'kode' => 'tidak_diarsipkan',
            ], 422);
        }

        PerubahanDataOrganisasi::siarkanAman($calibration->organization_id, 'kalibrasi', 'diubah', $calibration->id);

        return response()->json([
            'message' => 'Draf dipulihkan.',
            'data' => [
                'id' => $calibration->id,
                'nomor_sesi' => $calibration->nomor_sesi,
                'status' => $calibration->status,
                'diarsipkan' => false,
            ],
        ]);
    }

    /**
     * `GET /api/calibrations?diarsipkan=1` -- dipanggil `CalibrationController::index()`.
     * Hanya Master Data/super admin; peran lain 403 `hanya_admin`.
     */
    public function daftar(DaftarSesiRequest $request, DaftarSesiKalibrasi $daftar): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user->punyaHakAdmin()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Arsip draf hanya bisa dilihat Master Data.',
                'kode' => 'hanya_admin',
            ], 403));
        }

        $sesi = CalibrationSession::onlyTrashed()
            ->with(DaftarSesiKalibrasi::relasiRingan())
            ->withExists(['penyembunyi as tersembunyi' => fn ($q) => $q->where('users.id', $user->id)])
            ->where('organization_id', $user->organization_id)
            ->tap(fn ($query) => $daftar->terapkan($query, $user->organization_id, $request->validated()))
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        $jejak = JejakAlasan::terakhir(
            (int) $user->organization_id,
            'calibration_sessions',
            $sesi->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            AuditLog::ACTION_DIARSIPKAN,
        );

        $sesi->getCollection()->each(
            fn (CalibrationSession $s) => $s->setAttribute('arsip', $jejak[$s->id] ?? null),
        );

        return CalibrationDiarsipkanResource::collection($sesi);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sesi milik lab pemanggil, dan -- untuk teknisi -- miliknya sendiri.
     * Selain itu 404 (bukan 403): tidak mengakui bahwa barisnya ada.
     */
    private function pastikanBolehKelola(Request $request, CalibrationSession $sesi): CalibrationSession
    {
        PenjagaOrganisasi::pastikanSatu($request, $sesi);

        /** @var User $user */
        $user = $request->user();

        abort_if(! $user->punyaHakAdmin() && (int) $sesi->teknisi_id !== (int) $user->id, 404);

        return $sesi;
    }
}
