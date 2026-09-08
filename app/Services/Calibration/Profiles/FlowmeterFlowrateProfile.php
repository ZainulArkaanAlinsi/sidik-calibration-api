<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarFlowmeter;

/**
 * **Flow Meter Cairan (Flowrate)** — alat ke-28, lampiran akreditasi
 * LK-285-IDN no. 31 (75–191 Lpm pada 0,76 % dan 190,6–519,4 Lpm pada 1,2 %
 * of reading).
 *
 * Sumbernya `Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm`. Seluruh
 * bentuk lembar, mesin hitung, dan jejak auditnya di [FlowmeterProfile]; yang
 * di sini cuma yang membedakannya dari saudaranya.
 *
 * **Sembilan komponen budget.** `FORM VALIDASI` workbook ini punya baris kedua
 * (20 Mei 2026, PIC `NR`): *"Merubah all budget ketidakpastian ; menambahkan
 * stdev untuk UUT ; menambahkan keterangan spek pipa pada sheet sertifikat"* —
 * dan dari situ komponen ke-9 "Pengulangan Pembacaan UUT" lahir. Workbook
 * Totalizer belum ikut revisi itu; keduanya ditiru apa adanya. Pertanyaan lab §3.
 *
 * **Pembacaan UUT-nya BERSARANG.** Tiap ulangan berisi tiga durasi, dan
 * simpangan bakunya dihitung atas ketiga durasi ulangan ITU — bukan antar
 * ulangan. Tercampur, komponen ke-9 keluar jauh lebih besar. Lihat
 * `FlowmeterMentah::deretBersarang`.
 */
class FlowmeterFlowrateProfile extends FlowmeterProfile
{
    public function kode(): string
    {
        return 'flowmeter_flowrate';
    }

    /**
     * PERSIS baris lampiran akreditasi no. 31 — kurungnya ikut.
     * `CmcSemuaProfilTest` mengadunya kata per kata ke
     * `database/data/kemampuan-kalibrasi.json`.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Flow Meter Cairan (Flowrate)';
    }

    /** @return list<string> */
    public function aliasNama(): array
    {
        return [
            'Flowmeter Flowrate',
            'Flow Meter Flowrate',
            'Ultrasonic Flowmeter',
            'Flow Rate Meter',
        ];
    }

    public function mode(): string
    {
        return TabelStandarFlowmeter::MODE_FLOWRATE;
    }

    /**
     * `Lpm`, dengan huruf persis begitu.
     *
     * Kunci dropdown satuannya di `tabel-standar-flowmeter.json` ditulis `LPM`
     * (huruf besar) sementara lampiran akreditasi dan sertifikatnya menulis
     * `Lpm`. Yang dicetak versi lampiran; yang dicocokkan `faktorSatuan()`
     * versi tabel. Jangan disamakan salah satunya tanpa mengubah keduanya.
     */
    public function satuanHasil(): string
    {
        return 'Lpm';
    }

    public function nomorLingkupAkreditasi(): int
    {
        return 31;
    }

    /**
     * Tiga desimal Lpm.
     *
     * Sesi contoh membaca `506,822 Lpm` di standar dan `500,785 Lpm` di UUT.
     * Koreksi tabel standar di titik terkecil `−0,994 Lpm` — di dua desimal dia
     * jadi `−0,99` dan kehilangan angka penting terakhirnya.
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
