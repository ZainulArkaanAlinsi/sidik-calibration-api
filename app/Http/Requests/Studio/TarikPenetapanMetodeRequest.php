<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body `POST /api/studio/parameter-metode/penetapan/{id}/tarik`.
 *
 * Alasan wajib (min. 10 karakter, sama dengan tolak di alur Data Acuan): siapa,
 * kapan, dan kenapa sebuah penetapan ditarik harus terbaca di jejak.
 */
class TarikPenetapanMetodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'alasan.required' => 'Alasan menarik penetapan wajib diisi.',
            'alasan.min' => 'Alasan minimal 10 karakter.',
        ];
    }
}
