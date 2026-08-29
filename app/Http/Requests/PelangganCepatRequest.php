<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pendaftaran pelanggan CEPAT dari lapangan — nama & alamat doang.
 *
 * Sengaja bukan [CustomerRequest]. Yang itu buat layar CRUD admin dan menerima
 * `contact_person`, `telepon`, `email`; di sini ketiganya nggak diterima sama
 * sekali. Teknisi yang lagi berdiri di gerbang pabrik nggak punya data itu, dan
 * kolom yang ada di form pasti ada yang mengisinya dengan tebakan.
 *
 * Yang juga nggak diterima: `sumber` dan `organization_id`. Dua-duanya diisi
 * server. `sumber` yang paling penting — dia yang bikin baris hasil ketikan
 * lapangan bisa dibedakan dari baris yang diperiksa admin, dan kalau boleh
 * datang dari klien, satu `{"sumber":"admin"}` dari HP cukup buat menghapus
 * bedanya selamanya.
 *
 * Unique `nama` juga sengaja NGGAK dipasang di sini. Bukan karena boleh kembar,
 * tapi karena penolakan 422 yang cuma bilang "sudah ada" bikin teknisi mengarang
 * nama pembeda (`PT Maju Jaya 2`) supaya bisa lanjut. Controller yang menangani:
 * dia memulangkan KANDIDAT yang mirip, biar yang dipilih perusahaan yang sudah
 * ada — bukan baris baru dengan nama karangan.
 */
class PelangganCepatRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:255'],

            // Id tempat dari direktori luar, kalau barisnya dipilih dari hasil
            // pencarian direktori dan bukan diketik tangan.
            'direktori_ref' => ['nullable', 'string', 'max:255'],

            // "Iya, saya sudah lihat kandidatnya, ini perusahaan lain."
            // Cuma menembus kemiripan; nama yang PERSIS sama tetap ditolak,
            // karena unique index di database yang menahannya, bukan aturan ini.
            'tetap_buat' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama PT wajib diisi.',
        ];
    }
}
