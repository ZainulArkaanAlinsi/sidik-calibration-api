<?php

namespace App\Http\Requests\Admin;

use App\Models\PengajuanAkunPelanggan;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/admin/pengajuan-akun/{id}/tolak` — REQ-AUTH-05. */
class TolakPengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Minimal 10 karakter, dan angkanya dari REQ-AUTH-05. Alasan yang
            // boleh sependek "tidak" sampai ke pemohon apa adanya, dan yang dia
            // lakukan berikutnya menelepon lab — biaya yang dipindahkan dari
            // admin ke orang lain, bukan dihilangkan.
            'alasan' => ['required', 'string', 'min:'.PengajuanAkunPelanggan::MIN_ALASAN_TOLAK, 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'alasan.min' => 'Alasan penolakan minimal '.PengajuanAkunPelanggan::MIN_ALASAN_TOLAK
                .' karakter — pemohon membacanya apa adanya.',
        ];
    }
}
