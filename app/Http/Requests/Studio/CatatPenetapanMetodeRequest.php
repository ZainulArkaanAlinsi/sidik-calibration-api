<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body `POST /api/studio/parameter-metode/{kunci}/penetapan`.
 *
 * Yang divalidasi di sini BENTUK. Aturan D3 yang bergantung pada isi DB dan jam
 * server (nilai salah satu pilihan parameter itu, `berlaku_mulai` tidak berlaku
 * surut dan tidak turun menurut nomor) ditegakkan `PenetapanParameterMetode` di
 * dalam transaksi, supaya dua permintaan serentak tidak sama-sama lolos.
 * Gerbang akses = middleware `studio` di rute; `authorize()` tidak mengulanginya.
 */
class CatatPenetapanMetodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'nilai' => ['required', 'string', 'max:80'],
            'berlaku_mulai' => ['required', 'string', 'date_format:Y-m-d'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nilai.required' => 'Nilai (kode pilihan) wajib diisi.',
            'berlaku_mulai.required' => 'Tanggal mulai berlaku wajib diisi.',
            'berlaku_mulai.date_format' => 'Tanggal mulai berlaku harus berformat Y-m-d (mis. 2026-12-08).',
            'catatan.max' => 'Catatan maksimal 2000 karakter.',
        ];
    }
}
