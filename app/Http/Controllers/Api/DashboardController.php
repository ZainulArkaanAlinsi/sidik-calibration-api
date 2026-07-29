<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Services\TrenPekerjaan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

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

    public function __construct(private readonly TrenPekerjaan $tren) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->organization_id;

        // Penyaring per-role dipegang `TrenPekerjaan::query()`, dipakai bareng
        // `/dashboard/tren` — biar dua layar nggak bisa beda soal "sesi siapa yang
        // kehitung".
        $sesi = $this->tren->query($user);

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
     * Grafik tren rentang bebas (permintaan-endpoint.md §2).
     *
     * Angkanya dari service yang SAMA dengan `grafik_pekerjaan` di `/dashboard`.
     * Kalau kepisah, dua grafik di app yang sama bisa nunjukin angka beda buat
     * bulan yang sama, dan yang lihat nggak punya cara tahu mana yang bener.
     */
    public function tren(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'satuan' => ['sometimes', 'nullable', Rule::in(TrenPekerjaan::satuan())],
            'dari' => ['sometimes', 'nullable', 'date'],
            'sampai' => ['sometimes', 'nullable', 'date', 'after_or_equal:dari'],
        ], [
            'satuan.in' => 'Satuan cuma bisa: '.implode(', ', TrenPekerjaan::satuan()).'.',
            'sampai.after_or_equal' => 'Tanggal "sampai" nggak boleh sebelum tanggal "dari".',
        ]);

        $satuan = $data['satuan'] ?? TrenPekerjaan::SATUAN_BULAN;
        $sampai = filled($data['sampai'] ?? null) ? Carbon::parse($data['sampai']) : now();
        $dari = filled($data['dari'] ?? null)
            ? Carbon::parse($data['dari'])
            // Default: sepanjang grafik Dashboard, biar layar tren yang dibuka
            // tanpa penyaring langsung nunjukin angka yang sama kayak di Dashboard.
            : $this->awalDefault($sampai, $satuan);

        $jumlah = $this->tren->jumlahPeriode($dari, $sampai, $satuan);

        // Ditolak, BUKAN dipotong. Hasil yang dipotong sepi-sepi bikin orang salah
        // baca tren — dan 400 batang di layar HP itu garis abu-abu, bukan grafik.
        if ($jumlah > TrenPekerjaan::MAKS_PERIODE) {
            return response()->json([
                'message' => "Rentangnya kepanjangan buat satuan `{$satuan}` ({$jumlah} periode, "
                    .'maksimal '.TrenPekerjaan::MAKS_PERIODE.'). Sempitin rentangnya atau naikin satuan.',
                'errors' => ['satuan' => ['Rentang & satuan ini bikin terlalu banyak titik.']],
            ], 422);
        }

        return response()->json([
            'data' => $this->tren->hitung($this->tren->query($user), $dari, $sampai, $satuan),
            'penyaring' => [
                'dari' => $dari->toDateString(),
                'sampai' => $sampai->toDateString(),
                'satuan' => $satuan,
            ],
        ]);
    }

    /** Panjang bawaan tiap satuan kalau `dari` nggak dikirim. */
    private function awalDefault(Carbon $sampai, string $satuan): Carbon
    {
        return match ($satuan) {
            TrenPekerjaan::SATUAN_HARI => $sampai->copy()->subDays(29),
            TrenPekerjaan::SATUAN_MINGGU => $sampai->copy()->subWeeks(11),
            // `subMonthsNoOverflow` — sama alasannya dengan `addMonthNoOverflow`
            // di `TrenPekerjaan::majukan()`, cuma arah sebaliknya. `subMonths()`
            // polos dari tanggal 29-31 nyelonong maju kalau bulan sasarannya
            // lebih pendek: 29 Jul 2026 - 5 bulan = 29 Feb yang nggak ada, jadi
            // 1 Maret. `awalPeriode()` nge-startOfMonth-in itu ke Maret, dan
            // grafiknya keluar 5 bulan, bukan 6.
            //
            // Yang bikin ini susah ketangkep: dia cuma salah di tanggal ujung
            // bulan, jadi bug-nya ilang-timbul ngikut kalender dan gampang
            // dikira flaky. `grafikPekerjaan()` kebetulan kebal karena dia
            // `startOfMonth()` duluan — jadi /dashboard ngasih 6 bulan sementara
            // /dashboard/tren ngasih 5, dua grafik beda buat rentang yang sama.
            default => $sampai->copy()->subMonthsNoOverflow(self::BULAN_GRAFIK - 1),
        };
    }

    /**
     * Data batang buat grafik pekerjaan: berapa sesi MASUK vs berapa yang
     * SELESAI tiap bulan, 6 bulan terakhir termasuk bulan berjalan.
     *
     * Angkanya dari `TrenPekerjaan`, service yang sama yang dipakai
     * `/dashboard/tren`.
     *
     * Kuncinya tetap `bulan`, dan `periode` SENGAJA nggak ditambahin sebagai alias.
     * `kontrak-api.md` §7 udah bilang eksplisit "namanya `bulan`, bukan `periode`;
     * yang ngirim `periode` cuma /dashboard/tren" — dan mobile pernah kena bug
     * nyata gara-gara ketuker (sumbu X kosong melompong padahal semua test ijo).
     * Bikin dua-duanya jalan kedengerannya membantu, tapi itu ngaburin aturan yang
     * udah jelas: satu endpoint satu nama.
     *
     * @param  Builder<CalibrationSession>  $sesi
     * @return array<int, array<string, mixed>>
     */
    private function grafikPekerjaan(Builder $sesi): array
    {
        $baris = $this->tren->hitung(
            $sesi,
            now()->startOfMonth()->subMonths(self::BULAN_GRAFIK - 1),
            now(),
            TrenPekerjaan::SATUAN_BULAN,
        );

        return array_map(fn (array $b): array => [
            'bulan' => $b['periode'],
            'label' => $b['label'],
            'masuk' => $b['masuk'],
            'selesai' => $b['selesai'],
        ], $baris);
    }
}
