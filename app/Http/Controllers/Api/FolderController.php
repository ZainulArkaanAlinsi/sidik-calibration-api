<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesFolderAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\Customer;
use App\Models\Folder;
use App\Services\FolderOrganizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Folder Manager (spesifikasi poin 3 & 7) — CRUD folder.
 *
 * Sebagian besar isinya KEBENTUK SENDIRI: tiap sertifikat terbit langsung
 * masuk ke `PT / tahun` lewat `FolderOrganizer`. Yang di sini buat ngerapiin
 * sisanya — bikin folder arsip sendiri, ganti nama, hapus yang nggak kepake.
 */
class FolderController extends Controller
{
    use ScopesFolderAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $folder = $this->query($request)
            ->withCount(['children', 'files'])
            // `parent_id` nggak dikirim = tampilkan akar (daftar PT). Dikirim
            // kosong pun sama artinya — mobile gampang ngirim string kosong.
            ->when(
                $request->filled('parent_id'),
                fn (Builder $query) => $query->where('parent_id', $request->integer('parent_id')),
                fn (Builder $query) => $query->whereNull('parent_id'),
            )
            ->when(
                $request->filled('customer_id'),
                fn (Builder $query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            ->when(
                $request->filled('q'),
                fn (Builder $query) => $query->where('nama', 'like', '%'.$request->string('q').'%'),
            )
            ->orderBy('nama')
            ->get();

        return FolderResource::collection($folder);
    }

    /**
     * Buka folder akar satu PT — dibikin kalau belum ada.
     *
     * Ini inti Folder Manager: tap PT → lihat isinya (docs/permintaan-backend-2026-07-24.md
     * §2b baris 1). `index` doang nggak cukup, soalnya PT yang belum pernah punya
     * sertifikat belum punya folder — tanpa find-or-create, tap-nya mentok 404
     * padahal PT-nya ada.
     *
     * Balikannya bentuk `show` (breadcrumb + subfolder + berkas) — dipanggil
     * langsung, bukan disalin, biar mobile cukup satu parser buat dua endpoint.
     *
     * SEMUA ROLE boleh baca, tapi yang bisa MEMBIKIN folder cuma **admin**.
     * Dua alasannya ada di badan fungsi (teknisi & viewer), dan yang paling
     * menentukan: `POST`/`PUT`/`DELETE /folders` semuanya admin-only, jadi pintu
     * ini nggak boleh jadi celah nulis buat role yang di tempat lain ditolak.
     */
    public function folderPelanggan(
        Request $request,
        Customer $customer,
        FolderOrganizer $organizer,
    ): JsonResponse {
        // PT milik organisasi lain → 404, bukan 403. 403 ngasih tahu "id ini ada,
        // cuma bukan punyamu", dan karena responsnya bawa nama PT, beda 403 vs
        // 404 doang udah cukup buat nyisir daftar pelanggan lab lain.
        abort_if(
            $customer->organization_id !== $request->user()->organization_id,
            404,
            'Perusahaan nggak ketemu.',
        );

        // Non-admin nggak mancing pembikinan folder — cuma buka yang udah ada.
        //
        // TEKNISI: aturan privasi Folder Manager (lihat ScopesFolderAccess) bikin
        // teknisi cuma lihat folder yang ada isinya buat DIA. Jadi kalau dia buka
        // PT yang dia nggak punya kerjaan di situ, folder yang baru dibikin
        // langsung disembunyiin lagi oleh `show()` — barisnya kebikin cuma buat
        // dijawab 404. Di alur nyata ini nggak kerasa: daftar PT yang dia lihat
        // udah tersaring, jadi yang bisa dia tap selalu folder yang ada isinya.
        //
        // VIEWER: role baca-saja. Bikin barisnya sebagai efek samping GET bikin
        // "viewer nggak pernah nulis" berhenti benar — dan itu klaim yang lebih
        // berharga daripada kemudahan satu tap.
        //
        // Yang beneran butuh find-or-create cuma admin: dia milih PT dari daftar
        // pelanggan, termasuk PT yang belum pernah punya sertifikat sama sekali.
        if (! $request->user()->isAdmin()) {
            $folder = $organizer->cariFolderPelanggan($customer);

            abort_if($folder === null, 404, 'Folder perusahaan ini belum ada.');

            return $this->show($request, $folder);
        }

        $folder = DB::transaction(function () use ($customer, $organizer): Folder {
            // Kunci baris PT-nya dulu biar dua request buat PT yang sama nggak
            // bikin DUA folder akar. `firstOrCreate` nggak atomik, dan unique
            // index-nya nggak nahan: `parent_id` folder akar itu NULL, dan
            // MySQL/SQLite nganggep NULL selalu beda satu sama lain.
            //
            // Kenapa baru sekarang dijaga padahal FolderOrganizer udah lama
            // begini: dulu cuma kepanggil sekali per penerbitan sertifikat.
            // Endpoint ini kepanggil tiap tap, jadi dobel-tap doang udah cukup.
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            return $organizer->folderPelanggan($customer);
        });

        return $this->show($request, $folder);
    }

    public function show(Request $request, Folder $folder): JsonResponse
    {
        $this->pastikanBolehLihat($request, $folder);

        $folder->load([
            'customer',
            'children' => fn ($query) => $query->withCount(['children', 'files'])->orderBy('nama'),
            // Isi folder disaring pakai aturan yang sama kayak daftar file —
            // teknisi cuma lihat punyanya sendiri.
            'files' => fn ($query) => $query
                ->whereIn('id', $this->queryFileYangBolehDilihat($request)->select('id'))
                ->with(['certificate', 'uploader'])
                ->orderByDesc('id'),
        ]);

        return response()->json(['data' => new FolderResource($folder)]);
    }

    /** Admin doang (dijaga `role:admin` di routes). */
    public function store(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                Rule::exists('folders', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($pesan = $this->namaBentrok($organizationId, $data['parent_id'] ?? null, $data['nama'])) {
            return response()->json(['message' => $pesan], 422);
        }

        $folder = Folder::create([
            ...$data,
            'organization_id' => $organizationId,
            // Folder buatan tangan SELALU `manual`. Tipe `sistem` cuma boleh
            // lahir dari FolderOrganizer — kalau bisa dibikin lewat API, ada
            // yang bakal bikin folder "sistem" palsu yang nggak nyambung ke data.
            'tipe' => Folder::TIPE_MANUAL,
        ]);

        return response()->json([
            'data' => new FolderResource($folder->loadCount(['children', 'files'])),
        ], 201);
    }

    /** Admin doang. */
    public function update(Request $request, Folder $folder): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folder);

        // Folder sistem namanya = nama PT / tahun, dan itu yang dipakai
        // `FolderOrganizer` buat nemuin folder yang udah ada. Begitu direname,
        // sertifikat berikutnya bikin folder baru dan arsipnya kepecah dua.
        // Keterangannya tetap boleh diubah.
        $data = $request->validate([
            'nama' => [
                $folder->tipe === Folder::TIPE_SISTEM ? 'prohibited' : 'sometimes',
                'string', 'max:255',
            ],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [
            'nama.prohibited' => 'Folder ini kebentuk otomatis dari data, jadi namanya ngikut nama PT/tahun.',
        ]);

        if (isset($data['nama']) && $data['nama'] !== $folder->nama) {
            $pesan = $this->namaBentrok($folder->organization_id, $folder->parent_id, $data['nama']);

            if ($pesan !== null) {
                return response()->json(['message' => $pesan], 422);
            }
        }

        $folder->update($data);

        return response()->json([
            'data' => new FolderResource($folder->fresh()->loadCount(['children', 'files'])),
        ]);
    }

    /**
     * Pindahin folder ke induk lain (`docs/permintaan-backend-2026-07-24.md` §2b).
     *
     * Kepisah dari `update()` yang cuma rename, karena aturannya beda: yang ini
     * nyentuh struktur pohonnya, dan struktur yang rusak bikin folder ilang dari
     * layar tanpa kehapus. Admin doang.
     */
    public function pindah(Request $request, Folder $folder): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folder);

