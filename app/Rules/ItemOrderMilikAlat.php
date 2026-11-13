<?php

namespace App\Rules;

use App\Models\Equipment;
use App\Models\OrderItem;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `order_item_id` yang DIKIRIM klien saat membuat sesi harus benar-benar item order
 * untuk ALAT sesi itu, di organisasi yang sama, dan di order milik pelanggan alat itu.
 *
 * Tanpa ini klien bisa menautkan sesi ke item order pelanggan lain (atau alat lain):
 * tahap, kabar tahap, dan penjaga order lab semuanya mengikuti tautan itu. Order
 * milik lab lain / dihapus lunak diperlakukan sama dengan "tidak ada" -- pesannya
 * tidak membedakan, supaya id tebakan tidak mengakui keberadaan baris.
 */
class ItemOrderMilikAlat implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly int $organizationId) {}

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $equipmentId = (int) ($this->data['equipment_id'] ?? 0);

        $sah = $equipmentId > 0 && OrderItem::query()
            ->whereKey($value)
            ->where('equipment_id', $equipmentId)
            ->whereHas('order', fn ($order) => $order
                ->where('organization_id', $this->organizationId)
                ->whereIn('customer_id', Equipment::query()
                    ->where('organization_id', $this->organizationId)
                    ->whereKey($equipmentId)
                    ->select('customer_id')))
            ->exists();

        if (! $sah) {
            $fail('Item order ini bukan milik alat tersebut (atau tidak ada di order pelanggan alat ini).');
        }
    }
}
