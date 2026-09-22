<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\VolumetricGlasswareCalculator;
use App\Support\VolumetricGlasswareMentah as M;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Kelas dasar **Volumetric Glassware** — kalibrasi gravimetri gelas volumetrik,
 * metode `SIDIK-IK-CAL-0510`, Lab. Volumetrik.
 *
 * ## Dua mesin, enam pintu (keputusan pemilik proyek 21 Sep 2026)
 *
 * Lab memegang DUA workbook master — Fixed (satu tanda) dan Graduated
 * (berskala) — tapi lampiran akreditasi LK-285-IDN memuat ENAM alat dengan
 * tabel CMC masing-masing. Jadi dua kelas turunan membawa perbedaan metode
 * antar workbook, dan enam kelas konkret cuma menyumbang nama lampiran:
 *
 * | Keluarga | Alat (no. lampiran) |
 * |---|---|
 * | [FixedVolumetricGlasswareProfile] | Labu Ukur (18), Pipet Volume (20), Picnometer (21) |
 * | [GraduatedVolumetricGlasswareProfile] | Buret (13), Gelas Ukur (17), Pipet Ukur (19) |
 *
 * Picnometer di Fixed itu dugaan pengembang (bertanda satu, seperti labu ukur)
 * — pertanyaan lab no. 6.
 *
 * ## Yang diisi teknisi BUKAN volume
 *
 * Per titik tiga deret × tiga ulangan: berat wadah kosong (g), berat wadah +
 * air (g), dan suhu air suling (°C). V20 yang dicetak lahir di
 * [VolumetricGlasswareCalculator::hitungSesi] — sudah diadu ke kedua workbook
 * dari masukan mentahnya (`tests/Unit/VolumetricGlasswareSesiTest.php`).
 *
 * ## Correction tercetak = V20 − Nominal
 *
 * Kolom "Correction" sertifikat master berisi `N21 − E21` = Actual − Nominal,
 * padahal sheet budget melabelinya "Nominal − V20" (pertanyaan lab no. 11).
 * Yang TERCETAK yang ditiru. Validator menegakkan `koreksi = −error`, jadi
 * yang disimpan `error = V20 − Nominal`, `koreksi = Nominal − V20`, dan
 * [tandaKoreksiSertifikat] membalik tandanya saat cetak.
 *
 * ## Lantai CMC dari KAPASITAS alat
 *
 * `PERHITUNGAN_U95%!C27 = INPUT DATA!E15` — kapasitas maksimum, bukan nominal
 * tiap titik — lalu `MAX(U, CMC)` dicetak SATU angka di bawah tabel (Fixed
 * 0,003; Graduated 0,34 di contoh master). Pencarian barisnya nominal
 * TERDEKAT, seperti `INDEX/MATCH(MIN(ABS(...)))` master.
 */
abstract class VolumetricGlasswareProfile extends CalibrationProfile
{
    /**
     * Workbook & master metode lab menulis `Rev.7`; lampiran akreditasi masih
     * `Rev.6` (pertanyaan lab no. 3). Dipakai yang tertulis di workbook —
     * workbook itulah yang menghitung sertifikat lab hari ini.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0510_Rev.7';

    public const SATUAN = 'ml';

    public const PENGULANGAN = VolumetricGlasswareCalculator::PENGULANGAN;

    /**
     * Tiga standar yang TERCETAK di kedua kertas (`SIDIK-FM-CAL-0513/0514`).
     *
     * Neraca ketiga (Fujitsu di Fixed, Precisa di Graduated) dan kalibrator
     * Yokogawa TIDAK tercetak — padahal U95 Yokogawa masuk budget. Neraca
     * Precisa bahkan tidak punya catatan standar di database. Dicatat di
     * `docs/volumetric-sisa-pekerjaan.md`; lembar ini meniru kertasnya.
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Balance Excellent', 'cocok' => ['Electronic Balance Excellent', 'HSEX1403752']],
        ['label' => 'Balance Mettler Toledo', 'cocok' => ['Analytical Balance', '1129063525']],
        ['label' => 'RTD Sensor', 'cocok' => ['PRT Pt-100', 'SH1/20']],
    ];

    /** Dropdown "Thermohygro used" `INPUT DATA` kedua workbook (TH-1..TH-7). */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    private ?VolumetricGlasswareCalculator $kalk = null;

