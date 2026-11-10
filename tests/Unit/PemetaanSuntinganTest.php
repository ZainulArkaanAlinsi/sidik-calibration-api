<?php

namespace Tests\Unit;

use App\Models\WorkbookMaster;
use App\Services\Studio\Draf\PemetaanSuntingan;
use App\Services\Studio\PetaSel\Alamat;
use App\Services\Studio\PetaSel\JalurIsi;
use App\Services\Studio\PetaSel\PemeriksaMaster;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\WorkbookScalarEditPreparer;
use App\Support\JsonKanonik;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pemetaan sunting dari peta sel: micrometer ASLI (`database/data/peta-sel/
 * micrometer.json`) lawan peta sintetis untuk tiap aturan penyaring. Tanpa DB.
 */
class PemetaanSuntinganTest extends TestCase
{
    /** Hitungan tangan dari berkas peta (DATABASE!T5..T8, angka, bulat 10, tanpa kunci lain). */
    private const MICROMETER = [
        ['sel' => 'DATABASE!T5', 'jalur' => 'cmc[kode=A].u95_um', 'tipe' => 'angka', 'bulat' => 10],
        ['sel' => 'DATABASE!T6', 'jalur' => 'cmc[kode=B].u95_um', 'tipe' => 'angka', 'bulat' => 10],
        ['sel' => 'DATABASE!T7', 'jalur' => 'cmc[kode=C].u95_um', 'tipe' => 'angka', 'bulat' => 10],
        ['sel' => 'DATABASE!T8', 'jalur' => 'cmc[kode=D].u95_um', 'tipe' => 'angka', 'bulat' => 10],
    ];

    private function peta(): PetaSel
    {
        return PetaSel::untuk('micrometer') ?? $this->fail('peta micrometer tidak ada');
    }

