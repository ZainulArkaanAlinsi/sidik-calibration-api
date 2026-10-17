<?php

namespace App\Services\Studio\Hitung;

use RuntimeException;

/**
 * Galat jalur `/hitung` dengan kode stabil untuk klien (kontrak §18):
 * `berkas_asli_tidak_ada` 409, `perlu_sandi` 409, `hitung_terlalu_lama` 504,
 * `edit_tidak_valid` 422, `rumus_terlalu_berat` 422 (rumus edit merujuk terlalu
 * banyak sel, memakai rujukan dinamis, atau memadukan teks dengan larik besar —
 * `PagarRumus`), `hitung_terlalu_berat` 422 (memori hitung melewati ambang
 * `PagarMemori` di tengah evaluasi), `sel_terkunci` 422 (K-49-15: edit ke sel
 * terkunci lembar yang dilindungi Excel oleh selain super_admin).
 *
 * `tambahan` = kunci ekstra di badan respons (mis. `sel_terkunci`).
 */
final class GagalHitung extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $tambahan
     */
    public function __construct(
        public readonly string $kode,
        string $pesan,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly array $tambahan = [],
    ) {
        parent::__construct($pesan);
    }

    public static function terlaluLama(): self
    {
        return new self('hitung_terlalu_lama', 'Hitung ulang melewati batas waktu. Kurangi edit atau coba lagi.', 504);
    }

    /**
     * Pagar memori lunak (`PagarMemori`): dilempar SEBELUM PHP mencapai
     * `memory_limit`, supaya klien menerima jawaban bersih, bukan PHP Fatal.
     */
    public static function terlaluBerat(): self
    {
        return new self('hitung_terlalu_berat', 'Hitung ulang memakai memori melebihi batas server. Kurangi edit, atau perkecil rentang/teks di rumus yang diedit.', 422);
    }

    public static function berkasAsliTidakAda(): self
    {
        return new self('berkas_asli_tidak_ada', 'Workbook ini tidak punya berkas Excel asli (sumber CSV) — tidak bisa dihitung ulang.', 409);
    }

    public static function perluSandi(): self
    {
        return new self('perlu_sandi', 'Model hitung workbook bersandi belum disiapkan. Admin menjalankan: php artisan studio:siapkan-hitung <id workbook> (sandi diminta).', 409);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function editTidakValid(string $pesan, array $errors): self
    {
        return new self('edit_tidak_valid', $pesan, 422, $errors);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function rumusTerlaluBerat(string $pesan, array $errors): self
    {
        return new self('rumus_terlalu_berat', $pesan, 422, $errors);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<array{lembar_id: int, a: string}>  $sel
     */
    public static function selTerkunci(string $pesan, array $errors, array $sel): self
    {
        return new self('sel_terkunci', $pesan, 422, $errors, ['sel_terkunci' => $sel]);
    }
}
