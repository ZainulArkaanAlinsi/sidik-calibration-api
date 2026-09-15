<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\AnakTimbanganCalculator;
use App\Services\Calibration\TabelStandarAnakTimbangan;
use App\Support\AnakTimbanganMentah;
use Illuminate\Support\Carbon;

/**
 * Kalibrasi **Anak Timbangan** (OIML R111) — alat ke-29, dan yang KEDUA di
 * kelompok Massa sesudah Timbangan (alat ke-21).
 *
 * Sumbernya `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx` dan kertas
 * `SIDIK-FM-CAL-0541_Rev.0 - LEMBAR KERJA ANAK TIMBANGAN (Non KAN)`.
 * Verifikasi angkanya di `AnakTimbanganCalculator` dan
 * `tests/Unit/AnakTimbanganMasterTest.php` — 1011 pengaduan, nol beda.
 *
 * ## Di luar lampiran akreditasi, dan itu tertulis di kertasnya sendiri
 *
 * Kelompok **Massa** di lampiran LK-285-IDN cuma memuat satu baris: no. 12,
 * *"Timbangan (Elektronik, mekanik)"*. Kalibrasi anak timbangan tidak ada di
 * situ. Nama formulirnya sendiri sudah menyatakannya — **(Non KAN)** — jadi
 * [dalamLingkupAkreditasi] balik `false` bukan sebagai tafsiran.
 *
 * Perlakuannya mengikuti preseden Height Gauge (alat ke-26):
 *
 *  1. [namaAlatKemampuan] tetap `Anak Timbangan`, dan namanya masuk
 *     `CmcSemuaProfilTest::DILUAR_LAMPIRAN` berikut alasannya — bukan dipaksa
 *     cocok ke baris lampiran yang ada.
 *  2. Baris kemampuan tetap DIBUAT (`AnakTimbanganCapabilitySeeder`, CMC nol),
 *     karena tanpa baris itu jalur budget penuh tidak jalan dan `GumCalculator`
 *     jatuh ke `hitungDariStandarDanResolusi()`.
 *  3. [bentukLembarKerja] TIDAK memasang `nomor_lingkup`.
 *
 * Yang bikin butir ini tidak cuma administratif: kop kertas Rev.0 **tetap
 * mencetak `LK-285-IDN`** padahal nama berkasnya menyebut (Non KAN). Satu
 * lembar, dua pernyataan yang saling meniadakan. Pertanyaan lab §15.
 *
 * ## Jebakan routing yang diuji duluan
 *
 * `'Anak Timbangan'` memuat substring `'Timbangan'`, dan `TimbanganProfile`
 * (alat ke-21) sudah mengklaim ejaan itu lewat `aliasNama()`. Yang
 * menyelamatkan: `CalibrationProfileRegistry::bangunIndeksEjaan()` mengurut
 * kunci dari yang PALING PANJANG, jadi `anak timbangan` (14 huruf) dicoba
 * sebelum `timbangan` (9). Kalau urutan itu pernah dicabut, seluruh sesi anak
 * timbangan mendarat di lembar Timbangan — bentuk lembar yang sah, alat yang
 * salah, nol error. `ProfilDariNamaAlatTest` menjaganya dari kedua arah.
 *
 * ## Bentuk lembarnya mengikuti KERTAS, bukan workbook
 *
 * Keduanya berbeda, dan bedanya nyata:
 *
 * | | Kertas Rev.0 | Workbook |
 * |---|---|---|
 * | Jumlah keping | 10 | 20 |
 * | Pembacaan per baris ABBA | **3** (`X1 X2 X3`) | 1 |
 * | Tekanan udara | **tidak ada kolomnya** | ada, dan masuk hitungan |
 * | Meter lingkungan | `TH-3` | `Thermobarometer` |
 * | Neraca tercetak | 2 | 5 |
 *
 * Yang dipakai teknisi kertasnya, jadi tabelnya sepuluh baris × tiga
 * pengulangan × empat peran. Tapi tekanan udara TETAP diminta walau kertasnya
 * tidak punya kolomnya — tanpa tekanan, densitas udara tidak bisa dihitung dan
 * koreksi apung seluruh keping hilang. Pertanyaan lab §21, §22, §23.
 */
class AnakTimbanganProfile extends CalibrationProfile
{
    /** Satuan massa lembar ini — gram, apa adanya seperti master. */
    public const SATUAN = 'g';

    /** Sepuluh blok keping di kertas `SIDIK-FM-CAL-0541_Rev.0`. */
    public const BARIS_KERTAS = 10;

