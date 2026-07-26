<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LaporanKalibrasiResource;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\EquipmentCategory;
use App\Models\User;
use App\Services\LaporanExcelExporter;
use App\Services\LaporanKalibrasi;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan kalibrasi berpenyaring + export PDF/Excel (spesifikasi poin 08, fase-2 §5).
 *
 * Semua role boleh — tapi teknisi cuma dapat pekerjaannya sendiri, aturan yang
 * sama kayak `/calibrations`. Penyaring & barisnya dipegang `LaporanKalibrasi`
 * biar layar dan file export nggak bisa nunjukin isi yang beda.
 */
class LaporanController extends Controller
{
    public function __construct(
        private readonly LaporanKalibrasi $laporan,
        private readonly LaporanExcelExporter $excel,
    ) {}

    public function kalibrasi(Request $request): AnonymousResourceCollection
    {
        $filter = $this->penyaringTervalidasi($request);
        $user = $request->user();

        $sesi = $this->laporan->query($filter, $user)->paginate(15)->withQueryString();

        return LaporanKalibrasiResource::collection($sesi)->additional([
            // Ringkasan dihitung dari SELURUH hasil penyaring, bukan dari halaman
            // yang sedang dibuka — "total 15" yang cuma berarti "15 di halaman
            // ini" itu angka menyesatkan di dokumen yang dikirim ke asesor.
            'ringkasan' => $this->laporan->ringkasan($filter, $user),
            'penyaring' => $this->penyaringTerbaca($filter, $user),
        ]);
    }

    /**
     * Export laporan ke PDF atau Excel — penyaringnya sama persis kayak
     * `kalibrasi()`, jadi file-nya isi yang sama dengan yang dilihat di layar.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $filter = $this->penyaringTervalidasi($request, wajibFormat: true);
        $user = $request->user();

        $sesi = $this->laporan->query($filter, $user)
            ->limit(LaporanKalibrasi::MAKS_BARIS_EXPORT)
            ->get();

        abort_if(
            $sesi->isEmpty(),
            404,
            'Nggak ada sesi kalibrasi yang cocok sama penyaring itu.',
        );

        $baris = $this->laporan->baris($sesi);
        $ringkasan = $this->laporan->ringkasan($filter, $user);
        $terbaca = $this->penyaringTerbaca($filter, $user);
        $format = (string) $request->string('format');

        $namaFile = 'Laporan-Kalibrasi-'.now()->format('Y-m-d').'.'.($format === 'pdf' ? 'pdf' : 'xlsx');
        $tmp = tempnam(sys_get_temp_dir(), 'laporan-kalibrasi-').'.'.($format === 'pdf' ? 'pdf' : 'xlsx');

        if ($format === 'pdf') {
            // `landscape`: 11 kolom nggak masuk di portrait, dan tabel yang
            // kolomnya kepotong bikin laporan nggak kepakai.
            Pdf::loadView('laporan.kalibrasi-pdf', [
                'organisasi' => $user->organization,
                'judulKolom' => $this->laporan->judulKolom(),
                'baris' => $baris,
                'ringkasan' => $ringkasan,
                'penyaring' => $terbaca,
            ])->setPaper('a4', 'landscape')->save($tmp);
        } else {
            $this->excel->tulis(
                $tmp,
                $this->laporan->judulKolom(),
                $baris,
                $ringkasan,
                // Exporter Excel butuh kunci mesin (`dari`, `pelanggan`, …),
                // bukan label berbahasa Indonesia yang dipakai PDF.
                $this->penyaringUntukExcel($filter, $user),
            );
        }

        return response()->download($tmp, $namaFile)->deleteFileAfterSend();
    }

    /**
     * Satu aturan validasi buat dua endpoint — kalau kepisah, penyaring yang
     * ditolak di layar bisa lolos di export (atau sebaliknya).
     *
     * @return array<string, mixed>
     */
    private function penyaringTervalidasi(Request $request, bool $wajibFormat = false): array
    {
        $aturan = [
            'dari' => ['sometimes', 'nullable', 'date'],
            'sampai' => ['sometimes', 'nullable', 'date', 'after_or_equal:dari'],
            'pelanggan_id' => ['sometimes', 'nullable', 'integer'],
            'teknisi_id' => ['sometimes', 'nullable', 'integer'],
            'kategori' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', Rule::in([
                CalibrationSession::STATUS_DRAFT,
                CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                CalibrationSession::STATUS_DISETUJUI,
                CalibrationSession::STATUS_PERLU_REVISI,
            ])],
            'keputusan' => ['sometimes', 'nullable', Rule::in(['PASS', 'FAIL'])],
        ];

        if ($wajibFormat) {
            $aturan['format'] = ['required', Rule::in(['pdf', 'xlsx'])];
        }

        return $request->validate($aturan, [
            'sampai.after_or_equal' => 'Tanggal "sampai" nggak boleh sebelum tanggal "dari".',
            'format.required' => 'Formatnya wajib diisi: pdf atau xlsx.',
            'format.in' => 'Format cuma bisa pdf atau xlsx.',
        ]);
    }

    /**
     * Penyaring versi manusia — buat dipajang di layar & dicetak di file.
     *
     * Tanpa ini, file yang udah nyebar nggak bisa dijelasin lagi isinya periode
     * atau pelanggan mana. Itu pertanyaan pertama asesor waktu lihat rekap.
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, string|null>
     */
    private function penyaringTerbaca(array $filter, User $user): array
    {
        $nilai = $this->penyaringUntukExcel($filter, $user);

        return [
            'Dari tanggal' => $nilai['dari'],
            'Sampai tanggal' => $nilai['sampai'],
            'Pelanggan' => $nilai['pelanggan'],
            'Teknisi' => $nilai['teknisi'],
            'Kategori' => $nilai['kategori'],
            'Status' => $nilai['status'],
            'Keputusan' => $nilai['keputusan'],
        ];
    }

    /**
     * Nama-nama yang bisa dibaca, di-resolve dari id di penyaring.
     *
     * Di-scope ke organisasi: id dari lab lain jadi `null`, bukan nama PT orang.
     * Penyaringnya sendiri nggak bocor apa-apa (query-nya udah di-scope), tapi
     * nampilin nama PT lain di kepala laporan tetap kebocoran yang nggak perlu.
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, string|null>
     */
    private function penyaringUntukExcel(array $filter, User $user): array
    {
        $pelanggan = filled($filter['pelanggan_id'] ?? null)
            ? Customer::where('organization_id', $user->organization_id)
                ->whereKey((int) $filter['pelanggan_id'])
                ->value('nama')
            : null;

        $teknisi = filled($filter['teknisi_id'] ?? null)
            ? User::where('organization_id', $user->organization_id)
                ->whereKey((int) $filter['teknisi_id'])
                ->value('name')
            : null;

        $kategori = filled($filter['kategori'] ?? null)
            ? EquipmentCategory::where('organization_id', $user->organization_id)
                ->where('kode', $filter['kategori'])
                ->value('nama')
            : null;

        return [
            'dari' => $filter['dari'] ?? null,
            'sampai' => $filter['sampai'] ?? null,
            'pelanggan' => $pelanggan,
            'teknisi' => $teknisi,
            'kategori' => $kategori,
            'status' => $filter['status'] ?? null,
            'keputusan' => $filter['keputusan'] ?? null,
        ];
    }
}
