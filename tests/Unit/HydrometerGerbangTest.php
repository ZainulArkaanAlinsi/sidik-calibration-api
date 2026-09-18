<?php

namespace Tests\Unit;

use App\Services\Calibration\HydrometerCalculator;
use App\Support\AngkaDesimal;
use App\Support\HydrometerMentah;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penjagaan lembar **Hydrometer** yang TIDAK terwakili kedua workbook master.
 *
 * `HydrometerMasterTest` sudah membuktikan angkanya cocok sampai 1·10⁻¹².
 * Yang diuji di sini bentuk kegagalan yang kedua sesi contoh masternya
 * kebetulan tidak punya — dan justru itu yang akan datang dari lapangan: blok
 * Pre Condition setengah terisi, dua tabel yang tidak sinkron, ulangan yang
 * bukan tiga, koma desimal dari keyboard HP.
 *
 * Semuanya satu keluarga: **kalau gerbangnya tidak menyala, yang terbit bukan
 * error melainkan densitas yang kelihatan wajar.** Alat ini paling rawan
 * karena tidak ada satu pun angka di sertifikatnya yang pernah diketik
 * manusia — semuanya hasil olahan, jadi tidak ada yang bisa dibandingkan
 * sekilas dengan lembar kertas.
 *
 * Itu sebabnya tiap test di sini memeriksa DUA hal: titiknya ditolak, DAN
 * alasannya menyebutkan apa yang kurang.
 */
class HydrometerGerbangTest extends TestCase
{
    /** Blok Pre Condition yang lengkap & sehat; tiap test merusak satu bagian saja. */
    private const BLOK = [
        'pakai_beban_tambahan' => true,
        'beban_tambahan' => 54.0052,
        'massa_udara' => 39.9327,
        'tegangan_permukaan' => 17.5,
        'satuan_tegangan' => 'mN/m',
        'suhu_acuan_alat' => 15.0,
        'suhu_acuan_faktor' => 20.0,
        'diameter_stem' => [0.708, 0.710, 0.709],
        'resolusi' => 0.0005,
        'suhu_awal' => 20.4,
        'suhu_akhir' => 20.5,
        'kelembaban_awal' => 56.0,
        'kelembaban_akhir' => 55.0,
        'tekanan_awal' => 933.2,
        'tekanan_akhir' => 933.1,
    ];

    private const TITIK = [
        ['titik_ke' => 1, 'titik_ukur' => 0.610, 'massa' => [21.2727, 21.2726, 21.2856], 'suhu' => [20.6, 20.6, 20.6]],
    ];

    /**
     * Toggle menyala + `Sl` kosong = teknisi LUPA, bukan "tidak perlu sinker".
     *
     * Kalau ini lolos jadi varian non-sinker, densitasnya meleset beberapa
     * persen dan tetap ber-orde satuan yang sama — persis yang dibuktikan
     * `HydrometerMasterTest::varian_salah_memberi_angka_yang_tampak_wajar_tapi_beda_jauh`.
     */
    #[Test]
    public function toggle_sinker_menyala_tanpa_angka_ditolak(): void
    {
        $hasil = $this->hitung(['beban_tambahan' => null]);

        $this->assertSame([], $hasil['titik'], 'sesi mestinya tidak melahirkan satu pun titik');
        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertStringContainsString('beban tambahan', $this->alasan($hasil));
        $this->assertStringContainsString('Sl', $this->alasan($hasil));
    }

    /** Toggle mati + `Sl` terisi: angkanya DIABAIKAN, varian tetap non-sinker. */
    #[Test]
    public function toggle_sinker_mati_mengabaikan_angka_yang_terlanjur_terisi(): void
    {
        $hasil = $this->hitung(['pakai_beban_tambahan' => false]);

        $this->assertTrue($hasil['boleh_terbit']);
        $this->assertNull(
            $hasil['praolah']['sinker'],
            'toggle mati mestinya membuang beban tambahan, bukan diam-diam memakainya',
        );
    }

    #[Test]
    public function diameter_stem_bukan_tiga_ukuran_ditolak(): void
    {
        foreach ([[0.708, 0.710], [0.708, 0.710, 0.709, 0.711], []] as $d) {
            $hasil = $this->hitung(['diameter_stem' => $d]);

            $this->assertSame([], $hasil['titik'], count($d).' ukuran mestinya ditolak');
            $this->assertStringContainsString('Diameter stem', $this->alasan($hasil));
            $this->assertStringContainsString('tepat 3', $this->alasan($hasil));
        }
    }

