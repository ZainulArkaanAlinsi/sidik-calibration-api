<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Ada akun baru daftar & nunggu disetujui admin (fase-2 §2).
 *
 * Tanpa ini, `POST /register` jadi jebakan: orang daftar, kejebak di layar "akun
 * belum disetujui", dan **nggak ada satu pun admin yang dikabarin**. Satu-satunya
 * cara ketahuan itu kalau ada admin yang kebetulan buka
 * `GET /users?status=pending` — jadi lamanya orang nunggu itu soal keberuntungan,
 * bukan soal alur.
 *
 * Satu notifikasi per pendaftar, sengaja nggak diringkas: yang perlu ditindak
 * admin itu orangnya satu-satu (disetujui atau ditolak), dan rata-rata lab nggak
 * dapat pendaftar banyak dalam sehari.
 */
class AkunBaruMenunggu extends NotifikasiSistem
{
    public function __construct(
        private readonly int $userId,
        private readonly string $nama,
        private readonly string $employeeId,
        private readonly ?string $department = null,
    ) {}

    public static function dariUser(User $user): self
    {
        return new self(
            $user->id,
            (string) $user->name,
            (string) $user->employee_id,
            $user->department,
        );
    }

    protected function judul(): string
    {
        return 'Akun baru menunggu persetujuan';
    }

    protected function isi(): string
    {
        // Departemen ikut kalau ada — itu petunjuk pertama admin buat mutusin
        // orangnya beneran pegawai lab atau bukan.
        return implode(' · ', array_filter([
            $this->nama,
            $this->employeeId,
            $this->department,
        ]));
    }

    protected function kategori(): string
    {
        return 'akun.menunggu_persetujuan';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        // Mobile udah punya layar approval yang narik `?status=pending`, jadi
        // `filter`-nya dibawa biar tap-nya langsung mendarat di sana.
        return [
            'tipe' => 'users',
            'filter' => 'pending',
            'id' => $this->userId,
        ];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-user-plus';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
