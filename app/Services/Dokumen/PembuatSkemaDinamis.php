<?php

namespace App\Services\Dokumen;

/**
 * Struktur dokumen hasil baca -> SKEMA FORM, dibikin dari isi dokumennya.
 *
 * ## Kenapa bukan daftar field per jenis alat
 *
 * Skema di sini lahir dari kertas yang barusan difoto, bukan dari daftar yang
 * ditulis programmer. Konsekuensinya yang penting: lembar yang belum pernah
 * ada di sistem tetap menghasilkan form yang bisa diisi, tanpa siapa pun
 * menambah kelas baru. Lembar dengan tiga kolom menghasilkan form tiga kolom;
 * lembar dengan tujuh menghasilkan tujuh. Nggak ada yang perlu tahu duluan.
 *
 * ## Kunci dipilih dari POSISI, bukan dari label
 *
 * Label di lembar kerja berulang terus-terusan — satu lembar Conductivity
 * punya empat kolom yang semuanya berjudul "Reading" dan empat lagi "°C".
 * Kunci berbasis label bakal tabrakan, dan dua sel beda bakal terikat ke satu
 * kotak isian yang sama: yang satu menimpa yang lain, diam-diam.
 *
 * Posisi itu unik dan deterministik. Labelnya tetap dibawa terpisah buat
 * ditampilkan.
 *
 * ## Tipe kolom ditebak dari ISI, dan menyerah ke teks kalau ragu
 *
 * Kolom yang semua selnya angka itu kolom angka. Kolom campur DIBIARKAN teks —
 * bukan dipaksa jadi angka. Memaksa berarti sel yang isinya `-` atau `n/a`
 * berubah jadi 0 atau ditolak validasi, dan dua-duanya bikin teknisi berkelahi
 * sama form buat memasukkan apa yang jelas-jelas tertulis di kertas.
 */
class PembuatSkemaDinamis
{
    public const TIPE_ANGKA = 'number';

    public const TIPE_TEKS = 'text';

    public const TIPE_TANGGAL = 'date';

    public const TIPE_BOOLEAN = 'boolean';

    public const TIPE_TANDA_TANGAN = 'signature';

    public const TIPE_MULTILINE = 'multiline';

    private const TIPE_SAH = [
        self::TIPE_ANGKA,
        self::TIPE_TEKS,
        self::TIPE_TANGGAL,
        self::TIPE_BOOLEAN,
        self::TIPE_TANDA_TANGAN,
        self::TIPE_MULTILINE,
    ];

    /**
     * @param  array<string, mixed>  $dokumen  hasil PenguraiStrukturDokumen::urai()
     * @return array<string, mixed>
     */
    public function dari(array $dokumen): array
    {
        $bagian = [];

        foreach (($dokumen['sections'] ?? []) as $i => $b) {
            $kunciBagian = 'bagian-'.$i;

            $bagian[] = [
                'kunci' => $kunciBagian,
                'nama' => $b['name'] ?? null,
                'field' => $this->semuaField($b['fields'] ?? [], $kunciBagian),
                'tabel' => $this->semuaTabel($b['tables'] ?? [], $kunciBagian),
            ];
        }

        return [
            'dokumen' => $dokumen['document'] ?? [],
            'bagian' => $bagian,
            'peringatan' => $dokumen['warnings'] ?? [],
            // Dihitung sekali di sini supaya layar review nggak perlu menyapu
            // seluruh pohon cuma buat tahu ada berapa yang wajib dilihat.
            'ringkasan' => $this->ringkasan($bagian),
        ];
    }

