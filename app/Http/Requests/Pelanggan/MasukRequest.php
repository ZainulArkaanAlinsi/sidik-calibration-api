<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-AUTH-07/08/10 — masuk dari aplikasi pelanggan. */
class MasukRequest extends FormRequest
{
    use AturanPelanggan;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->rapikanIdentitas();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => $this->aturanEmail(),
            // SENGAJA cuma `required|string`: aturan kekuatan sandi tidak
            // dipasang di pintu masuk. Kalau dipasang, akun lama yang sandinya
            // lebih pendek dari kebijakan baru ditolak di VALIDASI — orangnya
            // dapat 422 "sandi minimal 10 karakter" waktu mau masuk, bukan
            // pesan yang menolongnya.
            'sandi' => ['required', 'string'],
            'nama_perangkat' => ['nullable', 'string', 'max:120'],
        ];
    }
}
