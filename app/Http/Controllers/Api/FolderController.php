<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FolderRequest;
use App\Http\Resources\ArsipBerkasResource;
use App\Http\Resources\FolderResource;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Folder arsip yang bisa disusun bebas — bikin, ganti nama, pindah, hapus.
 *
 * Bedanya sama ArsipController: yang itu nurunin hierarki TETAP dari relasi
 * (perusahaan → alat → berkas) dan cuma bisa dibaca. Yang ini pohon beneran
 * yang user susun sendiri, sedalam apa pun.
 *
 * Dua-duanya jalan bareng di atas data yang sama. Satu sesi selalu punya
 * `folder_id`, dan tetap kelihatan juga lewat folder alatnya di ArsipController.
 *
 * ## Yang dijaga di sini
 *
 * 1. **Lintas organisasi** — 404, sama kayak arsip lama.
 * 2. **Lintas perusahaan** — folder & berkas nggak bisa pindah ke perusahaan
 *    lain. Ini yang bikin sertifikat lab terakreditasi nggak mungkin nyasar.
 * 3. **Siklus** — folder nggak bisa dipindah ke dalam keturunannya sendiri.
 *    Kalau lolos, cabang itu lepas dari akar dan isinya ilang dari arsip tanpa
 *    pernah kehapus.
 * 4. **Folder akar** — nggak bisa dinamain ulang, dipindah, atau dihapus. Dia
 *    titik jatuh otomatis buat sesi baru.
 * 5. **Hapus folder berisi** — ditolak 422, bukan cascade. Rekaman kalibrasi
 *    nggak boleh ilang gara-gara salah pencet.
 */
class FolderController extends Controller
{
    /** Buka folder akar perusahaan — dibikin kalau belum ada. */
    public function akar(Request $request, Customer $customer): ResourceCollection
    {
        $this->pastikanSatuOrganisasi($request, $customer->organization_id);

        return $this->isiFolder($request, Folder::akarUntuk($customer));
    }

    /** Isi folder: subfolder + berkas + breadcrumb. */
    public function show(Request $request, Folder $folder): ResourceCollection
    {
        $this->pastikanSatuOrganisasi($request, $folder->organization_id);

        return $this->isiFolder($request, $folder);
    }

    public function store(FolderRequest $request): JsonResponse
    {
        $induk = Folder::findOrFail($request->integer('parent_id'));
        $this->pastikanSatuOrganisasi($request, $induk->organization_id);

        if ($induk->kedalaman() + 1 > Folder::MAKS_KEDALAMAN) {
            throw ValidationException::withMessages([
                'parent_id' => 'Folder udah kelewat dalam (maksimal '.Folder::MAKS_KEDALAMAN.' tingkat).',
            ]);
        }

        $this->pastikanNamaBelumKepakai($request->string('nama'), $induk->customer_id, $induk->id);

        $folder = Folder::create([
            'organization_id' => $induk->organization_id,
            // Ikut induknya, bukan dari payload — biar folder nggak bisa
            // "diculik" ke perusahaan lain lewat request yang dikarang.
            'customer_id' => $induk->customer_id,
            'parent_id' => $induk->id,
            'nama' => $request->string('nama'),
            'is_root' => false,
        ]);

        return response()->json(['data' => new FolderResource($folder)], 201);
    }

