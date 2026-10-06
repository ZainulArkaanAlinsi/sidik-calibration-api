<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\PistonVolumeCalculator as K;
use App\Services\Calibration\TabelStandarPistonVolume as Tabel;
use App\Support\LogMetodeTekananPiston as LogMetode;
use App\Support\PistonVolumeMentah as M;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Induk ketiga alat PISTON VOLUME — Piston Pipette, Dispensett, Buret Digital
 * (lampiran LK-285-IDN no. 15, 16, 14), metode SIDIK-IK-CAL-0522, gravimetri
 * ISO 8655.
 *
 * ## Profil = nama lampiran, varian = workbook master
 *
 * Dua workbook (Fixed & Graduated) bukan dua jenis alat: pipet piston yang sama
 * bisa bervolume tetap atau dapat diatur. Jadi profil mengikuti nama lampiran
 * (kunci ke `equipments.nama_alat_kemampuan` dan pita CMC-nya), dan keluarga
 * dipilih teknisi per sesi lewat `spesifikasi_alat.piston.keluarga` — pola
 * Hydrometer (dua varian, toggle teknisi).
 *
 * ## Yang dicatat teknisi: massa KUMULATIF
 *
 * Kertas FM-0528/FM-0529 memungut M0..M10 — angka di timbangan yang terus
 * bertambah; sistem yang menghitung selisihnya. Satu digit salah di kolom ini
 * merusak DUA massa dengan arah berlawanan, rata-ratanya nyaris tidak berubah,
 * yang membengkak cuma STDEV. Karena itu kolom kumulatif wajib naik monoton
 * (diblokir), dan tabelnya membawa `kumulatif: true` supaya HP menampilkan
 * kolom selisih di sebelahnya.
 *
 * ## Pernyataan kesesuaian BELUM diterbitkan — dan itu disengaja
 *
 * Master Graduated mencetak `#N/A` di blok MPE & PASS/NOT PASS (G-4), master
 * Fixed tidak punya blok itu sama sekali (G-5), dan aturan keputusannya tidak
 * tertulis di mana pun (master simple acceptance; aturan lab 14 Jul guarded).
 * ISO/IEC 17025 §7.8.6.1: pernyataan kesesuaian cuma boleh terbit kalau aturan
 * keputusannya jelas — jadi [punyaToleransi] `false` (sama seperti Volumetric
 * Glassware), kolom `toleransi`/`keputusan` kosong, dan MPE beserta VONIS
 * USULAN-nya ditaruh di jejak audit (`kesesuaian`) supaya siap dipakai begitu
 * pertanyaan lab V-6 dijawab. Nominal yang tidak ada di tabel MPE ditulis
 * alasannya — tidak `#N/A`, tidak dikosongkan diam-diam.
 */
abstract class PistonVolumeProfile extends CalibrationProfile
{
    /**
     * Blok STANDARD kertas FM-0528/0529, plus Fujitsu FSR-A yang dipakai
     * workbook Graduated tapi TIDAK tercetak di kertas (pertanyaan lab V-4).
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Timbangan Excellent/HSEX1403752', 'cocok' => ['Electronic Balance Excellent', 'HSEX1403752']],
        ['label' => 'Timbangan Mettler Toledo/1129063525', 'cocok' => ['Analytical Balance', '1129063525']],
        ['label' => 'Timbangan Fujitsu FSR-A/SIDIK/134/2024', 'cocok' => ['Electronic Balance Fujitsu', 'SIDIK/134/2024']],
        ['label' => 'RTD Sensor/SH1/20', 'cocok' => ['PRT Pt-100', 'SH1/20']],
        ['label' => 'Temp. Calibrator Constant', 'cocok' => ['Temperature Calibrator Constant 40T']],
        ['label' => 'Temp. Calibrator Yokogawa', 'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005']],
    ];

    /** Ketujuh unit, ikut `INPUT DATA!E23` master (TH-1..TH-7). */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    public const KODE_METODE = 'SIDIK-IK-CAL-0522_Rev.5';

    /** Batas kewajaran selisih massa terhadap nominal (adendum OCR K-3). */
    public const BATAS_SELISIH_RELATIF = 0.2;

    private ?K $kalk = null;

    /** `piston_pipette` / `dispensett` / `buret_digital`. */
    abstract public function jenis(): string;

