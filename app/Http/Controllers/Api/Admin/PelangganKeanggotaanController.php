<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BuatUndanganRequest;
use App\Http\Resources\Admin\AnggotaPelangganLabResource;
use App\Mail\Pelanggan\UndanganEmail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Services\Pelanggan\Keanggotaan;
use App\Services\Pelanggan\KodeUndangan;
use App\Services\PenjagaOrganisasi;
use App\Support\AlasanAksi;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sisi LAB dari keanggotaan pelanggan: undangan & penunjukan PIC admin
 * (03-SDD §7.3).
 *
 * Admin lab bisa mengundang anggota tanpa menunggu perusahaannya punya PIC
 * utama — dan itu bukan kemewahan: perusahaan yang PIC utamanya keluar kerja
 * tidak punya siapa pun yang bisa mengundang penggantinya, jadi tanpa jalur ini
 * satu-satunya jalan keluar mengutak-atik database.
 */
class PelangganKeanggotaanController extends Controller
{
    public function __construct(
        private readonly KodeUndangan $kodeUndangan,
        private readonly Keanggotaan $keanggotaan,
    ) {}

    /**
     * `GET /api/customers/{customer}/anggota` — daftar anggota + undangan
     * menunggu untuk layar admin lab (kontrak: `docs/perintah-frontend-super-admin.md` §3.2).
     *
     * `GET /customers/{customer}` tidak memuat anggota maupun undangan, dan
     * `GET /pelanggan/v1/anggota` itu pintu PELANGGAN (perusahaannya dari
     * `Konteks`, staf melihat versi sempit) — admin lab butuh pintu sendiri.
     * Bentuknya resource sendiri (`AnggotaPelangganLabResource`), tidak memakai
     * ulang resource dunia pelanggan.
     *
     * Kode undangan TIDAK pernah ada di sini: DB hanya menyimpan hash-nya, dan
     * kode polos muncul sekali di balasan `POST …/undangan`.
     */
    public function anggota(Request $request, Customer $customer): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $customer);

        $organisasi = (int) $request->user()->organization_id;

        $anggota = CustomerMember::query()
            ->with('user')
            ->where('organization_id', $organisasi)
            ->where('customer_id', $customer->getKey())
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [CustomerMember::STATUS_AKTIF])
            ->orderBy('id')
            ->get();

        $picUtamaAktif = $this->hitungPicUtamaAktif($anggota);
        $penonaktif = $this->namaPenonaktif($anggota, $organisasi);

        $undangan = UndanganPelanggan::query()
            ->with('pembuat')
            ->where('organization_id', $organisasi)
            ->where('customer_id', $customer->getKey())
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->where('kedaluwarsa_pada', '>', now())
            ->orderByDesc('id')
            ->get();

        $picAdmin = $customer->picAdmin;

        return response()->json([
            'data' => [
                'customer' => ['id' => $customer->getKey(), 'nama' => $customer->nama],
                'maks_anggota' => (int) ($customer->maks_anggota ?: 0),
                'jumlah_aktif' => $anggota->where('status', CustomerMember::STATUS_AKTIF)->count(),
                'pic_admin' => $picAdmin === null || (int) $picAdmin->organization_id !== $organisasi
                    ? null
                    : ['id' => $picAdmin->getKey(), 'nama' => $picAdmin->name, 'email' => $picAdmin->email],
                'anggota' => $anggota
                    ->map(fn (CustomerMember $satu) => (new AnggotaPelangganLabResource($satu, $picUtamaAktif, $penonaktif))
                        ->resolve($request))
                    ->values()
                    ->all(),
                'undangan_menunggu' => $undangan->map(fn (UndanganPelanggan $satu) => [
                    'id' => $satu->getKey(),
                    'email' => $satu->email,
                    'peran' => $satu->peran,
                    'kedaluwarsa_pada' => $satu->kedaluwarsa_pada?->toIso8601String(),
                    'dibuat_pada' => $satu->created_at?->toIso8601String(),
                    'diundang_oleh' => $satu->pembuat === null
                        ? null
                        : ['id' => $satu->pembuat->getKey(), 'nama' => $satu->pembuat->name],
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * `POST /api/customers/{customer}/anggota/{anggota}/nonaktifkan` — admin lab
     * / super admin mencabut akses satu anggota perusahaan pelanggan.
     *
     * Aturannya TIDAK ditulis di sini: `Keanggotaan::nonaktifkan()` yang
     * menegakkan "PIC utama terakhir tidak boleh hilang" (REQ-ANG-02) dan
     * "cabut token + perangkat" (REQ-AUTH-09) — satu pintu bersama jalur PIC
     * utama, supaya dua jalur tidak bisa berbeda. Yang ditambahkan di sini
     * cuma yang khas sisi lab: penjaga kepemilikan 404, `alasan` wajib, dan
     * satu baris audit yang menyebut siapa (dengan peran apa) dan kenapa.
     *
     * Baris `diubah` otomatis dari `Diaudit` (status lama → baru) tetap ditulis
     * model; baris di sini melengkapinya dengan alasan, dan keduanya dalam satu
     * transaksi dengan pencabutannya — audit gagal berarti pencabutan gagal.
     */
    public function cabutAnggota(Request $request, Customer $customer, CustomerMember $anggota): JsonResponse
    {
        PenjagaOrganisasi::pastikanSatu($request, $customer);
        PenjagaOrganisasi::pastikanSatu($request, $anggota);
        abort_unless((int) $anggota->customer_id === (int) $customer->getKey(), 404);

        // Setelah penjaga kepemilikan, bukan sebelum — lihat docblock `AlasanAksi`.
        $alasan = AlasanAksi::baca($request);

        if ($anggota->status !== CustomerMember::STATUS_AKTIF) {
            return response()->json([
                'kode' => 'sudah_nonaktif',
                'message' => 'Anggota ini sudah nonaktif.',
            ], 422);
        }

        /** @var User $pelaku */
        $pelaku = $request->user();

        try {
            DB::transaction(function () use ($anggota, $pelaku, $alasan): void {
                $this->keanggotaan->nonaktifkan($anggota, $pelaku);

                AuditLog::create([
                    'organization_id' => $anggota->organization_id,
                    'entity_type' => $anggota->getTable(),
                    'entity_id' => $anggota->getKey(),
                    'action' => AuditLog::ACTION_ANGGOTA_DICABUT,
                    'old_data' => ['status' => CustomerMember::STATUS_AKTIF],
                    'new_data' => [
                        'status' => CustomerMember::STATUS_NONAKTIF,
                        'customer_id' => $anggota->customer_id,
                        'user_id' => $anggota->user_id,
                        'peran_anggota' => $anggota->peran,
                        'peran_pelaku' => $pelaku->role,
                        'alasan' => $alasan,
                    ],
                    'changed_by' => $pelaku->getKey(),
                    'note' => 'Dicabut oleh '.$pelaku->role.' lab. Alasan: '.$alasan,
                ]);
            });
        } catch (AksiPelangganDitolak $e) {
            // Kode yang sama, kalimat untuk admin lab (bukan "Anda satu-satunya…").
            throw $e->kode === 'pic_utama_terakhir' ? AksiPelangganDitolak::picUtamaTerakhirOlehLab() : $e;
        }

        $semua = CustomerMember::query()
            ->where('organization_id', $pelaku->organization_id)
            ->where('customer_id', $customer->getKey())
            ->get();

        return response()->json([
            'message' => 'Anggota dinonaktifkan. Seluruh sesinya dicabut.',
            'data' => (new AnggotaPelangganLabResource(
                $anggota->fresh()->load('user'),
                $this->hitungPicUtamaAktif($semua),
                $this->namaPenonaktif($semua, (int) $pelaku->organization_id),
            ))->resolve($request),
        ]);
    }

    /** `POST /api/customers/{customer}/undangan` — REQ-AUTH-06. */
    public function undang(BuatUndanganRequest $request, Customer $customer): JsonResponse
    {
        $this->pastikanSeorganisasi($request, $customer);

        // Batasnya diperiksa SEKARANG walau penegakan sebenarnya waktu ditukar.
        // Tanpa ini, admin mengirim undangan ke lima orang, kelimanya menerima
        // email, dan yang kelima kena tolak — kegagalan yang muncul di HP orang
        // lain, bukan di layar yang menyebabkannya.
        $this->keanggotaan->pastikanMuat($customer);

        $this->pastikanBelumJadiAnggota($customer, $request->string('email')->toString());

        $hasil = $this->kodeUndangan->terbitkan(
            $customer,
            $request->string('email')->toString(),
            $request->string('peran')->toString(),
            $request->user(),
        );

        $emailTerkirim = UndanganEmail::kirim($hasil['undangan'], $hasil['kode'], $customer);

        // Kode polos ikut DIBALAS, sekali ini saja (aplikasi lab tidak punya
        // layar notifikasi seperti panel, dan `kirim()` menelan galat email).
        // Dia tidak disimpan di mana pun selain email: DB menyimpan hash, dan
        // `ringkas()` yang dipakai tempat lain sengaja tidak memuatnya.
        return response()->json([
            'message' => 'Undangan dibuat untuk '.$hasil['undangan']->email
                .'. Kirim kodenya langsung bila email tidak sampai.',
            'data' => [
                ...$this->ringkas($hasil['undangan']),
                'kode' => $hasil['kode'],
                // Nilai yang sama dengan `kedaluwarsa_pada` (nama itu tetap, klien lama
                // membacanya); ini nama yang dipakai layar "berlaku sampai".
                'berlaku_sampai' => $hasil['undangan']->kedaluwarsa_pada?->toIso8601String(),
                'email_terkirim' => $emailTerkirim,
            ],
        ], 201);
    }

    /** `DELETE /api/customers/{customer}/undangan/{undangan}`. */
    public function batalkanUndangan(Request $request, Customer $customer, UndanganPelanggan $undangan): JsonResponse
    {
        $this->pastikanSeorganisasi($request, $customer);
        abort_unless((int) $undangan->customer_id === (int) $customer->getKey(), 404);

        // Dibatalkan, bukan dihapus: baris undangan itu jejak "siapa mengundang
        // siapa, kapan" yang dipakai waktu ada pertanyaan kenapa orang tertentu
        // punya akses. Menghapusnya menghilangkan jejak itu.
        $undangan->forceFill(['dibatalkan_pada' => now()])->save();

        return response()->json(['message' => 'Undangan dibatalkan.']);
    }

    /**
     * `PATCH /api/customers/{customer}/pic-admin` — siapa di LAB yang memegang
     * pelanggan ini.
     *
     * Beda dari `pic_utama` yang ada di sisi pelanggan: yang ini orang lab.
     * Dipakai buat mengarahkan notifikasi pengajuan & verifikasi alat ke satu
     * admin tertentu, bukan ke semua admin — supaya notifikasi yang ditujukan
     * ke semua orang tidak jadi notifikasi yang tidak ditindak siapa pun.
     */
    public function picAdmin(Request $request, Customer $customer): JsonResponse
    {
        $this->pastikanSeorganisasi($request, $customer);

        $data = $request->validate([
            'user_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($kueri) => $kueri
                    ->where('organization_id', $request->user()->organization_id)
                    ->where('role', User::ROLE_ADMIN)
                    ->where('status', User::STATUS_AKTIF)),
            ],
        ], [
            'user_id.exists' => 'PIC admin harus admin lab yang masih aktif.',
        ]);

        $customer->forceFill(['pic_admin_id' => $data['user_id']])->save();

        return response()->json([
            'message' => $data['user_id'] === null
                ? 'PIC admin dilepas. Notifikasi kembali ke semua admin.'
                : 'PIC admin diperbarui.',
            'data' => ['customer_id' => $customer->getKey(), 'pic_admin_id' => $customer->pic_admin_id],
        ]);
    }

    /**
     * Orang yang SUDAH jadi anggota aktif tidak diundang lagi.
     *
     * Kalau dibiarkan, dia dapat email undangan buat perusahaan yang sudah dia
     * buka tiap hari — dan yang dia lakukan berikutnya bertanya ke lab apakah
     * akunnya bermasalah.
     */
    private function pastikanBelumJadiAnggota(Customer $customer, string $email): void
    {
        $sudah = $customer->members()
            ->whereHas('user', fn ($kueri) => $kueri->where('email', $email))
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->exists();

        if ($sudah) {
            throw ValidationException::withMessages([
                'email' => 'Email ini sudah jadi anggota aktif perusahaan tersebut.',
            ]);
        }
    }

    /**
     * Jumlah PIC utama AKTIF di antara anggota satu perusahaan.
     *
     * @param  Collection<int, CustomerMember>  $anggota  semua baris SATU perusahaan
     */
    private function hitungPicUtamaAktif(Collection $anggota): int
    {
        return $anggota
            ->filter(fn (CustomerMember $satu) => $satu->status === CustomerMember::STATUS_AKTIF && $satu->adalahPicUtama())
            ->count();
    }

    /**
     * Nama akun yang menonaktifkan, untuk `dinonaktifkan_oleh`. Disaring ke
     * organisasi pemanggil: id akun yang bukan di lab ini tidak diterjemahkan
     * jadi nama (jawabannya `null`).
     *
     * @param  Collection<int, CustomerMember>  $anggota
     * @return array<int, string> id akun → nama
     */
    private function namaPenonaktif(Collection $anggota, int $organisasi): array
    {
        $id = $anggota->pluck('dinonaktifkan_oleh')->filter()->unique()->values()->all();

        if ($id === []) {
            return [];
        }

        return User::query()
            ->where('organization_id', $organisasi)
            ->whereIn('id', $id)
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function ringkas(UndanganPelanggan $undangan): array
    {
        return [
            'id' => $undangan->getKey(),
            'email' => $undangan->email,
            'peran' => $undangan->peran,
            'kedaluwarsa_pada' => $undangan->kedaluwarsa_pada?->toIso8601String(),
        ];
    }

    private function pastikanSeorganisasi(Request $request, Customer $customer): void
    {
        abort_unless(
            (int) $customer->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