    /** `fixed` atau `graduated` — sumbu workbook. */
    abstract public function keluarga(): string;

    abstract protected function kodeDokumen(): string;

    abstract protected function judulLembar(): string;

    /**
     * Kotak isian khusus keluarga di blok identitas (resolusi, dst).
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function fieldKeluarga(string $kunci): array;

    /**
     * Penyimpangan yang DISENGAJA dari master, dengan angka master sebagai
     * pembanding — keputusan pemilik proyek 21 Sep (K3, K4).
     *
     * @param  array<string, mixed>  $h
     * @return list<array{kode: string, pesan: string, nilai: float|null}>
     */
    abstract protected function catatanAudit(array $h, float $uHitung): array;

    public function kodeFormula(): string
    {
        return 'gum-volumetric-'.$this->kode();
    }

    public function besaran(): string
    {
        return 'volume';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function dalamLingkupAkreditasi(): bool
    {
        return true;
    }

    /** `SERTIFIKAT!E15` kedua workbook. */
    public function judulKolomStandar(): string
    {
        return 'Nominal Value';
    }

    /** `SERTIFIKAT!N15` kedua workbook. */
    public function judulKolomUut(): string
    {
        return 'Actual Volume';
    }

    /** Nominal / Actual / Correction 4 desimal. */
    public function desimalSertifikat(): ?int
    {
        return 4;
    }

    /** `k` dicetak bulat; `U = k·uc` tetap memakai nilai penuh. */
    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /** Lihat docblock kelas — Correction tercetak = V20 − Nominal. */
    public function tandaKoreksiSertifikat(): int
    {
        return -1;
    }

    /**
     * Tidak ada PASS/FAIL. Tidak satu sel pun di kedua workbook membandingkan
     * hasil dengan batas keberterimaan; sertifikatnya berhenti di Correction +
     * U95%. Toleransi kelas tetap diisi — sebagai masukan diameter meniskus
     * (Fixed), bukan batas vonis.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Yang tercatat gram dan °C, bukan mL yang terbaca di skala alat —
     * mengadunya ke resolusi alat memunculkan peringatan palsu di tiap sesi.
     */
    public function pembacaanDiadukeResolusi(): bool
    {
        return false;
    }

    public function resolusiTitik(float $titikUkur): ?float
    {
        return null;
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /**
     * Tidak lewat pindai foto: tiga tabel yang harus sinkron per titik, dan
     * bentuk pindai cuma mengenal lembar "titik × Repeat" satu tabel.
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => false, 'standar_di_baris' => false, 'didukung' => false, 'lokal' => false];
    }

    /** Jalur simpan sendiri — lihat [CalibrationProfile::butuhBlokVolumetric]. */
    public function butuhBlokVolumetric(): bool
    {
        return true;
    }

    /** Budget lahir di [hitungPerGrup] — butuh konteks seluruh sesi. */
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

    /** @return array<string, mixed> */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => $this->kodeDokumen(),
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => $this->judulLembar(),
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Yang diisi BUKAN volume: tiap titik diisi tiga kali berat wadah kosong, '
                .'tiga kali berat wadah berisi air suling (gram), dan tiga kali suhu air (°C). Volume pada '
                .'20 °C dihitung server secara gravimetri. Tekanan udara (hPa) wajib walau tidak tercetak di '
                .'kertas: densitas udara dihitung dari situ.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master Olah Data Volumetric Glassware 2026 (Fixed & Graduated, .xlsm)',
                'catatan' => 'Delapan komponen dalam mL, k dari t-Student (v_eff dipotong ke bawah), lantai '
                    .'CMC dari lampiran akreditasi pada kapasitas alat, dan satu U95 untuk seluruh titik. '
                    .'Sesi dengan kelas selain A/B, neraca yang bukan milik lembarnya, atau kondisi '
                    .'lingkungan tak lengkap TIDAK diterbitkan.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianMeasurement(),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
    }

    /**
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}|null
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        // Disapu, bukan `$titik[0]`: jalur hitung ulang mengelompokkan lewat
        // `groupBy`, dan urutannya tidak dijamin — alasan yang sama dengan
        // `HydrometerProfile::hitungPerGrup()`.
        $konteksSesi = [];
        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = M::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Volumetric belum punya blok `spesifikasi_alat.'.M::KUNCI_SESI.'` — '
                        .'kelas, toleransi, kapasitas, dan neraca yang dipakai lahir di situ, bukan per titik.',
                ], $titik),
            ];
        }

        foreach (['suhu', 'kelembaban', 'tekanan'] as $besaran) {
            $blok["{$besaran}_awal"] = $konteksSesi["{$besaran}_awal"] ?? null;
            $blok["{$besaran}_akhir"] = $konteksSesi["{$besaran}_akhir"] ?? null;
        }

        $masukan = [];
        $belumDihitung = [];
        $standarPerTitik = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];
            $kosong = array_map('floatval', $k[M::KONTEKS_KOSONG] ?? []);
            $isi = array_map('floatval', $k[M::KONTEKS_ISI] ?? []);
            $suhu = array_map('floatval', $k[M::KONTEKS_SUHU] ?? []);

            // Deret datar tanpa peran DITOLAK, bukan ditebak: berat kosong,
            // berat isi, dan suhu tidak bisa dipisahkan lagi begitu perannya
            // hilang, dan V20 dari deret tertukar tetap terbit — cuma salah.
            if ($kosong === [] && $isi === [] && $suhu === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s`/`%s`. Lembar Volumetric menyimpan '
                        .'tiga deret terpisah — deret datar nggak bisa dipakai.',
                        $t['titik_ke'], M::PERAN_KOSONG, M::PERAN_ISI, M::PERAN_SUHU,
                    ),
                ];

                continue;
            }

            $standarPerTitik[(int) $t['titik_ke']] = $t['standard'] ?? null;
            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'nominal' => (float) $t['titik_ukur'],
                'kosong' => $kosong,
                'isi' => $isi,
                'suhu' => $suhu,
            ];
        }

        $hasil = $this->kalk()->hitungSesi($this->keluarga(), $masukan, $blok);

        foreach ($hasil['ditolak'] as $d) {
            $belumDihitung[] = $d;
        }

        $kapasitas = $this->kapasitasUntukCmc($blok, $masukan);

        if ($hasil['boleh_terbit'] && $kapasitas === null) {
            foreach ($hasil['titik'] as $h) {
                $belumDihitung[] = ['titik_ke' => $h['titik_ke'], 'alasan' => 'Kapasitas alat (mL) belum diisi. '
                    .'Lantai CMC diambil dari kapasitas, bukan dari nominal titik.'];
            }
            $hasil['titik'] = [];
        }

        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        // Budget yang tidak utuh tidak melahirkan satu pun baris hitungan —
        // yang menahan sertifikatnya harus ketiadaan barisnya, bukan peringatan
        // yang boleh dilewati admin.
        if (! $hasil['boleh_terbit'] || $hasil['titik'] === []) {
            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        $cmc = $this->cmcTerdekat($this->pitaKemampuan($equipment), (float) $kapasitas);
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($hasil['titik'] as $h) {
            $agregat = $h['agregat'];
            $uHitung = (float) $agregat['ketidakpastian_diperluas'];
            $u95 = max($uHitung, $cmc['cmc'] ?? 0.0);
            $typeA = (float) $h['masukan_budget']['u_keterulangan'];
            $uc = (float) $agregat['ketidakpastian_gabungan'];

            $hitungan[] = [
                'standard_id' => ($standarPerTitik[$h['titik_ke']] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['nominal'],
                'rata_rata' => $h['v20'],
                'error' => $h['deviasi'],
                'koreksi' => -$h['deviasi'],
                'standar_deviasi' => $h['stdev_v20'],
                'jumlah_pengulangan' => self::PENGULANGAN,
                'type_a' => $typeA,
                'type_b_components' => $this->jejakAudit($h, $hasil['praolah'], $uHitung, $u95, $cmc, (float) $kapasitas),
                'type_b' => sqrt(max(0.0, $uc ** 2 - $typeA ** 2)),
                'ketidakpastian_gabungan' => $uc,
                'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $u95,
                'toleransi' => null,
                'keputusan' => null,
                'metode' => self::KODE_METODE,
                'calculated_at' => $sekarang,
            ];
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Blok sesi yang belum lengkap tidak boleh lewat diam-diam. Yang MENAHAN
     * sertifikatnya [hitungPerGrup] (nol baris hitungan); pesan ini
     * menjelaskan kenapa sesinya kosong.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = M::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [[
                'kode' => 'volumetric_blok_sesi_kosong',
                'pesan' => 'Kelas, toleransi, kapasitas, dan neraca belum diisi — sesi ini tidak bisa '
                    .'menerbitkan volume.',
            ]];
        }

        $peringatan = [];

        if ($sesi->tekanan_awal === null || $sesi->tekanan_akhir === null) {
            $peringatan[] = [
                'kode' => 'volumetric_tekanan_kosong',
                'pesan' => 'Tekanan udara ruangan (hPa) belum lengkap. Densitas udara dihitung dari situ, '
                    .'jadi tanpa tekanan tidak ada volume yang bisa diterbitkan.',
            ];
        }

        if (VolumetricGlasswareCalculator::gammaDariKelas($blok['kelas']) === null) {
            $peringatan[] = [
                'kode' => 'volumetric_kelas_tidak_dikenal',
                'pesan' => sprintf('Kelas alat "%s" bukan A atau B.', (string) $blok['kelas']),
            ];
        }

        return $peringatan;
    }

    /**
     * Kapasitas untuk lantai CMC. Bawaan: wajib diisi di blok sesi.
     *
     * @param  array<string, mixed>  $blok
     * @param  list<array{nominal: float}>  $masukan
     */
    protected function kapasitasUntukCmc(array $blok, array $masukan): ?float
    {
        return $blok['kapasitas_ml'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $h
     * @param  array<string, mixed>  $praolah
     * @param  array{cmc: float, nominal: float}|null  $cmc
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $h, array $praolah, float $uHitung, float $u95, ?array $cmc, float $kapasitas): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit([
            'sumber' => 'volumetric_'.strtolower(str_replace(' ', '_', $k['nama'])),
            'keterangan' => sprintf('%s — U %.12g ÷ %.12g', $k['nama'], $k['u_diperluas'], $k['pembagi']),
            'distribusi' => match (true) {
                $k['pembagi'] == 1.0 => 'normal (tipe A)',
                $k['pembagi'] == 2.0 => 'normal',
                default => 'rectangular',
            },
            'u' => $k['u'],
            'ci' => $k['ci'],
            'vi' => $k['vi'],
        ]), $h['komponen_budget']);

        // WAJIB — `CalibrationValidator::cmcTitik()` mencarinya untuk gerbang
        // `u95_meledak_dari_cmc`.
        $budget[] = $this->barisPerbandinganCmc($uHitung, $cmc['cmc'] ?? null, self::SATUAN);

        foreach ($this->catatanAudit($h, $uHitung) as $c) {
            $budget[] = [
                'sumber' => $c['kode'],
                'keterangan' => $c['pesan'],
                'distribusi' => '-',
                'nilai' => $c['nilai'],
                'u_baku' => 0.0,
                'ci' => 0.0,
                'kontribusi' => 0.0,
                'satuan' => self::SATUAN,
            ];
        }

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'ρ udara %.12g g/mL (T %.12g °C · RH %.12g %% · P %.12g hPa) · kelas %s γ %.12g /°C · '
                .'neraca %s (u timbang %.12g g) · massa per ulangan %s g · suhu terkoreksi %s °C · '
                .'ρ air per ulangan %s g/mL · V20 per ulangan %s mL · lantai CMC %s pada kapasitas %.12g mL · '
                .'U95%% terbit %.12g mL',
                $praolah['rho_udara'], $praolah['suhu_ruang'], $praolah['kelembaban'], $praolah['tekanan'],
                $praolah['kelas'], $praolah['gamma'], $praolah['neraca']['nama'], $praolah['u_timbang'],
                self::deret($h['massa_per_ulangan']), self::deret($h['suhu_terkoreksi_per_ulangan']),
                self::deret($h['rho_air_per_ulangan']), self::deret($h['v20_per_ulangan']),
                $cmc === null ? 'tidak ada (di luar lampiran)' : sprintf('%.12g (baris %.12g mL)', $cmc['cmc'], $cmc['nominal']),
                $kapasitas, $u95,
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'kontribusi' => 0.0,
            'satuan' => self::SATUAN,
        ];