    /** Tiga kolom `X1 X2 X3` tiap baris Standard/UUT/UUT/Standard. */
    public const PENGULANGAN = AnakTimbanganMentah::PENGULANGAN_KERTAS;

    /** Nomor Instruksi Kerja, dari `INPUT DATA` & sertifikat master. */
    public const KODE_METODE = 'SIDIK-IK-CAL-0535_Rev.0';

    /** Nomor formulir lembar kerjanya, dari nama berkas kertasnya. */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0541_Rev.0';

    /**
     * Offset kunci tiap tabel peran.
     *
     * Keempatnya ber-`tahap` SAMA dan berbaris nominal sama, jadi tanpa offset
     * kunci barisnya bertabrakan DI LAYAR: angka yang diketik di kotak `S1`
     * muncul di kotak `T1`, tanpa satu pun error. Sudah nyata di Timbangan dan
     * Height Gauge.
     */
    public const OFFSET = [
        AnakTimbanganMentah::PERAN_S1 => 0,
        AnakTimbanganMentah::PERAN_T1 => 1000,
        AnakTimbanganMentah::PERAN_T2 => 2000,
        AnakTimbanganMentah::PERAN_S2 => 3000,
    ];

    /**
     * Standar yang TERCETAK di sertifikat master (`SERTIFIKAT` bagian
     * `2. STANDARD USED`) — lima neraca dan tujuh set anak timbangan.
     *
     * Kertas Rev.0 cuma mencetak dua neraca; yang dipakai di sini daftar
     * sertifikatnya, karena itu yang harus muncul di dokumen pelanggan. Kertas
     * dan sertifikat yang tidak sinkron diangkat sebagai pertanyaan lab §22.
     *
     * `cocok` dipakai `tautkanStandarTercetak()` mencari baris `Standard` yang
     * sudah ada di master data. Yang tidak ketemu tampil apa adanya sebagai
     * baris tercetak tanpa tautan — bukan hilang.
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Semi Micro Balance — OHAUS PIONEER/PX85', 'cocok' => ['Semi Micro Balance', 'PX85', 'C543502629']],
        ['label' => 'Analytical Balance — Mettler Toledo/XS204', 'cocok' => ['Analytical Balance', 'XS204', '1129063525']],
        ['label' => 'Electronic Balance Fujitsu — Fujitsu/FSR-A', 'cocok' => ['Electronic Balance Fujitsu', 'FSR-A', 'SIDIK/134/2024']],
        ['label' => 'Electronic Balance Excellent — Excellent/DJ', 'cocok' => ['Electronic Balance Excellent', 'Excellent/DJ', 'HSEX1403752']],
        ['label' => 'Electronic Balance Mettler — Mettler Toledo/IND690', 'cocok' => ['Electronic Balance  Mettler', 'IND690', '3127471']],
        ['label' => 'Anak Timbangan E2 200 g — Accurate/Stainless', 'cocok' => ['Anak Timbangan E2 200 g', '75870']],
        ['label' => 'Anak Timbangan E2 500 g — Accurate/Stainless', 'cocok' => ['Anak Timbangan E2 500 g', '100636']],
        ['label' => 'Anak Timbangan E2 1 kg — Want Ballance/E2', 'cocok' => ['Anak Timbangan E2 1 kg', '120015']],
        ['label' => 'Anak Timbangan F1-2 — Accurate/F1', 'cocok' => ['Anak Timbangan F1-2', '45723']],
        ['label' => 'Anak Timbangan F1-5 — Accurate/F1', 'cocok' => ['Anak Timbangan F1-5', '111962']],
        ['label' => 'Anak Timbangan F1-10 — Excellent/F1', 'cocok' => ['Anak Timbangan F1-10', '4321']],
        ['label' => 'Anak Timbangan F1-20 — Want Ballance/F1', 'cocok' => ['Anak Timbangan F1-20', '130016']],
    ];

    /**
     * Meter lingkungan yang ditawarkan.
     *
     * `Thermobarometer` dipakai sesi contoh master DAN satu-satunya yang tabel
     * koreksinya rekonsiliasi (lihat
     * `TabelStandarAnakTimbangan::meterLingkungan`). Ketujuh `TH-*` ikut karena
     * kertas Rev.0 mencetak `TH-3` di kopnya — kalau teknisi memakai yang itu,
     * dropdown-nya harus bisa menyebutkannya, walau tabel koreksinya belum ada.
     */
    public const THERMOHYGRO_TERCETAK = [
        'Thermobarometer', 'TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7',
    ];

    private ?AnakTimbanganCalculator $kalk = null;

    public function kode(): string
    {
        return 'anak_timbangan';
    }

