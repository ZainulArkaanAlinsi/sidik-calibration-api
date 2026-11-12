<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parameter TAMBAHAN `GET /calibrations` (Riwayat). Semuanya opsional; tanpa
 * satu pun dari mereka respons dan urutannya sama dengan sebelumnya.
 *
 * `status`, `equipment_id`, `mine`, `ringkas`, `page` sudah ada sejak lama dan
 * tidak divalidasi di sini -- perilakunya sengaja tidak disentuh.
 */
class DaftarSesiRequest extends FormRequest
{
    public const BENTUK_RINGAN = 'ringan';

    public const PER_HALAMAN_BAWAAN = 15;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // `min:2`: parameter ini baru, tidak ada klien lama yang mengirimnya.
            // Pencarian O(N) per lab, jadi satu huruf (yang mencocokkan hampir
            // semuanya) tidak layak dijalankan.
            'q' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],
            'dari' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // `after_or_equal:dari` hanya dipasang bila `dari` diisi: tanpa
            // itu Laravel mencoba membaca kata "dari" sebagai tanggal.
            'sampai' => array_filter([
                'sometimes', 'nullable', 'date_format:Y-m-d',
                $this->filled('dari') ? 'after_or_equal:dari' : null,
            ]),
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'bentuk' => ['sometimes', 'nullable', Rule::in([self::BENTUK_RINGAN])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.min' => 'Kata cari minimal 2 karakter.',
            'dari.date_format' => 'Tanggal `dari` harus berbentuk YYYY-MM-DD.',
            'sampai.date_format' => 'Tanggal `sampai` harus berbentuk YYYY-MM-DD.',
            'sampai.after_or_equal' => 'Tanggal `sampai` tidak boleh sebelum tanggal `dari`.',
            'per_page.max' => 'per_page paling banyak 50.',
            'bentuk.in' => 'bentuk hanya boleh `ringan`.',
        ];
    }

    public function ringan(): bool
    {
        return $this->input('bentuk') === self::BENTUK_RINGAN;
    }

    public function perHalaman(): int
    {
        return (int) ($this->input('per_page') ?: self::PER_HALAMAN_BAWAAN);
    }
}
