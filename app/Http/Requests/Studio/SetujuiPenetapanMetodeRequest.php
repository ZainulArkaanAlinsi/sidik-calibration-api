<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body `POST /api/studio/parameter-metode/penetapan/{id}/setujui`.
 *
 * `keputusan_oleh` = nama pemutus (Manajer Teknis), BUKAN akun Studio; akun yang
 * menekan tombol dicatat terpisah di jejak. Aturan yang bergantung jam server
 * (tanggal keputusan tidak di depan, penetapan tidak berlaku surut) ditegakkan
 * `PenetapanParameterMetode::setujui()`.
 */
class SetujuiPenetapanMetodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'keputusan_oleh' => ['required', 'string', 'min:3', 'max:150'],
            'tanggal_keputusan' => ['required', 'string', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'keputusan_oleh.required' => 'Nama pemutus wajib diisi.',
            'keputusan_oleh.min' => 'Nama pemutus minimal 3 karakter.',
            'tanggal_keputusan.required' => 'Tanggal keputusan wajib diisi.',
            'tanggal_keputusan.date_format' => 'Tanggal keputusan harus berformat Y-m-d (mis. 2026-12-04).',
        ];
    }
}
