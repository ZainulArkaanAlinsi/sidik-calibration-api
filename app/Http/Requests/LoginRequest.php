<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * `identifier` bisa ID pegawai (ASM-0001) atau email — teknisi di lapangan
     * hafalnya nomor pegawai, bukan email.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Locale app-nya `id` tapi Laravel nggak bawa file lang Indonesia,
     * jadi pesannya ditulis manual — mobile nampilin ini apa adanya ke user.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.required' => 'ID pegawai atau email wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ];
    }
}