    public function update(FolderRequest $request, Folder $folder): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folder->organization_id);
        $this->tolakKalauAkar($folder, 'Folder perusahaan nggak bisa diganti namanya — namanya ikut data pelanggan.');

        $this->pastikanNamaBelumKepakai(
            $request->string('nama'),
            $folder->customer_id,
            $folder->parent_id,
            kecualiId: $folder->id,
        );

        $folder->update(['nama' => $request->string('nama')]);

        return response()->json(['data' => new FolderResource($folder->refresh())]);
    }

    /** Pindahin folder (beserta seluruh isinya) ke induk lain. */
    public function pindah(Request $request, Folder $folder): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folder->organization_id);
        $this->tolakKalauAkar($folder, 'Folder perusahaan nggak bisa dipindah.');

        $data = $request->validate(['parent_id' => ['required', 'integer']]);

        $tujuan = Folder::findOrFail($data['parent_id']);
        $this->pastikanSatuOrganisasi($request, $tujuan->organization_id);

        if ($tujuan->customer_id !== $folder->customer_id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Folder nggak bisa dipindah ke perusahaan lain.',
            ]);
        }

        // Termasuk folder itu sendiri — "pindah ke diri sendiri" juga ditolak.
        if (in_array($tujuan->id, $folder->idBesertaKeturunan(), strict: true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Folder nggak bisa dipindah ke dalam folder isinya sendiri.',
            ]);
        }

        $this->pastikanNamaBelumKepakai($folder->nama, $folder->customer_id, $tujuan->id, kecualiId: $folder->id);

        $folder->update(['parent_id' => $tujuan->id]);

        return response()->json(['data' => new FolderResource($folder->refresh())]);
    }

    /** Pindahin satu berkas (sesi + sertifikatnya) ke folder lain. */
    public function pindahBerkas(Request $request, CalibrationSession $calibration): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $calibration->organization_id);

        $data = $request->validate(['folder_id' => ['required', 'integer']]);

        $tujuan = Folder::findOrFail($data['folder_id']);
        $this->pastikanSatuOrganisasi($request, $tujuan->organization_id);

        // Berkas cuma boleh pindah di dalam perusahaan pemilik alatnya.
        // Sertifikat yang nyasar ke folder perusahaan lain = data pelanggan
        // bocor ke pelanggan lain.
        $pemilik = $calibration->equipment?->customer_id;

        if ($pemilik !== null && $tujuan->customer_id !== $pemilik) {
            throw ValidationException::withMessages([
                'folder_id' => 'Berkas nggak bisa dipindah ke folder perusahaan lain.',
            ]);
        }

        $calibration->update(['folder_id' => $tujuan->id]);

        return response()->json([
            'data' => ['id' => $calibration->id, 'folder_id' => $tujuan->id],
        ]);
    }

    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folder->organization_id);
        $this->tolakKalauAkar($folder, 'Folder perusahaan nggak bisa dihapus.');

        // Sengaja nolak, bukan hapus berantai. Di lab terakreditasi, satu klik
        // yang ngilangin puluhan sertifikat itu risiko yang nggak sebanding
        // sama kemudahannya.
        if ($folder->adaIsinya()) {
            throw ValidationException::withMessages([
                'folder' => 'Folder masih ada isinya. Pindahin atau hapus isinya dulu.',
            ]);
        }

        $folder->delete();

        return response()->json(['message' => 'Folder dihapus.']);
    }

    /**
     * Daftar isi satu folder: subfolder (utuh) + berkas (dipaginasi).
     *
     * Subfolder nggak ikut dipaginasi — jumlahnya kecil, dan file manager yang
     * nyembunyiin sebagian foldernya di halaman 2 bikin orang ngira foldernya
     * ilang.
     */
    private function isiFolder(Request $request, Folder $folder): ResourceCollection
    {
        $user = $request->user();

        $subfolder = $folder->children()
            ->withCount(['children', 'calibrationSessions'])
            ->orderBy('nama')
            ->get();

        $berkas = $folder->calibrationSessions()
            ->with(['teknisi', 'certificate', 'equipment'])
            // Teknisi cuma lihat sesi miliknya sendiri — sama kayak
            // /calibrations, /certificates, dan arsip lama.
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn ($query) => $query->where('teknisi_id', $user->id),
            )
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return ArsipBerkasResource::collection($berkas)->additional([
            'folder' => [
                'tipe' => 'folder',
                'id' => $folder->id,
                'nama' => $folder->nama,
                'is_root' => $folder->is_root,
                'parent_id' => $folder->parent_id,
                'perusahaan' => [
                    'id' => $folder->customer_id,
                    'nama' => $folder->customer?->nama,
                ],
                // Rantai dari akar sampai folder ini — frontend nggak perlu
                // manjat parent satu-satu buat gambar breadcrumb.
                'breadcrumb' => $folder->breadcrumb()
                    ->map(fn (Folder $f) => ['id' => $f->id, 'nama' => $f->nama, 'is_root' => $f->is_root])
                    ->values(),
            ],
            'subfolder' => FolderResource::collection($subfolder),
        ]);
    }

    /**
     * Nama folder unik per induk. Dicek di sini juga (bukan cuma ngandelin
     * unique index DB) supaya user dapat pesan yang kebaca, bukan 500.
     */
    private function pastikanNamaBelumKepakai(
        string $nama,
        int $customerId,
        ?int $parentId,
        ?int $kecualiId = null,
    ): void {
        $bentrok = Folder::query()
            ->where('customer_id', $customerId)
            ->where('parent_id', $parentId)
            ->where('nama', $nama)
            ->when($kecualiId, fn ($query) => $query->whereKeyNot($kecualiId))
            ->exists();

        if ($bentrok) {
            throw ValidationException::withMessages([
                'nama' => 'Di folder ini udah ada folder dengan nama yang sama.',
            ]);
        }
    }

    private function tolakKalauAkar(Folder $folder, string $pesan): void
    {
        if ($folder->is_root) {
            throw ValidationException::withMessages(['folder' => $pesan]);
        }
    }

    /** Jaring pengaman multi-tenant: PT lain nggak boleh ngintip arsip kita. */
    private function pastikanSatuOrganisasi(Request $request, ?int $organizationId): void
    {
        abort_if($organizationId !== $request->user()->organization_id, 404);
    }
}
