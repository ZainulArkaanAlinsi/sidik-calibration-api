<?php

namespace App\Services\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\PreferensiNotifikasiAnggota;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Saklar notifikasi pelanggan, dan SATU tempat yang menjawab "siapa yang
 * boleh dikabari soal ini".
 *
 * Semua pengirim notifikasi pelanggan (pengingat jatuh tempo, status
 * permintaan, pesan lab) memilih penerimanya lewat `penerima()` — bukan
 * menulis sendiri query anggota. Kalau tiap pengirim menulis query-nya
 * sendiri, satu yang lupa membaca saklar tidak memunculkan error apa pun:
 * orang yang sudah mematikan pengingat tetap dapat pengingat, dan itu persis
 * alasan orang menghapus aplikasinya.
 *
 * Kuncinya anggota (per perusahaan), bukan akun — lihat migrasi tabelnya.
 */
class PreferensiNotifikasi
{
    public const PENGINGAT_JADWAL = 'pengingat_jadwal';

    public const STATUS_PERMINTAAN = 'status_permintaan';

    public const PESAN_LAB = 'pesan_lab';

    public const RINGKASAN_EMAIL_MINGGUAN = 'ringkasan_email_mingguan';

    /**
     * Nilai bagi anggota yang belum pernah menyentuh layarnya. Kabar yang
     * menyangkut pekerjaannya menyala; ringkasan email mati (belum ada
     * pengirimnya, dan email yang tidak diminta itu spam).
     *
     * @var array<string, bool>
     */
    public const BAWAAN = [
        self::PENGINGAT_JADWAL => true,
        self::STATUS_PERMINTAAN => true,
        self::PESAN_LAB => true,
        self::RINGKASAN_EMAIL_MINGGUAN => false,
    ];

    /** @return list<string> */
    public static function kunci(): array
    {
        return array_keys(self::BAWAAN);
    }

    /** @return array<string, bool> */
    public function untuk(CustomerMember $anggota): array
    {
        $baris = PreferensiNotifikasiAnggota::query()
            ->where('customer_member_id', $anggota->getKey())
            ->first();

        if ($baris === null) {
            return self::BAWAAN;
        }

        $hasil = [];

        foreach (self::BAWAAN as $kunci => $bawaan) {
            $hasil[$kunci] = (bool) ($baris->{$kunci} ?? $bawaan);
        }

        return $hasil;
    }

    /**
     * Simpan sebagian saklar; yang tidak disebut tidak berubah.
     *
     * @param  array<string, bool>  $nilai
     * @return array<string, bool>
     */
    public function simpan(CustomerMember $anggota, array $nilai): array
    {
        $baris = PreferensiNotifikasiAnggota::query()->firstOrNew([
            'customer_member_id' => $anggota->getKey(),
        ]);

        // Baris baru dimulai dari bawaan, bukan dari default kolom: supaya
        // "matikan satu saklar" tidak diam-diam menyalakan yang lain kalau
        // bawaan di kode dan default di migrasi suatu hari berbeda.
        if (! $baris->exists) {
            $baris->fill(self::BAWAAN);
        }

        $baris->fill(array_intersect_key($nilai, self::BAWAAN))->save();

        return $this->untuk($anggota);
    }

    /**
     * Akun pelanggan yang aktif, anggota aktif perusahaan ini, DAN saklar
     * [$kunci]-nya menyala.
     *
     * Lewat `customer_members`, bukan `users.customer_id`: satu orang bisa jadi
     * anggota beberapa perusahaan, dan anggota yang sudah dinonaktifkan PIC
     * tidak boleh terus menerima kabar perusahaan yang bukan urusannya lagi.
     *
     * @return Collection<int, User>
     */
    public function penerima(Customer $pelanggan, string $kunci): Collection
    {
        // `$kunci` masuk ke nama kolom di bawah. Dibatasi ke daftar yang dikenal
        // supaya nilai dari luar tidak pernah bisa jadi potongan SQL.
        if (! array_key_exists($kunci, self::BAWAAN)) {
            throw new \InvalidArgumentException("Kunci preferensi `{$kunci}` tidak dikenal.");
        }

        $bawaan = self::BAWAAN[$kunci];

        return User::query()
            ->where('role', User::ROLE_PELANGGAN)
            ->where('status', User::STATUS_AKTIF)
            ->whereExists(function ($q) use ($pelanggan, $kunci, $bawaan): void {
                $q->selectRaw(1)
                    ->from('customer_members')
                    ->whereColumn('customer_members.user_id', 'users.id')
                    ->where('customer_members.customer_id', $pelanggan->id)
                    ->where('customer_members.status', CustomerMember::STATUS_AKTIF)
                    ->where(function ($pref) use ($kunci, $bawaan): void {
                        // Tanpa baris = pakai bawaan. Kolomnya sendiri `NOT NULL`,
                        // jadi cukup dua cabang: ada barisnya, atau tidak ada.
                        $pref->whereExists(fn ($p) => $p->selectRaw(1)
                            ->from('preferensi_notifikasi_anggota')
                            ->whereColumn('preferensi_notifikasi_anggota.customer_member_id', 'customer_members.id')
                            ->where('preferensi_notifikasi_anggota.'.$kunci, true));

                        if ($bawaan) {
                            $pref->orWhereNotExists(fn ($p) => $p->selectRaw(1)
                                ->from('preferensi_notifikasi_anggota')
                                ->whereColumn('preferensi_notifikasi_anggota.customer_member_id', 'customer_members.id'));
                        }
                    });
            })
            ->get();
    }
}
