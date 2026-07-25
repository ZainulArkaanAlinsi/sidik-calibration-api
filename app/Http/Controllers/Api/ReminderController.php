<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PengingatJatuhTempo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pemicu MANUAL pengingat jatuh tempo (spec poin 6). Selain jalan otomatis tiap
 * pagi lewat scheduler, admin bisa mencet tombol ini buat langsung ngirim
 * pengingat sekarang — mis. sesudah baru nge-set tanggal jatuh tempo alat.
 */
class ReminderController extends Controller
{
    public function jatuhTempo(Request $request, PengingatJatuhTempo $pengingat): JsonResponse
    {
        $data = $request->validate([
            // Opsional: paksa ambang H- sekian hari buat run ini; kosong = pakai
            // setting organisasi (organization.settings.reminder_hari_sebelum).
            'hari' => ['sometimes', 'nullable', 'integer', 'between:1,365'],
        ]);

        $org = $request->user()->organization;

        $ringkasan = $pengingat->untukOrganisasi($org, $data['hari'] ?? null)
            ?? [
                'organization_id' => $org->id,
                'ambang_hari' => $data['hari'] ?? $pengingat->ambangOrganisasi($org),
                'overdue' => 0,
                'mendekati' => 0,
                'admin_dikabarin' => 0,
            ];

        return response()->json([
            'data' => $ringkasan,
            'message' => $ringkasan['admin_dikabarin'] > 0
                ? 'Pengingat jatuh tempo dikirim ke admin.'
                : 'Nggak ada alat yang mendekati/lewat jatuh tempo untuk saat ini.',
        ]);
    }
}
