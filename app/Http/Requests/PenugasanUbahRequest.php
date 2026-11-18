<?php

namespace App\Http\Requests;

use App\Models\Penugasan;
use App\Models\User;
use App\Services\PenjagaOrganisasi;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body `PUT /api/penugasan/{penugasan}`.
 *
 * Aturannya sama dengan `PenugasanRequest` (membuat), dengan tiga bedanya:
 *
 * 1. `item.*.id` -- baris yang sudah ada; HARUS milik penugasan ini.
 * 2. `tanggal_target` yang SAMA dengan yang tersimpan lolos walau sudah lewat
 *    (mengedit penugasan yang terlambat tidak boleh memaksa tanggal baru);
 *    tanggal yang BERUBAH wajib hari ini atau sesudahnya.
 * 3. Kategori NONAKTIF ditolak untuk baris baru, tetapi baris lama yang sudah
 *    memakainya boleh tetap di sana.
 *
 * ## Urutan yang disengaja: 404 sebelum 422
 *
 * `authorize()` memeriksa kepemilikan organisasi LEBIH DULU dan melempar 404.
 * Kalau tidak, penugasan lab lain menjawab 422 (validasi) sementara yang tidak
 * ada menjawab 404 -- dan beda itu membocorkan keberadaannya
 * (AGENTS.md butir 4 Modul Pelanggan, alasan yang sama untuk lab internal).
 */
class PenugasanUbahRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Penugasan $penugasan */
        $penugasan = $this->route('penugasan');

        PenjagaOrganisasi::pastikanSatu($this, $penugasan);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Penugasan $penugasan */
        $penugasan = $this->route('penugasan');
        $orgId = $this->user()->organization_id;

        $tanggalLama = $penugasan->tanggal_target?->toDateString();
        $kategoriLama = $penugasan->item()->pluck('equipment_category_id')->filter()->map(fn ($id): int => (int) $id)->all();

        return [
            'judul' => ['required', 'string', 'max:150'],

            'tanggal_target' => [
                'sometimes', 'nullable', 'bail', 'date',
                function (string $atribut, mixed $nilai, \Closure $gagal) use ($tanggalLama): void {
                    if ($nilai === null || $nilai === '') {
                        return;
                    }

                    $baru = Carbon::parse((string) $nilai)->toDateString();

                    if ($baru !== $tanggalLama && $baru < Carbon::today()->toDateString()) {
                        $gagal('Tanggal targetnya sudah lewat.');
                    }
                },
            ],

            'catatan' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'teknisi' => ['required', 'array', 'min:1', 'max:20'],
            'teknisi.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')
                    ->where('organization_id', $orgId)
                    ->where('role', User::ROLE_TEKNISI)
                    ->where('status', User::STATUS_AKTIF),
            ],

            'item' => ['required', 'array', 'min:1', 'max:50'],
            'item.*.id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('penugasan_item', 'id')->where('penugasan_id', $penugasan->id),
            ],
            'item.*.jenis_alat' => ['required', 'string', 'max:120'],
            'item.*.equipment_category_id' => [
                'sometimes', 'nullable', 'integer',
                // Aktif ATAU sudah dipakai baris penugasan ini sebelumnya.
                Rule::exists('equipment_categories', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->where(function ($kueri) use ($kategoriLama): void {
                        $kueri->whereNull('nonaktif_pada');

                        if ($kategoriLama !== []) {
                            $kueri->orWhereIn('id', $kategoriLama);
                        }
                    }),
            ],
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
            'item.*.id.exists' => 'Baris itu bukan bagian dari penugasan ini.',
            'item.*.jenis_alat.required' => 'Jenis alatnya belum diisi di salah satu baris.',
            'item.*.equipment_category_id.exists' => 'Kategori itu nggak ada atau sudah dinonaktifkan.',
            'item.*.jumlah.max' => 'Jumlahnya kebanyakan — periksa lagi, mungkin salah ketik.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            // Satu baris lama tidak boleh disebut dua kali -- yang kedua
            // menimpa yang pertama diam-diam.
            function (Validator $validator): void {
                $id = collect((array) $this->input('item'))
                    ->map(fn ($baris) => is_array($baris) ? ($baris['id'] ?? null) : null)
                    ->filter(fn ($v) => $v !== null && $v !== '');

                if ($id->count() !== $id->unique()->count() && ! $validator->errors()->has('item')) {
                    $validator->errors()->add('item', 'Ada baris yang disebut dua kali.');
                }
            },
        ];
    }
}
