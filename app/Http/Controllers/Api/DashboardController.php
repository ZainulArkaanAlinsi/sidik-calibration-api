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

    /** Batas jumlah titik yang boleh dibalikin `/tren` dalam sekali panggil. */
    private const MAKS_TITIK_TREN = 400;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->organization_id;

        $sesi = $this->sesiSesuaiRole($request);

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
     * Deret waktu masuk-vs-selesai dengan rentang & granularitas bebas.
     *
     * Beda sama `grafik_pekerjaan` di `index()` yang dikunci 6 bulan terakhir:
     * yang ini dipakai halaman Perhitungan yang butuh milih rentang sendiri.
     *
     * `GET /api/dashboard/tren?dari=2026-05-01&sampai=2026-07-31&satuan=bulan`
     */
    public function tren(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dari' => ['sometimes', 'date'],
            'sampai' => ['sometimes', 'date', 'after_or_equal:dari'],
            'satuan' => ['sometimes', Rule::in(['hari', 'minggu', 'bulan'])],
        ], [
            'sampai.after_or_equal' => 'Tanggal "sampai" nggak boleh lebih awal dari "dari".',
            'satuan.in' => 'Satuan harus salah satu dari: hari, minggu, bulan.',
        ]);

        $satuan = $validated['satuan'] ?? 'bulan';

        // Default 6 bulan terakhir — samain sama grafik di dashboard, biar
        // mobile bisa manggil polos tanpa mikir rentang.
        $sampai = isset($validated['sampai']) ? Carbon::parse($validated['sampai']) : now();
        $dari = isset($validated['dari'])
            ? Carbon::parse($validated['dari'])
            : (clone $sampai)->subMonths(5)->startOfMonth();

        $mulai = $this->awalPeriode($dari, $satuan);
        $akhir = $this->awalPeriode($sampai, $satuan);

        // Tanpa batas ini, `satuan=hari` dengan rentang 10 tahun bakal ngebentuk
        // ~3650 titik: berat buat server, dan nggak ada grafik yang kebaca.
        $jumlahTitik = $this->hitungTitik($mulai, $akhir, $satuan);

        if ($jumlahTitik > self::MAKS_TITIK_TREN) {
            return response()->json([
                'message' => "Rentangnya kepanjangan buat satuan '{$satuan}' — "
                    ."ngehasilin {$jumlahTitik} titik, maksimum ".self::MAKS_TITIK_TREN.'. '
                    .'Persempit rentangnya atau pakai satuan yang lebih besar.',
            ], 422);
        }

        $sesi = $this->sesiSesuaiRole($request);
        $batas = [$mulai, (clone $akhir)->add($this->satuanKeInterval($satuan))->subSecond()];

        $masuk = (clone $sesi)
            ->whereBetween('tanggal_kalibrasi', $batas)
            ->pluck('tanggal_kalibrasi')
            ->countBy(fn (Carbon $tanggal): string => $this->kunciPeriode($tanggal, $satuan));

        $selesai = (clone $sesi)
            ->where('status', CalibrationSession::STATUS_DISETUJUI)
            ->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', $batas)
            ->pluck('reviewed_at')
            ->countBy(fn (Carbon $tanggal): string => $this->kunciPeriode($tanggal, $satuan));

        $data = [];
        $kursor = clone $mulai;

        while ($kursor <= $akhir) {
            $kunci = $this->kunciPeriode($kursor, $satuan);

            // Periode kosong tetap dikirim dengan nol, nggak di-skip — kalau
            // dilewat, sumbu waktunya bolong dan trennya kebaca bohong.
            $data[] = [
                'periode' => $kunci,
                'masuk' => $masuk->get($kunci, 0),
                'selesai' => $selesai->get($kunci, 0),
            ];

            $kursor->add($this->satuanKeInterval($satuan));
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Sesi yang boleh dilihat pemanggil. Teknisi cuma miliknya sendiri; role
     * diambil dari token, bukan dari query param — kalau dari client, gampang
     * dibohongin.
     *
     * @return Builder<CalibrationSession>
     */
    private function sesiSesuaiRole(Request $request): Builder
    {
        $user = $request->user();

        return CalibrationSession::where('organization_id', $user->organization_id)
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn ($query) => $query->where('teknisi_id', $user->id),
            );
    }

    /** `hari` → `2026-05-01`, `minggu` → tanggal Senin-nya, `bulan` → `2026-05`. */
    private function kunciPeriode(Carbon $tanggal, string $satuan): string
    {
        return match ($satuan) {
            'hari' => $tanggal->format('Y-m-d'),
            // Dipakai tanggal awal minggu, bukan "2026-W18": mobile bisa
            // langsung `DateTime.parse()` buat naruh titik di sumbu waktu.
            'minggu' => (clone $tanggal)->startOfWeek()->format('Y-m-d'),
            default => $tanggal->format('Y-m'),
        };
    }

    private function awalPeriode(Carbon $tanggal, string $satuan): Carbon
    {
        return match ($satuan) {
            'hari' => (clone $tanggal)->startOfDay(),
            'minggu' => (clone $tanggal)->startOfWeek(),
            default => (clone $tanggal)->startOfMonth(),
        };
    }

    private function satuanKeInterval(string $satuan): \DateInterval
    {
        return match ($satuan) {
            'hari' => new \DateInterval('P1D'),
            'minggu' => new \DateInterval('P7D'),
            default => new \DateInterval('P1M'),
        };
    }

    private function hitungTitik(Carbon $mulai, Carbon $akhir, string $satuan): int
    {
        return match ($satuan) {
            'hari' => $mulai->diffInDays($akhir) + 1,
            'minggu' => $mulai->diffInWeeks($akhir) + 1,
            default => $mulai->diffInMonths($akhir) + 1,
        };
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
