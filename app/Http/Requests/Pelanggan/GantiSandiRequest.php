<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /saya/ganti-sandi` — REQ-AUTH-09, mencabut token lain. */
class GantiSandiRequest extends FormRequest
{
    use AturanPelanggan;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sandi_lama' => ['required', 'string'],
            'sandi' => $this->aturanSandi(),
        ];
    }
}
