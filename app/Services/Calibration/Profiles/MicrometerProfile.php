<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\TabelStandarMicrometer;
use App\Support\MicrometerMentah;
use Carbon\Carbon;

/**
 * Lembar & hitungan **Micrometer** — lampiran akreditasi LK-285-IDN no. 34,
 * kelompok Dimensi, dan yang PERTAMA di kelompok itu.
 *
 * Empat workbook master turun bersamaan (0-25, 25-50, 50-75, 75-100 mm) dan
 * keempatnya identik baris demi baris — yang membedakan cuma pita CMC-nya. Jadi
 * SATU profil dengan empat pita, bukan empat profil; polanya sama dengan
 * [TimbanganProfile] yang tiga workbook masternya jadi satu profil tiga varian.
 *
 * ## Ketidakpastian lahir per SESI, bukan per titik
 *
 * Sheet `PERHITUNGAN U95%` master cuma punya satu kolom, dan sertifikatnya
 * mencetak satu baris `Uncertainty U95% = ±` di bawah sebelas titik. Makanya
 * [hitungPerGrup] yang dipakai, dan [komponenBudget] memulangkan `null` —
 * bukan karena belum ditulis, tapi karena bentuk per titik tidak ada di alat
 * ini. Dua komponennya memang tidak bisa dinyatakan per titik: pengulangan
 * datang dari PRA-EVALUASI (sepuluh pembacaan berulang di satu titik) dan suhu
 * diisi sekali per sesi.
 *
 * ## Blok tingkat-sesi tinggal di `spesifikasi_alat`
 *
 * Pra-evaluasi, balok ukur pra-evaluasi, suhu balok/UUT, kapasitas, dan
 * resolusi bukan titik ukur — memaksanya jadi `titik_ke` melahirkan titik hantu
 * yang selalu gagal hitung ulang. Lihat [MicrometerMentah::blokSesi].
 *
 * ## Nomor formulir lembar kerjanya belum ada
 *
 * Sapuan `SIDIK-FM-` ke delapan sheet × empat workbook cuma memulangkan SATU
 * nomor: `SIDIK-FM-CAL-2403_Rev. 0`, di footer sheet `SERTIFIKAT`. Itu nomor
 * formulir SERTIFIKAT bersama — Tachometer, Timer, dan Centrifuge memakai nomor
 * yang sama persis. Nomor lembar kerjanya sendiri belum pernah dikirim, jadi
 * `kode_dokumen` null dan `micrometer` masuk daftar `belumAdaKertasnya` di
 * `SemuaProfilLembarKerjaTest`. Keluarkan dia dari daftar itu begitu kertasnya
 * turun — jangan sebelum.
 */
class MicrometerProfile extends CalibrationProfile
{
    /** Satuan panjang lembar & sertifikat. Budget-nya sendiri dalam µm. */
    public const SATUAN = 'mm';

    public const SATUAN_BUDGET = 'µm';

    /** Sebelas titik, sesuai sertifikat master (baris 18..28). */
    public const BARIS_KERTAS = 11;

    /** Sampai tiga keping balok ukur di-*wringing* jadi satu tumpukan. */
    public const KEPING_TUMPUKAN = 3;

    /** Lima pembacaan per titik (`PERHITUNGAN` kolom I..M). */
    public const PENGULANGAN = 5;

    /** Sepuluh pembacaan berulang pra-evaluasi (`PERHITUNGAN` baris 25). */
    public const PRA_EVALUASI = 10;

    /**
     * Instruksi Kerja-nya, dari lampiran akreditasi baris no. 34. Ini BUKAN
     * nomor formulir lembar kerja — yang itu belum pernah dikirim, lihat
     * docblock kelas.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0515_Rev.3';

    public const STANDARD_TERCETAK = [
        [
            'label' => 'Gauge Block Standard/Metrology/GB-9122-0',
            'cocok' => ['Gauge Block Standard', 'GB-9122-0', '160006'],
        ],
    ];

    /** Ketujuh unit thermohygro yang tercetak di kop master (`TH-1`…`TH-7`). */
    public const THERMOHYGRO_TERCETAK = ['TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7'];

