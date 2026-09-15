<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel standar **Dial Indicator**, dibaca dari
 * `database/data/tabel-standar-dial-indicator.json`.
 *
 * ## Balok ukurnya milik Micrometer — sengaja, bukan pinjam
 *
 * `Standar_GB` workbook Dial Indicator memuat 32 keping yang IDENTIK nilai demi
 * nilai dengan tabel Micrometer: satu set fisik Metrology GB-9122-0 S/N 160006.
 * Jadi nilai terkoreksi dan ketidakpastian kepingnya dibaca dari
 * [TabelStandarMicrometer], bukan disalin. Dua salinan untuk satu set balok
 * ukur berarti yang satu diam-diam basi begitu set itu dikalibrasi ulang —
 * generatornya (`gen-tabel-standar-dial-indicator.py`) mengadu keduanya dan
 * menolak menulis kalau berbeda.
 *
 * ## Nominal keping itu KUNCI PASTI
 *
 * Master mencari lewat `VLOOKUP(nominal; Nominal_mm; 2; 0)` dibungkus
 * `IFERROR(...; "")`, jadi keping yang tidak ada di daftar LENYAP dari jumlah
 * tumpukan tanpa error — titik 5,0 mm yang salah satu kepingnya salah ketik
 * terbaca 3,7 mm dan koreksinya meleset 1,3 mm. [nilaiKeping] memulangkan
 * `null` dan pemanggil wajib memblokir titiknya dengan alasan kebaca.
 */
class TabelStandarDialIndicator
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    private ?TabelStandarMicrometer $balok = null;

    /** Nilai terkoreksi (mm) satu keping, atau `null` kalau nominalnya tidak terdaftar. */
    public function nilaiKeping(float $nominal): ?float
    {
        return $this->balok()->nilaiTerkoreksi($nominal);
    }

    /** Ketidakpastian baku satu keping (µm) — tangga `PERHITUNGAN!H23` master. */
    public function ketidakpastianKeping(float $nominal): float
    {
        return $this->balok()->ketidakpastianBalok($nominal);
    }

    /** @return list<float> nominal keping yang terdaftar, urut naik */
    public function daftarNominal(): array
    {
        $nominal = array_map('floatval', array_keys($this->balok()->balokUkur()));
        sort($nominal);

        return $nominal;
    }

    /**
     * Pita CMC yang memuat `$kapasitasMm`, atau `null` di luar keempat pita
     * lampiran (0-25, 0-50, 0-100, 0-300 mm).
     *
     * Dua hal yang SENGAJA tidak ditiru dari `PERHITUNGAN U95%!AA20`:
     *
     *  1. **Kapasitas mentah, bukan mm.** Master memilih pita dari
     *     `INPUT DATA!E15` apa adanya, sementara budget-nya memakai kapasitas
     *     yang sudah dikali faktor satuan (`PERHITUNGAN!G8`). Dial 1 inch
     *     terbaca kapasitas `1` dan mendarat di pita 0-25 mm; yang benar 25,4 mm
     *     — pita 0-50 (8,6 µm, bukan 6,5). Di sini dari mm.
     *  2. **Di luar pita.** Master memulangkan teks `"cek range"`, `MAX()`
     *     mengabaikannya, dan U terbit tanpa lantai. Kapasitas ≤ 0 (belum
     *     diisi) juga ditolak — `0 <= 25` bernilai benar di Excel dan alat tanpa
     *     kapasitas memungut lantai 6,5 µm yang bukan haknya.
     *
     * @return array{kode: string, label: string, kapasitas_maks_mm: float, u95_mm: float}|null
     */
    public function pitaCmc(float $kapasitasMm): ?array
    {
        if ($kapasitasMm <= 0.0) {
            return null;
        }

        foreach (self::muat()['cmc'] as $pita) {
            if ($kapasitasMm <= (float) $pita['kapasitas_maks_mm']) {
                return $pita;
            }
        }

        return null;
    }

    /** @return array<string, float|int> */
    public function konstanta(): array
    {
        return self::muat()['konstanta'];
    }

    /** @return array{nama: string, merk_tipe: string, seri: string, traceability: string, tanggal_kalibrasi: string, interval_tahun: int} */
    public function standar(): array
    {
        return self::muat()['standar'];
    }

    private function balok(): TabelStandarMicrometer
    {
        return $this->balok ??= new TabelStandarMicrometer;
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-dial-indicator.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar dial indicator nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi)
            || ! isset($isi['cmc'], $isi['konstanta'])
            || ! isset($isi['standar']['tanggal_kalibrasi'])) {
            throw new RuntimeException("Tabel standar dial indicator rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
