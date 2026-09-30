<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body `POST /api/calibrations/{calibration}/sahkan`.
 *
 * Semua field OPSIONAL. Yang wajar terjadi di lapangan: super admin membuka
 * antrean, membaca, mencet "Sahkan & terbitkan" tanpa mengubah apa pun — dan
 * nilai yang dipakai adalah yang dititipkan admin waktu mengajukan. Mewajibkan
 * penandatangan atau masa berlaku di sini berarti pengesah harus mengetik ulang
 * keputusan yang sudah diambil orang lain, dan yang terjadi berikutnya adalah
 * dia mengetik apa saja supaya tombolnya nyala.
 */
class SahkanSertifikatRequest extends FormRequest
{
    /**
     * Izin dijaga `role:super_admin` di rutenya, bukan di sini.
     *
     * Dua tempat yang menjawab pertanyaan izin yang sama adalah cara paling
     * rapi untuk punya satu yang basi. `MatriksIzin` menurunkan jawabannya dari
     * middleware rute, jadi kalau `authorize()` di sini ikut memutuskan,
     * `/me/izin` bisa bilang "boleh" untuk sesuatu yang ditolak 403.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sesi = $this->route('calibration');

        return [
            // `after:tanggal kalibrasi`, bukan `after:today` — sertifikat yang
            // berlakunya habis sebelum atau pas hari alatnya dikerjakan itu
            // nggak masuk akal. Aturan & pesannya disamakan dengan approve();
            // batas 10 tahun menahan salah ketik tahun (2035 → 2350).
            'berlaku_sampai' => [
                'sometimes', 'nullable', 'date',
                'after:'.($sesi?->tanggal_kalibrasi?->toDateString() ?? 'today'),
                'before_or_equal:'.now()->addYears(10)->toDateString(),
            ],

            // Penandatangan harus orang di LAB YANG SAMA dan berstatus aktif.
            // Tanpa penyaring organisasi, id dari lab lain lolos dan namanya
            // tercetak di sertifikat berlogo akreditasi lab ini — kebocoran yang
            // bentuknya paling permanen, karena kertasnya sudah keluar.
            'penandatangan_user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where('organization_id', $this->user()->organization_id)
                    ->where('status', User::STATUS_AKTIF),
            ],

            // Mengakui peringatan pemisahan wewenang. Nama flag-nya disamakan
            // dengan approve() — satu istilah untuk satu maksud.
            'abaikan_peringatan' => ['sometimes', 'boolean'],

            'catatan' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'berlaku_sampai.after' => 'Masa berlaku harus sesudah tanggal kalibrasi.',
            'berlaku_sampai.before_or_equal' => 'Masa berlaku kejauhan — maksimal 10 tahun dari sekarang.',
            'penandatangan_user_id.exists' => 'Penandatangan yang dipilih nggak ada di lab ini, atau akunnya nggak aktif.',
        ];
    }
}
