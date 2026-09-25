<?php

namespace App\Services\Calibration\Profiles;

use App\Models\Equipment;
use App\Services\Calibration\GayaCalculator;
use App\Services\Calibration\TabelStandarGaya;
use App\Support\GayaMentah as M;
use Illuminate\Support\Carbon;

/**
 * Proving Ring — alat gaya ketiga, dan yang paling TIDAK sebangun dua lainnya.
 *
 * ## Empat hal yang membuatnya tidak bisa menumpang rantai UTM & Load Cell
 *
 * 1. **Yang dibaca bukan gaya.** Cincin bajanya melendut dan lendutannya
 *    dibaca sebagai jumlah DIVISI pada dial (`237`, `726`, …), bukan kgf atau
 *    kN. Jadi tidak ada konversi satuan sama sekali di sisi alat.
 *
 * 2. **Keluarannya FAKTOR, bukan koreksi.** Yang dicetak sertifikat
 *    `Calibration Factor = Z / J` — berapa kN per satu divisi. `Standar −
 *    UUT` tidak punya arti di sini: dua besaran yang berbeda, dan
 *    mengurangkannya menghasilkan angka yang kelihatan wajar tanpa punya
 *    makna apa pun.
 *
 * 3. **Enam pembacaan, bukan dua belas.** UP 3× lalu DOWN 3×, karena baja
 *    punya histeresis — bacaan saat beban NAIK berbeda dari saat TURUN. Tidak
 *    ada empat posisi; yang diuji bukan kesejajaran piringan.
 *
 * 4. **Koreksi suhunya faktor RUANGAN, bukan koreksi termal kalibrator.**
 *    `G52 = 1 + 0,00027 × (23 − suhu ruangan)`, acuannya 23 °C dipatok di
 *    rumus, dan dipakai DUA kali: pada pembacaan alat (`J`) dan pada nilai
 *    standar (`Y`).
 *
 *    Koreksi termal terhadap suhu sertifikat kalibrator — yang menggigit di
 *    UTM — di sini ADA di rumusnya tapi tidak pernah bekerja: lembar Proving
 *    Ring tidak punya field "actual temperature of standard" yang dipunyai
 *    lembar UTM, jadi pengurangnya nol dan `Z` sama persis dengan `Y` di
 *    kesembilan barisnya. Itu mengoreksi dugaan G6 soal "koreksi suhu ganda":
 *    ganda di rumus, tunggal pada hasilnya.
 *
 * ## Yang direplikasi walau ganjil, dan yang TIDAK
 *
 * **Direplikasi:** budget cuma menjumlahkan ENAM dari delapan komponennya —
 * zero error dan misalignment dihitung tapi tidak masuk. Terbukti dari angka,
 * bukan ditafsir: jumlah master cocok NOL BEDA dengan jumlah enam komponen
 * pertama, dan selisih terhadap delapan komponen persis suku misalignment.
 * Itu G8; keduanya tetap disimpan di jejak audit supaya kontribusinya yang
 * hilang kebaca tanpa membuka kode.
 *
 * **TIDAK direplikasi:** `IFERROR(…,"")` yang membuat sel kosong dibaca nol.
 * Master mencari koreksi standar lewat `VLOOKUP` yang gagal di setiap baris,
 * lalu menelannya jadi string kosong yang ikut dijumlahkan sebagai nol
 * (`Y = (B + W) × G52`). AGENTS.md §Aturan yang Lahir dari Kesalahan Nyata
 * melarang meniru pola itu.
 *
 * Yang perlu diketahui, dan ini mengoreksi dugaan awal: dengan standar yang
 * disebut workbook — **Load Cell 3000 kN** — pencarian yang BENAR pun
 * memulangkan nol, karena satu-satunya baris tabel di bawah 300 kN adalah
 * baris nol. Jadi angkanya kebetulan sama; yang berbeda cuma sekarang
 * keadaannya KEBACA. Titik 0,29 kN yang mengambil koreksi dari baris 0 kN
 * sementara baris berikutnya 300 kN akan menerbitkan temuan, bukan diam.
 *
 * Masalah sebenarnya bukan `ISERROR`-nya, melainkan alat 500 kgf (4,905 kN)
 * yang dikalibrasi dengan standar 3000 kN — titik terkalibrasi terendahnya 61×
 * kapasitas alat. Itu G11 & G14, dan lab yang memutuskan.
 */
class ProvingRingProfile extends GayaProfile
{
    public const KODE = 'proving_ring';

