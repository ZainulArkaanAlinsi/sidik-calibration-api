<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;

/** Atur ulang sandi dengan OTP yang dikirim ke email (§7.1). */
class AturUlangSandiRequest extends FormRequest
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
            'sandi' => $this->aturanSandi(),
        ];
    }
}
