<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Pembaca `database/data/tabel-standar-flowmeter-gravimetri.json`.
 *
 * Tabel standar varian **gravimetri (ISO 4185)**: empat timbangan digital,
 * densitas air hasil piknometer, kalibrator suhu Yokogawa, dan timer software.
 * Digenerate `docs/skrip/gen-tabel-standar-flowmeter-gravimetri.py` — jangan
 * diketik tangan.
 *
 * Sengaja TIDAK memuat pita CMC maupun faktor satuan: keduanya sudah hidup di
 * [TabelStandarFlowmeter] dan sama persis untuk kedua varian (lampiran
 * akreditasi cuma punya satu baris per mode). Menyalinnya ke sini akan
 * melahirkan sumber kebenaran kedua yang basi diam-diam begitu lampirannya
 * direvisi.
 *
 * ## Empat timbangan, satu di antaranya beda alat antar-workbook
 *
 * `INPUT DATA!X24` (Totalizer) / `Y24` (Flowrate) memilih satu dari empat
 * timbangan, dan pilihan itu mengubah tabel koreksi, U95, kestabilan, dan drift
 * sekaligus. Master menuliskannya sebagai delapan cabang `IF` bersarang di
 * SETIAP sel komponen budget; di sini satu tabel ber-kunci `kode_timbangan`.
 *
 * Timbangan ke-3 (Mettler) **bukan alat yang sama** di kedua workbook — tipe,
 * S/N, nomor akreditasi, tanggal kalibrasi, dan satuan tabel koreksinya semua
 * berbeda. Keduanya disimpan dan dipilih per mode; memilih salah satu sebagai
 * "yang benar" akan diam-diam menggeser angka yang sudah tercetak di
 * sertifikat pelanggan. Pertanyaan lab §15.
 */
