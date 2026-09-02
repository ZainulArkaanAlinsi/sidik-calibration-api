<?php

namespace App\Support\ImporPelanggan;

use RuntimeException;

/**
 * Tulis hasil pemilahan jadi satu CSV yang dibaca ORANG, bukan mesin.
 *
 * Yang membacanya admin lab yang memutuskan baris `perlu_tinjau` — jadi
 * judul kolomnya bahasa Indonesia dan sebab kemiripannya ditulis utuh, bukan
 * kode. Laporan yang butuh diterjemahkan dulu tidak akan dibaca, dan yang
 * tidak dibaca bikin `--uji-coba` jadi upacara.
 *
 * ## Pemisahnya ikut berkas masukan, dan itu bukan detail
 *
 * Excel membaca CSV memakai pemisah daftar dari setelan wilayah — bukan dari
 * isi berkasnya. Lab yang Excel-nya menulis `;` juga membacanya dengan `;`.
 * Kalau laporan ini dipaksa `,`, dia terbuka jadi satu kolom penuh di layar
 * admin yang sama, dan laporan yang tidak kebaca itu persis kegagalan yang
 * dijaga `BacaBerkas` di sisi masuk.
 */
final class Laporan
{
    /** RFC 4180, sama dengan `BacaBerkas` — lihat alasannya di sana. */
    private const ESCAPE = '';

    private const JUDUL = [
        'keranjang',
        'baris_berkas',
        'nama',
        'alamat',
        'contact_person',
        'telepon',
        'email',
        'lawan',
        'sebab',
        'peringatan',
    ];

    /**
     * @param  array{
     *     baru: list<BarisMasukan>,
     *     kembar_pasti: list<array{baris: BarisMasukan, lawan: string, sebab: string}>,
     *     perlu_tinjau: list<array{baris: BarisMasukan, lawan: string, sebab: string}>,
     * }  $keranjang
     * @param  list<array{baris: int, alasan: string, isi: string}>  $ditolak
     */
    public static function tulis(string $path, array $keranjang, array $ditolak, string $pemisah = ','): void
    {
        $folder = dirname($path);

        if (! is_dir($folder) && ! mkdir($folder, 0o775, true) && ! is_dir($folder)) {
            throw new RuntimeException("Folder laporan tidak bisa dibuat: {$folder}");
        }

        $berkas = fopen($path, 'w');

        if ($berkas === false) {
            throw new RuntimeException("Laporan tidak bisa ditulis: {$path}");
        }

        // BOM supaya huruf beraksen dan `°` tidak jadi byte rusak waktu Excel
        // membukanya. Tanpa ini alamat yang benar kelihatan salah di laporan,
        // lalu "diperbaiki" jadi salah beneran di sumbernya.
        fwrite($berkas, "\xEF\xBB\xBF");
        fputcsv($berkas, self::JUDUL, $pemisah, '"', self::ESCAPE);

        foreach ($keranjang['baru'] as $baris) {
            fputcsv($berkas, self::baris('baru', $baris, '', 'akan dibuat'), $pemisah, '"', self::ESCAPE);
        }

        foreach ($keranjang['kembar_pasti'] as $item) {
            fputcsv($berkas, self::baris('kembar_pasti', $item['baris'], $item['lawan'], $item['sebab']), $pemisah, '"', self::ESCAPE);
        }

        foreach ($keranjang['perlu_tinjau'] as $item) {
            fputcsv($berkas, self::baris('perlu_tinjau', $item['baris'], $item['lawan'], $item['sebab']), $pemisah, '"', self::ESCAPE);
        }

        foreach ($ditolak as $tolak) {
            fputcsv($berkas, [
                'ditolak',
                $tolak['baris'],
                $tolak['isi'],
                '', '', '', '',
                '',
                $tolak['alasan'],
                '',
            ], $pemisah, '"', self::ESCAPE);
        }

        fclose($berkas);
    }

    /**
     * @return list<string|int>
     */
    private static function baris(string $keranjang, BarisMasukan $baris, string $lawan, string $sebab): array
    {
        return [
            $keranjang,
            $baris->nomorBaris,
            $baris->nama,
            $baris->alamat ?? '',
            $baris->contactPerson ?? '',
            $baris->telepon ?? '',
            $baris->email ?? '',
            $lawan,
            $sebab,
            implode('; ', $baris->peringatan),
        ];
    }
}
