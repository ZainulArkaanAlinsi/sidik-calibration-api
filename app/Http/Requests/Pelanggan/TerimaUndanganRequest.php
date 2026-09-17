<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** REQ-AUTH-06 — `POST /auth/terima-undangan`. */
class TerimaUndanganRequest extends FormRequest
{
    use AturanPelanggan;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->rapikanIdentitas();

        // Kode diketik ulang orang dari email, sering di HP: spasi dan huruf
        // kecil dirapikan di sini. Yang TIDAK dimaafkan `O`/`0` dan `I`/`1` —
        // abjad kodenya memang tidak memuat keduanya (lihat `KodeUndangan`),
        // dan memaafkannya berarti mengecilkan ruang tebakan diam-diam.
        if ($this->has('kode')) {
            $this->merge(['kode' => mb_strtoupper(preg_replace('/\s+/', '', (string) $this->input('kode')) ?? '')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => $this->aturanEmail(),
            'kode' => ['required', 'string', 'min:6', 'max:16'],
            'nama' => ['required', 'string', 'min:2', 'max:100'],
            'sandi' => $this->aturanSandi(),
            'telepon' => ['required', 'string'],
            'jabatan' => ['nullable', 'string', 'max:100'],
            'nama_perangkat' => ['nullable', 'string', 'max:120'],
            'setuju_syarat' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->periksaTelepon($validator, true)];
    }
}
