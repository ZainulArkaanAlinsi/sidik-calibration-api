<?php

namespace Tests\Unit;

use App\Services\Calibration\AnakTimbanganCalculator;
use App\Services\Calibration\TabelStandarAnakTimbangan;
use App\Support\AnakTimbanganMentah;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penjagaan lembar **Anak Timbangan** yang TIDAK terwakili fixture master.
 *
 * `AnakTimbanganMasterTest` sudah membuktikan angkanya cocok dan enam titik
 * rusak master ditolak. Yang diuji di sini bentuk kegagalan yang sesi contoh
 * masternya kebetulan tidak punya — dan justru itu yang akan datang dari
 * lapangan: blok sesi setengah terisi, neraca yang salah ketik, kelas OIML yang
 * belum dipilih, keping kembar tanpa penanda.
 *
 * Semuanya satu keluarga: **kalau gerbangnya tidak menyala, yang terbit bukan
 * error melainkan angka yang kelihatan wajar.** Itu sebabnya tiap test di sini
 * memeriksa DUA hal — titiknya ditolak, DAN alasannya menyebutkan apa yang
 * kurang.
 */
class AnakTimbanganGerbangTest extends TestCase
{
    /** Konteks sesi yang lengkap dan sehat; tiap test merusak satu bagian saja. */
    private const KONTEKS = [
        'kelas_uut' => 'F1',
        'kelas_standar' => 'E2',
        'timbangan' => 'Analytical Balance',
        'meter_lingkungan' => 'Thermobarometer',
        'suhu' => ['awal' => 23.1, 'akhir' => 23.0],
        'kelembaban' => ['awal' => 55.0, 'akhir' => 56.0],
        'tekanan' => ['awal' => 933.2, 'akhir' => 933.1],
        'identitas' => [],
    ];

