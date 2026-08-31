<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\TabelStandarTimbangan;
use App\Services\Calibration\TimbanganCalculator;
use App\Services\Calibration\VarianMasterTimbangan;
use Carbon\Carbon;

/**
 * Profil kalibrasi **Timbangan** (alat ke-21) — lampiran akreditasi
 * LK-285-IDN **no. 12, kelompok Massa**, satu-satunya baris di kelompok itu:
 * *"Timbangan (Elektronik, mekanik)"*.
 *
 * Mesin hitungnya di [TimbanganCalculator]; tabel anak timbangan & CMC di
 * [TabelStandarTimbangan]; beda antar-revisi master di
 * [VarianMasterTimbangan]. Yang tinggal di sini bentuk lembar kerjanya dan
 * penjagaan yang cuma bisa dijawab di tingkat sesi.
 *
 * ## Satu profil, bukan tiga
 *
 * Lab mengirim TIGA workbook (kg, gram, substitusi) dan godaannya besar buat
 * membuat tiga profil. Yang membantahnya lampiran akreditasi: satu baris, satu
 * nama alat, satu rentang CMC bertingkat. `CalibrationProfileRegistry` sendiri
 * melempar `LogicException` kalau dua profil mengaku ejaan nama yang sama —
 * jadi "Timbangan kg" dan "Timbangan gram" tidak akan pernah bisa hidup
 * berdampingan tanpa mengarang nama alat yang tidak ada di lampiran.
 *
 * Pola yang sama sudah dipakai TITS (dua workbook: Measure & Source),
 * Enclosure (dua workbook: Recorder & Constant), dan TIDS (dua keluarga
 * standar). Yang membedakan ketiga workbook Timbangan bukan alatnya, tapi
 * **metode pembebanan** dan **revisi berkasnya** — dua-duanya properti SESI.
 *
 * ## Bentuk lembarnya BEDA dari 20 alat sebelumnya
 *
 * Dua puluh alat sebelumnya punya satu tabel pengukuran: titik ukur turun,
 * pengulangan ke kanan. Timbangan punya TUJUH blok yang tidak sebentuk, dan
 * cuma satu (Accuracy) yang jadi baris titik di sertifikat. Empat blok lain
 * (Repeatability, Loading Influence, Hysterisis, Scale Observation) menyumbang
 * ke budget atau ke pernyataan terpisah.
 *
 * Konsekuensinya: [butuhGridSensor] & [butuhPasanganStandarUut] dua-duanya
 * `false` — jalur datar `measurements[i].pembacaan` juga tidak cukup — dan
 * seluruh sesi dihitung sekali lewat [hitungPerGrup]. Sr, Sres, dan rentang
 * eksentrisitas besaran SESI; tiap titik memakai angka yang sama.
 *
 * ## Dua U95 per titik, dan yang dicetak DUA-DUANYA
 *
 * NMI Monograph 4 memisahkan *Uncertainty of Correction* (koreksi kalibrasi)
 * dari *Uncertainty of Weighing* (seluruh proses penimbangan). Sertifikat
 * master mencetak keduanya di bagian yang berbeda — bagian 3 memakai yang
 * pertama, bagian 7 yang kedua. Yang disimpan `UncertaintyCalculation` sebagai
 * `ketidakpastian_diperluas` yang **pertama**, karena itu yang menempel di
 * kolom `Correction` tiap titik; yang kedua ikut di `rincian`.
 */
class TimbanganProfile extends CalibrationProfile
{
    /**
     * Dibuat MALAS, dan itu wajib — bukan gaya.
     *
     * `TimbanganCalculator` menyusun `GumCalculator`, yang konstruktornya
     * membangun `CalibrationProfileRegistry`, yang membangun profil ini lagi.
     * Menaruh `new TimbanganCalculator` di parameter bawaan konstruktor
     * menutup lingkarannya, dan yang terjadi bukan error yang kebaca —
     * PHP mentok di batas tumpukan panggilan ("Infinite recursion?") jauh dari
     * baris yang menyebabkannya.
     */
    private ?TimbanganCalculator $kalkulator = null;

    private function kalkulator(): TimbanganCalculator
    {
        return $this->kalkulator ??= new TimbanganCalculator;
    }

    public function kode(): string
    {
        return 'timbangan';
    }

