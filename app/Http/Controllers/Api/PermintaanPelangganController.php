<?php

namespace App\Http\Controllers\Api;

use App\Events\PerubahanDataOrganisasi;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermintaanKalibrasiResource;
use App\Models\PermintaanKalibrasi;
use App\Models\PesanPermintaan;
use App\Notifications\Pelanggan\KabarKoreksiPermintaan;
use App\Services\Pelanggan\PreferensiNotifikasi;
use App\Services\PenjagaOrganisasi;
use App\Services\Permintaan\AlurPermintaan;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Kabar;
use Illuminate\Validation\Rule;

/**
 * Permintaan kalibrasi pelanggan — sisi LAB.
 *
 * Semua rutenya di grup `role:admin`: admin membaca dan memutuskan; teknisi &
 * viewer 403; super admin cuma membaca (GET lolos `lolosBacaSuperAdmin`, tulis
 * 403) — K4 belum dijawab, jadi wewenang memutuskan belum dibuka untuknya.
 *
 * Urutan di tiap aksi tulis SENGAJA: (1) binding model, (2) cek organisasi
 * (404 untuk lab lain), (3) baru validasi body. Kalau validasi jalan duluan,
 * ID permintaan lab lain dijawab 422 — dan itu sudah mengakui barisnya ada.
 *
 * Tidak ada penugasan "ditugaskan ke admin X": ajuan masuk ke semua admin aktif
 * dan yang lebih dulu memutuskan menang; yang kedua dijawab 422 "sudah
 * diputuskan" oleh `AlurPermintaan` (baris dikunci dan statusnya dibaca ulang).
 */
class PermintaanPelangganController extends Controller
{
    /**
     * `order.items.sesiTerakhir.certificate` untuk tahap & progres
     * (`TahapPermintaan`), `items.foto` untuk foto pelat nama alat baru.
     */
    private const RELASI = [
        'customer:id,nama', 'pemohon:id,name,email,telepon', 'pemutus:id,name', 'order:id,nomor,status',
        'order.items.sesiTerakhir.certificate', 'items.equipment', 'items.foto',
    ];

