<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Services\Calibration\TekananCalculator;
use App\Support\LogMetodeTekananPiston as LogMetode;
use App\Support\TekananMentah as M;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Induk ketiga alat TEKANAN: Pressure Gauge, Vacuum Gauge, Differential
 * Pressure — lampiran LK-285-IDN no. 23–26, formulir kertas bersama
 * `SIDIK-FM-CAL-0507_Rev.5 — LEMBAR KERJA PRESSURE & VACUUM`.
 *
 * ## Profil = nama alat di lampiran, varian = workbook master
 *
 * Empat workbook master (DRUCK07G, DRUCK13G, SPMK, Differential) BUKAN empat
 * jenis alat: mereka empat KALIBRATOR. Alat pelanggan yang sama — pressure
 * gauge 0–10 bar — bisa dikalibrasi memakai DRUCK13G atau SPMK tergantung
 * rentangnya. Jadi profil mengikuti nama lampiran (kunci pencocokan ke
 * `equipments.nama_alat_kemampuan`, persis pola repo), dan kalibratornya dipilih
 * teknisi per sesi lewat `spesifikasi_alat.tekanan.varian` — preseden Timbangan
 * (tiga workbook, satu profil) dan Hydrometer (dua varian, toggle teknisi).
 *
 * ## Pilihan CMC mengikuti VARIAN, bukan profil
 *
 * Lampiran menulis lima baris tekanan, dan master memilih di antaranya lewat
 * kalibrator + jenis tekanan (`DATABASE!S5/S6`):
 *
 *   DRUCK07G non-vakum  → no. 24 pita psi 0–27,5   0,0048 psi  (= 0,033094848 kPa)
 *   DRUCK13G non-vakum  → no. 23 "Pressure Gauge"   0,066 psi
 *   SPMK                → no. 24 pita bar 0–600     0,11 bar
 *   DRUCK07G/13G vakum  → no. 25 "Vacuum Gauge"     0,43 inHg   (= 1,4561477 kPa / 0,2111962… Psi)
 *   Differential        → no. 26                    0,0093 mbar
 *
 * Panduan eksternal mencurigai dua nilai vakum itu "hasil tempel" karena
 * digitnya panjang. Bukan: `DATABASE!S5 = 0,43*S34` — CMC resmi 0,43 inHg
 * dikonversi ke satuan kerja. Dibaca dari `calibration_capabilities` lalu
 * dikonversi dengan faktor VARIAN ITU, dan `TekananCmcTest` mengadunya ke sel
 * master.
 *
 * ## Tidak ada PASS/FAIL
 *
 * Tidak satu pun dari empat sertifikat master memuat pernyataan kesesuaian.
 * Kelas tekanan (`R46`) memang dihitung, tapi cuma informasi di lembar kerja.
 */
abstract class TekananProfile extends CalibrationProfile
{
    /**
     * Blok STANDARD kertas FM-0507 Rev.5, disesuaikan ke kalibrator yang punya
     * master olah data.
     *
     * Kertasnya mencetak SPMK, ADDITEL, dan `FLUKE/718 300G/3281083`. Dua
     * kalibrator Druck yang dipakai workbook master TIDAK tercetak di kertas,
     * sementara Fluke 718 yang tercetak tidak punya master, tabel koreksi, maupun
     * tanggal jatuh tempo di berkas mana pun. Fluke sengaja tidak dimasukkan:
     * menyeed-nya berarti mengarang sertifikat standar. Pertanyaan lab P-4.
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'SPMK/SPMK 700/223180480', 'cocok' => ['SPMK 700', '223180480']],
        ['label' => 'ADDITEL/ADT681-05-DP5-MBAR/211H18280008', 'cocok' => ['Additel ADT681-05-DP5-MBAR', '211H18280008']],
        ['label' => 'GE/Druck DPI611-07G/5568079', 'cocok' => ['Druck DPI611-07G', '5568079']],
        ['label' => 'GE/Druck DPI611-13G/5608066', 'cocok' => ['Druck DPI611-13G', '5608066']],
    ];

    /**
     * Ketujuh unit — ikut master (`INPUT_DATA!E23` 1..7 → TH-1..TH-7), bukan
     * empat yang tercetak di kotak kertas FM-0507 (TH-2/4/6/7). Menyempitkan
     * dropdown ke kertas berarti sesi yang memakai TH-1 tidak bisa diisi,
     * padahal masternya menerimanya.
     */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /** Batas titik: master menyediakan baris 24–37 `PERHITUNGAN FC`. */
    public const TITIK_MAKS = 14;

    /**
     * Baris lampiran yang jadi CMC tiap varian × jenis tekanan. Kunci ke
     * `calibration_capabilities`: nama + satuan pita (tanpa peka huruf).
     */
    private const CMC_LAMPIRAN = [
        Tabel::DRUCK07G => [
            M::JENIS_NON_VAKUM => ['Pressure Gauge; Pressure Tranducer; Pressure Recorder; Pressure Safety Valve; Manometer', 'psi'],
            M::JENIS_VAKUM => ['Vacuum Gauge', 'inHg'],
        ],
        Tabel::DRUCK13G => [
            M::JENIS_NON_VAKUM => ['Pressure Gauge', 'psi'],
            M::JENIS_VAKUM => ['Vacuum Gauge', 'inHg'],
        ],
        Tabel::SPMK => [
            M::JENIS_NON_VAKUM => ['Pressure Gauge; Pressure Tranducer; Pressure Recorder; Pressure Safety Valve; Manometer', 'bar'],
        ],
        Tabel::DIFFERENTIAL => [
            M::JENIS_NON_VAKUM => ['Differential Pressure', 'mbar'],
        ],
    ];

