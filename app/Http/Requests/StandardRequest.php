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

            // Persamaan suhu buffer dari sertifikat Merck: y = a·x² + b·x + c.
            // Ketiganya wajib bareng — `Standard::nilaiPadaSuhu()` balik null
            // kalau salah satunya ilang, jadi separuh terisi sama aja kayak
            // nggak diisi, cuma bikin admin ngira udah beres.
            'koefisien_suhu' => ['sometimes', 'nullable', 'array:a,b,c'],
            'koefisien_suhu.a' => ['required_with:koefisien_suhu', 'numeric'],
            'koefisien_suhu.b' => ['required_with:koefisien_suhu', 'numeric'],
            'koefisien_suhu.c' => ['required_with:koefisien_suhu', 'numeric'],

            // Data sertifikat thermohygro, satu blok per parameter. Dua-duanya
            // opsional sendiri-sendiri: ada unit yang cuma ngukur suhu.
            'parameter_kondisi' => ['sometimes', 'nullable', 'array:suhu,kelembaban'],
            ...$this->aturanParameterKondisi('suhu'),
            ...$this->aturanParameterKondisi('kelembaban'),
        ];
    }

    /**
     * Satu blok `parameter_kondisi`: nilai terindeks, koreksi, dan U95%-nya.
     *
     * `correction` sengaja BOLEH negatif — koreksi thermohygro nyaris selalu
     * negatif (lihat TH-1..TH-7 di lembar olah data lab). Yang dijaga cuma
     * U95%, karena ketidakpastian negatif nggak ada artinya.
     *
     * @return array<string, array<int, mixed>>
     */
    private function aturanParameterKondisi(string $parameter): array
    {
        return [
            "parameter_kondisi.$parameter" => ['sometimes', 'nullable', 'array:indexed_value,correction,u95'],
            "parameter_kondisi.$parameter.indexed_value" => ['nullable', 'numeric'],
            "parameter_kondisi.$parameter.correction" => ['nullable', 'numeric'],
            "parameter_kondisi.$parameter.u95" => ['nullable', 'numeric', 'min:0'],
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
            'koefisien_suhu.a.required_with' => 'Koefisien a, b, dan c harus diisi bertiga — persamaan suhunya nggak kepakai kalau kurang satu.',
            'koefisien_suhu.b.required_with' => 'Koefisien a, b, dan c harus diisi bertiga — persamaan suhunya nggak kepakai kalau kurang satu.',
            'koefisien_suhu.c.required_with' => 'Koefisien a, b, dan c harus diisi bertiga — persamaan suhunya nggak kepakai kalau kurang satu.',
            'parameter_kondisi.suhu.u95.min' => 'U95% suhu nggak boleh negatif.',
            'parameter_kondisi.kelembaban.u95.min' => 'U95% kelembaban nggak boleh negatif.',
        ];
    }
}