    /**
     * Tanpa tekanan udara tidak ada densitas udara, dan tanpa densitas udara
     * tidak ada satu pun suku rumus Cuckow yang bisa dihitung.
     *
     * Kertas `SIDIK-FM-CAL-0533_Rev.2` tidak mencetak kotaknya sama sekali —
     * lihat `docs/pertanyaan-lab-hydrometer.md` §9 — jadi sesi tanpa tekanan
     * bukan hal yang mustahil, justru yang paling mungkin datang.
     */
    #[Test]
    public function tanpa_tekanan_udara_sesi_diblokir(): void
    {
        $hasil = $this->hitung(['tekanan_awal' => null, 'tekanan_akhir' => null]);

        $this->assertSame([], $hasil['titik']);
        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertStringContainsString('Tekanan udara', $this->alasan($hasil));
    }

    #[Test]
    public function massa_hydrometer_di_udara_kosong_diblokir(): void
    {
        $hasil = $this->hitung(['massa_udara' => null]);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('Ma', $this->alasan($hasil));
    }

    /**
     * Ulangan yang bukan TIGA ditolak, bukan dihitung dengan pembagi yang salah.
     *
     * Komponen pertama budget memakai pembagi √3 dan `vi = n − 1 = 2`. Empat
     * ulangan yang lolos memakai pembagi yang sama menghasilkan `uc` yang
     * salah tanpa satu pun gejala.
     */
    #[Test]
    public function ulangan_bukan_tiga_ditolak(): void
    {
        foreach ([
            'massa dua, suhu tiga' => [[21.2727, 21.2726], [20.6, 20.6, 20.6]],
            'massa empat, suhu tiga' => [[21.2727, 21.2726, 21.2856, 21.28], [20.6, 20.6, 20.6]],
            'massa tiga, suhu dua' => [[21.2727, 21.2726, 21.2856], [20.6, 20.6]],
        ] as $label => [$massa, $suhu]) {
            $hasil = (new HydrometerCalculator)->hitungSesi(
                [['titik_ke' => 1, 'titik_ukur' => 0.610, 'massa' => $massa, 'suhu' => $suhu]],
                self::BLOK,
            );

            $this->assertSame([], $hasil['titik'], $label.' mestinya ditolak');
            $this->assertStringContainsString('tepat 3 ulangan', $this->alasan($hasil), $label);
        }
    }

    /**
     * Titik PERTAMA menentukan faktor koreksi suhu SELURUH sesi (temuan 4) dan
     * penyebut tiga koefisien sensitivitas (temuan 7).
     *
     * Kalau urutannya ikut urutan array yang datang dari `groupBy` jalur hitung
     * ulang, sesi yang sama bisa keluar angka berbeda tiap kali dihitung ulang
     * — dan bedanya muncul sebagai `hitung_ulang_beda` di tiap approve, yang
     * mengajari admin menekan "setujui tetap" secara refleks.
     */
    #[Test]
    public function urutan_titik_tidak_mengubah_hasil(): void
    {
        $titik = [
            ['titik_ke' => 1, 'titik_ukur' => 0.610, 'massa' => [21.2727, 21.2726, 21.2856], 'suhu' => [20.6, 20.6, 20.6]],
            ['titik_ke' => 2, 'titik_ukur' => 0.625, 'massa' => [22.7483, 22.7446, 22.7491], 'suhu' => [20.6, 20.6, 20.6]],
            ['titik_ke' => 3, 'titik_ukur' => 0.650, 'massa' => [25.4602, 25.4608, 25.4621], 'suhu' => [20.7, 20.7, 20.7]],
        ];

        $urut = (new HydrometerCalculator)->hitungSesi($titik, self::BLOK);
        $acak = (new HydrometerCalculator)->hitungSesi([$titik[2], $titik[0], $titik[1]], self::BLOK);

        foreach ($urut['titik'] as $i => $t) {
            $this->assertSame(
                $t['densitas'],
                $acak['titik'][$i]['densitas'],
                'titik ke-'.$t['titik_ke'].': urutan array mengubah densitas',
            );
            $this->assertSame(
                $t['ketidakpastian_gabungan'],
                $acak['titik'][$i]['ketidakpastian_gabungan'],
                'titik ke-'.$t['titik_ke'].': urutan array mengubah uc',
            );
        }
    }

    /**
     * Keyboard angka HP Indonesia menampilkan KOMA.
     *
     * §8.2 butir 1 dokumen analisis, dan angkanya persis dari sana:
     * `"21,2727"` harus tersimpan `21.2727` — bukan `212727` (koma dibuang)
     * dan bukan `21` (dipotong di koma). Dua-duanya lolos `(float)` PHP tanpa
     * satu pun error.
     */
    #[Test]
    public function koma_desimal_dibakukan_jadi_titik(): void
    {
        $this->assertSame('21.2727', AngkaDesimal::bakukan('21,2727'));
        $this->assertSame('20.6', AngkaDesimal::bakukan('20,6'));
        $this->assertSame('933.15', AngkaDesimal::bakukan('933,15'));
        $this->assertSame('0.0005', AngkaDesimal::bakukan('0,0005'));

        $this->assertSame(
            ['21.2727', '21.2726', '21.2856'],
            AngkaDesimal::bakukanDalam(['21,2727', '21,2726', '21,2856']),
        );

        // Dua pemisah SENGAJA tidak ditebak: salah satunya pemisah ribuan, dan
        // menebak yang mana bisa menggeser angka seribu kali tanpa error.
        // Dibiarkan apa adanya supaya aturan `numeric` menolaknya dengan jelas.
        $this->assertSame('1.234,5', AngkaDesimal::bakukan('1.234,5'));
        $this->assertSame('21,27,27', AngkaDesimal::bakukan('21,27,27'));
    }

