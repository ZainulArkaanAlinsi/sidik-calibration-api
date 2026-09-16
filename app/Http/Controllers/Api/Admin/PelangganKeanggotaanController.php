<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BuatUndanganRequest;
use App\Mail\Pelanggan\UndanganEmail;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Services\Pelanggan\Keanggotaan;
use App\Services\Pelanggan\KodeUndangan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        UndanganEmail::kirim($hasil['undangan'], $hasil['kode'], $customer);

        return response()->json([
            'message' => 'Undangan dikirim ke '.$hasil['undangan']->email.'.',
            'data' => $this->ringkas($hasil['undangan']),
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
