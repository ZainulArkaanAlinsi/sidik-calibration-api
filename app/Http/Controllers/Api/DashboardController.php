<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Satu endpoint biar mobile nggak perlu nembak 5 endpoint sekaligus buat
 * ngisi 1 layar.
 *
 * Isinya beda tergantung role: teknisi cuma lihat kalibrasi miliknya sendiri,
 * admin & viewer lihat lintas-teknisi. Role diambil dari token, mobile nggak
 * ngirim apa-apa — kalau dikirim dari client, gampang dibohongin.
 */
class DashboardController extends Controller
{
    /** Panjang sumbu X grafik pekerjaan, dihitung mundur dari bulan berjalan. */
    private const BULAN_GRAFIK = 6;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->organization_id;

        $sesi = CalibrationSession::where('organization_id', $organizationId)
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn ($query) => $query->where('teknisi_id', $user->id),
            );

        return response()->json([
            'data' => [
                'total_alat' => Equipment::where('organization_id', $organizationId)->count(),
                'alat_overdue' => Equipment::where('organization_id', $organizationId)->overdue()->count(),
                'kalibrasi_draft' => (clone $sesi)->where('status', CalibrationSession::STATUS_DRAFT)->count(),
                'menunggu_approval' => (clone $sesi)->where('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)->count(),

                // Kartu "Kalibrasi selesai". Yang dihitung sesi DISETUJUI, bukan
                // sertifikat terbit: penerbitan PDF jalan di queue, jadi sesi yang
                // baru di-approve sempat kehitung "belum selesai" kalau workernya
                // lagi ngantre — padahal kerjaan teknisinya udah kelar.
                'kalibrasi_selesai' => (clone $sesi)->where('status', CalibrationSession::STATUS_DISETUJUI)->count(),

                // Kartu "Menunggu proses" = semua sesi yang masih butuh sentuhan
                // orang: digarap, nunggu diperiksa, atau balik minta revisi.
                // Sengaja ditulis "bukan disetujui" — kalau nanti ada status baru,
                // dia otomatis kehitung di sini, bukan diam-diam ilang dari kartu.
                'menunggu_proses' => (clone $sesi)
                    ->where('status', '!=', CalibrationSession::STATUS_DISETUJUI)
                    ->count(),

                'sertifikat_bulan_ini' => Certificate::where('organization_id', $organizationId)
                    ->where('status', Certificate::STATUS_TERBIT)
                    ->whereBetween('diterbitkan_pada', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),

                'grafik_pekerjaan' => $this->grafikPekerjaan(clone $sesi),
            ],
        ]);
    }

    /**
     * Data batang buat grafik pekerjaan: berapa sesi MASUK vs berapa yang
     * SELESAI tiap bulan, 6 bulan terakhir termasuk bulan berjalan.
     *
     * Pengelompokan per bulan dikerjain di PHP, bukan `GROUP BY DATE_FORMAT(...)`.
     * Produksi jalan di MySQL tapi test jalan di sqlite, dan fungsi tanggal
     * keduanya beda nama — query yang lolos test bakal meledak di server.
     *
     * Bulan yang nggak ada kerjaan tetap ikut keluar dengan nilai 0. Kalau
     * dilewat, grafiknya bohong: jeda kosong ketutup dan tren naik-turunnya
     * kelihatan lebih mulus dari kenyataan.
     *
     * @param  Builder<CalibrationSession>  $sesi
     * @return array<int, array<string, mixed>>
     */
    private function grafikPekerjaan(Builder $sesi): array
    {
        $mulai = now()->startOfMonth()->subMonths(self::BULAN_GRAFIK - 1);
        $sampai = now()->endOfMonth();

        // `tanggal_kalibrasi` = kapan alat dikerjain, `reviewed_at` = kapan admin
        // nyetujuin. Dua-duanya ditarik sekali jalan lalu dihitung di memori —
        // 6 bulan sesi 1 lab masih jauh dari bikin berat.
        $masuk = (clone $sesi)
            ->whereBetween('tanggal_kalibrasi', [$mulai, $sampai])
            ->pluck('tanggal_kalibrasi')
            ->countBy(fn (Carbon $tanggal): string => $tanggal->format('Y-m'));

        $selesai = (clone $sesi)
            ->where('status', CalibrationSession::STATUS_DISETUJUI)
            ->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', [$mulai, $sampai])
            ->pluck('reviewed_at')
            ->countBy(fn (Carbon $tanggal): string => $tanggal->format('Y-m'));

        return collect(range(self::BULAN_GRAFIK - 1, 0))
            ->map(function (int $mundur) use ($masuk, $selesai): array {
                $bulan = now()->startOfMonth()->subMonths($mundur);
                $kunci = $bulan->format('Y-m');

                return [
                    'bulan' => $kunci,
                    // Label siap pakai buat sumbu X — biar mobile nggak perlu
                    // nerjemahin nama bulan sendiri di tiap layar.
                    'label' => $bulan->translatedFormat('M Y'),
                    'masuk' => $masuk->get($kunci, 0),
                    'selesai' => $selesai->get($kunci, 0),
                ];
            })
            ->all();
    }
}
