<?php

namespace App\Services\Acuan;

/**
 * Membaca isi versi data acuan sebagai SEL — (lembar, kunci baris, kolom) —
 * lewat `skemaAcuan()` profil, untuk banding versi (FR-11) dan riwayat sel
 * (FR-03, FR-28).
 *
 * Isi versi disimpan dalam bentuk berkas JSON-nya apa adanya (`JsonKanonik`
 * atas `database/data/*.json`), jadi letak tiap lembar dibaca dari `jalur`
 * skema, bukan dari bentuk generik §2.6 SDD. Aturan jenis lembar mengikuti
 * doc `CalibrationProfile::skemaAcuan()`.
 *
 * ## Kunci baris (dipakai juga di rute `sel/{lembar}/{kunci}/{kolom}`)
 *
 *  - `objek`  — satu baris, kuncinya `0`.
 *  - `baris`  — kunci objek peta, sebagai string (`1.0`, `101.6`, `200`).
 *  - `daftar` — nilai kolom `kunci` sebagai string; tanpa `kunci`, POSISI
 *               baris di list (mulai `0`).
 *  - Segmen `*` di jalur memberi awalan posisi/kunci elemen induk, dipisah
 *    `:` — titik pra-cetak varian ke-2 baris ke-3 = `1:2`. Bukan `/`, karena
 *    kunci baris masuk ke path URL.
 *
 * ## Nilai
 *
 * Nilai sel = nilai terurai apa adanya (`float`/`int`/`string`/list). TIDAK
 * dibulatkan dan tidak diubah tipenya: `200` dan `200.0` beda isi kanonik,
 * jadi juga beda di banding. Selisih dihitung langsung `baru − lama` dalam
 * float (AGENTS.md §Olah data 5).
 */
class SelAcuan
{
    /** Kunci baris lembar berjenis `objek` (satu-satunya baris). */
    public const KUNCI_OBJEK = '0';

    /** Pemisah awalan elemen induk (`*`) dari kunci baris. */
    public const PEMISAH_INDUK = ':';

    /**
     * Baris satu lembar: kunci baris => (kode kolom => nilai). Kolom yang
     * tidak ada di isi TIDAK muncul (beda dari kolom bernilai `null`).
     *
     * @param  array<string, mixed>  $lembar  satu entri `skemaAcuan()['lembar']`
     * @param  array<array-key, mixed>  $isi  isi versi terurai
     * @return array<string, array<string, mixed>>
     */
    public function baris(array $lembar, array $isi): array
    {
        $kolom = array_map(static fn (array $k): string => (string) $k['kode'], $lembar['kolom'] ?? []);
        $kunciKolom = $lembar['kunci'] ?? null;
        $hasil = [];

        foreach ($this->simpul($isi, (string) $lembar['jalur']) as $awalan => $simpul) {
            if (! is_array($simpul)) {
                continue;
            }

            switch ($lembar['jenis'] ?? null) {
                case 'objek':
                    $hasil[$this->kunci($awalan, self::KUNCI_OBJEK)] = $this->ambilKolom($simpul, $kolom);
                    break;

                case 'baris':
                    $kolomNilai = array_values(array_filter($kolom, static fn (string $k): bool => $k !== $kunciKolom));

                    foreach ($simpul as $k => $nilai) {
                        $sel = $kunciKolom !== null ? [$kunciKolom => (string) $k] : [];

                        if (is_array($nilai) && ! array_is_list($nilai)) {
                            $sel += $this->ambilKolom($nilai, $kolomNilai);
                        } elseif (count($kolomNilai) === 1) {
                            $sel[$kolomNilai[0]] = $nilai;
                        }

                        $hasil[$this->kunci($awalan, (string) $k)] = $sel;
                    }
                    break;

                case 'daftar':
                    if (! array_is_list($simpul)) {
                        break;
                    }

                    foreach ($simpul as $posisi => $obj) {
                        if (! is_array($obj)) {
                            continue;
                        }

                        $k = $kunciKolom !== null && array_key_exists($kunciKolom, $obj) && is_scalar($obj[$kunciKolom])
                            ? (string) $obj[$kunciKolom]
                            : (string) $posisi;

                        $hasil[$this->kunci($awalan, $k)] = $this->ambilKolom($obj, $kolom);
                    }
                    break;
            }
        }

        return $hasil;
    }

    /**
     * Nilai satu sel di satu isi.
     *
     * @param  array<string, mixed>  $lembar
     * @param  array<array-key, mixed>  $isi
     * @return array{ada: bool, nilai: mixed}
     */
    public function nilai(array $lembar, array $isi, string $kunci, string $kolom): array
    {
        $baris = $this->baris($lembar, $isi)[$kunci] ?? null;

        return $baris !== null && array_key_exists($kolom, $baris)
            ? ['ada' => true, 'nilai' => $baris[$kolom]]
            : ['ada' => false, 'nilai' => null];
    }