        // Folder SISTEM nggak bisa dipindah — alasannya sama kayak larangan rename
        // di `update()`, cuma jalurnya beda. `FolderOrganizer` nemuin folder akar
        // PT dari `parent_id = null` dan folder tahun dari `parent_id = akar->id`.
        // Begitu salah satunya dipindah, kriterianya nggak nyocok lagi, sertifikat
        // berikutnya bikin folder BARU, dan arsip satu PT kepecah dua tanpa ada
        // yang sadar.
        if ($folder->tipe === Folder::TIPE_SISTEM) {
            return response()->json([
                'message' => 'Folder ini kebentuk otomatis dari data (PT/tahun), jadi tempatnya '
                    .'ngikut datanya dan nggak bisa dipindah. Yang bisa dipindah folder buatan sendiri.',
            ], 422);
        }

        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            // `null` artinya jadiin folder akar — sama kayak yang `POST /folders`
            // udah izinin, jadi bukan kemampuan baru.
            'parent_id' => [
                'present', 'nullable',
                Rule::exists('folders', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'parent_id.present' => 'Kirim `parent_id` — pakai `null` kalau mau dijadiin folder akar.',
            'parent_id.exists' => 'Folder tujuannya nggak ketemu.',
        ]);

        $tujuanId = $data['parent_id'] === null ? null : (int) $data['parent_id'];

        if ($tujuanId === $folder->id) {
            return response()->json([
                'message' => 'Folder nggak bisa dipindah ke dalam dirinya sendiri.',
            ], 422);
        }