        return $budget;
    }

    /**
     * Semua baris CMC alat ini dari lampiran akreditasi (`calibration_capabilities`).
     *
     * @return Collection<int, CalibrationCapability>
     */
    private function pitaKemampuan(Equipment $equipment): Collection
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment->equipment_category_id !== null,
                fn ($q) => $q->where('equipment_category_id', $equipment->equipment_category_id),
            )
            ->when(
                $equipment->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
            )
            ->orderBy('range_max')
            ->get();
    }

    /**
     * Baris CMC dengan nominal (`range_max`) TERDEKAT ke kapasitas; seri →
     * yang pertama (terkecil). Meniru `INDEX/MATCH(MIN(ABS(...)))` master dan
     * [TabelStandarVolumetric::cmc].
     *
     * @param  Collection<int, CalibrationCapability>  $pita
     * @return array{cmc: float, nominal: float}|null
     */
    private function cmcTerdekat(Collection $pita, float $kapasitas): ?array
    {
        $terpilih = null;
        $jarakTerkecil = INF;

        foreach ($pita as $p) {
            if ($p->range_max === null || $p->ketidakpastian_terbaik === null) {
                continue;
            }

            $jarak = abs((float) $p->range_max - $kapasitas);
            if ($jarak < $jarakTerkecil) {
                $jarakTerkecil = $jarak;
                $terpilih = ['cmc' => (float) $p->ketidakpastian_terbaik, 'nominal' => (float) $p->range_max];
            }
        }

        return $terpilih;
    }

    /** @param  list<float>  $x */
    private static function deret(array $x): string
    {
        return implode(' · ', array_map(static fn (float $v): string => sprintf('%.12g', $v), $x));
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(): array
    {
        $kunci = 'spesifikasi_alat.'.M::KUNCI_SESI;
        $neraca = array_map(
            static fn (array $n): array => ['nilai' => $n['nama'], 'label' => $n['nama'].' ('.$n['merk_type'].')'],
            (new TabelStandarVolumetric)->semuaNeraca($this->keluarga()),
        );

        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Equipment',
            'field' => [
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Name', 'teks', sumber: 'otomatis'),
                $this->field("{$kunci}.kapasitas_ml", 'Capacity', 'angka', satuan: self::SATUAN),
                ...$this->fieldKeluarga($kunci),
                // Dropdown, bukan teks bebas: kelas selain A/B tidak punya γ,
                // dan γ yang salah menggeser seluruh V20 tanpa error.
                $this->field("{$kunci}.kelas", 'Class', 'pilihan', pilihan: [
                    ['nilai' => 'A', 'label' => 'Class A'],
                    ['nilai' => 'B', 'label' => 'Class B'],
                ]),
                $this->field("{$kunci}.toleransi_ml", 'Tolerance (±)', 'angka', satuan: self::SATUAN),
                // Neraca PER KELUARGA — neraca ketiga beda fisik (Fujitsu di
                // Fixed, Precisa di Graduated).
                $this->field("{$kunci}.neraca", 'Balance Used', 'pilihan', pilihan: $neraca),
                $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                $this->field('alat_merk', 'Manufacture', 'teks'),
                $this->field('alat_model', 'Model/Type', 'teks'),
                $this->field('alat_serial_number', 'Serial Number', 'teks'),
                $this->field('suhu_awal', 'Env. Condition — First (°C)', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Env. Condition — End (°C)', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Env. Condition — First (%RH)', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Env. Condition — End (%RH)', 'angka', satuan: '%RH'),
                // Tidak tercetak di kertas Rev.4, tapi densitas udara butuh.
                $this->field('tekanan_awal', 'Tekanan Udara — awal', 'angka', satuan: 'hPa'),
                $this->field('tekanan_akhir', 'Tekanan Udara — akhir', 'angka', satuan: 'hPa'),
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
                $this->field('thermohygro_standard_id', 'Thermohygro Used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Owner',
            'field' => [
                $this->field('pemilik_nama', 'Name', 'teks'),
                $this->field('pemilik_alamat', 'Address', 'teks_panjang'),
                $this->field('nomor_order', 'Order Number', 'teks'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianStandard(): array
    {
        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'Standard',
            'baris' => self::STANDARD_TERCETAK,
            'field' => [
                $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
            ],
        ];
    }

    /**
     * Tiga tabel yang barisnya SINKRON: kolom ke-n ketiganya merujuk titik
     * yang sama. Pola persis `HydrometerProfile::bagianMeasurement()` —
     * `offset_kunci` beda per tabel supaya HP tidak berbagi kotak isian, dan
     * `simpan_ke` bernama supaya ketiga deret sampai server terpisah.
     *
     * @return array<string, mixed>
     */
    private function bagianMeasurement(): array
    {
        $jumlahTitik = VolumetricGlasswareCalculator::TITIK_MAKS[$this->keluarga()];
        $baris = static fn (string $satuan, int $desimal): array => array_map(
            static fn (int $n): array => [
                'nomor' => $n,
                // `null` membuka kotak Nominal untuk diketik — kertasnya
                // membiarkan kolom itu kosong. Baris tanpa nominal ditahan HP.
                'titik_ukur' => null,
                'label' => 'Titik '.$n,
                'satuan' => $satuan,
                'desimal' => $desimal,
            ],
            range(1, $jumlahTitik),
        );

        $tabel = static fn (string $grup, int $offset, string $judul, string $satuan, string $label, string $ulang, int $desimal): array => [
            'tahap' => 'sesudah_adjustment',
            'grup' => $grup,
            'offset_kunci' => $offset,
            'judul' => $judul,
            'satuan' => $satuan,
            'judul_nilai' => 'Nominal (mL)',
            'judul_pengulangan' => $ulang,
            'titik_bisa_diubah' => false,
            'simpan_ke' => 'measurements[].'.$grup,
            'baris' => $baris($satuan, $desimal),
            'kolom' => [['kode' => 'pembacaan', 'label' => $label, 'tipe' => 'angka', 'satuan' => $satuan]],
            'pengulangan' => range(1, self::PENGULANGAN),
        ];

        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Measurement',
            'field' => [],
            'tabel' => [
                $tabel(M::PERAN_KOSONG, 1000, 'a. Empty Container Weight (g)', M::SATUAN_MASSA, 'Berat Kosong', 'Timbang ke', 4),
                $tabel(M::PERAN_ISI, 2000, 'b. Weight of Contents (g)', M::SATUAN_MASSA, 'Berat Isi', 'Timbang ke', 4),
                $tabel(M::PERAN_SUHU, 3000, 'c. Temperature of Destillate Water (°C)', M::SATUAN_SUHU, 'Suhu', 'Baca ke', 1),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 1,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Note', 'teks_panjang'),
                $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    private function kalk(): VolumetricGlasswareCalculator
    {
        return $this->kalk ??= new VolumetricGlasswareCalculator;
    }
}
