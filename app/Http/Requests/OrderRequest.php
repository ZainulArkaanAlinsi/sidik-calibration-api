<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'items.*.kondisi_terima' => ['nullable', 'string', 'max:1000'],
            'items.*.kelengkapan' => ['nullable', 'string', 'max:255'],
            'items.*.catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
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