    /**
     * Banding dua isi per lembar skema: sel berubah, baris tambah/hapus,
     * ringkasan jumlah. `$lama` → `$baru`.
     *
     * @param  array{lembar: list<array<string, mixed>>}  $skema
     * @param  array<array-key, mixed>  $lama
     * @param  array<array-key, mixed>  $baru
     * @return array{lembar: list<array<string, mixed>>, ringkasan: array<string, int>}
     */
    public function banding(array $skema, array $lama, array $baru): array
    {
        $perLembar = [];
        $ringkasan = ['lembar_berubah' => 0, 'sel_berubah' => 0, 'baris_tambah' => 0, 'baris_hapus' => 0];

        foreach ($skema['lembar'] as $lembar) {
            $kode = (string) $lembar['kode'];
            $kolom = array_map(static fn (array $k): string => (string) $k['kode'], $lembar['kolom'] ?? []);
            $barisLama = $this->baris($lembar, $lama);
            $barisBaru = $this->baris($lembar, $baru);

            $berubah = [];
            $tambah = [];
            $hapus = [];

            foreach ($barisLama as $kunci => $selLama) {
                if (! array_key_exists($kunci, $barisBaru)) {
                    $hapus[] = ['kunci' => (string) $kunci, 'sel' => $selLama];

                    continue;
                }

                foreach ($kolom as $k) {
                    $adaLama = array_key_exists($k, $selLama);
                    $adaBaru = array_key_exists($k, $barisBaru[$kunci]);

                    if (! $adaLama && ! $adaBaru) {
                        continue;
                    }

                    $nilaiLama = $adaLama ? $selLama[$k] : null;
                    $nilaiBaru = $adaBaru ? $barisBaru[$kunci][$k] : null;

                    // Strict: `200` → `200.0` juga perubahan isi kanonik.
                    if ($adaLama === $adaBaru && $nilaiLama === $nilaiBaru) {
                        continue;
                    }

                    $berubah[] = $this->selBerubah($kode, (string) $kunci, $k, $nilaiLama, $nilaiBaru);
                }
            }

            foreach ($barisBaru as $kunci => $selBaru) {
                if (! array_key_exists($kunci, $barisLama)) {
                    $tambah[] = ['kunci' => (string) $kunci, 'sel' => $selBaru];
                }
            }

            if ($berubah !== [] || $tambah !== [] || $hapus !== []) {
                $ringkasan['lembar_berubah']++;
            }

            $ringkasan['sel_berubah'] += count($berubah);
            $ringkasan['baris_tambah'] += count($tambah);
            $ringkasan['baris_hapus'] += count($hapus);

            $perLembar[] = [
                'kode' => $kode,
                'label' => $lembar['label'] ?? $kode,
                'sel_berubah' => $berubah,
                'baris_tambah' => $tambah,
                'baris_hapus' => $hapus,
            ];
        }

        return ['lembar' => $perLembar, 'ringkasan' => $ringkasan];
    }

    /**
     * Simpul di `$jalur` (dipisah `/`, `*` = tiap elemen induk), dikunci
     * awalan induknya (`''` bila jalurnya tanpa `*`).
     *
     * @param  array<array-key, mixed>  $isi
     * @return array<string, mixed>
     */
    private function simpul(array $isi, string $jalur): array
    {
        $simpul = ['' => $isi];

        // Jalur kosong = akar isi: lembar `objek` untuk daun tingkat atas
        // (mis. `faktor_set_point_kg_ke_kn` Gaya).
        if ($jalur === '') {
            return $simpul;
        }

        foreach (explode('/', $jalur) as $segmen) {
            $lanjut = [];

            foreach ($simpul as $awalan => $node) {
                if (! is_array($node)) {
                    continue;
                }

                if ($segmen === '*') {
                    foreach ($node as $k => $anak) {
                        $lanjut[$this->kunci((string) $awalan, (string) $k)] = $anak;
                    }
                } elseif (array_key_exists($segmen, $node)) {
                    $lanjut[(string) $awalan] = $node[$segmen];
                }
            }

            $simpul = $lanjut;
        }

        return $simpul;
    }

    /**
     * @param  array<array-key, mixed>  $obj
     * @param  list<string>  $kolom
     * @return array<string, mixed>
     */
    private function ambilKolom(array $obj, array $kolom): array
    {
        $sel = [];

        foreach ($kolom as $k) {
            if (array_key_exists($k, $obj)) {
                $sel[$k] = $obj[$k];
            }
        }

        return $sel;
    }

    private function kunci(string $awalan, string $kunci): string
    {
        return $awalan === '' ? $kunci : $awalan.self::PEMISAH_INDUK.$kunci;
    }

    /** @return array<string, mixed> */
    private function selBerubah(string $lembar, string $kunci, string $kolom, mixed $lama, mixed $baru): array
    {
        $sel = [
            'lembar' => $lembar,
            'kunci' => $kunci,
            'kolom' => $kolom,
            'lama' => $lama,
            'baru' => $baru,
        ];

        if (self::angka($lama) && self::angka($baru)) {
            $selisih = $baru - $lama;

            $sel['selisih_absolut'] = $selisih;
            // Relatif terhadap |lama|; lama nol tidak punya selisih relatif.
            $sel['selisih_relatif'] = $lama == 0 ? null : $selisih / abs($lama);
        }

        return $sel;
    }

    private static function angka(mixed $nilai): bool
    {
        return is_int($nilai) || is_float($nilai);
    }
}
