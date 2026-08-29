<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\PelangganCepatRequest;
use App\Http\Resources\CustomerLookupResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\User;
use App\Services\Direktori\DirektoriGagal;
use App\Services\Direktori\DirektoriPerusahaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Master data pelanggan — admin doang (dijaga `role:admin` di routes).
 *
 * TIGA pengecualian, semuanya buat satu keadaan yang sama — teknisi di lapangan
 * mau mendaftarkan alat punya pelanggan yang belum ada di master:
 *
 *  - `lookup()`   — cari di master lab. Kebuka semua role.
 *  - `direktori()`— cari di direktori perusahaan LUAR. Admin & teknisi.
 *  - `cepat()`    — daftarkan pelanggan baru, nama & alamat doang. Admin & teknisi.
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

        // Dihitung sekali di luar query. Kata kunci yang isinya tanda baca doang
        // (`.`, `-`) turun jadi string KOSONG, dan `LIKE '%%'` mencocoki SEMUA
        // baris — filternya mati diam-diam dan daftarnya balik utuh, persis bug
        // mahal yang dijaga komentar di bawah.
        $cariNormal = Customer::normalkanNama((string) $cari);

        $pelanggan = Customer::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when(
                (string) $cari !== '',
                // `nama` ATAU `alamat`, DAN kurungnya wajib.
                //
                // Tanpa `where(fn ...)` pembungkus, `orWhere` naik ke tingkat
                // atas dan jadi `organization_id = X AND nama LIKE .. OR alamat
                // LIKE ..` — pelanggan lab SEBELAH yang alamatnya kena
                // ikut kebawa. Endpoint ini kebuka semua role.
                //
                // Alamat ikut dicari karena begitu cara teknisi mengingat
                // pelanggannya: satu kawasan industri isinya belasan PT dengan
                // nama mirip, dan yang dia pegang alamat penjemputannya.
                fn ($query) => $query->where(
                    fn ($q) => $q
                        ->where('nama', 'like', '%'.$cari.'%')
                        ->orWhere('alamat', 'like', '%'.$cari.'%')
                        // Lawan tanda baca. Yang tersimpan `PT. Maju Jaya`
                        // sementara teknisi mengetik `PT Maju Jaya` (atau
                        // sebaliknya) nggak ketemu lewat dua baris di atas —
                        // dan yang nggak ketemu itu bakal didaftarkan ulang,
                        // bikin satu perusahaan punya dua riwayat kalibrasi
                        // yang terbelah.
                        ->when(
                            $cariNormal !== '',
                            fn ($q2) => $q2->orWhere('nama_normal', 'like', '%'.$cariNormal.'%'),
                        ),
                ),
            )
            ->orderBy('nama')
            ->paginate(15)
            ->withQueryString();

        return CustomerLookupResource::collection($pelanggan);
    }

    /**
     * Cari nama & alamat perusahaan di direktori LUAR — admin & teknisi.
     *
     * Dipakai waktu [lookup] nol hasil: pelanggannya beneran belum pernah
     * masuk master lab. Tanpa ini teknisi mengetik nama & alamat dari ingatan,
     * dan alamat yang salah ketik mendarat di blok OWNER sertifikat.
     *
     * ## Kenapa lewat server, bukan HP nembak langsung
     *
     * Supaya API key-nya nggak pernah ada di dalam APK. Key di aplikasi bisa
     * dicabut siapa pun dari berkasnya lalu dipakai orang lain atas tagihan lab
     * ini — dan tagihannya per request. Lewat sini, key-nya cuma ada di `.env`
     * server, kuotanya keawasi di satu tempat, dan menukar penyedia nggak butuh
     * rilis APK baru.
     *
     * ## Tiga jawaban yang HARUS bisa dibedakan klien
     *
     *  - `200` + daftar (boleh kosong) — direktorinya menjawab, segitu hasilnya.
     *  - `503` — key-nya belum disetel di server ini.
     *  - `502` — direktorinya nggak bisa dihubungi atau nolak.
     *
     * Kalau ketiganya diratakan jadi "daftar kosong", teknisi membacanya sebagai
     * "PT-nya nggak ada di direktori" lalu mendaftarkan ulang perusahaan yang
     * sebenarnya ada di sana — nambah kembar justru lewat fitur yang dipasang
     * buat menguranginya.
     */
    public function direktori(Request $request, DirektoriPerusahaan $direktori): JsonResponse
    {
        $data = $request->validate([
            // Minimal tiga huruf. Di bawah itu hasilnya sampah buat orang yang
            // lagi mencocokkan satu papan nama, dan requestnya tetap ditagih.
            'search' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        if (! $direktori->tersedia()) {
            return response()->json([
                'message' => 'Pencarian direktori perusahaan belum disetel di server ini. '
                    .'Ketik nama & alamat PT-nya manual dulu.',
                'tersedia' => false,
            ], 503);
        }

        try {
            $hasil = $direktori->cari($data['search']);
        } catch (DirektoriGagal $e) {
            // Dilaporkan ke log supaya sebabnya bisa ditelusuri, tapi TIDAK
            // diteruskan ke klien: pesan penyedia bisa memuat potongan key atau
            // id proyek, dan yang dibutuhkan layar teknisi cuma jalan keluarnya.
            report($e);

            return response()->json([
                'message' => 'Direktori perusahaan lagi nggak bisa dihubungi. '
                    .'Ketik nama & alamat PT-nya manual dulu.',
                'tersedia' => true,
            ], 502);
        }

        return response()->json([
            'data' => array_map(fn ($perusahaan) => $perusahaan->toArray(), $hasil),
        ]);
    }

    /**
     * Daftarkan pelanggan baru dari lapangan — admin & teknisi, langsung kepakai.
     *
     * Sejalan dengan keputusan K3/K4 buat nama alat: yang ditambah teknisi
     * langsung bisa dipakai, tanpa antrean persetujuan. Alasannya sama —
     * pelanggan yang belum terdaftar bikin `pelanggan_id` (wajib di
     * `POST /equipments`) nggak bisa diisi, dan kerjaan di lapangan berhenti
     * total sampai ada admin yang buka laptop.
     *
     * ## Yang ditukar: kembar
     *
     * Unique index yang ada jalan di `nama` MENTAH, jadi `PT. Maju Jaya` lolos
     * berdampingan dengan `PT Maju Jaya`. Kembar di sini bukan cuma berantakan:
     * folder arsip, sertifikat, dan daftar alat semuanya nempel ke baris
     * pelanggan, jadi satu perusahaan yang kedaftar dua kali punya riwayat
     * kalibrasi yang terbelah — dan yang kelihatan di layar cuma separuhnya.
     *
     * Penebusnya: sebelum baris lahir, yang mirip DITUNJUKKAN dulu. Teknisi
     * memilih yang sudah ada, atau menyatakan ini perusahaan lain
     * (`tetap_buat`). Kembar tetap mungkin — tapi jadi tindakan sadar, bukan
     * kecelakaan mengetik.
     *
     * `tetap_buat` sengaja **nggak** bisa menembus nama yang PERSIS sama: itu
     * ditahan unique index di database, dan menembusnya cuma menghasilkan 500
     * dari driver.
     */
    public function cepat(PelangganCepatRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $nama = trim((string) $request->string('nama'));
        $ref = trim((string) $request->string('direktori_ref'));
        $ref = $ref === '' ? null : $ref;

        $kandidat = Customer::query()
            ->where('organization_id', $user->organization_id)
            // Kurungnya WAJIB. Tanpa `where(fn ...)` pembungkus, `orWhere` naik
            // ke tingkat atas dan pelanggan lab SEBELAH yang `direktori_ref`-nya
            // kena ikut kebawa ke layar teknisi ini.
            ->where(function ($query) use ($nama, $ref) {
                $query->where('nama_normal', Customer::normalkanNama($nama));

                if ($ref !== null) {
                    // Perusahaan yang sama dipilih dua kali dari direktori
                    // dikenali PERSIS di sini, tanpa mengadu ejaan nama —
                    // direktori bisa saja menulisnya beda antar pencarian.
                    $query->orWhere('direktori_ref', $ref);
                }
            })
            ->orderBy('nama')
            ->limit(5)
            ->get();

        $namaPersisSudahAda = $kandidat->contains(fn (Customer $ada) => $ada->nama === $nama);

        if ($kandidat->isNotEmpty() && ($namaPersisSudahAda || ! $request->boolean('tetap_buat'))) {
            return response()->json([
                'message' => $namaPersisSudahAda
                    ? 'Pelanggan dengan nama ini sudah ada. Buka yang sudah ada, atau bedakan '
                        .'namanya (mis. tambah kota atau cabangnya).'
                    : 'Ada pelanggan dengan nama yang mirip. Kalau salah satunya yang kamu maksud, '
                        .'pilih itu — kalau memang perusahaan lain, lanjutkan.',
                // Klien butuh bisa membedakan dua keadaan ini: yang pertama
                // buntu (harus ganti nama), yang kedua bisa dilanjutkan dengan
                // `tetap_buat`. Tombol "lanjut" yang muncul di keadaan pertama
                // cuma bikin teknisi menabrak 409 berkali-kali.
                'nama_persis_sudah_ada' => $namaPersisSudahAda,
                'kandidat' => CustomerLookupResource::collection($kandidat),
            ], 409);
        }

        $pelanggan = new Customer;
        $pelanggan->fill([
            'nama' => $nama,
            'alamat' => $request->filled('alamat') ? trim((string) $request->string('alamat')) : null,
        ]);

        // Empat kolom di bawah diisi SERVER, dan nggak satu pun boleh datang
        // dari payload — lihat `PelangganCepatRequest` buat alasan `sumber`.
        $pelanggan->organization_id = $user->organization_id;
        $pelanggan->dibuat_oleh_user_id = $user->id;
        $pelanggan->direktori_ref = $ref;
        $pelanggan->sumber = match (true) {
            // Datang dari direktori itu asal DATA-nya, bukan pangkat yang
            // mengetik — jadi dia menang atas admin/teknisi. Baris begini
            // nama & alamatnya dari direktori tempat usaha, dan admin yang
            // merapikan master berhak tahu itu.
            $ref !== null => Customer::SUMBER_DIREKTORI,
            $user->isAdmin() => Customer::SUMBER_ADMIN,
            default => Customer::SUMBER_TEKNISI,
        };

        $pelanggan->save();

        // Bentuk lookup, bukan `CustomerResource`: yang manggil ini picker
        // pelanggan, dan role yang nggak boleh ngelola pelanggan nggak perlu
        // megang kontaknya.
        return response()->json(['data' => new CustomerLookupResource($pelanggan)], 201);
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
        $customer = new Customer;
        $customer->fill($request->validated());
        $customer->organization_id = $request->user()->organization_id;
        // `sumber` sengaja nggak diisi di sini: bawaan kolomnya sudah `admin`,
        // dan rutenya memang dijaga `role:admin`.
        $customer->dibuat_oleh_user_id = $request->user()->id;
        $customer->save();

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
