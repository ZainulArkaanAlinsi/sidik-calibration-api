<?php

namespace App\Http\Requests\Admin;

use App\Models\CustomerMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST /api/customers/{customer}/undangan` — REQ-AUTH-06 sisi lab. */
class BuatUndanganRequest extends FormRequest
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
            'peran' => ['required', Rule::in([CustomerMember::PERAN_PIC_UTAMA, CustomerMember::PERAN_STAF])],
        ];
    }
}
