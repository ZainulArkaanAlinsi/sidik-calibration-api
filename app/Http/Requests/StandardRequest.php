<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StandardRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $standard = $this->route('standard');
        $wajibKalauBikinBaru = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nama' => [$wajibKalauBikinBaru, 'string', 'max:255'],
            'merk' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => [
                'nullable', 'string', 'max:100',
                // Unik per organisasi, bukan global — dua PT boleh punya serial sama.
                Rule::unique('standards', 'serial_number')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->ignore($standard?->id),
            ],

            // Nilai sertifikat standar + suhu acuannya. Nggak divalidasi
            // rentangnya: 4.01 masuk akal buat buffer pH, 0.0004 buat gauge
            // block — nggak ada batas yang bener buat semua jenis standar.
            'nilai_konvensional' => ['nullable', 'numeric'],
            'suhu_referensi' => ['nullable', 'numeric'],

            'no_sertifikat' => ['nullable', 'string', 'max:100'],
            'tertelusur_ke' => ['nullable', 'string', 'max:255'],
            'berlaku_sampai' => ['nullable', 'date'],

            'ketidakpastian' => ['nullable', 'numeric', 'min:0'],
            'satuan_ketidakpastian' => ['nullable', 'string', 'max:50'],
            // Faktor cakupan dipakai sebagai PEMBAGI waktu ngubah ketidakpastian
            // diperluas jadi baku. Nol bikin pembagian nol; di bawah 1 nggak ada
            // artinya secara metrologi (k itu pengali, minimal 1).
            'faktor_cakupan' => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'drift' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama standar wajib diisi.',
            'serial_number.unique' => 'Nomor seri sudah dipakai standar lain.',
            'faktor_cakupan.min' => 'Faktor cakupan (k) minimal 1 — biasanya 2. Nilai di bawah itu nggak ada artinya.',
            'ketidakpastian.min' => 'Ketidakpastian nggak boleh negatif.',
        ];
    }
}
