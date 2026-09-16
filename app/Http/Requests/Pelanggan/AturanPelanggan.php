<?php

namespace App\Http\Requests\Pelanggan;

use App\Support\NomorTelepon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Aturan field yang dipakai lebih dari satu request pelanggan (02-SRS §11).
 *
 * Ditaruh di trait, bukan disalin: aturan sandi muncul di tiga endpoint
 * (daftar, atur ulang, ganti), dan satu salinan yang ketinggalan waktu
 * kebijakannya berubah artinya ada satu pintu yang lebih longgar dari dua
 * lainnya — tanpa satu pun test yang merah.
 */
trait AturanPelanggan
{
    /**
     * Sandi: minimal 10 karakter DAN bukan sandi yang sudah bocor.
     *
     * `uncompromised()` menembak API Have I Been Pwned lewat k-anonymity
     * (cuma 5 karakter pertama hash SHA-1 yang dikirim, bukan sandinya). Di
     * test jaringan itu tidak boleh disentuh — `Password::defaults()` bukan
     * jalannya; yang dipakai `UncompromisedVerifier` palsu lewat container.
     * Lihat `Tests\Concerns\MemalsukanCekSandiBocor`.
     *
     * @return array<int, mixed>
     */
    protected function aturanSandi(): array
    {
        return ['required', 'string', Password::min(10)->uncompromised()];
    }

    /** @return array<int, mixed> */
    protected function aturanEmail(): array
    {
        return ['required', 'string', 'email:filter', 'max:254'];
    }

    /** @return array<int, mixed> */
    protected function aturanOtp(): array
    {
        return ['required', 'string', 'digits:6'];
    }

    /**
     * Email selalu huruf kecil sebelum apa pun menyentuhnya.
     *
     * Bukan kerapian: `users.email` unik, dan MySQL default-nya
     * case-insensitive sementara SQLite tidak. Tanpa penurunan huruf di sini,
     * `Budi@…` dan `budi@…` jadi dua akun di suite SQLite dan satu akun di
     * MySQL — persis kelas beda-driver yang sudah menggigit repo ini empat
     * kali.
     */
    protected function rapikanIdentitas(): void
    {
        $bersih = [];

        if ($this->has('email')) {
            $bersih['email'] = mb_strtolower(trim((string) $this->input('email')));
        }

        // Telepon dinormalisasi DI SINI, jadi yang tersimpan & yang divalidasi
        // sama-sama bentuk `+62…`. Nilai yang tidak terbaca dibiarkan apa
        // adanya supaya aturan `telepon` di bawah yang memunculkan pesannya —
        // kalau diganti null, pesannya jadi "wajib diisi" buat orang yang
        // jelas-jelas sudah mengisi.
        if ($this->filled('telepon')) {
            $bersih['telepon'] = NomorTelepon::normal((string) $this->input('telepon'))
                ?? (string) $this->input('telepon');
        }

        if ($bersih !== []) {
            $this->merge($bersih);
        }
    }

    /** Aturan `telepon` — dipasang lewat `after()` supaya pesannya sendiri. */
    protected function periksaTelepon(Validator $validator, bool $wajib): void
    {
        $nilai = $this->input('telepon');

        if (! $wajib && ($nilai === null || $nilai === '')) {
            return;
        }

        if (! NomorTelepon::sah(is_string($nilai) ? $nilai : null)) {
            $validator->errors()->add(
                'telepon',
                'Nomor HP tidak terbaca. Contoh yang benar: 0812-3456-7890.',
            );
        }
    }

    /** @return array<int, mixed> */
    protected function aturanEmailBelumTerpakai(): array
    {
        return [...$this->aturanEmail(), Rule::unique('users', 'email')];
    }
}