    private ?TekananCalculator $kalk = null;

    /** @return list<string> varian kalibrator yang boleh dipakai alat ini */
    abstract public function varianDiizinkan(): array;

    /** `vakum` / `non_vakum` — dikunci per profil, lihat [PressureGaugeProfile]. */
    abstract public function jenisTekanan(): string;

    abstract public function kodeMetodeIk(): string;

    public function besaran(): string
    {
        return 'tekanan';
    }

    public function kodeMetode(): ?string
    {
        return $this->kodeMetodeIk();
    }

    public function kodeFormula(): string
    {
        return 'TEKANAN-'.strtoupper($this->kode());
    }

    public function versiRumus(): ?string
    {
        return LogMetode::versiTerakhir(LogMetode::TEKANAN);
    }

    /**
     * Cacat master (kode log metode) yang mengubah angka sesi ber-varian ini.
     * Syaratnya sama dengan `penyimpangan_master` di jejak.
     *
     * @return list<string>
     */
    protected function penyimpanganTerpicu(string $varian): array
    {
        return array_values(array_filter([
            $varian === Tabel::DRUCK07G ? 'T-1' : null,
            $varian !== Tabel::SPMK ? 'T-2' : null,
            $varian === Tabel::DRUCK13G && $this->jenisTekanan() === M::JENIS_VAKUM ? 'T-11' : null,
        ]));
    }

    /**
     * Keputusan pemilik proyek 28 Sep 2026 (keputusan 2 di log metode): sesi
     * yang memicu T-1/T-2/T-11 dihitung dua mode, tapi TIDAK terbit sampai
     * Technical Manager menjawab P-1/P-2/P-11. Menghitung beda dari metode yang
     * divalidasi berarti menerbitkan dari metode yang belum disahkan (ISO/IEC
     * 17025 7.2.1.5); meniru berarti sadar menerbitkan koreksi bertanda
     * terbalik. Dua-duanya ditahan, dua angkanya ditampilkan.
     *
     * Dibaca dari hasil hitung TERSIMPAN. Log yang menentukan apakah masih
     * ditahan: begitu jawaban TM dicatat di versi baru, penahanannya lepas.
     */
    public function penahanTerbit(CalibrationSession $sesi): array
    {
        $jejak = $sesi->uncertaintyCalculations
            ->pluck('type_b_components')
            ->filter(static fn (mixed $j): bool => is_array($j) && isset($j['tekanan_budget'], $j['versi_master']))
            ->values();

        if ($jejak->isEmpty()) {
            return [];
        }

        $terpicu = array_values(array_unique(array_merge(...$jejak->map(
            fn (array $j): array => $j['penyimpangan_terpicu'] ?? $this->penyimpanganTerpicu((string) $j['tekanan_budget']['varian']),
        )->all())));

        $awal = $jejak->first();
        $satuanKerja = (string) ($awal['tekanan_rantai']['satuan_kerja'] ?? '');
        $uMaster = (float) $awal['versi_master']['u_hitung'];
        $uBenar = (float) $awal['tekanan_budget']['u_hitung'];
        $u95Master = (float) $awal['versi_master']['u95'];
        $u95Benar = (float) $awal['tekanan_budget']['u95'];
        // U hitung DAN U95 terbit: lantai CMC bisa menyamakan U95 kedua versi
        // (sesi contoh DRUCK07G: dua-duanya 1,4561477 kPa) sementara U-nya
        // berbeda — yang diputuskan TM metodenya, bukan cuma angka tercetak.
        $angkaU95 = sprintf(
            'Kalau master ditiru: U %s, U95 terbit %s %s. Kalau dibetulkan: U %s, U95 terbit %s %s.',
            LogMetode::angka($uMaster),
            LogMetode::angka($u95Master),
            $satuanKerja,
            LogMetode::angka($uBenar),
            LogMetode::angka($u95Benar),
            $satuanKerja,
        );

        return array_map(function (array $p) use ($jejak, $satuanKerja, $angkaU95, $uMaster, $uBenar, $u95Master, $u95Benar): array {
            $titik = [];
            $rinci = '';

            if ($p['kode'] === 'T-11') {
                $titik = $jejak->map(static fn (array $j): array => [
                    'setelan' => (float) $j['tekanan_rantai']['setelan'],
                    'satuan' => (string) $j['tekanan_rantai']['satuan'],
                    'master' => (float) $j['versi_master']['koreksi_standar_up_titik_ini'],
                    'benar' => (float) $j['tekanan_rantai']['koreksi_standar_up'],
                ])->all();
                $rinci = ' Koreksi standar UP per titik (tiru master → dibetulkan, '.$satuanKerja.'): '.implode('; ', array_map(
                    static fn (array $t): string => sprintf(
                        '%s %s: %s → %s',
                        LogMetode::angka($t['setelan']),
                        $t['satuan'],
                        LogMetode::angka($t['master'], true),
                        LogMetode::angka($t['benar'], true),
                    ),
                    $titik,
                )).'.';
            }

            return LogMetode::butirTahan($p, $angkaU95.$rinci, [
                'satuan_kerja' => $satuanKerja,
                'u_master' => $uMaster,
                'u_benar' => $uBenar,
                'u95_master' => $u95Master,
                'u95_benar' => $u95Benar,
                'koreksi_up_per_titik' => $titik,
            ]);
        }, LogMetode::menahanTerbit(LogMetode::TEKANAN, $terpicu));
    }

