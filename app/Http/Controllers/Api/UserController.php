<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BuatAkunAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PenjagaOrganisasi;
use App\Support\AlasanAksi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Approval akun. Semua endpoint di sini admin-only (dijaga middleware `role:admin`
 * di routes/api.php) — tanpa ini, register jadi jebakan: orang daftar, statusnya
 * pending selamanya, nggak ada yang bisa nyetujuin.
 *
 * Pengecualian (18 Nov 2026): `store`, `nonaktifkan`, dan `aktifkan` SUPER ADMIN
 * saja — grup `role:super_admin` di routes, bukan `role:admin`. Menambah akun
 * admin dan memutus/memulihkan akses akun internal itu hak pengendali, bukan
 * meja depan; kemampuan lama `PUT /users/{user}` (status & role) untuk admin
 * tidak diperluas maupun dicabut.
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Saringan tambahan (18 Nov 2026), aditif — bentuk respons tidak berubah.
        // `role` divalidasi ke `User::roles()`: `super_admin` dan `pelanggan`
        // memang tidak pernah muncul di daftar ini, jadi memintanya 422 (bukan
        // daftar kosong yang kelihatan seperti "tidak ada orangnya").
        $filter = $request->validate([
            'role' => ['sometimes', 'nullable', 'string', Rule::in(User::roles())],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $users = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when(
                filled($filter['role'] ?? null),
                fn ($query) => $query->where('role', $filter['role']),
            )
            ->when(filled($filter['search'] ?? null), function ($query) use ($filter) {
                $cari = '%'.$filter['search'].'%';

                // Kurungnya WAJIB: tanpa pembungkus, `orWhere` naik ke tingkat atas
                // dan menyeret akun lab/role lain yang email-nya kebetulan cocok.
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $cari)
                    ->orWhere('email', 'like', $cari)
                    ->orWhere('employee_id', 'like', $cari));
            })
            // Cuma akun INTERNAL. `organization_id` nggak memisahkan apa-apa di
            // sini: pelanggan PT Sidik memang duduk di organisasi PT Sidik, jadi
            // tanpa saringan ini seluruh akun pelanggan bocor ke daftar pengguna
            // internal — dan dari situ `PUT /users/{id}` tinggal satu langkah.
            // Alasan lengkapnya di [pastikanAkunInternal].
            ->whereIn('role', User::roles())
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->latest('id')
            ->paginate(15);

        return UserResource::collection($users);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $user);

        // Admin yang nentuin role-nya di sini, bukan si pendaftar.
        $validated = $request->validate(
            // `viewer` tidak lagi ditawarkan untuk akun baru (20 Okt 2026).
            ['role' => ['required', Rule::in(User::rolesAkunBaru())]],
            [
                'role.required' => 'Role wajib diisi.',
                'role.in' => 'Role harus salah satu dari: admin, teknisi.',
            ],
        );

        $user->update([
            'role' => $validated['role'],
            'status' => User::STATUS_AKTIF,
        ]);

        return response()->json([
            'message' => 'Akun disetujui.',
            'data' => new UserResource($user),
        ]);
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $user);

        // Menolak = membatalkan PENDAFTARAN yang menunggu. Akun aktif dinonaktifkan
        // lewat `PUT /users/{id}` (yang dijaga admin terakhir); lewat sini dulu
        // mematikannya diam-diam dengan pesan "Pendaftaran ditolak." (BUG-8).
        if ($user->status !== User::STATUS_PENDING) {
            abort(response()->json([
                'message' => 'Akun ini bukan pendaftaran yang menunggu persetujuan. '
                    .'Untuk menonaktifkan akun, ubah statusnya lewat pengaturan akun.',
                'kode' => 'bukan_pending',
            ], 422));
        }

        $user->update(['status' => User::STATUS_NONAKTIF]);

        // Token yang mungkin udah kepegang dicabut, biar penolakannya langsung berlaku.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Pendaftaran ditolak.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Admin benerin data akun — terutama EMAIL yang salah ketik waktu daftar.
     *
     * Ini bukan kemewahan: reset password jalannya lewat email, sementara login
     * pakai ID pegawai. Jadi orang yang salah ketik emailnya waktu daftar bakal
     * kekunci selamanya kalau nggak ada yang bisa benerin.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $user);

        $validated = $request->validate([
            'nama' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'employee_id' => ['sometimes', 'string', 'max:50', Rule::unique('users', 'employee_id')->ignore($user->id)],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            // `viewer` hanya boleh DIPERTAHANKAN pada akun yang sudah viewer;
            // mengubah akun lain MENJADI viewer ditolak (20 Okt 2026).
            'role' => ['sometimes', Rule::in($user->role === User::ROLE_VIEWER ? User::roles() : User::rolesAkunBaru())],
            'status' => ['sometimes', Rule::in([User::STATUS_AKTIF, User::STATUS_PENDING, User::STATUS_NONAKTIF])],
        ], [
            'email.unique' => 'Email ini sudah terdaftar.',
            'employee_id.unique' => 'ID pegawai ini sudah terdaftar.',
        ]);

        if (isset($validated['nama'])) {
            $validated['name'] = $validated['nama'];
            unset($validated['nama']);
        }

        // Transaksi + kunci baris admin: dua admin yang saling menonaktifkan
        // bersamaan tidak boleh sama-sama lolos dan menyisakan nol admin.
        $user->getConnection()->transaction(function () use ($request, $user, $validated): void {
            $this->jagaPenonaktifan($request, $user, $validated['status'] ?? null);
            $user->update($validated);
        });

        // Akun yang dinonaktifkan admin harus langsung kehilangan sesinya.
        if (($validated['status'] ?? null) === User::STATUS_NONAKTIF) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Data akun diperbarui.',
            'data' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Admin nyetel ulang password user, buat kasus yang nggak bisa ditolong
     * `/forgot-password`: emailnya salah ketik, atau emailnya udah nggak bisa
     * diakses. Password barunya dikasih tahu admin ke orangnya langsung.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $user);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        $user->update(['password' => $validated['password']]);

        // Semua sesi lama dicabut — kalau password diganti karena akunnya
        // kebobolan, sesi yang lagi jalan justru yang paling penting dimatiin.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password akun berhasil disetel ulang.']);
    }

    /**
     * `POST /api/users` — super admin menambah akun ADMIN (Master Data).
     *
     * Siapa yang boleh dijaga SATU gerbang: `role:super_admin` di rutenya
     * (`MatriksIzin` membaca gerbang yang sama, jadi `/me/permissions` tidak bisa
     * berbeda). Controller ini sengaja tidak mengulang pemeriksaan peran.
     *
     * Yang dijaga di sini: role dipaksa `admin` (`BuatAkunAdminRequest`),
     * organisasi dari akun yang login (kiriman klien tidak dibaca), status
     * langsung `aktif` (dibuatkan orang yang berwenang, tanpa antrean approval),
     * dan sandinya tidak pernah ikut di balasan. Jejaknya: baris `dibikin` dari
     * `Diaudit` (pelaku = super admin, sandi disensor).
     */
    public function store(BuatAkunAdminRequest $request): JsonResponse
    {
        $data = $request->validated();

        $akun = User::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['nama'],
            'employee_id' => $data['employee_id'],
            'email' => $data['email'],
            'department' => $data['department'] ?? null,
            'password' => $data['password'],
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        return response()->json([
            'message' => 'Akun admin dibuat.',
            'data' => new UserResource($akun),
        ], 201);
    }

    /**
     * `POST /api/users/{user}/nonaktifkan` — super admin menonaktifkan akun internal.
     *
     * Bukan hapus: namanya menempel di sertifikat & audit. Tokennya dicabut di
     * request yang sama (token Sanctum di sini tanpa masa berlaku), dan
     * `EnsureUserHasRole` menolak akun tidak aktif walau ada token yang lolos.
     *
     * Sasarannya tidak pernah akun `super_admin` (`pastikanAkunInternal()`
     * menjawab 404), jadi pemanggil tidak bisa mengunci dirinya sendiri maupun
     * super admin lain lewat sini.
     */
    public function nonaktifkan(Request $request, User $user): JsonResponse
    {
        $this->pastikanBolehDikelola($request, $user);

        $alasan = AlasanAksi::baca($request);

        if ($user->status === User::STATUS_NONAKTIF) {
            return response()->json([
                'kode' => 'sudah_nonaktif',
                'message' => 'Akun ini sudah nonaktif.',
            ], 422);
        }

        $this->ubahStatus($request, $user, User::STATUS_NONAKTIF, AuditLog::ACTION_AKUN_DINONAKTIFKAN, $alasan);

        return response()->json([
            'message' => 'Akun dinonaktifkan. Seluruh sesinya dicabut.',
            'data' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * `POST /api/users/{user}/aktifkan` — super admin memulihkan akun nonaktif.
     *
     * Sandi dan role TIDAK berubah. Akun `pending` ditolak (`perlu_persetujuan`):
     * jalan keluarnya `approve`, yang menetapkan role-nya — mengaktifkan
     * langsung akan memberi akses dengan role bawaan pendaftar.
     */
    public function aktifkan(Request $request, User $user): JsonResponse
    {
        $this->pastikanBolehDikelola($request, $user);

        $alasan = AlasanAksi::baca($request, wajib: false);

        if ($user->status === User::STATUS_AKTIF) {
            return response()->json([
                'kode' => 'sudah_aktif',
                'message' => 'Akun ini sudah aktif.',
            ], 422);
        }

        if ($user->status !== User::STATUS_NONAKTIF) {
            return response()->json([
                'kode' => 'perlu_persetujuan',
                'message' => 'Akun ini belum disetujui. Setujui dulu lewat persetujuan akun supaya perannya ditetapkan.',
            ], 422);
        }

        $this->ubahStatus($request, $user, User::STATUS_AKTIF, AuditLog::ACTION_AKUN_DIAKTIFKAN, $alasan);

        return response()->json([
            'message' => 'Akun diaktifkan kembali.',
            'data' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Penjaga kepemilikan untuk aksi status: lab yang sama + akun internal.
     *
     * Lewat `PenjagaOrganisasi` (bukan `abort_if` polos seperti `pastikanSatuOrganisasi()`
     * di bawah) supaya percobaan menembus lab tercatat di `audit_logs`; jawabannya
     * tetap 404 yang sama.
     */
    private function pastikanBolehDikelola(Request $request, User $user): void
    {
        PenjagaOrganisasi::pastikanSatu($request, $user);

        $this->pastikanAkunInternal($user);
    }

    /**
     * Ubah status + (kalau nonaktif) cabut token + tulis jejak beralasan, SATU transaksi.
     *
     * Dua baris audit, dan itu disengaja: `Diaudit` menulis `diubah` (status lama →
     * baru) dari event model; baris kedua menambahkan yang tidak bisa diturunkan dari
     * diff kolom — peran pelaku dan alasannya. Kalau salah satunya gagal ditulis,
     * statusnya ikut batal (aturan `Diaudit`: perubahan tak tercatat lebih berbahaya
     * daripada perubahan yang gagal).
     */
    private function ubahStatus(Request $request, User $user, string $statusBaru, string $aksi, ?string $alasan): void
    {
        /** @var User $pelaku */
        $pelaku = $request->user();
        $statusLama = $user->status;

        DB::transaction(function () use ($user, $pelaku, $statusLama, $statusBaru, $aksi, $alasan): void {
            $user->forceFill(['status' => $statusBaru])->save();

            if ($statusBaru === User::STATUS_NONAKTIF) {
                $user->tokens()->delete();
            }

            AuditLog::create([
                'organization_id' => $user->organization_id,
                'entity_type' => $user->getTable(),
                'entity_id' => $user->getKey(),
                'action' => $aksi,
                'old_data' => ['status' => $statusLama],
                'new_data' => [
                    'status' => $statusBaru,
                    'role_akun' => $user->role,
                    'peran_pelaku' => $pelaku->role,
                    'alasan' => $alasan,
                ],
                'changed_by' => $pelaku->getKey(),
                // Dipotong ke panjang kolom `note` (500); alasan LENGKAP ada di `new_data`.
                'note' => AuditLog::catatan($alasan === null
                    ? 'Oleh '.$pelaku->role.'.'
                    : 'Oleh '.$pelaku->role.'. Alasan: '.$alasan),
            ]);
        });
    }

    /**
     * Penjaga penonaktifan akun (BUG-8 uji E2E-QA, 18 Nov 2026).
     *
     * Dua hal yang bisa mengunci laboratorium dari aplikasinya sendiri:
     *  - admin menonaktifkan AKUNNYA SENDIRI;
     *  - admin AKTIF TERAKHIR di organisasi dinonaktifkan (oleh siapa pun).
     *
     * Hanya berlaku pada perpindahan dari `aktif` ke status lain; menyetel
     * `nonaktif` pada akun yang memang sudah nonaktif bukan penonaktifan baru.
     * Admin dihitung per organisasi (admin lab lain tidak menolong).
     */
    private function jagaPenonaktifan(Request $request, User $user, ?string $statusBaru): void
    {
        if ($statusBaru === null || $statusBaru === User::STATUS_AKTIF || $user->status !== User::STATUS_AKTIF) {
            return;
        }

        if ($user->is($request->user())) {
            abort(response()->json([
                'message' => 'Kamu tidak bisa menonaktifkan akunmu sendiri. Minta admin lain melakukannya.',
                'kode' => 'tidak_boleh_nonaktifkan_diri_sendiri',
            ], 422));
        }

        if ($user->role !== User::ROLE_ADMIN) {
            return;
        }

        $adminAktifLain = User::query()
            ->where('organization_id', $user->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_AKTIF)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->exists();

        if (! $adminAktifLain) {
            abort(response()->json([
                'message' => 'Ini admin aktif terakhir di laboratorium. Jadikan akun lain admin dulu, '
                    .'baru akun ini dinonaktifkan.',
                'kode' => 'admin_terakhir_aktif',
            ], 422));
        }
    }

    /**
     * Admin cuma boleh ngurus akun di organisasinya sendiri.
     *
     * `role:admin` di routes cuma mastiin yang manggil itu admin — dia nggak
     * peduli admin MANA. Tanpa penjaga ini, admin PT A bisa nyetel ulang
     * password admin PT B cuma modal nebak ID di URL.
     *
     * 404, bukan 403: 403 ngonfirmasi akunnya ada: itu udah bocoran sendiri.
     */
    private function pastikanSatuOrganisasi(Request $request, User $user): void
    {
        abort_if($user->organization_id !== $request->user()->organization_id, 404);

        $this->pastikanAkunInternal($user);
    }

    /**
     * Endpoint `/users` cuma buat akun INTERNAL — bukan pelanggan, bukan
     * super admin.
     *
     * ## Kegagalan yang ditutup
     *
     * `pastikanSatuOrganisasi()` di atas cuma mencocokkan `organization_id`,
     * dan itu tidak memisahkan apa pun: akun pelanggan PT Sidik memang duduk di
     * organisasi PT Sidik. `Rule::in(User::roles())` di [update] & [approve]
     * juga tidak menolong — dia menjaga nilai role yang MASUK, bukan akun yang
     * DITUJU. Jadi sebelum penjaga ini:
     *
     *     PUT  /api/users/{id_pelanggan}          {"role":"admin"}  -> 200
     *     POST /api/users/{id_pelanggan}/approve  {"role":"admin"}  -> 200
     *
     * dua-duanya mengubah akun pelanggan jadi akun lab dengan akses ke seluruh
     * data seluruh pelanggan. Itu risiko R-D02 di docblock `User`, dan jalannya
     * satu request.
     *
     * Sisi panel Filament sudah ditutup lebih dulu lewat
     * `UserResource::getEloquentQuery()`; sisi API-nya belum ikut, dan celah itu
     * yang ditambal di sini. Kedua sisi sekarang memakai daftar yang sama
     * (`User::roles()`), jadi menambah role internal baru cukup di satu tempat.
     *
     * `super_admin` ikut tertolak dengan sendirinya — dia juga bukan anggota
     * `User::roles()`. Perilakunya sejajar dengan panel, yang sudah menolaknya
     * lewat `test_akun_super_admin_juga_tidak_bisa_dibuka`.
     *
     * 404, bukan 403, dengan alasan yang sama seperti penjaga organisasi di
     * atas: 403 mengonfirmasi akunnya ada, dan itu sudah bocoran sendiri.
     */
    private function pastikanAkunInternal(User $user): void
    {
        abort_if(! in_array($user->role, User::roles(), true), 404);
    }
}
