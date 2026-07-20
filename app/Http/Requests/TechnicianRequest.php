<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechnicianRequest extends FormRequest
{
    /**
     * `role` sengaja NGGAK ada di sini. Endpoint ini khusus master data teknisi —
     * role-nya dipaksa `teknisi` di controller. Kalau bisa dikirim dari client,
     * layar "tambah teknisi" berubah jadi jalan pintas bikin akun admin.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $teknisi = $this->route('technician');
        $bikinBaru = $this->isMethod('POST');
        $wajibKalauBikinBaru = $bikinBaru ? 'required' : 'sometimes';

        return [
            'nama' => [$wajibKalauBikinBaru, 'string', 'max:255'],
            'employee_id' => [
                $wajibKalauBikinBaru, 'string', 'max:50',
                Rule::unique('users', 'employee_id')->ignore($teknisi?->id),
            ],
            'email' => [
                $wajibKalauBikinBaru, 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($teknisi?->id),
            ],
            'department' => ['nullable', 'string', 'max:255'],
            // Password cuma wajib waktu bikin akun baru. Ganti password teknisi
            // yang udah ada lewat /users/{user}/reset-password — biar aksi
            // sensitif itu nggak nempel diam-diam di form edit biasa.
            'password' => [$bikinBaru ? 'required' : 'prohibited', 'string', 'min:8'],
            'status' => ['sometimes', Rule::in([User::STATUS_AKTIF, User::STATUS_NONAKTIF])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama teknisi wajib diisi.',
            'employee_id.required' => 'ID pegawai wajib diisi.',
            'employee_id.unique' => 'ID pegawai ini sudah terdaftar.',
            'email.required' => 'Email wajib diisi.',
            'email.unique' => 'Email ini sudah terdaftar.',
            'password.required' => 'Password awal wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.prohibited' => 'Ganti password lewat endpoint reset password, bukan dari sini.',
            'status.in' => 'Status teknisi cuma boleh aktif atau nonaktif.',
        ];
    }
}
