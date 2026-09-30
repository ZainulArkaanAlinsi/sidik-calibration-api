<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\PermintaanPelangganResource;
use App\Http\Resources\Pelanggan\PesanPermintaanPelangganResource;
use App\Models\Order;
use App\Models\PermintaanKalibrasi;
use App\Models\PesanPermintaan;
use App\Services\Permintaan\AlurPermintaan;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * `/permintaan` — pelanggan mengajukan kalibrasi ke lab dan berbincang soal itu.
 *
 * ID permintaan dicari DI DALAM lingkup perusahaan (`LingkupData::permintaan()`),
 * sama seperti `/alat` dan `/paket`: permintaan perusahaan lain dijawab 404,
 * dan pencarian itu terjadi SEBELUM validasi body. Urutan itu disengaja —
 * validasi yang jalan duluan menjawab 422 untuk ID yang bukan milik pemanggil,
 * dan 422 itu sudah mengakui barisnya ada.
 *
 * Yang boleh menulis: semua anggota aktif (PIC utama maupun staf). Keputusan
 * pemilik proyek tidak membatasi pengajuan ke PIC; yang dibatasi PIC cuma
 * mengelola anggota.
 */
class PermintaanController extends Controller
{
    public const SARING = ['aktif', 'selesai', 'semua'];

    public const BATAS_ALAT = 50;

    public function __construct(private readonly AlurPermintaan $alur) {}

