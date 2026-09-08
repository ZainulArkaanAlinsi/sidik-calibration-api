<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarFlowmeter;

/**
 * **Flow Meter Cairan (Totalizer)** — alat ke-27, lampiran akreditasi
 * LK-285-IDN no. 30 (10–78 L pada 0,76 % dan 78–1991 L pada 1,2 % of reading).
 *
 * Sumbernya `1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L)
 * 2026.xlsm`. Seluruh bentuk lembar, mesin hitung, dan jejak auditnya di
 * [FlowmeterProfile]; yang di sini cuma yang membedakannya dari saudaranya.
 *
 * **Delapan komponen budget, bukan sembilan.** Workbook ini belum ikut revisi
 * 20 Mei 2026 yang menambahkan "Pengulangan Pembacaan UUT" ke Flowrate.
 * Ditiru apa adanya — menambahkannya sendiri berarti menggeser U95 yang sudah
 * tercetak di sertifikat pelanggan. Pertanyaan lab §3.
 *
 * **Pembacaan UUT-nya DATAR** (tiga ulangan, satu angka masing-masing), beda
 * dari Flowrate yang bersarang tiga durasi per ulangan.
 */
class FlowmeterTotalizerProfile extends FlowmeterProfile
{
    public function kode(): string
    {
        return 'flowmeter_totalizer';
    }

    /**
     * PERSIS baris lampiran akreditasi no. 30 — kurungnya ikut.
     * `CmcSemuaProfilTest` mengadunya kata per kata ke
     * `database/data/kemampuan-kalibrasi.json`.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Flow Meter Cairan (Totalizer)';
    }

    /** @return list<string> */
    public function aliasNama(): array
    {
        return [
            'Flowmeter Totalizer',
            'Flow Meter Totalizer',
            'Ultrasonic Flowmeter Totalizer',
            'Water Meter',
        ];
    }

    public function mode(): string
    {
        return TabelStandarFlowmeter::MODE_TOTALIZER;
    }

    public function satuanHasil(): string
    {
        return 'L';
    }

    public function nomorLingkupAkreditasi(): int
    {
        return 30;
    }

    /**
     * Tiga desimal liter.
     *
     * Sesi contoh membaca `1013,852 L` di standar dan `1000,800 L` di UUT —
     * koreksinya `−13,052 L`. Di dua desimal deviasi antar-titik masih terbaca,
     * tapi koreksi tabel standar (`−0,965 L` di titik 100 L) kehilangan angka
     * pentingnya.
     */
    public function desimalSertifikat(): ?int
    {
        return 3;
    }

    public function desimalU95(): ?int
    {
        return 3;
    }
}
