<?php

namespace App\Services\Pencarian;

use Illuminate\Database\Query\Grammars\Grammar;

/**
 * Menyusun SQL "cocok" untuk SATU kata cari: kondisi `WHERE` dan peringkatnya.
 *
 * Ada tiga jenis kolom, dan bedanya menentukan cara membandingkan:
 *  - `kunci`     pengenal yang diketik apa adanya (nomor seri, nomor order);
 *  - `nomor`     nomor dokumen yang dibandingkan dalam bentuk ternormalisasi
 *                (`NormalisasiNomor`) di KEDUA sisi;
 *  - `deskripsi` teks bebas (nama alat, nama PT) -- hanya "mengandung".
 *
 * Peringkat (kecil = lebih relevan): 0 persis pada kolom kunci/nomor, 1 diawali
 * kata cari, 2 sisanya (mengandung). Kata cari yang cuma berisi pemisah
 * (`/`, `-`, spasi) tidak punya bentuk ternormalisasi, jadi kolom `nomor` dilewati
 * -- kalau tidak, `LIKE '%%'` mencocokkan semuanya.
 *
 * Hanya MERANGKAI SQL. Nilainya selalu lewat binding, nama kolom selalu dari
 * kode pemanggil (bukan input pengguna).
 */
final class RumusCocok
{
    /** @var list<string> */
    private array $kunci = [];

    /** @var list<string> */
    private array $nomor = [];

    /** @var list<string> */
    private array $deskripsi = [];

    /** @var list<array{sql: string, bindings: list<int|string>, persis: bool}> */
    private array $tambahan = [];

    private readonly string $kata;

    private readonly string $kunciNomor;

    public function __construct(string $kata, private readonly Grammar $grammar)
    {
        $this->kata = trim($kata);
        $this->kunciNomor = NormalisasiNomor::kunci($this->kata);
    }

    public function kunci(string ...$kolom): self
    {
        array_push($this->kunci, ...$kolom);

        return $this;
    }

    public function nomor(string ...$kolom): self
    {
        array_push($this->nomor, ...$kolom);

        return $this;
    }

    public function deskripsi(string ...$kolom): self
    {
        array_push($this->deskripsi, ...$kolom);

        return $this;
    }

    /**
     * Kecocokan lewat relasi (mis. `EXISTS (...)`). Fragmen SQL boolean beserta
     * binding-nya, sudah memakai nama kolom terbungkus.
     *
     * @param  list<int|string>  $bindings
     * @param  bool  $persis  true = ikut peringkat 0, false = peringkat 2
     */
    public function lewatRelasi(string $sql, array $bindings, bool $persis = false): self
    {
        $this->tambahan[] = ['sql' => $sql, 'bindings' => $bindings, 'persis' => $persis];

        return $this;
    }

    /** Bentuk ternormalisasi kata cari ('' bila tak ada huruf/angka). */
    public function kunciNomor(): string
    {
        return $this->kunciNomor;
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Kondisi `WHERE` (sudah berkurung) beserta binding-nya, urut sesuai `?`.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function kondisi(): array
    {
        $bagian = [];
        $binding = [];

        foreach ($this->kunci as $kolom) {
            $bagian[] = PolaLike::sql($this->grammar->wrap($kolom));
            $binding[] = PolaLike::mengandung($this->kata);
        }

        foreach ($this->nomorAktif() as $kolom) {
            $bagian[] = PolaLike::sql(NormalisasiNomor::sqlKolom($this->grammar->wrap($kolom)));
            $binding[] = PolaLike::mengandung($this->kunciNomor);
        }

        foreach ($this->deskripsi as $kolom) {
            $bagian[] = PolaLike::sql($this->grammar->wrap($kolom));
            $binding[] = PolaLike::mengandung($this->kata);
        }

        foreach ($this->tambahan as $fragmen) {
            $bagian[] = $fragmen['sql'];
            array_push($binding, ...$fragmen['bindings']);
        }

        if ($bagian === []) {
            return ['(1 = 0)', []];
        }

        return ['('.implode(' OR ', $bagian).')', $binding];
    }

    /**
     * Ekspresi peringkat 0/1/2 beserta binding-nya, urut sesuai `?`.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function peringkat(): array
    {
        $persis = [];
        $bindingPersis = [];
        $awalan = [];
        $bindingAwalan = [];

        foreach ($this->kunci as $kolom) {
            $ekspresi = $this->grammar->wrap($kolom);
            $persis[] = "UPPER({$ekspresi}) = ?";
            $bindingPersis[] = strtoupper($this->kata);
            $awalan[] = PolaLike::sql($ekspresi);
            $bindingAwalan[] = PolaLike::awalan($this->kata);
        }

        foreach ($this->nomorAktif() as $kolom) {
            $ekspresi = NormalisasiNomor::sqlKolom($this->grammar->wrap($kolom));
            $persis[] = "UPPER({$ekspresi}) = ?";
            $bindingPersis[] = $this->kunciNomor;
            $awalan[] = PolaLike::sql($ekspresi);
            $bindingAwalan[] = PolaLike::awalan($this->kunciNomor);
        }

        foreach ($this->tambahan as $fragmen) {
            if ($fragmen['persis']) {
                $persis[] = $fragmen['sql'];
                array_push($bindingPersis, ...$fragmen['bindings']);
            }
        }

        $cabang = [];
        $binding = [];

        if ($persis !== []) {
            $cabang[] = 'WHEN '.implode(' OR ', $persis).' THEN 0';
            array_push($binding, ...$bindingPersis);
        }

        if ($awalan !== []) {
            $cabang[] = 'WHEN '.implode(' OR ', $awalan).' THEN 1';
            array_push($binding, ...$bindingAwalan);
        }

        if ($cabang === []) {
            return ['2', []];
        }

        return ['CASE '.implode(' ', $cabang).' ELSE 2 END', $binding];
    }

    /** @return list<string> */
    private function nomorAktif(): array
    {
        return $this->kunciNomor === '' ? [] : $this->nomor;
    }
}
