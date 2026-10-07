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
    public function keping_kembar_tanpa_pembeda_ditolak_lalu_terbit_begitu_dibedakan(): void
    {
        $keping = static fn (int $ke): array => [
            'titik_ke' => $ke,
            'nominal_g' => 200.0,
            'at_s1' => 199.9999,
            'at_t1' => 199.9999,
            'at_t2' => 199.9999,
            'at_s2' => 199.9999,
        ];
        $titik = [$keping(1), $keping(2)];

        // Dua keping 200 g tanpa bintang maupun No. Seri: tidak bisa dibedakan.
        $tanpa = (new AnakTimbanganCalculator)->hitungSesi($titik, self::KONTEKS);

        $this->assertSame([], $tanpa['titik']);
        $this->assertCount(2, $tanpa['ditolak']);
        $this->assertStringContainsString('tidak bisa dibedakan', $tanpa['ditolak'][0]['alasan']);
        $this->assertStringContainsString('200*', $tanpa['ditolak'][0]['alasan']);

        // Keping kedua berbintang — cara kertas membedakannya.
        $konteks = self::KONTEKS;
        $konteks['bintang'] = [2];

        $berbintang = (new AnakTimbanganCalculator)->hitungSesi($titik, $konteks);

        $this->assertCount(2, $berbintang['titik']);
        $this->assertSame([], $berbintang['ditolak']);
        $this->assertFalse($berbintang['titik'][0]['bintang']);
        $this->assertTrue($berbintang['titik'][1]['bintang']);

        // Atau No. Seri keping yang berbeda.
        $konteks = self::KONTEKS;
        $konteks['identitas'] = [1 => 'SN-A', 2 => 'SN-B'];

        $berseri = (new AnakTimbanganCalculator)->hitungSesi($titik, $konteks);

        $this->assertCount(2, $berseri['titik']);
        $this->assertSame('SN-B', $berseri['titik'][1]['no_identitas']);
    }

    /**
     * Satu keping bernominal kembar yang dikalibrasi SENDIRIAN tidak ditolak:
     * tanpa bintang dia keping pertama, dan tidak ada keping lain bernominal sama
     * di sesi ini yang bisa tertukar dengannya.
     */
    #[Test]
    public function keping_kembar_sendirian_tidak_ditolak(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 200.0,
            'at_s1' => 199.9999,
            'at_t1' => 199.9999,
            'at_t2' => 199.9999,
            'at_s2' => 199.9999,
        ]];

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, self::KONTEKS);

        $this->assertCount(1, $hasil['titik']);
        $this->assertSame([], $hasil['ditolak']);
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
        // Bacaannya ikut 0,3 g supaya yang diuji memang tabel kepingnya, bukan
        // penjaga satuan nominal di bawah.
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 0.3,
            'at_s1' => 0.3,
            'at_t1' => 0.3001,
            'at_t2' => 0.3001,
            'at_s2' => 0.3,
        ]];

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, self::KONTEKS);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('tabel keping standar', $hasil['ditolak'][0]['alasan']);
        $this->assertStringContainsString('IFERROR', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Keping 20 g yang nominalnya ditulis `20000` DITOLAK, bukan terhitung
     * sebagai anak timbangan 20 kg.
     *
     * Bentuk persis sesi produksi 5 Okt 2026: 20000 g ada di tabel keping, jadi
     * tanpa penjaga ini sesinya terbit dengan mT 19999,37 g dan U95 0,014 g —
     * angka yang tampak wajar untuk keping yang bacaannya 20,0018 g.
     *
     * Kapasitas yang diadu milik NERACA terpilih (Analytical Balance, maks
     * 220 g di tabel standar), bukan "Kapasitas Alat" blok sesi — kotak itu
     * milik set pelanggan dan di lapangan diisi teks bebas ("1-500"). Nilai
     * kotak itu sengaja dibuat besar di sini supaya terbukti tidak dipakai.
     */
    #[Test]
    public function nominal_salah_satuan_melebihi_kapasitas_neraca_ditolak(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 20000.0,
            'at_s1' => 20.0018,
            'at_t1' => 19.99075,
            'at_t2' => 19.99093,
            'at_s2' => 20.0018,
        ]];
        $konteks = self::KONTEKS;
        $konteks['kapasitas_g'] = 999999.0;

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, $konteks);

        $this->assertSame([], $hasil['titik'], 'Keping 20 g terhitung sebagai 20 kg.');
        $this->assertStringContainsString('kapasitas neraca', $hasil['ditolak'][0]['alasan']);
        $this->assertStringContainsString('satuan nominal', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Beberapa neraca dicentang: tiap keping memakai neraca TERKECIL yang
     * sanggup memikulnya — persis kertas lapangan 5 Okt 2026 (set F2 1 g–500 g
     * di Semi Micro 80 g, Analytical 220 g, dan Fujitsu 1200 g).
     */
    #[Test]
    public function neraca_dipilih_per_keping_dari_yang_dicentang(): void
    {
        $neraca = array_map(
            static fn (string $n): array => TabelStandarAnakTimbangan::timbangan($n),
            ['Electronic Balance Fujitsu', 'Semi Micro Balance', 'Analytical Balance'],
        );
        $pilih = static fn (float $g): ?string => AnakTimbanganCalculator::pilihNeraca($neraca, $g)['nama'] ?? null;

        $this->assertSame('Semi Micro Balance', $pilih(1.0));
        $this->assertSame('Semi Micro Balance', $pilih(50.0));
        $this->assertSame('Analytical Balance', $pilih(100.0));
        $this->assertSame('Analytical Balance', $pilih(200.0));
        $this->assertSame('Electronic Balance Fujitsu', $pilih(500.0));
        $this->assertNull($pilih(2000.0), 'Tidak ada neraca tercentang yang sanggup memikul 2 kg.');
    }

    /**
     * Sesi tiga neraca: keping kecil dan besar sama-sama terhitung, masing-masing
     * dengan budget neraca TEMPAT dia ditimbang.
     */
    #[Test]
    public function sesi_beberapa_neraca_menghitung_tiap_keping_dengan_neracanya(): void
    {
        $konteks = self::KONTEKS;
        $konteks['kelas_uut'] = 'F2';
        $konteks['timbangan'] = null;
        $konteks['timbangan_daftar'] = ['Semi Micro Balance', 'Analytical Balance', 'Electronic Balance Fujitsu'];

        $titik = [
            ['titik_ke' => 1, 'nominal_g' => 50.0, 'at_s1' => 49.99992, 'at_t1' => 50.00012, 'at_t2' => 50.00012, 'at_s2' => 49.99992],
            ['titik_ke' => 2, 'nominal_g' => 100.0, 'at_s1' => 100.0, 'at_t1' => 100.0007, 'at_t2' => 100.0007, 'at_s2' => 100.0],
            ['titik_ke' => 3, 'nominal_g' => 500.0, 'at_s1' => 500.0, 'at_t1' => 500.0, 'at_t2' => 500.0, 'at_s2' => 500.0],
        ];

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, $konteks);

        $this->assertSame([], $hasil['ditolak']);
        $this->assertSame(
            ['Semi Micro Balance', 'Analytical Balance', 'Electronic Balance Fujitsu'],
            array_column($hasil['titik'], 'timbangan'),
        );

        // Satu neraca saja (Analytical) untuk keping 100 g = angka yang SAMA
        // persis dengan keping 100 g di sesi tiga neraca.
        $konteksTunggal = self::KONTEKS;
        $konteksTunggal['kelas_uut'] = 'F2';
        $tunggal = (new AnakTimbanganCalculator)->hitungSesi([$titik[1]], $konteksTunggal);

        $this->assertSame($tunggal['titik'][0]['u95_g'], $hasil['titik'][1]['u95_g']);
        $this->assertSame($tunggal['titik'][0]['mt_g'], $hasil['titik'][1]['mt_g']);
    }

    /**
     * Blok sesi lama (cuma `timbangan`) dibaca sebagai daftar berisi satu
     * neraca, dan satuannya gram.
     */
    #[Test]
    public function blok_lama_dibaca_sebagai_satu_neraca_bersatuan_gram(): void
    {
        $blok = AnakTimbanganMentah::blokSesi([AnakTimbanganMentah::KUNCI_SESI => ['timbangan' => 'Analytical Balance']]);

        $this->assertSame(['Analytical Balance'], $blok['timbangan_daftar']);
        $this->assertSame('g', $blok['satuan']);
        $this->assertSame(20000.0, AnakTimbanganMentah::keGram('20', 'kg'));
        $this->assertSame(20.0, AnakTimbanganMentah::keGram('20', 'lb'), 'Satuan asing dibaca gram, tidak ditebak.');
    }

    /**
     * Neracanya SANGGUP memikul 20 kg (persis sesi produksi 41: Mettler 30 kg),
     * jadi penjaga kapasitas lolos — yang menahan bacaannya, 20,0018 g untuk
     * nominal 20000 g.
     */
    #[Test]
    public function bacaan_yang_tidak_seorde_dengan_nominal_ditolak(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 20000.0,
            'at_s1' => 20.0018,
            'at_t1' => 19.99075,
            'at_t2' => 19.99093,
            'at_s2' => 20.0018,
        ]];
        $konteks = self::KONTEKS;
        $konteks['timbangan'] = 'Electronic Balance  Mettler';

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, $konteks);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('tidak sesuai nominal', $hasil['ditolak'][0]['alasan']);
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

    // ------------------------------------ nominal sah tidak boleh ikut ditolak

    /**
     * Konteks neraca 30 kg (Electronic Balance Mettler, kapasitas 30000 g)
     * dengan kapasitas yang dibawa blok sesi. Nama neraca di tabel standar
     * memang memuat DUA spasi ("Electronic Balance  Mettler").
     *
     * @return array<string, mixed>
     */
    private function konteksNeraca30kg(): array
    {
        $konteks = self::KONTEKS;
        $konteks['timbangan'] = 'Electronic Balance  Mettler';
        $konteks['kapasitas_g'] = 30000.0;

        return $konteks;
    }

    /**
     * Keping 20 kg ditulis 20000 (lembar ini selalu GRAM) dengan bacaan
     * ≈ 20000 g di neraca 30 kg harus TERHITUNG — dua penjaga satuan
     * (kapasitas & separuh nominal) cuma boleh menolak salah satuan, bukan
     * keping besar yang sah.
     */
    #[Test]
    public function keping_20_kg_di_neraca_30_kg_tidak_ditolak_penjaga_satuan(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 20000.0,
            'at_s1' => 20000.0275,
            'at_t1' => 20000.0250,
            'at_t2' => 20000.0255,
            'at_s2' => 20000.0275,
        ]];

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, $this->konteksNeraca30kg());

        $this->assertTrue($hasil['boleh_terbit']);
        $this->assertSame([], $hasil['ditolak'], json_encode($hasil['ditolak']));
        $this->assertCount(1, $hasil['titik']);
        $this->assertGreaterThan(0.0, $hasil['titik'][0]['u95_g']);
    }

    #[Test]
    public function keping_10_kg_di_neraca_30_kg_tidak_ditolak_penjaga_satuan(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 10000.0,
            'at_s1' => 10000.0140,
            'at_t1' => 10000.0120,
            'at_t2' => 10000.0125,
            'at_s2' => 10000.0140,
        ]];

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, $this->konteksNeraca30kg());

        $this->assertSame([], $hasil['ditolak'], json_encode($hasil['ditolak']));
        $this->assertCount(1, $hasil['titik']);
    }

    /** Keping kecil (0,1 g dibaca 0,1001) lolos tanpa penjaga mengira itu salah satuan. */
    #[Test]
    public function keping_kecil_dengan_bacaan_wajar_tidak_ditolak(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'nominal_g' => 0.1,
            'at_s1' => 0.1001,
            'at_t1' => 0.1000,
            'at_t2' => 0.1000,
            'at_s2' => 0.1001,
        ]];

        $hasil = (new AnakTimbanganCalculator)->hitungSesi($titik, self::KONTEKS);

        $this->assertSame([], $hasil['ditolak'], json_encode($hasil['ditolak']));
        $this->assertCount(1, $hasil['titik']);
    }

    /**
     * Batas separuh nominal: selisih rata-rata S1/S2 terhadap nominal PERSIS
     * di batas (50 g untuk 100 g) masih lolos penjaga ini; sedikit di atasnya
     * ditolak. Titik yang lolos penjaga tidak dijamin terhitung penuh, jadi
     * yang diperiksa alasan penolakannya, bukan hasil akhirnya.
     */
    #[Test]
    public function batas_separuh_nominal_lolos_dan_sedikit_di_atasnya_ditolak(): void
    {
        $dasar = [
            'titik_ke' => 1,
            'nominal_g' => 100.0,
            'at_t1' => 149.9999,
            'at_t2' => 149.9999,
        ];

        $dalam = (new AnakTimbanganCalculator)->hitungSesi(
            [$dasar + ['at_s1' => 150.0, 'at_s2' => 150.0]],
            self::KONTEKS,
        );
        $this->assertSame([], $dalam['ditolak'], 'Selisih tepat nominal/2 tidak boleh ditolak.');

        $luar = (new AnakTimbanganCalculator)->hitungSesi(
            [$dasar + ['at_s1' => 150.01, 'at_s2' => 150.01]],
            self::KONTEKS,
        );
        $this->assertCount(1, $luar['ditolak']);
        $this->assertStringContainsString('tidak sesuai nominal', $luar['ditolak'][0]['alasan']);
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
