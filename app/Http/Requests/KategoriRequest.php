<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Body `POST /api/categories` dan `PUT /api/categories/{kode}`.
 *
 * Yang divalidasi di sini hanya BENTUK `nama`. Keunikan nama per organisasi
 * (dan kodenya) diputuskan di `CategoryController` di dalam transaksi yang
 * mengunci baris organisasi, supaya dua permintaan yang berbarengan tidak
 * sama-sama lolos -- tidak ada indeks unik di `nama`, jadi pemeriksaan di
 * request saja akan balapan.
 *
 * `kode` TIDAK diterima dari klien: dia diturunkan dari nama oleh server dan
 * tidak pernah berubah sesudahnya.
 */
class KategoriRequest extends FormRequest
{
    /** Kolom `equipment_categories.nama` itu string(255); 100 menyisakan ruang. */
    public const NAMA_MAKS = 100;

    public const NAMA_MIN = 2;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Spasi ganda/tab/baris baru dilipat jadi satu spasi SEBELUM divalidasi:
     * "Tekanan  Vakum" dan "Tekanan Vakum" adalah nama yang sama di mata
     * manusia, dan keunikan dibandingkan pada bentuk yang sudah dilipat ini.
     */
    protected function prepareForValidation(): void
    {
        $nama = $this->input('nama');

        if (is_string($nama)) {
            $this->merge(['nama' => trim((string) preg_replace('/\s+/u', ' ', $nama))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'min:'.self::NAMA_MIN, 'max:'.self::NAMA_MAKS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama kategori wajib diisi.',
            'nama.min' => 'Nama kategori minimal '.self::NAMA_MIN.' karakter.',
            'nama.max' => 'Nama kategori maksimal '.self::NAMA_MAKS.' karakter.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            // Kode diturunkan dari nama; nama yang isinya cuma simbol
            // menghasilkan kode kosong dan tidak bisa dipakai sebagai kunci.
            function (Validator $validator): void {
                $nama = $this->input('nama');

                if (is_string($nama) && ! $validator->errors()->has('nama') && Str::slug($nama) === '') {
                    $validator->errors()->add('nama', 'Nama kategori harus memuat huruf Latin (boleh beraksen) atau angka — dari situ kode kategorinya dibentuk.');
                }
            },
        ];
    }
}
