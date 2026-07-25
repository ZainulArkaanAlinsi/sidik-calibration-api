<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            // Wajib: token reset itu nempel ke email, jadi Laravel butuh ini buat
            // nyocokin. Mobile udah punya nilainya — link reset di email bentuknya
            // `sidik://reset-password?token=...&email=...`, tinggal dibaca dari situ.
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            // Opsional: kontrak mobile cuma ngirim `password`. Kalau field konfirmasi
            // ikut dikirim, tetap dicek — kalau nggak, ya udah.
            'password_confirmation' => ['sometimes', 'same:password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Token reset wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password_confirmation.same' => 'Konfirmasi password nggak sama.',
        ];
    }
}
