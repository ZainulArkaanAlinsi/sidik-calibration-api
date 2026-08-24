<?php

namespace App\Services\Calibration\Profiles\Enclosure;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\EnclosureCalculator;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\TabelKalibratorEnclosure;
use Carbon\Carbon;

/**
 * Induk profil kalibrasi ENCLOSURE — logika bersama untuk kelima jenis enclosure
 * (Oven/Furnace/Bath/Inkubator/Refrigerator). BUKAN profil terdaftar sendiri;
 * yang didaftarkan di registry kelima anak-nya.
 *
 * ## Kenapa lima profil tipis, bukan satu `EnclosureProfile`
 *
 * `CalibrationProfileRegistry` mencocokkan SATU `nama_alat_kemampuan` per profil,
 * dan lampiran akreditasi (`database/data/kemampuan-kalibrasi.json`) memuat lima
 * baris CMC terpisah: Oven 1,5 (amb–300 °C), Furnace 3,0 (300–1000 °C), Bath 1,2
 * (0–100 °C), Inkubator 1,4 (15–100 °C), Refrigerator 1,5 (−20–10 °C). Jenis
 * enclosure itu IDENTITAS ALAT (oven tidak pernah jadi bath), jadi dia jadi
 * `nama_alat_kemampuan` seperti sepuluh alat lain — bukan kolom sesi. Mesin
 * hitung, budget, dan bentuk lembar kerjanya identik; yang beda cuma
 * [namaAlatKemampuan] + [kode] + kunci CMC-nya.
 *
 * ## Yang milik SESI (bukan alat): kalibrator & tipe sensor
 *
 * Enclosure yang sama bisa dikalibrasi pakai kalibrator berbeda hari ini vs
 * besok, dan tipe termokopel berbeda. Karena itu:
 *
 *  - **Merk kalibrator** (`constant`/`yokogawa`/`recorder`) dibaca dari
 *    `standards.merk` standar yang dicentang sesi — sama pola dengan TITS.
 *  - **Tipe sensor** (`Type N`/`Type K`) dari `calibration_sessions.tipe_sensor`,
 *    lewat `konteks` tiap titik.
 *
 * ## U95 PER SET POINT
 *
 * Beda dari TITS (satu U95 untuk seluruh sesi), tiap set point enclosure punya
 * budget & U95 sendiri — [u95PerTitik] `true`. Tetap lewat [hitungPerGrup]
 * karena tiap komponen "Type A"-nya statistik GRID (9 termokopel × 5 pengulangan
 * + Indikator), bukan STDEV satu deret yang muat di `komponenBudget()`.
 *
 * @see EnclosureCalculator
 * @see TabelKalibratorEnclosure
 * @see docs/pertanyaan-lab-enclosure.md
 */
abstract class EnclosureProfileBase extends CalibrationProfile
{
    /** Metode kalibrasi enclosure — `DATABASE!C68` master. */
    public const KODE_METODE = 'SIDIK-IK-CAL-0501_Rev.6';

    /**
     * Nomor formulir lembar kerja enclosure belum ada — sama temuan TITS: satu-
     * satunya nomor di berkas `SIDIK-FM-CAL-2403_Rev. 0` itu formulir sertifikat
     * bersama, bukan lembar kerja. Sengaja `null`, ditanyakan ke lab.
     */
    public const KODE_DOKUMEN = null;

    public const SATUAN = '°C';

    /** Jumlah pengulangan per termokopel di master (`INPUT DATA` kolom 1–5). */
    public const PENGULANGAN = 5;

    /**
     * Termokopel minimum per set point supaya Keseragaman & Variasi punya arti.
     *
     * Bukan [EnclosureCalculator::SENSOR_MASTER]: chamber kecil kadang dipetakan
     * dengan titik lebih sedikit, dan memblokirnya di 9 bikin sesi yang sah
     * nggak bisa disimpan — grid yang lebih tipis dari master dicatat di jejak
     * audit, bukan ditolak. Tapi di bawah DUA keduanya bukan sekadar kurang
     * teliti: nggak ada selisih antar-posisi buat diukur sama sekali.
     */
    public const MIN_SENSOR = 2;

    private ?EnclosureCalculator $kalkulator = null;

    public function __construct(private readonly TabelKalibratorEnclosure $tabel = new TabelKalibratorEnclosure) {}

    /** Kode stabil profil — di-supply anak (`oven`/`bath`/…). */
    abstract public function kode(): string;

