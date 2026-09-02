<?php

namespace Tests\Unit;

use App\Services\CalibrationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Judul yang muncul waktu approve ketahan peringatan.
 *
 * Dulu satu kalimat tetap — "Hasil hitung ulang beda dari yang tersimpan" —
 * dipakai buat kelima belas kode peringatan. Yang dijaga di sini: judulnya
 * lahir dari temuan yang beneran nyala, dan bentuk-bentuk yang susah dibangun
 * lewat endpoint (banyak jenis sekaligus, pesan kepanjangan, angka beribuan).
 *
 * Jalur ujung-ke-ujungnya dijaga terpisah di [CalibrationValidationTest].
 */
class JudulPeringatanTest extends TestCase
{
    /** @param list<array{kode: string, pesan: string}> $peringatan */
    private function hasil(array $peringatan): array
    {
        return [
            'temuan' => array_map(
                fn (array $p): array => [
                    'tingkat' => CalibrationValidator::PERINGATAN,
                    'kode' => $p['kode'],
                    'pesan' => $p['pesan'],
                ],
                $peringatan,
            ),
        ];
    }

    public function test_satu_peringatan_judulnya_pesan_peringatan_itu_sendiri(): void
    {
        $judul = CalibrationValidator::judulPeringatan($this->hasil([[
            'kode' => 'standar_titik_hilang',
            'pesan' => 'Titik ke-2: standar acuannya nggak ketemu, hitung ulang dilewati.',
        ]]));

        $this->assertSame(
            'Titik ke-2: standar acuannya nggak ketemu, hitung ulang dilewati.',
            $judul,
        );
    }

    /**
     * Kalimat KEDUA dibuang, bukan ikut jadi judul.
     *
     * Banyak pesan peringatan menutup dengan dugaan sebab ("Kemungkinan besar
     * komanya kegeser") — berguna di badan pesan, kepanjangan buat judul.
     */
    public function test_cuma_kalimat_pertama_yang_jadi_judul(): void
    {
        $judul = CalibrationValidator::judulPeringatan($this->hasil([[
            'kode' => 'pembacaan_di_luar_rentang',
            'pesan' => 'Titik ke-1: pembacaan 500 mm jauh di luar rentang ukur alat (0–100 mm). '
                .'Kemungkinan besar komanya kegeser waktu ngetik.',
        ]]));

        $this->assertSame(
            'Titik ke-1: pembacaan 500 mm jauh di luar rentang ukur alat (0–100 mm).',
            $judul,
        );
        $this->assertStringNotContainsString('kegeser', $judul);
    }

    public function test_satu_jenis_di_banyak_titik_nyebut_berapa_titik_lain(): void
    {
        $judul = CalibrationValidator::judulPeringatan($this->hasil([
            ['kode' => 'pembacaan_mentah_hilang', 'pesan' => 'Titik ke-1: pembacaan mentahnya nggak ada.'],
            ['kode' => 'pembacaan_mentah_hilang', 'pesan' => 'Titik ke-2: pembacaan mentahnya nggak ada.'],
            ['kode' => 'pembacaan_mentah_hilang', 'pesan' => 'Titik ke-3: pembacaan mentahnya nggak ada.'],
        ]));

        // Tanpa ekor ini admin baca "Titik ke-1" dan ngira cuma satu yang kena.
        $this->assertSame(
            'Titik ke-1: pembacaan mentahnya nggak ada. (+2 titik lain yang sama)',
            $judul,
        );
    }

    /**
     * Beda JENIS: nggak ada satu kalimat yang jujur mewakili semuanya, jadi
     * yang disebut cacahnya. Memilih salah satu pesan di sini bakal
     * menyembunyikan yang lain — persis cacat yang lagi diperbaiki.
     */
    public function test_lebih_dari_satu_jenis_nyebut_cacahnya(): void
    {
        $judul = CalibrationValidator::judulPeringatan($this->hasil([
            ['kode' => 'standar_titik_hilang', 'pesan' => 'Titik ke-1: standar acuannya nggak ketemu.'],
            ['kode' => 'hitung_ulang_beda', 'pesan' => 'Titik ke-2: U95 tersimpan 0,1, hasil hitung ulang 0,2.'],
            ['kode' => 'hitung_ulang_beda', 'pesan' => 'Titik ke-3: U95 tersimpan 0,3, hasil hitung ulang 0,4.'],
        ]));

        $this->assertSame('Ada 3 peringatan dari 2 hal berbeda di sesi ini.', $judul);
    }

    /**
     * Titik pemisah ribuan JANGAN dibaca sebagai akhir kalimat.
     *
     * `Angka::idRingkas` nulis `1.234,5`. Memecah pada titik saja bikin judul
     * berhenti di "…tersimpan 1." — angka yang beda seribu kali lipat dari
     * yang sebenarnya, di kalimat yang tugasnya justru melaporkan angka.
     */
    public function test_titik_pemisah_ribuan_bukan_akhir_kalimat(): void
    {
        $judul = CalibrationValidator::judulPeringatan($this->hasil([[
            'kode' => 'hitung_ulang_beda',
            'pesan' => 'Titik ke-1: U95 tersimpan 1.234,5, hasil hitung ulang 1.240,8.',
        ]]));

        $this->assertStringContainsString('1.234,5', $judul);
        $this->assertStringContainsString('1.240,8', $judul);
    }

    /** Judul notifikasi Filament satu baris — yang kepanjangan dipotong di batas KATA. */
    public function test_pesan_kepanjangan_dipotong_di_batas_kata(): void
    {
        $panjang = 'Koreksi tekanan 12,5 Bar itu 340% dari set point 3,5 Bar dan itu kejauhan buat '
            .'autoklaf yang masih jalan normal di ruang uji nomor tujuh belas lantai dua gedung timur';

        $judul = CalibrationValidator::judulPeringatan($this->hasil([
            ['kode' => 'koreksi_tekanan_mustahil', 'pesan' => $panjang],
        ]));

        $this->assertLessThanOrEqual(121, mb_strlen($judul));
        $this->assertStringEndsWith('…', $judul);

        // Dipotong di spasi: potongan terakhir sebelum '…' harus kata utuh yang
        // memang ada di pesan aslinya.
        $tanpaElipsis = rtrim($judul, '…');
        $this->assertStringContainsString($tanpaElipsis, $panjang);
    }

    /** Cabang ini cuma nyala kalau ada peringatan — tapi kalau kosong, jangan nuduh. */
    public function test_tanpa_peringatan_judulnya_nggak_nuduh_apa_apa(): void
    {
        $judul = CalibrationValidator::judulPeringatan(['temuan' => [
            ['tingkat' => CalibrationValidator::INFO, 'kode' => 'apa_aja', 'pesan' => 'Sekadar info.'],
        ]]);

        $this->assertSame('Ada peringatan di sesi ini yang perlu diperiksa dulu.', $judul);
        $this->assertStringNotContainsString('Sekadar info', $judul);
    }
}
