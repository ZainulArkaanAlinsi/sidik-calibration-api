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
     * Nomor formulir lembar kerja enclosure — dari kertasnya sendiri.
     *
     * Sebelumnya `null` dengan catatan "ditanyakan ke lab", karena satu-satunya
     * nomor yang kelihatan waktu itu (`SIDIK-FM-CAL-2403_Rev. 0`) ternyata
     * formulir sertifikat bersama, bukan lembar kerja. Pertanyaannya sekarang
     * sudah terjawab: pemilik proyek mengirim lembar kerjanya langsung, dan
     * kop halamannya bernomor `SIDIK-FM-CAL-0504 Rev.3`.
     *
     * Sengaja dipisah dari [KODE_METODE]: yang satu menomori LEMBAR KERJANYA,
     * yang satu menomori INSTRUKSI KERJA di baliknya. Dua-duanya naik revisi
     * sendiri-sendiri, dan menyamakannya bikin lembar tercetak mengaku ikut
     * revisi yang bukan revisinya.
     */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0504_Rev.3';

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

    /**
     * Tiga baris "Standar used" yang TERCETAK di formulir
     * `SIDIK-FM-CAL-0504 Rev.3`, apa adanya dari kertasnya.
     *
     * Ditulis di sini, bukan ditarik dari master standar, karena yang dituntut
     * lembar ini "ceklis mana yang dipakai dari tiga yang tercetak" — bukan
     * "pilih dari seluruh standar lab". Kalau ditarik dari master, daftarnya
     * ikut berubah tiap ada standar baru dan lembarnya berhenti cocok sama
     * kertas yang ditandatangani.
     *
     * `cocok` dipakai mencocokkan ke baris master standar lewat nama ATAU
     * nomor seri — nomor serinya yang paling jarang salah ketik.
     *
     * Catatan: Victor 14+ masih tercetak di kertas Rev.3, TAPI di master TITS
     * yang sekarang Victor sudah dihapus (FORM VALIDASI rev. 11, 24 Mei 2024:
     * "Remove std. Victor / Add std kalibrator yokogawa"), dan sheet tabel
     * koreksinya sudah nggak ada — `Index_Victor_*` semuanya `#REF!`. Jadi
     * baris kedua ini kemungkinan besar sisa cetakan lama. Dibiarkan tampil
     * karena kertas yang dipegang teknisi memang masih begitu; yang nggak boleh
     * adalah lembar di HP beda dari lembar di tangan.
     *
     * ## Yokogawa DITAMBAHKAN, Victor tetap tinggal
     *
     * Kertas Rev.3 nyetak tiga baris dan Yokogawa BUKAN salah satunya — padahal
     * dia kalibrator enclosure yang paling kepakai: master olah datanya sendiri
     * bernama `Master Olah Data_Suhu_Enclosure_Constant_Yokogawa.xlsm`, sesi
     * acuan `EnclosureSesiTest` memakainya, dan `TabelKalibratorEnclosure::MERK`
     * sudah lama punya tabel koreksinya.
     *
     * Akibat kalau dibiarkan: teknisi yang mengalibrasi pakai Yokogawa NGGAK
     * PUNYA baris buat dicentang. Dia nggak dapat error — dia cuma nggak bisa
     * menautkan standar, `merkKalibrator()` pulang null, dan SELURUH titiknya
     * nggak kehitung. Itu persis kegagalan yang dilaporkan 26 Agt 2026.
     *
     * Yang menambahkannya bukan tebakan: FORM VALIDASI rev. 11 yang dikutip di
     * atas berbunyi "Add std kalibrator yokogawa". Jadi ini menjalankan
     * keputusan lab yang sudah tertulis, cuma belum nyampe ke kertas Rev.3.
     *
     * Victor SENGAJA nggak dibuang walau rev. 11 minta dihapus: kertas yang
     * dipegang teknisi masih memuatnya, dan baris yang hilang dari layar bikin
     * dia mengira salah lembar. Karena `terdaftar` sekarang dihitung dari master
     * (lihat `tautkanStandar()`), Victor bakal tampil apa adanya sebagai baris
     * yang NGGAK terdaftar — jujur, bukan disembunyikan.
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Temperature Calibrator / Constant / 40T / 99875850',
            'cocok' => ['Temperature Calibrator Constant 40T', '99875850'],
        ],
        [
            'label' => 'Temperature Calibrator / Yokogawa / CA 150 Handy Cal / 23P1005',
            'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005'],
        ],
        [
            'label' => 'Temperature Calibrator / Victor / Victor 14+ / 992613877',
            'cocok' => ['Temperature Calibrator Victor 14+', '992613877'],
        ],
        [
            'label' => 'Temperature Recorder / Graptech / GL840-SDWV / C305B1470',
            'cocok' => ['Temperature Recorder Graptech GL840-SDWV', 'C305B1470'],
        ],
    ];

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

    /**
     * Jalur foto AI DITOLAK buat kelima lembar Enclosure.
     *
     * Dua penanda bentuk (`kolom_suhu`, `standar_di_baris`) cuma sanggup
     * menggambarkan lembar "titik ukur × Repeat". Kertas Enclosure bentuknya
     * GRID — 9 termokopel × 5 pembacaan per set point, plus baris Indikator &
     * Suhu Ruang — dan nggak ada kombinasi dua penanda itu yang
     * menggambarkannya.
     *
     * Tanpa penolakan ini, yang terjadi bukan error. Prompt & skema JSON yang
     * dikirim ke pembaca foto dibangun dari dua penanda itu, jadi modelnya
     * diminta membaca tabel yang NGGAK PERNAH ADA di kertasnya — dan yang balik
     * ke teknisi angka ngawur yang kelihatan wajar, di lembar yang hasilnya
     * masuk sertifikat terakreditasi.
     *
     * Ini penolakan yang sama persis dengan Autoklaf (matriks 7 besaran × 5
     * titik waktu) dan TIDS (dua tabel interval waktu). Ketiganya sekarang
     * lengkap; sebelum ini cuma Enclosure yang ketinggalan, dan ketinggalannya
     * nggak bergejala.
     *
     * Yang TIDAK ikut ditolak: jalur OCR template lokal (`PINDAI LEMBAR
     * KERJA`). Dia nggak pakai dua penanda ini sama sekali — dia pakai berkas
     * geometri per sel, dan sejak `TemplateLembarKerja` bisa menerjemahkan
     * grid, kelima lembar ini punya 55 sel yang sah.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung?: bool}
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => true, 'standar_di_baris' => false, 'didukung' => false];
    }

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
                // Teks yang dibaca teknisi di layar. Wajib sama dengan aturan
                // yang benar-benar dipakai `EnclosureCalculator::hitungSetpoint()`
                // — dulu di sini tertulis "sensor pertama", padahal urutan grid
                // sudah tidak menentukan apa pun sejak kalkulator mengurutkan
                // nomor sendiri. Instruksi layar yang bilang urutan itu penting
                // membuat teknisi menjaga sesuatu yang tidak berpengaruh, dan
                // menutupi hal yang benar-benar berpengaruh: nomornya.
                'catatan_sensor_acuan' => 'Sensor Acuan = termokopel bernomor TERKECIL yang terisi '
                    .'(keseragaman diukur relatif ke sensor itu). Urutan pengisian grid bebas — yang '
                    .'menentukan nomornya, bukan posisinya.',
            ],
            'bagian' => [
                [
                    'kode' => 'identitas_alat',
                    'halaman' => 1,
                    'judul' => 'Identitas Alat dan Data Customer',
                    'field' => [
                        // `equipment_id` INI YANG BIKIN LEMBAR ENCLOSURE HIDUP.
                        //
                        // Sampai sebelum ini bentuk lembar enclosure cuma punya
                        // dua kotak (tipe sensor & lokasi) dan nggak ada satu pun
                        // tempat milih ALATNYA. Sementara tombol kirim di HP
                        // nahan kalau `_isian.alat == null`. Jadi sesi enclosure
                        // baru NGGAK BISA DIKIRIM sama sekali — bukan "kurang
                        // mirip kertas", tapi fitur mati sejak lahir.
                        $this->field('equipment_id', 'Nama Alat', 'pilihan', sumber: 'master_alat'),
                        $this->field('equipment.nama_alat', 'Nama Alat (terpilih)', 'teks', sumber: 'otomatis'),
                        $this->field('alat_merk', 'Merk', 'teks'),
                        $this->field('alat_model', 'Type', 'teks'),
                        $this->field('alat_serial_number', 'No. Seri', 'teks'),
                        $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'angka', satuan: self::SATUAN),
                        $this->field('spesifikasi_alat.kapasitas', 'Kapasitas Alat', 'angka', satuan: self::SATUAN),
                        $this->field('spesifikasi_alat.resolusi', 'Resolusi Alat', 'angka', satuan: self::SATUAN),
                        $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                        $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                    ],
                ],
                [
                    'kode' => 'pemilik',
                    'halaman' => 1,
                    'judul' => 'Customer',
                    'field' => [
                        $this->field('pemilik_nama', 'Nama Customer', 'teks'),
                        $this->field('pemilik_alamat', 'Alamat Customer', 'teks_panjang'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'halaman' => 1,
                    'judul' => 'Standar used',
                    'baris' => self::STANDARD_TERCETAK,
                    'field' => [
                        $this->field('standar_dicek.*.dipakai', 'Dipakai', 'centang'),
                        $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                    ],
                ],
                [
                    'kode' => 'dimensi',
                    'halaman' => 1,
                    'judul' => 'Dimensi Alat',
                    // ## Blok ini CATATAN, bukan bahan hitung — dan itu dipastikan, bukan diduga
                    //
                    // Ditelusuri dua arah di dua master enclosure (15-16 sheet,
                    // termasuk yang tersembunyi):
                    //
                    //  - ke hulu: satu-satunya sel yang merujuk P/L/T & jari-jari
                    //    adalah dua sel Volume itu sendiri — 11 rujukan, semuanya
                    //    di dalam `INPUT DATA` baris 32 & 34.
                    //  - ke hilir: kedua sel Volume punya NOL konsumen. Nggak ada
                    //    satu pun sel di `PERHITUNGAN U95%`, `PERHITUNGAN FC`,
                    //    mau pun `SERTIFIKAT` yang mbacanya.
                    //
                    // Jadi dimensi nggak pernah nyentuh angka ketidakpastian dan
                    // nggak pernah kecetak di sertifikat. Ditulis di sini supaya
                    // yang berikutnya nggak menghabiskan waktu nyari "volume ini
                    // masuk komponen mana" — jawabannya: nggak masuk mana-mana.
                    //
                    // Yang beneran hidup di budget justru dua konstanta mati
                    // (efek radiasi 0,6 dan konduksi panas 0,1) plus efek
                    // pembebanan yang diturunkan dari keseragaman TERUKUR.
                    'field' => [
                        $this->field('dimensi_panjang', 'Panjang (P)', 'angka', satuan: 'm'),
                        $this->field('dimensi_lebar', 'Lebar (L)', 'angka', satuan: 'm'),
                        $this->field('dimensi_tinggi', 'Tinggi (T)', 'angka', satuan: 'm'),
                        $this->field('dimensi_jari_jari', 'Jari-jari (r)', 'angka', satuan: 'm'),
                        $this->field('dimensi_tinggi_silinder', 'Tinggi silinder (T)', 'angka', satuan: 'm'),
                        // Volume DIHITUNG, bukan diketik — persis kayak masternya:
                        // balok `P × L × T`, silinder `π · r² · t`, dalam METER,
                        // tanpa satu pun faktor konversi.
                        //
                        // Satu hal yang SENGAJA nggak ditiru dari master: di sana
                        // penjaganya bocor. Rumusnya `IF(AND(r=0,t=0), P*L*T, "-")`
                        // dan kebalikannya, jadi waktu SEMUA kotak kosong dua-duanya
                        // kondisinya benar dan hasilnya `0`, bukan `"-"`. Di master
                        // Recorder itu beneran kejadian: blok dimensinya kosong
                        // total tapi volumenya terbaca `0 m³`. "Nol meter kubik"
                        // dan "belum diisi" itu dua hal yang beda, dan yang satu
                        // nggak boleh menyamar jadi yang lain.
                        // Namanya SENGAJA pakai titik sementara lima kotak di
                        // atasnya nggak. Di repo ini titik di kode kolom itu
                        // penanda "nilai turunan" — `FieldLembarKerja.turunan`
                        // baca `kode.contains('.')`, dan kolom turunan nggak
                        // dikasih kotak isian sama sekali.
                        //
                        // Jadi bedanya bukan gaya penamaan: `dimensi_panjang`
                        // diketik teknisi, `dimensi.volume` dihitung. Kalau
                        // kelimanya ikut pakai titik, blok dimensinya tampil
                        // tapi NGGAK BISA DIKETIK — dan nggak ada yang gagal,
                        // kotaknya cuma diam.
                        $this->field('dimensi.volume', 'Volume', 'angka', sumber: 'otomatis', satuan: 'm³'),
                        $this->field('persyaratan_alat', 'Persyaratan Alat (ΔT)', 'angka', satuan: self::SATUAN),
                    ],
                ],
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
                        // Kelima profil enclosure lahir cuma dengan pilihan
                        // Inlab/Insitu — pasangan kotaknya nggak pernah ikut.
                        // Akibatnya sesi enclosure nggak punya tempat nyimpen
                        // LOKASINYA sama sekali, dan "Calibration Location" di
                        // sertifikat selalu jatuh ke tebakan
                        // `CertificateSnapshotBuilder::lokasiKalibrasi()`:
                        // `Laboratorium` buat yang Inlab, alamat pelanggan buat
                        // yang Insitu — dua-duanya kalimat yang nggak pernah
                        // diketik siapa pun.
                        $this->field('lokasi_nama', 'Nama Tempat (Insitu)', 'teks', tampilKalau: self::TAMPIL_KALAU_INSITU),
                        $this->field(
                            'room_id',
                            'Ruangan (Inlab)',
                            'pilihan',
                            sumber: 'master_ruangan',
                            tampilKalau: self::TAMPIL_KALAU_INLAB,
                        ),
                        $this->field(
                            'calibration_method_id',
                            'Calibration Methode',
                            'pilihan',
                            sumber: 'master_metode',
                            hanyaAdmin: true,
                        ),
                    ],
                ],
                [
                    'kode' => 'kondisi_lingkungan',
                    'halaman' => 1,
                    // ## Ini "Suhu Ruangan" yang HIDUP — jangan ketuker sama yang di grid
                    //
                    // Di lembar enclosure ada DUA hal bernama nyaris sama, dan cuma
                    // satu yang berpengaruh:
                    //
                    //  (a) "Suhu Ruangan" awal/akhir DI SINI — hidup. Di master dia
                    //      dirata-ratain, dikoreksi pakai sertifikat thermohygro,
                    //      diturunin U95-nya, lalu KECETAK di sertifikat sebagai
                    //      Env. Condition.
                    //  (b) baris "Suhu Ruang" di GRID sensor — mati. Nol konsumen
                    //      di seluruh workbook.
                    //
                    // Bedanya nyata: yang (b) di master Recorder rumus ringkasannya
                    // bahkan salah baris — nunjuk baris Indikator, bukan baris Suhu
                    // Ruang — dan keluar 67 °C padahal suhu ruang aslinya 24,6 °C.
                    // Selisih 43 °C yang nggak pernah ketahuan siapa pun, dan itu
                    // cuma mungkin kalau angkanya emang nggak pernah dipakai.
                    'judul' => 'Kondisi Lingkungan',
                    'field' => [
                        $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: self::SATUAN),
                        $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: self::SATUAN),
                        $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                        $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                        $this->field(
                            'thermohygro_standard_id',
                            'Thermohygro used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
                    ],
                ],
                [
                    'kode' => 'penutup',
                    'halaman' => 1,
                    'judul' => 'Catatan & Tanda Tangan',
                    'field' => [
                        $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                        $this->field('teknisi.nama', 'Dikalibrasi Oleh', 'teks', sumber: 'otomatis'),
                        $this->field('reviewer.nama', 'Diperiksa Oleh', 'teks', sumber: 'otomatis'),
                    ],
                ],
            ],
        ];

        return $this->tautkanStandar($this->isiPilihanThermohygro($bentuk));
    }

    /**
     * Baris "Standar used" yang tercetak DITAUTKAN ke baris `standards` asli.
     *
     * ## Kenapa ini ada
     *
     * Sampai 26 Agt 2026 lembar Enclosure mengirim baris tercetak apa adanya:
     * cuma `label` + `cocok`, TANPA `standard_id`. Layar HP membaca
     * `json['standard_id']` (lihat `lembar_kerja.dart`), dapat null, dan sesi
     * yang tersimpan `standard_id`-nya kosong.
     *
     * Dari situ jatuhnya beruntun dan senyap:
     * `merkKalibrator(null)` -> null -> `syaratKurang()` -> `semuaBelum()` —
     * SELURUH titik dicap belum dihitung. Yang dilihat admin bukan "standarnya
     * belum dipilih", tapi `titik_kosong` + `titik_tidak_terhitung` di tiap
     * titik: enam peringatan dari satu sebab, dan sebabnya paling nggak
     * kelihatan di antara semuanya.
     *
     * Kelima lembar Enclosure kena sekaligus karena semuanya mewarisi kelas ini.
     *
     * ## Kenapa nggak ketahuan test
     *
     * `EnclosureSesiTest` menyuapkan `standard_id` LANGSUNG ke payload, diambil
     * sendiri dari database. Dia nggak pernah lewat bentuk lembar kerja. Jadi
     * test membuktikan kalkulatornya benar, sementara jalur yang dilewati
     * manusia nggak pernah bisa sampai ke situ. Penjaganya sekarang ada di
     * `EnclosureStandarTertautTest`.
     *
     * ## Kenapa bentuknya begini
     *
     * Sama persis dengan `TitsProfile::tautkanStandar()` — sengaja, karena
     * dua-duanya menjawab kebutuhan yang sama dan dua bentuk yang beda buat satu
     * kebutuhan itu yang bikin salah satunya basi diam-diam. `whereNull(
     * 'parameter_kondisi')` menyaring ke KALIBRATOR: kolom itu cuma terisi di
     * baris thermohygro, dan tanpa saringan ini thermohygro bisa nyangkut jadi
     * kalibrator.
     *
     * Dicocokkan lewat nama ATAU nomor seri, seperti yang sudah dijanjikan
     * docblock `STANDARD_TERCETAK`. Nomor seri yang menyelamatkan baris
     * Recorder: kertas nyetak "Graptech GL840-SDWV" sementara master menulis
     * "Graphtech GL840" — beda huruf DAN beda model, jadi lewat nama nggak
     * akan pernah ketemu. Serialnya sama-sama `C305B1470`.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNull('parameter_kondisi')
            ->get(['id', 'nama', 'merk', 'serial_number', 'no_sertifikat', 'tertelusur_ke']);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $master->first(fn (Standard $s): bool => collect($baris['cocok'])
                        ->contains(fn (string $kunci): bool => $s->nama === $kunci
                            || $s->serial_number === $kunci));

                    return [
                        'label' => $baris['label'],
                        'standard_id' => $cocok?->id,
                        'merk' => $cocok?->merk,
                        'serial_number' => $cocok?->serial_number,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'tertelusur_ke' => $cocok?->tertelusur_ke,
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $bentuk['bagian'][$i]['baris'],
            );
        }

        return $bentuk;
    }

    /**
     * Unit thermohygro yang boleh dipilih di "Thermohygro used".
     *
     * Ketujuhnya — master Enclosure (Constant/Yokogawa) mencetak TH-1..TH-7
     * lengkap. Grup Inlab/Insitu-nya ikut penggolongan kanonik
     * (`LembarKerjaTemplate`, lembar pH); masternya TIDAK mencetak grup sama
     * sekali, cuma daftar namanya, jadi grup di sini bawaan dan bukan hasil
     * baca kertas. Aman: yang tersimpan `standard_id` yang sama apa pun
     * grupnya — ini cuma soal di bawah judul mana kotaknya muncul.
     */
    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-1', 'grup' => 'Inlab'],
        ['label' => 'TH-3', 'grup' => 'Inlab'],
        ['label' => 'TH-4', 'grup' => 'Inlab'],
        ['label' => 'TH-5', 'grup' => 'Inlab'],
        ['label' => 'TH-7', 'grup' => 'Inlab'],
        ['label' => 'TH-2', 'grup' => 'Insitu'],
        ['label' => 'TH-6', 'grup' => 'Insitu'],
    ];

    /**
     * Isi pilihan "Thermohygro used" untuk KELIMA profil Enclosure sekaligus.
     *
     * Tanpa ini kolomnya bukan error — cuma diam. `field()` memberi `pilihan`
     * nilai bawaan `[]`, layar teknisi menggambar dropdown dari daftar yang
     * dibawa bentuk (bukan dari master standar), dan daftar kosong bikin dia
     * jatuh ke cabang teks mati. Sesi jalan tanpa unit thermohygro, jadi
     * koreksi kondisi lingkungan berikut U95-nya nggak nempel ke unit mana pun.
     *
     * Lebih pahit di sini daripada di lembar lain: blok "Kondisi Lingkungan"
     * Enclosure sudah pernah kena kasus angka yang nggak kepakai, dan
     * komentarnya di atas masih menyimpan ceritanya — 67 °C padahal suhu ruang
     * aslinya 24,6 °C.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermohygro(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNotNull('parameter_kondisi')
            ->pluck('id', 'nama');

        $pilihan = [];
        foreach (self::THERMOHYGRO_TERCETAK as $unit) {
            $id = $master[$unit['label']] ?? null;
            if ($id === null) {
                continue;
            }
            $pilihan[] = [
                'nilai' => (string) $id,
                'label' => $unit['label'],
                'grup' => $unit['grup'],
            ];
        }

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['field'] ?? [] as $j => $field) {
                if ($field['kode'] === 'thermohygro_standard_id') {
                    $bentuk['bagian'][$i]['field'][$j]['pilihan'] = $pilihan;
                }
            }
        }

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
     * Disaring ke KATEGORI dan ORGANISASI alatnya kalau keduanya diketahui.
     * `nama_alat` sendiri tidak dijamin unik lintas kategori/organisasi, jadi
     * tanpa saringan ini baris pertama yang kebetulan cocok bisa berasal dari
     * lab lain — dan CMC yang salah langsung mendarat di sertifikat.
     *
     * Kategori saja TIDAK cukup, dan itu pelajaran yang baru dibayar: sampai
     * blokir scope organisasi ditutup, `calibration_capabilities` disebut "tidak
     * punya `organization_id` sendiri" di komentar ini. Kolomnya sudah ada sejak
     * migrasi 2026_08_24_100000, dan justru karena ada, dia bisa BEDA dari
     * organisasi kategorinya — baris milik lab A yang nangkring di kategori lab
     * B. Saringan kategori doang meloloskan baris itu bulat-bulat.
     */
    private function kemampuanEnclosure(?Equipment $equipment = null): ?CalibrationCapability
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment?->equipment_category_id !== null,
                fn ($q) => $q->where('equipment_category_id', $equipment->equipment_category_id),
            )
            ->when(
                $equipment?->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
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
        ?array $tampilKalau = null,
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
            'tampil_kalau' => $tampilKalau,
        ];
    }
}
