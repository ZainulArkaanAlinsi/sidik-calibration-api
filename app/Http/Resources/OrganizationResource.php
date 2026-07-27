<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'alamat' => $this->alamat,
            'telepon' => $this->telepon,
            'email' => $this->email,
            'no_akreditasi' => $this->no_akreditasi,
            'standar_akreditasi' => $this->standar_akreditasi,
            'akreditasi_mulai' => $this->akreditasi_mulai?->toDateString(),
            'akreditasi_berakhir' => $this->akreditasi_berakhir?->toDateString(),
            'akreditasi_masih_berlaku' => $this->akreditasiMasihBerlaku(),

            // Logo yang dicetak di kop sertifikat. URL absolut & siap dipasang di
            // `Image.network` — mobile nggak nyusun path sendiri, biar nggak ada
            // dua tempat yang harus tahu logonya disimpen di disk mana.
            //
            // `null` artinya org ini belum ngunggah logo. PDF sertifikat TETAP
            // kebuat: dia jatuh ke logo bawaan di `public/images/logo-sidik.png`,
            // dan kalau itu juga nggak ada, kop-nya jadi teks doang. Jadi `null`
            // di sini bukan berarti sertifikatnya bakal tanpa logo.
            'logo_url' => $this->logo_path
                ? Storage::disk('public')->url($this->logo_path)
                : null,

            // Tanda tangan SENGAJA nggak dikasih URL langsung — file-nya di disk
            // PRIVAT, karena gambar tanda tangan yang URL-nya bisa diakses siapa pun
            // berarti siapa pun bisa nempelin ke dokumen palsu. Yang dikirim cuma
            // penandanya; pratinjaunya lewat `GET /organization/tanda-tangan` yang
            // ngecek hak akses.
            'punya_tanda_tangan' => filled($this->tanda_tangan_path),
            'tanda_tangan' => $this->pengaturanTandaTangan(),

            'settings' => $this->settings,
        ];
    }
}
