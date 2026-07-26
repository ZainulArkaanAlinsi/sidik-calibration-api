<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Siapa yang dikabarin — satu tempat, dipakai semua notifikasi.
 *
 * Permintaannya: *"kalo ada sesuatu dan yang dibutuhkan sama admin maka semuanya
 * dikirim ke bagian admin"* (fase-2 §2). Kalau tiap pemicu nulis query admin-nya
 * sendiri, gampang ada satu yang lupa nyaring `status = aktif` — dan notifikasi
 * yang dikirim ke admin nonaktif itu notifikasi yang nggak pernah dibaca siapa
 * pun, tanpa ada yang sadar.
 */
class PenerimaNotifikasi
{
    /**
     * Admin AKTIF di satu organisasi.
     *
     * `pending`/`nonaktif` dikecualikan: mereka nggak bisa login, jadi
     * notifikasinya cuma numpuk di tabel. Yang paling penting, akun `pending`
     * nggak boleh dikabarin soal pendaftar lain.
     *
     * @return Collection<int, User>
     */
    public function adminAktif(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_AKTIF)
            ->get();
    }

    /** @return Collection<int, User> */
    public function adminAktifOrganisasi(Organization $org): Collection
    {
        return $this->adminAktif($org->id);
    }
}