    /** Satu keping 100 g yang sehat — nominalnya tidak punya kembaran. */
    private const TITIK = [[
        'titik_ke' => 1,
        'nominal_g' => 100.0,
        'at_s1' => 100.0,
        'at_t1' => 99.9999,
        'at_t2' => 99.9999,
        'at_s2' => 100.0,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        TabelStandarAnakTimbangan::lupakanCache();
    }

    // ------------------------------------------------------- prasyarat sesi

    /**
     * Tekanan yang cuma terisi separuh membuat SELURUH sesi ditolak.
     *
     * Bukan "dihitung dengan tekanan seadanya": densitas udara lahir dari
     * ketiganya dan masuk ke koreksi apung SETIAP keping. Tekanan 466 hPa di
     * ruangan ber-933 hPa menggeser densitas udara dari 1,09 ke 0,55 kg/m³.
     */
    #[Test]
    public function ujung_lingkungan_yang_separuh_menolak_seluruh_sesi(): void
    {
        $konteks = self::KONTEKS;
        $konteks['tekanan'] = ['awal' => 933.2, 'akhir' => null];

        $hasil = (new AnakTimbanganCalculator)->hitungSesi(self::TITIK, $konteks);

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertSame([], $hasil['titik']);
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertStringContainsString('awal DAN akhir', $hasil['ditolak'][0]['alasan']);
        $this->assertStringContainsString('densitas udara', $hasil['ditolak'][0]['alasan']);
    }

    #[Test]
    public function neraca_yang_tidak_terdaftar_menolak_seluruh_sesi(): void
    {
        $konteks = self::KONTEKS;
        $konteks['timbangan'] = 'Analytic Balance';  // salah ketik satu huruf

        $hasil = (new AnakTimbanganCalculator)->hitungSesi(self::TITIK, $konteks);

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertStringContainsString('Analytic Balance', $hasil['ditolak'][0]['alasan']);
        $this->assertStringContainsString('nggak ada di tabel standar', $hasil['ditolak'][0]['alasan']);
    }

    #[Test]
    public function kelas_oiml_yang_belum_dipilih_menolak_seluruh_sesi(): void
    {
        $konteks = self::KONTEKS;
        $konteks['kelas_uut'] = null;

        $hasil = (new AnakTimbanganCalculator)->hitungSesi(self::TITIK, $konteks);

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertStringContainsString('kelas OIML alat yang dikalibrasi', $hasil['ditolak'][0]['alasan']);
    }

    /** Sesi yang sehat memang terbit — supaya ketiga test di atas berarti. */
    #[Test]
    public function sesi_yang_sehat_terbit_dengan_enam_komponen(): void
    {
        $hasil = (new AnakTimbanganCalculator)->hitungSesi(self::TITIK, self::KONTEKS);

        $this->assertTrue($hasil['boleh_terbit']);
        $this->assertSame([], $hasil['ditolak']);
        $this->assertCount(1, $hasil['titik']);
        $this->assertCount(6, $hasil['titik'][0]['budget']);
        $this->assertGreaterThan(0.0, $hasil['titik'][0]['u95_g']);
    }

    // -------------------------------------------------------- gerbang titik

    #[Test]
    public function satu_peran_abba_yang_kosong_menolak_titiknya(): void
    {
        $titik = self::TITIK;
        $titik[0]['at_t2'] = null;

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, self::KONTEKS);

        $this->assertTrue($hasil['boleh_terbit'], 'Prasyarat sesinya lengkap; yang kurang titiknya.');
        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('at_t2', $hasil['ditolak'][0]['alasan']);
        $this->assertStringContainsString('ABBA', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Keping bernominal kembar WAJIB punya penanda.
     *
     * Tanpa itu, sertifikat mencetak dua baris "200 g" yang tidak bisa
     * dipetakan pelanggan ke keping fisiknya — dan untuk 20 g massanya bahkan
     * berbeda (19,99989598 g lawan 20,00049598 g) sementara identitasnya
     * sama-sama `-`, persis seperti di sertifikat master.
     */
    #[Test]
    public function keping_kembar_tanpa_penanda_ditolak_lalu_terbit_begitu_diberi_penanda(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 200.0,
            'at_s1' => 199.9999,
            'at_t1' => 199.9999,
            'at_t2' => 199.9999,
            'at_s2' => 199.9999,
        ]];

        $tanpa = (new AnakTimbanganCalculator)->hitungSesi($titik, self::KONTEKS);

        $this->assertSame([], $tanpa['titik']);
        $this->assertStringContainsString('no_identitas', $tanpa['ditolak'][0]['alasan']);

        $konteks = self::KONTEKS;
        $konteks['identitas'] = [1 => 'AT-200-1'];

        $dengan = (new AnakTimbanganCalculator)->hitungSesi($titik, $konteks);

        $this->assertCount(1, $dengan['titik']);
        $this->assertSame([], $dengan['ditolak']);
        $this->assertSame('AT-200-1', $dengan['titik'][0]['no_identitas']);
    }

    /**
     * Nominal yang TIDAK kembar tidak diwajibkan berpenanda — gerbangnya tidak
     * boleh menahan keping yang memang tunggal.
     */
    #[Test]
    public function keping_tunggal_tidak_diwajibkan_berpenanda(): void
    {
        $hasil = (new AnakTimbanganCalculator)->hitungSesi(self::TITIK, self::KONTEKS);

        $this->assertCount(1, $hasil['titik']);
        $this->assertNull($hasil['titik'][0]['no_identitas']);
    }