    /**
     * Ejaan PERSIS seperti di lampiran akreditasi & `kemampuan-kalibrasi.json`
     * (`Oven`/`Furnace`/`Bath`/`Inkubator`/`Refrigerator`). Kunci pencocokan ke
     * `equipments.nama_alat_kemampuan` DAN ke baris CMC.
     */
    abstract public function namaAlatKemampuan(): string;

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_ENCLOSURE;
    }

    public function besaran(): string
    {
        return 'suhu';
    }

    /** Tidak divonis PASS/FAIL — master enclosure nggak punya batas keberterimaan. */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /** Tiap set point punya U95 sendiri (kolom `U95% ±` per baris di sertifikat). */
    public function u95PerTitik(): bool
    {
        return true;
    }

    /** Tiap set point GRID: 9 termokopel × 5 + Indikator. Lihat [hitungPerGrup]. */
    public function butuhGridSensor(): bool
    {
        return true;
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /** Kolom hasil sertifikat: satu desimal (`SERTIFIKAT` blok sebaran). */
    public function desimalSertifikat(): ?int
    {
        return 1;
    }

    /** U95: dua desimal. */
    public function desimalU95(): ?int
    {
        return 2;
    }

    /**
     * `k` dicetak presisi penuh di master (`SERTIFIKAT!P37 = 1,9616…`), tidak
     * dibulatkan seperti alat lain. Ditiru; ditanyakan ke lab. Dua desimal
     * dipilih supaya tetap kebaca — lihat `docs/pertanyaan-lab-enclosure.md` #9.
     */
    public function desimalFaktorCakupan(): ?int
    {
        return 2;
    }

    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    public function desimalKelembabanEnv(): ?int
    {
        return 1;
    }

    /** Kalibrator dibaca nominal — koreksinya dari sertifikatnya sendiri. */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Baris `Standar: … / Sensor: …` yang tercetak di atas tabel hasil.
     */
    public function catatanAtasTabelHasil(CalibrationSession $sesi): ?string
    {
        $merk = $this->merkKalibrator($sesi->rawMeasurements->first()?->standard ?? $sesi->standard);
        $tipe = $this->normalkanTipeSensor($sesi->tipe_sensor);

        $bagian = array_filter([
            $merk !== null ? 'Standar: '.(TabelKalibratorEnclosure::MERK_TERCETAK[$merk] ?? $merk) : null,
            $tipe !== null ? 'Sensor: '.$tipe : null,
            'Enclosure: '.$this->namaAlatKemampuan(),
        ]);

        return $bagian === [] ? null : implode(', ', $bagian);
    }

    /**
     * SENGAJA `null` — enclosure nggak punya budget per titik lewat jalur
     * standar. U95 tiap set point lahir dari grid lewat [hitungPerGrup].
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
     * Hitung seluruh sesi enclosure — satu baris hasil per SET POINT.
     *
     * Tiap `$titik` = satu set point, dan grid-nya (`sensor_grid` + `indikator`)
     * dibawa lewat `konteks` — diisi controller dari request atau seeder dari
     * master. Merk kalibrator dari `standards.merk`, tipe sensor dari
     * `konteks['tipe_sensor']`.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, standard: Standard, konteks?: array<string, mixed>}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $titik = array_values($titik);
        $konteks = $titik[0]['konteks'] ?? [];
        $tipe = $this->normalkanTipeSensor($konteks['tipe_sensor'] ?? null);
        $merk = $this->merkKalibrator($titik[0]['standard'] ?? null);

        $kurang = $this->syaratKurang($merk, $tipe, $titik[0]['standard'] ?? null);

        if ($kurang !== null) {
            return $this->semuaBelum($titik, $kurang);
        }

        $kemampuan = $this->kemampuanEnclosure($equipment);

        if ($kemampuan === null) {
            return $this->semuaBelum($titik, sprintf(
                'CMC buat enclosure "%s" belum ada di master kemampuan kalibrasi — jalankan '
                .'CalibrationCapabilitySeeder dulu.',
                $this->namaAlatKemampuan(),
            ));
        }

        $spek = [
            'merk' => $merk,
            'tipe_sensor' => $tipe,
            'cmc' => (float) $kemampuan->ketidakpastian_terbaik,
            'resolusi_alat' => (float) ($equipment->resolusi ?: 0.1),
        ];

        $hitungan = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            $grid = $t['konteks']['sensor_grid'] ?? null;
            $indikator = $t['konteks']['indikator'] ?? null;

            if (! is_array($grid) || $grid === [] || ! is_array($indikator) || $indikator === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Set point %s °C belum punya grid termokopel/Indikator lengkap — nggak bisa dihitung.',
                        $this->angka((float) $t['titik_ukur']),
                    ),
                ];

                continue;
            }

            // Keseragaman itu selisih antar-POSISI di dalam chamber. Dengan satu
            // termokopel selisih itu nggak ada isinya, dan yang tercetak di
            // kolom Keseragaman jadi `0,0` — dibaca pelanggan sebagai "sudah
            // dibuktikan seragam" padahal yang benar "belum diukur". Master
            // pakai 9 titik; di bawah dua nggak bisa dihitung sama sekali.
            if (count($grid) < self::MIN_SENSOR) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Set point %s °C cuma punya %d termokopel. Keseragaman & Variasi butuh minimal %d '
                        .'posisi (master pakai %d) — dengan satu sensor keduanya keluar 0,0 seolah sudah terbukti.',
                        $this->angka((float) $t['titik_ukur']),
                        count($grid),
                        self::MIN_SENSOR,
                        EnclosureCalculator::SENSOR_MASTER,
                    ),
                ];

                continue;
            }

            $hasil = ($this->kalkulator ??= new EnclosureCalculator($this->tabel))->hitungSetpoint(
                array_map(static fn (array $s): array => [
                    'no' => (int) $s['no'],
                    'channel' => isset($s['channel']) ? (int) $s['channel'] : null,
                    'pembacaan' => array_map('floatval', $s['pembacaan']),
                ], array_values($grid)),
                array_map('floatval', array_values($indikator)),
                (float) $t['titik_ukur'],
                $spek,
                (int) $t['titik_ke'],
            );

            // Koreksi yang nggak ketemu di tabel bikin set point ini nggak boleh
            // terbit — sebaran suhu & keseragaman yang tercetak ikut salah, bukan
            // cuma U95. Lihat [EnclosureCalculator::hitungSensor].
            if ($hasil['koreksi_hilang'] !== []) {
                // Kalibrator sudah tahu KENAPA tiap koreksi hilang — kanal yang
                // kosong beda sebab dari nomor termokopel yang nggak ada di
                // tabel — dan sebab itu ikut disebut di sini.
                //
                // Sebelumnya cuma nomornya yang dicetak, jadi sesi Recorder yang
                // termokopelnya lupa diisi Channel dapat pesan "cek nomor
                // termokopel" — persis instruksi yang SALAH: nomornya sudah
                // benar, yang kosong kolom Channel-nya. Teknisi disuruh
                // membongkar penomoran yang nggak ada masalahnya.
                $kanalKosong = false;

                $rincian = array_map(
                    static function (array $h) use (&$kanalKosong): string {
                        $sebab = [];

                        foreach ($h['hilang'] as $apa) {
                            if ($apa === 'meter (kanal kosong)') {
                                $kanalKosong = true;
                                $sebab[] = 'nomor Channel belum diisi';

                                continue;
                            }

                            $sebab[] = $apa === 'meter'
                                ? 'koreksi kalibrator nggak ketemu'
                                : 'koreksi sensor nggak ketemu';
                        }

                        return sprintf('no. %d (%s)', $h['no'], implode(' & ', $sebab));
                    },
                    $hasil['koreksi_hilang'],
                );

                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Set point %s °C: termokopel %s di tabel kalibrator %s (%s). '
                        .'Koreksi yang hilang nggak boleh dianggap nol. %s',
                        $this->angka((float) $t['titik_ukur']),
                        implode(', ', $rincian),
                        TabelKalibratorEnclosure::MERK_TERCETAK[$merk] ?? $merk,
                        $tipe,
                        $kanalKosong
                            ? 'Isi kolom Channel (CH1..CH20) tiap termokopel — koreksi meter Recorder dibaca per kanal.'
                            : 'Cek nomor termokopelnya'
                                .($tipe === 'Type N' ? ' — sertifikat sensor Type N lab mulai dari no. 3.' : '.'),
                    ),
                ];

                continue;
            }

            // Termokopel yang pembacaannya kurang dari 4 nggak bisa dijalankan
            // lewat peta kolom master tanpa menebak isi kolom yang kosong, dan
            // tebakan itu mendarat di kolom Sebaran Suhu yang tercetak.
            // Lihat [EnclosureCalculator::MIN_PEMBACAAN].
            if ($hasil['pembacaan_kurang'] !== []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Set point %s °C: termokopel %s pembacaannya kurang dari %d. Lengkapi dulu — '
                        .'kolom yang kosong nggak boleh ditebak dari pembacaan sebelumnya.',
                        $this->angka((float) $t['titik_ukur']),
                        implode(', ', array_map(
                            static fn (array $p): string => sprintf('no. %d (%d pembacaan)', $p['no'], $p['jumlah']),
                            $hasil['pembacaan_kurang'],
                        )),
                        EnclosureCalculator::MIN_PEMBACAAN,
                    ),
                ];

                continue;
            }

            $hitungan[] = $this->barisHitungan($hasil, $t['standard'], $kemampuan);
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Peringatan di layar teknisi/admin sebelum sertifikat terbit.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];
        $tipe = $this->normalkanTipeSensor($sesi->tipe_sensor);

        if ($tipe === null) {
            $peringatan[] = [
                'kode' => 'enclosure_tipe_sensor_kosong',
                'pesan' => 'Tipe sensor (Type N / Type K) belum dipilih. Koreksi & U95 termokopel beda per tipe.',
            ];
        }

        // Cek yang butuh database cuma buat sesi yang BENERAN tersimpan — kontrak
        // `peringatanSesi()` juga dipanggil ke sesi in-memory (uji bentuk), dan
        // querying di situ bikin seluruh pemeriksaan meledak. Sama pola dengan
        // TITS yang pulang lebih awal sebelum nyentuh DB.
        if (! $sesi->exists) {
            return $peringatan;
        }

        $merk = $this->merkKalibrator($sesi->rawMeasurements->first()?->standard ?? $sesi->standard);

        if ($merk === null) {
            $peringatan[] = [
                'kode' => 'enclosure_kalibrator_kosong',
                'pesan' => 'Merk kalibrator (Constant/Yokogawa/Recorder) belum kebaca dari standar yang dicentang. '
                    .'Tabel koreksi & drift beda per kalibrator, jadi tanpa ini sesinya nggak kehitung.',
            ];
        }

        if ($this->kemampuanEnclosure($sesi->equipment) === null) {
            $peringatan[] = [
                'kode' => 'enclosure_cmc_kosong',
                'pesan' => sprintf('CMC buat enclosure "%s" belum ada di master kemampuan kalibrasi.', $this->namaAlatKemampuan()),
            ];
        }

        return $peringatan;
    }

    /** Enclosure nggak punya pasangan titik→standar tetap — setpoint bebas per kapasitas. */
    public function standarPerTitik(): array
    {
        return [];
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'judul' => sprintf('Calibration Worksheet - Enclosure (%s)', $this->namaAlatKemampuan()),
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Tiap set point diisi GRID: 9 termokopel × 5 pembacaan, plus baris Indikator '
                .'enclosure & Suhu Ruang. MERK KALIBRATOR (Constant/Yokogawa/Recorder) dan TIPE SENSOR '
                .'(Type N/Type K) wajib dipilih — koreksi, drift, dan U95 beda per keduanya. Untuk kalibrator '
                .'Recorder tiap termokopel juga wajib punya nomor Channel (CH1..CH20).',
            'grid_sensor' => [
                'jumlah_sensor_saran' => 9,
                'pengulangan' => range(1, self::PENGULANGAN),
                'butuh_channel_untuk' => TabelKalibratorEnclosure::MERK_BERKANAL,
                'baris_indikator' => true,
                'baris_suhu_ruang' => true,
                'catatan_sensor_acuan' => 'Sensor pertama = Sensor Acuan (keseragaman diukur relatif ke sensor ini).',
            ],
            'bagian' => [
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION DATA',
                    'field' => [
                        $this->field('tipe_sensor', 'Temperature Type', 'pilihan', pilihan: array_map(
                            static fn (string $t): array => ['nilai' => $t, 'label' => $t],
                            TabelKalibratorEnclosure::TIPE_SENSOR,
                        )),
                        $this->field('lokasi', 'Location', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'Inlab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                    ],
                ],
            ],
        ];

        return $bentuk;
    }

    /**
     * Baris `uncertainty_calculations` untuk satu set point.
     *
     * @param  array<string, mixed>  $hasil
     * @return array<string, mixed>
     */
    private function barisHitungan(array $hasil, Standard $standar, CalibrationCapability $kemampuan): array
    {
        // Type B = RSS komponen yang IKUT & bukan "pengulangan" (aturan sama
        // dengan GumCalculator, biar kolom yang sama nggak beda arti).
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $hasil['budget'],
                static fn (array $k): bool => $k['disertakan']
                    && ! str_starts_with((string) $k['sumber'], 'pengulangan'),
            ),
        )));

        $typeA = $this->akarKuadratPengulangan($hasil['budget']);

        return [
            'standard_id' => $standar->id,
            'titik_ke' => $hasil['titik_ke'],
            'titik_ukur' => $hasil['setpoint'],
            // Pembacaan Indikator enclosure — angka yang ditampilkan alat.
            'rata_rata' => $hasil['indikator_enclosure'],
            // Enclosure nggak punya "error/koreksi" satu angka per set point;
            // yang dilaporkan sebaran spasial (di type_b_components). Keseragaman
            // dipakai sebagai wakil koreksi terbesarnya.
            'error' => -$hasil['keseragaman'],
            'koreksi' => $hasil['keseragaman'],
            'standar_deviasi' => $hasil['kestabilan'],
            'jumlah_pengulangan' => self::PENGULANGAN,
            'type_a' => $typeA,
            'type_b_components' => $this->jejakAudit($hasil, $kemampuan),
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
            'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
            'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
            'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
            'toleransi' => null,
            'keputusan' => null,
            'calculated_at' => Carbon::now(),
        ];
    }

    /**
     * Jejak audit `uncertainty_calculations.type_b_components` — LIST komponen
     * (bukan peta bertingkat, biar `CalibrationResource` nggak meledak), lalu
     * catatan penyimpangan, sebaran sensor, dan konteks sesi.
     *
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, CalibrationCapability $kemampuan): array
    {
        $audit = array_map(static fn (array $k): array => [
            'sumber' => $k['sumber'],
            'keterangan' => $k['keterangan'],
            'distribusi' => $k['distribusi'],
            'nilai' => $k['u'] * $k['ci'],
            'u_baku' => $k['u'],
            'ci' => $k['ci'],
            'vi' => $k['vi'],
            'disertakan' => $k['disertakan'],
        ], $hasil['budget']);

        foreach ($hasil['catatan_audit'] as $catatan) {
            $audit[] = [
                'sumber' => $catatan['kode'],
                'keterangan' => $catatan['pesan'],
                'distribusi' => '-',
                'nilai' => $hasil['ketidakpastian_diperluas'],
            ];
        }

        $audit[] = [
            'sumber' => 'sebaran_sensor',
            'keterangan' => sprintf(
                'Kestabilan %s °C, Keseragaman %s °C, Variasi %s °C. %d sensor (acuan: no. %d); '
                .'Indikator enclosure %s °C.',
                $this->angka($hasil['kestabilan']),
                $this->angka($hasil['keseragaman']),
                $this->angka($hasil['variasi_keseluruhan']),
                count($hasil['sensor']),
                $hasil['sensor_acuan'],
                $this->angka($hasil['indikator_enclosure']),
            ),
            'distribusi' => '-',
            'nilai' => $hasil['ketidakpastian_diperluas'],
            'kestabilan' => $hasil['kestabilan'],
            'keseragaman' => $hasil['keseragaman'],
            'variasi_keseluruhan' => $hasil['variasi_keseluruhan'],
            // Nomor termokopel yang jadi acuan Keseragaman. Dicatat eksplisit
            // karena "sensor pertama" itu posisi di grid yang terkirim: sensor
            // yang kosong dibuang sebelum sampai kalkulator, jadi acuannya bisa
            // bergeser tanpa ada yang menyadari.
            'sensor_acuan' => $hasil['sensor_acuan'],
            'sensor' => $hasil['sensor'],
        ];

        $audit[] = [
            'sumber' => 'konteks_sesi',
            'keterangan' => sprintf(
                'Enclosure %s, kalibrator %s, sensor %s. CMC %s °C, U95 dari %s.',
                $this->namaAlatKemampuan(),
                TabelKalibratorEnclosure::MERK_TERCETAK[$hasil['merk_kalibrator']] ?? $hasil['merk_kalibrator'],
                $hasil['tipe_sensor'],
                $this->angka($hasil['cmc']),
                $hasil['sumber_u95'] === 'cmc' ? 'lantai CMC' : 'hitungan budget',
            ),
            'distribusi' => '-',
            'nilai' => $hasil['ketidakpastian_diperluas'],
            'enclosure' => $this->namaAlatKemampuan(),
            'merk_kalibrator' => $hasil['merk_kalibrator'],
            'tipe_sensor' => $hasil['tipe_sensor'],
            'cmc' => $hasil['cmc'],
            'cmc_parameter' => $kemampuan->parameter,
            'sumber_u95' => $hasil['sumber_u95'],
        ];

        return $audit;
    }

    /** Type A gabungan = RSS komponen "pengulangan" yang ikut. */
    private function akarKuadratPengulangan(array $budget): float
    {
        return sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $budget,
                static fn (array $k): bool => $k['disertakan'] && str_starts_with((string) $k['sumber'], 'pengulangan'),
            ),
        )));
    }

    /** Alasan sesi belum bisa dihitung, atau `null` kalau lengkap. */
    private function syaratKurang(?string $merk, ?string $tipe, ?Standard $standar): ?string
    {
        if ($merk === null) {
            return sprintf(
                'Kalibrator standar "%s" nggak dikenali merknya — tabel enclosure cuma ada buat Constant, '
                .'Yokogawa & Recorder. Betulin kolom `merk` standar itu.',
                $standar?->nama ?? '(kosong)',
            );
        }

        if ($tipe === null) {
            return 'Tipe sensor (Type N / Type K) belum dipilih di sesi ini. Koreksi, drift, & U95 beda per tipe.';
        }

        return null;
    }

    /**
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    private function semuaBelum(array $titik, string $alasan): array
    {
        return [
            'hitungan' => [],
            'belum_dihitung' => array_map(
                static fn (array $t): array => ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $alasan],
                $titik,
            ),
        ];
    }

    /**
     * CMC untuk enclosure ini, dicocokkan lewat `nama_alat`.
     *
     * Disaring ke KATEGORI alatnya kalau kategorinya diketahui.
     * `calibration_capabilities` memang tidak punya `organization_id` sendiri
     * (isinya lampiran akreditasi lab), tapi `equipment_category_id`-nya menunjuk
     * `equipment_categories` yang DIKUNCI per organisasi. `nama_alat` sendiri
     * tidak dijamin unik lintas kategori/organisasi, jadi tanpa saringan ini
     * baris pertama yang kebetulan cocok bisa berasal dari kategori organisasi
     * lain — dan CMC yang salah langsung mendarat di sertifikat.
     */
    private function kemampuanEnclosure(?Equipment $equipment = null): ?CalibrationCapability
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment?->equipment_category_id !== null,
                fn ($q) => $q->where('equipment_category_id', $equipment->equipment_category_id),
            )
            ->first();
    }

    /** Merk kalibrator dari `standards.merk`, jadi kunci tabel. */
    private function merkKalibrator(?Standard $standar): ?string
    {
        $merk = strtolower(trim((string) ($standar?->merk ?? '')));

        // "Temperature Recorder" / "Graphtech" → recorder.
        if (str_contains($merk, 'recorder') || str_contains($merk, 'graphtech')) {
            return 'recorder';
        }

        return in_array($merk, TabelKalibratorEnclosure::MERK, true) ? $merk : null;
    }

    /** Ejaan tipe sensor diseragamkan (`Type N`/`Type K`, alias `n`/`k`). */
    private function normalkanTipeSensor(mixed $tipe): ?string
    {
        if (! is_string($tipe)) {
            return null;
        }

        $bersih = strtolower(preg_replace('/[\s_-]+/', ' ', trim($tipe)) ?? '');

        foreach (TabelKalibratorEnclosure::TIPE_SENSOR as $dikenal) {
            if ($bersih === strtolower($dikenal) || $bersih === strtolower(substr($dikenal, 5))) {
                return $dikenal;
            }
        }

        return null;
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 8, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param  list<array<string, string>>  $pilihan
     * @return array<string, mixed>
     */
    private function field(
        string $kode,
        string $label,
        string $tipe,
        ?string $sumber = null,
        ?string $satuan = null,
        array $pilihan = [],
        bool $hanyaAdmin = false,
    ): array {
        return [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'wajib' => false,
            'sumber' => $sumber,
            'satuan' => $satuan,
            'pilihan' => $pilihan,
            'hanya_admin' => $hanyaAdmin,
        ];
    }
}
