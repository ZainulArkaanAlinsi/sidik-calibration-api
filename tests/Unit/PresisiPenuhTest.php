<?php

namespace Tests\Unit;

use App\Models\UncertaintyCalculation;
use App\Support\Angka;
use App\Support\AngkaFloatEksak;
use App\Support\PresisiPenuh;
use Illuminate\Database\Eloquent\JsonEncodingException;
use PHPUnit\Framework\TestCase;

/**
 * Titik SERI pembulatan: nilai yang tepat di tengah dua pembulatan (`x,xx5`)
 * jatuh ke atas atau ke bawah menurut sisa biner double-nya — dan master
 * (Excel) membaca double itu, bukan nilai yang sudah dibulatkan 8 desimal.
 *
 * Kasus master yang terdokumentasi: TIDS Recorder `071-CAL-325`, titik 3,
 * `SERTIFIKAT!L22` = `94,235 − 93,48` = 0,7549999999999955 → tercetak `0,75`.
 * Tersimpan di `decimal(20,8)` jadi 0,755 → tercetak `0,76`.
 */
class PresisiPenuhTest extends TestCase
{
    /** `SERTIFIKAT!L22` Recorder, nilai double sel master. */
    private const KOREKSI_MASTER = 0.7549999999999955;

    /** Baris hitung seperti yang sampai ke penyimpan: float asli, belum dibulatkan. */
    private function baris(array $nilai, ?array $jejak = null): UncertaintyCalculation
    {
        $baris = new UncertaintyCalculation;
        $baris->forceFill([
            'titik_ukur' => 94.23499999999999,
            'rata_rata' => 93.47999999999999,
            'koreksi' => self::KOREKSI_MASTER,
            'ketidakpastian_diperluas' => 1.6230242812345,
            ...$nilai,
        ]);

        if ($jejak !== null) {
            $baris->type_b_components = $jejak;
        }

        return $baris;
    }

    /**
     * Yang masuk ke kolom: persis hasil `bulatkanHitungan()` (`round(…, 8)`).
     * Titik awal pembuktian: pembulatan ini yang memindahkan sisi seri.
     */
    public function test_pembulatan_delapan_desimal_memindahkan_sisi_seri(): void
    {
        $disimpan = round(self::KOREKSI_MASTER, 8);

        $this->assertSame(0.755, $disimpan);
        $this->assertSame('0,75', Angka::hasil(self::KOREKSI_MASTER, 2), 'Master (nilai double) mencetak 0,75.');
        $this->assertSame('0,76', Angka::hasil($disimpan, 2), 'Nilai terbulat mencetak 0,76 — itu cacatnya.');
    }

    public function test_float_asli_dibekukan_sebagai_teks_yang_kembali_persis(): void
    {
        $beku = PresisiPenuh::kumpulkan([
            'koreksi' => self::KOREKSI_MASTER,
            'titik_ukur' => 94.23499999999999,
        ]);

        $this->assertSame(['titik_ukur', 'koreksi'], array_keys($beku), 'Urutan mengikuti KOLOM.');

        foreach ($beku as $teks) {
            $this->assertIsString($teks, 'Teks, bukan angka JSON: tidak bergantung serialize_precision / MySQL JSON.');
        }

        $this->assertSame(self::KOREKSI_MASTER, (float) $beku['koreksi']);
        $this->assertSame(94.23499999999999, (float) $beku['titik_ukur']);
    }

    /** Tidak ada yang hilang di pembulatan → tidak ada yang perlu dibekukan. */
    public function test_yang_tidak_kehilangan_apa_apa_tidak_dibekukan(): void
    {
        $this->assertSame([], PresisiPenuh::kumpulkan([
            'koreksi' => 0.755,                  // sudah 8-desimal-eksak
            'rata_rata' => 93,                   // int
            'titik_ukur' => '94.235',            // teks (bukan float hitungan)
            'ketidakpastian_diperluas' => NAN,   // bukan angka
            'error' => 0.7549999999999955,       // bukan kolom cetak
            'type_a' => 0.1234567890123,         // bukan kolom cetak
        ]));
        $this->assertSame([], PresisiPenuh::kumpulkan(['koreksi' => INF]));
        $this->assertSame([], PresisiPenuh::kumpulkan([]));
    }

