<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoomRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $room = $this->route('room');
        $wajibKalauBikinBaru = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'kode' => [
                $wajibKalauBikinBaru, 'string', 'max:50',
                // Unik per organisasi, bukan global — dua lab boleh sama-sama
                // punya "R-01". `whereNull(deleted_at)` biar kode ruangan yang
                // udah dihapus bisa dipakai lagi.
                Rule::unique('rooms', 'kode')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->ignore($room?->id),
            ],
            'nama' => [$wajibKalauBikinBaru, 'string', 'max:255'],
            'lokasi' => ['nullable', 'string', 'max:255'],

            'suhu_min' => ['nullable', 'numeric'],
            // Batas atas dibandingin ke batas bawah cuma kalau bawahnya ikut
            // dikirim. Di PATCH yang cuma ngubah satu sisi, `gte:suhu_min` bakal
            // ngadu ke field yang nggak ada dan lolos diam-diam — itu ditangkap
            // `withValidator()` di bawah pakai nilai yang tersimpan.
            'suhu_max' => ['nullable', 'numeric'],
            'kelembaban_min' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'kelembaban_max' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'keterangan' => ['nullable', 'string', 'max:1000'],
            'aktif' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Rentang kebalik (max < min) itu syarat ruangan yang nggak mungkin dipenuhi
     * siapa pun — kalau lolos, tiap sesi di ruangan itu bakal ketulis melanggar
     * syarat selamanya. Nilai yang nggak dikirim diambil dari data tersimpan,
     * biar update sebagian tetap kejaga.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $room = $this->route('room');

            foreach ([['suhu_min', 'suhu_max', 'Suhu'], ['kelembaban_min', 'kelembaban_max', 'Kelembaban']] as [$kunciMin, $kunciMax, $label]) {
                $min = $this->has($kunciMin) ? $this->input($kunciMin) : $room?->{$kunciMin};
                $max = $this->has($kunciMax) ? $this->input($kunciMax) : $room?->{$kunciMax};

                if ($min !== null && $max !== null && (float) $max < (float) $min) {
                    $validator->errors()->add($kunciMax, "{$label} maksimum nggak boleh lebih kecil dari minimum.");
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kode.required' => 'Kode ruangan wajib diisi.',
            'kode.unique' => 'Kode ruangan ini sudah dipakai.',
            'nama.required' => 'Nama ruangan wajib diisi.',
            'kelembaban_min.max' => 'Kelembaban relatif itu persen — maksimal 100.',
            'kelembaban_max.max' => 'Kelembaban relatif itu persen — maksimal 100.',
        ];
    }
}
