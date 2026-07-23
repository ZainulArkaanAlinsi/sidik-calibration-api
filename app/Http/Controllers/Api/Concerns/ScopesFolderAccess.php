<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\FolderFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Aturan siapa boleh lihat apa di Folder Manager.
 *
 * Folder Manager ada di navbar bawah — artinya teknisi ikut buka. Tapi isinya
 * seluruh arsip sertifikat SEMUA pelanggan lab, dan aturan yang udah dipakai di
 * daftar sesi & sertifikat itu jelas: teknisi cuma lihat pekerjaannya sendiri.
 * Kalau di sini dilonggarin, penyaringan di dua layar sebelumnya jadi nggak ada
 * artinya — tinggal buka Folder Manager buat ngintip kerjaan orang.
 *
 * Jadi: admin & viewer lihat semuanya; teknisi lihat file dari sesinya sendiri
 * plus file yang dia unggah, dan folder yang isinya kosong buat dia nggak
 * ditampilin sama sekali (bukan ditampilin kosong — itu cuma bikin bingung).
 */
trait ScopesFolderAccess
{
    /** @return Builder<FolderFile> */
    protected function queryFileYangBolehDilihat(Request $request): Builder
    {
        $user = $request->user();

        return FolderFile::query()
            ->where('organization_id', $user->organization_id)
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn (Builder $query) => $query->where(
                    fn (Builder $q) => $q
                        ->whereHas(
                            'certificate.session',
                            fn (Builder $s) => $s->where('teknisi_id', $user->id),
                        )
                        ->orWhere('uploaded_by', $user->id),
                ),
            );
    }

    protected function teknisiBiasa(Request $request): bool
    {
        return $request->user()->role === User::ROLE_TEKNISI;
    }
}