    public function test_sisipkan_menambah_kunci_tanpa_mengubah_daftar_komponen(): void
    {
        $komponen = [
            ['sumber' => 'resolusi', 'keterangan' => 'x', 'distribusi' => 'persegi', 'nilai' => 0.003],
            ['sumber' => 'lantai_cmc', 'keterangan' => 'y', 'distribusi' => '-', 'nilai' => 0.86],
        ];

        $hasil = PresisiPenuh::sisipkan($komponen, ['koreksi' => self::KOREKSI_MASTER]);

        $this->assertSame($komponen, PresisiPenuh::tanpaKunci($hasil), 'Keluaran API tidak boleh berubah bentuk.');
        $this->assertTrue(array_is_list(PresisiPenuh::tanpaKunci($hasil)), 'Klien membaca daftar (List), bukan peta.');
        $this->assertSame(['koreksi' => '0.7549999999999955'], $hasil[PresisiPenuh::KUNCI]);

        // Tanpa jejak sama sekali pun jalan: jejaknya jadi hanya kunci ini.
        $this->assertSame(
            [PresisiPenuh::KUNCI => ['koreksi' => '0.7549999999999955']],
            PresisiPenuh::sisipkan(null, ['koreksi' => self::KOREKSI_MASTER]),
        );
    }

    public function test_tanpa_apa_pun_yang_dibekukan_jejak_dipulangkan_apa_adanya(): void
    {
        $this->assertNull(PresisiPenuh::sisipkan(null, ['koreksi' => 0.5]));
        $this->assertSame([], PresisiPenuh::sisipkan([], ['koreksi' => 0.5]));
        $this->assertSame(
            [['sumber' => 'a']],
            PresisiPenuh::sisipkan([['sumber' => 'a']], ['koreksi' => 0.5]),
        );
    }

    /**
     * Jalur simpan membekukan SEBELUM pembulatan; penyimpan model cuma melihat
     * nilai yang sudah dibulatkan dan tidak boleh menimpanya.
     */
    public function test_kunci_yang_sudah_ada_tidak_ditimpa(): void
    {
        $asli = [PresisiPenuh::KUNCI => ['koreksi' => '0.7549999999999955']];

        $this->assertSame(
            $asli,
            PresisiPenuh::sisipkan($asli, ['koreksi' => 0.7549999999999999, 'rata_rata' => 93.47999999999999]),
        );
    }

    public function test_baca_memakai_float_beku_bila_sepadan_dengan_kolom(): void
    {
        $baris = $this->baris(
            ['koreksi' => 0.755, 'titik_ukur' => 94.235],
            [PresisiPenuh::KUNCI => ['koreksi' => '0.7549999999999955', 'titik_ukur' => '94.23499999999999']],
        );

        $this->assertSame(0.7549999999999955, PresisiPenuh::baca($baris, 'koreksi'));
        $this->assertSame(94.23499999999999, PresisiPenuh::baca($baris, 'titik_ukur'));
        $this->assertSame('0,75', Angka::hasil(PresisiPenuh::baca($baris, 'koreksi'), 2));
    }

    /**
     * Baris yang lahir sebelum perubahan ini tidak punya kuncinya: dibaca dari
     * kolom seperti dulu, jadi angka cetak sertifikat lama tidak bergeser.
     */
    public function test_baris_tanpa_kunci_dibaca_dari_kolom(): void
    {
        $lama = $this->baris(['koreksi' => 0.755], [['sumber' => 'resolusi', 'nilai' => 0.003]]);
        $tanpaJejak = $this->baris(['koreksi' => 0.755]);

        $this->assertSame(0.755, PresisiPenuh::baca($lama, 'koreksi'));
        $this->assertSame(0.755, PresisiPenuh::baca($tanpaJejak, 'koreksi'));
        $this->assertSame('0,76', Angka::hasil(PresisiPenuh::baca($lama, 'koreksi'), 2), 'Perilaku lama dipertahankan.');
    }

