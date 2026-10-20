<?php

namespace Tests\Unit;

use App\Models\CalibrationSession;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Support\Angka;
use App\Support\TataLetakSertifikat;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Lapisan bentuk sertifikat bersama (K-50-15, pemilik 18 Okt 2026: sertifikat
 * persis lembar `SERTIFIKAT` master alatnya).
 *
 * Yang dijaga di sini MEKANISME-nya: timpaan profil yang salah ketik melempar,
 * format sel master tidak membuang nol di belakang, `k` dicetak seperti format
 * selnya, baris yang disembunyikan master tidak dicetak, dan snapshot lama
 * tanpa `tata_letak` tetap dibaca bentuk lamanya. Isi per alat (H) dijaga test
 * profil masing-masing.
 */
class TataLetakSertifikatTest extends TestCase
{
    public function test_snapshot_tanpa_tata_letak_dibaca_bentuk_lama(): void
    {
        $this->assertSame(TataLetakSertifikat::BAWAAN, TataLetakSertifikat::untuk([]));
        $this->assertSame(TataLetakSertifikat::BAWAAN, TataLetakSertifikat::untuk(['tata_letak' => null]));
    }

    public function test_timpaan_menang_atas_bawaan_dan_kunci_asing_di_snapshot_diabaikan(): void
    {
        $tl = TataLetakSertifikat::untuk(['tata_letak' => ['judul_laporan' => 'Calibration Report :', 'bukan_kunci' => 1]]);

        $this->assertSame('Calibration Report :', $tl['judul_laporan']);
        $this->assertArrayNotHasKey('bukan_kunci', $tl);
        $this->assertSame(TataLetakSertifikat::BAWAAN['kepala'], $tl['kepala']);
    }

    public function test_kunci_tata_letak_salah_ketik_dilempar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('judul_lapoan');

