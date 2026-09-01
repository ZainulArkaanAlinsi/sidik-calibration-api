<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Tabel `Calibration Report` sertifikat kelompok Waktu dan Frekuensi HARUS
 * menjumlah, dan angkanya harus yang tercetak di master.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `CertificateSnapshotBuilder` mengisi kolom `Standard Value` dari `titik_ukur`,
 * dan itu benar untuk dua puluh satu alat yang `titik_ukur`-nya memang NILAI
 * ACUAN (buffer pH 4,01 datang dari sertifikat larutan). Kelompok Waktu dan
 * Frekuensi kebalikannya: yang dibaca berulang justru STANDARNYA, dan
 * `titik_ukur` menyimpan SET POINT — penunjukan alat pelanggan.
 *
 * Dibiarkan, sertifikat Centrifuge terbit sebagai
 *
 *     Standard 60  |  UUT 59,98  |  Correction −0,22
 *
 * padahal masternya menulis `59,78 | 60 | −0,22`. Dua kolomnya tertukar DAN
 * angkanya tidak menjumlah (60 − 59,98 = 0,02, bukan −0,22). Tidak ada satu pun
 * error yang terbit — yang terbit sertifikat terakreditasi yang aritmetikanya
 * salah, dan itu temuan audit yang paling mahal jenisnya.
 *
 * Yang ditegakkan di sini DUA hal, dan keduanya perlu:
 *
 *  1. **Identitas** `Standard ≡ UUT + Correction` di SETIAP titik. Identitas ini
 *     berlaku di seluruh sistem karena `GumCalculator` menurunkan
 *     `koreksi = titik_ukur − rata_rata`; alat yang melanggarnya menerbitkan
 *     tabel yang tidak konsisten dengan dirinya sendiri.
 *  2. **Angka masternya**, disalin dari sel `SERTIFIKAT` ketiga workbook —
 *     supaya identitas yang benar tapi isinya salah tetap ketahuan.
 */
class WaktuFrekuensiSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const TOL = 5e-6;

    /**
     * Nomor sesi ter-seed per alat, dan baris pertama tabel hasilnya seperti
     * TERCETAK di master.
     *
     * Centrifuge & Timer disalin apa adanya. Tachometer TIDAK: sheet
     * `SERTIFIKAT`-nya menukar kedua kolom (`C19 = PERHITUNGAN!G22` set point,
     * `J19 = G32` standar terkoreksi) sehingga koreksinya terbit `+0,22` —
     * kebalikan tanda dari Centrifuge untuk data yang SAMA PERSIS. Itu
     * kerusakan salin-tempel, bukan pilihan metode: konvensi yang benar
     * (Correction = nilai benar − penunjukan alat) yang dipakai Centrifuge dan
     * Timer, dan Tachometer memang disalin dari Centrifuge. Lihat
     * `docs/pertanyaan-lab-waktu-frekuensi.md` §10.
     *
     * @return array<string, array{string, float, float, float}>
     */
    public static function barisPertama(): array
    {
        return [
            // nomor sesi, standard, uut, correction
            'Centrifuge' => ['0133-CAL-324', 59.779999999999994, 60.0, -0.22000000000000597],
            'Tachometer' => ['0140-CAL-424', 59.779999999999994, 60.0, -0.22000000000000597],
            // Timer dalam DETIK: STD CORRECTED 60,09633 s lawan rata-rata UUT
            // 60,137 s -> koreksi −0,040667 s (master menulisnya −40,667 ms).
            'Timer/Stopwatch' => ['015-CAL-424', 60.09633333333333, 60.137, -0.04066666666666],
        ];
    }

    /**
     * Nomor sesinya saja, diturunkan dari [barisPertama].
     *
     * Provider sendiri, bukan memakai [barisPertama] langsung: PHPUnit
     * mengeluarkan WARNING kalau provider memasok lebih banyak argumen daripada
     * yang diterima method-nya, dan `php artisan test` memulangkan exit 1 pada
     * warning — suite hijau seluruhnya tapi CI merah, tanpa satu pun baris
     * `FAIL` untuk dibaca. Diturunkan (bukan disalin) supaya daftarnya tidak
     * bisa menyimpang dari sumbernya.
     *
     * @return array<string, array{string}>
     */
    public static function nomorSesi(): array
    {
        return array_map(
            static fn (array $baris): array => [$baris[0]],
            self::barisPertama(),
        );
    }

    /**
     * Identitas `Standard ≡ UUT + Correction` di setiap titik ketiga alat.
     *
     * Disapu ke SEMUA titik, bukan cuma yang pertama: cacatnya lahir dari
     * pemetaan kolom, jadi kalau salah dia salah di semua baris — dan kalau
     * suatu saat cuma sebagian yang meleset, itu justru cacat yang lebih halus.
     */
    #[DataProvider('nomorSesi')]
    public function test_tabel_hasil_menjumlah(string $nomorSesi): void
    {
        $this->seed(DatabaseSeeder::class);

        $baris = $this->tabelHasil($nomorSesi);

        $this->assertNotEmpty($baris, "Sesi {$nomorSesi} nggak punya baris hasil sama sekali.");

        foreach ($baris as $b) {
            $this->assertEqualsWithDelta(
                (float) $b['standard_value'],
                (float) $b['unit_under_test'] + (float) $b['correction'],
                self::TOL,
                sprintf(
                    'Sesi %s titik %d: Standard %s ≠ UUT %s + Correction %s. Tabel sertifikat '
                    .'yang nggak menjumlah terbit tanpa satu pun error.',
                    $nomorSesi, $b['titik_ke'], $b['standard_value'], $b['unit_under_test'], $b['correction'],
                ),
            );
        }
    }

    /** Baris pertama tiap alat diadu ke sel `SERTIFIKAT` masternya. */
    #[DataProvider('barisPertama')]
    public function test_baris_pertama_cocok_master(
        string $nomorSesi,
        float $standard,
        float $uut,
        float $correction,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $b = $this->tabelHasil($nomorSesi)[0] ?? null;

        $this->assertNotNull($b, "Sesi {$nomorSesi} nggak punya baris hasil.");

        $this->assertEqualsWithDelta($standard, (float) $b['standard_value'], self::TOL,
            "Sesi {$nomorSesi}: kolom Standard Value beda dari master.");
        $this->assertEqualsWithDelta($uut, (float) $b['unit_under_test'], self::TOL,
            "Sesi {$nomorSesi}: kolom Unit Under Test beda dari master.");
        $this->assertEqualsWithDelta($correction, (float) $b['correction'], self::TOL,
            "Sesi {$nomorSesi}: kolom Correction beda dari master.");
    }

    /**
     * Dua puluh satu alat lain TIDAK boleh bergeser: buat mereka
     * `nilaiStandarDariKoreksi()` tetap `false`, jadi `Standard Value` tetap
     * dibaca dari `titik_ukur` apa adanya.
     */
    public function test_alat_lain_tetap_membaca_titik_ukur(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '2405.13.A')
            ->with(['uncertaintyCalculations', 'equipment'])
            ->firstOrFail();

        $baris = $this->tabelHasil('2405.13.A');
        $titik = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();

        $this->assertNotEmpty($baris);

        foreach ($baris as $i => $b) {
            $this->assertEqualsWithDelta(
                (float) $titik[$i]->titik_ukur,
                (float) $b['standard_value'],
                self::TOL,
                'Alat lain kesenggol: Standard Value harusnya `titik_ukur` apa adanya.',
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function tabelHasil(string $nomorSesi): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)
            ->with(['uncertaintyCalculations', 'equipment', 'organization'])
            ->firstOrFail();

        // `hasil()` privat — dipanggil lewat refleksi supaya yang diuji BENTUK
        // TABELNYA, bukan seluruh snapshot yang butuh Certificate tersimpan.
        $metode = new ReflectionMethod(CertificateSnapshotBuilder::class, 'hasil');

        return $metode->invoke(app(CertificateSnapshotBuilder::class), $sesi);
    }
}