    /**
     * Float beku hanya sah selama dia masih pra-bayangan kolom. Kolom yang
     * diubah belakangan (koreksi tangan, migrasi data) menang; kunci yang rusak
     * diabaikan, bukan dipercaya.
     */
    public function test_float_beku_yang_tidak_sepadan_dengan_kolom_diabaikan(): void
    {
        $kolomBerubah = $this->baris(
            ['koreksi' => 0.9],
            [PresisiPenuh::KUNCI => ['koreksi' => '0.7549999999999955']],
        );
        $this->assertSame(0.9, PresisiPenuh::baca($kolomBerubah, 'koreksi'));

        foreach (['bukan angka', '', 'NAN', 'INF', null, 0.7549999999999955, ['0.755']] as $rusak) {
            $baris = $this->baris(['koreksi' => 0.755], [PresisiPenuh::KUNCI => ['koreksi' => $rusak]]);
            $this->assertSame(0.755, PresisiPenuh::baca($baris, 'koreksi'), 'Kunci rusak: '.var_export($rusak, true));
        }

        $bukanPeta = $this->baris(['koreksi' => 0.755], [PresisiPenuh::KUNCI => 'rusak']);
        $this->assertSame(0.755, PresisiPenuh::baca($bukanPeta, 'koreksi'));
    }

    public function test_batas_sepadan_adalah_setengah_satuan_desimal_ke_delapan(): void
    {
        $kolom = 0.12345679;

        // Float asli yang mana pun yang `round(…, 8)`-nya = kolom ada di dalam batas.
        foreach ([0.123456785000001, 0.123456794999999, 0.1234567900000001] as $asli) {
            $this->assertSame($kolom, round($asli, 8), 'Prasyarat: pra-bayangan kolom.');
            $baris = $this->baris(
                ['koreksi' => $kolom],
                [PresisiPenuh::KUNCI => ['koreksi' => AngkaFloatEksak::kodekan($asli)]],
            );
            $this->assertEqualsWithDelta($asli, PresisiPenuh::baca($baris, 'koreksi'), 1e-18);
        }

        // Satu satuan ke-8 jauhnya BUKAN pra-bayangan kolom.
        $jauh = $this->baris(['koreksi' => $kolom], [PresisiPenuh::KUNCI => ['koreksi' => '0.12345680']]);
        $this->assertSame($kolom, PresisiPenuh::baca($jauh, 'koreksi'));
    }

    /**
     * SAPUAN seri, sama dengan `tie2.php` audit 16 Nov: koreksi = rata-rata dua
     * bacaan standar yang jatuh persis di titik seri − bacaan UUT.
     *
     * Dua hitungan: (a) tanpa pembekuan — kolom 8 desimal saja — sekitar separuh
     * kasus tercetak lain dari master; (b) dengan pembekuan lewat jalur yang
     * dipakai penyimpan — nol selisih. (a) menjaga sapuan ini punya gigi: kalau
     * suatu hari (a) jadi nol, sapuannya sendiri yang berhenti mengukur.
     */
    public function test_sapuan_seri_tercetak_sama_dengan_nilai_double_master(): void
    {
        $total = 0;
        $bedaTanpaPembekuan = 0;
        $bedaDenganPembekuan = 0;
        $contoh = [];

        for ($i = 0; $i < 4000; $i += 5) {
            $ac = 2000 + $i * 2;
            $a = $ac / 100;
            $b = ($ac + 1) / 100;
            $standar = ($a + $b) / 2;                      // jatuh di x,xx5

            for ($geser = -9; $geser <= 9; $geser++) {
                $uut = round($a + $geser / 100, 2);
                $koreksi = $standar - $uut;                // double seperti sel Excel
                $disimpan = round($koreksi, 8);            // yang masuk kolom decimal(20,8)

                $master = Angka::hasil($koreksi, 2);
                $total++;

                if (Angka::hasil($disimpan, 2) !== $master) {
                    $bedaTanpaPembekuan++;
                }

                $baris = $this->baris(
                    ['koreksi' => $disimpan],
                    PresisiPenuh::sisipkan(null, ['koreksi' => $koreksi]),
                );
                $dibaca = Angka::hasil(PresisiPenuh::baca($baris, 'koreksi'), 2);

                if ($dibaca !== $master) {
                    $bedaDenganPembekuan++;
                    $contoh[] = sprintf('std=%.3f uut=%.2f k=%.17g master=%s dibaca=%s', $standar, $uut, $koreksi, $master, $dibaca);
                }
            }
        }

        $this->assertSame(15200, $total);
        $this->assertGreaterThan(
            0.3 * $total,
            $bedaTanpaPembekuan,
            'Sapuan tanpa gigi: tanpa pembekuan harusnya sekitar separuh kasus seri tercetak lain dari master.',
        );
        $this->assertSame(0, $bedaDenganPembekuan, "Cetak beda dari master di titik seri:\n".implode("\n", array_slice($contoh, 0, 5)));
    }

