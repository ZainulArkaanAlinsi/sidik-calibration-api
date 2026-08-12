<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\Profiles\TurbidimeterProfile;
use App\Support\Angka;
use PHPUnit\Framework\TestCase;

/**
 * Koreksi negatif yang MEMBULAT KE NOL: `-0,0` atau `0,0`?
 *
 * Pertanyaannya kelihatan sepele dan udah dua kali dijawab dari nalar — dua
 * kali salah, ke arah yang berlawanan:
 *
 *  1. Awalnya tandanya dibuang ("bikin orang ngira ada koreksi negatif padahal
 *     nol"). Dibalik 10 Agt 2026 waktu master Turbidimeter `0189-CAL-624`
 *     diadu langsung: kertasnya nulis `-0,00` & `-0,0`.
 *  2. Lalu dipatok "tandanya SELALU dipertahankan". Itu juga salah. Master
 *     Conductivity nyimpen koreksi yang SAMA PERSIS kayak yang dihitung sistem
 *     (`07 - SERTIFIKAT STYLE 1.csv` baris 35: `25` · `25.04` ·
 *     `-0.03999999999999915`) tapi nyetaknya `0,0`, tanpa minus.
 *
 * Jadi jawabannya nggak ada satu — dua dokumen resmi lab jawabnya beda buat
 * bentuk angka yang sama. Yang dikunci di sini: keputusannya diambil PER ALAT
 * dan dibaca dari master masing-masing, bukan diturunkan ulang dari prinsip.
 */
class AngkaTandaNolTest extends TestCase
{
    /**
     * Master Turbidimeter `0189-CAL-624`, diadu ke kertasnya 10 Agt 2026.
     *
     * Tandanya bukan hiasan di lembar ini: dia yang bilang alatnya baca DI ATAS
     * standar, di baris yang angkanya sendiri kebaca nol.
     */
    public function test_tanda_dipertahankan_kalau_masternya_gitu(): void
    {
        $this->assertSame('-0,00', Angka::hasil(-0.004, 2));
        $this->assertSame('-0,0', Angka::hasil(-0.02, 1));
    }

    /**
     * Master Conductivity, titik 25 µS/cm: sel-nya `-0.03999999999999915`,
     * cetaknya `0,0` (1 desimal, ngikut resolusi 0,1 µS/cm).
     */
    public function test_tanda_dibuang_kalau_masternya_gitu(): void
    {
        $this->assertSame('0,0', Angka::hasil(-0.03999999999999915, 1, tandaNol: false));
        $this->assertSame('0,00', Angka::hasil(-0.001, 2, tandaNol: false));
    }

    /**
     * `tandaNol: false` cuma nyentuh baris yang membulat ke nol — bukan izin
     * ngebuang minus di mana-mana.
     *
     * Di sertifikat Conductivity yang sama, dua titik lain koreksinya `-1` dan
     * `0,52`. Kalau setelan ini bocor ke situ, koreksi `-1 µS/cm` kecetak
     * `1 µS/cm` — arah koreksinya kebalik, dan pelanggan yang nerapin bakal
     * ngegeser pembacaannya ke arah yang salah.
     */
    public function test_minus_yang_bukan_nol_nggak_ikut_dibuang(): void
    {
        $this->assertSame('-1', Angka::hasil(-1.0, 0, tandaNol: false));
        $this->assertSame('-0,01', Angka::hasil(-0.01, 2, tandaNol: false));
        $this->assertSame('-0,1', Angka::hasil(-0.05, 1, tandaNol: false));
    }

    /**
     * Default-nya `true`, dan itu disengaja: empat dari lima alat
     * mempertahankan tandanya. Pemanggil yang lupa ngoper flag-nya jatuh ke
     * perilaku lama, bukan ke perilaku Conductivity.
     */
    public function test_bawaannya_mempertahankan_tanda(): void
    {
        $this->assertSame('-0,00', Angka::hasil(-0.004, 2));
        $this->assertSame(Angka::hasil(-0.004, 2), Angka::hasil(-0.004, 2, tandaNol: true));
    }

    /**
     * Yang mutusin profil alat, bukan pemanggilnya. Dikunci per kelas biar
     * ketahuan kalau ada alat baru yang diam-diam ngikut Conductivity — bentuk
     * angka di dokumen terakreditasi nggak boleh berubah karena kelas baru
     * kebetulan mewarisi default yang salah.
     */
    public function test_cuma_conductivity_yang_ngebuang_tandanya(): void
    {
        $this->assertFalse((new ConductivityProfile)->tandaNolDicetak());

        $mempertahankan = [
            PhMeterProfile::class,
            TurbidimeterProfile::class,
            ChlorineProfile::class,
            RefractometerProfile::class,
            SpectrophotometerProfile::class,
        ];

        foreach ($mempertahankan as $kelas) {
            $this->assertTrue(
                (new $kelas)->tandaNolDicetak(),
                $kelas.' mestinya mempertahankan tanda minus.',
            );
        }
    }
}
