<?php

namespace App\Http\Requests\Admin;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /api/admin/pengajuan-akun/{id}/setujui` — REQ-AUTH-04.
 *
 * Admin WAJIB memilih salah satu: pelanggan yang sudah ada, atau membuat yang
 * baru. Tidak ada jalan ketiga, dan itu inti R-D02: nama perusahaan yang
 * diketik pendaftar cuma KLAIM sampai ada manusia yang menautkannya. Kalau
 * kedua field boleh kosong, "setujui" jadi tombol yang menerima klaim apa
 * adanya.
 */
class SetujuiPengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'nullable',
                'integer',
                // Disaring ke organisasi pemanggil, bukan `exists:customers,id`
                // polos: tanpa itu admin satu lab bisa menautkan pendaftar ke
                // pelanggan milik lab lain hanya dengan menebak ID.
                Rule::exists('customers', 'id')->where(
                    fn ($kueri) => $kueri->where('organization_id', $this->user()?->organization_id),
                ),
            ],
            'pelanggan_baru' => ['nullable', 'array'],
            'pelanggan_baru.nama' => [
                'required_with:pelanggan_baru',
                'string',
                'max:255',
                Rule::unique('customers', 'nama')->where(
                    fn ($kueri) => $kueri->where('organization_id', $this->user()?->organization_id),
                ),
            ],
            'pelanggan_baru.alamat' => ['nullable', 'string', 'max:255'],
            'pelanggan_baru.contact_person' => ['nullable', 'string', 'max:255'],
            'pelanggan_baru.telepon' => ['nullable', 'string', 'max:50'],
            'pelanggan_baru.email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $pilihLama = $this->filled('customer_id');
            $pilihBaru = $this->filled('pelanggan_baru');

            if ($pilihLama === $pilihBaru) {
                $validator->errors()->add('customer_id', $pilihLama
                    ? 'Pilih pelanggan yang sudah ada ATAU buat baru, jangan dua-duanya.'
                    : 'Tautkan ke pelanggan yang sudah ada, atau isi data pelanggan baru.');
            }
        }];
    }

    public function pelangganTerpilih(): ?Customer
    {
        $id = $this->integer('customer_id');

        return $id > 0 ? Customer::query()->find($id) : null;
    }

    /** @return array<string, mixed>|null */
    public function dataPelangganBaru(): ?array
    {
        /** @var array<string, mixed>|null $data */
        $data = $this->validated()['pelanggan_baru'] ?? null;

        return $data;
    }
}
