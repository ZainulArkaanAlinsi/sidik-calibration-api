<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\TimbanganProfile;
use App\Support\Angka;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PK4-9 (`docs/pertanyaan-lab-adu-cetak-massa-volume.md`) — penanda SELISIH YANG DIKETAHUI,
 * bukan perilaku yang diinginkan. PERTANYAAN KODE, bukan pertanyaan lab.
 *
 * Jalur cetak Timbangan (`TimbanganProfile::teksAngkaCetak`) membulatkan lewat `Angka::id`
 * (`number_format`, nilai biner eksak), sedangkan profil lain memakai `Angka::tampilanExcel`.
 * Excel membulatkan TAMPILAN dari 15 angka penting nilai tersimpan, jadi nilai yang
 * secara desimal tepat di tengah tetapi tersimpan sedikit di bawahnya dicetak Excel ke atas
 * dan oleh Timbangan ke bawah:
 *
 *   0,1499999999999996 berformat `0.0`   Excel `0.2`   Timbangan `0,1`
 *   0,2549999999999999 berformat `0.00`  Excel `0.26`  Timbangan `0,25`
 *
 * Kolom "Excel" di bawah dibaca dari Excel sendiri (`Range.Text`, COM, 18 Nov 2026, sel baru
 * berformat `0.0` / `0.00`), BUKAN dari tiruan; `Angka::tampilanExcel` hanya dipakai sebagai
 * penjaga bahwa tiruan itu setuju dengan Excel.
 *
 * Mengapa baru terlihat sekarang: tidak satu pun nilai sampel tiga master Timbangan jatuh di
 * titik seri, jadi `TimbanganSesiContohCocokMasterTest` hijau tanpa menyentuh jalur ini —
 * mutan pembulatan HALF_EVEN pada `teksAngkaCetak` selamat (tinjauan independen PR #272).
 *
 * Aplikasi TIDAK diubah di PK-4. Begitu jalur Timbangan dialihkan ke `tampilanExcel` (PR
 * kode terpisah; perlu jawaban apakah master Timbangan memang mencetak ala Excel — sel master
 * di sampel tidak membuktikannya), kolom `aplikasi` di bawah HARUS disamakan dengan `excel` dan
 * assert `assertNotSame` dihapus; tes ini yang memberi tahu.
 */
class TimbanganTitikSeriCetakTest extends TestCase
{
    /**
     * @return array<string, array{0: float, 1: int, 2: string, 3: string, 4: string}>
     */
    public static function titikSeri(): array
    {
        return [
            // nilai, desimal, kode format master, teks Excel, teks aplikasi SEKARANG
            '0,1499999999999996 d=1' => [0.1499999999999996, 1, '0.0', '0,2', '0,1'],
            '0,2549999999999999 d=2' => [0.2549999999999999, 2, '0.00', '0,26', '0,25'],
        ];
    }

    #[DataProvider('titikSeri')]
    public function test_jalur_timbangan_beda_dari_excel_di_titik_seri_penanda_pk4_9(
        float $nilai,
        int $desimal,
        string $kodeFormat,
        string $excel,
        string $aplikasiSekarang,
    ): void {
        $this->assertSame($excel, Angka::tampilanExcel($nilai, $desimal), 'Tiruan tampilanExcel harus setuju dengan Range.Text Excel.');

        $cetak = TimbanganProfile::teksAngkaCetak($nilai, $desimal, $kodeFormat);

        $this->assertSame($aplikasiSekarang, $cetak, 'Isi cetak Timbangan di titik seri bergeser dari keadaan yang dicatat (PK4-9).');
        $this->assertNotSame(
            $excel,
            $cetak,
            'Jalur Timbangan kini mencetak SAMA dengan Excel di titik seri — PK4-9 tertutup: samakan kolom `aplikasi` dengan `excel` dan hapus assertNotSame ini.',
        );
    }

    /** Kontrol: di luar titik seri kedua jalur sama, jadi selisih di atas memang soal titik seri. */
    public function test_di_luar_titik_seri_jalur_timbangan_sama_dengan_excel(): void
    {
        foreach ([[0.07953615325627225, 2, '0.00', '0,08'], [0.7549999999999955, 2, '0.00', '0,75'], [10.000007, 2, '0.00', '10,00']] as [$nilai, $d, $format, $excel]) {
            $this->assertSame($excel, Angka::tampilanExcel($nilai, $d));
            $this->assertSame($excel, TimbanganProfile::teksAngkaCetak($nilai, $d, $format));
        }
    }
}
