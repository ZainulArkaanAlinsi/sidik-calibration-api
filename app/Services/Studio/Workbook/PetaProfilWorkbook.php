<?php

namespace App\Services\Studio\Workbook;

/**
 * Nama workbook (folder CSV atau nama berkas `.xlsm`) → kode profil.
 *
 * Sumber: `_agents_workspace/studio-data-acuan/01-riset-peta-workbook.md`
 * (13 Okt 2026, berdasarkan NAMA berkas saja) dan registry profil
 * `CalibrationProfileRegistry`. Aturannya:
 *
 *  - Satu workbook → satu profil: `kode_profil` diisi, walau profil itu punya
 *    beberapa workbook (mis. empat rentang Micrometer, dua TIDS).
 *  - Satu workbook → beberapa profil (keluarga) ATAU pemetaannya masih
 *    pertanyaan lab: `kode_profil = null`, `keluarga` diisi, `catatan`
 *    menyebut profil kandidat / nomor pertanyaannya.
 *  - Salinan per-alat (nama berkas memuat identitas satu alat) tetap dipetakan
 *    ke profilnya, ditandai di `catatan` — status kanoniknya pertanyaan
 *    pemilik Q4/Q7.
 *  - Tidak cocok pola mana pun: semua null, `catatan` = belum terpetakan.
 *
 * Pencocokan atas nama yang dinormalkan (huruf kecil, selain huruf/angka/titik
 * jadi spasi), jadi `Master_Olah_Data_Micrometer_0-25mm` dan
 * `Master Olah Data Micrometer 0-25mm.xlsm` mendarat di aturan yang sama.
 * Urutan aturan menentukan (yang spesifik dulu). Semua kode di sini wajib ada
 * di registry — dijaga `PetaProfilWorkbookTest`.
 */
final class PetaProfilWorkbook
{
    private const SALINAN = 'Salinan per-alat (nama berkas memuat identitas satu alat); status kanonik menunggu jawaban lab (01-riset-peta-workbook Q4/Q7).';

    /**
     * [pola, kode_profil, keluarga, catatan].
     *
     * @var list<array{0: string, 1: ?string, 2: ?string, 3: ?string}>
     */
    private const ATURAN = [
        // --- keluarga: satu workbook, beberapa profil
        ['/\bpiston\b/', null, 'piston_volume', 'Satu workbook untuk profil piston_pipette, dispensett, buret_digital.'],
        ['/\bdruck\b|\bdruck\s?\d/', null, 'tekanan_druck', 'Satu workbook untuk profil pressure_gauge dan vacuum_gauge.'],
        ['/fixed volumetric/', null, 'volumetric_fixed', 'Satu workbook untuk profil labu_ukur, pipet_volume, picnometer; pembagian per sheet = pertanyaan lab Q2.'],
        ['/graduated volumetric/', null, 'volumetric_graduated', 'Satu workbook untuk profil buret, gelas_ukur, pipet_ukur; pembagian per sheet = pertanyaan lab Q2.'],
        ['/enclosure/', null, 'enclosure', 'Satu workbook untuk profil oven, furnace, bath, inkubator, refrigerator; workbook mana untuk alat mana = pertanyaan lab Q3.'],
        ['/\bpipet ukur\b|\bpv\b/', null, 'volumetric', 'PV = pipet_ukur atau pipet_volume? (pertanyaan lab Q1). '.self::SALINAN],

        // --- tekanan satu-satu
        ['/differential/', 'differential_pressure', null, null],
        ['/\bspmk\b/', 'pressure_gauge', null, null],

        // --- volumetric salinan
        ['/\blabu ukur\b/', 'labu_ukur', null, self::SALINAN],

        // --- suhu
        ['/\btids\b/', 'tids', null, null],
        ['/\btits\b/', 'tits', null, null],
        ['/thermocouple|termocouple|termokopel/', 'thermocouple', null, null],
        ['/thermometer glass|termometer gelas|thermometer gelas/', 'thermometer_glass', null, null],
        ['/kelembapan|kelembaban|thermohygro|hygrometer/', 'thermohygro', null, null],

        // --- massa
        ['/anak timbangan/', 'anak_timbangan', null, null],
        ['/timbangan/', 'timbangan', null, null],

        // --- aliran
        ['/totalizer/', 'flowmeter_totalizer', null, null],
        ['/flowrate/', 'flowmeter_flowrate', null, null],

        // --- panjang
        ['/hydrometer/', 'hydrometer', null, null],
        ['/micrometer/', 'micrometer', null, null],
        ['/height gauge/', 'height_gauge', null, null],
        ['/caliper|jangka sorong/', 'jangka_sorong', null, null],
        ['/dial indicator/', 'dial_indicator', null, null],
        ['/sieve/', 'sieve', null, null],

        // --- gaya (berkas CSV memakai salah ketik "Profing Ring")
        ['/load cell/', 'load_cell', null, null],
        ['/pro[fv]ing ring/', 'proving_ring', null, null],
        ['/\butm\b/', 'utm', null, null],

        // --- waktu
        ['/centrifuge/', 'centrifuge', null, null],
        ['/tachometer/', 'tachometer', null, null],
        ['/timer|stopwatch/', 'timer_stopwatch', null, null],

        // --- instrumen analitik
        ['/autoclave/', 'autoclave', null, null],
        ['/chlorine/', 'chlorine_meter', null, null],
        ['/conductivity/', 'conductivity_meter', null, null],
        ['/\bdo meter\b/', 'do_meter', null, null],
        ['/refractometer/', 'refractometer', null, null],
        ['/spectro(f|ph)otometer/', 'spectrophotometer', null, null],
        ['/turbidimeter/', 'turbidimeter', null, null],
        ['/viscometer.*\d{6,}|\d{6,}.*viscometer/', 'viscometer', null, self::SALINAN],
        ['/viscometer/', 'viscometer', null, null],
        ['/gas detector/', 'gas_detector', null, self::SALINAN],
        ['/\bph meter\b.*\bimte\b/', 'ph_meter', null, self::SALINAN],
        ['/\bph meter\b/', 'ph_meter', null, null],
    ];

    /** @return array{kode_profil: ?string, keluarga: ?string, catatan: ?string} */
    public function cocokkan(string $nama): array
    {
        $normal = self::normalkan($nama);

        foreach (self::ATURAN as [$pola, $kode, $keluarga, $catatan]) {
            if (preg_match($pola, $normal) === 1) {
                return ['kode_profil' => $kode, 'keluarga' => $keluarga, 'catatan' => $catatan];
            }
        }

        return [
            'kode_profil' => null,
            'keluarga' => null,
            'catatan' => 'Belum terpetakan ke profil mana pun (nama tidak cocok aturan PetaProfilWorkbook).',
        ];
    }

    /**
     * Semua kode profil yang bisa dipulangkan peta ini — untuk test penjaga.
     *
     * @return list<string>
     */
    public static function kodeDipakai(): array
    {
        $kode = array_values(array_unique(array_filter(array_column(self::ATURAN, 1))));
        sort($kode);

        return $kode;
    }

    public static function normalkan(string $nama): string
    {
        $nama = (string) preg_replace('/\.(xlsm|xlsx)$/i', '', $nama);
        $nama = (string) preg_replace('/[^a-z0-9.]+/', ' ', mb_strtolower($nama, 'UTF-8'));

        return trim($nama);
    }
}
