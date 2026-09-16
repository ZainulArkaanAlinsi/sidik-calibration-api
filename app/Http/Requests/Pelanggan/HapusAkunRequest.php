<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-AUTH-11 — `DELETE /saya`, sandi diketik ulang.
 *
 * Aturan kekuatan sandi SENGAJA tidak dipasang di sini: yang diketik sandi LAMA,
 * dan akun yang dibuat sebelum kebijakan panjangnya naik akan ditolak validasi
 * sebelum sandinya sempat diadu — orangnya dapat "sandi minimal 10 karakter"
 * waktu mau menghapus akun, yang tidak berarti apa-apa buat dia.
 */
class HapusAkunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sandi' => ['required', 'string'],
            // Konfirmasi kedua, di luar sandi. Menghapus akun tidak bisa
            // dibatalkan, dan satu tap yang salah di layar HP tidak boleh cukup.
            'konfirmasi' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'konfirmasi.accepted' => 'Centang konfirmasi dulu — penghapusan akun tidak bisa dibatalkan.',
        ];
    }
}
