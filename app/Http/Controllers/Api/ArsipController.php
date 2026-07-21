<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArsipAlatResource;
use App\Http\Resources\ArsipBerkasResource;
use App\Http\Resources\ArsipPerusahaanResource;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Arsip = tampilan "file manager" atas data yang UDAH ADA, bukan penyimpanan
 * baru. Hierarkinya diturunkan langsung dari relasi:
 *
 *     Customer (folder perusahaan)
 *       └─ Equipment (folder alat)         [customer_id]
 *            └─ CalibrationSession + Certificate (berkas)   [equipment_id]
 *
 * Nggak ada tabel "folder" terpisah — sertifikat "masuk folder perusahaan yang
 * benar" secara otomatis karena alatnya `customer_id`-nya udah nunjuk ke situ.
 *
 * AUTHZ: dibuka buat semua role yang login (arsip = baca-baca hasil kerja).
 * Nama & daftar alat pelanggan toh udah kelihatan teknisi lewat /equipments.
 * Yang tetap disaring cuma level BERKAS: teknisi cuma lihat sesi miliknya
 * sendiri (sama kayak /calibrations & /certificates). Kalau mau lebih ketat,
 * tinggal tambahin `->middleware('role:admin')` di grup routenya.
 *
 * Angka `jumlah_*` di level folder masih org-wide (belum disaring per-teknisi) —
 * sengaja, biar hitungannya sekali query; bedanya baru kelihatan pas teknisi
 * buka folder dan isinya lebih sedikit dari angkanya.
 */
class ArsipController extends Controller
{
    /** Folder perusahaan — daftar pelanggan + ringkasan isinya. */
    public function perusahaan(Request $request): AnonymousResourceCollection
    {
        $perusahaan = Customer::query()
            ->where('organization_id', $request->user()->organization_id)
            ->withCount([
                'equipments',
                // Jumlah sertifikat TERBIT dari seluruh alat pelanggan ini.
                'calibrationSessions as jumlah_sertifikat' => fn ($query) => $query
                    ->whereHas('certificate', fn ($sub) => $sub->where('status', Certificate::STATUS_TERBIT)),
            ])
            ->withMax('calibrationSessions', 'tanggal_kalibrasi')
            ->when($request->filled('search'), fn ($query) => $query->where(
                'nama',
                'like',
                '%'.$request->string('search').'%',
            ))
            ->orderBy('nama')
            ->paginate(15)
            ->withQueryString();

        return ArsipPerusahaanResource::collection($perusahaan);
    }

    /** Isi folder perusahaan = folder-folder alat miliknya. */
    public function alat(Request $request, Customer $customer): ResourceCollection
    {
        $this->pastikanSatuOrganisasi($request, $customer->organization_id);

        $alat = $customer->equipments()
            ->with('category')
            ->withCount([
                'calibrationSessions as jumlah_sesi',
                'calibrationSessions as jumlah_sertifikat' => fn ($query) => $query
                    ->whereHas('certificate', fn ($sub) => $sub->where('status', Certificate::STATUS_TERBIT)),
            ])
            ->when($request->filled('search'), function ($query) use ($request) {
                $keyword = '%'.$request->string('search').'%';

                $query->where(fn ($sub) => $sub
                    ->where('nama_alat', 'like', $keyword)
                    ->orWhere('serial_number', 'like', $keyword)
                    ->orWhere('merk', 'like', $keyword));
            })
            ->orderBy('nama_alat')
            ->paginate(15)
            ->withQueryString();

        // `additional()` nyelipin konteks folder di samping data+meta paginasi —
        // dipakai frontend buat breadcrumb tanpa request kedua.
        return ArsipAlatResource::collection($alat)->additional([
            'folder' => [
                'tipe' => 'perusahaan',
                'id' => $customer->id,
                'nama' => $customer->nama,
                'alamat' => $customer->alamat,
                'contact_person' => $customer->contact_person,
                'telepon' => $customer->telepon,
                'email' => $customer->email,
            ],
        ]);
    }

    /** Isi folder alat = berkas kalibrasi (sesi + sertifikat). */
    public function berkas(Request $request, Equipment $equipment): ResourceCollection
    {
        $this->pastikanSatuOrganisasi($request, $equipment->organization_id);

        $user = $request->user();

        $berkas = $equipment->calibrationSessions()
            ->with(['teknisi', 'certificate'])
            // Teknisi cuma lihat sesi miliknya sendiri — sama kayak /calibrations.
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn ($query) => $query->where('teknisi_id', $user->id),
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $equipment->loadMissing('customer');

        return ArsipBerkasResource::collection($berkas)->additional([
            'folder' => [
                'tipe' => 'alat',
                'id' => $equipment->id,
                'nama_alat' => $equipment->nama_alat,
                'serial_number' => $equipment->serial_number,
                'merk' => $equipment->merk,
                'perusahaan' => [
                    'id' => $equipment->customer?->id,
                    'nama' => $equipment->customer?->nama,
                ],
            ],
        ]);
    }

    /** Jaring pengaman multi-tenant: PT lain nggak boleh ngintip arsip kita. */
    private function pastikanSatuOrganisasi(Request $request, int $organizationId): void
    {
        abort_if($organizationId !== $request->user()->organization_id, 404);
    }
}
