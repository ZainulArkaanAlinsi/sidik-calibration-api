<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerLookupResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Master data pelanggan — admin doang (dijaga `role:admin` di routes).
 *
 * SATU pengecualian: `lookup()` kebuka semua role. Lihat alasannya di sana.
 */
class CustomerController extends Controller
{
    /**
     * Daftar pelanggan buat dropdown — **kebuka semua role**, read-only.
     *
     * Kenapa perlu endpoint sendiri padahal `index()` udah ada: `POST /equipments`
     * boleh dipakai **teknisi** dan `pelanggan_id` itu **wajib**, tapi seluruh
     * `/customers` admin-only. Jadi form Tambah Alat mulus waktu dites pakai akun
     * admin lalu mentok total di akun teknisi — dropdown-nya `403`, dan alatnya
     * nggak bisa disimpen sama sekali.
     *
     * `docs/kontrak-api.md` §8 sempat nyaranin pakai `GET /arsip/perusahaan` buat
     * ini. Itu nggak nyelesaiin masalahnya: endpoint itu ngelist FOLDER, dan
     * folder cuma ada buat PT yang udah pernah punya sertifikat — jadi pelanggan
     * BARU (justru yang paling sering diinput) nggak akan nongol. Buat teknisi
     * daftarnya juga disaring lagi per-role.
     *
     * Yang dikirim cuma `id`, `nama`, `alamat` — sengaja nggak bawa
     * `contact_person`/`telepon`/`email`. Ini dropdown, bukan layar CRUD: role
     * yang nggak boleh ngelola pelanggan nggak perlu megang kontaknya juga.
     * `alamat` ikut karena blok OWNER di lembar kerja butuh, dan itu udah kekirim
     * ke semua role lewat `EquipmentResource.pelanggan` — jadi bukan data baru.
     */
    public function lookup(Request $request): AnonymousResourceCollection
    {
        $cari = $request->string('search')->value() !== ''
            ? $request->string('search')
            // `q` diterima juga: dokumen lama nyebut param ini buat lookup
            // pelanggan, dan filter yang diabaikan diam-diam itu bug yang mahal
            // (daftarnya balik utuh, kelihatan kayak pencariannya rusak).
            : $request->string('q');

        $pelanggan = Customer::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when(
                (string) $cari !== '',
                fn ($query) => $query->where('nama', 'like', '%'.$cari.'%'),
            )
            ->orderBy('nama')
            ->paginate(15)
            ->withQueryString();

        return CustomerLookupResource::collection($pelanggan);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $customers = Customer::query()
            ->withCount('equipments')
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->filled('search'), fn ($query) => $query->where(
                'nama',
                'like',
                '%'.$request->string('search').'%',
            ))
            ->orderBy('nama')
            ->paginate(15)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $customer = Customer::create([
            ...$request->validated(),
            'organization_id' => $request->user()->organization_id,
        ]);

        return response()->json(['data' => new CustomerResource($customer)], 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $customer);

        return response()->json(['data' => new CustomerResource($customer->loadCount('equipments'))]);
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $customer);

        $customer->update($request->validated());

        return response()->json(['data' => new CustomerResource($customer->fresh())]);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $customer);

        // Pelanggan yang masih punya alat nggak boleh dihapus — kalau dipaksa,
        // alat & riwayat kalibrasinya jadi yatim.
        if ($customer->equipments()->exists()) {
            return response()->json([
                'message' => 'Pelanggan ini masih punya alat terdaftar. Pindahin atau hapus alatnya dulu.',
            ], 422);
        }

        $customer->delete();

        return response()->json(['message' => 'Pelanggan dihapus.']);
    }

    private function pastikanSatuOrganisasi(Request $request, Customer $customer): void
    {
        abort_if($customer->organization_id !== $request->user()->organization_id, 404);
    }
}