        TataLetakSertifikat::normalisasi(['judul_lapoan' => 'Calibration Report']);
    }

    public function test_kepala_hanya_menerima_field_snapshot_atau_kepala_tambahan(): void
    {
        $kepala = [[['certificate_number', 'Certificate Number'], ['class', 'Class']]];

        $hasil = TataLetakSertifikat::normalisasi(['kepala' => $kepala], ['class']);
        $this->assertSame(['kunci' => 'class', 'label' => 'Class'], $hasil['kepala'][0][1]);

        $this->expectException(InvalidArgumentException::class);
        TataLetakSertifikat::normalisasi(['kepala' => $kepala]);
    }

    /**
     * Bentuk mayoritas master yang jadi bawaan profil harus lolos pemeriksa
     * yang sama dengan timpaan profil mana pun.
     */
    public function test_bentuk_mayoritas_master_lolos_normalisasi(): void
    {
        $hasil = TataLetakSertifikat::normalisasi(TataLetakSertifikat::MAYORITAS_MASTER);

        $this->assertSame(TataLetakSertifikat::MAYORITAS_MASTER['judul_laporan'], $hasil['judul_laporan']);
        $this->assertTrue($hasil['env_bertumpuk']);
        $this->assertTrue($hasil['satuan_baris_sendiri']);

        // Kepala dua kelompok, urutan master: kiri sepuluh field, kanan enam.
        $kiri = array_column(array_filter(array_column($hasil['kepala'], 0)), 'kunci');
        $kanan = array_column(array_filter(array_column($hasil['kepala'], 1)), 'kunci');
        $this->assertSame([
            'certificate_number', 'page', 'order_number', 'received_date', 'equipment_name',
            'manufacturer', 'model_type', 'serial_number', 'capacity_graduation', 'technician_id',
        ], $kiri);
        $this->assertSame([
            'owner', 'address', 'calibration_location', 'calibration_date', 'calibration_method', 'env_condition',
        ], $kanan);
    }

    public function test_format_sel_tetap_tidak_membuang_nol_di_belakang(): void
    {
        $this->assertSame('5,0', TataLetakSertifikat::formatAngka(5.0, '0.0'));
        $this->assertSame('0,610', TataLetakSertifikat::formatAngka(0.61, '0.000'));
        $this->assertSame('237', TataLetakSertifikat::formatAngka(237.49, '0'));
        // `General`: presisi tampilan, nol di belakang dibuang.
        $this->assertSame('1,9759', TataLetakSertifikat::formatAngka(1.9759, 'General'));
        $this->assertSame('—', TataLetakSertifikat::formatAngka(null, '0.0'));
    }

    public function test_format_kolom_di_baris_menang_atas_desimal_lama(): void
    {
        $baris = [
            'standard_value' => 5.0, 'unit_under_test' => 5.04, 'correction' => -0.04,
            'u95' => 0.0188, 'desimal' => 4, 'desimal_u95' => 4,
            'format' => ['standar' => '0.0', 'u95' => '0.000'],
        ];

        $this->assertSame('5,0', TataLetakSertifikat::teksSel($baris, 'standar', [], 2));
        $this->assertSame('5,0400', TataLetakSertifikat::teksSel($baris, 'uut', [], 2));
        $this->assertSame('0,019', TataLetakSertifikat::teksSel($baris, 'u95', [], 2));
        $this->assertSame(1, TataLetakSertifikat::desimalKolom($baris, 'standar', [], 2));
        $this->assertSame(4, TataLetakSertifikat::desimalKolom($baris, 'uut', [], 2));

        // Tanpa `format`: perilaku lama — Standard Value dibuang nol-nya.
        unset($baris['format']);
        $this->assertSame('5', TataLetakSertifikat::teksSel($baris, 'standar', [], 2));
    }

    public function test_k_dicetak_seperti_format_selnya(): void
    {
        // 40 lembar SERTIFIKAT memformat sel `k` `0`: k hitung 1,9714 tercetak `2`.
        $this->assertSame('2', TataLetakSertifikat::teksK(['format' => ['k' => '0']], 1.9714));
        // Turbidimeter `U27` `0.0`.
        $this->assertSame('2,0', TataLetakSertifikat::teksK(['format' => ['k' => '0.0']], 1.9714));
        // `desimal_k` (hook `desimalFaktorCakupan`) — jalur Spektro.
        $this->assertSame('3', TataLetakSertifikat::teksK(['desimal_k' => 0], 3.182446));
        // Tanpa keduanya: perilaku lama, 2 desimal nol di belakang dibuang.
        $this->assertSame('1,97', TataLetakSertifikat::teksK([], 1.9714));
    }

    /** P2 & P3 (pemilik 20 Okt 2026): `k` format sel `0`, titik desimal. */
    public function test_bentuk_master_k_format_nol_dan_titik_desimal(): void
    {
        $master = TataLetakSertifikat::untuk(['tata_letak' => TataLetakSertifikat::normalisasi(TataLetakSertifikat::MAYORITAS_MASTER)]);

        $this->assertSame('2', TataLetakSertifikat::teksK([], 1.9714, $master));
        // Profil yang menyatakan desimal/format sendiri tetap menang.
        $this->assertSame('3', TataLetakSertifikat::teksK(['desimal_k' => 0], 3.18, $master));
        $this->assertSame('2.0', TataLetakSertifikat::teksK(['format' => ['k' => '0.0']], 1.9714, $master));
        // Bentuk lama tidak berubah.
        $this->assertSame('1,97', TataLetakSertifikat::teksK([], 1.9714));

        $baris = ['standard_value' => 1412.0, 'unit_under_test' => 1413.25, 'correction' => -1.25, 'desimal' => 2];
        $snapMaster = ['tata_letak' => TataLetakSertifikat::normalisasi(TataLetakSertifikat::MAYORITAS_MASTER)];
        $this->assertSame('1413.25', TataLetakSertifikat::teksSel($baris, 'uut', $snapMaster, 2));
        $this->assertSame('1412', TataLetakSertifikat::teksSel($baris, 'standar', $snapMaster, 2));
        $this->assertSame('1413,25', TataLetakSertifikat::teksSel($baris, 'uut', [], 2));
        $this->assertSame('-0', TataLetakSertifikat::teksSel(['standard_value' => -0.0001, 'desimal' => 2], 'standar', [], 2));
        $this->assertSame('0', TataLetakSertifikat::teksSel(['standard_value' => -0.0001, 'desimal' => 2], 'standar', $snapMaster, 2));
    }

    public function test_ke_titik_hanya_mengubah_angka(): void
    {
        $this->assertSame('T 21.0 °C ± 1.7 °C', Angka::keTitik('T 21,0 °C ± 1,7 °C'));
        $this->assertSame('0–30000 rpm / 0.01 rpm', Angka::keTitik('0–30.000 rpm / 0,01 rpm'));
        $this->assertSame('Spindel No. 3, Speed 60', Angka::keTitik('Spindel No. 3, Speed 60'));
    }

    public function test_kode_format_yang_tidak_dikenal_dilempar(): void
    {
        $this->assertSame(['k' => '0'], TataLetakSertifikat::normalisasiFormat(['k' => '0', 'u95' => null]));

        $this->expectException(InvalidArgumentException::class);
        TataLetakSertifikat::normalisasiFormat(['k' => '#,##0.00']);
    }

    public function test_baris_yang_disembunyikan_master_tidak_dicetak(): void
    {
        $hasil = [['titik_ke' => 1, 'dicetak' => false], ['titik_ke' => 2], ['titik_ke' => 3, 'dicetak' => true]];

        $this->assertSame([2, 3], array_column(TataLetakSertifikat::barisDicetak($hasil), 'titik_ke'));
    }

    public function test_kelompok_tabel_dipisah_per_satuan(): void
    {
        $uS = TataLetakSertifikat::kunciKelompok(['remark' => null, 'satuan' => 'µS/cm'], true, true);
        $mS = TataLetakSertifikat::kunciKelompok(['remark' => null, 'satuan' => 'mS/cm'], true, true);

        $this->assertNotSame($uS, $mS);
        // Bentuk lama (snapshot terbit tanpa `tata_letak`): per `remark` saja.
        $this->assertSame(
            TataLetakSertifikat::kunciKelompok(['remark' => null, 'satuan' => 'µS/cm'], true),
            TataLetakSertifikat::kunciKelompok(['remark' => null, 'satuan' => 'mS/cm'], true),
        );
        $this->assertSame(
            TataLetakSertifikat::kunciKelompok(['remark' => 'Free', 'satuan' => 'mg/L'], false),
            TataLetakSertifikat::kunciKelompok(['remark' => 'Total', 'satuan' => 'mg/L'], false),
        );
    }

    public function test_blok_catatan_dirapikan_dan_posisi_asing_dilempar(): void
    {
        $blok = TataLetakSertifikat::normalisasiBlok([[
            'posisi' => TataLetakSertifikat::POSISI_BAWAH_HASIL,
            'judul' => 'Note :',
            'tabel' => [['Kerataan Muka Ukur dalam Kondisi', ':', 'Baik']],
        ]]);

        $this->assertSame([[
            'posisi' => 'bawah_hasil', 'judul' => 'Note :', 'baris' => [],
            'tabel' => [['Kerataan Muka Ukur dalam Kondisi', ':', 'Baik']],
        ]], $blok);

        $this->expectException(InvalidArgumentException::class);
        TataLetakSertifikat::normalisasiBlok([['posisi' => 'di_mana_saja', 'baris' => ['x']]]);
    }

    public function test_sel_kepala_kosong_ditelan_sel_berisi_di_atasnya(): void
    {
        // Kondisi lingkungan (baris 6 kanan) menelan empat sel kosong di
        // bawahnya — tiga barisnya tidak mempertinggi baris Manufacturer.
        $rentang = TataLetakSertifikat::rentangKepala(TataLetakSertifikat::MAYORITAS_MASTER['kepala']);

        $this->assertSame([1, 5], $rentang[5]);
        $this->assertSame([1, 0], $rentang[9]);

        // Bentuk lama tanpa sel kosong: semua rentang 1.
        foreach (TataLetakSertifikat::rentangKepala(TataLetakSertifikat::BAWAAN['kepala']) as $baris) {
            $this->assertSame([1, 1], $baris);
        }

        // Sel kosong di puncak kolom tetap sel sendiri.
        $this->assertSame([[1, 1], [1, 1]], TataLetakSertifikat::rentangKepala([
            [['kunci' => 'page', 'label' => 'Page'], null],
            [['kunci' => 'owner', 'label' => 'Owner'], ['kunci' => 'address', 'label' => 'Address']],
        ]));
    }

    public function test_sel_gabung_menyatukan_teks_cetak_yang_sama(): void
    {
        $kolom = [['kolom' => 'standar', 'gabung' => false], ['kolom' => 'u95', 'gabung' => true]];
        $teks = [
            ['standar' => '60', 'u95' => '2,6'],
            ['standar' => '80', 'u95' => '2,6'],
            ['standar' => '100', 'u95' => '3,1'],
        ];

        $this->assertSame(['u95' => [0 => 2, 1 => 0, 2 => 1]], TataLetakSertifikat::rentangGabung($teks, $kolom));
    }

    /**
     * Identitas rentang menang atas kesamaan teks: dua rentang Centrifuge
     * yang sama-sama tercetak `3.2` tetap dua kotak (`180!S19:V30`).
     */
    public function test_sel_gabung_ikut_identitas_rentang_bukan_teks(): void
    {
        $kolom = [['kolom' => 'standar', 'gabung' => false], ['kolom' => 'u95', 'gabung' => true]];
        $teks = [
            ['standar' => '60', 'u95' => '3.2'],
            ['standar' => '80', 'u95' => '3.2'],
            ['standar' => '500', 'u95' => '3.2'],
            ['standar' => '700', 'u95' => '3.2'],
        ];
        $baris = [['kunci_gabung' => 'A'], ['kunci_gabung' => 'A'], ['kunci_gabung' => 'B'], ['kunci_gabung' => 'B']];

        $this->assertSame(['u95' => [0 => 2, 1 => 0, 2 => 2, 3 => 0]], TataLetakSertifikat::rentangGabung($teks, $kolom, $baris));
        // Tanpa identitas: perilaku lama (satu kotak).
        $this->assertSame(['u95' => [0 => 4, 1 => 0, 2 => 0, 3 => 0]], TataLetakSertifikat::rentangGabung($teks, $kolom, [[], [], [], []]));
    }

    /**
     * Kolom berpecah Timer (`183!E19:W19`): Standard 60,0963 s → H 0, M 1,
     * S 0, S(0,001) 96; UUT 60,137 s → 0, 1, 0, 137; Correction per sel
     * (`S19 = E19-O19`, `W19 = L19-R19`) → 0, 0, 0, -41.
     */
    public function test_kolom_pecah_timer_seperti_master(): void
    {
        $spek = TataLetakSertifikat::normalisasi(['kolom_hasil' => [
            ['kolom' => 'standar', 'pecah' => [
                ['judul' => 'H', 'faktor' => 3600, 'format' => '0.00'],
                ['judul' => 'M', 'faktor' => 60, 'format' => '0.00'],
                ['judul' => 'S', 'faktor' => 1, 'format' => '0.00'],
                ['judul' => 'S(0,001)', 'faktor' => 0.001, 'format' => '0'],
            ]],
            'uut',
            'koreksi',
        ]])['kolom_hasil'];
        $pecah = $spek[0]['pecah'];
        $snap = ['tata_letak' => TataLetakSertifikat::normalisasi(TataLetakSertifikat::MAYORITAS_MASTER)];
        $baris = ['standard_value' => 60.0963333333, 'unit_under_test' => 60.137, 'correction' => -0.0406666667];

        $this->assertSame(['0.00', '1.00', '0.00', '96'], TataLetakSertifikat::teksPecah($baris, $spek[0], $snap));
        $this->assertSame(['0.00', '1.00', '0.00', '137'], TataLetakSertifikat::teksPecah($baris, ['kolom' => 'uut', 'pecah' => $pecah], $snap));
        $this->assertSame(['0.00', '0.00', '0.00', '-41'], TataLetakSertifikat::teksPecah($baris, ['kolom' => 'koreksi', 'pecah' => $pecah], $snap));
        $this->assertSame(4, TataLetakSertifikat::lebarKolom($spek[0]));

        $this->expectException(InvalidArgumentException::class);
        TataLetakSertifikat::normalisasi(['kolom_hasil' => [['kolom' => 'standar', 'pecah' => [['judul' => 'S', 'faktor' => 1], ['judul' => 'M', 'faktor' => 60]]]]]);
    }

    /**
     * U95 relatif (`%OR`, master `149!O22` `=K62/S23/D22*100`): padanan
     * `u95_persen_of_reading` kalkulator — 13,572 / 1004,58 × 100 = 1,351 →
     * `1.4` di format `0.0`. Kolom tambahan mencetak teks apa adanya dan angka
     * dengan format kolomnya.
     */
    public function test_u95_relatif_dan_kolom_tambahan(): void
    {
        $snap = ['tata_letak' => TataLetakSertifikat::normalisasi(TataLetakSertifikat::MAYORITAS_MASTER)];
        $baris = ['u95' => 13.572, 'unit_under_test' => 1004.58, 'tambahan' => ['suhu' => 25.45, 'fluida' => 'Water']];

        $this->assertSame('1.4', TataLetakSertifikat::teksSel($baris, 'u95_relatif', $snap, 2, ['kolom' => 'u95_relatif', 'format' => '0.0']));
        $this->assertSame('25.5', TataLetakSertifikat::teksSel($baris, 'tambahan:suhu', $snap, 2, ['format' => '0.0']));
        $this->assertSame('Water', TataLetakSertifikat::teksSel($baris, 'tambahan:fluida', $snap, 2, []));
        $this->assertSame('—', TataLetakSertifikat::teksSel($baris, 'tambahan:laju', $snap, 2, []));
        $this->assertSame('%OR', TataLetakSertifikat::satuanKolom(['kolom' => 'u95_relatif', 'satuan' => null], 'L'));
    }

    /**
     * Hook ber-sesi yang dibuka untuk builder H (Thermohygro, Timbangan,
     * Centrifuge, Flowmeter) bawaannya sama persis dengan hook lamanya —
     * profil yang belum menimpanya tidak bergeser satu karakter pun.
     */
    public function test_hook_ber_sesi_bawaannya_perilaku_lama(): void
    {
        $sesi = new CalibrationSession;

        foreach ([new ChlorineProfile, new PhMeterProfile] as $profil) {
            $this->assertSame($profil->desimalSuhuEnv(), $profil->desimalSuhuEnvSesi($sesi));
            $this->assertSame($profil->desimalKelembabanEnv(), $profil->desimalKelembabanEnvSesi($sesi));

            foreach ([1 => 1.74, 2 => 1.83] as $ke => $titik) {
                $this->assertSame($profil->satuanTitik($titik), $profil->satuanTitikSesi($sesi, $titik, $ke));
                $this->assertSame($profil->remarkTitikKe($ke, $titik), $profil->remarkTitikSesi($sesi, $ke, $titik));
                $this->assertNull($profil->kunciGabungTitik($sesi, $titik, $ke));
                $this->assertSame([], $profil->kolomTambahanTitik($sesi, $titik, $ke));
            }
        }
    }

    /** `k_tunggal`: satu `k` per kelompok, baris dipilih seperti sel master. */
    public function test_k_tunggal_memilih_baris(): void
    {
        $baris = [
            ['faktor_cakupan_k' => 1.9652, 'u95' => 0.81],
            ['faktor_cakupan_k' => 1.9640, 'u95' => 0.95],
        ];

        $this->assertSame(1.9652, TataLetakSertifikat::barisK($baris, 'baris_pertama')['faktor_cakupan_k']);
        $this->assertSame(1.9640, TataLetakSertifikat::barisK($baris, 'u95_terbesar')['faktor_cakupan_k']);
        $this->assertNull(TataLetakSertifikat::BAWAAN['k_tunggal']);

        $this->expectException(InvalidArgumentException::class);
        TataLetakSertifikat::normalisasi(['k_tunggal' => 'rata_rata']);
    }
}
