<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;

/** `kirim-ulang-otp` & `lupa-sandi` — dua-duanya cuma butuh email. */
class EmailSajaRequest extends FormRequest
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
        return ['email' => $this->aturanEmail()];
    }
}
