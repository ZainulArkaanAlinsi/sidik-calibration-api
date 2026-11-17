<?php

namespace Tests\Concerns;

/**
 * Aturan main `*SesiContohCocokMasterTest` (PK-4): satu master, satu sertifikat
 * terbit, SEMUA baris master harus punya nasib yang tertulis.
 *
 * Nasib yang sah hanya tiga:
 *
 *  1. `cocok` — angka cetak sama dengan master (kasus normal);
 *  2. selisih yang DIKETAHUI — baris master yang memang tidak (atau berbeda)
 *     dicetak, lengkap dengan alasan bernomor (`docs/pertanyaan-lab-*.md`) dan
 *     isi cetak aplikasinya. Daftar ini dua arah: baris yang diklaim beda tapi
 *     ternyata cocok, atau yang beda di luar daftar, sama-sama gagal;
 *  3. `galat_master` / `tak_pasti` — master sendiri mencetak `#VALUE!`, atau
 *     sel `General` berpecahan yang teksnya tidak bisa ditebak. Dihitung dan
 *     disebut, tidak dilewatkan diam-diam.
 *
 * Jumlah baris `cocok` dikunci (`$jumlahCocok`) supaya test tidak bisa hijau
 * karena pembanding diam-diam tidak menemukan apa-apa.
 */
trait MenegakkanCetakMaster
{
    /**
     * @param  array<int, array{0: string, 1: list<string>|null}>  $selisihDiketahui
     *                                                                                baris master => [alasan, token yang dicetak aplikasi di baris itu (null = tidak dicetak)]
     * @param  list<int>  $barisDibuang  baris lembar yang bukan hasil (tidak diadu)
     * @param  list<int>  $barisGalat  baris yang MEMANG diharapkan galat/tak pasti di master
     * @param  bool  $akhiran  lihat `PembandingCetakMaster::cocokkan()`
     */
    protected function assertCetakSamaMaster(
        string $kunci,
        string $html,
        int $jumlahCocok,
        array $selisihDiketahui = [],
        array $barisDibuang = [],
        array $barisGalat = [],
        bool $akhiran = false,
    ): void {
        $cetak = PembandingCetakMaster::barisCetak($html);
        $hasil = PembandingCetakMaster::cocokkan(PembandingCetakMaster::barisMaster($kunci, $barisDibuang), $cetak, $akhiran);

        $cocok = 0;
        $diluarDaftar = [];
        $galat = [];

        foreach ($hasil as $b) {
            switch ($b['status']) {
                case 'cocok':
                    $cocok++;
                    $this->assertArrayNotHasKey(
                        $b['baris'],
                        $selisihDiketahui,
                        "{$kunci} baris {$b['baris']} dicatat sebagai selisih yang diketahui, tetapi sekarang COCOK — "
                        .'hapus dari daftar (selisihnya sudah ditutup) supaya daftarnya tetap jujur.',
                    );
                    break;
                case 'beda':
                    if (! isset($selisihDiketahui[$b['baris']])) {
                        $diluarDaftar[] = $b;
                    }
                    break;
                default:
                    $galat[] = $b['baris'];
            }
        }

        $this->assertSame(
            '',
            PembandingCetakMaster::uraikan($diluarDaftar, ['beda']),
            "{$kunci}: angka cetak BERBEDA dari master di luar daftar selisih yang diketahui. "
            .'Ini temuan: JANGAN disetel supaya hijau — angkat sebagai pertanyaan lab bernomor atau perbaiki bug-nya.',
        );

        foreach ($selisihDiketahui as $baris => [$alasan, $tokenApp]) {
            $b = collect($hasil)->firstWhere('baris', $baris);
            $this->assertNotNull($b, "{$kunci} baris {$baris} tidak ada di fixture master.");
            $this->assertContains($b['status'], ['beda', 'galat_master'], "{$kunci} baris {$baris} ({$alasan}).");

            $baris_cetak = array_column($cetak, 'token');

            if ($tokenApp === null) {
                $this->assertNotContains(
                    $b['token'],
                    $baris_cetak,
                    "{$kunci} baris {$baris}: dicatat TIDAK dicetak aplikasi, tetapi barisnya muncul ({$alasan}).",
                );
            } else {
                $this->assertContains(
                    $tokenApp,
                    $baris_cetak,
                    "{$kunci} baris {$baris}: isi cetak aplikasi yang dicatat (".implode(' ', $tokenApp).') tidak ada — '
                    ."angka selisihnya bergeser ({$alasan}).",
                );
            }
        }

        $this->assertSame(
            $jumlahCocok,
            $cocok,
            "{$kunci}: jumlah baris master yang cocok dengan cetak berubah.",
        );

        $this->assertSame(
            $barisGalat,
            $galat,
            "{$kunci}: baris yang berstatus galat/tak pasti di master berubah.",
        );
    }
}
