<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris "+" di layar penugasan: jenis alat & jumlahnya.
 *
 * Pakai `Diaudit` karena `jumlah_selesai` adalah angka yang DILAPORKAN orang dan
 * tidak bisa dihitung ulang dari mana pun (lihat docblock migrasinya). Angka
 * seperti itu tanpa jejak siapa-mengubah-jadi-berapa berhenti bisa dipercaya
 * begitu ada yang menanyakannya.
 */
class PenugasanItem extends Model
{
    use Diaudit, HasFactory;

    protected $table = 'penugasan_item';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'jumlah_selesai' => 'integer',
        ];
    }

    public function penugasan(): BelongsTo
    {
        return $this->belongsTo(Penugasan::class);
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * `Diaudit` menyaring barisnya per organisasi, dan tabel ini TIDAK punya
     * kolom `organization_id` — dia mewarisinya dari penugasannya.
     *
     * Tanpa override ini, `catatAudit()` diam-diam tidak mencatat apa pun:
     * `organisasiUntukAudit()` memulangkan null, dan trait-nya memang berhenti
     * daripada menulis baris yang tidak bisa di-scope. Jadi kegagalannya senyap
     * — angka progres berubah tanpa jejak, dan yang menemukannya baru orang yang
     * mencari jejak itu berbulan-bulan kemudian.
     */
    protected function organisasiUntukAudit(): ?int
    {
        return $this->penugasan?->organization_id
            ?? Penugasan::withTrashed()->whereKey($this->penugasan_id)->value('organization_id');
    }
}
