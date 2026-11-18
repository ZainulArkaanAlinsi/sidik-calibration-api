<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/users` — super admin menambah akun ADMIN (Master Data).
 *
 * `role` dipaksa `admin`, bukan daftar pilihan, dan itu sengaja:
 *
 * - Daftar pilihannya SATU nilai literal, bukan `User::roles()` /
 *   `rolesAkunBaru()`. Menggantungkannya ke daftar bersama berarti role baru
 *   yang kelak masuk daftar itu otomatis bisa dicetak dari endpoint ini.
 * - `super_admin` tidak pernah lahir lewat API (hanya `php artisan
 *   akun:super-admin`), `viewer` tidak ditawarkan untuk akun baru (§53), dan
 *   `pelanggan` lahir dari undangan. Teknisi punya pintunya sendiri
 *   (`POST /api/technicians`).
 *
 * `organization_id` tidak ada di aturan: dia diisi controller dari akun yang
 * login, dan kiriman klien tidak pernah dibaca.
 */
class BuatAkunAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in([User::ROLE_ADMIN])],
            'nama' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('users', 'employee_id')],
            'department' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.required' => 'Peran akun wajib diisi.',
            'role.in' => 'Dari sini hanya akun Master Data (admin) yang bisa dibuat. '
                .'Akun teknisi dibuat lewat menu teknisi.',
            'nama.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah terdaftar.',
            'employee_id.required' => 'ID pegawai wajib diisi.',
            'employee_id.unique' => 'ID pegawai ini sudah terdaftar.',
            'password.required' => 'Password awal wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
        ];
    }
}
