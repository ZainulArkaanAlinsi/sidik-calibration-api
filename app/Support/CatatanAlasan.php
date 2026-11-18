<?php

namespace App\Support;

/**
 * Satu tempat untuk panjang ALASAN yang ikut tertulis di `audit_logs.note`.
 *
 * ## Kenapa ada
 *
 * `audit_logs.note` itu `string(500)` (migrasi `2026_07_27_100000`). Pernah
 * lolos di SQLite tetapi meledak di MySQL strict mode: alasan 500 karakter
 * ditambah awalan ("Arsipkan draf: ") menjadi 515 karakter, dan MySQL menolaknya
 * dengan 500 `Data too long for column 'note'` -- SQLite tidak pernah
 * memeriksa panjang `varchar`. Karena itu batasnya dihitung SEKALI di sini:
 *
 *     ALASAN_MAKS (400) + awalan terpanjang (< 100)  <=  PANJANG_NOTE (500)
 *
 * dan `BatasPanjangAuditCrudTest` membuktikannya terhadap angka yang dibaca
 * dari berkas migrasi, bukan terhadap konstanta ini saja.
 *
 * Hitungan KARAKTER (`mb_strlen`), bukan byte: kolom MySQL utf8mb4
 * `varchar(500)` memuat 500 karakter berapa pun lebar byte-nya, dan aturan
 * validasi `max:` Laravel juga menghitung karakter.
 */
final class CatatanAlasan
{
    /** Cermin `audit_logs.note` string(500). Dijaga test terhadap migrasinya. */
    public const PANJANG_NOTE = 500;

    public const ALASAN_MIN = 5;

    public const ALASAN_MAKS = 400;

    /**
     * Aturan validasi untuk field `alasan`.
     *
     * @return list<string>
     */
    public static function aturan(bool $wajib = true): array
    {
        return [
            $wajib ? 'required' : 'nullable',
            'string',
            'min:'.self::ALASAN_MIN,
            'max:'.self::ALASAN_MAKS,
        ];
    }

    /**
     * Teks `note`: `"<awalan>: <alasan>"` (atau `"<awalan>"` bila tanpa
     * alasan), dijamin tidak melebihi kolomnya.
     *
     * Pemotongan di sini HANYA jaring pengaman -- validasi `max:` sudah
     * menolak alasan yang terlalu panjang dengan 422 yang jelas, jadi di jalur
     * normal fungsi ini tidak memotong apa pun. Alasan LENGKAP selalu ikut di
     * `new_data.alasan` (kolom JSON), jadi pemotongan tidak menghilangkan data.
     */
    public static function note(string $awalan, ?string $alasan): string
    {
        $alasan = trim((string) $alasan);
        $teks = $alasan === '' ? $awalan : $awalan.': '.$alasan;

        return mb_strlen($teks) <= self::PANJANG_NOTE
            ? $teks
            : mb_substr($teks, 0, self::PANJANG_NOTE);
    }

    /**
     * Awalan `note` terpanjang yang dipakai paket CRUD. Test mengunci bahwa
     * `ALASAN_MAKS` + awalan ini + 2 (": ") tetap muat di `PANJANG_NOTE`.
     */
    public const AWALAN_ARSIPKAN = 'Arsipkan draf';

    public const AWALAN_PULIHKAN = 'Pulihkan draf';

    public const AWALAN_BATALKAN_PENUGASAN = 'Batalkan penugasan';

    public const AWALAN_NONAKTIFKAN_KATEGORI = 'Nonaktifkan kategori';

    public const AWALAN_AKTIFKAN_KATEGORI = 'Aktifkan kategori';
}
