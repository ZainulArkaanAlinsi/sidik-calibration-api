<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body `POST /api/penugasan`.
 *
 * Dua aturan di sini yang menutup bentuk data yang tidak bisa dibetulkan dari
 * layar, cuma dihapus:
 *
 * 1. `teknisi` minimal satu. Penugasan tanpa anggota tidak sampai ke siapa pun,
 *    dan di papan dia terlihat seperti pekerjaan yang sedang jalan.
 * 2. `item` minimal satu. Penugasan tanpa baris tidak memberi tahu apa yang harus
 *    dikerjakan — teknisi menerima notifikasi berjudul "Kalibrasi minggu ini"
 *    yang isinya kosong.
 */
class PenugasanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = $this->user()->organization_id;

        return [
            'judul' => ['required', 'string', 'max:150'],

            // `after_or_equal:today`, bukan `after:today` — target "hari ini"
            // wajar dan sering dipakai untuk pekerjaan mendesak.
            'tanggal_target' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],

            'catatan' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Yang PERTAMA di daftar jadi ketua — urutannya bermakna, jadi
            // `array` (berurutan), bukan set.
            'teknisi' => ['required', 'array', 'min:1', 'max:20'],
            'teknisi.*' => [
                'integer', 'distinct',
                // Penyaring organisasi + role + status, ketiganya wajib. Tanpa
                // penyaring organisasi, teknisi lab lain bisa ditugaskan dan
                // namanya muncul di papan lab ini — kebocoran dua arah: lab ini
                // melihat nama orang lab lain, dan orang itu menerima notifikasi
                // dari lab yang bukan tempatnya bekerja.
                Rule::exists('users', 'id')
                    ->where('organization_id', $orgId)
                    ->where('role', User::ROLE_TEKNISI)
                    ->where('status', User::STATUS_AKTIF),
            ],

            // Baris "+" di layar.
            'item' => ['required', 'array', 'min:1', 'max:50'],
            'item.*.jenis_alat' => ['required', 'string', 'max:120'],
            'item.*.equipment_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('equipment_categories', 'id')->where('organization_id', $orgId),
            ],
            // Batas 999 bukan angka keramat: dia menahan salah ketik (10 → 100000)
            // yang membuat persentase progres jadi tidak berguna selamanya.
            'item.*.jumlah' => ['required', 'integer', 'min:1', 'max:999'],
            'item.*.order_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('orders', 'id')->where('organization_id', $orgId),
            ],
            'item.*.catatan' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'teknisi.required' => 'Pilih minimal satu teknisi — penugasan tanpa orang nggak sampai ke siapa pun.',
            'teknisi.*.exists' => 'Ada teknisi yang nggak ada di lab ini, bukan teknisi, atau akunnya nggak aktif.',
            'teknisi.*.distinct' => 'Ada teknisi yang kepilih dua kali.',
            'item.required' => 'Tambahin minimal satu baris jenis alat & jumlahnya.',
            'item.*.jenis_alat.required' => 'Jenis alatnya belum diisi di salah satu baris.',
            'item.*.jumlah.max' => 'Jumlahnya kebanyakan — periksa lagi, mungkin salah ketik.',
            'tanggal_target.after_or_equal' => 'Tanggal targetnya sudah lewat.',
        ];
    }
}
