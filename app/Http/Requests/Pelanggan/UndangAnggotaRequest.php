<?php

namespace App\Http\Requests\Pelanggan;

use App\Models\CustomerMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** REQ-ANG-01 — PIC utama mengundang anggota. */
class UndangAnggotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:filter', 'max:254'],
            // PIC utama BOLEH mengangkat PIC utama lain — REQ-ANG-01 menyebut
            // kedua peran. Tanpa itu, perusahaan yang PIC-nya mau keluar kerja
            // tidak punya cara menyerahkan tugasnya tanpa menelepon lab.
            'peran' => ['required', Rule::in([CustomerMember::PERAN_PIC_UTAMA, CustomerMember::PERAN_STAF])],
        ];
    }
}
