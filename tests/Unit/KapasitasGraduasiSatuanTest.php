<?php

namespace Tests\Unit;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Services\CertificateSnapshotBuilder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Baris `Capacity/Graduation` di sertifikat nulis satuan TIAP BAND resolusi,
 * bukan satuan alatnya diborong ke semua baris.
 *
 * **Kenapa test ini ada.** Spectrophotometer ngukur dua besaran dengan satu
 * alat: 19 band panjang gelombang (`nm`, resolusi 0,01) dan 5 band transmitansi
 * (`%T`, resolusi 0,001). Kolom `equipments.satuan` cuma muat satu, dan yang
 * kesimpen di situ `nm`.
 *
 * Waktu satuan alat dipakai buat semua band, resolusi %T kecetak `0,001 nm` —
 * angka yang BENAR dengan satuan yang SALAH. Itu bentuk kekeliruan yang paling
 * susah ketahuan: nggak ada error, dokumennya kelihatan rapi, dan yang baca
 * cuma bisa curiga kalau dia hafal alatnya nggak punya resolusi 0,001 nm.
 *
 * Ketemu 21 Agt 2026 waktu sertifikat CAL/2026/08/0052 diadu ke master
 * Excel-nya baris per baris: seluruh 23 baris pengukuran, ketiga U95, dan kedua
 * faktor cakupan COCOK PERSIS — yang meleset cuma satuan di baris ini.
 *
 * Conductivity kena hal yang sama dan ikut kebetulan: tiga standarnya punya
 * satuan campur (µS/cm, µS/cm, mS/cm) di satu alat ber-`satuan` mS/cm.
 */
class KapasitasGraduasiSatuanTest extends TestCase
{
    private function kapasitas(Equipment $alat): ?string
    {
        $metode = new ReflectionMethod(CertificateSnapshotBuilder::class, 'kapasitasGraduasi');
        $metode->setAccessible(true);

        $sesi = new CalibrationSession;
        $sesi->setRelation('equipment', $alat);

        return $metode->invoke(app(CertificateSnapshotBuilder::class), $sesi);
    }

    public function test_alat_dua_besaran_nulis_satuan_masing_masing(): void
    {
        $alat = new Equipment([
            'nama_alat' => 'Visible Spectrofotometer',
            'satuan' => 'nm',
            'range_min' => 200,
            'range_max' => 700,
            'resolusi_rentang' => [
                ['titik' => 287.7, 'satuan' => 'nm', 'resolusi' => 0.01],
                ['titik' => 9.9, 'satuan' => '%T', 'resolusi' => 0.001],
            ],
        ]);

        $hasil = $this->kapasitas($alat);

        $this->assertStringContainsString('0,01 nm', $hasil);
        $this->assertStringContainsString(
            '0,001 %T',
            $hasil,
            'Resolusi band %T mesti bawa satuannya sendiri.'
        );
        $this->assertStringNotContainsString(
            '0,001 nm',
            $hasil,
            'Resolusi %T kecetak sebagai nm — angka benar, satuan salah. '
            .'Itu persis kekeliruan yang bikin sertifikat CAL/2026/08/0052 '
            .'beda dari masternya.'
        );
    }

    public function test_conductivity_satuan_campur_ikut_kejaga(): void
    {
        $alat = new Equipment([
            'nama_alat' => 'Conductivity Meter',
            'satuan' => 'mS/cm',
            'range_min' => 0,
            'range_max' => 100,
            'resolusi_rentang' => [
                ['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
                ['titik' => 1412, 'satuan' => 'µS/cm', 'resolusi' => 1],
                ['titik' => 111, 'satuan' => 'mS/cm', 'resolusi' => 0.01],
            ],
        ]);

        $hasil = $this->kapasitas($alat);

        // Persis tiga kotak resolusi di lembar kerja Conductivity.
        $this->assertStringContainsString('0,1 µS/cm', $hasil);
        $this->assertStringContainsString('1 µS/cm', $hasil);
        $this->assertStringContainsString('0,01 mS/cm', $hasil);
    }

    /**
     * Alat bersatuan seragam nggak boleh ikut berubah — band tanpa `satuan`
     * tetap jatuh ke satuan alatnya.
     */
    public function test_alat_satu_satuan_nggak_berubah_perilakunya(): void
    {
        $alat = new Equipment([
            'nama_alat' => 'Turbidimeter',
            'satuan' => 'NTU',
            'range_min' => 0,
            'range_max' => 1000,
            'resolusi_rentang' => [
                ['maks' => 10, 'resolusi' => 0.01],
                ['maks' => 100, 'resolusi' => 0.1],
                ['maks' => 1000, 'resolusi' => 1],
            ],
        ]);

        $hasil = $this->kapasitas($alat);

        foreach (['0,01 NTU', '0,1 NTU', '1 NTU'] as $harap) {
            $this->assertStringContainsString($harap, $hasil);
        }
    }
}
