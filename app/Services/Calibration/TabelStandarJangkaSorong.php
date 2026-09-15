<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel standar **Jangka Sorong (Vernier Caliper)**, dibaca dari
 * `database/data/tabel-standar-jangka-sorong.json`.
 *
 * ## Dua standar, dua perlakuan
 *
 * - **Caliper Checker** (tabel Outside & Inside) dibaca dari
 *   [TabelStandarHeightGauge], bukan disalin. `Std_CaliperCek` workbook ini
 *   identik nilai demi nilai dengan milik Height Gauge — satu keping fisik
 *   Metrology CMG-9060C S/N 800035. Dua salinan untuk satu keping berarti yang
 *   satu diam-diam basi begitu keping itu dikalibrasi ulang; generatornya
 *   mengadu keduanya dan berhenti kalau berbeda.
 * - **Balok ukur** (tabel Depth) disimpan sebagai snapshot milik workbook ini.
 *   Set nominalnya sama dengan Micrometer, tapi nilai 200 mm BERBEDA
 *   (199,99924 lawan 200,00017) — dua sertifikat keping yang berbeda, dan
 *   memilih satu diam-diam menggeser angka salah satu sertifikat pelanggan.
 *
 * ## Nominal itu KUNCI PASTI
 *
 * Master mencari lewat `VLOOKUP(...; 0)` dibungkus `IFERROR(...; "")`, jadi
 * nominal yang tidak terdaftar membuat titiknya LENYAP dari sertifikat tanpa
 * error. Ketiga pencari di sini memulangkan `null`, dan pemanggil wajib
 * memblokir titiknya dengan alasan kebaca.
 */
class TabelStandarJangkaSorong
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    private ?TabelStandarHeightGauge $caliperChecker = null;

    /** Nilai terkoreksi (mm) Caliper Checker sisi Outside, atau `null` kalau tidak terdaftar. */
    public function nilaiOutside(float $nominal): ?float
    {
        return $this->caliperChecker()->nilaiTerkoreksi($nominal);
    }

    /**
     * Nilai terkoreksi (mm) Caliper Checker sisi Inside, atau `null`.
     *
     * Di Height Gauge tabel ini disalin tapi tidak tersambung; di sini dia
     * DIPAKAI — `PERHITUNGAN!F72` master memakai `Nom_Inside`.
     */
    public function nilaiInside(float $nominal): ?float
    {
        foreach ($this->caliperChecker()->inside() as $baris) {
            if (abs((float) $baris['nominal_mm'] - $nominal) < 1e-9) {
                return (float) $baris['nilai_terkoreksi_mm'];
            }
        }

        return null;
    }

    /** Nilai terkoreksi (mm) satu keping balok ukur Depth, atau `null`. */
    public function nilaiKeping(float $nominal): ?float
    {
        $keping = $this->keping($nominal);

        return $keping === null ? null : (float) $keping['nilai_terkoreksi_mm'];
    }

    /** U95 (mm) satu keping balok ukur, atau `null` kalau tidak terdaftar. */
    public function u95Keping(float $nominal): ?float
    {
        $keping = $this->keping($nominal);

        return $keping === null ? null : (float) $keping['u95_mm'];
    }

    /**
     * Ketidakpastian baku Caliper Checker (mm) sebelum dibagi — `U95` satu angka.
     *
     * `PERHITUNGAN U95%!K7 = Std_CaliperCek!J19/1000` membaca baris Outside
     * 600 mm untuk SELURUH titik, dan blok Inside ikut memakainya (`K26 = K7`).
     * Kesepuluh baris kedua tabel memang 4,1 µm, jadi angkanya sama — generator
     * berhenti begitu sertifikat berikutnya berbeda per nominal.
     */
    public function u95CaliperCheckerMm(): float
    {
        return (float) $this->caliperChecker()->standar()['u95_um'] / 1000.0;
    }

    /** @return list<float> nominal Outside terdaftar, urut naik */
    public function nominalOutside(): array
    {
        return $this->caliperChecker()->titikPraCetak();
    }

    /** @return list<float> nominal Inside terdaftar, urut naik */
    public function nominalInside(): array
    {
        return array_map(
            static fn (array $b): float => (float) $b['nominal_mm'],
            $this->caliperChecker()->inside(),
        );
    }

    /**
     * Lima tumpukan balok ukur baris Depth (mm), dari sesi master.
     *
     * Dipatok server, bukan diketik teknisi: tabel ber-kunci-bernama di HP tidak
     * punya jalur mengirim tumpukan per baris. Pertanyaan lab §11.
     *
     * @return list<list<float>>
     */
    public function tumpukanDepth(): array
    {
        return array_map(
            static fn (array $t): array => array_map('floatval', $t),
            self::muat()['tumpukan_depth_mm'],
        );
    }

    /**
     * Pita CMC lampiran (0-300 mm, 0,015 mm), atau `null` kalau kapasitas di
     * luarnya atau belum diisi.
     *
     * Master TIDAK menyambungkan lantai ini ke sheet U95 mana pun (sel lantainya
     * kosong), padahal angkanya ada di `DATABASE!T5`. Dipasang di sini karena
     * Vernier Caliper ADA di lampiran — lihat pertanyaan lab §2.
     *
     * @return array{label: string, kapasitas_maks_mm: float, u95_mm: float}|null
     */
    public function pitaCmc(float $kapasitasMm): ?array
    {
        $cmc = self::muat()['cmc'];

        return $kapasitasMm > 0.0 && $kapasitasMm <= (float) $cmc['kapasitas_maks_mm'] ? $cmc : null;
    }

    /** @return array<string, float|int> */
    public function konstanta(): array
    {
        return self::muat()['konstanta'];
    }

    /**
     * Identitas Caliper Checker — titik nol umur drift Outside & Inside.
     *
     * @return array<string, mixed>
     */
    public function standarCaliperChecker(): array
    {
        return $this->caliperChecker()->standar();
    }

    /** @return array{nama: string, merk_tipe: string, seri: string, traceability: string, tanggal_kalibrasi: string} */
    public function standarBalokUkur(): array
    {
        $b = self::muat()['balok_ukur'];
        unset($b['keping']);

        return $b;
    }

    /** @return array{nominal_mm: float, nilai_terkoreksi_mm: float, u95_mm: float}|null */
    private function keping(float $nominal): ?array
    {
        foreach (self::muat()['balok_ukur']['keping'] as $k) {
            if (abs((float) $k['nominal_mm'] - $nominal) < 1e-9) {
                return $k;
            }
        }

        return null;
    }

    private function caliperChecker(): TabelStandarHeightGauge
    {
        return $this->caliperChecker ??= new TabelStandarHeightGauge;
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-jangka-sorong.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar jangka sorong nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi)
            || ! isset($isi['cmc'], $isi['konstanta'], $isi['tumpukan_depth_mm'], $isi['balok_ukur']['keping'])) {
            throw new RuntimeException("Tabel standar jangka sorong rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
