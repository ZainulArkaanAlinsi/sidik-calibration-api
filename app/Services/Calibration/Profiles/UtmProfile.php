<?php

namespace App\Services\Calibration\Profiles;

/**
 * Mesin UTM (Universal Testing Machine) — alat gaya pertama yang mendarat.
 *
 * ## Kenapa UTM dikerjakan duluan dari tiga alat gaya
 *
 * Dia yang paling lengkap cabang CMC-nya (lima pita: Tekan & Tarik 0-500 kgf,
 * Tekan & Tarik 10-88 kN, Tekan 200-3000 kN) dan paling banyak datanya. Dan
 * yang menentukan: dari enam temuan di workbook master, TIGA ada di Proving
 * Ring. Kalau yang bermasalah dikerjakan duluan, susah membedakan "kode saya
 * salah" dari "masternya memang begitu" — dengan UTM yang relatif bersih, pola
 * benarnya sudah terbentuk sebelum menghadapi yang lain.
 *
 * ## Empat posisi, dan kenapa bukan sekadar pengulangan
 *
 * Mesin uji punya piringan tempat benda uji diletakkan. Kalau load cell standar
 * tidak tepat di sumbu tengah — atau piringannya sedikit miring — gaya tidak
 * jatuh lurus dan ada momen lentur yang ikut terbaca. Efeknya: hasil baca
 * berubah tergantung ke mana load cell dihadapkan.
 *
 * Diuji satu posisi saja, kesalahan itu tersembunyi. Karena itu 4 posisi x 3
 * replikat = 12 pembacaan per titik, dan karena itu pula ada komponen
 * `misalignment` di budget yang tidak ada di alat lain mana pun di repo ini.
 *
 * Sebarannya dilaporkan sebagai RRPE di sertifikat — angka yang memberi tahu
 * pelanggan seberapa konsisten mesinnya, dan yang membedakan "meleset tapi
 * konsisten" (bisa disetel) dari "rata-ratanya pas tapi acak" (masalah mekanis
 * yang tidak bisa diperbaiki dengan penyetelan).
 */
class UtmProfile extends GayaProfile
{
    public const KODE = 'utm';

    /**
     * Baris "Standard Used" yang TERCETAK di lembar — tiga load cell standar
     * lab, sama dengan `Tabel_Standar` di workbook master.
     *
     * Ditaut ke master `standards` lewat `tautkanStandarTercetak()`, bukan
     * di-query sendiri: yang di-query sendiri gampang lupa menyaring organisasi,
     * dan standar lab lain yang bocor ke lembar tidak menghasilkan error — cuma
     * ketertelusuran yang menunjuk alat yang bukan milik lab ini.
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Load Cell 5 kN', 'cocok' => ['Load Cell 5 kN', 'LC-01-SDK']],
        ['label' => 'Load Cell 100 kN', 'cocok' => ['Load Cell 100 kN', 'J10CC13283']],
        ['label' => 'Load Cell 3000 kN', 'cocok' => ['Load Cell 3000 kN', 'C-140-BZ/0008']],
    ];

    /** Dropdown "Thermohygro used" — ketujuh unit lab, sama dengan master. */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    public function kode(): string
    {
        return self::KODE;
    }

    /**
     * Sama persis dengan baris lampiran akreditasi LK-285-IDN.
     *
     * Nama yang meleset satu huruf bikin lantai CMC tidak ketemu, dan sesi tetap
     * terbit — dengan U95% yang lebih kecil dari kemampuan yang diakui.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Mesin UTM';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            'UTM',
            'Universal Testing Machine',
            'Mesin Uji Tarik',
            'Mesin Uji Tekan',
            'Universal Testing Machine (UTM)',
        ];
    }

    public function kodeFormula(): string
    {
        return 'GAYA-UTM';
    }

    /**
     * Nomor formulir dari kertas resminya, bukan karangan:
     * `worksheet_alat_calibration/SIDIK-FM-CAL-0519_Rev.3 - LEMBAR KERJA
     * COMPRESSION (UTM).pdf`.
     *
     * Workbook masternya sendiri DIAM soal ini — sapuan `SIDIK-FM-` di seluruh
     * folder `Alat_Gaya/` cuma menemukan `SIDIK-FM-CAL-2403`, dan itu formulir
     * SERTIFIKAT bersama (lihat preseden Height Gauge di
     * `SemuaProfilLembarKerjaTest`). Yang menjawab sumber kedua.
     */
    protected function kodeDokumen(): ?string
    {
        return 'SIDIK-FM-CAL-0519_Rev.3';
    }

    protected function judulLembar(): string
    {
        return 'Lembar Kerja Kalibrasi Mesin UTM';
    }

    protected function sumberMaster(): string
    {
        return 'Master Olah Data Gaya — UTM (.xlsm, PERHITUNGAN FC & PERHITUNGAN U95%)';
    }

    public function sumberDrift(): string
    {
        return 'utm';
    }

    /**
     * Workbook UTM mencetak Z di kolom `Standard Value`.
     *
     * `SERTIFIKAT!D26 = 200,7852728972789 kgf`, dan itu `1,969703527 kN ÷
     * 0,00981` — nilai SESUDAH koreksi termal. Workbook Load Cell mencetak Y di
     * kolom yang sama; keduanya direplikasi apa adanya, lihat G12.
     */
    public function pakaiKoreksiTermalDiSertifikat(): bool
    {
        return true;
    }

    /** Baris `Methode :` di `SIDIK-FM-CAL-0519_Rev.3 — LEMBAR KERJA COMPRESSION (UTM).pdf`. */
    public function kodeMetodeIk(): string
    {
        return 'SIDIK-IK-CAL-0513_Rev.3';
    }

    /**
     * Sertifikat master mencetak gaya sampai satu desimal dalam satuan aslinya
     * (kgf), sesudah dikonversi balik dari kN.
     */
    public function desimalSertifikat(): ?int
    {
        return 1;
    }

    public function desimalU95(): ?int
    {
        return 1;
    }

    /** Master mencetak `k = 2` walau nilai hitungnya 1,96985… */
    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /**
     * Bentuk pemindaian foto lembar kerjanya.
     *
     * `kolom_suhu = false`: tiap sel memuat SATU angka (pembacaan UUT), bukan
     * sepasang nilai+suhu seperti lembar alat suhu. Penanda ini yang membangun
     * prompt & skema JSON pembaca foto — salah nilai berarti model diminta
     * membaca kolom yang tidak ada di kertasnya, dan yang kembali bukan error
     * melainkan angka karangan yang kelihatan wajar.
     *
     * `standar_di_baris = false`: nilai standar tidak ditulis berdampingan di
     * tiap baris; dia lahir dari tabel kalibrasi load cell, bukan dari kertas.
     *
     * `didukung = false` (jalur CLOUD ditutup), `lokal = true`: lembar ini
     * padat — empat tabel x 14 baris x 3 replikat = 168 sel angka di dua
     * halaman, dan keempat tabelnya kelihatan serupa. Salah baris berarti satu
     * pembacaan mendarat di titik beban yang lain, dan itu menggeser koreksi
     * yang tercetak tanpa memunculkan error.
     *
     * Salah POSISI justru tidak merusak angkanya — keduabelas bacaan satu titik
     * memang digabung untuk rata-rata, STDEV, dan RRPE. Yang mahal cuma salah
     * BARIS. Karena itu jalur lokal dibiarkan terbuka (teknisi bisa langsung
     * mengoreksi di layar), sementara jalur cloud ditutup sampai bentuknya
     * terbukti terbaca rapi di lembar nyata, bukan di sesi contoh.
     */
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