    public function __construct(private readonly AlurPermintaan $alur) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([
                PermintaanKalibrasi::STATUS_BARU,
                PermintaanKalibrasi::STATUS_DITERIMA,
                PermintaanKalibrasi::STATUS_DITOLAK,
                PermintaanKalibrasi::STATUS_DIBATALKAN,
            ])],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $data['status'] ?? null;

        $daftar = PermintaanKalibrasi::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when(filled($data['customer_id'] ?? null), fn ($q) => $q->where('customer_id', $data['customer_id']))
            ->when(filled($data['search'] ?? null), function ($q) use ($data): void {
                $kata = '%'.$data['search'].'%';
                $q->where(fn ($c) => $c->where('nomor', 'like', $kata)
                    ->orWhereHas('customer', fn ($k) => $k->where('nama', 'like', $kata)));
            })
            ->with(self::RELASI)
            ->withCount('pesan')
            // Antrean "baru": yang paling lama menunggu di atas. Daftar lain
            // (riwayat) terbaru di atas — sama alasannya dengan antrean
            // pengesahan: urutan terbalik menyembunyikan yang paling mendesak.
            ->when(
                $status === PermintaanKalibrasi::STATUS_BARU,
                fn ($q) => $q->orderBy('created_at')->orderBy('id'),
                fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
            )
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($daftar->items())
                ->map(fn (PermintaanKalibrasi $p) => (new PermintaanKalibrasiResource($p))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $daftar->total(),
                'per_page' => $daftar->perPage(),
                'current_page' => $daftar->currentPage(),
                'last_page' => $daftar->lastPage(),
                // Angka badge antrean, terlepas dari filter yang sedang dipakai.
                'jumlah_baru' => PermintaanKalibrasi::query()
                    ->where('organization_id', $request->user()->organization_id)
                    ->where('status', PermintaanKalibrasi::STATUS_BARU)
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, PermintaanKalibrasi $permintaan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $permintaan);

        return response()->json(['data' => $this->bentuk($permintaan, $request)]);
    }

    public function terima(Request $request, PermintaanKalibrasi $permintaan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $permintaan);

        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'tanggal_masuk' => ['nullable', 'date'],
            'tanggal_janji_selesai' => ['nullable', 'date'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            // Kelengkapan alat baru. `item_id` dibatasi ke item permintaan INI,
            // dan kategori ke kategori lab ini — id dari permintaan atau lab
            // lain dijawab 422 biasa, bukan diterima.
            'alat_baru' => ['sometimes', 'array'],
            'alat_baru.*.item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('permintaan_kalibrasi_item', 'id')->where('permintaan_kalibrasi_id', $permintaan->id),
            ],
            'alat_baru.*.equipment_category_id' => [
                'required', 'integer',
                Rule::exists('equipment_categories', 'id')->where('organization_id', $organizationId),
            ],
            'alat_baru.*.serial_number' => ['nullable', 'string', 'max:255'],
        ]);

        $this->alur->terima($permintaan, $request->user(), $data);

        return response()->json([
            'message' => 'Permintaan diterima. Order dibuat.',
            'data' => $this->bentuk($permintaan->fresh(), $request),
        ]);
    }

    public function tolak(Request $request, PermintaanKalibrasi $permintaan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $permintaan);

        $data = $request->validate([
            // Dibaca PELANGGAN apa adanya. `min:5` menahan "-" atau "x" yang
            // lolos hanya karena kolomnya wajib.
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->alur->tolak($permintaan, $request->user(), $data['alasan']);

        return response()->json([
            'message' => 'Permintaan ditolak. Pelanggan akan membaca alasannya.',
            'data' => $this->bentuk($permintaan->fresh(), $request),
        ]);
    }

    /**
     * Jadwal kunjungan teknisi untuk permintaan `diambil_lab` yang sudah
     * diterima (PL_Daftar_Permintaan "Teknisi dijadwalkan"). Boleh diubah
     * ulang selama alatnya belum ditandai tiba — tiap perubahan dikabarkan.
     */
    public function jadwal(Request $request, PermintaanKalibrasi $permintaan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $permintaan);

        $data = $request->validate([
            'jadwal_pada' => ['required', 'date', 'after_or_equal:today'],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ]);

        $baris = DB::transaction(function () use ($permintaan, $data): PermintaanKalibrasi {
            $baris = PermintaanKalibrasi::query()->whereKey($permintaan->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                $baris->status === PermintaanKalibrasi::STATUS_DITERIMA
                    && $baris->metode_pengantaran === PermintaanKalibrasi::METODE_DIAMBIL_LAB
                    && $baris->alat_tiba_pada === null,
                422,
                'Jadwal teknisi cuma untuk permintaan "diambil lab" yang sudah diterima dan alatnya belum tiba.',
            );

            $baris->update([
                'jadwal_pada' => Carbon::parse($data['jadwal_pada']),
                'jadwal_lokasi' => $data['lokasi'] ?? null,
                'jadwal_catatan' => $data['catatan'] ?? null,
            ]);

            return $baris;
        });

        $this->kabariPelanggan($baris, KabarKoreksiPermintaan::jadwalTeknisi($baris));
        PerubahanDataOrganisasi::siarkanAman((int) $baris->organization_id, 'permintaan', 'jadwal', $baris->id);

        return response()->json([
            'message' => 'Jadwal teknisi disimpan. Pelanggan dikabari.',
            'data' => $this->bentuk($baris, $request),
        ]);
    }

    /** Tandai alat sudah tiba di lab (dari kurir, diantar, atau dijemput teknisi). */
    public function alatTiba(Request $request, PermintaanKalibrasi $permintaan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $permintaan);

        $baris = DB::transaction(function () use ($permintaan): PermintaanKalibrasi {
            $baris = PermintaanKalibrasi::query()->whereKey($permintaan->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                $baris->status === PermintaanKalibrasi::STATUS_DITERIMA && $baris->alat_tiba_pada === null,
                422,
                'Cuma permintaan yang sudah diterima dan alatnya belum ditandai tiba.',
            );

            $baris->update(['alat_tiba_pada' => now()]);

            return $baris;
        });

        PerubahanDataOrganisasi::siarkanAman((int) $baris->organization_id, 'permintaan', 'alat_tiba', $baris->id);

        return response()->json([
            'message' => 'Alat ditandai sudah tiba di lab.',
            'data' => $this->bentuk($baris, $request),
        ]);
    }

    public function pesan(Request $request, PermintaanKalibrasi $permintaan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $permintaan);

        $pesan = $permintaan->pesan()
            ->with('pengirim:id,name')
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return response()->json([
            'data' => collect($pesan->items())->map(fn (PesanPermintaan $p): array => $this->bentukPesan($p))->values(),
            'meta' => [
                'total' => $pesan->total(),
                'per_page' => $pesan->perPage(),
                'current_page' => $pesan->currentPage(),
                'last_page' => $pesan->lastPage(),
                'percakapan_terbuka' => $permintaan->percakapanTerbuka(),
            ],
        ]);
    }

    public function kirimPesan(Request $request, PermintaanKalibrasi $permintaan): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $permintaan);

        $data = $request->validate([
            'isi' => ['required', 'string', 'max:2000'],
        ]);

        $pesan = $this->alur->kirimPesan($permintaan, $request->user(), PesanPermintaan::SISI_LAB, $data['isi']);
        $pesan->load('pengirim:id,name');

        return response()->json(['data' => $this->bentukPesan($pesan)], 201);
    }

    /** @return array<string, mixed> */
    private function bentuk(PermintaanKalibrasi $permintaan, Request $request): array
    {
        $permintaan->load(self::RELASI)->loadCount('pesan');

        return (new PermintaanKalibrasiResource($permintaan))->resolve($request);
    }

    /** Pola `AlurPermintaan::kabari` — gagal kirim tidak boleh jadi 500. */
    private function kabariPelanggan(PermintaanKalibrasi $permintaan, Notification $notifikasi): void
    {
        try {
            $permintaan->loadMissing('customer');

            if ($permintaan->customer !== null) {
                Kabar::send(
                    app(PreferensiNotifikasi::class)->penerima($permintaan->customer, PreferensiNotifikasi::STATUS_PERMINTAAN),
                    $notifikasi,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Notifikasi jadwal teknisi gagal dikirim.', [
                'permintaan_id' => $permintaan->id,
                'pesan' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function bentukPesan(PesanPermintaan $pesan): array
    {
        return [
            'id' => $pesan->id,
            'sisi' => $pesan->sisi,
            'pengirim' => $pesan->pengirim ? ['id' => $pesan->pengirim->id, 'nama' => $pesan->pengirim->name] : null,
            'isi' => $pesan->isi,
            'dibuat_pada' => $pesan->created_at?->toIso8601ZuluString(),
        ];
    }
}
