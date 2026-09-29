<?php

namespace App\Http\Controllers\Api;

use App\Events\PerubahanDataOrganisasi;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaketLacakResource;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PenjagaOrganisasi;
use App\Services\TahapPaket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pelacakan paket alat — "kayak lacak paket di online shop, tapi lebih detail".
 *
 * Tidak ada tabel baru di fitur ini. `orders` + `order_items` sudah memodelkan
 * persis yang dimaksud: satu `order` = satu batch alat dari satu pelanggan =
 * satu "paket". Yang kurang cuma layarnya, satu kolom tahap fisik, dan service
 * yang menurunkan tahapnya (`TahapPaket`).
 *
 * ## Relasi yang WAJIB di-eager-load
 *
 * `TahapPaket::untukItem()` membaca `$item->sesiTerakhir` dan
 * `->sesiTerakhir->certificate`. Tanpa eager load, satu paket 12 alat jadi 24
 * kueri, dan daftar 20 paket jadi 480. Konstanta `RELASI` di bawah yang
 * menjaganya — pakai dia, jangan menyusun `with()` sendiri per method.
 */
class PelacakanController extends Controller
{
    private const RELASI = [
        'customer:id,nama',
        'items:id,order_id,equipment_id,teknisi_id,tahap_fisik,tahap_fisik_pada,diserahkan_kepada,kondisi_terima,catatan',
        'items.equipment:id,nama_alat,merk,model,serial_number',
        'items.teknisi:id,name,kode_teknisi',
        // Tanpa pemilihan kolom: `latestOfMany()` menyusun subkueri sendiri dan
        // butuh `order_item_id` + kunci utamanya; memotong kolom di sini bikin
        // pencocokan eager-load diam-diam kosong dan semua alat terbaca "Diterima".
        'items.sesiTerakhir',
        'items.sesiTerakhir.certificate',
    ];

    public function __construct(private readonly TahapPaket $tahap) {}

