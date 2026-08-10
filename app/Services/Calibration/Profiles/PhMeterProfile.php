<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\LembarKerjaTemplate;

/**
 * Profil pH Meter — alat pertama, dan acuan bentuk buat semua alat lain.
 *
 * Lembar kerjanya SENGAJA didelegasikan ke `LembarKerjaTemplate` yang lama,
 * bukan disalin ke sini: bentuk pH itu udah kepakai di lapangan & dicover test
 * (`LembarKerjaTest`), jadi mindahin 440 baris cuma nambah risiko tanpa manfaat.
 * Profil yang butuh bentuk BEDA (Turbidimeter, dst) nulis bentuknya sendiri.
 *
 * Budget ketidakpastiannya (5 komponen) DIPINDAH ke sini dari
 * `GumCalculator::hitungDariBudgetPenuh()` yang lama — biar bentuk budget jadi
 * milik profil, dan `GumCalculator` cukup punya agregatornya yang generik.
 */
class PhMeterProfile extends CalibrationProfile
{
    public function __construct(private readonly LembarKerjaTemplate $template = new LembarKerjaTemplate) {}

    public function kode(): string
    {
        return 'ph_meter';
    }

    public function namaAlatKemampuan(): string
    {
        return 'pH Meter';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_PH;
    }

    public function besaran(): string
    {
        return 'ph';
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false): array
    {
        return $this->tautkanStandarTitik($this->template->phMeter($untukAdmin));
    }

    /**
     * Tiga baris tabel hasil (4,00 / 7,00 / 10,01) sama tiga buffer tercetak di
     * `LembarKerjaTemplate::STANDARD_TERCETAK`. Urutannya emang sejajar, tapi
     * ditulis eksplisit — pasangan yang cuma "kebetulan sejajar" bakal diam-diam
     * ketuker begitu ada yang nyisipin satu baris.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return [
            ['titik' => 4.00, 'standar' => ['pH Buffer Solution 4']],
            ['titik' => 7.00, 'standar' => ['pH Buffer Solution 7']],
            ['titik' => 10.01, 'standar' => ['pH Buffer Solution 10']],
        ];
    }

    /**
     * Budget 5 komponen gaya lampiran akreditasi pH (sheet `PERHITUNGAN U95%`
     * di workbook pH). Balik `null` kalau konstanta budgetnya belum lengkap —
     * `GumCalculator` bakal jatuh ke jalur CMC/generik, sama persis kayak dulu.
     *
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>|null
     */
    public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
    ): ?array {
        if (! $kemampuan->punyaBudgetPenuh()) {
            return null;
        }

        $sqrt3 = sqrt(3);
        $kStandar = $standard->faktor_cakupan ?: 2.0;

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator %s (U=%s %s, k=%s)',
                    $standard->nama, $standard->ketidakpastian, $standard->satuan_ketidakpastian ?? '', $kStandar,
                ),
                'distribusi' => 'normal',
                'u' => ($standard->ketidakpastian ?? 0.0) / $kStandar,
                'ci' => 1.0,
                'vi' => 200,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s', $equipment->resolusi, $equipment->satuan ?? ''),
                'distribusi' => 'persegi',
                'u' => ((float) $equipment->resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000,
            ],
            [
                'sumber' => 'ketidakpastian_temperature',
                'keterangan' => sprintf(
                    'UTemperature %s °C (÷k=2), ci %s',
                    $kemampuan->u_temperature, $kemampuan->ci_suhu,
                ),
                'distribusi' => 'normal',
                'u' => (float) $kemampuan->u_temperature / 2.0,
                'ci' => (float) $kemampuan->ci_suhu,
                'vi' => 200,
            ],
            [
                'sumber' => 'pengaruh_perbedaan_suhu',
                'keterangan' => sprintf(
                    'Pengaruh perbedaan suhu U=%s (÷√3), ci %s',
                    $kemampuan->u_perbedaan_suhu, $kemampuan->ci_perbedaan_suhu,
                ),
                'distribusi' => 'persegi',
                'u' => (float) $kemampuan->u_perbedaan_suhu / $sqrt3,
                'ci' => (float) $kemampuan->ci_perbedaan_suhu,
                'vi' => 50,
            ],
            [
                'sumber' => 'pengulangan_pembacaan',
                'keterangan' => sprintf('Pengulangan %d pembacaan (Type A)', $n),
                'distribusi' => 't-student',
                'u' => $typeA,
                'ci' => 1.0,
                'vi' => max($n - 1, 1),
            ],
        ];
    }

    /** Master pH: sel T format `0.0` → `21,0`. */
    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    /** Master pH: sel %RH format `General` → `51,95` (nggak dipangkas). */
    public function desimalKelembabanEnv(): ?int
    {
        return null;
    }
}