    /**
     * Ejaan PERSIS baris lampiran akreditasi no. 12 — termasuk kurungnya.
     *
     * Bukan "Timbangan" yang lebih enak dibaca: `CmcSemuaProfilTest`
     * mencocokkan `calibration_capabilities.nama_alat` dengan nilai method ini
     * apa adanya, dan baris CMC-nya di-seed dengan nama lengkap. Meleset satu
     * huruf berarti profil ini tidak punya satu pun baris CMC — dan akibatnya
     * BUKAN error: `kemampuanUntukTitik()` pulang null, hitungannya jatuh ke
     * jalur generik, dan sertifikatnya terbit dengan U95 lebih KECIL daripada
     * yang diakreditasi.
     *
     * Nama pendek "Timbangan" tetap kepakai lewat [aliasNama] — yang dipakai
     * teknisi memilih alat memang nama pendek.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Timbangan (Elektronik, mekanik)';
    }

    /**
     * Nama yang datang dari pelanggan hampir tidak pernah cuma "Timbangan",
     * dan tidak pernah menyertakan kurung lampiran akreditasi — jadi ejaan
     * pendeknya WAJIB ada di sini, kalau tidak tiap alat pelanggan jatuh ke
     * form generik.
     *
     * `Neraca` & `Balance` masuk karena itu nama yang sama dalam dua bahasa.
     * `Moisture Analyzer` masuk karena SESI CONTOH master gram memang alat itu
     * (`019-CAL-425`, Mettler Toledo HB53): alat pengering yang menimbang, dan
     * yang dikalibrasi lab memang bagian timbangannya.
     *
     * Yang SENGAJA tidak dimasukkan: `Timbangan Analitik` tidak perlu ditulis
     * (kunci "timbangan" sudah nempel di tengahnya), dan `Load Cell` tidak
     * masuk — itu komponen sensor, bukan timbangan utuh, dan lampiran
     * akreditasi tidak memuatnya. Kesalahan yang sama pernah nyaris terjadi
     * waktu `Hydrometer` didaftarkan sebagai alias Thermohygro; lihat §11.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Timbangan', 'Neraca', 'Balance', 'Moisture Analyzer'];
    }

    public function kodeFormula(): string
    {
        return 'gum-timbangan';
    }

    public function besaran(): string
    {
        return 'massa';
    }

    /**
     * U95 memang beda per titik — tiap titik punya kombinasi anak timbangan
     * sendiri, jadi komponen "Weight Standard" dan enam baris drift-nya beda.
     */
    public function u95PerTitik(): bool
    {
        return true;
    }

    public function judulKolomUut(): string
    {
        return 'The Reading';
    }

    /**
     * Toleransi Timbangan tidak datang dari kolom `equipments.toleransi`,
     * melainkan dari **MPE kelas** (SNSU PK.M-02:2021) yang diturunkan dari
     * nilai `e` dan `n = kapasitas / e`. Selama kelasnya belum diisi teknisi,
     * tidak ada vonis lulus/tidak — dan menebak dari kolom alat berarti
     * mengarang batas untuk sertifikat yang ikut diaudit.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Pemeriksa `pembacaan_bukan_kelipatan_resolusi` DIMATIKAN.
     *
     * Blok Accuracy mencatat pembacaan UUT (kelipatan resolusi UUT, sah
     * diadu), TAPI blok yang sama juga mencatat nilai NOMINAL anak timbangan —
     * 10 kg, 20 kg — yang sama sekali bukan kelipatan resolusi UUT dan memang
     * tidak seharusnya. Diadu ke `equipments.resolusi`, tiap baris nominal jadi
     * tuduhan salah ketik atas angka yang disalin apa adanya dari master.
     *
     * Kelas kesalahan yang sama sudah kejadian tiga kali — TITS (25 tuduhan
     * palsu per sesi), baris Suhu Ruang Enclosure (20 per sesi), dan ketiga
     * alat suhu berpasangan. Penggaris yang salah tidak menghasilkan error,
     * dia menghasilkan kebenaran yang dibalik.
     */
    public function pembacaanDiadukeResolusi(): bool
    {
        return false;
    }

    /**
     * Lembar ini BELUM punya jalur kamera.
     *
     * Tujuh blok yang tidak sebentuk tidak bisa diungkapkan lewat
     * `kolom_suhu` / `standar_di_baris` yang cuma memodelkan satu tabel datar.
     * Dibiarkan `didukung: true`, prompt & skema JSON yang dikirim ke pembaca
     * foto dibangun untuk tabel yang tidak ada di kertasnya — dan yang balik
     * bukan error, tapi angka yang dikarang supaya kolomnya kelihatan terisi.
     * Alasan yang sama persis dipakai lembar Autoklaf & grid Enclosure.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung: bool}
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => false, 'standar_di_baris' => false, 'didukung' => false];
    }

    /** Tujuh blok, bukan tabel titik — lihat docblock kelas & bawaannya. */
    public function butuhBlokTimbangan(): bool
    {
        return true;
    }