    /**
     * @param  array<int, mixed>  $field
     * @return list<array<string, mixed>>
     */
    private function semuaField(array $field, string $kunciBagian): array
    {
        $hasil = [];

        foreach ($field as $i => $f) {
            $tipe = $this->tipeField($f);

            $hasil[] = [
                'kunci' => $kunciBagian.'.field-'.$i,
                'label' => $f['label'] ?? null,
                'tipe' => $tipe,
                'satuan' => $f['unit'] ?? null,
                'nilai' => $f['value'] ?? null,
                'sumber' => $f['source'] ?? PenguraiStrukturDokumen::SUMBER_TIDAK_DIKETAHUI,
                'keyakinan' => $f['confidence'] ?? null,
                'tingkat_keyakinan' => $f['confidence_level'] ?? AmbangKeyakinan::TIDAK_DIKETAHUI,
                'status' => $f['status'] ?? AmbangKeyakinan::PERLU_REVIEW,
                'halaman' => $f['page'] ?? 1,
                'bbox' => $f['bbox'] ?? null,
                'aturan' => $this->aturan($tipe),
                // Yang tercetak di formulir BUKAN isian teknisi. Ditandai
                // supaya form bisa menampilkannya tanpa mengundang diedit —
                // nama standar yang sudah dicetak di kertas nggak seharusnya
                // kelihatan seperti kotak kosong yang lupa diisi.
                'bisa_diisi' => ($f['source'] ?? null) !== PenguraiStrukturDokumen::SUMBER_STATIS,
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<int, mixed>  $tabel
     * @return list<array<string, mixed>>
     */
    private function semuaTabel(array $tabel, string $kunciBagian): array
    {
        $hasil = [];

        foreach ($tabel as $i => $t) {
            $kunciTabel = $kunciBagian.'.tabel-'.$i;
            $baris = $t['rows'] ?? [];

            $hasil[] = [
                'kunci' => $kunciTabel,
                'nama' => $t['name'] ?? null,
                'kolom' => $this->kolom($t['headers'] ?? [], $baris, $kunciTabel),
                'baris' => $this->baris($baris, $kunciTabel),
                'keyakinan' => $t['confidence'] ?? null,
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<int, mixed>  $judul
     * @param  array<int, mixed>  $baris
     * @return list<array<string, mixed>>
     */
    private function kolom(array $judul, array $baris, string $kunciTabel): array
    {
        $lebar = max(count($judul), count($baris[0] ?? []));
        $kolom = [];

        for ($k = 0; $k < $lebar; $k++) {
            $kolom[] = [
                'kunci' => $kunciTabel.'.kolom-'.$k,
                // Judul dibawa APA ADANYA. Kolom tanpa judul tetap kolom —
                // dikasih judul karangan malah bikin teknisi mengira ada
                // keterangan yang sebenarnya nggak tertulis di kertas.
                'judul' => $judul[$k] ?? null,
                'tipe' => $this->tipeKolom($baris, $k),
            ];
        }

        return $kolom;
    }

    /**
     * @param  array<int, mixed>  $baris
     * @return list<list<array<string, mixed>>>
     */
    private function baris(array $baris, string $kunciTabel): array
    {
        $hasil = [];

        foreach ($baris as $r => $isi) {
            $satuBaris = [];

            foreach ((array) $isi as $k => $sel) {
                $satuBaris[] = [
                    'kunci' => $kunciTabel.'.sel-'.$r.'-'.$k,
                    'baris' => $sel['row'] ?? $r,
                    'kolom' => $sel['column'] ?? $k,
                    'nilai' => $sel['value'] ?? null,
                    'sumber' => $sel['source'] ?? PenguraiStrukturDokumen::SUMBER_TIDAK_DIKETAHUI,
                    'keyakinan' => $sel['confidence'] ?? null,
                    'tingkat_keyakinan' => $sel['confidence_level'] ?? AmbangKeyakinan::TIDAK_DIKETAHUI,
                    'status' => $sel['status'] ?? AmbangKeyakinan::PERLU_REVIEW,
                    'halaman' => $sel['page'] ?? 1,
                    'bbox' => $sel['bbox'] ?? null,
                ];
            }

            $hasil[] = $satuBaris;
        }

        return $hasil;
    }

    /**
     * Tipe satu kolom, dari sel-sel yang BENAR-BENAR terbaca.
     *
     * Sel kosong nggak ikut memilih — kolom angka yang separuh selnya belum
     * kebaca tetap kolom angka. Kalau nggak begitu, kolom yang paling butuh
     * bantuan validasi justru yang paling nggak dapat.
     *
     * @param  array<int, mixed>  $baris
     */
    private function tipeKolom(array $baris, int $kolom): string
    {
        $adaIsi = false;

        foreach ($baris as $isi) {
            $nilai = $isi[$kolom]['value'] ?? null;

            if ($nilai === null || $nilai === '') {
                continue;
            }

            $adaIsi = true;

            if (! $this->rasaAngka($nilai)) {
                return self::TIPE_TEKS;
            }
        }

        // Kolom yang belum ada isinya sama sekali jangan diklaim angka.
        return $adaIsi ? self::TIPE_ANGKA : self::TIPE_TEKS;
    }

    /**
     * @param  array<string, mixed>|mixed  $f
     */
    private function tipeField($f): string
    {
        $tipe = is_array($f) ? ($f['type'] ?? null) : null;
        $tipe = is_string($tipe) ? strtolower(trim($tipe)) : '';

        if (in_array($tipe, self::TIPE_SAH, true)) {
            return $tipe;
        }

        // Tipe asing dari model nggak dipaksa jadi salah satu yang dikenal.
        // Kalau nilainya kelihatan angka, perlakukan sebagai angka; selain itu
        // teks — pilihan yang paling nggak mungkin menolak isian yang sah.
        $nilai = is_array($f) ? ($f['value'] ?? null) : null;

        if (is_bool($nilai)) {
            return self::TIPE_BOOLEAN;
        }

        return $nilai !== null && $this->rasaAngka($nilai) ? self::TIPE_ANGKA : self::TIPE_TEKS;
    }

    /**
     * Angka gaya lembar kerja: koma desimal, boleh minus.
     *
     * Koma ikut diterima karena teknisi menulis `25,3` dan formulirnya memang
     * begitu. Nilainya TIDAK diubah di sini — yang ditentukan cuma tipenya.
     * Mengubah `25,3` jadi `25.3` di lapisan ini bikin angka yang tampil di
     * layar review beda dari yang tertulis di kertas, dan teknisi yang
     * membandingkan keduanya bakal mengira pembacaannya salah.
     */
    private function rasaAngka(mixed $nilai): bool
    {
        if (is_int($nilai) || is_float($nilai)) {
            return true;
        }

        if (! is_string($nilai)) {
            return false;
        }

        return preg_match('/^-?\d+(?:[.,]\d+)?$/', trim($nilai)) === 1;
    }

    /**
     * Aturan validasi diturunkan dari TIPE, bukan dari daftar per jenis alat.
     *
     * @return array<string, mixed>
     */
    private function aturan(string $tipe): array
    {
        return match ($tipe) {
            self::TIPE_ANGKA => ['numerik' => true],
            self::TIPE_TANGGAL => ['tanggal' => true],
            self::TIPE_BOOLEAN => ['boolean' => true],
            // Tanda tangan bukan teks: form nggak boleh menyuruh mengetiknya.
            self::TIPE_TANDA_TANGAN => ['baca_saja' => true],
            self::TIPE_MULTILINE => ['banyak_baris' => true],
            default => [],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $bagian
     * @return array<string, int>
     */
    private function ringkasan(array $bagian): array
    {
        $field = 0;
        $sel = 0;
        $perluReview = 0;

        foreach ($bagian as $b) {
            foreach ($b['field'] as $f) {
                $field++;

                if ($f['status'] === AmbangKeyakinan::PERLU_REVIEW) {
                    $perluReview++;
                }
            }

            foreach ($b['tabel'] as $t) {
                foreach ($t['baris'] as $baris) {
                    foreach ($baris as $s) {
                        $sel++;

                        if ($s['status'] === AmbangKeyakinan::PERLU_REVIEW) {
                            $perluReview++;
                        }
                    }
                }
            }
        }

        return [
            'jumlah_field' => $field,
            'jumlah_sel' => $sel,
            'perlu_review' => $perluReview,
        ];
    }
}
