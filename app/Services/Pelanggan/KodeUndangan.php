<?php

namespace App\Services\Pelanggan;

use App\Models\Customer;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Terbit & tukar kode undangan anggota (REQ-AUTH-06, REQ-ANG-01).
 *
 * ## Kodenya dicari lewat EMAIL, bukan lewat hash
 *
 * `kode_hash` itu bcrypt — bergaram, jadi kode yang sama menghasilkan hash
 * berbeda tiap kali dan `where('kode_hash', ...)` tidak akan pernah cocok.
 * Jalannya: ambil undangan yang MASIH HIDUP buat email itu, lalu `Hash::check`
 * satu per satu. Jumlahnya terbatas dengan sendirinya — yang sudah dipakai,
 * dibatalkan, atau kedaluwarsa tidak ikut terambil.
 *
 * ## Abjad kodenya sengaja dipangkas
 *
 * Orang mengetik ulang kode ini dari email, sering di HP. `0`/`O` dan `1`/`I`/`l`
 * dibuang seluruhnya — bukan "dimaafkan waktu dicocokkan", karena memaafkan
 * berarti ruang tebakannya mengecil tanpa ada yang sadar. Sisa 30 simbol × 8
 * posisi ≈ 39 bit, dan itu dipagari masa berlaku 7 hari + terikat satu email.
 */
class KodeUndangan
{
    private const ABJAD = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const PANJANG = 8;

    /**
     * @return array{undangan: UndanganPelanggan, kode: string}
     */
    public function terbitkan(
        Customer $perusahaan,
        string $email,
        string $peran,
        ?User $oleh = null,
    ): array {
        $email = mb_strtolower(trim($email));

        // Undangan lama buat email + perusahaan yang sama dibatalkan, bukan
        // dibiarkan hidup berdampingan. Dua kode aktif berarti kode yang sudah
        // terlanjur terkirim (mis. ke alamat yang salah ketik lalu dibetulkan)
        // masih membuka keanggotaan.
        UndanganPelanggan::query()
            ->where('customer_id', $perusahaan->getKey())
            ->where('email', $email)
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->update(['dibatalkan_pada' => now()]);

        $kode = $this->acak();

        $undangan = new UndanganPelanggan;
        $undangan->organization_id = $perusahaan->organization_id;
        $undangan->customer_id = $perusahaan->getKey();
        $undangan->email = $email;
        $undangan->kode_hash = Hash::make($kode);
        $undangan->peran = $peran;
        $undangan->dibuat_oleh = $oleh?->getKey();
        $undangan->kedaluwarsa_pada = now()->addDays(UndanganPelanggan::BERLAKU_HARI);
        $undangan->save();

        return ['undangan' => $undangan, 'kode' => $kode];
    }

    /**
     * Undangan yang cocok buat pasangan email + kode, atau `null`.
     *
     * `null` sengaja tidak membedakan "kode salah", "sudah dipakai", dan
     * "kedaluwarsa": pemanggil membalas satu pesan yang sama buat ketiganya,
     * jadi kode ini tidak bisa dipakai menyisir undangan yang pernah ada.
     */
    public function tukar(string $email, string $kode): ?UndanganPelanggan
    {
        $kandidat = UndanganPelanggan::query()
            ->with('customer')
            ->where('email', mb_strtolower(trim($email)))
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->where('kedaluwarsa_pada', '>', now())
            ->orderByDesc('id')
            ->get();

        foreach ($kandidat as $undangan) {
            if (Hash::check($kode, (string) $undangan->kode_hash)) {
                return $undangan;
            }
        }

        return null;
    }

    private function acak(): string
    {
        $kode = '';
        $batas = strlen(self::ABJAD) - 1;

        for ($i = 0; $i < self::PANJANG; $i++) {
            $kode .= self::ABJAD[random_int(0, $batas)];
        }

        return $kode;
    }
}
