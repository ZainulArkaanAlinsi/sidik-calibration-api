<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bikin & ganti nama folder arsip.
 *
 * Yang divalidasi di sini cuma bentuk datanya. Aturan yang butuh tahu isi
 * pohon — folder akar nggak boleh diapa-apain, folder nggak boleh masuk ke
 * anaknya sendiri, batas kedalaman — ditaruh di controller, karena butuh
 * model yang udah keresolve plus pesan error yang beda-beda.
 */
class FolderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $wajibSaatBikin = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nama' => [$wajibSaatBikin, 'string', 'max:120'],

            // Cuma dipakai waktu bikin. Null = folder akar perusahaan — tapi
            // akar dibikin sistem, jadi controller nolak parent_id kosong.
            'parent_id' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                'integer',
                Rule::exists('folders', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama folder wajib diisi.',
            'nama.max' => 'Nama folder maksimal 120 karakter.',
            'parent_id.required' => 'Folder induk wajib dipilih.',
            'parent_id.exists' => 'Folder induk nggak ketemu.',
            'parent_id.prohibited' => 'Buat mindahin folder, pakai endpoint /pindah.',
        ];
    }
}