    /**
     * Timbangan dari centang Standard Used, bukan dropdown kedua.
     *
     * Nilainya nama di `tabel-standar-piston-volume.json` — yang kebetulan
     * sama dengan nama master pertama di `cocok` tiap baris timbangan.
     */
    public function kolomDariCentang(): array
    {
        $baris = [];

        foreach (self::STANDARD_TERCETAK as $b) {
            if (in_array($b['cocok'][0], Tabel::namaTimbangan(), true)) {
                $baris[] = ['cocok' => $b['cocok'], 'nilai' => $b['cocok'][0]];
            }
        }

        return [M::KUNCI_SESI.'.timbangan' => ['judul' => 'timbangan', 'baris' => $baris]];
    }

    /**
     * Pilihan sub-jenis yang menentukan kolom tabel MPE, atau `[]` kalau alat
     * ini tidak punya sub-jenis.
     *
     * @return list<array{nilai: string, label: string}>
     */
    abstract public function pilihanSubJenis(): array;

    public function besaran(): string
    {
        return 'volume';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function kodeFormula(): string
    {
        return 'PISTON-VOLUME-'.strtoupper($this->kode());
    }

    public function versiRumus(): ?string
    {
        return LogMetode::versiTerakhir(LogMetode::PISTON);
    }

    /**
     * Keputusan pemilik proyek 28 Sep 2026 (keputusan 3 di log metode): sesi
     * yang MEMICU G-2/G-7/G-8 dihitung dua mode tapi tidak terbit sampai
     * Technical Manager menjawab; sesi yang tidak memicunya — angka master &
     * aplikasinya sama — tetap terbit. G-9 tidak menahan.
     */
    public function penahanTerbit(CalibrationSession $sesi): array
    {
        $jejak = $sesi->uncertaintyCalculations
            ->pluck('type_b_components')
            ->filter(static fn (mixed $j): bool => is_array($j) && isset($j['piston_budget'], $j['versi_master']))
            ->values();

        if ($jejak->isEmpty()) {
            return [];
        }

        $terpicu = array_values(array_unique(array_merge(...$jejak->map(
            static fn (array $j): array => $j['penyimpangan_terpicu'] ?? [],
        )->all())));

        $awal = $jejak->first();
        $satuan = (string) (M::blokSesi($sesi->spesifikasi_alat)['satuan'] ?? 'ml');
        $uMaster = (float) $awal['versi_master']['u_hitung_ml'];
        $uBenar = (float) $awal['piston_budget']['u_hitung_ml'];
        $u95Master = (float) $awal['versi_master']['u95'];
        $u95Benar = (float) $awal['piston_budget']['u95'];
        $cmcMaster = $awal['versi_master']['cmc_ml'];
        $cmcBenar = $awal['piston_budget']['cmc_ml'];
        $titik = $jejak->map(static fn (array $j): array => [
            'label' => (string) $j['piston_rantai']['label'],
            'master' => (float) $j['versi_master']['V20_ml_titik_ini'],
            'benar' => (float) $j['piston_rantai']['V20_ml'],
        ])->all();

        return array_map(function (array $p) use ($satuan, $uMaster, $uBenar, $u95Master, $u95Benar, $cmcMaster, $cmcBenar, $titik): array {
            // U hitung (ml) DAN U95 terbit (satuan alat): lantai CMC bisa
            // menyamakan U95 kedua versi sementara U-nya berbeda.
            $angka = sprintf(
                'Kalau master ditiru: U %s ml, U95 terbit %s %s. Kalau dibetulkan: U %s ml, U95 terbit %s %s.',
                LogMetode::angka($uMaster),
                LogMetode::angka($u95Master),
                $satuan,
                LogMetode::angka($uBenar),
                LogMetode::angka($u95Benar),
                $satuan,
            );

            $angka .= $p['kode'] === 'G-7'
                ? sprintf(
                    ' CMC master: %s; CMC pita kontinu: %s ml.',
                    $cmcMaster === null ? '"cek range" (tanpa lantai)' : LogMetode::angka((float) $cmcMaster).' ml',
                    $cmcBenar === null ? '-' : LogMetode::angka((float) $cmcBenar),
                )
                : ' V20 per titik (tiru master → dibetulkan, ml): '.implode('; ', array_map(
                    static fn (array $t): string => sprintf('%s: %s → %s', $t['label'], LogMetode::angka($t['master']), LogMetode::angka($t['benar'])),
                    $titik,
                )).'.';

            return LogMetode::butirTahan($p, $angka, [
                'satuan' => $satuan,
                'u_master_ml' => $uMaster,
                'u_benar_ml' => $uBenar,
                'u95_master' => $u95Master,
                'u95_benar' => $u95Benar,
                'cmc_master_ml' => $cmcMaster,
                'cmc_benar_ml' => $cmcBenar,
                'v20_per_titik_ml' => $titik,
            ]);
        }, LogMetode::menahanTerbit(LogMetode::PISTON, $terpicu));
    }

    public function butuhBlokPiston(): bool
    {
        return true;
    }

    /** Lihat docblock kelas: vonis menunggu aturan keputusan (V-6). */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /** Volumetrik: kolom pertama `Nominal Value`, kedua `Actual Volume`, koreksi = V20 − Nominal. */
    public function judulKolomStandar(): string
    {
        return 'Nominal Value';
    }

    public function judulKolomUut(): string
    {
        return 'Actual Volume';
    }

    public function tandaKoreksiSertifikat(): int
    {
        return -1;
    }

    public function desimalSertifikat(): ?int
    {
        return 4;
    }

    /**
     * @param  array<string, mixed>  $konteksTitik
     */
    public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
        array $konteksTitik = [],
    ): ?array {
        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array<string, mixed>>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        usort($titik, static fn (array $a, array $b): int => (int) $a['titik_ke'] <=> (int) $b['titik_ke']);

        $konteks = [];
        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteks = $t['konteks'];

                break;
            }
        }

        $blok = M::blokSesi($konteks['spesifikasi_alat'] ?? null);
        $semua = static fn (string $alasan): array => [
            'hitungan' => [],
            'belum_dihitung' => array_map(static fn (array $t): array => [
                'titik_ke' => (int) $t['titik_ke'],
                'alasan' => $alasan,
            ], $titik),
        ];

        $penghalang = $this->penghalangSesi($blok, $konteks, $titik);

        if ($penghalang !== null) {
            return $semua($penghalang);
        }

        $masukan = [
            'keluarga' => $blok['keluarga'],
            'jenis' => $this->jenis(),
            'sub_jenis' => $blok['sub_jenis'],
            'satuan' => $blok['satuan'],
            'kapasitas' => (float) $blok['kapasitas'],
            'timbangan' => $blok['timbangan'],
            'suhu_ruang' => [(float) $konteks['suhu_awal'], (float) $konteks['suhu_akhir']],
            'kelembaban' => [(float) $konteks['kelembaban_awal'], (float) $konteks['kelembaban_akhir']],
            'tekanan_udara' => [(float) $konteks['tekanan_awal'], (float) $konteks['tekanan_akhir']],
            'titik' => array_map(fn (array $t): array => [
                'label' => $blok['keluarga'] === K::FIXED ? 'TUNGGAL' : (M::LABEL_TITIK[(int) $t['titik_ke']] ?? (string) $t['titik_ke']),
                'nominal' => (float) $t['titik_ukur'],
                'kumulatif' => array_map('floatval', $t['konteks'][M::KONTEKS_KUMULATIF] ?? []),
                'suhu_air' => array_map('floatval', $t['konteks'][M::KONTEKS_SUHU_AIR] ?? []),
                'penguapan' => (float) ($blok['penguapan'][(int) $t['titik_ke']] ?? 0.0),
            ], $titik),
        ];

        try {
            $benar = $this->kalk()->hitungSesi($masukan, K::MODE_BENAR);
            $master = $this->kalk()->hitungSesi($masukan, K::MODE_MASTER);
        } catch (InvalidArgumentException $e) {
            return $semua($e->getMessage());
        }

        $temuanSesi = $this->temuanSesi($benar, $konteks);
        $terpicu = $this->kalk()->penyimpanganTerpicu($masukan);
        $kaliSatuan = (float) $benar['faktor_nominal'] === 1.0 ? 1.0 : 1000.0;
        $uc = (float) $benar['uc'] * $kaliSatuan;
        // Bagian Type A dari komponen massa: s/2 masuk sebagai U (k=2) lalu
        // dibagi 2 → s/4, dikali ci massa. Disimpan untuk jejak saja.
        $typeA = ((float) $benar['maks_stdev'] / 4) * (float) $benar['komponen'][0]['ci'] * $kaliSatuan;
        $u95 = (float) $benar['u95'];
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($benar['per_titik'] as $i => $p) {
            $v20 = (float) $p['V20'] * $kaliSatuan;
            $nominal = (float) $p['nominal'];
            $mpe = $this->mpeTitik($blok, (float) $p['nominal_ml'], $kaliSatuan);
            $error = $v20 - $nominal;

            $hitungan[] = [
                'titik_ke' => (int) $titik[$i]['titik_ke'],
                'titik_ukur' => $nominal,
                // `Actual Volume` = V20; `Correction` tercetak = V20 − Nominal
                // lewat `tandaKoreksiSertifikat() = -1` (pola Volumetric).
                'rata_rata' => $v20,
                'error' => $error,
                'koreksi' => -$error,
                'standar_deviasi' => (float) $p['stdev'],
                'jumlah_pengulangan' => K::PEMINDAHAN,
                'type_a' => $typeA,
                'type_b_components' => $this->jejakAudit($benar, $master, $i, $mpe, $blok, $temuanSesi, abs($error), $u95, $terpicu),
                'type_b' => sqrt(max(0.0, $uc ** 2 - $typeA ** 2)),
                'ketidakpastian_gabungan' => $uc,
                'faktor_cakupan_k' => $benar['k'],
                'derajat_kebebasan_efektif' => $benar['veff'],
                'ketidakpastian_diperluas' => $u95,
                // Vonis TIDAK terbit sampai aturan keputusan dijawab (V-6);
                // MPE & vonis usulan ada di jejak `kesesuaian`.
                'toleransi' => null,
                'keputusan' => null,
                'metode' => self::KODE_METODE,
                'calculated_at' => $sekarang,
            ];
        }

        return ['hitungan' => $hitungan, 'belum_dihitung' => []];
    }

    /**
     * MPE dalam satuan alat, atau `null` — keluarga FIXED tidak memvonis (G-5),
     * dan nominal yang tidak ada di tabel sub-jenisnya tidak boleh diberi vonis.
     *
     * @param  array<string, mixed>  $blok
     */
    protected function mpeTitik(array $blok, float $nominalMl, float $kaliSatuan): ?float
    {
        if ($blok['keluarga'] !== K::GRADUATED) {
            return null;
        }

        $mpeUl = Tabel::mpe($this->jenis(), $blok['sub_jenis'], $nominalMl);

        return $mpeUl === null ? null : $mpeUl / 1000 * $kaliSatuan;
    }

    /**
     * Yang MEMBLOKIR (bukan label) — prompt panduan §11 & Bagian VI:
     * blok sesi, keluarga, satuan, kapasitas, timbangan, sub-jenis (penentu
     * MPE), kondisi lingkungan termasuk TEKANAN UDARA (masuk ρ udara → V20),
     * jumlah titik, tepat 11 massa kumulatif & 2 suhu air per titik, kumulatif
     * naik monoton, dan standar (timbangan, termometer, sensor) belum lewat
     * jatuh tempo pada TANGGAL KALIBRASI.
     *
     * @param  array<string, mixed>|null  $blok
     * @param  array<string, mixed>  $konteks
     * @param  list<array<string, mixed>>  $titik
     */
    protected function penghalangSesi(?array $blok, array $konteks, array $titik): ?string
    {
        if ($blok === null) {
            return 'Sesi piston volume belum punya blok `spesifikasi_alat.'.M::KUNCI_SESI.'` — keluarga, satuan, '
                .'kapasitas, dan timbangan lahir di situ.';
        }

        if (! in_array($blok['keluarga'], [K::FIXED, K::GRADUATED], true)) {
            return 'Pilih keluarga alat: volume tetap (One Mark, FM-0528) atau dapat diatur (Graduated, FM-0529).';
        }

        if (! in_array($blok['satuan'], ['ml', 'µl'], true)) {
            return 'Satuan alat wajib ml atau µl (`Tabel_Satuan` master).';
        }

        if (($blok['kapasitas'] ?? 0.0) <= 0.0) {
            return 'Kapasitas alat wajib diisi dan lebih dari nol — dia yang memilih pita CMC.';
        }

        // Errata E-5: di luar SEMUA pita lampiran = di luar ruang lingkup
        // akreditasi. Dihitung pun, U95-nya lahir tanpa lantai CMC dan tetap
        // tercetak di bawah logo KAN — master menjawabnya "cek range" lalu
        // `MAX(U, "cek range")` = U polos (G-7).
        $kapasitasMl = (float) $blok['kapasitas'] * ($blok['satuan'] === 'µl' ? 0.001 : 1.0);

        if (Tabel::cmc($this->kode(), $kapasitasMl) === null) {
            $maks = Tabel::kapasitasMaksCmc($this->kode());

            return sprintf(
                'Kapasitas %s %s di luar semua pita CMC %s di lampiran LK-285-IDN%s. Sesi tidak dihitung: U95 tanpa '
                .'lantai CMC tidak boleh terbit sebagai kalibrasi terakreditasi (errata E-5).',
                rtrim(rtrim(number_format((float) $blok['kapasitas'], 6, ',', ''), '0'), ','),
                $blok['satuan'],
                $this->namaAlatKemampuan(),
                $maks === null ? '' : sprintf(' (terbesar %s ml)', rtrim(rtrim(number_format($maks, 6, ',', ''), '0'), ',')),
            );
        }

        if ($blok['timbangan'] === null || Tabel::timbangan($blok['timbangan']) === null) {
            return sprintf('Timbangan wajib dipilih dari tabel standar (%s).', implode(', ', Tabel::namaTimbangan()));
        }

        $pilihanSub = array_column($this->pilihanSubJenis(), 'nilai');

        if ($pilihanSub !== [] && ! in_array($blok['sub_jenis'], $pilihanSub, true)) {
            return sprintf('Sub-jenis %s wajib dipilih (%s) — dia menentukan tabel MPE.', $this->namaAlatKemampuan(), implode(' / ', $pilihanSub));
        }

        foreach (['suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir', 'tekanan_awal', 'tekanan_akhir'] as $k) {
            if (! isset($konteks[$k]) || $konteks[$k] === '' || ! is_numeric($konteks[$k])) {
                return sprintf(
                    'Kondisi lingkungan `%s` wajib diisi. Suhu, kelembaban, DAN tekanan udara (hPa) masuk rumus densitas '
                    .'udara, dan densitas udara masuk V20 — kertas FM-0528/0529 belum punya kotak tekanan (pertanyaan lab V-3).',
                    $k,
                );
            }
        }

        $wajib = $blok['keluarga'] === K::FIXED ? 1 : 3;

        if (count($titik) !== $wajib) {
            return $blok['keluarga'] === K::FIXED
                ? sprintf('Volume tetap cuma SATU titik (nominal alat); terisi %d.', count($titik))
                : sprintf('Graduated wajib TIGA titik (MIN, MID, MAX); terisi %d.', count($titik));
        }

        $tanggal = isset($konteks['tanggal_kalibrasi']) && $konteks['tanggal_kalibrasi'] !== null
            ? Carbon::parse($konteks['tanggal_kalibrasi'])->startOfDay()
            : null;

        foreach ($titik as $t) {
            $kum = $t['konteks'][M::KONTEKS_KUMULATIF] ?? [];
            $suhu = $t['konteks'][M::KONTEKS_SUHU_AIR] ?? [];
            $ke = (int) $t['titik_ke'];

            if (count($kum) !== K::PEMINDAHAN + 1) {
                return sprintf('Titik %d wajib TEPAT %d massa kumulatif (M0..M10); terisi %d.', $ke, K::PEMINDAHAN + 1, count($kum));
            }

            if (count($suhu) !== 2) {
                return sprintf('Titik %d wajib dua suhu air (awal & akhir); terisi %d.', $ke, count($suhu));
            }

            $retara = $blok['keluarga'] === K::GRADUATED && $blok['satuan'] === 'ml' && (float) end($kum) > 200;

            for ($i = 1; $i <= K::PEMINDAHAN; $i++) {
                if ((float) $kum[$i] < (float) $kum[$i - 1] && ! ($retara && in_array($i, [4, 7], true))) {
                    return sprintf(
                        'Titik %d: massa kumulatif M%d (%s) lebih kecil dari M%d (%s). Massa air di timbangan tidak '
                        .'mungkin berkurang — hampir pasti salah ketik/salah baca, dan kesalahannya menyembunyikan diri '
                        .'di rata-rata.',
                        $ke, $i, $kum[$i], $i - 1, $kum[$i - 1],
                    );
                }
            }

            if ((float) $t['titik_ukur'] <= 0.0) {
                return sprintf('Titik %d belum punya nominal.', $ke);
            }
        }

        if ($tanggal !== null) {
            $standar = [
                'Timbangan '.$blok['timbangan'] => Tabel::timbangan($blok['timbangan'])['jatuh_tempo'] ?? null,
                'Termometer '.(Tabel::termometer()['merk_tipe'] ?? '') => Tabel::termometer()['jatuh_tempo'] ?? null,
            ];

            foreach ($standar as $nama => $jatuhTempo) {
                if ($jatuhTempo !== null && $tanggal->gt(Carbon::parse($jatuhTempo))) {
                    return sprintf(
                        'Standar %s jatuh tempo %s, sebelum tanggal kalibrasi %s. Perbarui data sertifikat standarnya '
                        .'di tabel referensi dulu — memakai standar yang lewat masa berlakunya menerbitkan sertifikat '
                        .'yang ketelusurannya putus.',
                        $nama, $jatuhTempo, $tanggal->toDateString(),
                    );
                }
            }
        }

        return null;
    }

    /**
     * Peringatan — terlihat, tidak memblokir (ambang dari adendum OCR K-3; angka
     * pastinya keputusan metode, pertanyaan lab V-8).
     *
     * @param  array<string, mixed>  $h
     * @param  array<string, mixed>  $konteks
     * @return list<string>
     */
    protected function temuanSesi(array $h, array $konteks): array
    {
        $temuan = [];

        foreach ($h['per_titik'] as $p) {
            $nominalG = (float) $p['nominal_ml'];

            foreach ($p['m'] as $i => $mi) {
                if ($nominalG > 0 && abs($mi - $nominalG) > self::BATAS_SELISIH_RELATIF * $nominalG) {
                    $temuan[] = sprintf(
                        'Titik %s pemindahan ke-%d = %s g, lebih dari ±20%% nominal %s ml — cek kolom kumulatif M%d/M%d.',
                        $p['label'], $i + 1, round($mi, 6), $p['nominal_ml'], $i, $i + 1,
                    );

                    break;
                }
            }

            foreach ($p['t_terkoreksi'] as $t) {
                if ($t < 15 || $t > 35) {
                    $temuan[] = sprintf('Titik %s suhu air %s °C di luar 15–35 °C — salah baca atau salah kolom?', $p['label'], round($t, 3));

                    break;
                }
            }
        }

        foreach (['tekanan_awal', 'tekanan_akhir'] as $k) {
            $v = (float) $konteks[$k];

            if ($v < 900 || $v > 1100) {
                $temuan[] = sprintf('Tekanan udara %s hPa di luar 900–1100 hPa (Bandung ≈930) — salah digit?', $v);
            }
        }

        return $temuan;
    }

    /**
     * @param  array<string, mixed>  $benar
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $blok
     * @param  list<string>  $temuanSesi
     * @param  list<string>  $terpicu  kode log metode yang mengubah angka sesi ini
     * @return array<string, mixed>
     */
    protected function jejakAudit(
        array $benar,
        array $master,
        int $i,
        ?float $mpe,
        array $blok,
        array $temuanSesi,
        float $deviasiMutlak,
        float $u95,
        array $terpicu = [],
    ): array {
        $p = $benar['per_titik'][$i];

        return [
            'komponen' => $benar['komponen'],
            'piston_rantai' => [
                'keluarga' => $benar['keluarga'],
                'label' => $p['label'],
                'kumulatif' => $p['kumulatif'],
                'selisih' => $p['m'],
                'selisih_terkoreksi_penguapan' => $p['m_terkoreksi'],
                'm_rata' => $p['m_rata'],
                'stdev' => $p['stdev'],
                'indeks_suhu' => $p['indeks_suhu'],
                'koreksi_meter' => $p['koreksi_meter'],
                'koreksi_sensor' => $p['koreksi_sensor'],
                't_terkoreksi' => $p['t_terkoreksi'],
                't_rata' => $p['t_rata'],
                'rho_air' => $p['rho_air'],
                'rho_udara' => $benar['rho_udara'],
                'V20_ml' => $p['V20'],
                'deviasi_ml' => $p['deviasi'],
                'deviasi_ul' => $p['deviasi_ul'],
            ],
            'piston_budget' => [
                'acuan_ci' => $benar['acuan'],
                'U_massa' => $benar['U_massa'],
                'U_suhu' => $benar['U_suhu'],
                'U_densitas_air' => $benar['U_rho'],
                'uc_ml' => $benar['uc'],
                'v_eff' => $benar['veff'],
                'df_inverse_t' => $benar['df'],
                // `k` PENUH — master Graduated & Fixed mencetaknya tanpa
                // pembulatan, sementara kolom `faktor_cakupan_k` cuma 2 desimal.
                'k' => $benar['k'],
                'u_hitung_ml' => $benar['U'],
                'cmc_ml' => $benar['cmc'],
                'u95' => $benar['u95'],
                'lantai_cmc_menang' => $benar['cmc'] !== null && $benar['cmc'] > $benar['U'],
                'di_luar_lampiran' => $benar['cmc'] === null,
            ],
            'kesesuaian' => [
                'diterbitkan' => false,
                'mpe' => $mpe,
                'vonis_usulan_guarded' => $mpe === null ? null : ($deviasiMutlak + $u95 <= $mpe ? 'PASS' : 'FAIL'),
                'vonis_usulan_simple' => $mpe === null ? null : ($deviasiMutlak <= $mpe ? 'PASS' : 'NOT PASS'),
                'aturan' => 'BELUM diterbitkan: aturan keputusan menunggu pertanyaan lab V-6 (master simple '
                    .'acceptance; aturan lab 14 Jul guarded acceptance).',
                'alasan_tanpa_vonis' => $mpe !== null ? null : ($blok['keluarga'] === K::FIXED
                    ? 'Master Fixed tidak punya blok pernyataan kesesuaian (G-5, pertanyaan V-5).'
                    : 'MPE tidak tersedia untuk nominal ini di tabel sub-jenisnya — hubungi Technical Manager.'),
            ],
            'versi_master' => [
                'keterangan' => 'Angka kalau cacat master G-2/G-8/G-9/G-7 DITIRU. Selisih > 0 cuma kalau cacatnya terpicu.',
                'u_hitung_ml' => $master['U'],
                'u95' => $master['u95'],
                'cmc_ml' => $master['cmc'],
                'U_densitas_air' => $master['U_rho'],
                'V20_ml_titik_ini' => $master['per_titik'][$i]['V20'],
            ],
            // Versi rumus yang BENAR-BENAR menghitung baris ini — diadu
            // `CalibrationValidator` ke versi formula yang distempelkan.
            'versi_rumus' => LogMetode::stempel(LogMetode::PISTON),
            // Kode log metode yang BENAR-BENAR mengubah angka sesi ini —
            // dibaca `penahanTerbit()` dari hasil tersimpan.
            'penyimpangan_terpicu' => $terpicu,
            'penyimpangan_master' => array_filter([
                'g9_densitas_air' => $benar['keluarga'] === K::GRADUATED
                    ? 'Graduated `P87 = (SQRT(P86^2)+(H87^2))` — kurung salah; dihitung √(P86² + H87²). Pertanyaan V-9.'
                    : null,
                'g7_pita_cmc' => '`J48` memakai batas pita bilangan bulat (celah 1–2, 5–6 ml → "cek range" → tanpa lantai CMC); '
                    .'di sini pitanya kontinu. Pertanyaan V-7.',
                'metode_beda_antar_master' => 'Densitas air Fixed ÷2 / Graduated ÷√3; suhu air Fixed ÷√3 / Graduated ÷2 — '
                    .'ditiru per master (pertanyaan V-10).',
            ], static fn (?string $x): bool => $x !== null),
            'temuan_sesi' => $temuanSesi,
        ];
    }

    protected function kalk(): K
    {
        return $this->kalk ??= new K;
    }

    /** @return array<string, mixed> */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($this->rangkaLembar(), $equipment),
            $equipment,
        );
    }

    /** @return array<string, mixed> */
    protected function rangkaLembar(): array
    {
        $sub = $this->pilihanSubJenis();
        $identitas = [
            $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
            $this->field('alat_model', 'Model/Type', 'teks'),
            $this->field('alat_merk', 'Manufacture', 'teks'),
            $this->field('alat_serial_number', 'Serial Number', 'teks'),
            $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.keluarga', 'Keluarga alat', 'pilihan', pilihan: [
                ['nilai' => K::FIXED, 'label' => 'Volume tetap — One Mark (FM-0528)'],
                ['nilai' => K::GRADUATED, 'label' => 'Dapat diatur — Graduated (FM-0529)'],
            ], ekstra: ['mempengaruhi_ketidakpastian' => true]),
            $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.kapasitas', 'Capacity', 'angka',
                ekstra: ['mempengaruhi_ketidakpastian' => true]),
            $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.satuan', 'Satuan', 'pilihan', pilihan: ['ml', 'µl']),
        ];

        if ($sub !== []) {
            $identitas[] = $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.sub_jenis', 'Sub-jenis', 'pilihan',
                pilihan: $sub, ekstra: ['mempengaruhi_ketidakpastian' => true]);
        }

        $baris = array_map(
            static fn (int $n): array => ['nomor' => $n, 'titik_ukur' => null, 'label' => [1 => 'Titik 1 / MIN', 2 => 'MID', 3 => 'MAX'][$n]],
            [1, 2, 3],
        );

        return [
            'kode_dokumen' => 'SIDIK-FM-CAL-0529_Rev.3',
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Worksheet - Piston Volume',
            'jumlah_pengulangan' => K::PEMINDAHAN,
            'semua_kolom_opsional' => false,
            'catatan_pengisian' => 'Tulis massa KUMULATIF di timbangan (M0 = tara, M1..M10 sesudah tiap pemindahan) — '
                .'sistem yang menghitung selisihnya. Volume tetap: isi Titik 1 saja. Graduated: isi MIN, MID, MAX, '
                .'masing-masing dengan suhu air awal & akhirnya. Tekanan udara (hPa) WAJIB: masuk densitas udara.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olah Data Fixed/Graduated Piston Volume, PERHITUNGAN & PERHITUNGAN U95%',
                'catatan' => 'Tujuh komponen dengan koefisien sensitivitas turunan parsial V20, k dari t-Student dengan '
                    .'v_eff dipotong ke bawah, lantai CMC lampiran LK-285-IDN.',
            ],
            'bagian' => [
                ['kode' => 'identitas_alat', 'halaman' => 1, 'judul' => 'Equipment Identity', 'field' => $identitas],
                [
                    'kode' => 'pemilik',
                    'halaman' => 1,
                    'judul' => 'Owner',
                    'field' => [
                        $this->field('pemilik_nama', 'Name', 'teks'),
                        $this->field('pemilik_alamat', 'Address', 'teks_panjang'),
                        $this->field('suhu_awal', 'Env. Condition — First (°C)', 'angka', satuan: '°C'),
                        $this->field('suhu_akhir', 'Env. Condition — End (°C)', 'angka', satuan: '°C'),
                        $this->field('kelembaban_awal', 'Env. Condition — First (%RH)', 'angka', satuan: '%RH'),
                        $this->field('kelembaban_akhir', 'Env. Condition — End (%RH)', 'angka', satuan: '%RH'),
                        $this->field('tekanan_awal', 'Tekanan Udara — First (hPa)', 'angka', satuan: 'hPa',
                            ekstra: ['mempengaruhi_ketidakpastian' => true]),
                        $this->field('tekanan_akhir', 'Tekanan Udara — End (hPa)', 'angka', satuan: 'hPa',
                            ekstra: ['mempengaruhi_ketidakpastian' => true]),
                        $this->field('lokasi', 'Location', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'Inlab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        $this->field('room_id', 'Ruangan (Inlab)', 'pilihan', sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB),
                        $this->field('lokasi_nama', 'Nama Tempat (Insitu)', 'teks', tampilKalau: self::TAMPIL_KALAU_INSITU),
                        $this->field('thermohygro_standard_id', 'Thermohygro Used', 'pilihan', sumber: 'master_thermohygro'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'halaman' => 1,
                    'judul' => 'Standard',
                    'baris' => static::STANDARD_TERCETAK,
                    // Timbangan lahir dari baris yang dicentang — lihat
                    // `kolomDariCentang()`. Dropdown keduanya dicabut 6 Okt 2026.
                    'field' => [],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 2,
                    'judul' => 'Reading of Weighing Result',
                    'field' => [],
                    'tabel' => [
                        [
                            'tahap' => 'sesudah_adjustment',
                            'grup' => M::PERAN_KUMULATIF,
                            'offset_kunci' => 1000,
                            'judul' => 'Weight of Content — massa KUMULATIF (gram)',
                            'judul_nilai' => 'Nominal UUT',
                            'judul_pengulangan' => 'M',
                            'titik_bisa_diubah' => false,
                            'simpan_ke' => 'measurements[].'.M::PERAN_KUMULATIF,
                            // HP menampilkan kolom SELISIH M_i − M_{i−1} di
                            // sebelahnya — itu yang membuat salah ketik terlihat
                            // waktu mengetik (adendum OCR K-1).
                            'kumulatif' => true,
                            'baris' => $baris,
                            'kolom' => [['kode' => 'pembacaan', 'label' => 'Massa (g)', 'tipe' => 'angka']],
                            'pengulangan' => range(1, K::PEMINDAHAN + 1),
                            'pengulangan_arah' => array_map(
                                static fn (int $i): array => ['ke' => $i + 1, 'label' => 'M'.$i],
                                range(0, K::PEMINDAHAN),
                            ),
                        ],
                        [
                            'tahap' => 'sesudah_adjustment',
                            'grup' => M::PERAN_SUHU_AIR,
                            'offset_kunci' => 2000,
                            'judul' => 'Suhu Air Destilasi (°C)',
                            'judul_nilai' => 'Nominal UUT',
                            'judul_pengulangan' => 'Suhu',
                            'titik_bisa_diubah' => false,
                            'simpan_ke' => 'measurements[].'.M::PERAN_SUHU_AIR,
                            'baris' => $baris,
                            'kolom' => [['kode' => 'pembacaan', 'label' => 'Suhu (°C)', 'tipe' => 'angka']],
                            'pengulangan' => [1, 2],
                            'pengulangan_arah' => [['ke' => 1, 'label' => 'Awal'], ['ke' => 2, 'label' => 'Akhir']],
                        ],
                    ],
                ],
                [
                    'kode' => 'penutup',
                    'halaman' => 2,
                    'judul' => 'Catatan & Tanda Tangan',
                    'field' => [
                        $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                        $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                        $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Cloud OCR mati; jalur lokal (kunci sel sebelum baca) — kolom kumulatif &
     * tiga blok berdampingan Graduated paling rawan salah-kolom. Adendum §3.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung: bool, lokal: bool}
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => false, 'standar_di_baris' => false, 'didukung' => false, 'lokal' => true];
    }
}
