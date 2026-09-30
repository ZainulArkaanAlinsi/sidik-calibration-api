<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saklar notifikasi satu anggota di satu perusahaan. Lihat `PreferensiNotifikasi`. */
class PreferensiNotifikasiAnggota extends Model
{
    protected $table = 'preferensi_notifikasi_anggota';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pengingat_jadwal' => 'boolean',
            'status_permintaan' => 'boolean',
            'pesan_lab' => 'boolean',
            'ringkasan_email_mingguan' => 'boolean',
        ];
    }

    /** @return BelongsTo<CustomerMember, $this> */
    public function anggota(): BelongsTo
    {
        return $this->belongsTo(CustomerMember::class, 'customer_member_id');
    }
}
