<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $wajibKalauBikinBaru = $this->isMethod('POST') ? 'required' : 'sometimes';

        // Nama PT itu kunci: folder pelanggan, sertifikat, lembar kerja, dan
        // daftar alat semuanya nempel ke situ, dan yang dipakai orang buat
        // nyari itu namanya. Database udah nahan kembar lewat unique index —
        // ini yang bikin pesannya kebaca manusia, bukan 500 dari driver.
        //
        // `ignore()` biar pelanggan bisa disimpan ulang tanpa ganti nama;
        // tanpa itu, edit alamat doang ketolak gara-gara namanya sendiri.
        $unikPerOrganisasi = Rule::unique('customers', 'nama')
            ->where(
                'organization_id',
                $this->user()?->organization_id
                    ?? $this->route('customer')?->organization_id,
            )
            ->ignore($this->route('customer'));

        return [
            'nama' => [$wajibKalauBikinBaru, 'string', 'max:255', $unikPerOrganisasi],
            'alamat' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama pelanggan wajib diisi.',
            'nama.unique' => 'Pelanggan dengan nama ini sudah ada. '
                .'Nama PT dipakai sebagai kunci folder & berkasnya, jadi nggak '
                .'boleh kembar — buka yang sudah ada, atau bedakan namanya '
                .'(mis. tambah kota atau cabangnya).',
            'email.email' => 'Format email tidak valid.',
        ];
    }
}