class TabelStandarFlowmeterGravimetri
{
    /**
     * Jarak relatif ke titik tabel terdekat yang masih dianggap wajar.
     *
     * Di atas ini titiknya tetap terbit, tapi dengan peringatan ber-angka —
     * master memungut baris TERDEKAT tanpa interpolasi berapa pun jaraknya,
     * dan admin berhak tahu seberapa jauh koreksi yang dipakai itu diambil.
     */
    public const AMBANG_JARAK_TABEL = 0.10;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /**
     * Satu timbangan menurut mode dan kodenya, atau `null` kalau tidak ada.
     *
     * Kode di luar yang terdaftar pulang `null` — TIDAK jatuh ke timbangan
     * pertama. Sesi yang menyebut kode 9 harus berhenti dengan alasan yang
     * kebaca, bukan terbit memakai U95 Dini Argeo yang 3.000 kali lebih besar
     * dari Mettler.
     *
     * @return array<string, mixed>|null
     */
    public function timbangan(string $mode, int $kode): ?array
    {
        return $this->data()['timbangan'][$this->mode($mode)][(string) $kode] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function semuaTimbangan(string $mode): array
    {
        return $this->data()['timbangan'][$this->mode($mode)] ?? [];
    }

    /**
     * Titik tabel koreksi TERDEKAT ke `$massaKg`, dalam kilogram.
     *
     * Tabelnya sebagian bersatuan gram (`satuan_tabel_koreksi`), dan master
     * mencocokkan TANPA mengonversi. Di sini titik tabel dikonversi ke kilogram
     * DULU, baru dicocokkan. Kalau tidak, penimbangan 27 kg akan memungut titik
     * 27 g berikut koreksi 0,1 kg-nya — koreksi 588 kali U95 timbangannya
     * sendiri, yang mendarat di sertifikat tanpa satu pun error.
     *
     * @return array{titik_kg: float, koreksi_kg: float, jarak_relatif: float}|null
     */
    public function cocokTerdekat(string $mode, int $kode, float $massaKg): ?array
    {
        $baris = $this->barisKoreksi($mode, $kode);

        if ($baris === []) {
            return null;
        }

        $terdekat = $baris[0];

        foreach ($baris as $b) {
            if (abs($b['titik_kg'] - $massaKg) < abs($terdekat['titik_kg'] - $massaKg)) {
                $terdekat = $b;
            }
        }

        return $terdekat + [
            'jarak_relatif' => $massaKg == 0.0
                ? 0.0
                : abs($terdekat['titik_kg'] - $massaKg) / abs($massaKg),
        ];
    }

    /**
     * Massa ini masih di dalam rentang yang tabel koreksinya sanggup menanggung?
     *
     * Rentang pakainya span tabel **ditambah satu langkah tabel di tiap ujung**.
     * Bukan span telanjang: seluruh titik Totalizer sesi contoh ada di bawah
     * titik terendah tabel Dini Argeo (139,87 kg lawan titik terendah 200 kg),
     * dan master tetap menerbitkannya. Bukan pula tak terbatas: penimbangan
     * 2.485,9 kg lawan titik tertinggi 2.000 kg itu ekstrapolasi 24 % ke luar
     * daerah yang pernah dikalibrasi, dan koreksi di situ tidak diketahui
     * siapa pun.
     *
     * Satu langkah = jarak antar-titik TERBESAR di tabel itu. Dipakai yang
     * terbesar, bukan rata-rata, supaya tabel yang titiknya renggang di ujung
     * tidak jadi lebih ketat daripada yang dijamin kalibrasinya.
     */
    public function dalamJangkauan(string $mode, int $kode, float $massaKg): bool
    {
        $rentang = $this->rentangPakai($mode, $kode);

        return $rentang !== null && $massaKg >= $rentang['min'] && $massaKg <= $rentang['maks'];
    }

    /**
     * @return array{min: float, maks: float}|null
     */
    public function rentangPakai(string $mode, int $kode): ?array
    {
        $baris = $this->barisKoreksi($mode, $kode);

        if ($baris === []) {
            return null;
        }

        $titik = array_map(static fn (array $b): float => $b['titik_kg'], $baris);
        sort($titik);

        $langkah = 0.0;

        for ($i = 1, $n = count($titik); $i < $n; $i++) {
            $langkah = max($langkah, $titik[$i] - $titik[$i - 1]);
        }

        return ['min' => min($titik) - $langkah, 'maks' => max($titik) + $langkah];
    }

    /**
     * Densitas air (kg/L) pada `$suhu` °C — interpolasi LINIER antar titik
     * piknometer, `null` di luar 20..50,5 °C.
     *
     * Master gravimetri **tidak** memakai rumus Tanaka/Kell yang dipakai varian
     * UFM. Dia menimbang piknometer 50,3139 ml di empat suhu
     * (`STANDAR KALIBRATOR!N61:P65`) lalu menginterpolasi. Mengganti dengan
     * Tanaka/Kell menggeser tiap sertifikat gravimetri lama di digit belakang —
     * selisihnya kecil hari ini, dan justru karena itu tidak akan pernah
     * terlihat.
     *
     * Di luar jangkauan pulang `null`, bukan titik terdekat: mengekstrapolasi
     * densitas air ke 5 °C dari garis 20–27 °C melesetnya 0,15 %, dan densitas
     * masuk hasil akhir sebagai pembagi.
     */
    public function densitasAir(float $suhu): ?float
    {
        $titik = $this->data()['densitas_air']['titik'] ?? [];

        for ($i = 1, $n = count($titik); $i < $n; $i++) {
            $t0 = (float) $titik[$i - 1]['suhu_c'];
            $t1 = (float) $titik[$i]['suhu_c'];

            if ($suhu >= $t0 && $suhu <= $t1) {
                $d0 = (float) $titik[$i - 1]['densitas_kg_per_l'];
                $d1 = (float) $titik[$i]['densitas_kg_per_l'];

                return $t1 == $t0 ? $d0 : $d0 + ($suhu - $t0) * ($d1 - $d0) / ($t1 - $t0);
            }
        }

        return null;
    }

    /**
     * Titik kalibrator suhu TERDEKAT ke pembacaan `$suhu` — koreksi + U95.
     *
     * Master mencocokkan ke kolom `Pembacaan Alat (UUT)`, bukan kolom
     * `Konversi Satuan Standart` — yang terakhir KOSONG di baris 25 °C dan
     * 50 °C pada kedua workbook. Ditiru.
     *
     * @return array{pembacaan_c: float, koreksi_c: float, u95_c: float}|null
     */
    public function koreksiSuhu(float $suhu): ?array
    {
        return $this->terdekatPada($this->data()['kalibrator_suhu']['titik'] ?? [], 'pembacaan_c', $suhu);
    }

    /**
     * Titik timer TERDEKAT ke `$menit` — koreksi dalam menit.
     *
     * @return array{titik_min: float, koreksi_min: float}|null
     */
    public function koreksiTimer(float $menit): ?array
    {
        return $this->terdekatPada($this->data()['timer']['titik'] ?? [], 'titik_min', $menit);
    }

    /** @return array<string, mixed> */
    public function kalibratorSuhu(): array
    {
        return $this->data()['kalibrator_suhu'] ?? [];
    }

    /** @return array<string, mixed> */
    public function sensorSuhu(): array
    {
        return $this->data()['sensor_suhu'] ?? [];
    }

    /** @return array<string, mixed> */
    public function timer(): array
    {
        return $this->data()['timer'] ?? [];
    }

    /** @return array<string, mixed> */
    public function densitasAirMeta(): array
    {
        return $this->data()['densitas_air'] ?? [];
    }

    /** @return array<string, float> */
    public function konstanta(): array
    {
        return $this->data()['konstanta'] ?? [];
    }

    /**
     * Baris koreksi satu timbangan, titiknya SUDAH dalam kilogram.
     *
     * @return list<array{titik_kg: float, koreksi_kg: float}>
     */
    private function barisKoreksi(string $mode, int $kode): array
    {
        $timbangan = $this->timbangan($mode, $kode);

        if ($timbangan === null) {
            return [];
        }

        $faktor = ($timbangan['satuan_tabel_koreksi'] ?? 'kg') === 'g' ? 0.001 : 1.0;

        return array_values(array_map(
            static fn (array $b): array => [
                'titik_kg' => (float) $b['titik'] * $faktor,
                'koreksi_kg' => (float) $b['koreksi_kg'],
            ],
            $timbangan['koreksi'] ?? [],
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $titik
     * @return array<string, float>|null
     */
    private function terdekatPada(array $titik, string $kunci, float $nilai): ?array
    {
        if ($titik === []) {
            return null;
        }

        $terdekat = null;

        foreach ($titik as $t) {
            if ($terdekat === null
                || abs((float) $t[$kunci] - $nilai) < abs((float) $terdekat[$kunci] - $nilai)) {
                $terdekat = $t;
            }
        }

        return array_map('floatval', $terdekat);
    }

    private function mode(string $mode): string
    {
        $rapi = mb_strtolower(trim($mode));

        if (! in_array($rapi, [TabelStandarFlowmeter::MODE_TOTALIZER, TabelStandarFlowmeter::MODE_FLOWRATE], true)) {
            throw new RuntimeException("Mode flowmeter tidak dikenal: `{$mode}`.");
        }

        return $rapi;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $berkas = database_path('data/tabel-standar-flowmeter-gravimetri.json');

        if (! is_file($berkas)) {
            throw new RuntimeException(
                "Tabel standar Flowmeter Gravimetri tidak ada di {$berkas}. "
                .'Jalankan `python docs/skrip/gen-tabel-standar-flowmeter-gravimetri.py`.'
            );
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi)) {
            throw new RuntimeException("Tabel standar Flowmeter Gravimetri di {$berkas} bukan JSON yang sah.");
        }

        return $this->data = $isi;
    }
}