    /**
     * Daftar paket, disortif yang paling perlu dilihat duluan.
     *
     * "Paling perlu dilihat" = yang janji selesainya paling dekat atau sudah
     * lewat, bukan yang terbaru masuk. Alasannya sama dengan antrean pengesahan:
     * urutan "terbaru dulu" menyembunyikan yang paling mendesak di dasar daftar,
     * dan daftar ini ada justru supaya tidak ada paket yang tertinggal.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tahap' => ['sometimes', 'nullable', Rule::in(TahapPaket::URUTAN)],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'terlambat' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paket = Order::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when(filled($data['customer_id'] ?? null), fn ($q) => $q->where('customer_id', $data['customer_id']))
            ->when(filled($data['q'] ?? null), fn ($q) => $q->where(function ($cari) use ($data): void {
                $cari->where('nomor', 'like', '%'.$data['q'].'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('nama', 'like', '%'.$data['q'].'%'))
                    ->orWhereHas('items.equipment', fn ($a) => $a
                        ->where('nama_alat', 'like', '%'.$data['q'].'%')
                        ->orWhere('serial_number', 'like', '%'.$data['q'].'%'));
            }))
            ->when($request->boolean('terlambat'), fn ($q) => $q
                ->whereNotNull('tanggal_janji_selesai')
                ->whereDate('tanggal_janji_selesai', '<', now())
                ->where('status', '!=', Order::STATUS_SELESAI))
            ->with(self::RELASI)
            // Yang tanpa janji selesai ditaruh di belakang, bukan di depan:
            // `NULL` yang disortir naik di MySQL akan menumpuk di puncak daftar
            // dan mendorong paket yang beneran mendesak ke bawah.
            ->orderByRaw('tanggal_janji_selesai IS NULL ASC')
            ->orderBy('tanggal_janji_selesai')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        $baris = collect($paket->items());

        // Penyaring tahap dilakukan SESUDAH pengambilan, bukan di SQL — tahapnya
        // diturunkan di PHP dari status sesi & sertifikat, jadi tidak ada kolom
        // yang bisa di-`WHERE`. Konsekuensinya jujur dan harus diketahui: dengan
        // `?tahap=`, jumlah baris per halaman bisa lebih sedikit dari `per_page`.
        // Itu pilihan sadar — alternatifnya menyimpan kolom `tahap` yang pasti
        // melenceng (lihat docblock migrasinya).
        if (filled($data['tahap'] ?? null)) {
            $baris = $baris->filter(
                fn (Order $o): bool => $this->tahap->untukPaket($o->items) === $data['tahap']
            )->values();
        }

        return response()->json([
            'data' => PaketLacakResource::collection($baris),
            'meta' => [
                'total' => $paket->total(),
                'per_page' => $paket->perPage(),
                'current_page' => $paket->currentPage(),
                'last_page' => $paket->lastPage(),
                'disaring_tahap' => $data['tahap'] ?? null,
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $order);

        $order->load(self::RELASI);

        return response()->json([
            'data' => new PaketLacakResource($order),
            // Garis waktunya dibentuk di server supaya apk lab dan apk pelanggan
            // tidak punya dua salinan urutan tahap yang bisa beda versi.
            'garis_waktu' => $this->tahap->garisWaktu(
                $this->tahap->untukPaket($order->items),
                untukPelanggan: $request->query('untuk') === 'pelanggan',
            ),
        ]);
    }

    /**
     * Tandai satu alat siap diambil / sudah diserahkan.
     *
     * Dua tahap ini yang disimpan, karena dua-duanya peristiwa fisik di meja
     * depan yang tidak tercermin di data mana pun. Enam tahap lainnya diturunkan
     * dan TIDAK bisa disetel tangan — kalau bisa, tahap yang disetel akan
     * bertentangan dengan status sesi dan tidak ada yang tahu mana yang benar.
     */
    public function tandaiTahapFisik(Request $request, OrderItem $orderItem): JsonResponse
    {
        $order = $orderItem->order;
        PenjagaOrganisasi::pastikanSatu($request, $order);

        $data = $request->validate([
            'tahap_fisik' => ['required', Rule::in([TahapPaket::SIAP_DIAMBIL, TahapPaket::DISERAHKAN])],
            // Wajib waktu diserahkan: tanpa nama penerima, "sudah diserahkan"
            // tidak bisa dipertanggungjawabkan kalau pelanggan menelepon bilang
            // alatnya belum sampai.
            'diserahkan_kepada' => [
                Rule::requiredIf(fn (): bool => $request->input('tahap_fisik') === TahapPaket::DISERAHKAN),
                'nullable', 'string', 'max:120',
            ],
        ], [
            'diserahkan_kepada.required' => 'Tulis nama yang mengambil alatnya — ini yang dipegang '
                .'kalau nanti ada pertanyaan alat sudah sampai atau belum.',
        ]);

        // Alat yang sudah diserahkan tidak bisa dikembalikan ke "siap diambil".
        // Barangnya sudah keluar dari lab; membatalkannya di sistem tidak
        // membatalkan kenyataannya, dan riwayat yang bisa dibalik berhenti jadi
        // bukti serah terima.
        if (
            $orderItem->tahap_fisik === TahapPaket::DISERAHKAN
            && $data['tahap_fisik'] === TahapPaket::SIAP_DIAMBIL
        ) {
            return response()->json([
                'message' => 'Alat ini sudah tercatat diserahkan. Kalau catatannya salah, '
                    .'perbaiki lewat catatan — jangan dimundurkan.',
            ], 422);
        }

        $sebelum = $orderItem->only(['tahap_fisik', 'tahap_fisik_pada', 'diserahkan_kepada']);

        $orderItem->fill([
            'tahap_fisik' => $data['tahap_fisik'],
            'tahap_fisik_pada' => now(),
            'tahap_fisik_oleh' => $request->user()->id,
            'diserahkan_kepada' => $data['diserahkan_kepada'] ?? $orderItem->diserahkan_kepada,
        ])->save();

        // Ditulis langsung, bukan lewat trait `Diaudit`: `order_items` tidak
        // memakai trait itu, dan memasangnya sekarang ikut mengaudit SEMUA
        // perubahan order_items di tempat lain — perubahan perilaku yang bukan
        // bagian fitur ini. Serah terima alat tanpa jejak bukan serah terima.
        AuditLog::create([
            'organization_id' => $order->organization_id,
            'entity_type' => $orderItem->getTable(),
            'entity_id' => $orderItem->id,
            'action' => AuditLog::ACTION_DIUBAH,
            'old_data' => $sebelum,
            'new_data' => $orderItem->only(['tahap_fisik', 'tahap_fisik_pada', 'diserahkan_kepada']),
            'changed_by' => $request->user()->id,
            'note' => 'Tahap fisik alat: '.$data['tahap_fisik'],
        ]);

        PerubahanDataOrganisasi::siarkanAman($order->organization_id, 'paket', 'diubah', $order->id);

        return response()->json([
            'message' => $data['tahap_fisik'] === TahapPaket::DISERAHKAN
                ? 'Tercatat diserahkan.'
                : 'Tercatat siap diambil.',
            'data' => new PaketLacakResource($orderItem->order->fresh()->load(self::RELASI)),
        ]);
    }
}