    /**
     * Budget per titik TIDAK dipakai — seluruh sesi lewat [hitungPerGrup].
     *
     * Sr, Sres, dan rentang eksentrisitas besaran SESI, dan
     * `GumCalculator::hitungTitik()` cuma melihat satu titik. Balik `null`
     * supaya pemanggil tahu profil ini tidak menjawab lewat jalur itu, bukan
     * menyusun budget setengah jadi yang kelihatan sah.
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
     * Hitung SELURUH sesi sekaligus.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard|null, konteks?: array<string, mixed>}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $titik = array_values($titik);
        $konteks = $titik[0]['konteks'] ?? [];

        $sesi = $this->susunMasukan($titik, $equipment, $konteks);

        if (is_string($sesi)) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    static fn (array $t): array => ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $sesi],
                    $titik,
                ),
            ];
        }

        $hasil = $this->kalkulator()->hitung($sesi);
        $sekarang = Carbon::now();
        $hitungan = [];

        foreach ($hasil['titik'] as $t) {
            $hitungan[] = [
                'standard_id' => $titik[0]['standard_id'] ?? null,
                'titik_ke' => $t['titik_ke'],
                // Massa konvensional total anak timbangan titik ini — angka
                // yang dicetak sertifikat sebagai `Nominal Standard`. Nominal
                // mentahnya tetap hidup di `raw_measurements`.
                'titik_ukur' => $t['titik_ukur'],
                'rata_rata' => $t['rata_beban'],
                // `Correction = ΣCN − (m̄ − z̄)`, jadi error-nya kebalikannya.
                'error' => -$t['koreksi'],
                'koreksi' => $t['koreksi'],
                // `Sr` = STDEV(m, m') satu siklus — dua pembacaan, bukan
                // sepuluh. Keterulangan sepuluh pengulangan hidup di bloknya
                // sendiri dan masuk budget lewat komponen `repeatability`.
                'standar_deviasi' => $t['sr'],
                'jumlah_pengulangan' => 2,
                // NOL, dan bukan kolom yang lupa diisi: seluruh keterulangan
                // Timbangan masuk sebagai komponen Type B ber-distribusi
                // t-student di budget koreksi, persis seperti masternya. Tidak
                // ada Type A terpisah di lembar ini.
                'type_a' => 0.0,
                'type_b_components' => $this->jejakAudit($t, $hasil),
                'type_b' => $t['uc_koreksi'],
                'ketidakpastian_gabungan' => $t['uc_koreksi'],
                'faktor_cakupan_k' => $t['k_koreksi'],
                'derajat_kebebasan_efektif' => $t['veff_koreksi'],
                // Yang disimpan U95 of CORRECTION — itu yang menempel di kolom
                // `Correction` tiap titik. U95 of Weighing ikut di
                // `type_b_components` supaya dua-duanya bisa diaudit.
                'ketidakpastian_diperluas' => $t['u95_koreksi'],
                'metode' => 'NMI Monograph 4 (CSIRO 2010)',
                // Nggak divonis — batas keberterimaan Timbangan datang dari MPE
                // kelas, dan kelasnya baru ada kalau teknisi mengisi `e`.
                'toleransi' => null,
                'keputusan' => null,
                'calculated_at' => $sekarang,
            ];
        }

        return ['hitungan' => $hitungan, 'belum_dihitung' => $hasil['belum_dihitung']];
    }

    /**
     * Baris `type_b_components`: KEDUA budget berikut konteks sesinya.
     *
     * Dua budget dalam satu kolom, dan itu disengaja. `uncertainty_calculations`
     * cuma punya satu kolom JSON, sementara NMI Monograph 4 menerbitkan DUA
     * ketidakpastian per titik dan sertifikatnya mencetak dua-duanya (bagian 3
     * & bagian 7). Menyimpan yang satu saja berarti separuh angka yang
     * tercetak tidak punya jejak audit sama sekali.
     *
     * Tiap baris bawa `budget` (`koreksi` / `penimbangan`) supaya pembacanya
     * tidak perlu menebak dari nama komponen — dan nama komponen memang
     * bertabrakan: "Repeatability" ada di budget koreksi, "Resolution" di
     * budget penimbangan, dan dua-duanya sah.
     *
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $t, array $hasil): array
    {
        $baris = [];

        foreach (['koreksi' => 'budget_koreksi', 'penimbangan' => 'budget_penimbangan'] as $nama => $kunci) {
            foreach ($t[$kunci] as $k) {
                $baris[] = [
                    'budget' => $nama,
                    'sumber' => $k['sumber'],
                    'keterangan' => $k['keterangan'],
                    'distribusi' => $k['distribusi'],
                    'nilai' => $k['u'],
                    'ci' => $k['ci'],
                    'vi' => $k['vi'],
                ];
            }
        }

        $baris[] = [
            'budget' => '-',
            'sumber' => 'u95_penimbangan',
            'keterangan' => sprintf(
                'U95%% of Weighing titik ini %s %s (hitungan %s, k = %s, v_eff = %s). '
                .'Dicetak di bagian 7 sertifikat; yang di kolom Correction U95%% of Correction.',
                $this->angka($t['u95_penimbangan']),
                $hasil['satuan'],
                $this->angka($t['u95_penimbangan_hitung']),
                $this->angka($t['k_penimbangan']),
                $this->angka((float) $t['veff_penimbangan']),
            ),
            'distribusi' => '-',
            'nilai' => $t['u95_penimbangan'],
        ];

        $baris[] = [
            'budget' => '-',
            'sumber' => 'varian_master',
            'keterangan' => sprintf(
                'Revisi master yang dipakai: %s. Ketiga workbook Timbangan berbeda rumus DAN berbeda '
                .'snapshot sertifikat anak timbangan — lihat docs/pertanyaan-lab-timbangan.md T1 & T2.',
                $hasil['varian'],
            ),
            'distribusi' => '-',
            'nilai' => null,
        ];

        if ($t['dibatasi_cmc'] && $hasil['cmc'] !== null) {
            $baris[] = [
                'budget' => '-',
                'sumber' => 'lantai_cmc',
                'keterangan' => sprintf(
                    'U95 hitung %s di bawah CMC terakreditasi %s (pita %s, %s kg) — yang diterbitkan '
                    .'CMC (ILAC-P14).',
                    $this->angka($t['u95_koreksi_hitung']),
                    $this->angka((float) $hasil['cmc']['cmc_satuan']),
                    $hasil['cmc']['kode'],
                    $hasil['cmc']['rentang'],
                ),
                'distribusi' => '-',
                'nilai' => $hasil['cmc']['cmc_satuan'],
            ];
        }

        return $baris;
    }

    /**
     * Susun masukan kalkulator dari baris mentah sesi, atau balik STRING
     * alasan kalau sesinya belum bisa dihitung.
     *
     * @param  list<array<string, mixed>>  $titik
     * @param  array<string, mixed>  $konteks
     * @return array<string, mixed>|string
     */
    private function susunMasukan(array $titik, Equipment $equipment, array $konteks): array|string
    {
        // Blok tingkat-SESI (keterulangan, eksentrisitas, histeresis, metode
        // pembebanan) hidup di `calibration_sessions.spesifikasi_alat`, bukan
        // di `raw_measurements` — kelimanya satu per sesi, bukan per titik,
        // jadi nggak punya `titik_ke`. Dua jalur hitung ulang sudah
        // meneruskannya apa adanya; lihat App\Support\TimbanganMentah.
        $spek = (array) ($konteks['spesifikasi_alat'] ?? []);
        $ambil = static fn (string $kunci, mixed $bawaan = null): mixed => $konteks[$kunci]
            ?? $spek[$kunci]
            ?? $bawaan;

        $kapasitas = (float) ($ambil('kapasitas') ?? $equipment->range_max ?? 0.0);
        $resolusi = (float) ($ambil('resolusi') ?? $equipment->resolusi ?? 0.0);

        if ($resolusi <= 0.0) {
            return 'Resolusi alat belum diisi. Seluruh budget Timbangan bertumpu padanya — '
                .'komponen Resolution, lantai Sres (0,82 × a), dan Rounding of Final Result '
                .'semuanya diturunkan dari angka itu.';
        }

        if ($kapasitas <= 0.0) {
            return 'Kapasitas alat belum diisi — pita CMC dipilih dari kapasitas, dan tanpa pita '
                .'itu U95 sertifikat nggak punya lantai.';
        }

        $satuan = (string) ($ambil('satuan') ?? $equipment->satuan ?? 'kg');
        $varian = (string) ($ambil('varian_master') ?? VarianMasterTimbangan::bawaanUntuk(
            strtolower(trim($satuan)) === 'g' ? $kapasitas / 1000.0 : $kapasitas,
            $satuan,
        )->kode);

        $akurasi = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];
            $akurasi[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'nominal' => array_map('floatval', $k['nominal'] ?? []),
                'baca' => [
                    'z1' => $k['z1'] ?? null,
                    'm' => $k['m'] ?? null,
                    'm_aksen' => $k['m_aksen'] ?? null,
                    'z2' => $k['z2'] ?? null,
                ],
            ];
        }

        return [
            'varian' => $varian,
            'resolusi' => $resolusi,
            'kapasitas' => $kapasitas,
            'satuan' => $satuan,
            'tipe_display' => (string) ($ambil('tipe_display', 'Digital')),
            'tipe_timbangan' => (string) ($ambil('tipe_timbangan', TabelStandarTimbangan::NON_ANALYTICAL)),
            'akurasi' => $akurasi,
            'keterulangan' => (array) $ambil('keterulangan', []),
            'eksentrisitas' => (array) $ambil('eksentrisitas', []),
            'histeresis' => (array) $ambil('histeresis', []),
        ];
    }

    /**
     * Peringatan tingkat sesi.
     *
     * Lampiran akreditasi no. 12 memuat ketujuh belas pita CMC sampai 2000 kg,
     * jadi kapasitas di bawah itu tidak perlu ditandai apa-apa. Yang ditandai
     * kapasitas **di atas** pita terakhir: sesinya tetap boleh dihitung, tapi
     * U95-nya kehilangan lantai CMC dan terbit dengan angka hitungan murni —
     * rapi, bernomor sertifikat, dan tanpa satu pun error yang memberi tahu
     * pembacanya. Bahaya yang bentuknya sama dengan §1 daftar permintaan.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];
        $alat = $sesi->equipment;
        // Kapasitas dibaca dari lembar dulu (`spesifikasi_alat`), baru jatuh ke
        // `range_max` alat. Tabel `equipments` nggak punya kolom kapasitas.
        $kapasitas = $sesi->spesifikasi_alat['kapasitas'] ?? $alat?->range_max;
        $kapasitas = $kapasitas === null ? null : (float) $kapasitas;
        $satuan = (string) ($alat?->satuan ?? 'kg');

        if ($kapasitas !== null && $kapasitas > 0.0) {
            $kapasitasKg = strtolower(trim($satuan)) === 'g' ? $kapasitas / 1000.0 : $kapasitas;
            $pita = TabelStandarTimbangan::pitaCmc($kapasitasKg);

            if ($pita === null) {
                $peringatan[] = [
                    'kode' => 'cmc_di_luar_tabel',
                    'pesan' => sprintf(
                        'Kapasitas %s kg di atas pita CMC terakhir lampiran akreditasi LK-285-IDN '
                        .'no. 12 (berhenti di 2000 kg). Sesi ini boleh dihitung dan disimpan, tapi '
                        .'U95-nya nggak punya lantai CMC — angkanya hitungan murni, dan nggak boleh '
                        .'terbit sebagai hasil terakreditasi tanpa keputusan manajer teknis.',
                        $this->angka($kapasitasKg),
                    ),
                ];
            }
        }

        return $peringatan;
    }

    /**
     * Bentuk lembar kerja. Tujuh blok pengukuran, urutannya persis kertas
     * master (`INPUT DATA`), dengan blok identitas & standar di depan sesuai
     * urutan seragam yang dijaga `SemuaProfilLembarKerjaTest`.
     *
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'profil' => $this->kode(),
            'judul' => 'KALIBRASI MASSA / TIMBANGAN',
            // Ketiga workbook cuma memuat `SIDIK-FM-CAL-2403_Rev. 0` di footer
            // sheet SERTIFIKAT — itu formulir SERTIFIKAT yang dipakai bersama
            // semua alat, bukan nomor lembar kerjanya. Alasan yang sama persis
            // dipakai TITS & ketiga alat suhu; lihat
            // SemuaProfilLembarKerjaTest::$belumAdaKertasnya.
            'kode_dokumen' => null,
            'metode' => 'NMI Monograph 4 (CSIRO 2010)',
            'alat_baru' => [
                'kode_kategori' => 'massa',
                'nama_alat_kemampuan' => $this->namaAlatKemampuan(),
            ],
            'bagian' => [
                ...$this->bagianIdentitas(),
                $this->bagianStandar(),
                $this->bagianScaleObservation(),
                $this->bagianEffectOfTare(),
                $this->bagianAkurasi($equipment),
                $this->bagianKeterulangan($equipment),
                $this->bagianEksentrisitas(),
                $this->bagianHisteresis(),
                $this->bagianPenutup(),
            ],
        ];

        if ($untukAdmin) {
            $bentuk['bagian'][] = $this->bagianAdmin();
        }

        return $this->tautkanStandarTitik(
            $this->tautkanAnakTimbangan(
                $this->isiPilihanThermohygro($bentuk, $equipment),
                $equipment,
            ),
            $equipment,
        );
    }

    /**
     * Isi dropdown "Thermohygro Used" dari master `standards` — TERSARING
     * ORGANISASI lewat [masterThermohygro], bukan query telanjang.
     *
     * Query sendiri di sini menawarkan termohigrometer milik lab LAIN, dan yang
     * kepilih tidak berhenti di dropdown: `standard_id`-nya masuk ke sesi,
     * koreksi kondisi lingkungannya dibaca dari sertifikat lab itu, lalu
     * angkanya kecetak di sertifikat lab INI. Itu temuan audit paling mahal
     * jenisnya, dan `StandarTidakBocorAntarLabTest` menyapunya ke semua profil.
     *
     * Daftarnya diambil dari MASTER, bukan dari daftar nama tercetak: begitu
     * lab menambah TH-8, unit itu muncul di sini tanpa berkas ini disentuh.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermohygro(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = $this->masterThermohygro($equipment)->pluck('id', 'nama');

        $pilihan = [];

        foreach (self::THERMOHYGRO_TERCETAK as $label) {
            $id = $master[$label] ?? null;

            if ($id === null) {
                continue;
            }

            $pilihan[] = ['nilai' => (string) $id, 'label' => $label, 'grup' => 'Thermohygro lab'];
        }

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['field'] ?? [] as $j => $field) {
                if (($field['kode'] ?? null) === 'thermohygro_standard_id') {
                    $bentuk['bagian'][$i]['field'][$j]['pilihan'] = $pilihan;
                }
            }
        }

        return $bentuk;
    }

    /**
     * Tautkan ketujuh baris "Standar Anak Timbangan" ke master `standards`.
     *
     * Lewat [masterStandarTertaut] — saringan organisasi yang sama, dan alasan
     * yang sama seperti dropdown di atas. Baris yang tidak ketemu orangnya
     * pulang `terdaftar: false` dengan `standard_id` null, BUKAN dibuang:
     * teknisi harus bisa melihat bahwa keping yang tercetak di kertasnya belum
     * terdaftar di master, bukan mendapati barisnya hilang begitu saja.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanAnakTimbangan(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = $this->masterStandarTertaut($equipment);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            foreach ($bagian['baris'] ?? [] as $j => $baris) {
                $cocok = $this->cocokkanStandar($master, [(string) $baris['nama']]);

                $bentuk['bagian'][$i]['baris'][$j] = [
                    ...$baris,
                    'standard_id' => $cocok?->id,
                    'terdaftar' => $cocok !== null,
                ];
            }
        }

        return $bentuk;
    }

    /** @return list<array<string, mixed>> */
    private function bagianIdentitas(): array
    {
        return [
            [
                'kode' => 'identitas_alat',
                'halaman' => 1,
                'judul' => 'IDENTITAS ALAT',
                'field' => [
                    $this->f('tanggal_terima', 'Received Date', 'tanggal'),
                    $this->f('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                    $this->f('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                    $this->f('equipment.nama_alat', '1. Nama Alat', 'teks', sumber: 'otomatis'),
                    $this->f('alat_merk', '2. Merk', 'teks'),
                    $this->f('alat_model', '3. Type', 'teks'),
                    $this->f('alat_serial_number', '4. No. Seri', 'teks'),
                    $this->f('spesifikasi_alat.rentang_ukur', '5. Rentang Ukur', 'angka'),
                    $this->f('spesifikasi_alat.kapasitas', '6. Kapasitas Alat', 'angka'),
                    $this->f('spesifikasi_alat.resolusi', '7. Resolusi Alat', 'angka'),
                    // `e` & kelas menentukan MPE (SNSU PK.M-02:2021) yang
                    // dicetak di sertifikat. Boleh "-" kalau alatnya memang
                    // tidak punya — master menyediakan kotaknya begitu.
                    $this->f('spesifikasi_alat.nilai_e', '8. Nilai e (boleh "-")', 'teks'),
                    $this->f('spesifikasi_alat.kelas', '9. Kelas (I / II / III / IIII, boleh "-")', 'teks'),
                    $this->f('tipe_display', 'Tipe Display', 'pilihan', pilihan: [
                        ['nilai' => 'Digital', 'label' => 'Digital'],
                        ['nilai' => 'Mekanik', 'label' => 'Mekanik'],
                    ]),
                    // Yang MEMILIH tabel anak timbangan (E2 vs F1) — bukan
                    // satuannya, bukan nominalnya. Salah pilih di sini bikin
                    // koreksi meleset di digit yang justru dilaporkan.
                    $this->f('tipe_timbangan', 'Tipe Timbangan', 'pilihan', pilihan: [
                        ['nilai' => TabelStandarTimbangan::NON_ANALYTICAL, 'label' => 'Non-Analytical'],
                        ['nilai' => TabelStandarTimbangan::ANALYTICAL, 'label' => 'Analytical'],
                    ]),
                    $this->f('jenis_timbangan', 'Jenis Timbangan', 'pilihan', pilihan: [
                        ['nilai' => 'tak_bertingkat', 'label' => 'Tak Bertingkat'],
                        ['nilai' => 'bertingkat_analog', 'label' => 'Bertingkat, tanpa alat penunjuk tambahan (analog)'],
                        ['nilai' => 'bertingkat_digital', 'label' => 'Bertingkat, dengan alat penunjuk tambahan (digital)'],
                    ]),
                    $this->f('varian_master', 'Metode Pembebanan', 'pilihan', pilihan: [
                        ['nilai' => VarianMasterTimbangan::KG, 'label' => 'Langsung (kg)'],
                        ['nilai' => VarianMasterTimbangan::GRAM, 'label' => 'Langsung (gram)'],
                        ['nilai' => VarianMasterTimbangan::SUBSTITUSI, 'label' => 'Beban substitusi (kapasitas besar)'],
                    ]),
                ],
            ],
            [
                'kode' => 'pemilik',
                'halaman' => 1,
                'judul' => 'IDENTITAS CUSTOMER',
                'field' => [
                    $this->f('pemilik_nama', '1. Nama Customer', 'teks'),
                    $this->f('pemilik_alamat', '2. Alamat Customer', 'teks_panjang'),
                    $this->f('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                        ['nilai' => 'lab', 'label' => 'Inlab'],
                        ['nilai' => 'onsite', 'label' => 'Insitu'],
                    ]),
                    $this->f('lokasi_nama', 'Nama Tempat (Insitu)', 'teks', tampilKalau: self::TAMPIL_KALAU_INSITU),
                    $this->f('room_id', 'Ruangan (Inlab)', 'pilihan', sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB),
                    $this->f('calibration_method_id', 'Calibration Method', 'pilihan', sumber: 'master_metode'),
                    $this->f('suhu_awal', 'Suhu Ruangan — Awal', 'angka', satuan: '°C'),
                    $this->f('suhu_akhir', 'Suhu Ruangan — Akhir', 'angka', satuan: '°C'),
                    $this->f('kelembaban_awal', 'Kelembaban — Awal', 'angka', satuan: '%RH'),
                    $this->f('kelembaban_akhir', 'Kelembaban — Akhir', 'angka', satuan: '%RH'),
                    $this->f('thermohygro_standard_id', 'Thermohygro Used', 'pilihan', sumber: 'master_thermohygro'),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianStandar(): array
    {
        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'STANDAR ANAK TIMBANGAN',
            'baris' => self::STANDARD_TERCETAK,
            'field' => [
                $this->f('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                $this->f('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
            ],
        ];
    }

    /**
     * Ketujuh unit yang tercetak di dropdown "Thermohygro Used" master
     * (`INPUT DATA!AC16`: rantai `IF(E25=1,"TH-1", … IF(E25=7,"TH-7"))`).
     *
     * Disaring ke daftar ini, BUKAN dipulangkan apa adanya dari
     * [masterThermohygro]. Master itu memulangkan tiap `standards` ber-
     * `parameter_kondisi`, dan di lab ini termasuk **Thermobarometer Lutron** —
     * unit TEKANAN yang cuma dipakai lembar Gas Detector, dan yang tidak
     * pernah muncul di kertas Timbangan. Membiarkannya lolos berarti teknisi
     * bisa memilih barometer sebagai sumber koreksi suhu & kelembapan
     * ruangan, dan koreksi yang lahir dari situ tidak berarti apa-apa.
     */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /**
     * Anak timbangan yang tercetak di kotak "Standar AT" master
     * (`DATABASE!R25:V31`). Nama & nominalnya harus cocok dengan baris
     * `standards` yang di-seed, kalau tidak kotaknya pulang `terdaftar: false`.
     */
    public const STANDARD_TERCETAK = [
        ['kode' => 'AT1', 'nama' => 'Anak Timbangan E2', 'nominal' => '100 mg - 200 gr'],
        ['kode' => 'AT2', 'nama' => 'Anak Timbangan F1', 'nominal' => '100 mg - 500 gr'],
        ['kode' => 'AT3', 'nama' => 'Anak Timbangan F21', 'nominal' => '1000 gr'],
        ['kode' => 'AT4', 'nama' => 'Anak Timbangan F22', 'nominal' => '2000 gr'],
        ['kode' => 'AT5', 'nama' => 'Anak Timbangan F25', 'nominal' => '5000 gr'],
        ['kode' => 'AT6', 'nama' => 'Anak Timbangan M2', 'nominal' => '20 kg'],
        ['kode' => 'AT7', 'nama' => 'Anak Timbangan F1-10', 'nominal' => '10 kg'],
    ];

    /** @return array<string, mixed> */
    private function bagianScaleObservation(): array
    {
        return [
            'kode' => 'scale_observation',
            'halaman' => 1,
            'judul' => '1. SCALE OBSERVATION',
            // Dua tahap yang sudah punya kolomnya sendiri di `raw_measurements`
            // (`tahap`), dipakai sepuluh alat lain buat hal yang sama persis:
            // pembacaan as-found yang dicatat tapi tidak ikut GUM.
            'baris' => [
                ['kode' => 'sebelum_adjustment', 'label' => 'Before Adjustment'],
                ['kode' => 'sesudah_adjustment', 'label' => 'After Adjustment'],
            ],
            'field' => [
                $this->f('scale_observation.*.standar', 'Standar Weight', 'angka'),
                $this->f('scale_observation.*.z1', 'z1', 'angka'),
                $this->f('scale_observation.*.m1', 'm1', 'angka'),
                $this->f('scale_observation.*.m2', 'm2', 'angka'),
                $this->f('scale_observation.*.z2', 'z2', 'angka'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianEffectOfTare(): array
    {
        return [
            'kode' => 'effect_of_tare',
            'halaman' => 1,
            'judul' => '2. EFFECT OF TARE',
            'field' => [
                $this->f('effect_of_tare.standar', 'Standar Weight', 'angka'),
                $this->f('effect_of_tare.m1', 'm1', 'angka'),
                $this->f('effect_of_tare.m2', 'm2', 'angka'),
                $this->f('effect_of_tare.bentuk_pan', 'Bentuk Pan', 'pilihan', pilihan: [
                    ['nilai' => 'kotak', 'label' => 'Kotak'],
                    ['nilai' => 'lingkaran', 'label' => 'Lingkaran'],
                ]),
                $this->f('effect_of_tare.ukuran_pan', 'Ukuran / Diameter Pan', 'teks'),
            ],
        ];
    }

    /**
     * Blok 3 — ACCURACY. Satu-satunya blok yang jadi baris titik di sertifikat.
     *
     * Dimodelkan sebagai `tabel` beneran (bukan daftar field) karena tiga hal
     * bergantung padanya: layar teknisi menggambar grid dari sini, `ocr:cetak-
     * lembar` menurunkan kotak kertasnya dari sini, dan
     * `ocr:rangka-geometri` menurunkan koordinat selnya dari sini. Ditulis
     * sebagai field datar, ketiganya diam-diam kehilangan tabelnya — lembarnya
     * terbuka rapi dan tidak bisa diisi.
     *
     * Empat kolom pengulangannya BUKAN pengulangan dalam arti biasa: mereka
     * empat pembacaan yang artinya berbeda-beda (`z` nol sebelum, `m` &
     * `m'` berbeban, `z'` nol sesudah). Yang dipakai sumbu `pengulangan`
     * karena bentuk gridnya memang itu — satu baris titik, empat kotak angka.
     *
     * @return array<string, mixed>
     */
    private function bagianAkurasi(?Equipment $equipment = null): array
    {
        $satuan = (string) ($equipment?->satuan ?? 'kg');

        return [
            'kode' => 'akurasi',
            'halaman' => 1,
            'judul' => '3. ACCURACY',
            'tabel' => [[
                'tahap' => 'sesudah_adjustment',
                'grup' => 'akurasi',
                'peran' => 'akurasi',
                'judul' => 'Accuracy — pembebanan bertingkat',
                'satuan' => $satuan,
                'judul_nilai' => 'Nominal',
                'judul_pengulangan' => 'Pembacaan',
                'pengulangan_arah' => [
                    ['ke' => 1, 'label' => 'z'],
                    ['ke' => 2, 'label' => 'm'],
                    ['ke' => 3, 'label' => "m'"],
                    ['ke' => 4, 'label' => "z'"],
                ],
                'titik_bisa_diubah' => true,
                'baris' => $this->tangga($equipment, $satuan),
                'kolom' => [
                    ['kode' => 'pembacaan', 'label' => $satuan, 'tipe' => 'angka', 'satuan' => $satuan],
                ],
                'pengulangan' => range(1, 4),
            ]],
            'field' => [
                // Nominal anak timbangan per titik, urut Mass 1..6 (KOLOM-MAJOR
                // seperti master). Dipisah dari tabel karena satu baris titik
                // bisa memakai sampai enam keping sekaligus, dan urutannya ikut
                // ke slot drift di budget — lihat TimbanganCalculator.
                $this->f('measurements.*.nominal', 'Nominal Anak Timbangan (Mass 1..6)', 'daftar_angka'),
            ],
        ];
    }

    /**
     * Tangga titik saran: **10 % s/d 100 % rentang ukur, sepuluh langkah rata**.
     *
     * Bukan tebakan — ketiga master memakai pola yang sama persis:
     * sesi kg (rentang 100 kg) memuat 10…100 kg; sesi gram (rentang 50 g)
     * memuat 5…50 g; sesi substitusi (rentang 2000 kg) memuat 200…2000 kg.
     *
     * `titik_bisa_diubah` tetap `true` — teknisi boleh menggeser tangganya.
     * Yang dihindari kebalikannya: lembar yang terbuka dengan NOL baris, dan
     * teknisi harus menambah tiap baris satu-satu sebelum bisa mulai. Itu
     * persis K18 yang sudah pernah menahan lembar TIDS.
     *
     * @return list<array<string, mixed>>
     */
    private function tangga(?Equipment $equipment, string $satuan): array
    {
        // `range_max`, bukan `kapasitas`: tabel `equipments` nggak punya kolom
        // kapasitas sama sekali. "Kapasitas Alat" itu field LEMBAR, tersimpan
        // di `spesifikasi_alat` — dan buat hampir semua timbangan angkanya
        // sama dengan rentang ukur.
        $rentang = (float) ($equipment?->range_max ?? 0.0);
        $langkah = 10;

        return array_map(
            static function (int $i) use ($rentang, $langkah, $satuan): array {
                $nilai = $rentang > 0.0 ? round($rentang * $i / $langkah, 6) : 0.0;

                return [
                    'titik_ukur' => $nilai,
                    'label' => $rentang > 0.0
                        ? rtrim(rtrim(number_format($nilai, 4, ',', '.'), '0'), ',').' '.$satuan
                        : sprintf('Titik %d', $i),
                    'satuan' => $satuan,
                ];
            },
            range(1, $langkah),
        );
    }

    /**
     * Blok 4 — REPEATABILITY. Dua kapasitas × sepuluh pengulangan, dan tiap
     * pengulangan punya SEPASANG angka (nol & berbeban).
     *
     * `kolom` yang membawa pasangannya, bukan dua tabel terpisah: di kertasnya
     * memang satu tabel dengan kolom `(zi)` dan `(mi)` berdampingan.
     *
     * @return array<string, mixed>
     */
    private function bagianKeterulangan(?Equipment $equipment = null): array
    {
        $satuan = (string) ($equipment?->satuan ?? 'kg');
        $rentang = (float) ($equipment?->range_max ?? 0.0);

        return [
            'kode' => 'keterulangan',
            'halaman' => 1,
            'judul' => '4. REPEATABILITY',
            'tabel' => [[
                'tahap' => 'sesudah_adjustment',
                'grup' => 'keterulangan',
                'peran' => 'keterulangan',
                'judul' => 'Repeatability — 10 pengulangan',
                'satuan' => $satuan,
                'judul_nilai' => 'Kapasitas',
                'judul_pengulangan' => 'Pengulangan ke-',
                'titik_bisa_diubah' => true,
                'baris' => [
                    [
                        'titik_ukur' => $rentang > 0.0 ? round($rentang / 2, 6) : 0.0,
                        'label' => 'Middle Capacity',
                        'satuan' => $satuan,
                    ],
                    [
                        'titik_ukur' => $rentang,
                        'label' => 'Maximum Capacity',
                        'satuan' => $satuan,
                    ],
                ],
                'kolom' => [
                    ['kode' => 'zero', 'label' => 'Zero (zi)', 'tipe' => 'angka', 'satuan' => $satuan],
                    ['kode' => 'pembacaan', 'label' => 'Reading (mi)', 'tipe' => 'angka', 'satuan' => $satuan],
                ],
                'jumlah_pengulangan' => 10,
                'pengulangan' => range(1, 10),
            ]],
            'field' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianEksentrisitas(): array
    {
        return [
            'kode' => 'eksentrisitas',
            'halaman' => 1,
            'judul' => '5. LOADING INFLUENCE ON EACH POSITION',
            'field' => [
                $this->f('eksentrisitas.beban', 'Beban yang dipakai', 'angka'),
                $this->f('eksentrisitas.baca.center', 'Center', 'angka'),
                $this->f('eksentrisitas.baca.front', 'Front', 'angka'),
                $this->f('eksentrisitas.baca.back', 'Back', 'angka'),
                $this->f('eksentrisitas.baca.left', 'Left', 'angka'),
                $this->f('eksentrisitas.baca.right', 'Right', 'angka'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianHisteresis(): array
    {
        return [
            'kode' => 'histeresis',
            'halaman' => 1,
            'judul' => '6. HYSTERISIS',
            'field' => [
                $this->f('histeresis.m', 'M', 'angka'),
                $this->f('histeresis.m_aksen', "M'", 'angka'),
                $this->f('histeresis.baca1.*', 'Reading 1', 'angka'),
                $this->f('histeresis.baca2.*', 'Reading 2', 'angka'),
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
                $this->f('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->f('teknisi.nama', 'Calculated by', 'teks', sumber: 'otomatis'),
                $this->f('reviewer.nama', 'Signed by', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianAdmin(): array
    {
        return [
            'kode' => 'administratif',
            'halaman' => 1,
            'judul' => 'Data Administratif (Admin)',
            'field' => [
                $this->f('nomor_order', 'Order Number', 'teks', hanyaAdmin: true),
                $this->f('certificate.nomor', 'Certificate Number', 'teks', sumber: 'otomatis', hanyaAdmin: true),
                $this->f('lop', 'Limit of Performance', 'angka', sumber: 'otomatis', hanyaAdmin: true),
                $this->f('histeresis_hasil', 'Hysterisis', 'angka', sumber: 'otomatis', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pilihan
     * @return array<string, mixed>
     */
    private function f(
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

    private function angka(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, ',', '.'), '0'), ',');
    }
}