    /** Sama dengan dua alat gaya lain: tiga load cell standar lab. */
    public const STANDARD_TERCETAK = UtmProfile::STANDARD_TERCETAK;

    public const THERMOHYGRO_TERCETAK = UtmProfile::THERMOHYGRO_TERCETAK;

    public function kode(): string
    {
        return self::KODE;
    }

    /** Sama persis dengan baris lampiran akreditasi LK-285-IDN. */
    public function namaAlatKemampuan(): string
    {
        return 'Proving Ring';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            'Cincin Uji',
            'Proving Ring Analog',
            'Load Ring',
        ];
    }

    public function kodeFormula(): string
    {
        return 'GAYA-PROVING-RING';
    }

    public function sumberDrift(): string
    {
        return 'proving_ring';
    }

    /**
     * Enam: UP 3× + DOWN 3×.
     *
     * Bukan kelengkapan administratif — divisor pengulangan di budget-nya akar
     * 6, dan master membuktikannya: RSD terbesar 0,23062625 dibagi akar 6
     * memberi 0,09415277 persis seperti yang tercatat.
     */
    public function jumlahBacaanWajib(): int
    {
        return M::REPLIKAT * 2;
    }

    /**
     * Sertifikat mencetak `Z` — sesudah KEDUA koreksi suhu.
     *
     * Kolom `Standard Value` master mengambil `Z` di semua baris kecuali baris
     * data PERTAMA, yang memakai `Y`. Di titik nol keduanya nol, jadi tidak ada
     * percabangan untuknya; lihat G12.
     */
    public function pakaiKoreksiTermalDiSertifikat(): bool
    {
        return true;
    }

    /**
     * Dari kolom `Calibration Method` sertifikat masternya.
     *
     * Nomor IK-nya SAMA dengan Load Cell (`0514`) — dua alat berbagi satu
     * instruksi kerja, dan itu masuk akal karena keduanya transduser gaya.
     * Revisinya yang beda: workbook menyebut Rev.5, sementara lembar kerja
     * Load Cell yang ada kertasnya menyebut Rev.3. Lembar kerja Proving Ring
     * sendiri BELUM ketemu, jadi yang dipakai satu-satunya sumber yang ada.
     */
    public function kodeMetodeIk(): string
    {
        return 'SIDIK-IK-CAL-0514_Rev.5';
    }

    /**
     * BELUM ada nomor formulirnya, dan itu sudah diperiksa.
     *
     * Sapuan `SIDIK-FM-` di seluruh `Gaya_Proving_Ring/` memulangkan NOL — yang
     * ada cuma daftar nomor IK. Dua alat gaya lain punya kertasnya
     * (`SIDIK-FM-CAL-0519` & `0520`); yang ini tidak, jadi dia masuk
     * `belumAdaKertasnya` di `SemuaProfilLembarKerjaTest` dengan bukti itu.
     */
    protected function kodeDokumen(): ?string
    {
        return null;
    }

    protected function judulLembar(): string
    {
        return 'Lembar Kerja Kalibrasi Proving Ring';
    }

    protected function sumberMaster(): string
    {
        return 'Master Olah Data Gaya — Proving Ring (.xlsm, PERHITUNGAN FC & PERHITUNGAN U95%)';
    }

    protected function catatanPengisian(): string
    {
        return 'Tiap titik beban diisi ENAM kali: UP tiga kali lalu DOWN tiga kali. Arahnya bukan '
            .'pengulangan biasa — cincin baja punya histeresis, jadi bacaan saat beban NAIK memang '
            .'berbeda dari saat TURUN, dan perbedaannya itu yang diukur. Pembacaan diisi dalam '
            .'DIVISI dial, bukan kgf. Preload dan empat pengukuran misalignment diisi sekali per sesi.';
    }

    /** Master mencetak `Calibration Factor`, bukan `Correction`. */
    public function judulKolomKoreksi(): string
    {
        return 'Calibration Factor';
    }

    /** Sebarannya RSD (dibagi rata-rata pembacaan), bukan RRPE (dibagi beban). */
    public function judulKolomSebaran(): ?string
    {
        return 'Repeatability';
    }

    public function judulKolomUut(): string
    {
        return 'Unit Under Test (1 Div)';
    }

    /**
     * Kolom UUT jumlah DIVISI dial — bukan gaya.
     *
     * Ikut dibagi faktor kgf, `237,49` divisi tercetak `24.209` dan berlabel
     * kgf. Angka yang tidak berarti apa-apa, di kolom yang kelihatan wajar.
     */
    public function uutIkutSatuanAlat(): bool
    {
        return false;
    }

    /**
     * Dua desimal untuk kolom gaya.
     *
     * Dial 25 mm beresolusi 0,002 mm berarti 12.500 divisi untuk 500 kgf —
     * satu divisi ≈ 0,04 kgf. Dua desimal memuat itu; tiga mengaku ketelitian
     * yang dialnya tidak punya.
     */
    public function desimalSertifikat(): ?int
    {
        return 2;
    }

    public function desimalU95(): ?int
    {
        return 2;
    }

    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /**
     * LIMA desimal untuk kolom faktor kalibrasi, bukan dua.
     *
     * Faktornya berorde 0,126 kgf/Div. Di dua desimal yang dipakai kolom gaya
     * di sebelahnya dia jadi `0,13` — kehilangan tiga angka penting, dan
     * faktor kalibrasi justru satu-satunya angka yang dipakai pelanggan untuk
     * mengubah bacaan dialnya jadi gaya.
     */
    public function desimalKolomKoreksi(): ?int
    {
        return 5;
    }

    /** Sama dengan dua alat gaya lain — lihat docblock `UtmProfile`. */
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
     * Identitas alat + SPESIFIKASI DIAL.
     *
     * Dua kotak terakhir tidak ada di alat gaya lain, dan bukan kelengkapan
     * administratif: komponen `daya baca alat` di budget lahir dari
     * `resolusi_dial / kapasitas_dial`, bukan dari resolusi gaya. Kosong,
     * komponen itu jadi nol dan U95 yang tercetak lebih kecil dari yang
     * seharusnya.
     *
     * `resolusi_uut` induknya TIDAK dipakai Proving Ring — yang dibaca dial,
     * bukan gaya — jadi kotaknya diganti, bukan ditambahi.
     *
     * @return array<string, mixed>
     */
    protected function bagianIdentitasAlat(): array
    {
        $bagian = parent::bagianIdentitasAlat();

        $bagian['field'] = array_values(array_filter(
            $bagian['field'],
            static fn (array $f): bool => ($f['kode'] ?? null) !== 'spesifikasi_alat.gaya.resolusi_uut',
        ));

        $bagian['field'][] = $this->field(
            'spesifikasi_alat.gaya.kapasitas_dial_mm',
            'Kapasitas Dial (mm)',
            'angka',
        );
        $bagian['field'][] = $this->field(
            'spesifikasi_alat.gaya.resolusi_dial_mm',
            'Resolusi Dial (mm)',
            'angka',
        );

        return $bagian;
    }

    /**
     * DUA tabel, bukan empat: UP lalu DOWN.
     *
     * Arahnya bukan label kosmetik. Cincin baja punya histeresis — bacaan saat
     * beban NAIK berbeda dari saat TURUN pada beban yang sama — dan itulah yang
     * diukur. Digabung jadi satu tabel enam kolom, arah tiap bacaan hilang dari
     * baris mentahnya, dan lembar yang dibuka ulang tidak tahu angka mana milik
     * kotak mana.
     *
     * `offset_kunci` beda per tabel supaya HP tidak berbagi kotak isian, sama
     * pola dengan empat tabel posisi di induknya.
     *
     * @return array<string, mixed>
     */
    protected function bagianMeasurement(): array
    {
        $baris = static fn (): array => array_map(
            static fn (int $n): array => [
                'nomor' => $n,
                // `null` membuka kotak Nominal untuk diketik: titik bebannya
                // ditentukan teknisi sesuai kapasitas cincin.
                'titik_ukur' => null,
                'label' => 'Titik '.$n,
            ],
            range(1, 14),
        );

        $tabel = static fn (string $peran, int $offset, string $judul): array => [
            'tahap' => 'sesudah_adjustment',
            'grup' => $peran,
            'offset_kunci' => $offset,
            'judul' => $judul,
            'judul_nilai' => 'Nominal',
            'judul_pengulangan' => 'Replikat ke',
            'titik_bisa_diubah' => false,
            'simpan_ke' => 'measurements[].'.$peran,
            'baris' => $baris(),
            'kolom' => [
                // DIVISI, bukan gaya. Label yang menyebut satuan gaya di sini
                // membuat teknisi mengetik kgf ke kotak yang dihitung sebagai
                // divisi — dan angkanya tetap masuk akal sampai sertifikatnya
                // terbit.
                ['kode' => 'pembacaan', 'label' => 'Pembacaan Dial (Div)', 'tipe' => 'angka'],
            ],
            'pengulangan' => range(1, M::REPLIKAT),
        ];

        return [
            'kode' => 'hasil',
            'halaman' => 2,
            'judul' => 'Accuracy Test',
            'field' => [],
            'tabel' => [
                $tabel(M::PERAN_UP, 1000, 'a. Beban NAIK (UP)'),
                $tabel(M::PERAN_DOWN, 2000, 'b. Beban TURUN (DOWN)'),
            ],
        ];
    }

    /**
     * Rantai Proving Ring, dari enam bacaan divisi sampai faktor kalibrasi.
     *
     * Ditulis penuh, bukan memanggil induknya, karena yang berbeda bukan satu
     * langkah melainkan seluruh sumbunya: pembacaannya divisi, keluarannya
     * faktor, budget-nya enam komponen, dan koreksi suhunya dua lapis.
     *
     * @param  list<array<string, mixed>>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array<string, mixed>>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $konteksSesi = $titik[0]['konteks'] ?? [];
        $blok = M::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi gaya belum punya blok `spesifikasi_alat.'.M::KUNCI_SESI.'` yang lengkap.',
                ], $titik),
            ];
        }

        $penghalang = $this->penghalangSesi($blok, $konteksSesi);

        if ($penghalang !== null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => $penghalang,
                ], $titik),
            ];
        }

        $temuanSesi = $this->temuanSesi($blok, $konteksSesi, $titik);

        $arah = $blok['tipe_beban'] ?? M::ARAH_PUSH;
        $standarGaya = TabelStandarGaya::standar((string) $blok['standar']);
        $suhuSertifikat = $blok['suhu_sertifikat_standar']
            ?? (float) ($standarGaya['suhu_sertifikat'] ?? 0.0);

        $suhuRuang = self::rataSuhuRuang(
            $konteksSesi['suhu_awal'] ?? null,
            $konteksSesi['suhu_akhir'] ?? null,
        ) ?? (float) $suhuSertifikat;

        $hasilTitik = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            $k = $t['konteks'] ?? [];
            $bacaan = array_map('floatval', $k['bacaan'] ?? []);
            $nominal = (float) ($t['titik_ukur'] ?? 0);

            if ($bacaan === []) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Titik ini belum punya satu pun pembacaan.',
                ];

                continue;
            }

            if (count($bacaan) !== $this->jumlahBacaanWajib()) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %s baru terisi %d dari %d pembacaan (UP %d + DOWN %d). Divisor '
                        .'pengulangan di budget mengasumsikan %d bacaan.',
                        $nominal,
                        count($bacaan),
                        $this->jumlahBacaanWajib(),
                        M::REPLIKAT,
                        M::REPLIKAT,
                        $this->jumlahBacaanWajib(),
                    ),
                ];

                continue;
            }

            // Set point DALAM kN. Nominalnya diketik teknisi dalam satuan alat
            // (kgf), dan konversinya dilakukan sekali di sini — bukan di
            // kalkulator, yang sengaja menerima kN supaya rantainya tidak
            // punya dua tempat yang bisa salah satuan.
            $setPointKn = GayaCalculator::keKn($nominal, (string) $blok['satuan']);

            // Dua argumen suhu terakhir SENGAJA sama, jadi koreksi termalnya
            // tepat 1 dan `Z = Y`.
            //
            // Itu yang dilakukan master, dan sebabnya bukan kebetulan: lembar
            // Proving Ring TIDAK punya field "actual temperature of standard"
            // yang dipunyai lembar UTM. Pengurangnya kosong, hasilnya nol, dan
            // `Z` sama persis dengan `Y` di kesembilan barisnya — diperiksa
            // satu per satu, bukan disimpulkan dari satu baris.
            //
            // Jadi "koreksi suhu ganda" yang dicatat G6 memang ADA di rumusnya
            // tapi tidak pernah menggigit di workbook ini. Yang benar-benar
            // bekerja cuma faktor ruangan `G52`. Kalau suatu saat lab menambah
            // field itu, yang berubah satu argumen di sini.
            $h = GayaCalculator::hitungTitikProvingRing(
                $setPointKn,
                $bacaan,
                (string) $blok['standar'],
                (string) $arah,
                (float) $suhuRuang,
                (float) $suhuSertifikat,
                (float) $suhuSertifikat,
            );

            if ($h['W'] === null) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => implode(' ', $h['temuan']),
                ];

                continue;
            }

            $h['titik_ke'] = (int) $t['titik_ke'];
            $h['nominal'] = $nominal;
            $h['jumlah_bacaan'] = count($bacaan);
            $hasilTitik[] = $h;
        }

        if ($hasilTitik === []) {
            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        // RSD terbesar antar titik. Titik yang RSD-nya null (dial tidak
        // bergerak) TIDAK ikut — memasukkannya sebagai nol menurunkan budget
        // justru waktu datanya paling meragukan.
        $rsdMaks = 0.0;
        foreach ($hasilTitik as $h) {
            if ($h['L'] !== null) {
                $rsdMaks = max($rsdMaks, (float) $h['L']);
            }
        }

        $rentangKn = GayaCalculator::keKn(
            (float) ($blok['kapasitas'] ?? 0),
            (string) $blok['satuan'],
        );

        $budget = GayaCalculator::komponenBudgetProvingRing(
            $blok,
            $rsdMaks,
            $rentangKn,
            (float) ($standarGaya['u95_persen'] ?? 0.0),
            TabelStandarGaya::drift((string) $blok['standar'], (string) $arah, $this->sumberDrift()),
        );

        // Cuma yang `dijumlahkan` yang masuk agregasi. Dua sisanya dihitung
        // dan disimpan di jejak audit — lihat docblock kelas, butir G8.
        $agregat = $this->gum()->agregasiBudget($budget['dijumlahkan']);

        $uPersen = (float) $agregat['ketidakpastian_diperluas'];
        $uKn = $rentangKn > 0.0 ? $uPersen / 100 * $rentangKn : 0.0;
        $cmcKn = $this->cmcKn($equipment, $blok, (string) $arah);
        $u95 = max($uKn, $cmcKn ?? 0.0);

        $sekarang = Carbon::now();
        $hitungan = [];
        $typeA = 0.0;

        foreach ($budget['dijumlahkan'] as $komponen) {
            if (($komponen['sumber'] ?? null) === 'pengulangan') {
                $typeA = (float) $komponen['u'];
            }
        }

        $uc = (float) $agregat['ketidakpastian_gabungan'];

        foreach ($hasilTitik as $h) {
            $hitungan[] = [
                'titik_ke' => $h['titik_ke'],
                // NILAI ACUAN, dalam kN — inilah yang dicetak di kolom
                // `Standard Value`. Buat dua puluh satu alat lain `titik_ukur`
                // memang berarti itu; Proving Ring ikut arti yang sama, bukan
                // menyimpan nominal yang tidak pernah dicetak.
                'titik_ukur' => $h['Z'],
                // Kolom `Unit Under Test`: rata-rata pembacaan DIAL sesudah
                // koreksi ruangan, dalam DIVISI. Tidak ikut konversi satuan —
                // lihat [uutIkutSatuanAlat].
                'rata_rata' => $h['J'],
                // Kolom ketiga: FAKTOR, bukan koreksi. `error` diisi lawan
                // tandanya semata-mata untuk memenuhi kesepakatan repo
                // (`koreksi = -error`) yang ditegakkan validator; di alat ini
                // dia tidak punya arti fisik, dan itu ditulis di sini supaya
                // tidak dibaca sebagai "seberapa meleset".
                // Titik nol TIDAK punya faktor kalibrasi — pembaginya nol,
                // dan master mencetaknya `-`, bukan `0`. Kolomnya `NOT NULL`
                // di database, jadi yang tersimpan penampung `0.0`.
                //
                // Yang menentukan apa yang DICETAK bukan angka penampung itu,
                // melainkan `CF_faktor_kn_per_divisi` di jejak audit yang tetap
                // `null`. `CertificateSnapshotBuilder` membacanya dan mencetak
                // `-`. Tanpa pemisahan itu, sertifikat menerbitkan "0 kgf per
                // divisi" — pernyataan yang salah dan kelihatan wajar, persis
                // kelas kesalahan yang dilarang AGENTS.md.
                'error' => $h['CF'] === null ? 0.0 : -$h['CF'],
                'koreksi' => $h['CF'] ?? 0.0,
                'standar_deviasi' => $h['K'],
                'jumlah_pengulangan' => $h['jumlah_bacaan'],
                'type_a' => $typeA,
                'type_b_components' => $this->jejakAuditProvingRing(
                    $h, $budget, $agregat, $uKn, $cmcKn, $u95, (string) $arah, $blok, $temuanSesi,
                ),
                'type_b' => sqrt(max(0.0, $uc ** 2 - $typeA ** 2)),
                'ketidakpastian_gabungan' => $uc,
                'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $u95,
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $this->kodeMetodeIk(),
                'calculated_at' => $sekarang,
            ];
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * @param  array<string, mixed>  $h
     * @param  array{dijumlahkan: list<array<string, mixed>>, di_luar_jumlah: list<array<string, mixed>>}  $budget
     * @param  array<string, mixed>  $agregat
     * @param  array<string, mixed>  $blok
     * @param  list<string>  $temuanSesi
     * @return array<string, mixed>
     */
    private function jejakAuditProvingRing(
        array $h,
        array $budget,
        array $agregat,
        float $uKn,
        ?float $cmcKn,
        float $u95,
        string $arah,
        array $blok,
        array $temuanSesi,
    ): array {
        return [
            'komponen' => $budget['dijumlahkan'],
            // Dua komponen yang DIHITUNG tapi tidak ikut dijumlahkan master.
            // Disimpan lengkap supaya orang yang menyetujui sesi bisa melihat
            // kontribusi yang hilang tanpa membuka kode — itu yang diminta
            // AGENTS.md §Olah data butir 4.
            'komponen_di_luar_jumlah' => $budget['di_luar_jumlah'],
            'gaya_rantai' => [
                'B_set_point_kn' => $h['B'],
                'J_rata_divisi' => $h['J'],
                'K_stdev_divisi' => $h['K'],
                'L_rsd_persen' => $h['L'],
                'W_koreksi_standar_kn' => $h['W'],
                'Y_terkoreksi_kn' => $h['Y'],
                'Z_terkoreksi_termal_kn' => $h['Z'],
                'CF_faktor_kn_per_divisi' => $h['CF'],
                'set_point_standar_kn' => $h['set_point_standar_kn'],
            ],
            'gaya_budget' => [
                'u_persen' => $agregat['ketidakpastian_diperluas'],
                'u_kn' => $uKn,
                'cmc_kn' => $cmcKn,
                'u95_kn' => $u95,
                'lantai_cmc_menang' => $cmcKn !== null && $cmcKn > $uKn,
            ],
            'gaya_standar' => [
                'kunci' => $blok['standar'],
                'arah' => $arah,
                'sumber_drift' => $this->sumberDrift(),
                'drift_semua_workbook' => TabelStandarGaya::driftSemuaSumber(
                    (string) $blok['standar'],
                    $arah,
                ),
            ],
            'penyimpangan_master' => [
                'enam_dari_delapan_komponen' => 'Master cuma menjumlahkan ENAM dari delapan komponen '
                    .'budget-nya: zero error dan misalignment dihitung tapi berada di luar '
                    .'penjumlahannya. Terbukti dari angka — jumlah master cocok NOL BEDA dengan enam '
                    .'komponen pertama. Direplikasi apa adanya; keduanya tersimpan di '
                    .'`komponen_di_luar_jumlah` di atas. Pertanyaan lab bernomor G8.',
                'koreksi_standar_tidak_ditelan' => 'Master mencari koreksi standar lewat VLOOKUP yang gagal '
                    .'di setiap baris lalu menelannya jadi sel kosong yang dibaca NOL. Di sini pencariannya '
                    .'dijalankan sungguhan. Dengan standar yang disebut workbook (3000 kN) hasilnya kebetulan '
                    .'sama — nol — karena satu-satunya baris di bawah 300 kN adalah baris nol; bedanya '
                    .'sekarang keadaannya kebaca sebagai temuan, bukan diam. Pertanyaan lab bernomor G11.',
                'koreksi_suhu_ganda' => 'Rantai ini memakai DUA koreksi suhu: faktor ruangan '
                    .'1 + 0,00027 x (23 - T_ruangan) dan koreksi termal terhadap suhu sertifikat '
                    .'kalibrator. Ditiru apa adanya; pertanyaan lab bernomor G6.',
                'drift_tidak_dibagi_divisor' => 'Master mengambil U drift langsung jadi ui tanpa dibagi '
                    .'akar 3, padahal kolom divisornya terisi — di KETIGA workbook. Pertanyaan lab G3.',
            ],
            'temuan' => $h['temuan'],
            'temuan_sesi' => $temuanSesi,
        ];
    }
}