    private function jsonDasar(): array
    {
        return json_decode((string) file_get_contents(base_path('database/data/tabel-standar-micrometer.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function preparer(): WorkbookScalarEditPreparer
    {
        return new WorkbookScalarEditPreparer(new PemeriksaMaster);
    }

    // ---- peta micrometer asli -------------------------------------------------

    public function test_micrometer_asli_empat_entri_angka_bulat_10_persis_empat_kunci(): void
    {
        $peta = $this->peta();
        $peta->isi['varian'] ?? $this->fail('peta micrometer harus ber-varian');

        $hasil = PemetaanSuntingan::untukNama($peta, 'Master_Olah_Data_Micrometer_0-25mm');

        $this->assertSame(self::MICROMETER, $hasil);

        foreach ($hasil as $m) {
            $this->assertSame(['sel', 'jalur', 'tipe', 'bulat'], array_keys($m));
            $this->assertSame(10, $m['bulat']);
        }
    }

    public function test_tipe_selain_angka_polos_tidak_ikut(): void
    {
        $sel = array_column(PemetaanSuntingan::untukNama($this->peta(), 'Master Olah Data_Micrometer 25-50mm.xlsm'), 'sel');

        // teks, tanggal, peta_angka, daftar_angka: semua sel/rentang ekstrak non-angka di peta.
        foreach ([
            'DATABASE!S5', 'DATABASE!S6', 'DATABASE!S7', 'DATABASE!S8', // label cmc (teks)
            'DATABASE!S13', 'DATABASE!T13', 'DATABASE!U13', 'DATABASE!V13', // standar (teks)
            'DATABASE!W13', // tanggal
            'Standar_GB!Q10:Q132', 'Standar_GB!R10:R132', // peta_angka
            'PERHITUNGAN!H22:M22', 'PERHITUNGAN!C31:E33', // daftar_angka
        ] as $tidakBoleh) {
            $this->assertNotContains($tidakBoleh, $sel, $tidakBoleh);
        }

        $jumlahEkstrakPeta = count($this->peta()->isi['ekstrak']);
        $this->assertSame(16, $jumlahEkstrakPeta, 'peta berubah: hitung ulang entri sah micrometer dengan tangan');
        $this->assertCount(4, $sel);
    }

    /** @return iterable<string, array{string, string}> */
    public static function empatPita(): iterable
    {
        yield 'A nama asli xlsm' => ['Master Olah Data_Micrometer 0-25mm.xlsm', 'A'];
        yield 'B nama asli xlsm' => ['Master Olah Data_Micrometer 25-50mm.xlsm', 'B'];
        yield 'C nama asli xlsm' => ['Master Olah Data_Micrometer 50-75mm.xlsm', 'C'];
        yield 'D nama asli xlsm' => ['Master Olah Data_Micrometer 75-100mm.xlsm', 'D'];
        yield 'A nama csv' => ['Master_Olah_Data_Micrometer_0-25mm', 'A'];
        yield 'B nama csv' => ['Master_Olah_Data_Micrometer_25-50mm', 'B'];
        yield 'C nama csv' => ['Master_Olah_Data_Micrometer_50-75mm', 'C'];
        yield 'D nama csv' => ['Master_Olah_Data_Micrometer_75-100mm', 'D'];
    }

    #[DataProvider('empatPita')]
    public function test_varian_dari_nama_sama_dengan_jalur_impor_dan_keempat_pita_memuat_keempat_entri(string $nama, string $varianHarapan): void
    {
        $peta = $this->peta();

        $this->assertSame($varianHarapan, PemetaanSuntingan::varian($peta, $nama));

        // Jalur impor: PemeriksaMaster::varian() -> PetaSel::varianDariPola (privat; adu hasilnya).
        $this->assertSame($varianHarapan, PetaSel::varianDariPola($peta->isi['varian']['pola'], $nama));

        // Tak satu pun entri u95_um micrometer ber-`varian`/`{varian}`: SEMUA pita
        // berlaku di SETIAP workbook (tabel DATABASE!S5:T8 ada di keempat master).
        $this->assertSame(self::MICROMETER, PemetaanSuntingan::untukNama($peta, $nama));
    }

    public function test_untuk_master_memakai_nama_workbook_master(): void
    {
        $w = new WorkbookMaster(['nama' => 'Master Olah Data_Micrometer 75-100mm.xlsm']);

        $this->assertSame(self::MICROMETER, PemetaanSuntingan::untukMaster($this->peta(), $w));
        $this->assertSame([], PemetaanSuntingan::untukMaster($this->peta(), new WorkbookMaster(['nama' => 'Dial Indicator 0-10'])));
    }

    public function test_nama_tak_dikenal_atau_ambigu_tidak_menghasilkan_apa_pun(): void
    {
        foreach (['Master Micrometer Baru', '', 'Micrometer 0-25mm dan 25-50mm'] as $nama) {
            $this->assertNull(PemetaanSuntingan::varian($this->peta(), $nama), $nama);
            $this->assertSame([], PemetaanSuntingan::untukNama($this->peta(), $nama), $nama);
        }
    }

    public function test_pemetaan_micrometer_lolos_preparer_ubah_satu_sel_hash_daun_lain_identik(): void
    {
        $base = $this->jsonDasar();
        $teksKanonik = JsonKanonik::dariTeks((string) file_get_contents(base_path('database/data/tabel-standar-micrometer.json')));
        $this->assertSame($teksKanonik, JsonKanonik::serialisasi($base), 'dasar tidak stabil kanonik lewat decode assoc');

        $hasil = $this->preparer()->prepare($base, PemetaanSuntingan::untukNama($this->peta(), 'Master_Olah_Data_Micrometer_50-75mm'), [
            ['sel' => 'DATABASE!T6', 'nilai' => '0.95'],
        ]);

        $this->assertSame([['sel' => 'DATABASE!T6', 'jalur' => 'cmc[kode=B].u95_um', 'nilai_lama' => 0.87, 'nilai_baru' => 0.95]], $hasil['perubahan']);

        $sesudah = $hasil['isi'];
        $this->assertSame(0.95, JalurIsi::ambil($sesudah, 'cmc[kode=B].u95_um'));

        // Hash tiap kunci puncak, tiap baris cmc, dan tiap bidang baris cmc yang bukan u95_um B: identik.
        foreach ($base as $kunci => $nilai) {
            if ($kunci === 'cmc') {
                continue;
            }
            $this->assertSame(JsonKanonik::sha256(JsonKanonik::serialisasi($nilai)), JsonKanonik::sha256(JsonKanonik::serialisasi($sesudah[$kunci])), "kunci {$kunci}");
        }
        $this->assertSame(array_keys($base), array_keys($sesudah));
        $this->assertCount(4, $base['cmc']);

        foreach ($base['cmc'] as $i => $baris) {
            foreach ($baris as $bidang => $nilai) {
                $lama = JsonKanonik::sha256(JsonKanonik::serialisasi($nilai));
                $baru = JsonKanonik::sha256(JsonKanonik::serialisasi($sesudah['cmc'][$i][$bidang]));

                if ($baris['kode'] === 'B' && $bidang === 'u95_um') {
                    $this->assertNotSame($lama, $baru);

                    continue;
                }

                $this->assertSame($lama, $baru, "cmc[{$i}].{$bidang}");
            }
        }
    }

    public function test_keempat_sel_sekaligus_dan_noop_menjaga_tipe(): void
    {
        $base = $this->jsonDasar();
        $m = PemetaanSuntingan::untukNama($this->peta(), 'Master_Olah_Data_Micrometer_0-25mm');

        $hasil = $this->preparer()->prepare($base, $m, [
            ['sel' => 'DATABASE!T5', 'nilai' => '0.5'], ['sel' => 'DATABASE!T6', 'nilai' => '0.6'],
            ['sel' => 'DATABASE!T7', 'nilai' => '0.7'], ['sel' => 'DATABASE!T8', 'nilai' => '0.91'], // T8 = nilai sekarang
        ]);

        $this->assertSame(['DATABASE!T5', 'DATABASE!T6', 'DATABASE!T7'], array_column($hasil['perubahan'], 'sel'));
        $this->assertSame(0.91, JalurIsi::ambil($hasil['isi'], 'cmc[kode=D].u95_um'));
    }

    public function test_sel_di_luar_pemetaan_ditolak_preparer_dan_basis_tak_berubah(): void
    {
        $base = $this->jsonDasar();
        $sebelum = JsonKanonik::serialisasi($base);
        $m = PemetaanSuntingan::untukNama($this->peta(), 'Master_Olah_Data_Micrometer_0-25mm');

        foreach (['DATABASE!S5', 'DATABASE!T13', 'PERHITUNGAN!H22', 'DATABASE!T9'] as $sel) {
            try {
                $this->preparer()->prepare($base, $m, [['sel' => 'DATABASE!T5', 'nilai' => '0.5'], ['sel' => $sel, 'nilai' => '1']]);
                $this->fail("{$sel} seharusnya ditolak");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Scalar edit address is not in the server mapping subset.', $e->getMessage(), $sel);
            }
        }

        $this->assertSame($sebelum, JsonKanonik::serialisasi($base));
    }

    /**
     * Batas persis KELAS INI. `Alamat::urai` menerima nama lembar apa saja
     * sebelum `!` terakhir, jadi alamat valid sepanjang tepat 256 byte ada
     * (`LLL…L!A1`); begitu pula jalur satu segmen 512 byte. Yang diuji hanya
     * panjang: kedua sisi batas sama-sama lolos parser ketat.
     */
    public function test_batas_persis_alamat_256_diterima_257_ditolak_jalur_512_diterima_513_ditolak(): void
    {
        $this->assertSame(256, WorkbookScalarEditPreparer::MAX_ADDRESS_BYTES);
        $this->assertSame(512, WorkbookScalarEditPreparer::MAX_PATH_BYTES);

        $sel256 = str_repeat('L', 253).'!A1';
        $sel257 = str_repeat('L', 254).'!A1';
        $jalur512 = str_repeat('a', 512);
        $jalur513 = str_repeat('a', 513);

        $this->assertSame(256, strlen($sel256));
        $this->assertSame(257, strlen($sel257));
        $this->assertSame(512, strlen($jalur512));
        $this->assertSame(513, strlen($jalur513));

        foreach ([$sel256, $sel257] as $sel) {
            $this->assertNotNull(Alamat::urai($sel), 'premis: alamat sah menurut parser');
        }
        foreach ([$jalur512, $jalur513] as $jalur) {
            $this->assertNotNull(JalurIsi::urai($jalur), 'premis: jalur sah menurut parser');
        }

        $this->assertSame(
            [['sel' => $sel256, 'jalur' => 'x.y', 'tipe' => 'angka', 'bulat' => 10]],
            PemetaanSuntingan::dariIsiPeta($this->peta1([$this->angka($sel256, 'x.y')]), 'n'),
        );
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($this->peta1([$this->angka($sel257, 'x.y')]), 'n'));

        $this->assertSame(
            [['sel' => 'S!A1', 'jalur' => $jalur512, 'tipe' => 'angka', 'bulat' => 10]],
            PemetaanSuntingan::dariIsiPeta($this->peta1([$this->angka('S!A1', $jalur512)]), 'n'),
        );
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($this->peta1([$this->angka('S!A1', $jalur513)]), 'n'));

        // Di batas persis, pemetaan hasil kami tetap diterima preparer (tidak melempar untuk seluruh gelombang).
        $pemetaan = PemetaanSuntingan::dariIsiPeta($this->peta1([$this->angka($sel256, $jalur512)]), 'n');
        $hasil = $this->preparer()->prepare([$jalur512 => 1.5], $pemetaan, [['sel' => $sel256, 'nilai' => '2']]);

        $this->assertSame([$jalur512 => 2.0], $hasil['isi']);
    }

    public function test_mutasi_peta_asli_satu_kunci_tambahan_mengeluarkan_hanya_entri_itu(): void
    {
        $isi = $this->peta()->isi;

        foreach ($isi['ekstrak'] as $i => $e) {
            if (($e['sel'] ?? null) === 'DATABASE!T5') {
                $isi['ekstrak'][$i]['bagi'] = 1000;
            }
        }

        $hasil = PemetaanSuntingan::dariIsiPeta($isi, 'Master_Olah_Data_Micrometer_0-25mm');

        $this->assertSame(['DATABASE!T6', 'DATABASE!T7', 'DATABASE!T8'], array_column($hasil, 'sel'));
    }

    // ---- peta sintetis: tiap aturan penyaring ---------------------------------

    private function angka(string $sel, string $jalur, array $tambahan = []): array
    {
        return ['jalur' => $jalur, 'tipe' => 'angka', 'sel' => $sel, 'bulat' => 10] + $tambahan;
    }

    private function peta1(array $ekstrak, ?array $varian = null): array
    {
        return ['ekstrak' => $ekstrak] + ($varian === null ? [] : ['varian' => $varian]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function entriDitolak(): iterable
    {
        $dasar = ['jalur' => 'a.b', 'tipe' => 'angka', 'sel' => 'S!A1', 'bulat' => 10];

        yield 'bagi' => [$dasar + ['bagi' => 1000]];
        yield 'angka_penting' => [$dasar + ['angka_penting' => 10]];
        yield 'nol_di_bawah' => [$dasar + ['nol_di_bawah' => 1e-9]];
        yield 'buang_akhiran' => [$dasar + ['buang_akhiran' => '*']];
        // Hanya kunci tambahan yang menolak (jalur tanpa {i}, jadi aturan {i} tidak ikut bekerja).
        yield 'ulang' => [$dasar + ['ulang' => 2, 'langkah_baris' => 1]];
        yield 'kunci bebas' => [$dasar + ['catatan' => 'x']];
        yield 'bulat 9' => [['bulat' => 9] + $dasar];
        yield 'bulat 0' => [['bulat' => 0] + $dasar];
        yield 'bulat float 10.0' => [['bulat' => 10.0] + $dasar];
        yield 'bulat teks' => [['bulat' => '10'] + $dasar];
        yield 'bulat hilang' => [['jalur' => 'a.b', 'tipe' => 'angka', 'sel' => 'S!A1']];
        yield 'sel hilang' => [['jalur' => 'a.b', 'tipe' => 'angka', 'bulat' => 10]];
        yield 'tipe bilangan_bulat' => [['tipe' => 'bilangan_bulat'] + $dasar];
        yield 'tipe teks' => [['tipe' => 'teks'] + $dasar];
        yield 'tipe tanggal' => [['tipe' => 'tanggal'] + $dasar];
        yield 'tipe angka_atau_teks' => [['tipe' => 'angka_atau_teks'] + $dasar];
        // Terisolasi: tanpa kunci tambahan, hanya aturan `tipe` yang bisa menolak.
        yield 'tipe peta_angka' => [['tipe' => 'peta_angka'] + $dasar];
        yield 'tipe daftar_angka' => [['tipe' => 'daftar_angka'] + $dasar];
        // Bentuk peta asli (ditolak oleh tipe DAN kunci tambahan).
        yield 'tipe peta_angka bentuk asli' => [['tipe' => 'peta_angka', 'kunci' => 'S!A1:A3', 'nilai' => 'S!B1:B3'] + $dasar];
        yield 'tipe daftar_angka bentuk asli' => [['tipe' => 'daftar_angka', 'rentang' => 'S!A1:B3'] + $dasar];
        yield 'sel rentang' => [['sel' => 'S!A1:A3'] + $dasar];
        yield 'sel tak sah' => [['sel' => 'A1'] + $dasar];
        yield 'sel bukan teks' => [['sel' => 5] + $dasar];
        yield 'sel kepanjangan' => [['sel' => str_repeat('L', 300).'!A1'] + $dasar];
        yield 'jalur tak sah' => [['jalur' => 'a..b'] + $dasar];
        yield 'jalur kepanjangan' => [['jalur' => str_repeat('a', 513)] + $dasar];
        yield 'jalur memakai i' => [['jalur' => 'a[{i}].b'] + $dasar];
        yield 'jalur varian tanpa varian' => [['jalur' => 'cmc[kode={varian}].b'] + $dasar];
    }

    /** @param  array<string, mixed>  $entri */
    #[DataProvider('entriDitolak')]
    public function test_entri_yang_menyimpang_dari_bentuk_persis_tidak_masuk(array $entri): void
    {
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($this->peta1([$entri]), 'apa saja'));
    }

    public function test_entri_sah_sintetis_masuk_persis_empat_kunci_urutan_peta(): void
    {
        $isi = $this->peta1([
            $this->angka('S!B2', 'x.dua'),
            ['jalur' => 'x.teks', 'tipe' => 'teks', 'sel' => 'S!C3'],
            $this->angka('S!A1', 'x.satu'),
        ]);

        $this->assertSame([
            ['sel' => 'S!B2', 'jalur' => 'x.dua', 'tipe' => 'angka', 'bulat' => 10],
            ['sel' => 'S!A1', 'jalur' => 'x.satu', 'tipe' => 'angka', 'bulat' => 10],
        ], PemetaanSuntingan::dariIsiPeta($isi, 'n'));
    }

    public function test_peta_tanpa_entri_sah_atau_bentuk_aneh_menghasilkan_daftar_kosong(): void
    {
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta([], 'n'));
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta(['ekstrak' => []], 'n'));
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta(['ekstrak' => 'x'], 'n'));
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta(['ekstrak' => ['bukan array', 5, null]], 'n'));
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta(['ekstrak' => ['a' => $this->angka('S!A1', 'x.y')]], 'n'));
    }

    public function test_filter_varian_butir_dan_jalur_ber_placeholder_diselesaikan_dengan_fungsi_peta(): void
    {
        $varian = ['sumber' => 'nama', 'pola' => ['A' => '(^| )0 ?25( |$)', 'B' => '(^| )25 ?50( |$)']];
        $isi = $this->peta1([
            $this->angka('S!A1', 'umum.nilai'),
            $this->angka('S!A2', 'cmc[kode={varian}].khusus'),
            $this->angka('S!A3', 'cuma.b', ['varian' => ['B']]),
            $this->angka('S!A4', 'cuma.a', ['varian' => ['A']]),
        ], $varian);

        $this->assertSame(
            [['S!A1', 'umum.nilai'], ['S!A2', 'cmc[kode=A].khusus'], ['S!A4', 'cuma.a']],
            array_map(static fn (array $m): array => [$m['sel'], $m['jalur']], PemetaanSuntingan::dariIsiPeta($isi, 'mikro 0-25')),
        );
        $this->assertSame(
            [['S!A1', 'umum.nilai'], ['S!A2', 'cmc[kode=B].khusus'], ['S!A3', 'cuma.b']],
            array_map(static fn (array $m): array => [$m['sel'], $m['jalur']], PemetaanSuntingan::dariIsiPeta($isi, 'mikro 25-50')),
        );

        // Kunci `varian` hanyalah penyaring: tidak bocor ke keluaran.
        foreach (PemetaanSuntingan::dariIsiPeta($isi, 'mikro 25-50') as $m) {
            $this->assertSame(['sel', 'jalur', 'tipe', 'bulat'], array_keys($m));
        }

        // Peta ber-varian + nama tak dikenal = KOSONG (bukan "butir umum saja").
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($isi, 'mikro 75-100'));
    }

    public function test_sel_dibaca_butir_lain_tidak_boleh_disunting_terpisah(): void
    {
        $dibagi = $this->peta1([$this->angka('S!A1', 'x.um'), $this->angka('S!A1', 'x.mm', ['bagi' => 1000]), $this->angka('S!A2', 'x.lain')]);
        $this->assertSame(['S!A2'], array_column(PemetaanSuntingan::dariIsiPeta($dibagi, 'n'), 'sel'));

        // Dua entri sah memakai sel yang sama: ambigu, keduanya keluar.
        $kembar = $this->peta1([$this->angka('S!A1', 'x.satu'), $this->angka('S!A1', 'x.dua'), $this->angka('S!A2', 'x.tiga')]);
        $this->assertSame(['S!A2'], array_column(PemetaanSuntingan::dariIsiPeta($kembar, 'n'), 'sel'));

        // Sel jatuh di dalam rentang butir lain (daftar_angka), juga di lembar yang sama saja.
        $dalamRentang = $this->peta1([
            $this->angka('S!B2', 'x.satu'),
            $this->angka('T!B2', 'x.dua'),
            ['jalur' => 'x.daftar', 'tipe' => 'daftar_angka', 'rentang' => 'S!A1:C3', 'bulat' => 10],
        ]);
        $this->assertSame(['T!B2'], array_column(PemetaanSuntingan::dariIsiPeta($dalamRentang, 'n'), 'sel'));

        // Rentang berulang (`ulang` + `langkah_baris`): baris ke-3 (3 + 2*1 = baris 5) kena.
        $berulang = $this->peta1([
            $this->angka('S!A5', 'y.satu'),
            $this->angka('S!A9', 'y.dua'),
            ['jalur' => 'x[{i}].d', 'tipe' => 'daftar_angka', 'rentang' => 'S!A3:A3', 'ulang' => 3, 'langkah_baris' => 1, 'bulat' => 10],
        ]);
        $this->assertSame(['S!A9'], array_column(PemetaanSuntingan::dariIsiPeta($berulang, 'n'), 'sel'));

        // Kolom daftar_objek & peta_angka (kunci/nilai).
        $objek = $this->peta1([
            $this->angka('S!B5', 'x.satu'),
            $this->angka('S!D5', 'x.dua'),
            $this->angka('S!E5', 'x.empat'), // di kolom `nilai` peta_angka
            $this->angka('S!F5', 'x.tiga'),
            ['jalur' => 'x.o', 'tipe' => 'daftar_objek', 'kunci' => 'k', 'kolom' => ['k' => 'S!A1:A9', 'v' => ['rentang' => 'S!B1:B9', 'tipe' => 'angka'], 't' => ['nilai_tetap' => 1]]],
            ['jalur' => 'x.p', 'tipe' => 'peta_angka', 'kunci' => 'S!D1:D9', 'nilai' => 'S!E1:E9'],
        ]);
        $this->assertSame(['S!F5'], array_column(PemetaanSuntingan::dariIsiPeta($objek, 'n'), 'sel'));

        // daftar_kunci: `rentang` berbentuk LIST rentang satu kolom; sel di antara dua rentang tetap bebas.
        $daftarKunci = $this->peta1([
            $this->angka('S!H2', 'x.satu'),
            $this->angka('S!H5', 'x.dua'),
            $this->angka('S!H8', 'x.tiga'),
            ['jalur' => 'x.k', 'tipe' => 'daftar_kunci', 'rentang' => ['S!H1:H3', 'S!H7:H9']],
        ]);
        $this->assertSame(['S!H5'], array_column(PemetaanSuntingan::dariIsiPeta($daftarKunci, 'n'), 'sel'));
    }

    /** @return iterable<string, array{string}> */
    public static function bentukAlamatSamaDenganA1(): iterable
    {
        yield 'persis' => ['S!A1'];
        yield 'rentang satu sel' => ['S!A1:A1'];
        yield 'rentang yang memuatnya' => ['S!A1:C3'];
        yield 'dolar mutlak' => ['S!$A$1'];
        yield 'huruf kecil sel' => ['S!a1'];
        yield 'huruf kecil lembar' => ['s!A1'];
        yield 'lembar dikutip' => ["'S'!A1"];
        yield 'semuanya' => ["'s'!\$a\$1"];
    }

    #[DataProvider('bentukAlamatSamaDenganA1')]
    public function test_sel_yang_juga_jangkar_tidak_boleh_disunting_apa_pun_bentuk_alamatnya(string $alamatJangkar): void
    {
        $isi = $this->peta1([$this->angka('S!A1', 'x.satu'), $this->angka('S!A9', 'x.dua')])
            + ['jangkar' => [['sel' => $alamatJangkar, 'teks' => 'Label']]];

        $this->assertSame(['S!A9'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'n'), 'sel'), $alamatJangkar);
    }

    public function test_sel_di_lembar_lain_atau_di_luar_rentang_jangkar_tetap_bebas(): void
    {
        $isi = $this->peta1([$this->angka('S!A2', 'x.satu'), $this->angka('T!A1', 'x.dua'), $this->angka('S!B1', 'x.tiga')])
            + ['jangkar' => [['sel' => 'S!A1', 'teks' => 'Label'], ['sel' => 'U!A2', 'teks' => 'Lain']]];

        $this->assertSame(['S!A2', 'T!A1', 'S!B1'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'n'), 'sel'));
    }

    public function test_jangkar_varian_lain_tidak_menghalangi_tetapi_jangkar_varian_ini_menghalangi(): void
    {
        $varian = ['sumber' => 'nama', 'pola' => ['A' => '(^| )a( |$)', 'B' => '(^| )b( |$)']];
        $isi = $this->peta1([$this->angka('S!A1', 'x.satu'), $this->angka('S!A2', 'x.dua')], $varian)
            + ['jangkar' => [['sel' => 'S!A1', 'teks' => 'Label B', 'varian' => ['B']], ['sel' => 'S!A2', 'teks' => 'Label A', 'varian' => ['A']]]];

        $this->assertSame(['S!A1'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'm a'), 'sel'));
        $this->assertSame(['S!A2'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'm b'), 'sel'));
    }

    public function test_sel_yang_juga_cek_termasuk_geseran_ulang_tidak_boleh_disunting(): void
    {
        $isi = $this->peta1([$this->angka('S!H1', 'x.a'), $this->angka('S!H4', 'x.b'), $this->angka('S!H7', 'x.c'), $this->angka('S!H5', 'x.d')])
            + ['cek' => [['tipe' => 'selisih_maks', 'sel' => 'S!H1', 'banding' => 'x.bandingan[{i}]', 'toleransi' => 0.06, 'ulang' => 3, 'langkah_baris' => 3]]];

        // Cek membaca H1, H4, H7; H5 bebas.
        $this->assertSame(['S!H5'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'n'), 'sel'));
    }

    public function test_sel_yang_juga_ekstrak_paket_lain_tidak_boleh_disunting_tetapi_jalurnya_tidak_bentrok(): void
    {
        $isi = $this->peta1([$this->angka('S!A1', 'x.satu'), $this->angka('S!C2', 'x.dua'), $this->angka('S!A9', 'p.q')])
            + ['paket_lain' => [['paket' => 'lain', 'ekstrak' => [
                $this->angka('S!A1', 'p.q'),
                ['jalur' => 'p.daftar', 'tipe' => 'daftar_angka', 'rentang' => 'S!C1:C3', 'bulat' => 10],
            ]]]];

        // A1 dan C2 dikuasai paket lain; A9 memakai jalur `p.q` yang sama dengan jalur paket lain
        // tapi itu data paket LAIN, jadi tidak bentrok.
        $this->assertSame(['S!A9'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'n'), 'sel'));
    }

    public function test_jalur_banding_cek_dan_butir_lain_yang_bertumpang_tindih_mengeluarkan_entri(): void
    {
        $isi = $this->peta1([$this->angka('S!A1', 'x.banding'), $this->angka('S!A2', 'x.bandingan[1]'), $this->angka('S!A3', 'x.bebas')])
            + ['cek' => [
                ['tipe' => 'selisih_maks', 'sel' => 'S!Z1', 'banding' => 'x.banding', 'toleransi' => 1],
                ['tipe' => 'selisih_maks', 'sel' => 'S!Z2', 'banding' => 'x.bandingan[{i}]', 'toleransi' => 1, 'ulang' => 2, 'langkah_baris' => 1],
            ]];

        $this->assertSame(['S!A3'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'n'), 'sel'));
    }

    public function test_alamat_tak_terurai_pada_butir_lain_mengeluarkan_semua_kandidat(): void
    {
        $jangkarRusak = $this->peta1([$this->angka('S!A1', 'x.satu')]) + ['jangkar' => [['sel' => 'tanpa-lembar', 'teks' => 'x']]];
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($jangkarRusak, 'n'));

        $ekstrakRusak = $this->peta1([$this->angka('S!A1', 'x.satu'), ['jalur' => 'x.t', 'tipe' => 'teks', 'sel' => 'S!B1:B']]);
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($ekstrakRusak, 'n'));
    }

    public function test_ulang_di_atas_batas_tidak_dipotong_diam_diam_semua_kandidat_keluar(): void
    {
        // `ulang` 20000 menggeser baris sampai 20000: S!A20000 dikuasai butir daftar_angka itu.
        // Dulu geseran dipotong di 10000 -> S!A20000 lolos sebagai "bebas".
        $butir = ['jalur' => 'x[{i}].d', 'tipe' => 'daftar_angka', 'rentang' => 'S!A1:A1', 'ulang' => 20000, 'langkah_baris' => 1, 'bulat' => 10];
        $isi = $this->peta1([$this->angka('S!A20000', 'y.satu'), $this->angka('S!Z1', 'y.dua'), $butir]);

        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($isi, 'n'));

        // Tepat di batas masih dijabarkan penuh: yang di luar jangkauan tetap bebas, yang kena tetap keluar.
        $dibatas = $this->peta1([$this->angka('S!A10000', 'y.satu'), $this->angka('S!A10001', 'y.dua'), $butir]);
        $dibatas['ekstrak'][2]['ulang'] = 10000;
        $this->assertSame(['S!A10001'], array_column(PemetaanSuntingan::dariIsiPeta($dibatas, 'n'), 'sel'));

        // Butir cek TANPA {i} di jalurnya: hanya geseran alamat yang menjaga (H1 + 10000 = H10001).
        $cek = $this->peta1([$this->angka('S!H10001', 'y.satu'), $this->angka('S!H10002', 'y.dua')])
            + ['cek' => [['tipe' => 'selisih_maks', 'sel' => 'S!H1', 'banding' => 'q.b', 'toleransi' => 1, 'ulang' => 10001, 'langkah_baris' => 1]]];
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($cek, 'n'));

        $cek['cek'][0]['ulang'] = 10000;
        $this->assertSame(['S!H10001', 'S!H10002'], array_column(PemetaanSuntingan::dariIsiPeta($cek, 'n'), 'sel'), 'ulang tepat di batas dijabarkan penuh (H1..H10000)');
    }

    public function test_alamat_dan_jalur_butir_ulang_terlalu_besar_masing_masing_fail_closed(): void
    {
        $butir = ['jalur' => 'x[{i}].d', 'tipe' => 'daftar_angka', 'rentang' => 'S!A1:A1', 'ulang' => 10001, 'langkah_baris' => 1, 'bulat' => 10];
        $alamat = new \ReflectionMethod(PemetaanSuntingan::class, 'alamatButir');
        $jalur = new \ReflectionMethod(PemetaanSuntingan::class, 'jalurButir');

        $this->assertTrue($alamat->invoke(null, $butir)['rusak']);
        $this->assertFalse($alamat->invoke(null, ['ulang' => 10000] + $butir)['rusak']);
        $this->assertCount(10000, $alamat->invoke(null, ['ulang' => 10000] + $butir)['alamat']);

        // Jalur: satu penanda yang TAK TERURAI (jalurBertumpang -> bentrok), bukan 10000 jalur terpotong.
        $hasil = $jalur->invoke(null, $butir, null, 'jalur');
        $this->assertCount(1, $hasil);
        $this->assertNull(JalurIsi::urai($hasil[0]));
        $this->assertCount(10000, $jalur->invoke(null, ['ulang' => 10000] + $butir, null, 'jalur'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function pasanganJalur(): iterable
    {
        // [jalur kandidat, jalur butir lain, bentrok?]
        yield 'sama persis' => ['a.b', 'a.b', true];
        yield 'lain adalah awalan segmen kandidat' => ['a.b', 'a', true];
        yield 'kandidat adalah awalan segmen lain' => ['a', 'a.b', true];
        yield 'awalan dalam' => ['a.b.c', 'a.b', true];
        yield 'indeks vs seluruh list' => ['a[0]', 'a', true];
        yield 'seluruh list vs indeks' => ['a', 'a[0]', true];
        yield 'elemen kode vs seluruh list' => ['cmc[kode=A].u', 'cmc', true];
        yield 'elemen sama kode, daun lebih dalam' => ['cmc[kode=A].u', 'cmc[kode=A]', true];
        yield 'elemen sama indeks, daun lebih dalam' => ['cmc[2].u', 'cmc[2]', true];
        yield 'kode vs indeks (alias, fail-closed)' => ['cmc[kode=A].u', 'cmc[0].u', true];
        yield 'indeks vs kode (alias, fail-closed)' => ['cmc[0].u', 'cmc[kode=A].u', true];
        yield 'kode vs indeks beda daun, tetap alias di elemen' => ['cmc[kode=A].u', 'cmc[1]', true];
        yield 'kode berbeda' => ['cmc[kode=A].u', 'cmc[kode=B].u', false];
        yield 'indeks berbeda' => ['cmc[0].u', 'cmc[1].u', false];
        yield 'saudara' => ['a.b', 'a.c', false];
        yield 'awalan string bukan awalan segmen' => ['ab', 'a', false];
        yield 'awalan string bukan segmen dalam' => ['a.bc', 'a.b', false];
        yield 'kunci beda huruf besar-kecil' => ['a.B', 'a.b', false];
    }

    #[DataProvider('pasanganJalur')]
    public function test_bentrok_jalur_awalan_segmen_dan_alias_kode_indeks(string $jalurKandidat, string $jalurLain, bool $bentrok): void
    {
        $isi = $this->peta1([$this->angka('S!A1', $jalurKandidat), ['jalur' => $jalurLain, 'tipe' => 'teks', 'sel' => 'S!B1']]);

        $this->assertSame($bentrok ? [] : ['S!A1'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'n'), 'sel'), "{$jalurKandidat} vs {$jalurLain}");
    }

    public function test_jalur_butir_lain_yang_tak_terurai_mengeluarkan_kandidat(): void
    {
        $isi = $this->peta1([$this->angka('S!A1', 'x.satu'), ['jalur' => 'a..b', 'tipe' => 'teks', 'sel' => 'S!B1']]);

        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($isi, 'n'));
    }

    public function test_pasangan_alias_kode_indeks_antar_kandidat_keluar_semuanya(): void
    {
        $isi = $this->peta1([
            $this->angka('S!A1', 'cmc[kode=A].u95_um'),
            $this->angka('S!A2', 'cmc[0].u95_um'),
            $this->angka('S!A3', 'cmc[kode=B].u95_um'),
            $this->angka('S!A4', 'cmc[1].u95_um'),
        ]);

        // Tiap kandidat beralias dengan kandidat lain (kode=A~0, kode=B~1 pada kunci `cmc`): tak satu pun lolos.
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($isi, 'n'));
    }

    // ---- peta nyata: refractometer (sel jangkar) -------------------------------

    public function test_refractometer_asli_sel_b4_yang_juga_jangkar_tidak_ikut(): void
    {
        $peta = PetaSel::untuk('refractometer') ?? $this->fail('peta refractometer tidak ada');

        // Premis: B4 adalah entri ekstrak angka bulat 10 yang LOLOS bentuk, dan sekaligus jangkar '19'.
        $b4 = array_values(array_filter($peta->isi['ekstrak'], static fn (array $e): bool => ($e['sel'] ?? null) === 'Tab Konversi Temperatur!B4'));
        $this->assertCount(1, $b4);
        $this->assertSame(['tipe' => 'angka', 'bulat' => 10], ['tipe' => $b4[0]['tipe'], 'bulat' => $b4[0]['bulat']]);
        $this->assertSame('koreksi_suhu.suhu_tabel_min_c', $b4[0]['jalur']);
        $this->assertContains(['sel' => 'Tab Konversi Temperatur!B4', 'teks' => '19'], $peta->isi['jangkar']);

        $hasil = PemetaanSuntingan::untukNama($peta, 'Refractometer');

        $this->assertNotContains('Tab Konversi Temperatur!B4', array_column($hasil, 'sel'));
        $this->assertSame([
            ['sel' => 'Tab Konversi Temperatur!H4', 'jalur' => 'koreksi_suhu.koef_n20d_per_c', 'tipe' => 'angka', 'bulat' => 10],
            ['sel' => 'Tab Konversi Temperatur!E4', 'jalur' => 'koreksi_suhu.koef_brix_per_c', 'tipe' => 'angka', 'bulat' => 10],
            ['sel' => 'Tab Konversi Temperatur!B116', 'jalur' => 'koreksi_suhu.suhu_tabel_maks_c', 'tipe' => 'angka', 'bulat' => 10],
        ], $hasil);
    }

    public function test_micrometer_asli_tetap_persis_empat_entri_t5_sampai_t8_sesudah_penjaga_baru(): void
    {
        $sel = array_column(PemetaanSuntingan::untukNama($this->peta(), 'Master_Olah_Data_Micrometer_0-25mm'), 'sel');

        $this->assertSame(['DATABASE!T5', 'DATABASE!T6', 'DATABASE!T7', 'DATABASE!T8'], $sel);
    }

    public function test_jalur_yang_ditulis_butir_lain_juga_mengeluarkan_entri(): void
    {
        $isi = $this->peta1([
            $this->angka('S!A1', 'x.sama'),
            ['jalur' => 'x.sama', 'tipe' => 'teks', 'sel' => 'S!B1'],
            $this->angka('S!A2', 'x.unik'),
            ['jalur' => 'x.pola[{i}]', 'tipe' => 'daftar_angka', 'rentang' => 'S!H1:H3', 'ulang' => 2, 'langkah_baris' => 3, 'bulat' => 10],
            $this->angka('S!A3', 'x.pola[1]'),
        ]);

        $this->assertSame(['S!A2'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'n'), 'sel'));
    }

    public function test_butir_varian_lain_tidak_menyebabkan_bentrok(): void
    {
        $varian = ['sumber' => 'nama', 'pola' => ['A' => '(^| )a( |$)', 'B' => '(^| )b( |$)']];
        $isi = $this->peta1([
            $this->angka('S!A1', 'x.satu'),
            ['jalur' => 'x.satu', 'tipe' => 'teks', 'sel' => 'S!A1', 'varian' => ['B']],
        ], $varian);

        $this->assertSame(['S!A1'], array_column(PemetaanSuntingan::dariIsiPeta($isi, 'm a'), 'sel'));
        $this->assertSame([], PemetaanSuntingan::dariIsiPeta($isi, 'm b'));
    }

    public function test_mapping_sintetis_sah_lolos_preparer_dan_hanya_memengaruhi_jalur_sendiri(): void
    {
        $isi = $this->peta1([$this->angka('S!A1', 'a.b'), $this->angka('S!A2', 'c[kode=Z].d')]);
        $base = ['a' => ['b' => 1, 'u' => 2.0], 'c' => [['kode' => 'Z', 'd' => 3.5]]];

        $hasil = $this->preparer()->prepare($base, PemetaanSuntingan::dariIsiPeta($isi, 'n'), [['sel' => 'S!A2', 'nilai' => '4']]);

        $this->assertSame(['a' => ['b' => 1, 'u' => 2.0], 'c' => [['kode' => 'Z', 'd' => 4.0]]], $hasil['isi']);
    }
}
