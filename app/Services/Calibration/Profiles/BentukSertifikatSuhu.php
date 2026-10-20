<?php

namespace App\Services\Calibration\Profiles;

/**
 * Bentuk lembar `SERTIFIKAT` master keluarga Suhu (K-50-15, pemilik 18 Okt
 * 2026: sertifikat persis lembar SERTIFIKAT master alatnya). Tampilan saja —
 * tidak ada angka yang dihitung di sini.
 *
 * Dibaca 20 Okt 2026 dari Studio (`workbook_master` aktif):
 *
 * - Judul kolom dengan satuan DI DALAM judulnya, tanpa baris satuan:
 *   `Standard Reading (oC)` · `Unit Under Test (oC)` · `Correction (oC)`
 *   (TITS `173!E19:L19`, `174!E15:L15`; TIDS `193!E19`, `194!E19`;
 *   Thermocouple `175!E19`; Glass `176!E20`). `oC` ditulis persis master.
 * - Label baris U95 `Uncertainty  95%  ±` (`173!E29`, `174!E24`, `193!E34`,
 *   `175!E34`, `176!E35`), angkanya di bawah kolom Correction (`L29`).
 * - Tabel Standard used tanpa titik dua: `Merk/Type`, `Serial Number`,
 *   `Traceable to SI through` (`173!I35:Q35`, `193!I40:Q40`, `175!I40:Q40`,
 *   `176!I41:Q41`, Thermohygro `154!I70:Q70`, Enclosure `171!I52:Q52`,
 *   Autoclave `155!G46:Q46`).
 */
trait BentukSertifikatSuhu
{
    /** `173!E29` — dua spasi di kiri-kanan `95%` ikut master. */
    public const LABEL_U95_SUHU = 'Uncertainty  95%  ±';

    /**
     * Kolom hasil TITS/TIDS/Thermocouple/Glass: satuan di judul, `satuan` ''
     * supaya tidak ada sufiks maupun baris satuan tambahan.
     *
     * @return list<array<string, mixed>>
     */
    protected static function kolomHasilSuhuMaster(): array
    {
        return [
            ['kolom' => 'standar', 'judul' => 'Standard Reading (oC)', 'satuan' => ''],
            ['kolom' => 'uut', 'judul' => 'Unit Under Test (oC)', 'satuan' => ''],
            ['kolom' => 'koreksi', 'judul' => 'Correction (oC)', 'satuan' => ''],
        ];
    }

    /** @return list<array{kunci: string, label: string}> */
    protected static function kolomStandarSuhuMaster(): array
    {
        return [
            ['kunci' => 'name', 'label' => 'Name'],
            ['kunci' => 'merk_type', 'label' => 'Merk/Type'],
            ['kunci' => 'serial_number', 'label' => 'Serial Number'],
            ['kunci' => 'traceable_to', 'label' => 'Traceable to SI through'],
        ];
    }

    /**
     * Timpaan bersama TITS/TIDS/Thermocouple/Glass di atas bentuk mayoritas.
     * `Issuance Date` tanpa titik dua (`173!B41`, `193!B47`, `175!B47`,
     * `176!B48`).
     *
     * @param  array<string, mixed>  $induk  `parent::tataLetakSertifikat($sesi)`
     * @return array<string, mixed>
     */
    protected static function tataLetakSuhuMaster(array $induk): array
    {
        return [
            ...$induk,
            'kolom_hasil' => self::kolomHasilSuhuMaster(),
            'satuan_baris_sendiri' => false,
            'label_u95' => self::LABEL_U95_SUHU,
            'kolom_standar_digunakan' => self::kolomStandarSuhuMaster(),
            'label_tanggal_terbit' => 'Issuance Date',
        ];
    }

    /**
     * Ganti label sel kondisi lingkungan di grid kepala (Thermohygro
     * `154!L13` `Environmental Condition`), sisanya tetap.
     *
     * @param  list<list<array{kunci: string, label: string}|null>>  $kepala
     * @return list<list<array{kunci: string, label: string}|null>>
     */
    protected static function labelKepala(array $kepala, string $kunci, string $label): array
    {
        return array_map(
            static fn (array $baris): array => array_map(
                static fn (?array $sel): ?array => ($sel['kunci'] ?? null) === $kunci ? [...$sel, 'label' => $label] : $sel,
                $baris,
            ),
            $kepala,
        );
    }
}