    public function punyaToleransi(): bool
    {
        return false;
    }

    public function butuhBlokTekanan(): bool
    {
        return true;
    }

    /**
     * Budget tekanan lahir per SESI (MAX STDEV, MAX koreksi, zero error baris
     * pertama) — tidak bisa dijawab dari satu titik. Yang dipakai
     * [hitungPerGrup].
     *
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

    /** Kolom sertifikat dicetak oleh blok `tekanan` sendiri; lihat [tabelSertifikatTekanan]. */
    public function nilaiStandarDariKoreksi(): bool
    {
        return true;
    }

    public function judulKolomStandar(): string
    {
        return 'Standard Indication';
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

        $konteksSesi = [];
        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = M::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);
        $semua = static fn (string $alasan): array => [
            'hitungan' => [],
            'belum_dihitung' => array_map(static fn (array $t): array => [
                'titik_ke' => (int) $t['titik_ke'],
                'alasan' => $alasan,
            ], $titik),
        ];

        $penghalang = $this->penghalangSesi($blok, $titik);

        if ($penghalang !== null) {
            return $semua($penghalang);
        }

        $masukan = [
            'satuan' => (string) $blok['satuan'],
            'tampilan' => (string) $blok['tampilan'],
            'rasio_jarum' => $blok['rasio_jarum'],
            'resolusi' => (float) $blok['resolusi'],
            'kapasitas' => (float) $blok['kapasitas'],
            'media' => $blok['media'],
            'jenis_tekanan' => $this->jenisTekanan(),
            'beda_tinggi' => $blok['beda_tinggi'],
            'titik' => array_map(static fn (array $t): array => [
                'titik_ke' => (int) $t['titik_ke'],
                'setelan' => (float) $t['titik_ukur'],
                'up' => array_map('floatval', $t['konteks'][M::KONTEKS_UP] ?? []),
                'down' => array_map('floatval', $t['konteks'][M::KONTEKS_DOWN] ?? []),
            ], $titik),
        ];

        $varian = (string) $blok['varian'];

        try {
            $benar = $this->kalk()->hitungSesi($varian, $masukan, TekananCalculator::MODE_BENAR);
            $master = $this->kalk()->hitungSesi($varian, $masukan, TekananCalculator::MODE_MASTER);
        } catch (InvalidArgumentException $e) {
            return $semua($e->getMessage());
        }

        $cmcKerja = $this->cmcKerja($equipment, $varian);
        $uHitung = (float) $benar['U'];
        $u95Kerja = max($uHitung, $cmcKerja ?? 0.0);
        $u95KerjaMaster = max((float) $master['U'], $cmcKerja ?? 0.0);
        $f = (float) $benar['faktor'];
        $temuanSesi = $this->temuanSesi($benar);

        $uUlangKerja = 0.0;
        foreach ($benar['komponen'] as $k) {
            if ($k['sumber'] === 'pengulangan') {
                $uUlangKerja = (float) $k['u'];
            }
        }

        $uc = (float) $benar['uc'];
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($benar['per_titik'] as $i => $p) {
            $pm = $master['per_titik'][$i];

            $hitungan[] = [
                'titik_ke' => $p['titik_ke'],
                // Semua kolom DALAM SATUAN PILIHAN TEKNISI, bukan satuan kerja:
                // yang dicetak sertifikat dan yang dibaca Master Data. Rantai
                // satuan kerjanya utuh di jejak audit.
                'titik_ukur' => $p['setelan'],
                // `rata_rata` = penunjukan UUT = setelannya sendiri. Yang dibaca
                // berulang di alat ini justru STANDARNYA, persis kelompok Gaya.
                'rata_rata' => $p['setelan'],
                // Kolom `koreksi` baris ini = koreksi arah UP. DOWN, histeresis,
                // dan standar terkoreksi kedua arah dicetak blok sertifikat
                // `tekanan` dari jejak di bawah — tabel empat kolom bawaan
                // cuma punya tempat untuk satu arah.
                'error' => -$p['koreksi_up_tampil'],
                'koreksi' => $p['koreksi_up_tampil'],
                'standar_deviasi' => max($p['stdev_up'], $p['stdev_down']) / $f,
                'jumlah_pengulangan' => 2 * TekananCalculator::PENGULANGAN,
                'type_a' => $uUlangKerja / $f,
                'type_b_components' => $this->jejakAudit($benar, $master, $p, $pm, $cmcKerja, $u95Kerja, $u95KerjaMaster, $varian, $temuanSesi),
                'type_b' => sqrt(max(0.0, $uc ** 2 - $uUlangKerja ** 2)) / $f,
                'ketidakpastian_gabungan' => $uc / $f,
                'faktor_cakupan_k' => $benar['k'],
                'derajat_kebebasan_efektif' => $benar['veff'],
                'ketidakpastian_diperluas' => $u95Kerja / $f,
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $this->kodeMetodeIk(),
                'calculated_at' => $sekarang,
            ];
        }

