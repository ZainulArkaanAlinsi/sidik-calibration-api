<?php

namespace App\Http\Requests;

use App\Rules\AngkaTerhingga;
use App\Services\KonflikDataTerhapus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoomRequest extends FormRequest
{
    /** Nilai mutlak terbesar kolom `rooms.suhu_min/suhu_max` (`decimal(8,2)`). */
    private const BATAS_SUHU = 999999.99;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $room = $this->route('room');
        $wajibKalauBikinBaru = $this->isMethod('POST') ? 'required' : 'sometimes';

        // Suhu: kolom `decimal(8,2)` (maks ±999.999,99); kelembaban sudah 0..100.
        // Keduanya dijaga dari INF/NAN — `1e400` dulu 500 "Value INF does not
        // represent a valid number", `1e300` 500 SQLSTATE 22003 (R2-8, QA ronde 2).
        return AngkaTerhingga::pasangDiSemua([
            'kode' => [
                $wajibKalauBikinBaru, 'string', 'max:50',
                // Unik per organisasi, bukan global — dua lab boleh sama-sama
                // punya "R-01". Yang masih HIDUP ditolak di sini; kode yang
                // dipegang baris TERHAPUS ditolak di `withValidator()` dengan
                // pesan sendiri, karena indeks unik di database tetap melihat
                // baris terhapus (BUG-5 uji E2E-QA, 18 Nov 2026).
                Rule::unique('rooms', 'kode')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->ignore($room?->id),
            ],
            'nama' => [$wajibKalauBikinBaru, 'string', 'max:255'],
            'lokasi' => ['nullable', 'string', 'max:255'],

            'suhu_min' => ['nullable', 'numeric', 'between:-'.self::BATAS_SUHU.','.self::BATAS_SUHU],
            // Batas atas dibandingin ke batas bawah cuma kalau bawahnya ikut
            // dikirim. Di PATCH yang cuma ngubah satu sisi, `gte:suhu_min` bakal
            // ngadu ke field yang nggak ada dan lolos diam-diam — itu ditangkap
            // `withValidator()` di bawah pakai nilai yang tersimpan.
            'suhu_max' => ['nullable', 'numeric', 'between:-'.self::BATAS_SUHU.','.self::BATAS_SUHU],
            'kelembaban_min' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'kelembaban_max' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'keterangan' => ['nullable', 'string', 'max:1000'],
            'aktif' => ['sometimes', 'boolean'],
        ]);
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

            if (! $validator->errors()->has('kode') && is_string($this->input('kode'))) {
                KonflikDataTerhapus::untukRuangan()->periksa(
                    $this->user()->organization_id,
                    ['kode' => $this->input('kode')],
                    $room?->id,
                );
            }

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