    /**
     * `Anak Timbangan` — dan ini SENGAJA tidak dicocokkan ke baris lampiran
     * akreditasi mana pun, karena kalibrasi anak timbangan memang tidak ada di
     * LK-285-IDN. Namanya masuk `CmcSemuaProfilTest::DILUAR_LAMPIRAN`.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Anak Timbangan';
    }

    /**
     * Ejaan yang datang dari pelanggan.
     *
     * `Batu Timbangan` memuat `Timbangan` yang sudah diklaim `TimbanganProfile`,
     * dan yang menyelamatkannya urutan terpanjang-duluan di indeks ejaan —
     * bukan urutan baris di `daftarProfil()`. Jangan menambahkan ejaan yang
     * lebih PENDEK dari `timbangan` ke sini tanpa mengadu ulang
     * `ProfilDariNamaAlatTest`.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Weight Set', 'Test Weight', 'Batu Timbangan', 'Anak Timbangan OIML', 'Standard Weight'];
    }

    public function kodeFormula(): string
    {
        return 'gum-anak-timbangan';
    }

    /**
     * `massa`, mengikuti nama kelompok pengukuran lampiran akreditasi — sama
     * seperti Timbangan. Alat ini memang belum diakreditasi, tapi KELOMPOKNYA
     * ada dan alatnya jelas milik kelompok itu; membuat kategori baru cuma
     * melahirkan kategori hantu di `GET /api/categories` yang isinya nol
     * kemampuan.
     */
    public function besaran(): string
    {
        return 'massa';
    }

    public function kodeMetode(): ?string
    {
        return self::KODE_METODE;
    }

    /** Lihat docblock kelas — kertasnya sendiri menyebut (Non KAN). */
    public function dalamLingkupAkreditasi(): bool
    {
        return false;
    }

    /**
     * Satu titik = EMPAT penimbangan ber-peran, bukan satu deret.
     *
     * Alasan lengkapnya di `CalibrationProfile::butuhBlokAnakTimbangan()`.
     * Singkatnya: `de = (T1 − S1 − S2 + T2) / 2` memberi tanda berbeda ke tiap
     * suku, jadi deret datar tidak cuma kehilangan presisi — dia bisa
     * membalikkan ARAH koreksi kepingnya.
     */
    public function butuhBlokAnakTimbangan(): bool
    {
        return true;
    }

    /**
     * Sesi Anak Timbangan TIDAK divonis PASS/FAIL.
     *
     * Tabel MPE OIML R111 lengkap ada di workbook master, tapi sertifikatnya
     * terbit tanpa kolom lulus/tidak lulus. Menambahkannya sendiri berarti
     * mencetak vonis yang lab belum pernah membuatnya, dan dengan U95 sekarang
     * **keping di bawah 1 g tidak akan pernah bisa dinyatakan lulus** (U95
     * 0,1223 mg lawan MPE 0,05 mg di 0,1 g). Pertanyaan lab §10 & §12.
     *
     * MPE-nya sendiri TETAP dipakai — sebagai gerbang penolakan `|de|`, yang
     * menahan salah ketik, bukan memvonis alat pelanggan.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /**
     * Delapan desimal gram.
     *
     * Bukan kemewahan: massa konvensional keping 0,1 g di sertifikat master
     * tercetak `0,09884965` dan U95-nya 0,1223 **mg** = 0,0001223 g. Di lima
     * desimal, U95 seluruh lembar runtuh jadi `0,00012` dan kehilangan angka
     * pentingnya; di tiga desimal dia jadi `0,000` — klaim pengukuran sempurna.
     *
     * Sertifikat master mencetak ketidakpastiannya dalam MILIGRAM sementara
     * massanya dalam gram. Di sini keduanya gram, jadi satu baris tidak memuat
     * dua satuan. Angkanya sama, penyajiannya beda — dicatat di
     * `docs/perintah-frontend-anak-timbangan.md`.
     */
    public function desimalSertifikat(): ?int
    {
        return 8;
    }

    public function desimalU95(): ?int
    {
        return 8;
    }

    /** Kolom hasilnya massa konvensional, bukan "pembacaan alat". */
    public function judulKolomUut(): string
    {
        return 'Conventional Mass';
    }