    public function index(Request $request, Konteks $konteks): JsonResponse
    {
        $data = $request->validate([
            'saring' => ['sometimes', 'nullable', Rule::in(self::SARING)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $lingkup = new LingkupData($konteks);
        $saring = $data['saring'] ?? 'aktif';

        $daftar = $lingkup->permintaan()
            ->when($saring === 'aktif', fn (Builder $q) => $this->aktif($q))
            ->when($saring === 'selesai', fn (Builder $q) => $q->whereNot(fn (Builder $n) => $this->aktif($n)))
            ->with($this->relasi())
            ->withCount('pesan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($daftar->items())
                ->map(fn (PermintaanKalibrasi $p) => (new PermintaanPelangganResource($p))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $daftar->total(),
                'per_page' => $daftar->perPage(),
                'current_page' => $daftar->currentPage(),
                'last_page' => $daftar->lastPage(),
                // Angka di tab "Aktif 3 · Selesai 4" — dihitung di server supaya
                // tab yang sedang tidak dibuka tetap punya angkanya.
                'jumlah' => [
                    'aktif' => $this->aktif($lingkup->permintaan())->count(),
                    'selesai' => $lingkup->permintaan()->whereNot(fn (Builder $n) => $this->aktif($n))->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request, Konteks $konteks): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        $perusahaan = $lingkup->perusahaan();

        $data = $request->validate([
            'metode_pengantaran' => ['required', Rule::in(PermintaanKalibrasi::metodePengantaran())],
            'tanggal_diinginkan_dari' => ['nullable', 'date', 'after_or_equal:today'],
            'tanggal_diinginkan_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_diinginkan_dari'],
            'catatan' => ['nullable', 'string', 'max:2000'],

            // Alat yang sudah terdaftar. Ditegakkan DI DALAM lingkup perusahaan:
            // id alat milik perusahaan lain dan id yang tidak ada dijawab
            // dengan pesan yang sama, jadi tidak bisa dipakai menyisir.
            'alat_id' => ['sometimes', 'array', 'max:'.self::BATAS_ALAT],
            'alat_id.*' => [
                'integer',
                'distinct',
                Rule::exists('equipments', 'id')->where(fn ($q) => $q
                    ->where('customer_id', $konteks->customerId)
                    ->where('organization_id', $perusahaan->organization_id)
                    ->where('status', 'aktif')
                    ->whereNull('deleted_at')),
            ],

            // Alat baru, field mengikuti formulir PL_Form_Alat.
            'alat_baru' => ['sometimes', 'array', 'max:'.self::BATAS_ALAT],
            'alat_baru.*.nama_alat' => ['required', 'string', 'max:255'],
            'alat_baru.*.merk' => ['nullable', 'string', 'max:255'],
            'alat_baru.*.model' => ['nullable', 'string', 'max:255'],
            'alat_baru.*.serial_number' => ['nullable', 'string', 'max:255'],
            'alat_baru.*.no_identifikasi' => ['nullable', 'string', 'max:255'],
            'alat_baru.*.rentang_min' => ['nullable', 'numeric'],
            'alat_baru.*.rentang_maks' => ['nullable', 'numeric', 'gte:alat_baru.*.rentang_min'],
            // Aturan layar: rentang diisi → satuannya wajib (02-SRS §11).
            'alat_baru.*.satuan' => ['nullable', 'required_with:alat_baru.*.rentang_min,alat_baru.*.rentang_maks', 'string', 'max:30'],
            'alat_baru.*.resolusi' => ['nullable', 'numeric', 'min:0'],
            'alat_baru.*.lokasi' => ['nullable', 'string', 'max:255'],
            'alat_baru.*.catatan' => ['nullable', 'string', 'max:1000'],
        ]);

        $jumlah = count($data['alat_id'] ?? []) + count($data['alat_baru'] ?? []);

        if ($jumlah === 0) {
            throw ValidationException::withMessages(['alat_id' => 'Pilih minimal satu alat, atau tambahkan alat baru.']);
        }

        if ($jumlah > self::BATAS_ALAT) {
            throw ValidationException::withMessages(['alat_id' => 'Satu permintaan maksimal '.self::BATAS_ALAT.' alat.']);
        }

        $this->tolakSerialKembar($lingkup, $data['alat_baru'] ?? []);

        $permintaan = $this->alur->ajukan($perusahaan, $request->user(), $data);

        return response()->json([
            'message' => 'Permintaan terkirim. Tim lab akan meninjaunya.',
            'data' => $this->bentuk($permintaan->fresh($this->relasi())->loadCount('pesan'), $request),
        ], 201);
    }

    public function show(Request $request, Konteks $konteks, string $permintaan): JsonResponse
    {
        $baris = (new LingkupData($konteks))->permintaan()
            ->whereKey($permintaan)
            ->with($this->relasi())
            ->withCount('pesan')
            ->firstOrFail();

        return response()->json(['data' => $this->bentuk($baris, $request)]);
    }

    /** Batalkan — hanya selama masih `baru`. */
    public function batal(Request $request, Konteks $konteks, string $permintaan): JsonResponse
    {
        $baris = (new LingkupData($konteks))->permintaan()->whereKey($permintaan)->firstOrFail();

        $this->alur->batalkan($baris);

        return response()->json([
            'message' => 'Permintaan dibatalkan.',
            'data' => $this->bentuk($baris->fresh($this->relasi())->loadCount('pesan'), $request),
        ]);
    }

    public function pesan(Request $request, Konteks $konteks, string $permintaan): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        $baris = $lingkup->permintaan()->whereKey($permintaan)->firstOrFail();

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Terlama di atas: ini percakapan, dibaca dari atas ke bawah.
        $pesan = $baris->pesan()
            ->with('pengirim:id,name')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 50);

        $namaLab = (string) ($lingkup->perusahaan()->organization?->nama ?? 'lab');

        return response()->json([
            'data' => collect($pesan->items())
                ->map(fn (PesanPermintaan $p) => (new PesanPermintaanPelangganResource($p, $request->user()->id, $namaLab))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $pesan->total(),
                'per_page' => $pesan->perPage(),
                'current_page' => $pesan->currentPage(),
                'last_page' => $pesan->lastPage(),
                'percakapan_terbuka' => $baris->percakapanTerbuka(),
            ],
        ]);
    }

    public function kirimPesan(Request $request, Konteks $konteks, string $permintaan): JsonResponse
    {
        $lingkup = new LingkupData($konteks);
        $baris = $lingkup->permintaan()->whereKey($permintaan)->firstOrFail();

        $data = $request->validate([
            'isi' => ['required', 'string', 'max:2000'],
        ]);

        $pesan = $this->alur->kirimPesan($baris, $request->user(), PesanPermintaan::SISI_PELANGGAN, $data['isi']);
        $pesan->load('pengirim:id,name');

        $namaLab = (string) ($lingkup->perusahaan()->organization?->nama ?? 'lab');

        return response()->json([
            'data' => (new PesanPermintaanPelangganResource($pesan, $request->user()->id, $namaLab))->resolve($request),
        ], 201);
    }

    /**
     * "Masih berjalan" = baru, atau diterima dan paketnya belum selesai/batal.
     * Sisanya riwayat. Sebuah permintaan diterima tetap layak di tab Aktif
     * selama alatnya masih dikerjakan — pelanggan masih menunggunya.
     *
     * @param  Builder<PermintaanKalibrasi>  $q
     * @return Builder<PermintaanKalibrasi>
     */
    private function aktif(Builder $q): Builder
    {
        return $q->where(fn (Builder $a) => $a
            ->where('status', PermintaanKalibrasi::STATUS_BARU)
            ->orWhere(fn (Builder $d) => $d
                ->where('status', PermintaanKalibrasi::STATUS_DITERIMA)
                ->where(fn (Builder $o) => $o
                    ->whereNull('order_id')
                    ->orWhereHas('order', fn (Builder $x) => $x->whereNotIn('status', [Order::STATUS_SELESAI, Order::STATUS_DIBATALKAN])))));
    }

    /**
     * Nomor seri alat baru yang sudah ada di daftar alat perusahaan ini ditolak
     * (REQ-ALT-03): pelanggan diminta memilihnya dari daftar, bukan membuat dua.
     *
     * Yang diperiksa HANYA alat milik perusahaan sendiri. Nomor seri yang
     * terpakai perusahaan LAIN sengaja tidak disebut — menjawabnya "sudah ada"
     * membuka jalan memetakan alat pesaing; bentrok macam itu diputuskan admin
     * waktu menerima.
     *
     * @param  list<array<string, mixed>>  $alatBaru
     */
    private function tolakSerialKembar(LingkupData $lingkup, array $alatBaru): void
    {
        $galat = [];
        $terlihat = [];

        foreach ($alatBaru as $i => $alat) {
            $serial = trim((string) ($alat['serial_number'] ?? ''));

            if ($serial === '') {
                continue;
            }

            if (isset($terlihat[$serial])) {
                $galat["alat_baru.{$i}.serial_number"] = 'Nomor seri ini muncul dua kali di permintaan.';

                continue;
            }

            $terlihat[$serial] = true;

            if ($lingkup->alat()->where('serial_number', $serial)->exists()) {
                $galat["alat_baru.{$i}.serial_number"] = 'Alat dengan nomor seri ini sudah ada di daftar alat Anda. Pilih dari daftar alat.';
            }
        }

        if ($galat !== []) {
            throw ValidationException::withMessages($galat);
        }
    }

    /** @return array<string, mixed> */
    private function bentuk(PermintaanKalibrasi $p, Request $request): array
    {
        return (new PermintaanPelangganResource($p))->resolve($request);
    }

    /** @return list<string> */
    private function relasi(): array
    {
        return ['items.equipment', 'order:id,nomor,status'];
    }
}