    /**
     * Angka yang sudah dibakukan benar-benar sampai ke densitas.
     *
     * Test di atas menjaga pembakunya; yang ini menjaga bahwa hasilnya dipakai
     * — sesi yang seluruh angkanya diketik berkoma harus memberi densitas yang
     * SAMA PERSIS dengan sesi master.
     */
    #[Test]
    public function sesi_berkoma_desimal_memberi_densitas_yang_sama(): void
    {
        $bakukan = static fn (array $x): array => array_map(
            static fn ($v): float => (float) AngkaDesimal::bakukan($v),
            $x,
        );

        $hasil = (new HydrometerCalculator)->hitungSesi(
            [[
                'titik_ke' => 1,
                'titik_ukur' => 0.610,
                'massa' => $bakukan(['21,2727', '21,2726', '21,2856']),
                'suhu' => $bakukan(['20,6', '20,6', '20,6']),
            ]],
            ['tekanan_awal' => (float) AngkaDesimal::bakukan('933,2')] + self::BLOK,
        );

        $this->assertEqualsWithDelta(
            0.6039099485606535,
            $hasil['titik'][0]['densitas'],
            1.0e-12,
            'angka berkoma mestinya memberi densitas yang sama persis dengan master',
        );
    }

    /**
     * `HydrometerMentah::blokSesi()` membaca toggle sebagai BOOLEAN eksplisit.
     *
     * HP bisa mengirim `true`, `"1"`, `1`, atau `"true"` — dan yang tidak
     * dikenali harus jatuh ke `false` (varian non-sinker), bukan ke `true`:
     * salah ke arah itu membuat sesi tanpa sinker dihitung seolah punya, dan
     * `Sl` kosong terbaca nol yang membuat `Q = 0` — densitas bergeser diam-diam.
     */
    #[Test]
    public function varian_dibaca_dari_dua_bentuk_kiriman_yang_sah(): void
    {
        // `ya` = dropdown lembar kerja HP; boolean = seeder, test, klien lain.
        foreach (['ya', 'YA', ' ya ', true, 'true', '1', 1] as $nyala) {
            $blok = HydrometerMentah::blokSesi([
                HydrometerMentah::KUNCI_SESI => ['pakai_beban_tambahan' => $nyala],
            ]);
            $this->assertTrue($blok['pakai_beban_tambahan'], var_export($nyala, true).' mestinya dibaca nyala');
        }

        // Yang tidak dikenali jatuh ke NON-sinker, bukan sinker — lihat
        // `HydrometerMentah::pakaiBebanTambahan()` soal kenapa arah itu yang
        // aman.
        foreach (['tidak', 'TIDAK', false, 'false', '0', 0, null, 'entah'] as $mati) {
            $blok = HydrometerMentah::blokSesi([
                HydrometerMentah::KUNCI_SESI => ['pakai_beban_tambahan' => $mati],
            ]);
            $this->assertFalse($blok['pakai_beban_tambahan'], var_export($mati, true).' mestinya dibaca mati');
        }
    }

    /** Sesi tanpa blok Pre Condition sama sekali balik `null`, bukan blok kosong. */
    #[Test]
    public function blok_sesi_tidak_ada_balik_null(): void
    {
        $this->assertNull(HydrometerMentah::blokSesi(null));
        $this->assertNull(HydrometerMentah::blokSesi([]));
        $this->assertNull(HydrometerMentah::blokSesi(['micrometer' => ['satuan' => 'mm']]));
    }

    /** Satuan densitas dikonversi ke g/ml, satuan tak dikenal dibaca g/ml. */
    #[Test]
    public function satuan_densitas_dikonversi_ke_gram_per_ml(): void
    {
        $this->assertSame(0.61, HydrometerMentah::keGramPerMl(0.61, 'g/ml'));
        $this->assertSame(0.61, HydrometerMentah::keGramPerMl(610.0, 'kg/m3'));
        $this->assertSame(0.61, HydrometerMentah::keGramPerMl(0.61, 'entah'));
    }

    /** @param  array<string, mixed>  $rusak */
    private function hitung(array $rusak): array
    {
        return (new HydrometerCalculator)->hitungSesi(self::TITIK, [...self::BLOK, ...$rusak]);
    }

    /** @param  array<string, mixed>  $hasil */
    private function alasan(array $hasil): string
    {
        return implode(' ', array_map(
            static fn (array $d): string => (string) $d['alasan'],
            $hasil['ditolak'],
        ));
    }
}