    /**
     * `kolom_suhu = false`: suhu diisi SEKALI per sesi, tidak per baris tabel.
     *
     * `didukung = false`: geometri di
     * `database/ocr-templates/anak_timbangan-v1.json` masih grid rata hasil
     * generator dan belum pernah diadu ke foto formulir yang benar-benar
     * terisi. Membuka jalur kamera dengan geometri yang belum diadu berarti
     * pembaca foto memungut sel yang salah — dan yang balik bukan error
     * melainkan angka wajar di baris yang keliru. Di lembar ini akibatnya lebih
     * buruk daripada di alat lain: empat peran ABBA yang tertukar membalik
     * TANDA koreksi kepingnya.
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
     * Ketidakpastian alat ini lahir per SESI — densitas udara dan neraca sama
     * untuk seluruh keping. `null` di sini bukan "belum ditulis": jalur per
     * titik memang tidak ada bentuknya.
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
     * Sampai dua puluh keping, satu budget per keping, satu densitas udara per
     * sesi.
     *
     * Titik yang tidak bisa dihitung dilaporkan lewat `belum_dihitung`, tidak
     * dibuang diam-diam — itu bedanya dari `IFERROR(…;"")` master, yang membuat
     * titiknya terbit sebagai `#VALUE!` di sertifikat pelanggan.
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
        // DISAPU, bukan diambil dari `$titik[0]`: jalur hitung ulang
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

        $blok = AnakTimbanganMentah::blokSesi($konteksSesi['spesifikasi_alat'] ?? null);

        if ($blok === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Sesi Anak Timbangan belum punya blok tingkat-sesi di `spesifikasi_alat.'
                        .AnakTimbanganMentah::KUNCI_SESI.'` — kelas OIML, neraca, meter lingkungan, dan '
                        .'keenam ujung kondisi ruangan lahir di situ. Tanpa suhu, kelembaban, dan '
                        .'tekanan, densitas udara tidak bisa dihitung dan koreksi apung SELURUH keping '
                        .'hilang.',
                ], $titik),
            ];
        }

        $masukan = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            // Keempat peran datang lewat `konteks`, bukan level atas — jalur
            // simpan dan jalur hitung ulang sama-sama menaruhnya di situ,
            // persis seperti tumpukan Micrometer dan blok Timbangan.
            $k = $t['konteks'] ?? [];

            $punya = false;

            foreach (AnakTimbanganMentah::PERAN_URUT as $peran) {
                if (isset($k[$peran])) {
                    $punya = true;
                }
            }

            // Sesi yang baris mentahnya belum ber-`peran_sensor` DITOLAK dengan
            // alasan yang kebaca, bukan diam-diam dihitung dari `pembacaan`
            // datar. Deret datar tidak bisa dibedakan mana penimbangan standar
            // mana penimbangan UUT, dan `de` yang lahir dari situ bisa terbalik
            // TANDANYA.
            if (! $punya) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d nggak punya baris ber-peran `%s`. Lembar Anak Timbangan menyimpan '
                        .'substitusi ganda ABBA — deret datar nggak bisa dipakai karena tanda tiap '
                        .'sukunya beda.',
                        $t['titik_ke'],
                        implode('`/`', AnakTimbanganMentah::PERAN_URUT),
                    ),
                ];

                continue;
            }

            $masukan[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'nominal_g' => (float) $t['titik_ukur'],
                'at_s1' => $k[AnakTimbanganMentah::PERAN_S1] ?? null,
                'at_t1' => $k[AnakTimbanganMentah::PERAN_T1] ?? null,
                'at_t2' => $k[AnakTimbanganMentah::PERAN_T2] ?? null,
                'at_s2' => $k[AnakTimbanganMentah::PERAN_S2] ?? null,
                'standard' => $t['standard'] ?? null,
            ];
        }

        $hasil = $this->kalk()->hitungSesi(
            array_map(static fn (array $m): array => [
                'titik_ke' => $m['titik_ke'],
                'nominal_g' => $m['nominal_g'],
                'at_s1' => $m['at_s1'],
                'at_t1' => $m['at_t1'],
                'at_t2' => $m['at_t2'],
                'at_s2' => $m['at_s2'],
            ], $masukan),
            $blok,
        );

        $standarPerTitik = [];

        foreach ($masukan as $m) {
            $standarPerTitik[$m['titik_ke']] = $m['standard'];
        }

        $kemampuan = $this->kemampuanSesi($equipment);
        $sekarang = Carbon::now();
        $hitungan = [];

        // Prasyarat sesi yang tidak utuh TIDAK melahirkan satu pun baris.
        //
        // Ini bukan kerapian. Menerbitkan baris ber-`ketidakpastian_diperluas`
        // nol berarti sertifikatnya mencetak `± 0,000` — klaim pengukuran
        // SEMPURNA. Peringatan sesi tidak menahannya:
        // `CalibrationValidator::periksaPeringatanProfil()` membungkus
        // [peringatanSesi] jadi temuan tingkat PERINGATAN yang boleh dilewati
        // admin lewat `abaikan_peringatan`, jadi yang menahan harus ketiadaan
        // barisnya, bukan pesannya.
        //
        // Di alat ini gerbangnya lebih menentukan daripada di Micrometer: di
        // sana lantai CMC menampung budget yang kehilangan komponen, di sini
        // tidak ada apa pun yang menampung MAUPUN menahan (pertanyaan lab §16).
        if (! $hasil['boleh_terbit']) {
            foreach ($hasil['ditolak'] as $d) {
                $belumDihitung[] = $d;
            }

            usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        foreach ($hasil['titik'] as $h) {
            $hitungan[] = [
                // Null-safe: sesi yang standarnya di-soft-delete memulangkan
                // null, dan tanpa `?->` perintah hitung ulang mati total.
                'standard_id' => ($standarPerTitik[$h['titik_ke']] ?? null)?->id,
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['nominal_g'],
                // Yang dicetak sertifikat massa KONVENSIONAL kepingnya, bukan
                // rata-rata pembacaan neraca.
                'rata_rata' => $h['mt_g'],
                'error' => $h['mt_g'] - $h['nominal_g'],
                'koreksi' => -($h['mt_g'] - $h['nominal_g']),
                'standar_deviasi' => $h['simpangan_baku_g'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                // Type A dari keterulangan NERACA, bukan sebaran kedua
                // penimbangan titik ini — lihat `AnakTimbanganCalculator`.
                'type_a' => $h['type_a_g'],
                'type_b_components' => $this->jejakAudit($hasil, $h),
                'type_b' => $h['type_b_g'],
                'ketidakpastian_gabungan' => $h['ketidakpastian_gabungan_g'],
                'faktor_cakupan_k' => $h['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $h['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $h['u95_g'],
                'toleransi' => null,
                'keputusan' => null,
                'metode' => $kemampuan?->metode ?? self::KODE_METODE,
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
     * Peringatan sesi.
     *
     * Tiga hal yang tidak boleh lewat diam-diam, dan ketiganya BUKAN yang
     * menahan sesinya — yang menahan ketiadaan baris hitungan di
     * [hitungPerGrup]; pesan ini tugasnya menjelaskan KENAPA.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $blok = AnakTimbanganMentah::blokSesi($sesi->spesifikasi_alat);

        if ($blok === null) {
            return [];
        }

        $temuan = [[
            'kode' => 'anak_timbangan_diluar_akreditasi',
            'pesan' => 'Kalibrasi Anak Timbangan TIDAK ada di lampiran akreditasi LK-285-IDN — kelompok '
                .'Massa di situ cuma memuat "Timbangan (Elektronik, mekanik)". Nama lembar kerjanya '
                .'sendiri menyebut (Non KAN). U95 sesi ini karena itu terbit TANPA lantai CMC, persis '
                .'seperti masternya, yang sel lantainya kosong di kedua puluh blok. Angkanya sah. '
                .'Sertifikatnya sengaja terbit tanpa logo maupun nomor akreditasi; pastikan pelanggan '
                .'tahu bedanya dari sertifikat terakreditasi.',
        ]];

        if (TabelStandarAnakTimbangan::densitasDisengketakan()) {
            $temuan[] = [
                'kode' => 'anak_timbangan_densitas_disengketakan',
                'pesan' => 'Tabel densitas anak timbangan yang dipakai sesi ini disalin apa adanya dari '
                    .'master, dan master memuat nilai yang mustahil: 10650 kg/m3 (densitas TIMBAL) untuk '
                    .'kelas F1 pada 5 g, 14400 kg/m3 untuk F2 pada 20 g, dan kolom E2/F1 yang tertukar '
                    .'antara 0,1 g dan 0,2 g. Dampaknya KECIL — koreksi apung yang benar untuk seluruh '
                    .'keping cuma 0,0012-0,0303 mg, di bawah seperempat U95 — jadi angkanya tidak '
                    .'menyesatkan. Tapi tabelnya belum disahkan lab (pertanyaan lab §6), dan titik '
                    .'bernominal kecil TIDAK bisa terbit karena barisnya memang tidak ada.',
            ];
        }

        $timbangan = $blok['timbangan'] === null
            ? null
            : TabelStandarAnakTimbangan::timbangan($blok['timbangan']);

        if ($timbangan !== null) {
            $terkecil = $this->nominalTerkecil($sesi);
            $terbaik = $terkecil === null
                ? null
                : TabelStandarAnakTimbangan::timbanganTerbaikUntuk($terkecil);

            if (
                $terbaik !== null
                && $terbaik['nama'] !== $timbangan['nama']
                && $terbaik['std_dev_mg'] < $timbangan['std_dev_mg']
            ) {
                $temuan[] = [
                    'kode' => 'anak_timbangan_neraca_terlalu_kasar',
                    'pesan' => sprintf(
                        'Sesi ini memakai %s (keterulangan %s mg), padahal keping terkecilnya %s g dan '
                        .'%s (keterulangan %s mg, %sx lebih baik) masih sanggup memikulnya. Di master, '
                        .'kedua puluh keping — termasuk yang 5 mg — ditimbang di neraca yang sama, dan '
                        .'akibatnya U95 keping di bawah 1 g melebihi MPE kepingnya sendiri sehingga '
                        .'kesesuaian tidak bisa dinyatakan (pertanyaan lab §12).',
                        $timbangan['nama'],
                        $this->angka($timbangan['std_dev_mg']),
                        $this->angka($terkecil),
                        $terbaik['nama'],
                        $this->angka($terbaik['std_dev_mg']),
                        $this->angka($timbangan['std_dev_mg'] / max($terbaik['std_dev_mg'], 1e-12), 1),
                    ),
                ];
            }
        }

        return $temuan;
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            // `nomor_lingkup` SENGAJA tidak dipasang — alat ini di luar
            // lampiran LK-285-IDN. Lihat docblock kelas.
            'judul' => 'Calibration Work Sheet - Anak Timbangan',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Urutan penimbangan ABBA WAJIB diisi sesuai perannya: Standard, UUT, '
                .'UUT, Standard. Keempatnya punya TANDA yang berbeda di rumus `de = (T1 − S1 − S2 + T2)/2`, '
                .'jadi baris yang tertukar membalik arah koreksi kepingnya tanpa satu pun error. '
                .'TEKANAN UDARA wajib diisi walau kertas Rev.0 belum punya kolomnya — tanpa tekanan, '
                .'densitas udara tidak bisa dihitung dan koreksi apung seluruh keping hilang. Keping '
                .'yang nominalnya KEMBAR (dua 200 g, dua 20 g, dua 2 g, dua 0,2 g, dua 0,02 g) wajib '
                .'diberi No. Identitas — tanpa itu pelanggan tidak bisa memetakan sertifikat ke keping '
                .'fisiknya, dan titiknya tidak akan diterbitkan.',
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => '1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx',
                'catatan' => 'Enam komponen per keping dalam miligram, mengikuti OIML R111. TANPA lantai '
                    .'CMC — kalibrasi anak timbangan di luar lampiran LK-285-IDN, dan sel lantai '
                    .'masternya memang kosong di kedua puluh blok. Titik yang densitasnya tidak ada di '
                    .'tabel, yang |de|-nya melebihi 10x MPE, atau yang keping kembarnya belum diberi '
                    .'No. Identitas TIDAK diterbitkan.',
            ],
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianDataKalibrasi(),
                $this->bagianPenutup(),
            ],
        ];

        return $this->isiPilihanThermohygro(
            $this->tautkanStandarTercetak($bentuk, $equipment),
            $equipment,
        );
    }

    /** @return array<string, mixed> */
    private function bagianIdentitas(): array
    {
        $kelas = array_map(
            static fn (string $k): array => ['nilai' => $k, 'label' => $k],
            AnakTimbanganMentah::KELAS,
        );

        $timbangan = array_map(
            static fn (array $t): array => [
                'nilai' => $t['nama'],
                'label' => sprintf(
                    '%s — %s (maks %s g, res %s g)',
                    $t['nama'],
                    $t['merk_tipe'],
                    rtrim(rtrim(number_format((float) $t['kapasitas_g'], 2, ',', '.'), '0'), ','),
                    rtrim(number_format((float) $t['resolusi_g'], 5, ',', '.'), '0'),
                ),
            ],
            TabelStandarAnakTimbangan::semuaTimbangan(),
        );

        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat',
            'field' => [
                $this->field('equipment_id', 'Pilih Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                // Kelas UUT lebih dulu: dia yang menentukan kolom mana yang
                // dibaca di tabel densitas DAN baris mana di tabel MPE.
                $this->field(
                    'spesifikasi_alat.anak_timbangan.kelas_uut', 'Class (UUT)', 'pilihan',
                    pilihan: $kelas,
                ),
                $this->field(
                    'spesifikasi_alat.anak_timbangan.kelas_standar', 'Class (Standar)', 'pilihan',
                    pilihan: $kelas,
                ),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                $this->field('spesifikasi_alat.anak_timbangan.kapasitas_g', 'Kapasitas Alat', 'angka', satuan: self::SATUAN),
                $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                $this->field(
                    'spesifikasi_alat.anak_timbangan.timbangan', 'Timbangan yang Dipakai', 'pilihan',
                    pilihan: $timbangan,
                ),
                $this->field('thermohygro_standard_id', 'Environmental Meter Used', 'pilihan', sumber: 'master_thermohygro'),
                $this->field(
                    'spesifikasi_alat.anak_timbangan.meter_lingkungan', 'TH Used', 'pilihan',
                    pilihan: array_map(
                        static fn (string $n): array => ['nilai' => $n, 'label' => $n],
                        self::THERMOHYGRO_TERCETAK,
                    ),
                ),
                $this->field('spesifikasi_alat.anak_timbangan.suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: '°C'),
                $this->field('spesifikasi_alat.anak_timbangan.suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: '°C'),
                $this->field('spesifikasi_alat.anak_timbangan.kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('spesifikasi_alat.anak_timbangan.kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                // Kertas Rev.0 belum punya kolom ini — lihat docblock kelas.
                $this->field('spesifikasi_alat.anak_timbangan.tekanan_awal', 'Tekanan Udara — awal', 'angka', satuan: 'hPa'),
                $this->field('spesifikasi_alat.anak_timbangan.tekanan_akhir', 'Tekanan Udara — akhir', 'angka', satuan: 'hPa'),
                $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                // Dua kotak lokasi yang saling meniadakan — tanpa `tampil_kalau`,
                // dropdown Ruangan tetap menyimpan pilihan lama walau sedang
                // Insitu, dan sertifikatnya mencetak nama ruang lab yang tidak
                // pernah didatangi.
                $this->field(
                    'room_id', 'Ruangan (Inlab)', 'pilihan',
                    sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                $this->field(
                    'lokasi_nama', 'Nama Tempat (Insitu)', 'teks',
                    tampilKalau: self::TAMPIL_KALAU_INSITU,
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Identitas Customer',
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
     * Blok pengukuran — EMPAT tabel ber-`tahap` sama, offset kunci berbeda.
     *
     * Satu tabel per peran ABBA, masing-masing sepuluh baris keping × tiga
     * pengulangan (`X1 X2 X3` di kertas). Perannya jadi `grup`, jadi
     * `peran_sensor` baris mentahnya lahir dari situ — bukan dari urutan kolom,
     * yang tidak dijamin.
     *
     * `titik_bisa_diubah = true`: berbeda dari Height Gauge dan Micrometer,
     * nominal keping di sini DIPILIH teknisi. Satu set anak timbangan isinya
     * berbeda-beda per pelanggan, dan kertasnya sendiri menyediakan kotak
     * kosong `( )` untuk diisi.
     *
     * @return array<string, mixed>
     */
    private function bagianDataKalibrasi(): array
    {
        $tabel = [];

        $judul = [
            AnakTimbanganMentah::PERAN_S1 => 'Standard (S1) — penimbangan standar, pertama',
            AnakTimbanganMentah::PERAN_T1 => 'UUT (T1) — penimbangan alat, pertama',
            AnakTimbanganMentah::PERAN_T2 => 'UUT (T2) — penimbangan alat, kedua',
            AnakTimbanganMentah::PERAN_S2 => 'Standard (S2) — penimbangan standar, kedua',
        ];

        foreach (AnakTimbanganMentah::PERAN_URUT as $peran) {
            $tabel[] = [
                'tahap' => 'sesudah_adjustment',
                'grup' => $peran,
                'judul' => $judul[$peran],
                'satuan' => self::SATUAN,
                'judul_nilai' => 'Nominal AT',
                'judul_pengulangan' => 'Pembacaan',
                'titik_bisa_diubah' => true,
                'offset_kunci' => self::OFFSET[$peran],
                // Tujuan simpannya dinyatakan EKSPLISIT, dan tanpa ini angkanya
                // hilang diam-diam. Sisi HP data-driven: `_measurementsDeretBernama`
                // cuma menyusun `measurements[]` dari tabel yang menyebut
                // tujuannya, dan tabel yang diam dianggap tidak punya tempat
                // simpan — kotaknya kegambar, teknisi mengisi sepuluh keping ×
                // empat peran × tiga ulangan, payloadnya terkirim tanpa satu pun
                // kunci peran, lalu `hitungPerGrup()` menolak seluruh titiknya.
                //
                // `offset_kunci` yang berbeda tidak mengganggu penggabungannya:
                // HP menyusuri keempat tabel PER INDEKS BARIS dan mencari
                // titiknya lewat `titikUntukBaris(baris, i, tabel)`, jadi baris
                // ke-i keempat tabel mendarat di SATU entri `measurements[]`
                // berisi empat kunci. Persis pola lima deret sejajar Flowmeter.
                //
                // Kunci di sini sama persis dengan yang dibaca
                // `CalibrationController::susunBlokAnakTimbangan()` dan
                // `AnakTimbanganMentah::dari()`. Ketiganya harus sepakat.
                'simpan_ke' => 'measurements[].'.$peran,
                'baris' => array_map(
                    static fn (int $n): array => [
                        'nomor' => $n,
                        'titik_ukur' => null,
                        'label' => 'Keping '.$n,
                        'satuan' => self::SATUAN,
                    ],
                    range(1, self::BARIS_KERTAS),
                ),
                'kolom' => [
                    ['kode' => 'pembacaan', 'label' => 'Nilai', 'tipe' => 'angka', 'satuan' => self::SATUAN],
                ],
                'pengulangan' => range(1, self::PENGULANGAN),
            ];
        }

        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Data Hasil Kalibrasi',
            'field' => [],
            'tabel' => $tabel,
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

    /**
     * @param  array<string, mixed>  $hasil
     * @param  array<string, mixed>  $h
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, array $h): array
    {
        $budget = array_map(fn (array $k): array => $this->barisAudit($k), $h['budget']);

        // Baris `perbandingan_cmc` tetap diterbitkan walau CMC-nya tidak ada,
        // dan itu WAJIB: `CalibrationValidator::cmcTitik()` menyapu
        // `type_b_components` mencari `sumber` ini, dan yang tidak ketemu
        // mematikan gerbang ERROR `u95_meledak_dari_cmc`.
        //
        // `null` dioper apa adanya supaya keterangannya berbunyi "tanpa lantai
        // CMC", bukan "vs CMC 0.00000000" yang terbaca seperti klaim sempurna.
        $budget[] = $this->barisPerbandinganCmc((float) $h['u95_g'], null, self::SATUAN);

        $ling = $hasil['lingkungan'];

        $budget[] = [
            'sumber' => 'jejak_titik',
            'keterangan' => sprintf(
                'Keping %s g%s · massa standar %s g · de %s g · koreksi apung %s g '
                .'(jalur master %s g, selisih %s mg — pertanyaan lab §2) · massa konvensional %s g · '
                .'densitas UUT %s / standar %s kg/m3 · densitas udara %s kg/m3 dari T %s °C, RH %s %%, '
                .'P %s hPa (rata-rata MENTAH — pertanyaan lab §13) · U95 %s g tanpa lantai CMC',
                $this->angka($h['nominal_g']),
                $h['no_identitas'] === null ? '' : ' ('.$h['no_identitas'].')',
                $this->angka($h['ms_g']),
                $this->angka($h['de_g']),
                $this->angka($h['b_g']),
                $this->angka($h['b_jalur_master_g']),
                $this->angka(($h['b_jalur_master_g'] - $h['b_g']) * 1000, 4),
                $this->angka($h['mt_g']),
                $this->angka($h['rho_uut'], 1),
                $this->angka($h['rho_standar'], 1),
                $this->angka((float) $hasil['rho_udara']),
                $this->angka((float) $ling['suhu']['rata'], 2),
                $this->angka((float) $ling['kelembaban']['rata'], 2),
                $this->angka((float) $ling['tekanan']['rata'], 2),
                $this->angka($h['u95_g']),
            ),
            'distribusi' => 'jejak',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'vi' => 0.0,
        ];

        return $budget;
    }

    /** Nominal keping TERKECIL sesi ini, dari baris mentahnya. */
    private function nominalTerkecil(CalibrationSession $sesi): ?float
    {
        $nominal = $sesi->rawMeasurements()
            ->whereIn('peran_sensor', AnakTimbanganMentah::PERAN_URUT)
            ->whereNotNull('titik_ukur')
            ->min('titik_ukur');

        return $nominal === null ? null : (float) $nominal;
    }

    private function angka(float $nilai, int $desimal = 8): string
    {
        $teks = rtrim(rtrim(number_format($nilai, $desimal, ',', '.'), '0'), ',');

        return $teks === '' || $teks === '-' ? '0' : $teks;
    }

    private function kalk(): AnakTimbanganCalculator
    {
        // Malas, bukan di parameter bawaan konstruktor: `new
        // AnakTimbanganCalculator` di situ melahirkan lingkaran lewat
        // `CalibrationProfileRegistry` yang gejalanya "Maximum call stack size"
        // jauh dari penyebabnya.
        return $this->kalk ??= new AnakTimbanganCalculator;
    }
}
