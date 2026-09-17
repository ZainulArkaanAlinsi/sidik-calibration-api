<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** REQ-AUTH-01 — daftar perusahaan baru. */
class DaftarRequest extends FormRequest
{
    use AturanPelanggan;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->rapikanIdentitas();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'min:2', 'max:100'],
            'email' => $this->aturanEmailBelumTerpakai(),
            'sandi' => $this->aturanSandi(),
            'telepon' => ['required', 'string'],
            'jabatan' => ['required', 'string', 'max:100'],
            'nama_perusahaan' => ['required', 'string', 'min:3', 'max:150'],
            'alamat_perusahaan' => ['nullable', 'string', 'max:255'],
            // Persetujuan privasi & syarat (S05 langkah 1). WAJIB, dan
            // `accepted` bukan `boolean`: `false` yang lolos validasi lalu
            // dicatat sebagai baris `persetujuan_dokumen` itu catatan
            // persetujuan yang bohong — lebih buruk daripada tidak mencatat.
            'setuju_syarat' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->periksaTelepon($validator, true)];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // Pesan bawaan buat `unique` berbunyi "email sudah digunakan", dan
            // itu membocorkan email mana yang punya akun di sini ke siapa pun
            // yang mau menyisir. Diganti kalimat yang tidak menjawab itu, tapi
            // tetap menolong orang yang memang pemilik emailnya.
            'email.unique' => 'Email ini tidak bisa dipakai mendaftar. Kalau ini email Anda, coba masuk atau pakai "Lupa sandi".',
            'nama_perusahaan.min' => 'Nama perusahaan minimal 3 karakter.',
            'setuju_syarat.accepted' => 'Anda harus menyetujui kebijakan privasi dan syarat & ketentuan.',
        ];
    }
}
