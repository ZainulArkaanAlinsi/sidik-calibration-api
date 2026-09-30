<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\KoreksiPelangganResource;
use App\Models\KoreksiPelanggan;
use App\Services\Pelanggan\AlurKoreksi;
use App\Services\PenjagaOrganisasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Antrean koreksi data dari pelanggan — sisi LAB (§42 A4).
 *
 * Grup `role:admin`: admin membaca & memutuskan; teknisi/viewer 403; super
 * admin baca saja (tulis 403 lewat `lolosBacaSuperAdmin`, sampai K4).
 *
 * Urutan di aksi tulis sama dengan `PermintaanPelangganController`: binding →
 * cek organisasi (404 untuk lab lain) → baru validasi body.
 */
class KoreksiPelangganController extends Controller
{
    private const RELASI = [
        'customer:id,nama', 'pengaju:id,name', 'peninjau:id,name',
        'equipment:id,nama_alat,serial_number', 'certificate:id,nomor', 'revisi:id,nomor,status', 'foto',
    ];

    public function __construct(private readonly AlurKoreksi $alur) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([...KoreksiPelanggan::daftarStatus(), 'semua'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $data['status'] ?? KoreksiPelanggan::STATUS_MENUNGGU;
        $orgId = $request->user()->organization_id;

        $daftar = KoreksiPelanggan::query()
            ->where('organization_id', $orgId)
            ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
            ->with(self::RELASI)
            // Antrean menunggu: yang paling lama menunggu di atas; riwayat
            // terbaru di atas — sama dengan antrean permintaan.
            ->when(
                $status === KoreksiPelanggan::STATUS_MENUNGGU,
                fn ($q) => $q->orderBy('created_at')->orderBy('id'),
                fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
            )
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($daftar->items())
                ->map(fn (KoreksiPelanggan $k) => (new KoreksiPelangganResource($k))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $daftar->total(),
                'per_page' => $daftar->perPage(),
                'current_page' => $daftar->currentPage(),
                'last_page' => $daftar->lastPage(),
                'jumlah' => [
                    'menunggu' => KoreksiPelanggan::query()
                        ->where('organization_id', $orgId)
                        ->where('status', KoreksiPelanggan::STATUS_MENUNGGU)
                        ->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, KoreksiPelanggan $koreksi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $koreksi);

        return response()->json(['data' => $this->bentuk($koreksi, $request)]);
    }

    public function terima(Request $request, KoreksiPelanggan $koreksi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $koreksi);

        $data = $request->validate([
            'tanggapan' => ['nullable', 'string', 'max:1000'],
            'perubahan' => ['sometimes', 'array'],
            'perubahan.*' => ['nullable', 'max:500'],
            'alasan' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->alur->terima(
            $koreksi,
            $request->user(),
            (array) ($data['perubahan'] ?? []),
            $data['tanggapan'] ?? null,
            $data['alasan'] ?? null,
        );

        return response()->json([
            'message' => $koreksi->jenis === KoreksiPelanggan::JENIS_SERTIFIKAT
                ? 'Koreksi diterima. Revisi sertifikat sedang diterbitkan.'
                : 'Koreksi diterima. Data alat sudah diperbarui.',
            'data' => $this->bentuk($koreksi->fresh(), $request),
        ]);
    }

    public function tolak(Request $request, KoreksiPelanggan $koreksi): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $koreksi);

        $data = $request->validate([
            // Dibaca PELANGGAN apa adanya — `min:5` menahan "-" atau "x".
            'tanggapan' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->alur->tolak($koreksi, $request->user(), $data['tanggapan']);

        return response()->json([
            'message' => 'Koreksi ditolak. Pelanggan akan membaca tanggapannya.',
            'data' => $this->bentuk($koreksi->fresh(), $request),
        ]);
    }

    /** @return array<string, mixed> */
    private function bentuk(KoreksiPelanggan $koreksi, Request $request): array
    {
        return (new KoreksiPelangganResource($koreksi->load(self::RELASI)))->resolve($request);
    }
}
