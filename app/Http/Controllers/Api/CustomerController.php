<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Master data pelanggan — admin doang (dijaga `role:admin` di routes). */
class CustomerController extends Controller
{
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