    #[Test]
    public function nominal_yang_tidak_ada_di_tabel_keping_ditolak(): void
    {
        $titik = self::TITIK;
        $titik[0]['nominal_g'] = 0.3;

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, self::KONTEKS);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('tabel keping standar', $hasil['ditolak'][0]['alasan']);
        $this->assertStringContainsString('IFERROR', $hasil['ditolak'][0]['alasan']);
    }

    // ------------------------------------------------------- tabel standar

    /**
     * Baris ber-bintang adalah keping KEDUA bernominal sama, dan pencarian
     * exact master selalu mendarat di yang pertama — ditiru.
     */
    #[Test]
    public function baris_ber_bintang_tidak_pernah_terpilih(): void
    {
        // Daftar PASANGAN, bukan peta — kunci larik PHP yang berupa angka
        // dalam bentuk teks ('2', '20', '200') dipaksa jadi int, jadi
        // `nominal_teks`-nya tidak akan pernah cocok.
        $uji = [['0.02', 0.02], ['0.2', 0.2], ['2', 2.0], ['20', 20.0], ['200', 200.0]];

        foreach ($uji as [$teks, $nominal]) {
            $keping = TabelStandarAnakTimbangan::cariKeping($nominal);

            $this->assertNotNull($keping, "Nominal {$teks} g harus ada di tabel.");
            $this->assertSame(
                $teks,
                $keping['nominal_teks'],
                "Nominal {$teks} g mendarat di baris ber-bintang — pencariannya menyimpang dari master.",
            );
        }
    }

    /** Keenam nominal itu memang punya kembaran, jadi test di atas berarti. */
    #[Test]
    public function enam_nominal_punya_keping_kembar(): void
    {
        $kembar = TabelStandarAnakTimbangan::kepingKembar();

        sort($kembar);

        $this->assertSame([0.002, 0.02, 0.2, 2.0, 20.0, 200.0], $kembar);
    }

    /**
     * Nominal ≥ 100 g memakai baris 100 g — bukan tebakan: masternya menulis
     * `Note : Diatas 100 g nilainya =` di atas baris itu, dan kedua keping
     * 200 g di sesi contoh memungut 8060/8010 milik baris 100 g.
     */
    #[Test]
    public function densitas_diatas_100_gram_memakai_baris_100_gram(): void
    {
        $ini = TabelStandarAnakTimbangan::densitas(100.0, 'F1');

        $this->assertSame($ini, TabelStandarAnakTimbangan::densitas(200.0, 'F1'));
        $this->assertSame($ini, TabelStandarAnakTimbangan::densitas(500.0, 'F1'));
        $this->assertSame(8010.0, TabelStandarAnakTimbangan::densitas(200.0, 'E2'));
    }

    /**
     * Densitas yang tidak ada balik `null`, BUKAN nol.
     *
     * Nol di sini melahirkan pembagian nol; teks `"tdk ada di tabel"` master
     * melahirkan `#VALUE!` yang tercetak di sertifikat pelanggan. Keduanya
     * salah — yang benar `null` yang memblokir titiknya.
     */
    #[Test]
    public function densitas_yang_tidak_ditabelkan_balik_null(): void
    {
        $this->assertNull(TabelStandarAnakTimbangan::densitas(0.05, 'F1'));
        $this->assertNull(TabelStandarAnakTimbangan::densitas(0.01, 'F1'));
        $this->assertNull(TabelStandarAnakTimbangan::densitas(0.02, 'F1'));
        $this->assertNull(TabelStandarAnakTimbangan::densitas(0.005, 'F1'));
        // Yang ADA tetap ketemu — supaya null di atas bukan karena tabelnya rusak.
        $this->assertSame(3000.0, TabelStandarAnakTimbangan::densitas(0.1, 'F1'));
        $this->assertSame(2300.0, TabelStandarAnakTimbangan::densitas(0.01, 'E2'));
    }

    /**
     * Titik indeks meter dipilih yang TERDEKAT, dan seri memilih yang lebih
     * rendah. Suhu 23,05 berjarak persis sama (2,95) dari 20,1 dan 26.
     */
    #[Test]
    public function titik_indeks_memilih_terdekat_dan_seri_memilih_yang_rendah(): void
    {
        $suhu = TabelStandarAnakTimbangan::titikIndeks('Thermobarometer', 'suhu', 23.05);
        $rh = TabelStandarAnakTimbangan::titikIndeks('Thermobarometer', 'kelembaban', 55.5);
        $p = TabelStandarAnakTimbangan::titikIndeks('Thermobarometer', 'tekanan', 933.15);

        $this->assertSame(20.1, $suhu['standar'], 'Seri 2,95 : 2,95 wajib memilih yang lebih rendah.');
        $this->assertSame(59.2, $rh['standar']);
        $this->assertSame(931.0, $p['standar']);

        // Ketidakpastian tekanan meternya 2 hPa — BUKAN 3 (itu punya kelembaban,
        // dan sertifikat master keliru memungutnya). Pertanyaan lab §7.
        $this->assertSame(2.0, $p['u95']);
        $this->assertSame(3.0, $rh['u95']);
    }

    /** `TH-7` sengaja tidak disalin — tabel koreksinya tidak rekonsiliasi (§19). */
    #[Test]
    public function meter_th7_sengaja_tidak_tersedia(): void
    {
        $this->assertNull(TabelStandarAnakTimbangan::meterLingkungan('TH-7'));
        $this->assertNotNull(TabelStandarAnakTimbangan::meterLingkungan('Thermobarometer'));
    }

    // ------------------------------------------------------------- mentah

    #[Test]
    public function blok_sesi_menolak_ujung_lingkungan_yang_separuh(): void
    {
        $blok = AnakTimbanganMentah::blokSesi([
            AnakTimbanganMentah::KUNCI_SESI => [
                'kelas_uut' => 'F1',
                'kelas_standar' => 'E2',
                'timbangan' => 'Analytical Balance',
                'suhu_awal' => 23.1,
                'suhu_akhir' => 23.0,
                'kelembaban_awal' => 55.0,
                // `kelembaban_akhir` sengaja tidak diisi.
                'tekanan_awal' => 933.2,
                'tekanan_akhir' => 933.1,
            ],
        ]);

        $this->assertNotNull($blok);
        $this->assertSame(55.0, $blok['kelembaban']['awal']);
        $this->assertNull($blok['kelembaban']['akhir']);

        // Dan kalkulatornya yang menolak, bukan blok-nya yang menebak.
        $hasil = (new AnakTimbanganCalculator)->hitungSesi(self::TITIK, [
            ...$blok,
            'meter_lingkungan' => 'Thermobarometer',
        ]);

        $this->assertFalse($hasil['boleh_terbit']);
    }

    #[Test]
    public function rata_ujung_balik_null_kalau_salah_satunya_kosong(): void
    {
        $this->assertSame(23.05, AnakTimbanganMentah::rataUjung(23.1, 23.0));
        $this->assertNull(AnakTimbanganMentah::rataUjung(23.1, null));
        $this->assertNull(AnakTimbanganMentah::rataUjung(null, 23.0));
        $this->assertNull(AnakTimbanganMentah::rataUjung('', 23.0));
    }

    #[Test]
    public function blok_sesi_menolak_kelas_yang_tidak_dikenali(): void
    {
        $blok = AnakTimbanganMentah::blokSesi([
            AnakTimbanganMentah::KUNCI_SESI => ['kelas_uut' => 'F9', 'kelas_standar' => 'e2'],
        ]);

        // `F9` bukan kelas OIML — balik null, BUKAN ditebak ke F1.
        $this->assertNull($blok['kelas_uut']);
        // Huruf kecil tetap dikenali; yang dibandingkan kelasnya, bukan ejaannya.
        $this->assertSame('E2', $blok['kelas_standar']);
    }

    #[Test]
    public function blok_sesi_membuang_penanda_yang_salah_bentuk(): void
    {
        $blok = AnakTimbanganMentah::blokSesi([
            AnakTimbanganMentah::KUNCI_SESI => [
                'identitas' => [
                    '3' => 'AT-C',
                    '1' => '  AT-A  ',
                    '2' => '   ',       // kosong sesudah dipangkas
                    'x' => 'AT-X',      // kunci bukan angka
                    '4' => 12345,       // nilai bukan teks
                ],
            ],
        ]);

        // Diurut, dipangkas, dan yang salah bentuk dibuang — bukan dipaksa.
        $this->assertSame([1 => 'AT-A', 3 => 'AT-C'], $blok['identitas']);
    }

    #[Test]
    public function blok_sesi_yang_belum_ada_balik_null(): void
    {
        $this->assertNull(AnakTimbanganMentah::blokSesi(null));
        $this->assertNull(AnakTimbanganMentah::blokSesi([]));
        $this->assertNull(AnakTimbanganMentah::blokSesi(['height_gauge' => ['satuan' => 'mm']]));
    }
}
