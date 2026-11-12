<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /api/me/ganti-sandi` — akun LAB mengganti sandinya sendiri.
 *
 * Aturan sandi baru sengaja SAMA dengan ganti sandi pelanggan
 * (`Pelanggan\AturanPelanggan::aturanSandi()`): minimal 10 karakter dan bukan
 * sandi yang sudah bocor (`uncompromised()`, k-anonymity ke Have I Been Pwned —
 * layanan yang tak terjangkau tidak memblokir, itu perilaku bawaan Laravel).
 * Aturannya ditulis ulang di sini, bukan memakai trait itu: trait itu milik
 * namespace `Pelanggan`, dan dua dunia ini tidak saling meminjam kelas
 * (AGENTS.md §Modul Pelanggan butir 2). Kalau kebijakan sandinya berubah,
 * ubah keduanya.
 *
 * Tambahan khusus lab: `confirmed` (aplikasi lab mengirim
 * `sandi_confirmation`) dan `different:sandi_lama` — "mengganti" ke sandi yang
 * sama mencabut semua sesi lain tanpa mengubah apa pun.
 *
 * Sandi lama TIDAK diperiksa di sini tetapi di controller, supaya salahnya
 * dijawab dengan `kode` yang stabil (`sandi_lama_salah`) seperti jalur
 * pelanggan, bukan galat validasi generik.
 */
class GantiSandiSendiriRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sandi_lama' => ['required', 'string'],
            'sandi' => [
                'required', 'string', 'confirmed', 'different:sandi_lama',
                Password::min(10)->uncompromised(),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sandi_lama.required' => 'Sandi lama wajib diisi.',
            'sandi.required' => 'Sandi baru wajib diisi.',
            'sandi.confirmed' => 'Konfirmasi sandi baru tidak cocok.',
            'sandi.different' => 'Sandi baru tidak boleh sama dengan sandi lama.',
            'sandi.min' => 'Sandi baru minimal 10 karakter.',
            'sandi.password.uncompromised' => 'Sandi ini pernah muncul di kebocoran data. Pilih sandi lain.',
        ];
    }
}
