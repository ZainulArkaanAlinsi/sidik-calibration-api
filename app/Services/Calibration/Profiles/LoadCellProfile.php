<?php

namespace App\Services\Calibration\Profiles;

/**
 * Load Cell — alat gaya kedua, sebangun UTM tapi TIDAK identik.
 *
 * ## Yang sama, dan itu sudah dibuktikan sebelum kelas ini ditulis
 *
 * Rantai hitungnya persis sama. Enam angka sesi contoh master (`R`, `S`, `T`,
 * `Y`, `Z`, `AA`) direproduksi bit-per-bit oleh `GayaCalculator` yang sudah ada,
 * tanpa satu baris rumus baru — itu yang membuat kelas ini cuma berisi data.
 *
 * ## Tiga hal yang BEDA, dan ketiganya menggeser angka kalau tertukar
 *
 * 1. **Satuannya kN, bukan kgf.** Resolusi alatnya 0,01 kN, jadi sertifikatnya
 *    mencetak DUA desimal — bukan satu seperti UTM yang resolusinya 0,1 kgf.
 *    Menyamakannya membuat `2,15` runtuh jadi `2,2`.
 *
 * 2. **Kolom `Standard Value` memakai Y, bukan Z.** Sertifikat masternya
 *    mencetak `2,1530386700000004` — nilai SEBELUM koreksi termal, sementara
 *    UTM mencetak yang sesudah. Dua workbook, satu rumus, dua jawaban (G12).
 *    Di sini Y dipakai untuk SEMUA satuan, sementara master cuma memakainya di
 *    cabang kN — penyimpangan yang disengaja, alasannya di
 *    [pakaiKoreksiTermalDiSertifikat].
 *
 * 3. **Drift standarnya beda dari yang ditulis workbook UTM** untuk standar
 *    yang SAMA: arah Tarik `0,04` di sini, `0` di sana (G2). Karena itu
 *    [sumberDrift] menyebut workbook-nya sendiri, bukan satu nilai bersama.
 *
 * ## Yang perlu diketahui tentang sesi contohnya
 *
 * Sesi master menguji 0–100 kN memakai standar 100 kN yang tabel kalibrasinya
 * mulai dari 9,8 kN dan berhenti di 88,3 kN. Artinya **setiap titiknya di luar
 * rentang tabel** — yang di bawah maupun yang di atas — dan koreksi standarnya
 * diambil dari titik terdekat, bukan interpolasi tervalidasi. Master diam soal
 * itu; sistem ini menandainya per titik (G10).
 */
class LoadCellProfile extends GayaProfile
{
    public const KODE = 'load_cell';

    /** Sama dengan UTM: tiga load cell standar lab yang tercetak di lembar. */
    public const STANDARD_TERCETAK = UtmProfile::STANDARD_TERCETAK;

    public const THERMOHYGRO_TERCETAK = UtmProfile::THERMOHYGRO_TERCETAK;

    public function kode(): string
    {
        return self::KODE;
    }

    /** Sama persis dengan baris lampiran akreditasi LK-285-IDN. */
    public function namaAlatKemampuan(): string
    {
        return 'Load Cell';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            'Load Cell Standar',
            'Loadcell',
            'Load Cell Indicator',
            'Sel Beban',
        ];
    }

    public function kodeFormula(): string
    {
        return 'GAYA-LOAD-CELL';
    }

    public function sumberDrift(): string
    {
        return 'load_cell';
    }

    /**
     * Workbook Load Cell mencetak Y — SEBELUM koreksi termal.
     *
     * `SERTIFIKAT!D26 = 2,1530386700000004 kN`, dan itu `Y`, bukan `Z`
     * (`2,1505680581261752`). Beda dari UTM yang mencetak Z; lihat G12.
     *
     * ## Satu cabang, bukan enam — dan itu yang membuat ini penyimpangan sadar
     *
     * Rumus aslinya bercabang per satuan tampilan, dan Y cuma mendarat di
     * cabang `kN`:
     *
     * ```
     * =IF('PERHITUNGAN FC'!Z30="","",
     *    IF(G15="kN",  'PERHITUNGAN FC'!Y30/DATABASE!$S$20,      <- Y
     *    IF(G15="N",   'PERHITUNGAN FC'!Z30/DATABASE!$S$21,      <- Z
     *    IF(G15="lbf", 'PERHITUNGAN FC'!Z30/…                    <- Z
     * ```
     *
     * Penjaga `IF(Z30="","")` di depannya pun masih menguji Z. Bentuk itu —
     * satu cabang disunting, lima tertinggal, penjaganya tidak ikut — adalah
     * suntingan yang berhenti di tengah, bukan keputusan metode. Kalau ditiru
     * apa adanya, angka yang TERCETAK di sertifikat berubah arti tergantung
     * satuan tampilan yang dipilih teknisi, dan tidak ada satu pun error yang
     * muncul.
     *
     * Jadi Y dipakai untuk SEMUA satuan. Sesi bersatuan kN — satu-satunya yang
     * pernah dijalankan lab untuk alat ini, termasuk sesi masternya sendiri —
     * identik byte per byte dengan master. Selisihnya ditulis di jejak audit
     * (`penyimpangan_master.standard_value_hanya_cabang_kn`), bukan cuma di
     * komentar ini, karena yang menyetujui sesi tidak membuka kode.
     */
    public function pakaiKoreksiTermalDiSertifikat(): bool
    {
        return false;
    }

    /** Baris `Methode :` di `SIDIK-FM-CAL-0520_Rev.3 — LEMBAR KERJA LOAD CELL.pdf`. */
    public function kodeMetodeIk(): string
    {
        return 'SIDIK-IK-CAL-0514_Rev.3';
    }

    protected function kodeDokumen(): string
    {
        return 'SIDIK-FM-CAL-0520_Rev.3';
    }

    protected function judulLembar(): string
    {
        return 'Lembar Kerja Kalibrasi Load Cell';
    }

    protected function sumberMaster(): string
    {
        return 'Master Olah Data Gaya — Load Cell (.xlsm, PERHITUNGAN FC & PERHITUNGAN U95%)';
    }

    /**
     * DUA desimal, bukan satu.
     *
     * `Capacity/Graduation : 100 kN / 0,01 kN` di kepala sertifikat masternya.
     * Mencetak satu desimal berarti mengaku ketelitian yang lebih kasar dari
     * yang alatnya tampilkan, dan angka seperti `2,15` runtuh jadi `2,2`.
     */
    public function desimalSertifikat(): ?int
    {
        return 2;
    }

    public function desimalU95(): ?int
    {
        return 2;
    }

    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /** Sama dengan UTM — lihat docblock di sana. */
    public function bentukPindaiFoto(): array
    {
        return [
            'kolom_suhu' => false,
            'standar_di_baris' => false,
            'didukung' => false,
            'lokal' => true,
        ];
    }
}