        // Pindah ke KETURUNAN sendiri bikin siklus: folder-nya lepas dari pohon
        // dan ilang dari semua layar, padahal barisnya masih ada di database.
        // `jalur()` udah dibatesin 10 tingkat biar nggak loop tak hingga, jadi
        // kerusakannya sunyi — nggak error, cuma nggak keliatan lagi.
        if ($tujuanId !== null && $this->keturunanDari($folder, $tujuanId)) {
            return response()->json([
                'message' => 'Folder nggak bisa dipindah ke dalam folder yang ada DI DALAM dia. '
                    .'Kalau dipaksa, folder-nya lepas dari pohon dan ilang dari layar.',
            ], 422);
        }

        if ($tujuanId === $folder->parent_id) {
            // Udah di situ — nggak diapa-apain, tapi tetap `200` biar mobile yang
            // nge-drag ke tempat yang sama nggak nampilin error.
            return response()->json([
                'data' => new FolderResource($folder->loadCount(['children', 'files'])),
            ]);
        }

        if ($pesan = $this->namaBentrok($folder->organization_id, $tujuanId, (string) $folder->nama)) {
            return response()->json(['message' => $pesan], 422);
        }

        $folder->update(['parent_id' => $tujuanId]);

        return response()->json([
            'data' => new FolderResource($folder->fresh()->loadCount(['children', 'files'])),
        ]);
    }

    /**
     * Apakah `$kandidatId` ada di dalam `$folder` (anak, cucu, dst)?
     *
     * Ditelusuri dari kandidat NAIK ke akar, bukan dari folder turun ke bawah:
     * naik itu satu query per tingkat dan pohonnya cuma dua tingkat, sedangkan
     * turun harus nyapu seluruh cabang.
     *
     * Batas 10 tingkat sama kayak `Folder::jalur()` — kalau datanya udah rusak
     * dan bikin siklus, ini berhenti, bukan nggantung.
     */
    private function keturunanDari(Folder $folder, int $kandidatId): bool
    {
        $kursor = Folder::find($kandidatId);

        for ($i = 0; $kursor !== null && $i < 10; $i++) {
            if ($kursor->id === $folder->id) {
                return true;
            }

            $kursor = $kursor->parent_id === null ? null : Folder::find($kursor->parent_id);
        }

        return false;
    }

    /** Admin doang. */
    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folder);

        // Folder sistem boleh dihapus cuma kalau udah kosong — kalau masih ada
        // isinya, yang kehapus itu jalur ke sertifikat resmi, dan itu bukan
        // "ngerapiin", itu ngilangin arsip.
        if ($folder->tipe === Folder::TIPE_SISTEM
            && ($folder->files()->exists() || $folder->children()->exists())) {
            return response()->json([
                'message' => 'Folder otomatis yang masih ada isinya nggak bisa dihapus. '
                    .'Kosongin dulu isinya kalau memang mau dibuang.',
            ], 422);
        }

        $folder->delete();

        return response()->json(null, 204);
    }

    /**
     * Nama folder harus unik dalam satu induk. Dicek di aplikasi, bukan lewat
     * unique index: `parent_id` bisa null (folder akar), dan di MySQL/SQLite
     * dua baris dengan kolom unik bernilai NULL itu dianggap nggak bentrok —
     * jadi index-nya nggak bakal nahan dua folder akar bernama sama.
     */
    private function namaBentrok(int $organizationId, ?int $parentId, string $nama): ?string
    {
        $ada = Folder::query()
            ->where('organization_id', $organizationId)
            ->where('parent_id', $parentId)
            ->where('nama', $nama)
            ->exists();

        return $ada ? "Sudah ada folder bernama \"{$nama}\" di lokasi ini." : null;
    }

    /** @return Builder<Folder> */
    private function query(Request $request): Builder
    {
        return Folder::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with('customer')
            // Teknisi cuma dikasih lihat folder yang beneran ada isinya buat
            // dia — folder PT yang nggak pernah dia sentuh nggak usah nongol.
            ->when($this->teknisiBiasa($request), fn (Builder $query) => $query->where(
                fn (Builder $q) => $q
                    ->whereIn('id', $this->queryFileYangBolehDilihat($request)->select('folder_id'))
                    ->orWhereHas(
                        'children',
                        fn (Builder $anak) => $anak->whereIn(
                            'id',
                            $this->queryFileYangBolehDilihat($request)->select('folder_id'),
                        ),
                    ),
            ));
    }

    private function pastikanBolehLihat(Request $request, Folder $folder): void
    {
        $this->pastikanSatuOrganisasi($request, $folder);

        if (! $this->teknisiBiasa($request)) {
            return;
        }

        abort_unless($this->query($request)->whereKey($folder->id)->exists(), 404);
    }

    /** Jaring pengaman multi-tenant: PT lain nggak boleh baca folder kita. */
    private function pastikanSatuOrganisasi(Request $request, Folder $folder): void
    {
        abort_if($folder->organization_id !== $request->user()->organization_id, 404);
    }
}
