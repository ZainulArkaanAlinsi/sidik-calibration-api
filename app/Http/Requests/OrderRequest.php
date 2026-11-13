<?php

namespace App\Http\Requests;

use App\Models\Equipment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

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
     * Alat di order harus milik PELANGGAN order itu (satu organisasi saja tidak cukup).
     *
     * `Rule::exists(...)->where('organization_id')` di atas hanya menjaga batas
     * ANTAR LAB. Dua pelanggan di lab yang sama tetap bisa tercampur: alat PT B
     * masuk order PT A, dan `/paket` PT A lalu menampilkan nama + nomor seri alat
     * PT B (ISO/IEC 17025 klausul 4.2). Tidak ada error di mana pun yang
     * memberitahu — makanya dijaga di sini, di satu pintu tulis order.
     *
     * Aturannya: alat yang `customer_id`-nya TIDAK null dan berbeda dari
     * pelanggan order ditolak (422). Alat tanpa pemilik (null) lolos — keputusan
     * 13 Nov 2026; kolomnya NOT NULL hari ini, jadi cabang itu hanya penjaga
     * kalau kolomnya suatu hari dilonggarkan.
     *
     * Dua pemeriksaan:
     *  1. alat yang DIKIRIM di `items` (buat dan ubah);
     *  2. saat `customer_id` order DIGANTI lewat PUT: alat yang tetap tinggal di
     *     order (semua bila `items` tidak dikirim; yang sudah punya sesi bila
     *     dikirim — lihat `OrderController::gantiItems`). Tanpa ini, mengganti
     *     pelanggan lewat PUT mengulang celah yang sama tanpa menyentuh `items`.
     *
     * Order yang sudah tercampur dari dulu TIDAK memblokir PUT yang tidak
     * menyentuh `customer_id`/`items` (mis. ganti status) — datanya dibereskan
     * lewat kueri hitung di `docs/permintaan-user-7.md` §57, bukan lewat tembok.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Aturan dasar sudah gagal untuk pelanggan/alat: jangan menumpuk pesan
            // dan jangan menebak pelanggan dari id yang tidak valid.
            $sudahGagal = collect($validator->errors()->keys())
                ->contains(fn (string $k): bool => $k === 'customer_id' || str_starts_with($k, 'items'));

            if ($sudahGagal) {
                return;
            }

            $organizationId = $this->user()->organization_id;
            $order = $this->route('order');

            // Order milik lab lain TIDAK dipakai: controller yang menjawab 404,
            // dan 422 di sini akan mengakui bahwa order itu ada.
            $order = $order instanceof Order && (int) $order->organization_id === (int) $organizationId
                ? $order
                : null;

            $customerId = $this->filled('customer_id')
                ? (int) $this->input('customer_id')
                : ($order !== null ? (int) $order->customer_id : null);

            if ($customerId === null) {
                return;
            }

            $this->periksaAlatDikirim($validator, $organizationId, $customerId);

            if ($order !== null && $customerId !== (int) $order->customer_id) {
                $this->periksaAlatYangTinggal($validator, $organizationId, $customerId, $order);
            }
        }];
    }

    private function periksaAlatDikirim(Validator $validator, int $organizationId, int $customerId): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        $idPerBaris = collect($items)->map(fn ($item): int => (int) (is_array($item) ? ($item['equipment_id'] ?? 0) : 0));
        $alat = $this->alatOrganisasi($organizationId, $idPerBaris->all());

        foreach ($idPerBaris as $baris => $equipmentId) {
            $a = $alat->get($equipmentId);

            if ($a !== null && $this->milikPelangganLain($a, $customerId)) {
                $validator->errors()->add("items.{$baris}.equipment_id", $this->pesanAlatPelangganLain($a));
            }
        }
    }

    private function periksaAlatYangTinggal(Validator $validator, int $organizationId, int $customerId, Order $order): void
    {
        $tinggal = $this->has('items')
            ? $order->items()->has('calibrationSessions')->pluck('equipment_id')->all()
            : $order->items()->pluck('equipment_id')->all();

        foreach ($this->alatOrganisasi($organizationId, $tinggal) as $a) {
            if ($this->milikPelangganLain($a, $customerId)) {
                $validator->errors()->add('customer_id', 'Pelanggan order ini nggak bisa diganti. '
                    .$this->pesanAlatPelangganLain($a));

                return;
            }
        }
    }

    /**
     * @param  array<int, int>  $idAlat
     * @return Collection<int, Equipment>
     */
    private function alatOrganisasi(int $organizationId, array $idAlat): Collection
    {
        if ($idAlat === []) {
            return collect();
        }

        return Equipment::query()
            ->withTrashed()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $idAlat)
            ->get(['id', 'nama_alat', 'serial_number', 'customer_id'])
            ->keyBy('id');
    }

    private function milikPelangganLain(Equipment $alat, int $customerId): bool
    {
        return $alat->customer_id !== null && (int) $alat->customer_id !== $customerId;
    }

    private function pesanAlatPelangganLain(Equipment $alat): string
    {
        $seri = filled($alat->serial_number) ? ", No. seri {$alat->serial_number}" : '';

        return "Alat \"{$alat->nama_alat}\"{$seri} terdaftar atas nama pelanggan lain, jadi nggak bisa masuk order "
            .'pelanggan ini. Pilih alat milik pelanggan order ini, atau perbaiki dulu pemilik alatnya di data alat.';
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
