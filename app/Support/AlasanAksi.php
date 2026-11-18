<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Membaca `alasan` untuk aksi lab yang memutus atau memulihkan akses orang
 * (mencabut anggota pelanggan, menonaktifkan/mengaktifkan akun internal).
 * Kontrak: `docs/perintah-frontend-super-admin.md`.
 *
 * ## Kenapa helper, bukan FormRequest
 *
 * Rute-rute ini memuat penunjuk baris di path (`{customer}`, `{user}`). Sebuah
 * FormRequest yang di-type-hint di controller divalidasi SEBELUM isi controller
 * jalan — jadi sebelum pemeriksaan "baris ini milik lab pemanggil". Hasilnya
 * pemanggil dari lab A yang mengirim `alasan` kosong ke baris lab B mendapat
 * `422`, sedangkan id yang tidak ada mendapat `404`: perbedaan itu sudah
 * membuktikan baris lab B ada, persis yang dijaga aturan "404, bukan 403".
 * Dipanggil sesudah penjaga kepemilikan, perbedaannya hilang.
 */
final class AlasanAksi
{
    public const MAKS = 500;

    /**
     * @param  bool  $wajib  `true` = 3–500 karakter; `false` = boleh kosong
     * @return string|null alasan yang sudah dipangkas; `null` hanya bila `$wajib` false dan kosong
     */
    public static function baca(Request $request, bool $wajib = true): ?string
    {
        $data = $request->validate(
            [
                'alasan' => $wajib
                    ? ['required', 'string', 'min:3', 'max:'.self::MAKS]
                    : ['nullable', 'string', 'max:'.self::MAKS],
            ],
            [
                'alasan.required' => 'Alasan wajib diisi.',
                'alasan.min' => 'Alasan minimal 3 karakter.',
                'alasan.max' => 'Alasan maksimal '.self::MAKS.' karakter.',
            ],
        );

        $alasan = isset($data['alasan']) ? trim((string) $data['alasan']) : '';

        return $alasan === '' ? null : $alasan;
    }
}
