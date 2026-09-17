<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-AUTH-02 — tukar OTP dengan status `pending_verifikasi`. */
class VerifikasiEmailRequest extends FormRequest
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
            'otp' => $this->aturanOtp(),
        ];
    }
}
