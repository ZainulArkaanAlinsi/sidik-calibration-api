<?php

namespace App\Services\Calibration\Profiles;

/**
 * Profil **Infrared Tachometer** — lampiran akreditasi LK-285-IDN kelompok
 * "Waktu dan Frekuensi" no. 39, pita CMC 60–7000 rpm → 1,5 rpm dan
 * 7000–30000 rpm → 5,0 rpm.
 *
 * Seluruh mesin hitungnya di [ProfilPutaran]; yang dinyatakan di sini cuma
 * identitasnya. Lihat docblock basisnya untuk alasan kedua alat rpm berbagi
 * satu mesin.
 *
 * ## Blok 5 masternya rusak, dan itu tidak ditiru
 *
 * Dari enam blok budget di `Master Olda Tachometer.xlsm`, lima cocok dengan
 * hitungan kita sampai 5·10⁻⁶. Yang keenam (blok 5, rentang 10000–15000 rpm)
 * rusak di tiga tempat sekaligus — pita sertifikat meleset satu baris,
 * `u_drift` menunjuk sel kosong di workbook LAIN lewat tautan luar, dan
 * pengulangannya menunjuk baris kosong. Ketiganya membuat U95 master di blok
 * itu terlalu KECIL (1,69 rpm lawan 4,04 rpm hitungan benar), dan yang
 * menyelamatkannya dari terbit cuma lantai CMC 5,0 rpm yang kebetulan lebih
 * besar dari keduanya.
 *
 * Rinciannya di docblock `PutaranCalculator` dan
 * `docs/pertanyaan-lab-waktu-frekuensi.md` §2.
 */
class TachometerProfile extends ProfilPutaran
{
    /** Batas atas pita CMC tertinggi Tachometer di lampiran akreditasi. */
    private const BATAS_AKREDITASI_RPM = 30000.0;

    public function kode(): string
    {
        return 'tachometer';
    }

    /**
     * Persis seperti tertulis di lampiran akreditasi baris no. 39 — bukan
     * "Tachometer" saja. Nama yang meleset satu kata membuat baris CMC-nya
     * tidak ketemu, dan sesinya jatuh ke jalur generik tanpa lantai
     * ketidakpastian.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Infrared Tachometer';
    }

    /**
     * Ejaan lain yang PUNYA BUKTI di data, bukan tebakan:
     *
     *  - `Tachometer` — sheet `DATABASE` baris standar (`R13`) dan tabel metode
     *    kalibrasi no. 11 (`Centrifuge & Tachometer`) menulisnya begitu.
     *  - `Tachometer Digital` / `Digital Tachometer` — ejaan yang lazim di
     *    kolom `nama_alat` alat pelanggan untuk keping yang sama.
     *
     * Tidak memuat singkatan pendek: pencocokan menerima kunci yang menempel di
     * tengah nama, dan singkatan bakal diam-diam memberi lembar Tachometer ke
     * alat lain yang kebetulan memuatnya.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Tachometer', 'Tachometer Digital', 'Digital Tachometer'];
    }

    protected function judulLembar(): string
    {
        return 'Calibration Work Sheet - Infrared Tachometer';
    }

    /**
     * Lampiran LK-285-IDN no. 39 berhenti di 30000 rpm — lihat
     * `ProfilPutaran::peringatanSesi()`.
     *
     * @return array{float, int}
     */
    protected function batasAkreditasi(): array
    {
        return [self::BATAS_AKREDITASI_RPM, 39];
    }
}
