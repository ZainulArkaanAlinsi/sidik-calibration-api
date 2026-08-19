<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Order kalibrasi — permintaan pelanggan yang dicatat di meja penerimaan.
 *
 * Satu order boleh banyak alat: klien biasanya nganter beberapa sekaligus.
 * Daftar alatnya disimpen di `order_items` dan dibikin barengan sama ordernya,
 * bukan diturunin dari sesi kalibrasi — work order harus bisa dicetak begitu
 * alat diterima, jauh sebelum teknisi mulai ngerjain.
 */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with('customer')
            ->withCount('items')
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when(
                $request->filled('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            // Antrean kerjaan satu teknisi: order yang punya minimal satu alat
            // ditugaskan ke dia. `teknisi_id=saya` biar mobile nggak perlu tau
            // ID-nya sendiri buat layar "Tugas Saya".
            ->when($request->filled('teknisi_id'), function ($query) use ($request) {
                // `input()`, bukan `string()`: yang terakhir balikin Stringable,
                // jadi `=== 'saya'` nggak pernah kena dan filternya diam-diam
                // nyari teknisi_id = 0.
                $teknisiId = $request->input('teknisi_id') === 'saya'
                    ? $request->user()->id
                    : $request->integer('teknisi_id');

                $query->whereHas('items', fn ($i) => $i->where('teknisi_id', $teknisiId));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $cari = '%'.$request->string('search').'%';

                // Dicari lewat nomor order ATAU nama pelanggan — petugas depan
                // seringnya cuma inget "punya PT Maju Jaya kemarin".
                $query->where(fn ($q) => $q->where('nomor', 'like', $cari)
                    ->orWhereHas('customer', fn ($c) => $c->where('nama', 'like', $cari)));
            })
            ->latest('tanggal_masuk')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(OrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $organizationId = $request->user()->organization_id;

        // Nomor + item dibikin dalam satu transaksi: kalau salah satu itemnya
        // gagal, ordernya jangan sampai nyangkut kosong tanpa alat sama sekali —
        // dan nomor yang udah kepakai jangan bolong.
        $order = DB::transaction(function () use ($data, $organizationId, $request) {
            $order = Order::create([
                'organization_id' => $organizationId,
                'customer_id' => $data['customer_id'],
                'diterima_oleh' => $request->user()->id,
                'nomor' => $this->nomorOrderBerikutnya($organizationId),
                'tanggal_masuk' => $data['tanggal_masuk'],
                'tanggal_janji_selesai' => $data['tanggal_janji_selesai'] ?? null,
                'status' => $data['status'] ?? Order::STATUS_BARU,
                'catatan' => $data['catatan'] ?? null,
            ]);

            $this->simpanItems($order, $data['items']);

            return $order;
        });

        return response()->json([
            'data' => new OrderResource($this->muatRelasi($order)),
        ], 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $order);

        return response()->json(['data' => new OrderResource($this->muatRelasi($order))]);
    }

    public function update(OrderRequest $request, Order $order): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $order);

        $data = $request->validated();

        DB::transaction(function () use ($order, $data) {
            $order->update(array_diff_key($data, ['items' => null]));

            // `items` cuma disentuh kalau emang dikirim — PUT yang cuma ngubah
            // status nggak boleh diam-diam ngosongin daftar alatnya.
            if (isset($data['items'])) {
                $this->gantiItems($order, $data['items']);
            }
        });

        return response()->json(['data' => new OrderResource($this->muatRelasi($order->fresh()))]);
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $order);

        // Order yang alatnya udah mulai dikalibrasi nggak boleh dihapus: sesi &
        // sertifikatnya nunjuk ke sini, dan itu jejak yang dicari asesor waktu
        // audit. Batalin aja — statusnya berubah, riwayatnya utuh.
        if ($this->punyaSesiKalibrasi($order)) {
            return response()->json([
                'message' => 'Order ini alatnya udah masuk kalibrasi. Ubah statusnya jadi "dibatalkan" biar riwayatnya nggak putus.',
            ], 422);
        }

        $order->delete();

        return response()->json(['message' => 'Order dihapus.']);
    }

    /**
     * Bagi-bagi kerjaan tanpa ngirim ulang seluruh order.
     *
     * `PUT /orders/{id}` butuh payload `items` lengkap; itu berat & rawan buat
     * aksi yang sesering ini — kepala lab cuma mindahin satu alat ke teknisi
     * lain. Endpoint sendiri bikin aksinya murah dan nggak bisa nggak sengaja
     * ngubah data penerimaan.
     *
     * `teknisi_id: null` artinya lepas penugasan, bukan diabaikan.
     */
    public function penugasan(Request $request, Order $order): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $order);

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'penugasan' => ['required', 'array', 'min:1'],
            'penugasan.*.item_id' => [
                'required', 'integer',
                // Dibatesin ke item milik order INI — tanpa ini, satu request
                // bisa nyentuh item order lain modal nebak ID.
                Rule::exists('order_items', 'id')->where('order_id', $order->id),
            ],
            'penugasan.*.teknisi_id' => [
                'present', 'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('role', User::ROLE_TEKNISI)
                    ->where('status', User::STATUS_AKTIF),
            ],
        ], [
            'penugasan.required' => 'Nggak ada penugasan yang dikirim.',
            'penugasan.*.item_id.exists' => 'Ada alat yang bukan bagian dari order ini.',
            'penugasan.*.teknisi_id.present' => 'Kirim `teknisi_id` (isi null kalau mau lepas penugasan).',
            'penugasan.*.teknisi_id.exists' => 'Teknisi yang dipilih nggak ketemu, bukan teknisi, atau akunnya nonaktif.',
        ]);

        DB::transaction(function () use ($order, $validated) {
            foreach ($validated['penugasan'] as $baris) {
                $order->items()->whereKey($baris['item_id'])->update([
                    'teknisi_id' => $baris['teknisi_id'],
                ]);
            }
        });

        return response()->json(['data' => new OrderResource($this->muatRelasi($order->fresh()))]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function simpanItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            $order->items()->create([
                'equipment_id' => $item['equipment_id'],
                'teknisi_id' => $item['teknisi_id'] ?? null,
                'kondisi_terima' => $item['kondisi_terima'] ?? null,
                'kelengkapan' => $item['kelengkapan'] ?? null,
                'catatan' => $item['catatan'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function gantiItems(Order $order, array $items): void
    {
        // Item yang udah punya sesi kalibrasi dipertahanin — ngehapusnya bakal
        // mutusin sesi dari asal-usulnya. Sisanya boleh diganti bebas, karena
        // itu cuma catatan penerimaan yang belum dipakai siapa-siapa.
        $dipakai = $order->items()->has('calibrationSessions')->pluck('equipment_id')->all();

        $order->items()->whereDoesntHave('calibrationSessions')->delete();

        $baru = collect($items)->reject(
            fn (array $item): bool => in_array($item['equipment_id'], $dipakai, true),
        );

        $this->simpanItems($order, $baru->all());
    }

    private function punyaSesiKalibrasi(Order $order): bool
    {
        return $order->items()->has('calibrationSessions')->exists();
    }

    private function muatRelasi(Order $order): Order
    {
        return $order->load(['customer', 'penerima', 'items.equipment', 'items.teknisi'])->loadCount('items');
    }

    /** Nomor order urut per organisasi per bulan: ORD/2026/07/0001. */
    private function nomorOrderBerikutnya(int $organizationId): string
    {
        $prefix = sprintf('ORD/%s/', now()->format('Y/m'));

        $urutanTerakhir = Order::where('organization_id', $organizationId)
            ->where('nomor', 'like', $prefix.'%')
            // Dikunci biar dua petugas yang nyimpen barengan nggak dapet nomor
            // yang sama — sama kayak penomoran sesi & sertifikat.
            ->lockForUpdate()
            ->orderByDesc('nomor')
            ->value('nomor');

        $urutan = $urutanTerakhir ? ((int) substr($urutanTerakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    private function pastikanSatuOrganisasi(Request $request, Order $order): void
    {
        abort_if($order->organization_id !== $request->user()->organization_id, 404);
    }
}