    /**
     * Satuan yang ditawarkan master lewat `Satuan_Caliper` (DATABASE R23:T25),
     * berikut faktor pengalinya ke mm.
     *
     * Dipasang sebagai dropdown, bukan teks bebas: sesi contoh 0-25 mm master
     * tersetel `inch` sementara angkanya diketik dalam mm, dan akibatnya
     * berantai — kapasitas jadi 635 mm, jatuh di luar semua pita CMC, dan
     * koreksinya terbit −61 mm pada balok ukur 2,5 mm. Tidak ada satu pun sel
     * yang memprotes.
     */
    public const SATUAN_PILIHAN = ['mm' => 1.0, 'inch' => 25.4, 'µm' => 0.001];

    private ?MicrometerCalculator $kalk = null;

    public function kode(): string
    {
        return 'micrometer';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Micrometer';
    }

    public function aliasNama(): array
    {
        return ['Mikrometer', 'Outside Micrometer', 'Micrometer Outside', 'Mikrometer Luar'];
    }

    public function kodeFormula(): string
    {
        return 'gum-micrometer';
    }

    public function besaran(): string
    {
        return 'dimensi';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    public function butuhBlokMicrometer(): bool
    {
        return true;
    }

    /**
     * Sesi Micrometer TIDAK divonis PASS/FAIL.
     *
     * Keempat workbook master berhenti di kolom `Correction` + satu baris
     * `Uncertainty U95%`; tidak ada satu pun batas keberterimaan di lembarnya,
     * dan lampiran akreditasi baris no. 34 juga tidak menyebutnya. Dibiarkan
     * `true` (bawaan), form Alat mewajibkan kolom `toleransi` yang tidak punya
     * isi yang benar — dan teknisi mengarang angkanya.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Bentuk lembar buat pembaca foto.
     *
     * `kolom_suhu = false`: bawaan [CalibrationProfile::bentukPindaiFoto]
     * menuruti lembar pH, yang tiap selnya sepasang angka (pembacaan + suhu).
     * Lembar ini tidak — kolom tabelnya cuma `nominal` dan `nilai`, dan suhu
     * diisi SEKALI per sesi di blok pra-evaluasi. Dibiarkan `true`, prompt
     * pembaca foto meminta model membaca kolom yang tidak ada di kertasnya, dan
     * yang balik bukan error melainkan angka karangan yang wajar.
     *
     * `didukung = false`: kertas lembar kerjanya belum pernah dikirim lab, jadi
     * geometri di `database/ocr-templates/micrometer-v1.json` masih grid rata
     * hasil generator (`terverifikasi: false`) — belum pernah diadu ke formulir
     * cetak asli. Jalur kamera dibuka begitu kertasnya turun; input manual
     * dulu.
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

    /**
     * Ketidakpastian alat ini lahir per SESI — lihat docblock kelas. `null` di
     * sini bukan "belum ditulis": jalur per titik memang tidak ada bentuknya.
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
     * Sebelas titik + satu budget sesi.
     *
     * Titik yang tidak bisa dihitung dilaporkan lewat `belum_dihitung`, tidak
     * dibuang diam-diam — itu bedanya dari `IFERROR(...; "")` master, yang
     * membuat titiknya hilang dari sertifikat tanpa jejak.
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        // Blok tingkat-sesi datang lewat `konteks`, bukan relasi — jalur simpan
        // dan jalur hitung ulang sama-sama menaruhnya di situ, dan profil yang
        // menengok relasi sesi cuma jalan di salah satunya.
        //
        // Disapu, bukan diambil dari `$titik[0]`: jalur hitung ulang
        // mengelompokkan per `titik_ke` lewat `groupBy` dan urutannya tidak
        // dijamin, jadi bertumpu pada elemen pertama berarti sesi yang titik
        // pertamanya kebetulan tersaring pulang tanpa blok — diam-diam, dengan
        // seluruh titiknya "belum dihitung".
        $konteksSesi = [];

        foreach ($titik as $t) {
            if (isset($t['konteks']['spesifikasi_alat'])) {
                $konteksSesi = $t['konteks'];

                break;
            }
        }

        $blok = MicrometerMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Micrometer belum punya blok pra-evaluasi di `spesifikasi_alat.'
                        .MicrometerMentah::KUNCI_SESI.'` — pengulangan, suhu, kapasitas, dan '
                        .'resolusi lahir di situ, bukan per titik.',
                ], $titik),
            ];
        }

        $masukan = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            // Kedua deret datang lewat `konteks`, bukan level atas — jalur
            // simpan dan jalur hitung ulang sama-sama menaruhnya di situ,
            // persis seperti blok Timbangan dan grid Enclosure.
            $k = $t['konteks'] ?? [];
            $balok = array_map('floatval', $k[MicrometerMentah::PERAN_BALOK] ?? []);
            $pembacaan = array_map('floatval', $k[MicrometerMentah::PERAN_PEMBACAAN] ?? []);

            // Sesi yang baris mentahnya belum ber-`peran_sensor` DITOLAK dengan
            // alasan yang kebaca, bukan diam-diam dihitung dari `pembacaan`
            // datar. Deret datar tidak bisa dibedakan mana nominal balok ukur
            // mana penunjukan alat, dan koreksi yang lahir dari situ tidak
            // berarti apa-apa.
            if ($balok === [] && $pembacaan === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`/`%s`. Lembar Micrometer nyimpen '
                        .'TUMPUKAN balok ukur dan deret pembacaan terpisah — deret datar nggak '
                        .'bisa dipakai.',
                        $t['titik_ke'],
                        MicrometerMentah::PERAN_BALOK,
                        MicrometerMentah::PERAN_PEMBACAAN,
                    ),
                ];

                continue;
            }

            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'nominal' => $balok,
                'pembacaan' => $pembacaan,
                'standard' => $t['standard'] ?? null,
            ];
        }

        $kemampuan = $this->kemampuanSesi($equipment);
        $hasil = ($this->kalk ??= new MicrometerCalculator)->hitungSesi(
            array_map(static fn (array $m): array => [
                'titik_ke' => $m['titik_ke'],
                'nominal' => $m['nominal'],
                'pembacaan' => $m['pembacaan'],
            ], $masukan),
            $blok + ['tanggal_kalibrasi' => $this->tanggalKalibrasi($konteksSesi)],
        );

        $standarPerTitik = collect($masukan)->keyBy('titik_ke');
        $sekarang = Carbon::now();
        $hitungan = [];

        // Budget yang tidak utuh TIDAK melahirkan satu pun baris hitungan —
        // titiknya masuk `belum_dihitung` seluruhnya.
        //
        // Ini bukan kerapian. Menerbitkan baris ber-`ketidakpastian_diperluas`
        // nol berarti sertifikatnya mencetak `± 0,000` — klaim pengukuran
        // SEMPURNA, dan itu lebih buruk daripada 0,735 µm yang sedang
        // diperbaiki. Peringatan sesi tidak menahannya: `CalibrationValidator`
        // membungkus [peringatanSesi] jadi temuan tingkat PERINGATAN yang boleh
        // dilewati admin lewat `abaikan_peringatan`, jadi yang menahan harus
        // ketiadaan barisnya, bukan pesannya.
        //
        // Dua sebabnya, dan gerbangnya sengaja SATU
        // ([MicrometerCalculator] `boleh_terbit`): pita CMC yang hilang, dan
        // budget yang kehilangan komponen — mis. pra-evaluasi berisi kurang
        // dari dua pembacaan, yang membuat pengulangan jatuh ke nol dan U95
        // mendarat di lantai CMC dengan tampang wajar.
        if (! $hasil['boleh_terbit']) {
            $alasanSesi = implode(' ', array_map(
                static fn (array $d): string => (string) $d['alasan'],
                array_filter($hasil['ditolak'], static fn (array $d): bool => (int) $d['titik_ke'] === 0),
            ));

            foreach ($hasil['titik'] as $h) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $h['titik_ke'],
                    'alasan' => trim('Budget sesi tidak utuh, jadi titik ini tidak diterbitkan. '.$alasanSesi),
                ];
            }

            usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        foreach ($hasil['titik'] as $h) {
            $hitungan[] = [
                // Null-safe: sesi yang standarnya di-soft-delete memulangkan
                // null, dan tanpa `?->` perintah hitung ulang mati total.
                'standard_id' => ($standarPerTitik[$h['titik_ke']]['standard'] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['total_nominal'],
                'rata_rata' => $h['rata_rata'],
                'error' => -$h['koreksi'],
                'koreksi' => $h['koreksi'],
                'standar_deviasi' => $h['simpangan_baku'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                // Type A sesi, bukan titik: pengulangan lahir dari pra-evaluasi.
                'type_a' => $this->typeASesi($hasil['budget']),
                'type_b_components' => $this->jejakAudit($hasil, $h, $kemampuan),
                'type_b' => $hasil['type_b'],
                'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
                'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $kemampuan?->metode,
                'calculated_at' => $sekarang,
            ];
        }

        foreach ($hasil['ditolak'] as $d) {
            $belumDihitung[] = $d;
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Peringatan sesi: sesi tanpa pita CMC tidak boleh lewat diam-diam.
     *
     * Master 0-25 mm menerbitkan U95 0,735 µm padahal pita terakreditasinya
     * 0,83 µm — dan tidak ada satu pun sel yang memprotes.
     *
     * ## Peringatan ini BUKAN yang menahan sesinya
     *
     * `CalibrationValidator::periksaPeringatanProfil()` membungkus apa pun yang
     * dipulangkan di sini jadi temuan tingkat PERINGATAN — menahan approve
     * sekali, lalu boleh dilewati admin lewat `abaikan_peringatan`. Jadi
     * mengembalikan `tingkat => 'blokir'` di sini cuma bohong pada pembaca
     * kode: kuncinya tidak pernah dibaca, cuma `kode` dan `pesan`.
     *
     * Yang benar-benar menahan: [hitungPerGrup] tidak melahirkan satu pun baris
     * hitungan waktu pitanya hilang, jadi tidak ada angka yang bisa dicetak.
     * Pesan ini tugasnya menjelaskan KENAPA sesinya kosong.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = MicrometerMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $pita = (new TabelStandarMicrometer)->pitaCmc($blok['kapasitas_mm']);

        if ($pita !== null) {
            return [];
        }

        return [[
            'kode' => 'micrometer_di_luar_cmc',
            'pesan' => sprintf(
                'Kapasitas %s mm tidak masuk keempat pita CMC Micrometer (0-25, 25-50, 50-75, '
                .'75-100 mm), jadi sesi ini TIDAK menghasilkan satu pun titik terhitung — '
                .'ketidakpastiannya tidak punya lantai yang bisa dipertanggungjawabkan. '
                .'Periksa SATUAN kapasitas lebih dulu: master 0-25 mm sendiri tersetel `inch` '
                .'sementara angkanya milimeter, dan 25 × 25,4 = 635 mm jatuh di luar semua pita.',
                rtrim(rtrim(number_format($blok['kapasitas_mm'], 4, ',', '.'), '0'), ','),
            ),
        ]];
    }

    public function desimalSertifikat(): ?int
    {
        // Lima desimal mm — nilai terkoreksi balok ukur master (`2.50014`,
        // `100.00012`) memang sedetail itu, dan membulatkannya lebih dulu
        // membuang koreksi yang justru sedang diukur.
        return 5;
    }

    public function desimalU95(): ?int
    {
        return 3;
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => null,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => 'LK-285-IDN',
            'judul' => 'Calibration Work Sheet - Micrometer',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Isi SATUAN alat lebih dulu — dia yang mengubah semua angka '
                .'lembar ini ke mm. Tiap titik: tumpuk balok ukur (sampai tiga keping, '
                .'di-wringing) lalu baca mikrometernya lima kali. Titik yang nggak dipakai '
                .'dikosongin — jangan diisi nol, karena nominal nol tetap melahirkan koreksi '
                .'sebesar rata-rata pembacaannya dan tercetak seperti titik sungguhan. Blok '
                .'pra-evaluasi (sepuluh pembacaan berulang) WAJIB diisi: dari situ '
                .'pengulangannya lahir, bukan dari lima pembacaan tiap titik.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master_Olah_Data_Micrometer_{025,2550,5075,75100}mm.xlsm',
                'catatan' => 'Sembilan komponen tingkat-SESI, lantai CMC empat pita '
                    .'(0,83 / 0,87 / 0,91 / 0,91 µm). Sesi yang kapasitasnya di luar keempat '
                    .'pita diblokir, bukan diterbitkan tanpa lantai.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianPraEvaluasi(),
                $this->bagianDataKalibrasi(),
                $this->bagianPemeriksaanMuka(),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
    }

    /**
     * Tanggal kalibrasi sesi — titik nol umur drift standar.
     *
     * Master memakai `NOW()`, jadi U95 sesi yang sama tidak pernah terulang:
     * keempat workbook disimpan selang dua menit dan umur driftnya beda
     * (695,4212 vs 695,4225 hari). Di sini tanggal SESI, yang jelas maksudnya
     * dan bisa diulang tahun depan dengan hasil yang sama.
     *
     * @param  array<string, mixed>  $konteks
     */
    private function tanggalKalibrasi(array $konteks): \DateTimeInterface
    {
        $tanggal = $konteks['tanggal_kalibrasi'] ?? null;

        return $tanggal ? Carbon::parse($tanggal) : Carbon::now();
    }

    /** @param  list<array<string, mixed>>  $budget */
    private function typeASesi(array $budget): float
    {
        foreach ($budget as $k) {
            if (($k['distribusi'] ?? null) === 't-student') {
                return (float) $k['u'] * (float) $k['ci'];
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @param  array<string, mixed>  $h
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, array $h, ?CalibrationCapability $kemampuan): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit($k), $hasil['budget']);

        $budget[] = $this->barisPerbandinganCmc(
            (float) $hasil['ketidakpastian_diperluas'],
            $hasil['pita_cmc']['u95_um'] ?? null,
            self::SATUAN_BUDGET,
        );

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Tumpukan balok ukur %s mm · total nominal %s mm · rata-rata pembacaan %s mm · '
                .'koreksi %s mm · U sebelum lantai CMC %s µm · pita CMC %s',
                implode(' + ', array_map(
                    static fn ($n): string => (string) $n,
                    array_filter($h['nominal'], static fn ($n): bool => $n !== null),
                )) ?: '-',
                $h['total_nominal'], $h['rata_rata'], $h['koreksi'],
                $hasil['ketidakpastian_diperluas'],
                $hasil['pita_cmc']['label'] ?? 'DI LUAR PITA — U95 tidak diterbitkan',
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $budget;
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(): array
    {
        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat dan Data Customer',
            'field' => [
                $this->field('equipment_id', 'Nama Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                // Satuan lebih dulu, karena dia yang mengubah arti tiga field
                // di bawahnya. Lihat [SATUAN_PILIHAN].
                $this->field('spesifikasi_alat.micrometer.satuan', 'Satuan Alat', 'pilihan', pilihan: [
                    ['nilai' => 'mm', 'label' => 'mm'],
                    ['nilai' => 'inch', 'label' => 'inch'],
                    ['nilai' => 'µm', 'label' => 'µm'],
                ]),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'teks'),
                $this->field('spesifikasi_alat.micrometer.kapasitas_mm', 'Kapasitas Max.', 'angka', satuan: self::SATUAN),
                $this->field('spesifikasi_alat.micrometer.resolusi_mm', 'Resolusi Alat', 'angka', satuan: self::SATUAN),
                $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: '°C'),
                $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: '°C'),
                $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                // Dua kotak lokasi yang saling meniadakan — tanpa
                // `tampil_kalau`, dropdown Ruangan tetap menyimpan pilihan lama
                // walau sedang Insitu, dan sertifikatnya mencetak nama ruang lab
                // yang tidak pernah didatangi.
                $this->field(
                    'room_id', 'Ruangan (Inlab)', 'pilihan',
                    sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                $this->field(
                    'lokasi_nama', 'Nama Tempat (Insitu)', 'teks',
                    tampilKalau: self::TAMPIL_KALAU_INSITU,
                ),
                $this->field('thermohygro_standard_id', 'Environmental Meter Used', 'pilihan', sumber: 'master_thermohygro'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Data Customer',
            'field' => [
                $this->field('pemilik_nama', 'Nama Customer', 'teks'),
                $this->field('pemilik_alamat', 'Alamat Customer', 'teks_panjang'),
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
            'judul' => 'Standard Used',
            'baris' => self::STANDARD_TERCETAK,
            'field' => [
                $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
            ],
        ];
    }

    /**
     * Blok pra-evaluasi — tingkat SESI, bukan titik.
     *
     * Dari sini pengulangan (Type A) dan suhu lahir. Sepuluh pembacaan berulang
     * di SATU titik, ditambah tumpukan balok ukur yang dipakai untuk
     * pembacaan itu.
     *
     * @return array<string, mixed>
     */
    private function bagianPraEvaluasi(): array
    {
        return [
            'kode' => 'pra_evaluasi',
            'halaman' => 1,
            'judul' => 'Pre-Evaluation (Outside Measurement)',
            'field' => [
                $this->field('spesifikasi_alat.micrometer.suhu_balok_c', 'Suhu Balok Ukur', 'angka', satuan: '°C'),
                $this->field('spesifikasi_alat.micrometer.suhu_uut_c', 'Suhu Mikrometer', 'angka', satuan: '°C'),
            ],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pra_balok',
                    'judul' => 'Balok Ukur Pra-Evaluasi',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Keping',
                    'judul_pengulangan' => 'Nominal',
                    'titik_bisa_diubah' => false,
                    'simpan_ke' => 'spesifikasi_alat.micrometer.balok_pra_evaluasi',
                    'baris' => array_map(
                        static fn (int $n): array => [
                            'nomor' => $n,
                            'titik_ukur' => null,
                            'label' => 'Keping '.$n,
                            'satuan' => self::SATUAN,
                        ],
                        range(1, 6),
                    ),
                    'kolom' => [
                        ['kode' => 'nominal', 'label' => 'Nominal', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => [1],
                ],
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => 'pra_pembacaan',
                    'judul' => 'Pembacaan Berulang (X1…X10)',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Pembacaan',
                    'judul_pengulangan' => 'Ulangan',
                    'titik_bisa_diubah' => false,
                    'simpan_ke' => 'spesifikasi_alat.micrometer.pra_evaluasi',
                    'baris' => [[
                        'nomor' => 1,
                        'titik_ukur' => null,
                        'label' => 'Pembacaan berulang',
                        'satuan' => self::SATUAN,
                    ]],
                    'kolom' => [
                        ['kode' => 'nilai', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => range(1, self::PRA_EVALUASI),
                ],
            ],
        ];
    }

    /**
     * Dua tabel bersisian: tumpukan balok ukur dan deret pembacaan.
     *
     * `grup` — bukan `peran` — yang memisahkan keduanya. Di HP `peran` berarti
     * lembar pasangan standar/UUT dan membelokkan seluruh jalur kirimnya;
     * Micrometer bukan lembar pasangan.
     *
     * @return array<string, mixed>
     */
    private function bagianDataKalibrasi(): array
    {
        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Data Hasil Kalibrasi',
            'field' => [],
            'tabel' => [
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => MicrometerMentah::PERAN_BALOK,
                    'judul' => 'Nominal Balok Ukur (tumpukan)',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Titik',
                    'judul_pengulangan' => 'Keping',
                    'titik_bisa_diubah' => true,
                    'baris' => $this->barisTitik(),
                    'kolom' => [
                        ['kode' => 'nominal', 'label' => 'Nominal', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => range(1, self::KEPING_TUMPUKAN),
                ],
                [
                    'tahap' => 'sesudah_adjustment',
                    'grup' => MicrometerMentah::PERAN_PEMBACAAN,
                    'judul' => 'Pembacaan Mikrometer',
                    'satuan' => self::SATUAN,
                    'judul_nilai' => 'Titik',
                    'judul_pengulangan' => 'Ulangan',
                    'titik_bisa_diubah' => true,
                    'baris' => $this->barisTitik(),
                    'kolom' => [
                        ['kode' => 'nilai', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                    ],
                    'pengulangan' => range(1, self::PENGULANGAN),
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function barisTitik(): array
    {
        return array_map(
            static fn (int $n): array => [
                'nomor' => $n,
                'titik_ukur' => null,
                'label' => 'Titik '.$n,
                'satuan' => self::SATUAN,
            ],
            range(1, self::BARIS_KERTAS),
        );
    }

    /**
     * Pemeriksaan muka ukur — tercetak di sertifikat sebagai Baik/Buruk
     * (`SERTIFIKAT!G32` & `G33`), bukan angka.
     *
     * @return array<string, mixed>
     */
    private function bagianPemeriksaanMuka(): array
    {
        $pilihan = [
            ['nilai' => 'baik', 'label' => 'Baik'],
            ['nilai' => 'buruk', 'label' => 'Buruk'],
        ];

        return [
            'kode' => 'pemeriksaan_muka',
            'halaman' => 1,
            'judul' => 'Pemeriksaan Muka Ukur',
            'field' => [
                $this->field(
                    'spesifikasi_alat.micrometer.kerataan_muka',
                    'Kerataan Muka Ukur (Optical Flat)', 'pilihan', pilihan: $pilihan,
                ),
                $this->field(
                    'spesifikasi_alat.micrometer.kesejajaran_muka',
                    'Kesejajaran Muka Ukur (Gauge Block)', 'pilihan', pilihan: $pilihan,
                ),
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
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Dikalibrasi Oleh', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Diperiksa Oleh', 'teks', sumber: 'otomatis'),
            ],
        ];
    }
}
