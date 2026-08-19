<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class OrderRequest extends FormRequest
{
    /**
     * `nomor` sengaja NGGAK ada di sini — dibikin backend (ORD/2026/07/0001),
     * sama kayak nomor sesi & nomor sertifikat. Kalau client boleh ngirim,
     * urutannya bolong dan nomornya gampang dobel.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $bikinBaru = $this->isMethod('POST');
        $wajibKalauBikinBaru = $bikinBaru ? 'required' : 'sometimes';

        return [
            'customer_id' => [
                $wajibKalauBikinBaru, 'integer',
                // Dibatesin ke pelanggan seorganisasi — tanpa ini, order bisa
                // dibikin atas nama pelanggan PT lain modal nebak ID.
                Rule::exists('customers', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'tanggal_masuk' => [$wajibKalauBikinBaru, 'date'],
            'tanggal_janji_selesai' => ['nullable', 'date', 'after_or_equal:tanggal_masuk'],
            'status' => ['sometimes', Rule::in(Order::statuses())],
            'catatan' => ['nullable', 'string', 'max:1000'],

            // Alat yang ikut dianter. Wajib minimal 1 waktu bikin: order tanpa
            // alat nggak ada gunanya, work order-nya kosong.
            'items' => [$wajibKalauBikinBaru, 'array', 'min:1'],
            'items.*.equipment_id' => [
                'required', 'integer',
                Rule::exists('equipments', 'id')->where('organization_id', $organizationId),
            ],
            // Penugasan boleh kosong dulu — alat sering diterima duluan, baru
            // dibagi kerjaannya belakangan pas kepala lab lihat antreannya.
            'items.*.teknisi_id' => ['nullable', 'integer', $this->teknisiValid($organizationId)],
            'items.*.kondisi_terima' => ['nullable', 'string', 'max:1000'],
            'items.*.kelengkapan' => ['nullable', 'string', 'max:255'],
            'items.*.catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Yang ditugaskan harus teknisi seorganisasi yang AKTIF.
     *
     * Tiga saringannya masing-masing nutup lubang: tanpa organisasi, kerjaan
     * bisa dilempar ke orang PT lain; tanpa role, admin/viewer bisa ketugasan
     * kalibrasi; tanpa status, kerjaan nyangkut di akun yang udah dinonaktifin
     * dan nggak pernah ada yang ngerjain.
     */
    private function teknisiValid(int $organizationId): Exists
    {
        return Rule::exists('users', 'id')
            ->where('organization_id', $organizationId)
            ->where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.teknisi_id.exists' => 'Teknisi yang dipilih nggak ketemu, bukan teknisi, atau akunnya nonaktif.',
            'customer_id.required' => 'Pelanggan wajib dipilih.',
            'customer_id.exists' => 'Pelanggan nggak ketemu.',
            'tanggal_masuk.required' => 'Tanggal alat masuk wajib diisi.',
            'tanggal_janji_selesai.after_or_equal' => 'Tanggal janji selesai nggak boleh lebih awal dari tanggal masuk.',
            'items.required' => 'Minimal satu alat harus didaftarin di order ini.',
            'items.min' => 'Minimal satu alat harus didaftarin di order ini.',
            'items.*.equipment_id.exists' => 'Ada alat yang nggak ketemu di daftar alat lab.',
            'status.in' => 'Status order harus salah satu dari: baru, diproses, selesai, dibatalkan.',
        ];
    }
}
