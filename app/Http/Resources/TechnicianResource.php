<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sengaja dipisah dari UserResource walaupun sumbernya tabel yang sama.
 * UserResource bentuknya dikunci kontrak API buat layar akun; yang ini punya
 * layar master data teknisi dan bawa `jumlah_kalibrasi`. Digabung, nambah field
 * di sini bakal ikut ngubah payload /me & /users tanpa sengaja.
 *
 * @mixin User
 */
class TechnicianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->name,
            'employee_id' => $this->employee_id,
            'email' => $this->email,
            'department' => $this->department,
            'status' => $this->status,
            // Dipakai layar master data buat nunjukkin kenapa teknisi tertentu
            // nggak bisa dihapus.
            'jumlah_kalibrasi' => $this->whenCounted('calibrationSessions'),
        ];
    }
}
