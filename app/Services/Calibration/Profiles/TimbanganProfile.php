<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\TabelStandarTimbangan;
use App\Services\Calibration\TimbanganCalculator;
use App\Services\Calibration\VarianMasterTimbangan;
use App\Support\Angka;
use App\Support\TimbanganMentah;
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

    /**
     * Nomor IK Timbangan, dibaca dari `DATABASE` baris 5 ketiga workbook
     * master: `Timbangan -> SIDIK-IK-CAL-0505-Rev.7`. Ketiga sertifikat master
     * mencetaknya di kolom `Calibration Method` (`INPUT DATA!AD12`).
     *
     * JANGAN dikira `SIDIK-IK-CAL-0508`: nomor itu milik Spectrophotometer
     * (DATABASE baris 8). Kemiripannya dengan nomor FORMULIR lembar kerja
     * Timbangan (`SIDIK-FM-CAL-0508.A_Rev.4`) kebetulan — FM dan IK dua deret
     * penomoran yang berbeda, dan menyamakannya bikin sertifikat menyebut
     * metode alat lain.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0505_Rev.7';

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
     * Kamera LOKAL nyala, jalur CLOUD tetap mati — dan yang nyala digerbangi
     * per TABEL, bukan per lembar.
     *
     * ## `didukung` (jalur CLOUD) — tetap false
     *
     * Tujuh blok yang tidak sebentuk tidak bisa diungkapkan lewat
     * `kolom_suhu` / `standar_di_baris` yang cuma memodelkan satu tabel datar.
     * Dibiarkan `true`, prompt & skema JSON yang dikirim ke pembaca foto
     * dibangun untuk tabel yang tidak ada di kertasnya — dan yang balik bukan
     * error, tapi angka yang dikarang supaya kolomnya kelihatan terisi. Alasan
     * yang sama dipakai lembar Autoklaf & grid Enclosure. Jalur itu juga
     * MENGIRIM FOTO LEMBAR KERJA PELANGGAN ke layanan pihak ketiga; tidak ada
     * yang meminta itu untuk lembar ini.
     *
     * ## `lokal` (tombol `FOTO TABEL INI`, ML Kit di perangkat) — true
     *
     * Sempat dimatikan 31 Agt 2026 dengan tiga alasan. Pemilik proyek lalu
     * mengirim cetakan `CALIBRATION RESULT` ketiga master, dan cetakan itu
     * membatalkan dua di antaranya:
     *
     *  - *"kertasnya belum ada"* — SALAH. `kode_dokumen` memang null (nomor
     *    formulir lembar kerjanya belum turun), tapi tabel yang difoto teknisi
     *    itu sheet `INPUT DATA` yang sudah dicetak dan sudah dipakai. Nomor
     *    formulir dan keberadaan kertas dua hal yang berbeda; menyamakannya
     *    membuang jalur yang sebenarnya ada.
     *  - *"kepala kolomnya tidak terjangkau"* — SALAH, dan sebabnya bentuk
     *    yang dikirim sendiri: tabelnya transposed dari kertasnya. Di kertas
     *    nomor pengulangan TURUN di kolom `No.` dan kapasitas berjajar ke
     *    samping. Sesudah `sumbu_pengulangan: 'baris'`, ketiga jangkarnya
     *    tersedia — nomor 1..10 di kiri, `Middle`/`Maximum Capacity` di atas,
     *    dan `Zero (kg)` / `Reading (kg)` sebagai pembeda sub-kolom.
     *
     * Yang TETAP berlaku alasan ketiga, dan itu yang menggerbangi per tabel.
     *
     * ## Cuma satu dari dua tabelnya yang bisa difoto
     *
     *  - **Repeatability** — grid sempurna sesudah orientasinya dibetulkan.
     *    `pindai_foto: true`.
     *  - **Accuracy** — BUKAN grid. Di kertas master blok ini daftar MENURUN,
     *    satu pembacaan per baris, dan labelnya yang membedakan:
     *    `z1, m1, m1', z2, m2, m2', z3…` (kg & gram) atau
     *    `z1, M1, M1', z1', z2, M2…` (substitusi — huruf besar, dan ada baris
     *    penutup `z'` yang dua master lain tidak punya). Grid empat kolom yang
     *    digambar layar itu bentuk LAYAR, bukan bentuk kertasnya. Pemeta yang
     *    ada menjangkar kolom ke nomor pengulangan; di sini pembedanya TULISAN
     *    per baris. `pindai_foto: false` sampai jalur jangkar-label itu ada.
     *
     * ## Satuan ikut jadi jangkar, dan itu disengaja
     *
     * Label sub-kolomnya `Zero (kg)` / `Reading (kg)` di dua master dan
     * `Zero (g)` / `Reading (g)` di master gram — persis seperti tercetak.
     * Jadi lembar gram yang difoto ke sesi ber-satuan kg tidak menemukan
     * jangkarnya dan pulang NOL sel. Gagal berisik, bukan memindahkan
     * `24,9999 g` ke kotak kilogram.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung: bool, lokal: bool}
     */
    public function bentukPindaiFoto(): array
    {
        return [
            // Satu angka per sel — tidak ada kolom °C di dalam tiap
            // pengulangan. Dibiarkan true, pembaca foto diminta mencari kolom
            // suhu yang tidak pernah ada di kertasnya.
            'kolom_suhu' => false,
            'standar_di_baris' => false,
            'didukung' => false,
            'lokal' => true,
        ];
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
                'error' => -$this->koreksiTerbit($t),
                // Varian substitusi menyimpan KUMULATIF (`Cn`), bukan `ΔI`.
                //
                // Bukan kerapian: `ΔI` itu besaran ANTARA — selisih satu
                // langkah substitusi — dan sertifikat masternya mencetak `Cn`
                // (`SERTIFIKAT!D28..D37` -> `PERHITUNGAN FC!T50..T86`, yaitu
                // 0,0059 · 1,5118 · 2,4177 …, bukan 0,0059 · 1,5059 · 0,9059).
                // Menyimpan `ΔI` di kolom yang dibaca sertifikat, Excel, dan
                // API bikin ketiganya menerbitkan angka yang benar-benar lain
                // dari lembar master, tanpa satu pun error — koreksi titik
                // terakhir terbit 1,4559 kg di tempat masternya menulis
                // 13,309 kg.
                //
                // `ΔI` tetap hidup: `TimbanganCalculator` memulangkannya, dan
                // `TimbanganMasterTest` mengadunya ke master di tingkat
                // kalkulator. Yang berubah cuma angka mana yang MENDARAT di
                // `uncertainty_calculations`. Dua varian lain tidak punya
                // kumulatif dan jatuh ke `koreksi` — persis perilaku lamanya.
                'koreksi' => $this->koreksiTerbit($t),
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
     * Koreksi yang DITERBITKAN buat satu titik — kumulatif di varian
     * substitusi, langsung di dua varian lain.
     *
     * @param  array<string, mixed>  $t  satu baris keluaran TimbanganCalculator
     */
    private function koreksiTerbit(array $t): float
    {
        return (float) ($t['koreksi_kumulatif'] ?? $t['koreksi']);
    }

    /**
     * Delapan bagian sertifikat Timbangan, dibekukan ke snapshot waktu
     * sertifikat terbit. Lihat [CalibrationProfile::ringkasanSertifikat] soal
     * kenapa jalur ini ada sama sekali.
     *
     * ## Kenapa dihitung ulang di sini, bukan dibaca dari yang tersimpan
     *
     * Tiga dari delapan bagian nggak punya tempat penyimpanan: STDEV & maksimum
     * beda keterulangan (bagian 1), selisih tiap posisi eksentrisitas (bagian
     * 4), dan perbandingan histeresis (bagian 5) semuanya lahir dari blok
     * MASUKAN di `spesifikasi_alat` dan nggak pernah mendarat di kolom mana
     * pun. `uncertainty_calculations` cuma punya baris per TITIK AKURASI.
     *
     * Jadi sesinya dihitung ulang utuh — bukan cuma tiga bagian itu — supaya
     * kedelapan angkanya lahir dari satu lintasan yang sama. Ngambil sebagian
     * dari hitung ulang dan sebagian dari database bikin satu lembar
     * sertifikat memuat dua generasi angka kalau kalkulatornya pernah berubah,
     * dan nggak ada yang bisa lihat bedanya dari lembarnya.
     *
     * Amannya dijaga dua hal: sesi cuma bisa disetujui kalau `CalibrationValidator`
     * udah mengadu hitung ulang ke yang tersimpan (`ketidakpastian_beda` /
     * `hitung_ulang_gagal`), dan `TimbanganSertifikatTest` mengunci bagian 3
     * di sini ke isi `uncertainty_calculations`. Begitu keduanya berpisah,
     * yang merah test — bukan pelanggan yang megang dua lembar beda angka.
     *
     * ## Desimalnya dibekukan, dan tiga masternya nggak sepakat
     *
     * Tiga workbook memformat sel yang sama dengan jumlah desimal berbeda —
     * LOP-nya `0` (kg-substitusi), `0.00` (kg), dan `0.00000` (gram) — jadi
     * nggak ada satu aturan pun yang bisa meniru ketiganya. Yang dipakai:
     * `d` = desimal dari resolusi (cocok di ketiganya buat nilai & koreksi),
     * `max(d,2)` buat STDEV (cocok di ketiganya), dan `d+1` buat U95 & LOP —
     * cocok di gram, satu digit lebih banyak dari kg & substitusi. Dipilih
     * yang MELEBIH, bukan yang mengurang: `± 0,00 g` di kolom Limit of
     * Performance itu angka yang nggak menyatakan apa-apa. Diangkat sebagai
     * T14.
     *
     * @return array<string, mixed>|null
     */
    public function ringkasanSertifikat(CalibrationSession $sesi): ?array
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return null;
        }

        $spek = (array) ($sesi->spesifikasi_alat ?? []);
        $titik = [];

        foreach ($sesi->rawMeasurements->groupBy('titik_ke') as $titikKe => $baris) {
            $titik[] = [
                'titik_ke' => (int) $titikKe,
                'konteks' => [...TimbanganMentah::dari($baris), 'spesifikasi_alat' => $spek],
            ];
        }

        if ($titik === []) {
            return null;
        }

        usort($titik, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        $masukan = $this->susunMasukan($titik, $alat, $titik[0]['konteks']);

        // Alasannya (resolusi/kapasitas kosong) udah muncul sebagai
        // `belum_dihitung` di layar sesi jauh sebelum sertifikat terbit —
        // di sini yang benar balik `null`, dan blade jatuh ke tabel empat
        // kolom biasa alih-alih nyetak delapan bagian berisi tanda pisah.
        if (is_string($masukan)) {
            return null;
        }

        $hasil = $this->kalkulator()->hitung($masukan);
        $resolusi = (float) $masukan['resolusi'];
        $desimal = Angka::desimalDariResolusi($resolusi);
        $ket = $hasil['keterulangan'];
        $ekc = $hasil['eksentrisitas'];

        // `koreksiTerbit()` yang sama dipakai jalur simpan — kalau angka yang
        // dicetak sertifikat sampai lahir dari rumus lain, bedanya nggak
        // kelihatan di mana pun.
        $titikSertifikat = array_map(fn (array $t): array => [
            'titik_ke' => (int) $t['titik_ke'],
            'titik_ukur' => (float) $t['titik_ukur'],
            'koreksi' => $this->koreksiTerbit($t),
            'u95_koreksi' => (float) $t['u95_koreksi'],
            'u95_penimbangan' => (float) $t['u95_penimbangan'],
        ], $hasil['titik']);

        return [
            'varian' => $hasil['varian'],
            'satuan' => $hasil['satuan'],
            'resolusi' => $resolusi,
            'desimal' => $desimal,
            'desimal_stdev' => max($desimal, 2),
            'desimal_u95' => $desimal + 1,
            'keterulangan' => $this->bagianSertifikatKeterulangan($ket),
            'effect_of_tare' => $this->effectOfTare($spek),
            'eksentrisitas' => $this->bagianSertifikatEksentrisitas($ekc),
            'histeresis' => $this->bagianSertifikatHisteresis($hasil, $spek, $resolusi),
            'lop' => $hasil['lop'] === null ? null : (float) $hasil['lop'],
            'titik' => $titikSertifikat,
            // SATU `k` buat seluruh bagian 7, diambil dari titik pertama —
            // persis masternya, yang nunjuk `'PERHITUNGAN U95%-Weighing'!R20`
            // (blok titik pertama) buat kalimat di bawah tabelnya.
            'k_penimbangan' => isset($hasil['titik'][0])
                ? (float) $hasil['titik'][0]['k_penimbangan']
                : null,
        ];
    }

    /**
     * Bagian 1 — REPEATABILITY. DUA baris, bukan tiga.
     *
     * Master kg & substitusi nyetak baris KETIGA berlabel `Penuh` yang isinya
     * `PERHITUNGAN FC!I103` / `M116` / `M117` — kolom yang di blok
     * Repeatability workbook itu nggak ada (bloknya berhenti di kolom H, dua
     * kapasitas: Middle & Miximum). Selnya balik 0, jadi yang tercetak baris
     * `Penuh | 0 | 0 | 0` di sertifikat terakreditasi. Master gram nggak punya
     * baris itu sama sekali.
     *
     * Itu kerusakan salin-tempel, bukan metode — dan aturannya (CLAUDE.md)
     * kerusakan salin-tempel dihitung benar, bukan ditiru. Barisnya nggak
     * dicetak, dan selisihnya ditulis di docs/pertanyaan-lab-timbangan.md.
     *
     * Label `Half Capacity` dieja `Half Capaity` di master kg — dua dari tiga
     * mengejanya benar, jadi yang dipakai ejaan yang benar.
     *
     * @param  array<string, mixed>  $ket
     * @return list<array<string, mixed>>
     */
    private function bagianSertifikatKeterulangan(array $ket): array
    {
        $slot = [
            ['Half Capacity', 'mid', 'nominal_mid', 'stdev_mid'],
            ['Full Capacity', 'maks', 'nominal_maks', 'stdev_maks'],
        ];

        $baris = [];

        foreach ($slot as [$label, $blok, $kunciNominal, $kunciStdev]) {
            // Slot yang TIDAK diisi teknisi nggak dicetak.
            //
            // Ini bukan kerapian, ini menolak mengarang: waktu bloknya kosong,
            // `keterulangan()` tetap memulangkan STDEV — bukan nol, melainkan
            // lantai `Sres` (0,82 × resolusi/2), angka yang diturunkan dari
            // RESOLUSI ALAT dan bukan dari satu pun pembacaan. Lantai itu sah
            // di dalam budget (timbangan yang sepuluh pembacaannya identik
            // memang bersebaran nol, dan nol di budget berarti mengaku tidak
            // punya sebaran sama sekali) — tapi di kolom hasil sertifikat dia
            // terbaca sebagai "keterulangan diukur, hasilnya 0,01 kg" padahal
            // tidak ada yang diukur.
            //
            // Bentuknya persis jebakan `IFERROR(…,"")` yang dilarang ditiru:
            // sel kosong yang dibaca sebagai angka. Yang benar barisnya tidak
            // ada, dan admin diperingatkan sebelum menyetujui — lihat
            // `peringatanSesi()`.
            if ((int) ($ket[$blok]['n'] ?? 0) === 0) {
                continue;
            }

            $baris[] = [
                'label' => $label,
                'kapasitas' => (float) $ket[$kunciNominal],
                'stdev' => (float) $ket[$kunciStdev],
                'maks_beda' => (float) ($ket[$blok]['maks_beda'] ?? 0.0),
            ];
        }

        return $baris;
    }

    /**
     * Bagian 2 — EFFECT OF TARE: `|m1 − m2|`, dan cuma itu.
     *
     * Petunjuk di lembar kerjanya nulis `C = Ms − (M − z)`; yang dicetak
     * sertifikat BUKAN itu. Selnya `PERHITUNGAN FC!F42 = ABS(E42−E43)` —
     * selisih mutlak dua pembacaan tare. Rumus di kertas itu buat besaran
     * lain, dan kalau ditiru angkanya beda sebesar massa standarnya.
     *
     * `null` kalau salah satu kotaknya kosong: nol di sini kebaca sebagai
     * "tare-nya sempurna", padahal artinya "belum diukur".
     *
     * @param  array<string, mixed>  $spek
     */
    private function effectOfTare(array $spek): ?float
    {
        $tare = (array) ($spek['effect_of_tare'] ?? []);
        $m1 = $tare['m1'] ?? null;
        $m2 = $tare['m2'] ?? null;

        if (! is_numeric($m1) || ! is_numeric($m2)) {
            return null;
        }

        return abs((float) $m1 - (float) $m2);
    }

    /**
     * Bagian 4 — LOADING INFLUENCE. Lima posisi urut kertas, plus
     * `Maximum Difference` = MAX − MIN.
     *
     * Yang dicetak tiap posisi SELISIH (`beban − pembacaan`), bukan
     * pembacaannya. Nilai acuannya lihat T13: mesin hitung memakai pembacaan
     * CENTER waktu `beban` kosong. `Maximum Difference` nggak kena selisih itu
     * — MAX − MIN kebal terhadap pergeseran acuan yang sama besar di kelima
     * posisi — tapi kelima angka posisinya kena.
     *
     * @param  array<string, mixed>  $ekc
     * @return array<string, mixed>
     */
    private function bagianSertifikatEksentrisitas(array $ekc): array
    {
        $urut = ['center' => 'Center', 'front' => 'Front', 'back' => 'Back', 'left' => 'Left', 'right' => 'Right'];
        $selisih = (array) ($ekc['selisih'] ?? []);
        $posisi = [];

        foreach ($urut as $kunci => $label) {
            $posisi[] = [
                'label' => $label,
                'selisih' => array_key_exists($kunci, $selisih) ? (float) $selisih[$kunci] : null,
            ];
        }

        return [
            'beban' => isset($ekc['beban']) ? (float) $ekc['beban'] : null,
            'posisi' => $posisi,
            'maks_beda' => (float) ($ekc['rentang'] ?? 0.0),
        ];
    }

    /**
     * Bagian 5 — HYSTERISIS: yang tercetak PERBANDINGAN, bukan nilainya.
     *
     * Sel masternya `IF('PERHITUNGAN FC'!I148 <= resolusi, "<", ">")` lalu
     * memajang nilai RESOLUSI di sebelahnya — jadi yang terbit `< 0,0001 g`,
     * bukan angka histeresisnya. Mencetak angka mentahnya berarti sertifikat
     * menyatakan hal yang berbeda dari yang dimaksud lab.
     *
     * Nilai mentahnya tetap ada jejaknya: baris `histeresis` di
     * `type_b_components` tiap titik.
     *
     * @param  array<string, mixed>  $hasil
     * @param  array<string, mixed>  $spek
     * @return array<string, mixed>|null
     */
    private function bagianSertifikatHisteresis(array $hasil, array $spek, float $resolusi): ?array
    {
        if (($hasil['histeresis'] ?? null) === null) {
            return null;
        }

        $beban = $spek['histeresis']['m'] ?? null;

        return [
            'beban' => is_numeric($beban) ? (float) $beban : null,
            // Perbandingan MENTAH ke resolusi, persis masternya — bukan
            // `abs()`. Histeresis negatif memang lebih kecil dari resolusi.
            'pembanding' => (float) $hasil['histeresis'] <= $resolusi ? '<' : '>',
            'batas' => $resolusi,
        ];
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

        // LOP & histeresis dihitung sekali per SESI, tapi disimpan di tiap
        // titik — `uncertainty_calculations` satu-satunya tempat hasil hitung
        // mendarat, dan tidak ada baris tingkat-sesi di situ. Diulang, bukan
        // hilang: sertifikat mencetak dua-duanya (bagian 5 & 6), dan angka yang
        // tercetak tanpa jejak audit sama saja dengan angka yang dikarang.
        $baris[] = [
            'budget' => '-',
            'sumber' => 'limit_of_performance',
            'keterangan' => sprintf(
                'LOP sesi ini ± %s %s = 2,26 × STDEV max + |C max| + U(C max). '
                .'U-nya HITUNGAN (k · uc), bukan U95 yang sudah dilantai CMC — begitu masternya.',
                $this->angka((float) $hasil['lop']),
                $hasil['satuan'],
            ),
            'distribusi' => '-',
            'nilai' => $hasil['lop'],
        ];

        if ($hasil['histeresis'] !== null) {
            $baris[] = [
                'budget' => '-',
                'sumber' => 'histeresis',
                'keterangan' => sprintf(
                    'Histeresis sesi ini %s %s (bagian 5 sertifikat).',
                    $this->angka((float) $hasil['histeresis']),
                    $hasil['satuan'],
                ),
                'distribusi' => '-',
                'nilai' => $hasil['histeresis'],
            ];
        }

        if (($hasil['drift_massa_standar'] ?? null) !== null) {
            $baris[] = [
                'budget' => '-',
                'sumber' => 'drift_massa_standar',
                'keterangan' => sprintf(
                    'Drift Massa Standar (d) = %s kg — kotak 7 formulir metode substitusi. '
                    .'0,1 × MAX(Σ u tiap titik) / 2, dan satuannya kg bahkan untuk timbangan gram. '
                    .'Tidak masuk budget mana pun, jadi tidak ada test budget yang menjaganya.',
                    $this->angka((float) $hasil['drift_massa_standar']),
                ),
                'distribusi' => '-',
                'nilai' => $hasil['drift_massa_standar'],
            ];
        }

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

        // Blok keterulangan yang KOSONG bikin bagian 1 sertifikat nggak punya
        // baris sama sekali (lihat `bagianSertifikatKeterulangan`). Itu
        // perilaku yang benar — mengarang angka lebih buruk — tapi kalau
        // diam-diam, yang terbit lembar terakreditasi tanpa Repeatability dan
        // nggak ada yang sadar sampai pelanggan bertanya.
        //
        // PERINGATAN, bukan error: sesi yang keterulangannya belum diisi tetap
        // boleh terbit kalau lab memang memutuskan begitu. Yang berubah cuma
        // keputusannya jadi sadar.
        $ket = (array) ($sesi->spesifikasi_alat['keterulangan'] ?? []);
        $adaIsi = false;

        foreach (['mid', 'maks'] as $blok) {
            if (($ket[$blok]['mi'] ?? []) !== []) {
                $adaIsi = true;
            }
        }

        if (! $adaIsi) {
            $peringatan[] = [
                'kode' => 'keterulangan_kosong',
                'pesan' => 'Blok Repeatability belum diisi sama sekali, jadi bagian 1 sertifikat '
                    .'terbit TANPA baris. Angkanya sengaja nggak dikarang: waktu bloknya kosong, '
                    .'STDEV yang keluar itu lantai Sres (0,82 × resolusi/2) — turunan resolusi '
                    .'alat, bukan hasil ukur. Isi dulu blok itu, atau setujui secara sadar kalau '
                    .'sesi ini memang nggak menguji keterulangan.',
            ];
        }

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
            // Nomor formulir LEMBAR KERJA, dan cuma dipasang di varian yang
            // kertasnya benar-benar ada di tangan.
            //
            // Ketiga workbook cuma memuat `SIDIK-FM-CAL-2403_Rev. 0` di footer
            // sheet SERTIFIKAT — itu formulir SERTIFIKAT yang dipakai bersama
            // semua alat, bukan nomor lembar kerjanya. Jadi selama yang ada
            // cuma workbook, kolom ini memang null.
            //
            // 31 Agt 2026 pemilik proyek mengirim kertasnya untuk metode
            // SUBSTITUSI: `SIDIK-FM-CAL-0508.A`, Revise 4. Yang kg & gram
            // belum — dan nomornya TIDAK ditebak dari situ. Akhiran `.A`
            // menyiratkan ada saudaranya, tapi menyiratkan bukan mengetahui,
            // dan nomor formulir yang salah di kop lembar lab terakreditasi
            // itu temuan audit. Ditanyakan sebagai T12.
            'kode_dokumen' => $this->kodeFormulir($equipment),
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

    /**
     * Nomor formulir lembar kerja — per VARIAN, dan cuma yang kertasnya ada.
     *
     * Variannya diturunkan dari alat (kapasitas & satuan) memakai aturan yang
     * sama dengan bawaan `varian_master`, jadi lembar yang dibuka untuk
     * timbangan 2000 kg langsung memajang nomor formulir yang benar. Teknisi
     * tetap boleh mengganti variannya di dalam lembar; yang berubah cuma
     * perhitungannya, bukan kop yang sudah tercetak di kertas yang dia pegang.
     *
     * Null buat kg & gram, dan itu BUKAN kelupaan: kertasnya belum pernah
     * sampai. Menebaknya dari `SIDIK-FM-CAL-0508.A` (misal dengan membuang
     * `.A`) berarti mencetak nomor formulir karangan di kop lembar lab
     * terakreditasi — persis jenis temuan yang paling mahal.
     */
    private function kodeFormulir(?Equipment $equipment): ?string
    {
        if ($equipment === null) {
            return null;
        }

        // Kapasitas dibawa ke KILOGRAM dulu — ambang `> 200 kg` di
        // `bawaanUntuk()` bersatuan kg, dan alat gram berkapasitas 54 yang
        // dilempar mentah ke situ bakal dibaca 54 kg.
        $kapasitas = (float) ($equipment->range_max ?? 0.0);
        $gram = strtolower(trim((string) ($equipment->satuan ?? ''))) === 'g';

        $varian = VarianMasterTimbangan::bawaanUntuk(
            $gram ? $kapasitas / 1000.0 : $kapasitas,
            $equipment->satuan,
        );

        return $varian->kode === VarianMasterTimbangan::SUBSTITUSI
            ? 'SIDIK-FM-CAL-0508.A_Rev.4'
            : null;
    }

    /** @return array<string, mixed> */
    private function bagianScaleObservation(): array
    {
        return [
            'kode' => 'scale_observation',
            'halaman' => 1,
            'judul' => '1. SCALE OBSERVATION',
            // TIDAK ada `baris` di sini. Kunci itu di HP berarti "baris tabel
            // STANDARD yang tercetak" (`BagianLembarKerja.baris`), dan
            // sepasang `{kode, label}` tahap bukan itu — yang digambar bakal
            // daftar standar hantu. Nama tahapnya sekarang hidup di kode tiap
            // kotak (`…scale_observation.sebelum_adjustment.z1`), yang lebih
            // baik: tidak ada urutan yang perlu ditebak dua sisi.
            'field' => [
                ...$this->fieldScaleObservation('sebelum_adjustment', 'Before'),
                ...$this->fieldScaleObservation('sesudah_adjustment', 'After'),
                // "Standar deviasi yang lalu (SD)" — kotak paling bawah blok
                // ini di kertas.
                //
                // KOSONG di ketiga workbook master, jadi tidak dipakai
                // perhitungan mana pun; kalau dipakai, salah satu dari 1.127
                // angka paritas pasti sudah meleset. Kotaknya tetap ada karena
                // ADA di kertas: lembar yang punya kotak di tangan tapi tidak
                // punya kotaknya di layar bikin teknisi mencatatnya di
                // sembarang tempat, atau tidak sama sekali.
                //
                // Kalau suatu saat lab mulai mengisinya DAN memakainya, yang
                // berubah bukan cuma kotak ini — `vi` keterulangan ikut, dan
                // itu perubahan metode, bukan penambahan kolom.
                $this->f(
                    'spesifikasi_alat.scale_observation.sd_tahun_lalu',
                    'Standar deviasi tahun lalu (s)',
                    'angka',
                ),
            ],
        ];
    }

    /**
     * Lima kotak Scale Observation satu tahap, dengan kode yang BENAR-BENAR
     * sampai ke server.
     *
     * ## Kenapa bukan `scale_observation.*.z1` seperti draf pertama
     *
     * Kode ber-wildcard itu idiom lembar CETAK, bukan kunci payload — dan HP
     * membaca titik di dalam kode sebagai penanda "kolom TURUNAN": read-only,
     * diisi sistem dari alat yang dipilih, dan **tidak pernah ikut dikirim**
     * (`FieldLembarKerja.turunan`). Sepuluh kotak ini digambar rapi, teknisi
     * mengisinya dari kertas, lalu isinya hilang waktu tombol kirim ditekan.
     * Tanpa satu pun error, di kedua sisi.
     *
     * Awalan `spesifikasi_alat.` yang membalikkannya: HP mengecualikan awalan
     * itu dari aturan turunan (di sana titik artinya PENGELOMPOKAN), jadi
     * kotaknya bisa diketik dan isinya mendarat di
     * `calibration_sessions.spesifikasi_alat` — tempat yang sama dengan empat
     * blok tingkat-sesi lainnya.
     *
     * Tahapnya ditulis EKSPLISIT, bukan `*`: dua baris kertasnya memang cuma
     * dua, dan kunci yang eksplisit bisa divalidasi, bisa dicari, dan tidak
     * bergantung pada HP menebak urutan `baris`.
     *
     * @return list<array<string, mixed>>
     */
    private function fieldScaleObservation(string $tahap, string $label): array
    {
        $kotak = [
            'standar' => 'Standar Weight',
            'z1' => 'z1',
            'm1' => 'm1',
            'm2' => 'm2',
            'z2' => 'z2',
        ];

        return array_values(array_map(
            fn (string $judul, string $kunci): array => $this->f(
                "spesifikasi_alat.scale_observation.{$tahap}.{$kunci}",
                "{$label} — {$judul}",
                'angka',
            ),
            $kotak,
            array_keys($kotak),
        ));
    }

    /** @return array<string, mixed> */
    private function bagianEffectOfTare(): array
    {
        return [
            'kode' => 'effect_of_tare',
            'halaman' => 1,
            'judul' => '2. EFFECT OF TARE',
            'field' => [
                // Awalan `spesifikasi_alat.` — lihat `fieldScaleObservation()`
                // soal kenapa kode bertitik tanpa awalan itu read-only di HP.
                $this->f('spesifikasi_alat.effect_of_tare.standar', 'Standar Weight', 'angka'),
                $this->f('spesifikasi_alat.effect_of_tare.m1', 'm1', 'angka'),
                $this->f('spesifikasi_alat.effect_of_tare.m2', 'm2', 'angka'),
                $this->f('spesifikasi_alat.effect_of_tare.bentuk_pan', 'Bentuk Pan', 'pilihan', pilihan: [
                    ['nilai' => 'kotak', 'label' => 'Kotak'],
                    ['nilai' => 'lingkaran', 'label' => 'Lingkaran'],
                ]),
                $this->f('spesifikasi_alat.effect_of_tare.ukuran_pan', 'Ukuran / Diameter Pan', 'teks'),
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
                // Nominal keping PER BARIS, bukan satu kotak untuk selembar.
                //
                // Draf pertama menaruhnya sebagai `measurements.*.nominal` di
                // `field` bagian ini — satu kotak, di luar tabel. Itu tidak
                // bisa benar: tiap titik punya SUSUNAN kepingnya sendiri
                // (`20+20+10` di titik 5, `20+20+20+10` di titik 7), dan satu
                // kotak untuk sepuluh titik tidak punya cara dipetakan balik.
                //
                // `kolom_baris` sudah jadi tempat kotak tambahan per baris di
                // lembar lain (`no_probe` Thermocouple), jadi bentuknya sudah
                // dikenal HP; yang baru cuma isinya.
                //
                // JUMLAH nominal inilah yang jadi `titik_ukur` titik itu —
                // dihitung server (lihat `susunBlokTimbangan`), bukan dikirim
                // HP, supaya cuma ada satu angka yang mengaku mewakilinya.
                'kolom_baris' => [
                    $this->f(
                        'nominal',
                        'Nominal keping (pisahkan dengan +)',
                        'daftar_angka',
                        satuan: $satuan,
                    ),
                ],
                'tahap' => 'sesudah_adjustment',
                // `grup`, dan SENGAJA tanpa `peran`. Di HP `tabel.peran`
                // bukan label bebas: nilainya-yang-bukan-null berarti "lembar
                // ini membaca DUA deret per titik (standar & uut)", dan itu
                // membelokkan SELURUH lembar ke jalur pasangan — payloadnya
                // berangkat berisi kunci `standar`/`uut` tanpa satu pun
                // nominal, dan kunci barisnya jatuh ke offset parameter yang
                // bikin tabel ini bentrok lagi dengan tabel sebelahnya.
                // Dua tabel yang beda isinya dibedakan `grup`, seperti ketiga
                // tabel Spectrophotometer.
                'grup' => 'akurasi',
                // Blok ini TIDAK bisa difoto — kertasnya daftar menurun, bukan
                // grid. Alasan lengkapnya di `bentukPindaiFoto()`.
                'pindai_foto' => false,
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
            // Kosong: kotak nominalnya sekarang duduk PER BARIS di dalam
            // tabel (`kolom_baris`), bukan satu kotak untuk selembar.
            'field' => [],
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
                // Tanpa `peran` — lihat alasannya di `bagianAkurasi()`.
                'grup' => 'keterulangan',
                'judul' => 'Repeatability — 10 pengulangan',
                'satuan' => $satuan,
                // Geser kunci baris tabel ini di LAYAR supaya tidak rebutan
                // dengan baris Accuracy. Tabrakannya nyata di sesi contoh kg:
                // Accuracy memuat titik 50 kg & 100 kg, dan Middle/Maximum di
                // sini juga 50 kg & 100 kg. Tanpa offset, dua tabel berbagi
                // satu baris isian — angka yang diketik di salah satunya
                // muncul di kotak satunya lagi, tanpa error.
                //
                // Angkanya cuma harus TIDAK bertabrakan; 1000 dipilih karena
                // di atas kapasitas titik akurasi mana pun yang masuk akal
                // (master paling panjang 10 titik).
                'offset_kunci' => 1000,
                // Isi tabel ini TIDAK masuk `measurements[]` — dia besaran
                // tingkat-SESI (dua kapasitas, bukan dua titik ukur), jadi
                // tempatnya `calibration_sessions.spesifikasi_alat` bareng
                // empat blok lainnya.
                //
                // Dinyatakan lewat `simpan_ke` supaya HP tahu dari BENTUKNYA,
                // bukan dari daftar kode profil yang ditulis di layar —
                // daftar begitu menyusut diam-diam tiap ada alat baru, dan
                // yang ketinggalan justru yang paling baru. Kunci ini sudah
                // dipakai lembar TIDS untuk maksud yang serumpun.
                'simpan_ke' => 'spesifikasi_alat.keterulangan',
                // Kertasnya menyusun blok ini KE BAWAH: kolom `No.` berisi
                // 1..10 turun, dan yang berjajar ke samping dua KAPASITAS —
                // masing-masing dengan sepasang sub-kolom `Zero (zi)` /
                // `Reading (mi)`. Bentuk yang sebelumnya dikirim di sini
                // transposed dari itu.
                //
                // Bukan soal selera tata letak. Pemeta foto menjangkar tiap
                // angka ke DUA sumbu, dan dua-duanya diambil dari tulisan yang
                // TERCETAK: nomor pengulangan di kolom kiri, dan kepala slot
                // di atas. Dijalankan pada bentuk yang transposed, kedua
                // jangkarnya ada di sumbu yang salah — hasilnya nol sel, dan
                // seluruh angka dibuang. Layar pun jadi lebih sulit dibaca:
                // teknisi memegang kertas yang barisnya turun sementara
                // layarnya melebar sepuluh kolom.
                'sumbu_pengulangan' => 'baris',
                'slot_cetak' => $this->slotKeterulangan($rentang),
                // Satu-satunya tabel lembar ini yang bentuk kertasnya muat di
                // pemeta foto — lihat `bentukPindaiFoto()`.
                'pindai_foto' => true,
                // Nomor pengulangan seperti TERCETAK: `1`..`10` polos, bukan
                // `X1`/`Repeat 1`.
                //
                // Bedanya menentukan, dan sumbernya beda kertas. `X1` itu
                // bawaan lembar cetak SIDIK sendiri (`ocr:cetak-lembar`);
                // yang difoto teknisi di lembar ini kertas MASTER LAB, dan
                // kolom `No.`-nya menomori polos. Dibiarkan pakai bawaan,
                // tidak ada satu pun jangkar baris yang ketemu dan tiap
                // jepretan pulang nol sel.
                //
                // Nomor polos aman di sini karena pemetanya menolak
                // menerimanya satu-satu — yang diterima cuma DERETNYA utuh,
                // tersusun tegak di satu kolom, di kiri kolom data. Lihat
                // `_jangkarNomorPolosBaris` di HP.
                'pengulangan_arah' => array_map(
                    static fn (int $i): array => ['ke' => $i, 'label' => (string) $i],
                    range(1, 10),
                ),
                'judul_nilai' => 'Kapasitas',
                'judul_pengulangan' => 'No.',
                // FALSE, dan bedanya dari tabel Accuracy di atas bukan
                // kelalaian. `titik_bisa_diubah` di HP menggerakkan SATU daftar
                // titik yang dipakai bersama seluruh lembar (`titikKustom`) —
                // benar buat lembar yang tabel Before & After-nya memang satu
                // daftar, salah total di sini: begitu teknisi menyusun sepuluh
                // titik Accuracy, tabel ini ikut berubah jadi sepuluh baris
                // Middle/Maximum yang tidak ada di kertas mana pun. Nol error;
                // yang hilang delapan belas kotak keterulangan.
                //
                // Kedua kapasitasnya memang tidak perlu diketik: setengah dan
                // penuh dari `range_max` alat, sumber yang sama yang dipakai
                // tangga Accuracy. Kalau angkanya salah, yang dibetulkan master
                // alatnya — bukan diketik ulang tiap sesi.
                'titik_bisa_diubah' => false,
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
                // Label kolom ditulis PERSIS seperti tercetak — termasuk
                // satuannya, dan itu bagian yang menjaga angka tidak ketukar.
                //
                // Kertas gram menulis `Zero (g)` / `Reading (g)`; kertas kg dan
                // substitusi menulis `Zero (kg)` / `Reading (kg)`. Pemeta foto
                // memakai tulisan ini sebagai jangkar sub-kolom, jadi lembar
                // gram yang difoto ke sesi ber-satuan kg TIDAK akan menemukan
                // jangkarnya dan pulang nol sel — gagal dengan berisik, bukan
                // memindahkan 24,9999 g ke kotak kg. Itu satu-satunya jenis
                // kegagalan yang bisa ditanggung di sini.
                'kolom' => [
                    ['kode' => 'zero', 'label' => sprintf('Zero (%s)', $satuan), 'tipe' => 'angka', 'satuan' => $satuan],
                    ['kode' => 'pembacaan', 'label' => sprintf('Reading (%s)', $satuan), 'tipe' => 'angka', 'satuan' => $satuan],
                ],
                'jumlah_pengulangan' => 10,
                'pengulangan' => range(1, 10),
            ]],
            // Dua kotak kapasitas uji — DIKETIK, bukan diturunkan dari rentang
            // alat. Lihat `fieldKapasitasKeterulangan()`; master gram yang
            // membuktikan kenapa.
            'field' => $this->fieldKapasitasKeterulangan($satuan),
        ];
    }

    /**
     * Dua kepala slot Repeatability seperti TERCETAK di kertas.
     *
     * Kertasnya menulis dua baris kepala per slot: nama kapasitasnya
     * (`Middle Capacity` / `Maximum Capacity`) lalu ANGKANYA (`50` / `100`).
     * Yang dikirim sebagai jangkar cuma NAMANYA.
     *
     * ## Kenapa angkanya TIDAK ikut jadi jangkar
     *
     * Angka itu tidak kita ketahui. Kelihatannya setengah & penuh dari rentang
     * alat, dan buat dua master memang begitu — tapi master GRAM membantahnya:
     * alatnya berkapasitas **54 g** dan keterulangannya diambil di **25 g dan
     * 50 g**, bukan 27 g dan 54 g. Kapasitas uji itu pilihan teknisi (keping
     * yang ada di van), bukan turunan spesifikasi alat.
     *
     * Menjangkar ke angka yang ditebak lebih buruk daripada tidak menjangkar:
     * `27` tidak akan ketemu di kertas, dan kolomnya gagal tanpa alasan yang
     * kebaca. Nama slotnya selalu benar dan selalu ada.
     *
     * `titik_ukur` tetap dikirim, tapi tugasnya cuma IDENTITAS BARIS di layar
     * (dipasangkan lewat urutan: Middle dulu, Maximum kedua). Angka yang
     * dipakai menghitung datang dari dua kotak isian di `field` bagian ini —
     * lihat `fieldKapasitasKeterulangan()`.
     *
     * @return list<array<string, mixed>>
     */
    private function slotKeterulangan(float $rentang): array
    {
        $tengah = $rentang > 0.0 ? round($rentang / 2, 6) : 0.0;

        // `satuan` SENGAJA tidak dikirim per slot.
        //
        // HP memakai `slot.satuan ?? kolom.label` untuk DUA hal sekaligus:
        // tulisan kepala sub-kolom yang digambar, dan jangkar sub-kolom yang
        // dicari pemeta foto. Diisi `kg`, kedua sub-kolom slot ini berjangkar
        // tulisan yang SAMA — `Zero` dan `Reading` jadi tak terbedakan, dan
        // angka nol bisa mendarat di kolom berbeban. Dikosongkan, keduanya
        // jatuh ke `kolom.label` yang memang sudah membawa satuannya
        // (`Zero (kg)` / `Reading (kg)`), persis seperti tercetak.
        return [
            [
                'label' => 'Middle Capacity',
                'titik_ukur' => [$tengah],
            ],
            [
                'label' => 'Maximum Capacity',
                // Kertas `SIDIK-FM-CAL-0508.A_Rev.4` mengetik **`Miximum
                // Capacity`** — salah ketik yang ada di formulir resminya,
                // bukan di sini.
                //
                // Dikirim sebagai jangkar kedua, bukan diperbaiki diam-diam:
                // pemeta foto mencocokkan tulisan yang TERCETAK, jadi tanpa
                // ejaan ini slot Maximum tidak pernah ketemu dan SEPARUH tabel
                // (dua puluh angka) hilang tiap jepretan — sementara slot
                // Middle tetap kejangkar, jadi hasilnya kelihatan "separuh
                // kebaca" bukan "gagal".
                //
                // Ejaan yang benar tetap dikirim duluan supaya kertas yang
                // suatu saat direvisi tetap kejangkar tanpa perubahan kode.
                'varian' => 'Miximum Capacity',
                'titik_ukur' => [$rentang],
            ],
        ];
    }

    /**
     * Dua kotak KAPASITAS UJI keterulangan — diketik teknisi, bukan diturunkan.
     *
     * ## Kenapa ini bukan detail tata letak
     *
     * Angka ini masuk rumus, di dua tempat sekaligus:
     *
     *  1. `deviasiKurangiNominal` (nyala di varian **gram** dan **substitusi**)
     *     membuat tiap deviasi jadi `m − z − nominal`. Nominal yang meleset
     *     2 g menggeser SELURUH sepuluh deviasi, dan lewat situ `Sr`, lantai
     *     `Sres`, `u`, dan `vi` blok keterulangan.
     *  2. `srTerdekat($titikAkurasi, $nominal)` memilih titik akurasi mana yang
     *     `Sr`-nya diadu ke lantai. Nominal yang meleset memilih titik yang
     *     salah.
     *
     * Diturunkan dari `range_max` (setengah & penuh) angkanya SALAH untuk
     * master gram — 54 g jadi 27/54, sementara kertasnya 25/50 — dan salahnya
     * tidak memunculkan apa pun: kesepuluh pembacaan tetap masuk, budgetnya
     * tetap terbit, cuma angkanya bukan angka yang benar.
     *
     * Kodenya bersarang di bawah `spesifikasi_alat.keterulangan` supaya
     * mendarat di blok yang sama dengan pembacaannya, bukan jadi kunci ketiga
     * yang harus dijahit di sisi server.
     *
     * @return list<array<string, mixed>>
     */
    private function fieldKapasitasKeterulangan(string $satuan): array
    {
        return [
            $this->f(
                'spesifikasi_alat.keterulangan.mid.nominal',
                'Middle Capacity — beban yang dipakai',
                'angka',
                satuan: $satuan,
            ),
            $this->f(
                'spesifikasi_alat.keterulangan.maks.nominal',
                'Maximum Capacity — beban yang dipakai',
                'angka',
                satuan: $satuan,
            ),
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
                // Blok ini MENGGERAKKAN ANGKA: rentang (maks − min) posisinya
                // jadi komponen Eccentricity di budget U95% of Weighing. Jadi
                // awalan `spesifikasi_alat.` di sini bukan kerapian — tanpa
                // itu keenam kotaknya read-only di HP dan komponennya nol
                // terus, di setiap sesi. Lihat `fieldScaleObservation()`.
                $this->f('spesifikasi_alat.eksentrisitas.beban', 'Beban yang dipakai', 'angka'),
                $this->f('spesifikasi_alat.eksentrisitas.baca.center', 'Center', 'angka'),
                $this->f('spesifikasi_alat.eksentrisitas.baca.front', 'Front', 'angka'),
                $this->f('spesifikasi_alat.eksentrisitas.baca.back', 'Back', 'angka'),
                $this->f('spesifikasi_alat.eksentrisitas.baca.left', 'Left', 'angka'),
                $this->f('spesifikasi_alat.eksentrisitas.baca.right', 'Right', 'angka'),
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
                $this->f('spesifikasi_alat.histeresis.m', 'M', 'angka'),
                $this->f('spesifikasi_alat.histeresis.m_aksen', "M'", 'angka'),
                // Delapan kotak per deret, bernomor EKSPLISIT — bukan satu
                // kotak ber-wildcard. Rumusnya membaca posisi tertentu
                // (`b[0] + b[7] − b[2] − b[5]`), jadi urutannya bagian dari
                // artinya: satu kotak yang isinya "20 40 20 0 …" tidak punya
                // cara dipetakan balik ke delapan posisi itu tanpa menebak.
                ...$this->fieldHisteresis(1),
                ...$this->fieldHisteresis(2),
            ],
        ];
    }

    /**
     * Delapan kotak satu deret Hysterisis, urut seperti tercetak.
     *
     * Label posisinya ikut kertas master: `[M(p1), M+M', M(q1), Zero, M+M',
     * M(q2), Zero, M(p2)]`. Ditulis di label, bukan cuma di komentar, karena
     * yang mengisinya teknisi yang sedang memegang kertas itu — dan kotak
     * bernomor 1..8 tanpa nama posisi bikin dia harus menghitung kolom.
     *
     * @return list<array<string, mixed>>
     */
    private function fieldHisteresis(int $deret): array
    {
        // Nomor p/q BERLANJUT antar deret, tidak mengulang dari 1.
        //
        // Kertasnya menomori Reading 1 `p1 q1 q2 p2` dan Reading 2
        // `p3 q3 q4 p4` — delapan pembebanan yang berbeda, bukan dua kali
        // empat. Dilabeli sama dua-duanya, teknisi yang mencocokkan layar ke
        // kertas menemukan `M(p1)` di dua tempat dan harus menebak yang mana.
        // Rumusnya sendiri membaca POSISI, jadi ini murni soal kotak yang
        // diisi teknisi mendarat di posisi yang dia kira.
        $mulai = ($deret - 1) * 2;
        $posisi = [
            sprintf('M(p%d)', $mulai + 1),
            "M+M'",
            sprintf('M(q%d)', $mulai + 1),
            'Zero',
            "M+M'",
            sprintf('M(q%d)', $mulai + 2),
            'Zero',
            sprintf('M(p%d)', $mulai + 2),
        ];

        return array_values(array_map(
            fn (int $i): array => $this->f(
                "spesifikasi_alat.histeresis.baca{$deret}.{$i}",
                sprintf('Reading %d — %s', $deret, $posisi[$i]),
                'angka',
            ),
            range(0, 7),
        ));
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