        return ['hitungan' => $hitungan, 'belum_dihitung' => []];
    }

    /**
     * Alasan SELURUH sesi tidak boleh dihitung, atau `null`.
     *
     * Yang memblokir, bukan sekadar label (prompt panduan §11, adendum OCR K-3):
     *
     * - varian kalibrator belum dipilih / tidak berlaku untuk alat ini;
     * - satuan di luar daftar VARIAN itu (daftarnya beda: 12 / 12 / 7 / 10);
     * - alat analog tanpa rasio jarum — centang itu menentukan pembagi resolusi;
     * - resolusi / kapasitas kosong atau ≤ 0;
     * - SPMK tanpa media atau beda tinggi — keduanya masuk rantai;
     * - lebih dari 14 titik, atau titik yang bacaannya tidak TEPAT 3 UP + 3 DOWN;
     * - titik di LUAR rentang tabel kalibrator setelah konversi (T-4). Excel
     *   membiarkannya dan menerapkan koreksi dari set point terdekat — angka
     *   yang secara metrologi tidak berarti apa-apa.
     *
     * Standar kedaluwarsa TIDAK diulang di sini: `CalibrationValidator`
     * menegakkannya untuk SEMUA alat, dan dua penjaga untuk satu keadaan
     * menghasilkan dua pesan yang berbeda.
     *
     * @param  array<string, mixed>|null  $blok
     * @param  list<array<string, mixed>>  $titik
     */
    protected function penghalangSesi(?array $blok, array $titik): ?string
    {
        if ($blok === null) {
            return 'Sesi tekanan belum punya blok `spesifikasi_alat.'.M::KUNCI_SESI.'` — kalibrator, satuan, '
                .'tipe tampilan, dan resolusi lahir di situ, bukan per titik.';
        }

        $varian = $blok['varian'];

        if ($varian === null || ! in_array($varian, $this->varianDiizinkan(), true)) {
            return sprintf(
                'Kalibrator standar belum dipilih atau tidak berlaku untuk %s (yang boleh: %s). Tabel koreksi, '
                .'U95 sertifikat, dan drift standar lahir dari pilihan itu.',
                $this->namaAlatKemampuan(),
                implode(', ', $this->varianDiizinkan()),
            );
        }

        if ($blok['satuan'] === null || Tabel::faktor($varian, $blok['satuan']) === null) {
            return sprintf(
                'Satuan "%s" tidak ada di daftar kalibrator %s: %s.',
                $blok['satuan'] ?? '—',
                $varian,
                implode(', ', Tabel::satuanTersedia($varian)),
            );
        }

        if (! in_array($blok['tampilan'], [M::TAMPILAN_ANALOG, M::TAMPILAN_DIGITAL], true)) {
            return 'Tipe tampilan alat (Analog/Digital) belum dipilih — dia menentukan pembagi resolusi di budget.';
        }

        if ($blok['tampilan'] === M::TAMPILAN_ANALOG && ! in_array($blok['rasio_jarum'], M::RASIO_JARUM, true)) {
            return 'Alat analog wajib mencentang rasio jarum/NST (1/2, 1/5, 1/10). Centang itu tidak tercetak di '
                .'sertifikat, tapi dia pembagi komponen daya baca — di Differential 99,99% budget.';
        }

        if (($blok['resolusi'] ?? 0.0) <= 0.0 || ($blok['kapasitas'] ?? 0.0) <= 0.0) {
            return 'Resolusi dan kapasitas maksimum alat wajib diisi dan lebih dari nol.';
        }

        if ($varian === Tabel::SPMK) {
            if ($blok['media'] === null || Tabel::massaJenisMedia($varian, (int) $blok['media']) === null) {
                return 'SPMK wajib memilih media (Water/Oil/Air): massa jenisnya masuk koreksi beda tinggi dan '
                    .'koefisien sensitivitas beda level.';
            }

            if ($blok['beda_tinggi'] === null) {
                return 'SPMK wajib mengisi beda tinggi (h) standar–UUT, walau nol: koreksinya ditambahkan ke '
                    .'setiap bacaan standar.';
            }
        }

        if (count($titik) > self::TITIK_MAKS) {
            return sprintf('Maksimal %d titik per sesi (baris 24–37 master); terisi %d.', self::TITIK_MAKS, count($titik));
        }

        $faktor = (float) Tabel::faktor($varian, (string) $blok['satuan']);
        [$min, $maks] = Tabel::rentang($varian);

        foreach ($titik as $t) {
            $up = $t['konteks'][M::KONTEKS_UP] ?? [];
            $down = $t['konteks'][M::KONTEKS_DOWN] ?? [];

            if (count($up) !== TekananCalculator::PENGULANGAN || count($down) !== TekananCalculator::PENGULANGAN) {
                return sprintf(
                    'Titik ke-%d terisi %d UP + %d DOWN; wajib TEPAT %d + %d. Budget pengulangan dan histeresis '
                    .'per pengulangan mengandaikan jumlah itu.',
                    $t['titik_ke'],
                    count($up),
                    count($down),
                    TekananCalculator::PENGULANGAN,
                    TekananCalculator::PENGULANGAN,
                );
            }

            $kerja = (float) $t['titik_ukur'] * $faktor;

            if ($kerja < $min || $kerja > $maks) {
                return sprintf(
                    'Titik ke-%d (%s %s = %s %s) di luar rentang tabel kalibrator %s (%s s/d %s %s). Koreksi standar '
                    .'di luar rentang itu tidak tervalidasi — master Excel tetap menerapkannya dari set point '
                    .'terdekat (temuan T-4), sistem ini memblokir.',
                    $t['titik_ke'],
                    $t['titik_ukur'],
                    $blok['satuan'],
                    round($kerja, 6),
                    Tabel::satuanKerja($varian),
                    $varian,
                    $min,
                    $maks,
                    Tabel::satuanKerja($varian),
                );
            }
        }

        return null;
    }

    /**
     * Peringatan tingkat-SESI — terlihat, tidak memblokir. Ambang angkanya
     * belum ditetapkan lab (pertanyaan P-8), jadi yang dipakai cuma yang
     * pasti-pasti saja.
     *
     * @param  array<string, mixed>  $h
     * @return list<string>
     */
    protected function temuanSesi(array $h): array
    {
        $temuan = [];
        $pertama = $h['per_titik'][0];

        if ($pertama['setelan'] != 0.0) {
            $temuan[] = sprintf(
                'Titik pertama bersetelan %s, bukan nol. Zero error master (`Q39`) SELALU dibaca dari titik '
                .'pertama, jadi komponennya sekarang berisi histeresis titik %s — bukan zero error.',
                $pertama['setelan'],
                $pertama['setelan'],
            );
        }

        $sebelum = null;
        foreach ($h['per_titik'] as $p) {
            if ($sebelum !== null && $p['rata_up'] < $sebelum['rata_up'] && $p['setelan'] > $sebelum['setelan']) {
                $temuan[] = sprintf(
                    'Bacaan standar UP turun (%s → %s %s) padahal setelan naik (%s → %s) — tekanan tidak mungkin '
                    .'turun saat dipompa; kemungkinan salah baca atau salah kolom.',
                    round($sebelum['rata_up'] / $h['faktor'], 6),
                    round($p['rata_up'] / $h['faktor'], 6),
                    $h['satuan'],
                    $sebelum['setelan'],
                    $p['setelan'],
                );
            }

            $sebelum = $p;
        }

        return $temuan;
    }

    /**
     * CMC dalam SATUAN KERJA varian, dari baris lampiran LK-285-IDN.
     *
     * `null` kalau barisnya tidak ada di `calibration_capabilities` lab ini —
     * dan itu TIDAK diam-diam jadi lantai nol: jejak auditnya menyebutnya, dan
     * `CalibrationValidator::periksaAlatTanpaCmc()` sudah menyorot alat tanpa CMC.
     */
    protected function cmcKerja(Equipment $equipment, string $varian): ?float
    {
        [$nama, $satuanPita] = self::CMC_LAMPIRAN[$varian][$this->jenisTekanan()]
            ?? self::CMC_LAMPIRAN[$varian][M::JENIS_NON_VAKUM];

        $pita = CalibrationCapability::query()
            ->where('nama_alat', $nama)
            ->when(
                $equipment->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
            )
            ->get()
            ->first(static fn (CalibrationCapability $p): bool => $p->punyaCmc()
                && strcasecmp((string) $p->satuan_ketidakpastian, $satuanPita) === 0);

        if ($pita === null) {
            return null;
        }

        $faktor = Tabel::faktor($varian, (string) $pita->satuan_ketidakpastian);

        return $faktor === null ? null : (float) $pita->ketidakpastian_terbaik * $faktor;
    }

    /**
     * Jejak yang terbaca TANPA membuka kode (AGENTS.md §Olah data butir 4):
     * rantai per titik dalam satuan kerja, budget lengkap, angka versi MASTER
     * berdampingan dengan yang terbit, dan penyimpangan master beserta
     * sumbernya.
     *
     * @param  array<string, mixed>  $benar
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $pm
     * @param  list<string>  $temuanSesi
     * @return array<string, mixed>
     */
    protected function jejakAudit(
        array $benar,
        array $master,
        array $p,
        array $pm,
        ?float $cmcKerja,
        float $u95Kerja,
        float $u95KerjaMaster,
        string $varian,
        array $temuanSesi,
    ): array {
        $f = (float) $benar['faktor'];
        $selisih = abs($u95Kerja - $u95KerjaMaster);

        return [
            'komponen' => $benar['komponen'],
            'tekanan_rantai' => [
                'satuan' => $benar['satuan'],
                'satuan_kerja' => $benar['satuan_kerja'],
                'faktor' => $f,
                'setelan' => $p['setelan'],
                'E_kerja' => $p['E'],
                'up_kerja' => $p['up_kerja'],
                'down_kerja' => $p['down_kerja'],
                'rata_up' => $p['rata_up'],
                'rata_down' => $p['rata_down'],
                'deviasi_up' => $p['deviasi_up'],
                'deviasi_down' => $p['deviasi_down'],
                'stdev_up' => $p['stdev_up'],
                'stdev_down' => $p['stdev_down'],
                'histeresis' => $p['histeresis'],
                'histeresis_rata' => $p['histeresis_rata'],
                'indeks_standar' => $p['indeks'],
                'koreksi_standar_up' => $p['koreksi_up'],
                'koreksi_standar_down' => $p['koreksi_down'],
                'koreksi_tinggi' => $p['koreksi_tinggi'],
                'terkoreksi_up' => $p['terkoreksi_up'],
                'terkoreksi_down' => $p['terkoreksi_down'],
                'u95_kalibrator_titik' => $p['u95_titik'],
                // Yang DICETAK — satuan pilihan teknisi.
                'standar_up_tampil' => $p['standar_up_tampil'],
                'standar_down_tampil' => $p['standar_down_tampil'],
                'koreksi_up_tampil' => $p['koreksi_up_tampil'],
                'koreksi_down_tampil' => $p['koreksi_down_tampil'],
                'histeresis_tampil' => $p['histeresis_tampil'],
            ],
            'tekanan_budget' => [
                'varian' => $varian,
                'agregat' => $benar['agregat'],
                'uc' => $benar['uc'],
                'v_eff' => $benar['veff'],
                'df_inverse_t' => $benar['df'],
                'k' => $benar['k'],
                'u_hitung' => $benar['U'],
                'cmc' => $cmcKerja,
                'u95' => $u95Kerja,
                'lantai_cmc_menang' => $cmcKerja !== null && $cmcKerja > (float) $benar['U'],
                'cmc_tidak_ditemukan' => $cmcKerja === null,
            ],
            'versi_master' => [
                'keterangan' => 'Angka yang akan keluar kalau cacat master T-1/T-2/T-11 DITIRU (keputusan '
                    .'28 Sep 2026: dihitung benar). Ditulis supaya selisihnya terbaca tanpa membuka kode.',
                'u_hitung' => $master['U'],
                'v_eff' => $master['veff'],
                'k' => $master['k'],
                'u95' => $u95KerjaMaster,
                'selisih_u95_kerja' => $selisih,
                'u95_kalibrator' => $master['agregat']['u95_kalibrator'],
                'komponen_pengulangan' => $master['komponen'],
                'koreksi_standar_up_titik_ini' => $pm['koreksi_up'],
            ],
            // Versi rumus yang BENAR-BENAR menghitung baris ini — diadu
            // `CalibrationValidator` ke versi formula yang distempelkan.
            'versi_rumus' => LogMetode::stempel(LogMetode::TEKANAN),
            // Kode log metode yang mengubah angka sesi ini — dibaca
            // `penahanTerbit()` dari hasil TERSIMPAN, bukan dihitung ulang.
            'penyimpangan_terpicu' => $this->penyimpanganTerpicu($varian),
            'penyimpangan_master' => array_filter([
                't1_u95_kalibrator' => $varian === Tabel::DRUCK07G
                    ? 'DRUCK07G `PERHITUNGAN FC!AH24:AH37` membaca `VLOOKUP(ABC4, …)` — salah ketik, U95 '
                        .'kalibrator selalu dari set point 0. Dihitung benar dari indeks titik. Pertanyaan lab P-1.'
                    : null,
                't2_pengulangan' => $varian !== Tabel::SPMK
                    ? '`PERHITUNGAN U95%!N14` merujuk `PERHITUNGAN FC!I44` yang KOSONG di master ini, jadi '
                        .'komponen pengulangan selalu nol. Dihitung dengan rumus induk SPMK `MAX(U24:V37)`. '
                        .'Pertanyaan lab P-2.'
                    : null,
                't11_koreksi_vakum' => $varian === Tabel::DRUCK13G && $this->jenisTekanan() === M::JENIS_VAKUM
                    ? 'DRUCK13G `AD24` cabang Vacum membaca kolom 4 (U95) sebagai koreksi UP. Dihitung benar '
                        .'dari kolom koreksi. Pertanyaan lab P-11.'
                    : null,
                't3_pembagi_pengulangan' => 'Pembagi komponen pengulangan `3` dengan v = 2, bukan √3 — ditiru '
                    .'apa adanya (kejanggalan metode). Pertanyaan lab P-3.',
                'drift' => ($d = Tabel::drift($varian)) !== null
                    ? sprintf('Drift standar %s = %s (%s). %s', $d['sel_master'], $d['nilai'], $d['metode'], $d['catatan'])
                    : null,
            ], static fn (?string $x): bool => $x !== null),
            'temuan_sesi' => $temuanSesi,
        ];
    }

    /**
     * Data blok sertifikat `tekanan` — tabel master `SERTIFIKAT!E20:V34` plus
     * baris U95 & k (`P35`, `U36`). Dibaca dari hasil hitung TERSIMPAN, bukan
     * dihitung ulang: sertifikat lima tahun lalu harus tetap sama angkanya.
     *
     * @return array<string, mixed>|null
     */
    public function tabelSertifikatTekanan(CalibrationSession $sesi): ?array
    {
        $blok = M::blokSesi($sesi->spesifikasi_alat);
        $baris = [];
        $u95 = null;
        $k = null;

        foreach ($sesi->uncertaintyCalculations()->orderBy('titik_ke')->get() as $h) {
            $r = $h->type_b_components['tekanan_rantai'] ?? null;

            if (! is_array($r)) {
                continue;
            }

            $baris[] = [
                'setelan' => $r['setelan'],
                'standar_up' => $r['standar_up_tampil'],
                'standar_down' => $r['standar_down_tampil'],
                'koreksi_up' => $r['koreksi_up_tampil'],
                'koreksi_down' => $r['koreksi_down_tampil'],
                'histeresis' => $r['histeresis_tampil'],
            ];
            $u95 = (float) $h->ketidakpastian_diperluas;
            $k = $h->type_b_components['tekanan_budget']['k'] ?? (float) $h->faktor_cakupan_k;
        }

        if ($baris === []) {
            return null;
        }

        $desimal = self::desimalDari($blok['resolusi'] ?? null);

        return [
            'satuan' => $blok['satuan'] ?? '',
            'baris' => $baris,
            'u95' => $u95,
            // Master mencetak `ROUND(k;1)` di keempat sertifikat tekanan.
            'k_cetak' => $k === null ? null : round((float) $k, 1),
            'desimal' => $desimal,
            'desimal_u95' => $desimal + 1,
        ];
    }

    /** Desimal cetak = desimal resolusi alat (0,1 → 1; 0,05 → 2). */
    public static function desimalDari(mixed $resolusi): int
    {
        $s = rtrim(rtrim(sprintf('%.10F', (float) $resolusi), '0'), '.');
        $titik = strpos($s, '.');

        return $titik === false ? 0 : strlen($s) - $titik - 1;
    }

    protected function kalk(): TekananCalculator
    {
        // Malas, bukan di konstruktor: kalkulator menyentuh GumCalculator →
        // CalibrationProfileRegistry → profil ini lagi.
        return $this->kalk ??= new TekananCalculator;
    }

    /** @return array<string, mixed> */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($this->rangkaLembar(), $equipment),
            $equipment,
        );
    }

    /** Label varian untuk dropdown kalibrator. */
    public static function labelVarian(string $varian): string
    {
        return match ($varian) {
            Tabel::DRUCK07G => 'GE Druck DPI611-07G (-0,8 ~ 2 bar)',
            Tabel::DRUCK13G => 'GE Druck DPI611-13G (0 ~ 20 bar)',
            Tabel::SPMK => 'SPMK 700 (0 ~ 600 bar)',
            Tabel::DIFFERENTIAL => 'Additel ADT681-05-DP5-MBAR (±10 mbar)',
            default => $varian,
        };
    }

    /** @return array<string, mixed> */
    protected function rangkaLembar(): array
    {
        return [
            'kode_dokumen' => 'SIDIK-FM-CAL-0507_Rev.5',
            'kode_metode' => $this->kodeMetodeIk(),
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Worksheet - Pressure & Vacuum Gauge',
            'jumlah_pengulangan' => TekananCalculator::PENGULANGAN,
            'semua_kolom_opsional' => false,
            'catatan_pengisian' => 'Tiap titik: naikkan tekanan sampai jarum/display UUT TEPAT di angka setelan, '
                .'lalu catat bacaan STANDAR — tiga kali arah naik (UP), lalu tiga kali arah turun (DOWN). '
                .'Titik pertama wajib titik NOL: zero error dibaca dari situ. Satuan yang dipilih berlaku untuk '
                .'setelan dan bacaan standar sekaligus.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olah Data Pressure (DRUCK07G / DRUCK13G / SPMK / Differential), '
                    .'PERHITUNGAN FC & PERHITUNGAN U95%',
                'catatan' => 'Enam komponen (tujuh di SPMK: + beda level), k dari t-Student dengan v_eff dipotong '
                    .'ke bawah, lantai CMC dari lampiran LK-285-IDN. Tiga cacat master yang mengecilkan U95 '
                    .'dihitung benar; angka versi master tercatat di jejak sesi.',
            ],
            'tanpa_keputusan' => [
                'alasan' => 'Sertifikat tekanan tidak menyatakan lulus/tidak lulus — keempat master tidak '
                    .'memuat pernyataan kesesuaian (ISO/IEC 17025 klausul 7.8.6.1).',
            ],
            'bagian' => [
                $this->bagianIdentitasAlat(),
                $this->bagianPemilik(),
                $this->bagianStandar(),
                $this->bagianPengukuran(),
                $this->bagianPenutup(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianIdentitasAlat(): array
    {
        $satuan = [];
        foreach ($this->varianDiizinkan() as $v) {
            foreach (Tabel::satuanTersedia($v) as $s) {
                $satuan[$s] = $s;
            }
        }

        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Equipment',
            'field' => [
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('alat_model', 'Type/Model', 'teks'),
                $this->field('alat_merk', 'Merk/Manufacture', 'teks'),
                $this->field('alat_serial_number', 'Serial Number/LPI', 'teks'),
                $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.kapasitas', 'Kapasitas Maks (Range)', 'angka'),
                $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.resolusi', 'Resolusi', 'angka'),
                // Satu satuan untuk setelan DAN bacaan standar — master
                // (`G15 = Z6`, `M28 = G15`) tidak pernah membedakannya.
                // Daftarnya per varian; yang tidak cocok dengan kalibrator
                // yang dipilih ditolak [penghalangSesi] dengan alasan terbaca.
                $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.satuan', 'Satuan Tekanan', 'pilihan',
                    pilihan: array_values($satuan)),
                $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.tampilan', 'Option of Resolution Display', 'pilihan',
                    pilihan: [
                        ['nilai' => M::TAMPILAN_DIGITAL, 'label' => 'Digital'],
                        ['nilai' => M::TAMPILAN_ANALOG, 'label' => 'Analog (rasio jarum/NST)'],
                    ]),
                $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.rasio_jarum', 'Rasio jarum/NST', 'pilihan',
                    pilihan: M::RASIO_JARUM,
                    ekstra: ['mempengaruhi_ketidakpastian' => true]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Owner',
            'field' => [
                $this->field('pemilik_nama', 'Name', 'teks'),
                $this->field('pemilik_alamat', 'Address', 'teks_panjang'),
                $this->field('suhu_awal', 'Environment Cond. — First (°C)', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Environment Cond. — End (°C)', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Environment Cond. — First (%RH)', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Environment Cond. — End (%RH)', 'angka', satuan: '%RH'),
                $this->field('lokasi', 'Location', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                $this->field(
                    'room_id', 'Ruangan (Inlab)', 'pilihan',
                    sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                $this->field(
                    'lokasi_nama', 'Nama Tempat (Insitu)', 'teks',
                    tampilKalau: self::TAMPIL_KALAU_INSITU,
                ),
                $this->field('thermohygro_standard_id', 'Thermohygro used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianStandar(): array
    {
        $field = [
            $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.varian', 'Kalibrator (master olah data)', 'pilihan',
                pilihan: array_map(
                    static fn (string $v): array => ['nilai' => $v, 'label' => self::labelVarian($v)],
                    $this->varianDiizinkan(),
                ),
                ekstra: ['mempengaruhi_ketidakpastian' => true]),
        ];

        if (in_array(Tabel::SPMK, $this->varianDiizinkan(), true)) {
            $field[] = $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.media', 'Media', 'pilihan',
                pilihan: array_map(
                    static fn (array $m): array => ['nilai' => (string) $m['nomor'], 'label' => (string) $m['media']],
                    Tabel::daftarMedia(Tabel::SPMK),
                ),
                ekstra: ['mempengaruhi_ketidakpastian' => true]);
            $field[] = $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.tinggi_standar', 'Standard Pressure Height (m)', 'angka', satuan: 'm');
            $field[] = $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.tinggi_uut', 'UUT Pressure Height (m)', 'angka', satuan: 'm');
            $field[] = $this->field('spesifikasi_alat.'.M::KUNCI_SESI.'.beda_tinggi', 'Deviation Height (h) (m)', 'angka', satuan: 'm',
                ekstra: ['mempengaruhi_ketidakpastian' => true]);
        }

        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'Standard',
            'baris' => static::STANDARD_TERCETAK,
            'field' => $field,
        ];
    }

    /**
     * Dua tabel yang barisnya SINKRON: baris ke-n keduanya titik setelan yang
     * sama, dibaca naik lalu turun. `offset_kunci` beda supaya HP tidak
     * berbagi kotak isian; `simpan_ke` bernama supaya kedua deret sampai
     * server terpisah — pola Proving Ring.
     *
     * @return array<string, mixed>
     */
    protected function bagianPengukuran(): array
    {
        $baris = array_map(
            static fn (int $n): array => ['nomor' => $n, 'titik_ukur' => null, 'label' => 'Titik '.$n],
            range(1, self::TITIK_MAKS),
        );

        $tabel = static fn (string $peran, int $offset, string $judul): array => [
            'tahap' => 'sesudah_adjustment',
            'grup' => $peran,
            'offset_kunci' => $offset,
            'judul' => $judul,
            'judul_nilai' => 'UUT Setting',
            'judul_pengulangan' => 'Pengulangan ke',
            'titik_bisa_diubah' => false,
            'simpan_ke' => 'measurements[].'.$peran,
            'baris' => $baris,
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => 'Standard Reading', 'tipe' => 'angka'],
            ],
            'pengulangan' => range(1, TekananCalculator::PENGULANGAN),
        ];

        return [
            'kode' => 'hasil',
            'halaman' => 2,
            'judul' => 'Pressure Calibration',
            // Tampilan saja: satu kartu per set point, UP & DOWN berdampingan
            // di layar lebar — kertas 0507 satu baris `UUT | UP 1-3 | DOWN
            // 1-3`, bukan dua tabel yang digulir naik-turun (6 Okt 2026).
            'tampilan' => 'kartu_per_baris',
            'kartu_sejajar' => true,
            'nominal_berbintang' => false,
            'field' => [],
            'tabel' => [
                $tabel(M::PERAN_UP, 1000, 'UP (tekanan dinaikkan)'),
                $tabel(M::PERAN_DOWN, 2000, 'DOWN (tekanan diturunkan)'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 2,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    /**
     * Pindai foto: jalur AI cloud sehalaman DIMATIKAN untuk alat ini. Satu baris
     * lembar tekanan berisi enam angka yang hampir sama (99,6 99,7 99,8 / 99,3
     * 99,6 99,7), dan satu-satunya pembeda UP dari DOWN adalah POSISINYA di
     * kertas — model yang membaca sehalaman menebak urutan, dan tebakan yang
     * benar sembilan dari sepuluh kali justru yang berbahaya. Jalur lokal
     * (kunci sel sebelum baca) yang dipakai. Adendum OCR §3.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung: bool, lokal: bool}
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