    /**
     * BATAS toleransi pra-bayangan, diukur di sekitar nol supaya selisihnya
     * persis nilai beku itu sendiri (tanpa derau pengurangan): tepat 5,5e-9
     * DITERIMA, sedikit di atasnya DITOLAK. Tanpa tes ini toleransi yang
     * dilonggarkan sampai 9,5e-9 (atau 5e-8) lolos semua tes lain — dan
     * kunci yang usang/rusak mulai dipercaya.
     */
    public function test_batas_toleransi_tepat_5_5e_9_diterima_dan_di_atasnya_ditolak(): void
    {
        foreach ([0.0, 1e-12, 5.4999999e-9, 5.5e-9, -5.5e-9] as $asli) {
            $baris = $this->baris(['koreksi' => 0.0], [PresisiPenuh::KUNCI => ['koreksi' => AngkaFloatEksak::kodekan($asli)]]);
            $this->assertSame($asli, PresisiPenuh::baca($baris, 'koreksi'), "Selisih {$asli} harusnya diterima.");
        }

        foreach ([5.5000001e-9, 5.6e-9, 6e-9, 9.5e-9, 5e-8, -5.5000001e-9, -9.5e-9] as $asli) {
            $baris = $this->baris(['koreksi' => 0.0], [PresisiPenuh::KUNCI => ['koreksi' => AngkaFloatEksak::kodekan($asli)]]);
            $this->assertSame(0.0, PresisiPenuh::baca($baris, 'koreksi'), "Selisih {$asli} harusnya ditolak (kolom menang).");
        }
    }

    /**
     * Batas yang DIKETAHUI: untuk |x| ≳ 1e6 kunci beku ditolak DIAM-DIAM.
     *
     * Penyimpan memberi kolom teks 14 angka penting (PDO), jadi di 1e6 ke atas
     * kolom bergeser lebih dari 5,5e-9 dari float aslinya: bukan lagi
     * pra-bayangan, kunci tidak dipercaya, angka dibaca dari kolom — persis
     * perilaku sebelum PR ini (tidak lebih buruk). Titik seri x,xx5 di
     * magnitudo itu tidak diselamatkan; besaran sebesar itu juga tidak
     * ditemukan di alat yang ada (gaya UTM puluhan ribu kgf paling besar).
     * Tes ini mengunci perilakunya supaya perubahan toleransi/pembekuan tidak
     * menggesernya diam-diam.
     */
    public function test_nilai_sebesar_sejuta_menolak_kunci_diam_diam_dan_jatuh_ke_perilaku_lama(): void
    {
        foreach ([1234567.8912345678, 98765432.123456789, -1234567.8912345678] as $asli) {
            $kolom = round((float) sprintf('%.14G', $asli), 8);   // teks PDO 14 angka penting → decimal(20,8)

            $this->assertGreaterThan(5.5e-9, abs($asli - $kolom), 'Prasyarat: kolom bergeser di luar toleransi.');

            $baris = $this->baris(['koreksi' => $kolom], [PresisiPenuh::KUNCI => ['koreksi' => AngkaFloatEksak::kodekan($asli)]]);
            $this->assertSame($kolom, PresisiPenuh::baca($baris, 'koreksi'), "{$asli}: kunci harusnya ditolak, kolom menang.");
        }

        // Di bawah ~1e5 teks 14 angka penting masih menyisakan ≥ 9 desimal: kunci sah.
        foreach ([12345.678912345678, 0.7549999999999955, 94.23499999999999] as $asli) {
            $kolom = round((float) sprintf('%.14G', $asli), 8);
            $baris = $this->baris(['koreksi' => $kolom], [PresisiPenuh::KUNCI => ['koreksi' => AngkaFloatEksak::kodekan($asli)]]);
            $this->assertSame($asli, PresisiPenuh::baca($baris, 'koreksi'), "{$asli}: kunci harusnya diterima.");
        }
    }

