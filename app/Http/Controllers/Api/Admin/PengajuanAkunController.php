<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetujuiPengajuanRequest;
use App\Http\Requests\Admin\TolakPengajuanRequest;
use App\Http\Resources\Admin\PengajuanAkunResource;
use App\Models\PengajuanAkunPelanggan;
use App\Services\Pelanggan\PersetujuanAkun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Antrean pengajuan akun pelanggan — dipakai ADMIN LAB dari app internal
 * (03-SDD §7.3).
 *
 * Rutenya sengaja hidup di `routes/api.php`, bukan `routes/api_pelanggan.php`:
 * pemanggilnya aplikasi internal dengan token ber-ability `internal`, dan
 * menaruhnya di berkas pelanggan berarti dia ikut mati waktu
 * `FITUR_PELANGGAN=false` — padahal antrean yang sudah telanjur berisi tetap
 * harus bisa diputus admin.
 */
class PengajuanAkunController extends Controller
{
    public function __construct(private readonly PersetujuanAkun $persetujuan) {}

    /**
     * Antrean yang siap ditinjau, beserta saran pelanggan mirip.
     *
     * Saringannya `siapDitinjau()`, bukan `where('status','menunggu')` — baris
     * pengajuan lahir waktu orangnya menekan "Daftar", jadi yang emailnya belum
     * diverifikasi belum boleh terlihat sama sekali. Scope itu menuntut
     * keduanya sekaligus supaya tidak ada pemanggil yang cuma ingat separuh.
     */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', PengajuanAkunPelanggan::STATUS_MENUNGGU);

        $kueri = PengajuanAkunPelanggan::query()
            ->with(['pemohon', 'pemutus'])
            ->where('organization_id', $request->user()->organization_id);

        $kueri = $status === PengajuanAkunPelanggan::STATUS_MENUNGGU
            ? $kueri->siapDitinjau()
            : $kueri->where('status', $status);

        $halaman = $kueri->orderBy('created_at')->paginate(20);

        // Saran dihitung SEKALI per baris di sini, bukan di dalam resource:
        // resource yang menghitungnya sendiri bikin satu query kemiripan per
        // baris tanpa ada yang melihatnya di controller.
        $halaman->getCollection()->each(function (PengajuanAkunPelanggan $pengajuan): void {
            $pengajuan->saranPelanggan = $this->persetujuan->saranPelanggan($pengajuan);
        });

        return response()->json([
            'data' => PengajuanAkunResource::collection($halaman->items())->resolve(),
            'meta' => [
                'current_page' => $halaman->currentPage(),
                'last_page' => $halaman->lastPage(),
                'per_page' => $halaman->perPage(),
                'total' => $halaman->total(),
            ],
        ]);
    }

    /** REQ-AUTH-04. */
    public function setujui(SetujuiPengajuanRequest $request, PengajuanAkunPelanggan $pengajuan): JsonResponse
    {
        $this->pastikanSeorganisasi($request, $pengajuan);

        $hasil = $this->persetujuan->setujui(
            $pengajuan,
            $request->user(),
            $request->pelangganTerpilih(),
            $request->dataPelangganBaru(),
        );

        return response()->json([
            'message' => 'Pengajuan disetujui. Pemohon sudah bisa membuka aplikasi.',
            'data' => (new PengajuanAkunResource($hasil->load(['pemohon', 'pemutus'])))->resolve(),
        ]);
    }

    /** REQ-AUTH-05. */
    public function tolak(TolakPengajuanRequest $request, PengajuanAkunPelanggan $pengajuan): JsonResponse
    {
        $this->pastikanSeorganisasi($request, $pengajuan);

        $hasil = $this->persetujuan->tolak($pengajuan, $request->user(), $request->string('alasan')->toString());

        return response()->json([
            'message' => 'Pengajuan ditolak. Pemohon dikabari beserta alasannya.',
            'data' => (new PengajuanAkunResource($hasil->load(['pemohon', 'pemutus'])))->resolve(),
        ]);
    }

    /**
     * 404, bukan 403 — sama seperti seluruh repo ini.
     *
     * 403 memberi tahu bahwa pengajuan dengan ID itu ADA di lab lain, dan itu
     * sudah informasi.
     */
    private function pastikanSeorganisasi(Request $request, PengajuanAkunPelanggan $pengajuan): void
    {
        abort_unless(
            (int) $pengajuan->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
