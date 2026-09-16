<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `PATCH /saya` — nama, telepon, jabatan. Email TIDAK ikut.
 *
 * Email yang bisa diganti sendiri membatalkan seluruh gunanya verifikasi:
 * akun yang sudah disetujui admin buat PT A tinggal ganti email jadi milik
 * orang lain, dan penerima notifikasi sertifikat ikut pindah tanpa ada yang
 * meninjau. Perubahan email jadi urusan admin (M1-05), bukan endpoint ini.
 */
class PerbaruiProfilRequest extends FormRequest
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
            'nama' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'telepon' => ['sometimes', 'required', 'string'],
            'jabatan' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->periksaTelepon($validator, false)];
    }
}