    /**
     * Hook `save()` memanggil `sisipkan` dengan jejak apa adanya dari penulis:
     * apa pun yang bukan daftar/peta dipulangkan tanpa dilempar.
     */
    public function test_sisipkan_tidak_melempar_untuk_jejak_bukan_array(): void
    {
        $nilai = ['koreksi' => self::KOREKSI_MASTER];

        foreach (['[{"sumber":"a"}]', '', 'bukan json', 42, 4.5, true] as $bukanArray) {
            $this->assertSame($bukanArray, PresisiPenuh::sisipkan($bukanArray, $nilai));
        }

        $this->assertNull(PresisiPenuh::sisipkan(null, ['koreksi' => 0.5]));
    }

    /** Cast kolom: kunci beku tidak terbaca, tetapi ikut tersimpan ulang. */
    public function test_cast_menyembunyikan_kunci_beku_dan_simpan_ulang_mempertahankannya(): void
    {
        $mentah = '{"0":{"sumber":"a"},"1":{"sumber":"b"},"presisi_penuh":{"koreksi":"0.7549999999999955"}}';
        $baris = new UncertaintyCalculation;
        $baris->setRawAttributes(['type_b_components' => $mentah], true);

        $this->assertSame([['sumber' => 'a'], ['sumber' => 'b']], $baris->type_b_components);
        $this->assertTrue(array_is_list($baris->type_b_components));
        $this->assertSame(['koreksi' => '0.7549999999999955'], PresisiPenuh::petaBekuBaris($baris));
        $this->assertSame($mentah, $baris->getAttributes()['type_b_components'], 'Nilai mentah atribut tidak disentuh membaca.');

        // Penulis yang hanya melihat daftar bersih menambah satu entri.
        $baris->type_b_components = [...$baris->type_b_components, ['sumber' => 'c']];

        $this->assertCount(3, $baris->type_b_components);
        $this->assertSame(['koreksi' => '0.7549999999999955'], PresisiPenuh::petaBekuBaris($baris), 'Simpan ulang menghapus kunci beku.');

        // Penulis yang membawa kuncinya sendiri (jalur simpan sesi) menang.
        $baris->type_b_components = [['sumber' => 'x'], PresisiPenuh::KUNCI => ['koreksi' => '0.5']];
        $this->assertSame(['koreksi' => '0.5'], PresisiPenuh::petaBekuBaris($baris));
        $this->assertSame([['sumber' => 'x']], $baris->type_b_components);

        // `null` = jejak dihapus, kunci ikut.
        $baris->type_b_components = null;
        $this->assertNull($baris->type_b_components);
        $this->assertNull(PresisiPenuh::petaBekuBaris($baris));
    }

    /** Tanpa kunci beku cast ini sama dengan `array`: nilai bukan array dienkode & dibaca apa adanya. */
    public function test_cast_tanpa_kunci_beku_berperilaku_seperti_array_bawaan(): void
    {
        $baris = new UncertaintyCalculation;

        foreach ([[['sumber' => 'a']], [], 'teks', 42, [0.25, 'x' => ['y' => 1]]] as $nilai) {
            $baris->type_b_components = $nilai;
            $this->assertSame($nilai, $baris->type_b_components);
            $this->assertSame(json_encode($nilai), $baris->getAttributes()['type_b_components']);
        }

        $this->expectException(JsonEncodingException::class);
        $baris->type_b_components = [['nilai' => INF]];
    }
}
