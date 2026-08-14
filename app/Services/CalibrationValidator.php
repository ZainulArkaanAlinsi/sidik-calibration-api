<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\Angka;
use Illuminate\Support\Collection;

/**
 * Pemeriksaan ulang sebelum sertifikat diterbitin (spesifikasi poin 11).
 *
 * Angka di sertifikat NGGAK boleh cuma disalin dari apa yang tersimpan waktu
 * teknisi submit. Sistem ngitung ULANG dari pembacaan mentah, terus ngadu
 * hasilnya sama yang tersimpan. Kalau beda, ada yang berubah di antaranya —
 * data alat diedit, standar diganti, atau barisnya disentuh langsung di DB.
 * Itu justru kasus yang paling berbahaya buat dokumen resmi, dan paling nggak
 * mungkin ketahuan dengan dilihat mata.
 *
 * Tiga tingkat temuan, karena nggak semuanya sama beratnya:
 *
 * - `error`      — sertifikat NGGAK BOLEH terbit. Datanya rusak/nggak lengkap
 *                  sampai keputusan PASS/FAIL nggak bisa dipertanggungjawabkan.
 * - `peringatan` — angka hasil hitung ulang beda dari yang tersimpan. Admin
 *                  boleh lanjut, tapi harus sadar & eksplisit (`abaikan_peringatan`).
 * - `info`       — kolom administratif sertifikat masih kosong. Nggak nahan
 *                  penerbitan; cuma bikin sertifikatnya ada strip.
 */
class CalibrationValidator
{
    public const ERROR = 'error';

    public const PERINGATAN = 'peringatan';

    /**
     * Berapa kali lipat CMC sebelum U95 dianggap mustahil.
     *
     * 10x sengaja longgar. Alat pelanggan yang bener-bener nggak stabil bisa
     * keluar 2–3x CMC, dan itu temuan yang sah — bukan sesuatu yang pantas
     * ditahan. Yang dicari di sini salah ketik, dan salah ketik satu digit
     * biasanya ngasih puluhan sampai ratusan kali lipat.
     */
    private const FAKTOR_U95_MELEDAK = 10.0;

    public const INFO = 'info';

    public function __construct(
        private readonly GumCalculator $gum,
        private readonly CalibrationProfileRegistry $profil,
    ) {}

    /**
     * @return array{
     *     valid: bool,
     *     boleh_terbit: bool,
     *     temuan: list<array<string, mixed>>,
     *     ringkasan: array<string, int>,
     * }
     */
    public function periksa(CalibrationSession $sesi): array
    {
        $sesi->loadMissing([
            'equipment', 'teknisi', 'standard', 'standarDicek',
            'rawMeasurements', 'uncertaintyCalculations.standard',
        ]);

        $temuan = [
            ...$this->periksaKelengkapanHitung($sesi),
            ...$this->periksaPembacaanMustahil($sesi),
            ...$this->periksaKondisiLingkunganMustahil($sesi),
            ...$this->periksaTiapTitik($sesi),
            ...$this->periksaU95MeledakDariCmc($sesi),
            ...$this->periksaKeputusanSesi($sesi),
            ...$this->periksaKelengkapanSertifikat($sesi),
            ...$this->periksaPeringatanProfil($sesi),
        ];

        $ringkasan = [
            self::ERROR => 0,
            self::PERINGATAN => 0,
            self::INFO => 0,
        ];

        foreach ($temuan as $t) {
            $ringkasan[$t['tingkat']]++;
        }

        return [
            // `valid` = nggak ada temuan sama sekali di luar info.
            'valid' => $ringkasan[self::ERROR] === 0 && $ringkasan[self::PERINGATAN] === 0,
            // `boleh_terbit` = nggak ada yang fatal. Peringatan masih bisa
            // dilewatin admin secara sadar.
            'boleh_terbit' => $ringkasan[self::ERROR] === 0,
            'temuan' => $temuan,
            'ringkasan' => $ringkasan,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function periksaKelengkapanHitung(CalibrationSession $sesi): array
    {
        $temuan = [];

        if ($sesi->uncertaintyCalculations->isEmpty()) {
            $temuan[] = $this->temuan(
                self::ERROR,
                'titik_kosong',
                'Sesi ini nggak punya satu pun titik hasil hitung — nggak ada yang bisa disertifikasi.',
            );
        }

        // Toleransi kosong cuma jadi temuan kalau alatnya MEMANG divonis
        // PASS/FAIL. Ada jenis alat yang nggak — Conductivity Meter nggak punya
        // satu pun batas keberterimaan di seluruh master-nya, dan sertifikatnya
        // berhenti di `Correction` + `U95%`. Buat alat kayak gitu, `toleransi`
        // NULL itu jawaban yang benar, dan nahan penerbitannya berarti minta
        // orang ngarang kriteria kelulusan biar lembarnya bisa lewat.
        $profilAlat = $sesi->equipment !== null
            ? $this->profil->untukAlat($sesi->equipment)
            : null;

        if ($sesi->equipment === null
            || ($sesi->equipment->toleransi === null && $profilAlat?->punyaToleransi() !== false)) {
            $temuan[] = $this->temuan(
                self::ERROR,
                'toleransi_kosong',
                'Alat belum punya nilai toleransi, jadi keputusan PASS/FAIL nggak punya dasar.',
            );
        }

        // Titik yang datanya kecatat di lembar kerja tapi nggak kehitung —
        // pembacaannya kurang dari 2, standarnya belum dipilih, atau alatnya
        // belum punya toleransi. Titik ini NGGAK bakal muncul di sertifikat,
        // dan admin harus tau itu sebelum nerbitin, bukan sesudah pelanggan
        // nanya kenapa barisnya kurang.
        $titikMentah = $sesi->rawMeasurements
            ->where('tahap', 'sesudah_adjustment')
            ->pluck('titik_ke')
            ->unique();
        $titikTerhitung = $sesi->uncertaintyCalculations->pluck('titik_ke')->unique();

        foreach ($titikMentah->diff($titikTerhitung)->sort() as $ke) {
            $temuan[] = $this->temuan(
                self::PERINGATAN,
                'titik_tidak_terhitung',
                "Titik ke-{$ke} ada pembacaannya tapi nggak kehitung — datanya belum cukup "
                    .'(pengulangan kurang dari '.GumCalculator::MIN_PENGULANGAN
                    .', standar acuan belum dipilih, atau toleransi alat kosong). '
                    .'Titik ini nggak akan muncul di sertifikat.',
                ['titik_ke' => (int) $ke],
            );
        }

        if ($sesi->rawMeasurements->where('is_verified', false)->isNotEmpty()) {
            $temuan[] = $this->temuan(
                self::ERROR,
                'ocr_belum_diverifikasi',
                'Masih ada pembacaan hasil OCR yang belum dikonfirmasi teknisi.',
            );
        }

        // Standar yang sertifikatnya kadaluarsa bikin ketertelusuran putus —
        // temuan asesor, dan sertifikatnya bisa ditarik.
        foreach ($this->standarTerpakai($sesi) as $standar) {
            if (! $standar->masihBerlaku()) {
                $temuan[] = $this->temuan(
                    self::ERROR,
                    'standar_kadaluarsa',
                    "Sertifikat standar \"{$standar->nama}\" udah lewat masa berlaku "
                        .$standar->berlaku_sampai?->format('d/m/Y').'.',
                    ['standard_id' => $standar->id],
                );
            }
        }

        return $temuan;
    }

    /**
     * Pembacaan yang **alatnya sendiri nggak mungkin nampilin** — bukan
     * pembacaan yang "kelihatan aneh".
     *
     * ## Kenapa cuma yang mustahil, bukan yang mencurigakan
     *
     * 6 Agt 2026 sesi chlorine kesimpen dengan pembacaan 1,90 buat standar 1,83,
     * padahal kertas labnya 1,86 — salah ketik satu digit yang nembus sampai
     * sertifikat terbit. Yang pertama dicoba: nyalain peringatan kalau
     * |error| + U mepet ke toleransi. Diadu ke data nyata, ambangnya gugur:
     *
     *     Turbidimeter master, titik 1.000 NTU  → rasio 0,958
     *     pH master 012-CAL-524, titik 10,01    → rasio 0,811
     *     Chlorine 1,90 (yang salah)            → rasio 1,000
     *     Chlorine 1,86 (yang benar)            → rasio 0,733
     *
     * Ambang mana pun yang nangkep si 1,90 ikut nyalain peringatan di dua
     * SERTIFIKAT MASTER yang benar. Alasannya sederhana: 1,90 buat standar 1,83
     * itu meleset 3,8% — angka yang sama sekali wajar buat alat bertoleransi
     * 8%. Nggak ada ambang yang bisa misahin salah ketik dari penyimpangan
     * beneran, karena emang nggak beda. Yang bisa mbedain cuma orang yang
     * mbandingin ke lembar kerjanya.
     *
     * Jadi yang diperiksa di sini cuma yang OBJEKTIF salah, dan dua-duanya
     * nggak nyala satu kali pun di data master ketiga alat:
     *
     *  1. **Di luar rentang ukur alat** — nangkep koma kegeser (18,6 buat alat
     *     0–4 mg/L).
     *  2. **Bukan kelipatan resolusi** — nangkep digit kelebihan (1,867 di alat
     *     yang layarnya cuma bisa nampilin dua desimal). Alatnya nggak bisa
     *     nampilin angka itu, jadi nggak mungkin itu yang dibaca teknisi.
     *
     * Dua-duanya PERINGATAN, bukan error: admin tetap boleh lanjut kalau dia
     * yakin (mis. alat rusak yang beneran nampilin di luar rentang), asal sadar.
     *
     * Tahap `sebelum_adjustment` ikut diperiksa. Dia nggak masuk hitungan
     * sertifikat, tapi tetap kecetak di lembar kerja yang diarsip — dan salah
     * ketik di situ sama nyasarnya buat orang yang baca arsipnya nanti.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaPembacaanMustahil(CalibrationSession $sesi): array
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return [];
        }

        $profil = $this->profil->untukAlat($alat);
        $satuan = $alat->satuan ?? '';
        $temuan = [];

        foreach ($sesi->rawMeasurements->sortBy(['titik_ke', 'pembacaan_ke']) as $m) {
            /** @var RawMeasurement $m */
            $nilai = $m->pembacaan === null ? null : (float) $m->pembacaan;

            if ($nilai === null) {
                continue;
            }

            $ke = (int) $m->titik_ke;
            $ulang = (int) $m->pembacaan_ke;
            $di = "Titik ke-{$ke} Repeat {$ulang}";

            // Dibandingin dalam SATUAN ALAT, bukan satuan yang kecatat di baris
            // pembacaan. Buat alat yang lembarnya satu satuan (hampir semuanya)
            // ini nggak ngubah angkanya sama sekali; buat Conductivity Meter —
            // yang nyampur µS/cm & mS/cm dalam satu lembar — ini yang bikin
            // 1413 µS/cm nggak lagi ke-flag "di luar rentang 0–100 mS/cm".
            $nilaiAlat = $profil->nilaiDalamSatuanAlat($nilai, $m->satuan, $alat);
            $titikAlat = $profil->nilaiDalamSatuanAlat((float) $m->titik_ukur, $m->satuan, $alat);

            // Pembacaan yang DEKAT ke titik yang lagi dikalibrasi dilewat,
            // walau titiknya sendiri di luar rentang terdaftar alat.
            //
            // Aturan ini ada buat nangkep SALAH KETIK ("komanya kegeser"), dan
            // pembacaan di titik yang emang lagi diukur menurut definisi bukan
            // salah ketik — itu justru angka yang diharapkan.
            //
            // Kasus nyatanya Conductivity: larutan standarnya 111 mS/cm
            // sementara rentang alat kecatat 0–100 mS/cm (masternya sendiri
            // nulis `Rentang Ukur 0-100` lalu ngalibrasi di 111). Tiap approve
            // ngeluarin 4 peringatan yang selalu bisa diabaikan — dan itu yang
            // bahaya: admin belajar nekan "SETUJUI TETAP" tanpa baca, lalu
            // peringatan yang beneran penting ikut tenggelam.
            //
            // Yang meleset jauh TETAP kena: 1106,7 atau 11,067 di titik 111
            // masih ke-flag. Kalau titik standarnya sendiri emang di luar
            // kemampuan alat, itu pertanyaan ke master alat — bukan sesuatu
            // yang pantas diteriakin per pembacaan.
            if ($this->diLuarRentang($nilaiAlat, $alat)
                && ! $this->dekatTitikStandar($nilaiAlat, $titikAlat)) {
                $temuan[] = $this->temuan(
                    self::PERINGATAN,
                    'pembacaan_di_luar_rentang',
                    "{$di}: pembacaan {$nilai} {$satuan} jauh di luar rentang ukur alat "
                        ."({$alat->range_min}–{$alat->range_max} {$satuan}). "
                        .'Kemungkinan besar komanya kegeser waktu ngetik.',
                    ['titik_ke' => $ke, 'pembacaan_ke' => $ulang, 'nilai' => $nilai],
                );

                continue;
            }

            // Resolusi bisa beda per titik (Turbidimeter: 0,01 / 0,1 / 1 NTU),
            // jadi ditanya ke profilnya dulu — bukan langsung pakai satu angka
            // di master alat.
            $resolusi = $profil->resolusiTitik((float) $m->titik_ukur)
                ?? ($alat->resolusi === null ? null : (float) $alat->resolusi);

            if ($resolusi === null || $resolusi <= 0) {
                continue;
            }

            if (! $this->kelipatanDari($nilai, $resolusi)) {
                $temuan[] = $this->temuan(
                    self::PERINGATAN,
                    'pembacaan_bukan_kelipatan_resolusi',
                    "{$di}: pembacaan {$nilai} {$satuan} bukan kelipatan resolusi alat "
                        ."({$resolusi} {$satuan}), jadi layarnya nggak mungkin nunjukin angka itu. "
                        .'Kemungkinan besar kelebihan digit waktu ngetik.',
                    ['titik_ke' => $ke, 'pembacaan_ke' => $ulang, 'nilai' => $nilai, 'resolusi' => $resolusi],
                );
            }
        }

        return $temuan;
    }

    /**
     * Di luar rentang ukur, **dikasih pita jaga selebar toleransi alat**.
     *
     * Batas mentahnya nggak bisa dipakai apa adanya. Titik kalibrasi teratas
     * itu justru duduk PERSIS di batas rentang, jadi pembacaan yang normal pun
     * lewat sedikit: master Turbidimeter (`Master Data TurbidiMeter_CSV`) nyatet
     * 1001 NTU di titik 1.000 NTU sementara rentang alatnya 0–1000, dan itu
     * angka asli yang kecetak di sertifikat lab (UUT 1.000,6). Tanpa pita jaga,
     * empat dari lima pembacaan di titik teratas ke-flag tiap sesi Turbidimeter
     * — persis jenis temuan yang bikin orang berhenti baca temuan.
     *
     * Toleransi alat dipakai sebagai lebar pitanya karena itu ukuran "masih
     * masuk akal buat alat ini" yang sudah dipegang lab, bukan angka baru yang
     * dikarang di sini. Chlorine rentang 0–4 toleransi 0,15: pembacaan 18,6
     * (koma kegeser dari 1,86) tetap ketangkep, 4,1 nggak.
     */
    /**
     * Batas kelembaban yang MASUK AKAL buat ruang lab. Bukan batas alat —
     * higrometer bisa baca 0–100 — tapi batas ruangan berpendingin yang lagi
     * dipakai kerja. Di luar ini hampir pasti salah ketik.
     */
    private const KELEMBABAN_MIN = 20.0;

    private const KELEMBABAN_MAKS = 90.0;

    /**
     * Pergeseran kelembaban selama satu sesi. Ruangan bisa naik-turun beberapa
     * persen; puluhan persen artinya salah satu dari dua angkanya salah ketik.
     */
    private const DELTA_KELEMBABAN_WAJAR = 20.0;

    /**
     * Pembacaan thermohygro yang ruangannya nggak mungkin segitu.
     *
     * Sesi Turbidimeter `KAL/2026/08/0021` (8 Agt 2026) kesimpen
     * `kelembaban_awal = 2`. Dua persen RH itu lebih kering dari gurun — jelas
     * `52` yang kepencet jadi `2`. Yang bikin mahal bukan salah ketiknya, tapi
     * sistemnya nerima tanpa sepatah kata: Δ jadi 53, dan sertifikatnya kecetak
     * `%RH: 28% ± 53%` — ketidakpastian dua kali lipat nilainya sendiri, di
     * dokumen terakreditasi.
     *
     * Rumus U95%-nya sendiri bener (`√(4,8² + 53²)`), dan itu justru intinya:
     * masukan ngawur nggak bikin hitungannya meledak, cuma bikin hasilnya
     * ngawur dengan rapi. Jadi yang mesti nangkep validator, bukan rumusnya.
     *
     * ## Kenapa naik dari PERINGATAN ke ERROR
     *
     * Awalnya peringatan, alasannya masuk akal: admin yang mutusin, dan sesi
     * insitu di gudang tanpa AC beneran bisa lembab ekstrem.
     *
     * Yang nggak diperhitungkan: peringatan yang bisa diabaikan itu, ternyata,
     * diabaikan. Disisir ke MySQL produksi 14 Agt 2026 — DUA sertifikat sudah
     * beredar dengan angka yang persis diperingatkan di sini:
     *
     *     CAL/2026/08/0022  (KAL/2026/08/0025)  %RH: 28% ± 53,2%
     *     CAL/2026/08/0027  (KAL/2026/08/0031)  %RH: 27% ± 53,2%
     *
     * Ketidakpastian dua kali lipat nilainya sendiri, di dokumen terakreditasi.
     * Peringatannya nyala di dua-duanya dan tetap di-"SETUJUI TETAP".
     *
     * Jadi batasnya digeser ke tempat yang beda: rentang [KELEMBABAN_MIN] –
     * [KELEMBABAN_MAKS] itu 20–90 %RH, dan di luar itu bukan "ruangan yang
     * ekstrem" — itu angka yang nggak bisa dibaca thermohygro mana pun di
     * ruangan berpenghuni. Gudang tanpa AC paling parah masih di dalam rentang.
     * Yang di luar rentang cuma dua kemungkinan: salah ketik, atau alatnya
     * rusak. Dua-duanya nggak pantas jadi sertifikat.
     *
     * Delta yang ekstrem TETAP peringatan: dua angka yang sama-sama masuk
     * rentang tapi jauh (30 → 80) beneran bisa kejadian di sesi panjang.
     *
     * Cara mbenerin sesi yang ketahan: ralat angkanya di lembar kerja. Kalau
     * angkanya emang segitu, yang mesti dicek thermohygro-nya — bukan
     * sertifikatnya yang dipaksa keluar.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaKondisiLingkunganMustahil(CalibrationSession $sesi): array
    {
        $temuan = [];

        foreach (['awal' => $sesi->kelembaban_awal, 'akhir' => $sesi->kelembaban_akhir] as $kapan => $nilai) {
            if ($nilai === null) {
                continue;
            }

            $nilai = (float) $nilai;

            if ($nilai >= self::KELEMBABAN_MIN && $nilai <= self::KELEMBABAN_MAKS) {
                continue;
            }

            $temuan[] = $this->temuan(
                self::ERROR,
                'kelembaban_mustahil',
                "Kelembaban {$kapan} kecatat {$nilai} %RH — di luar rentang wajar ruang lab ("
                .self::KELEMBABAN_MIN.'–'.self::KELEMBABAN_MAKS.' %RH). '
                .'Ralat angkanya di lembar kerja; kalau pembacaannya emang segitu, '
                .'thermohygro-nya yang mesti dicek.',
                ['kolom' => "kelembaban_{$kapan}", 'nilai' => $nilai],
            );
        }

        // Dicek terpisah dari batas di atas: dua angka yang sama-sama masuk
        // rentang masih bisa mustahil kalau jaraknya kejauhan (30 → 80).
        // Δ ini yang langsung masuk U95%, jadi dampaknya paling kelihatan.
        if ($sesi->kelembaban_awal !== null && $sesi->kelembaban_akhir !== null) {
            $delta = abs((float) $sesi->kelembaban_akhir - (float) $sesi->kelembaban_awal);

            if ($delta > self::DELTA_KELEMBABAN_WAJAR) {
                $temuan[] = $this->temuan(
                    self::PERINGATAN,
                    'delta_kelembaban_ekstrem',
                    "Kelembaban bergeser {$delta} %RH selama sesi ini — angka segitu langsung "
                    .'kebawa ke U95% kondisi lingkungan. Pastikan dua-duanya kecatat bener.',
                    [
                        'kolom' => 'kelembaban_akhir',
                        'awal' => (float) $sesi->kelembaban_awal,
                        'akhir' => (float) $sesi->kelembaban_akhir,
                        'delta' => $delta,
                    ],
                );
            }
        }

        return $temuan;
    }

    private function diLuarRentang(float $nilai, Equipment $alat): bool
    {
        if ($alat->range_min === null || $alat->range_max === null) {
            return false;
        }

        $pita = $alat->toleransi === null ? 0.0 : abs((float) $alat->toleransi);

        return $nilai < (float) $alat->range_min - $pita
            || $nilai > (float) $alat->range_max + $pita;
    }

    /**
     * `1.86` kelipatan `0.01`? Dihitung lewat pembulatan, bukan `fmod` — `fmod`
     * di floating point balikin sisa 0,00999999… buat angka yang jelas-jelas
     * pas, jadi tiap pembacaan bakal ke-flag.
     *
     * Toleransinya diikat ke besar angkanya (bukan epsilon tetap) supaya
     * pembacaan 1.001 NTU nggak kena cuma gara-gara galat representasi double.
     */
    private function kelipatanDari(float $nilai, float $resolusi): bool
    {
        $kelipatan = round($nilai / $resolusi);
        $selisih = abs($nilai - $kelipatan * $resolusi);

        return $selisih <= max(1e-9, 1e-6 * abs($nilai));
    }

    /**
     * Hitung ulang tiap titik dari pembacaan mentah, terus adu sama yang
     * tersimpan.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaTiapTitik(CalibrationSession $sesi): array
    {
        $temuan = [];
        $alat = $sesi->equipment;

        // Titik dikumpulin dulu, hitung ulangnya belakangan — lihat
        // [bandingkanHitungUlang].
        $siapHitung = [];

        // Cuma pembacaan SESUDAH adjustment yang masuk hitungan — as-found itu
        // dokumentasi kondisi awal alat, bukan dasar sertifikat.
        $pembacaanPerTitik = $sesi->rawMeasurements
            ->where('tahap', 'sesudah_adjustment')
            ->groupBy('titik_ke');

        // Suhu ruang MENTAH (awal+akhir)/2, sama persis kayak yang dipakai waktu
        // sesi ini pertama dihitung di `CalibrationController::susunPengukuran()`.
        // Wajib dikirim ke `hitungTitik()` di bawah: tanpa ini budget
        // Refractometer jatuh ke jalur CMC waktu hitung ulang, dan tiap
        // sertifikat refractometer ke-flag `ketidakpastian_beda` padahal angka
        // tersimpannya benar.
        $suhuRuangTerisi = array_values(array_filter(
            [$sesi->suhu_awal, $sesi->suhu_akhir],
            fn ($s): bool => $s !== null,
        ));
        $suhuRuang = $suhuRuangTerisi === []
            ? null
            : array_sum(array_map('floatval', $suhuRuangTerisi)) / count($suhuRuangTerisi);

        foreach ($sesi->uncertaintyCalculations->sortBy('titik_ke') as $titik) {
            /** @var UncertaintyCalculation $titik */
            $ke = (int) $titik->titik_ke;

            // Correction wajib = kebalikan error, apa pun yang kejadian di
            // hitungan ketidakpastian. Ini rumus mati, nggak ada toleransinya.
            if (! $this->samaDengan((float) $titik->koreksi, -(float) $titik->error)) {
                $temuan[] = $this->temuan(
                    self::ERROR,
                    'koreksi_tidak_konsisten',
                    "Titik ke-{$ke}: Correction ({$titik->koreksi}) nggak sama dengan kebalikan error ({$titik->error}).",
                    ['titik_ke' => $ke],
                );
            }

            $pembacaan = $pembacaanPerTitik->get($ke);

            if ($pembacaan === null || $pembacaan->isEmpty()) {
                $temuan[] = $this->temuan(
                    self::PERINGATAN,
                    'pembacaan_mentah_hilang',
                    "Titik ke-{$ke}: pembacaan mentahnya nggak ada, jadi angkanya nggak bisa dihitung ulang.",
                    ['titik_ke' => $ke],
                );

                continue;
            }

            if ($alat === null) {
                continue;
            }

            $standar = $titik->standard ?? $sesi->standard;

            if (! $standar instanceof Standard) {
                $temuan[] = $this->temuan(
                    self::PERINGATAN,
                    'standar_titik_hilang',
                    "Titik ke-{$ke}: standar acuannya nggak ketemu, hitung ulang dilewati.",
                    ['titik_ke' => $ke],
                );

                continue;
            }

            $temuan = [...$temuan, ...$this->periksaKoreksiSuhu(
                $ke,
                $standar,
                $pembacaan,
                $this->profil->untukAlat($alat)->standarBerkurvaSuhu(),
            )];

            $siapHitung[] = [
                'titik_ke' => $ke,
                'titik_ukur' => (float) $titik->titik_ukur,
                'pembacaan' => $pembacaan->sortBy('pembacaan_ke')
                    ->map(fn (RawMeasurement $m): float => (float) $m->pembacaan)
                    ->values()
                    ->all(),
                'standard' => $standar,
                // Suhu larutan yang kecatat di pembacaan, biar hitung ulang di
                // sini nurunin nilai acuan dengan cara yang SAMA kayak waktu
                // disimpen. Kalau nggak dikirim, pemeriksaan ini nerima
                // `titik_ukur` tersimpan apa adanya — dan `titik_ukur` yang
                // nggak cocok kurva suhu buffernya nggak akan pernah ketangkep.
                'suhu_larutan' => $this->suhuLarutanRataRata($pembacaan),
                'tersimpan' => $titik,
            ];
        }

        if ($alat !== null && $siapHitung !== []) {
            $temuan = [...$temuan, ...$this->bandingkanHitungUlang($siapHitung, $alat, $suhuRuang)];
        }

        return $temuan;
    }

    /**
     * Dua setengah pasang yang bikin nilai acuan diam-diam salah.
     *
     * Nilai acuan yang bener itu nilai larutan PADA SUHU PENGUKURAN, diturunin
     * dari kurva di sertifikat standarnya. Itu butuh DUA bahan: kurvanya ada di
     * master standar, dan suhu larutannya dicatat teknisi. Kalau salah satu
     * hilang, `GumCalculator` balik ke nilai nominal — dan itu **sengaja**,
     * karena teknisi di lapangan nggak boleh keblokir gara-gara satu kolom.
     *
     * Masalahnya, sampai sekarang nggak ada satu pun yang ngasih tau. Sertifikat
     * tetap terbit rapi, angkanya masuk akal, nol error di mana pun — padahal
     * kolom Correction meleset sebesar koreksi suhu yang nggak pernah kepakai.
     * Di titik pH 10 itu 0,065 pH pada alat bertoleransi 0,2: sepertiga
     * anggaran toleransi, cukup buat mbalik keputusan PASS/FAIL.
     *
     * Jadi dua-duanya PERINGATAN, bukan error: yang berubah cuma kegagalannya
     * jadi kelihatan sebelum admin menyetujui, bukan sesudah pelanggan nanya.
     *
     * ## Kenapa nggak nyalain peringatan buat semua standar tanpa kurva
     *
     * Standar panjang, massa, dan tekanan emang nggak punya kurva suhu dan
     * nggak butuh — nyalain peringatan buat mereka bikin tiap sesi non-pH
     * kebanjiran temuan yang nggak ada tindak lanjutnya, dan temuan yang selalu
     * muncul itu berhenti dibaca orang.
     *
     * Yang dipakai: **suhu larutan yang KECATAT** sebagai tanda bahwa suhu
     * memang relevan buat pengukuran ini. Teknisi yang repot nyatat suhu tiap
     * pembacaan lagi ngerjain alat yang suhunya ngaruh — dan kalau standarnya
     * ternyata nggak punya kurva, angka yang dia catat itu kebuang percuma.
     *
     * TAPI tanda itu nggak cukup sendirian. Turbidimeter & chlorine juga nyatet
     * suhu larutan — bukan buat ngoreksi nilai acuan, tapi karena suhunya masuk
     * budget ketidakpastian. Standarnya emang dibaca nominal, `koefisien_suhu`
     * NULL-nya disengaja. Tanpa saringan kedua, tiap sertifikat dua alat itu
     * ke-flag `valid: false` gara-gara perilaku yang justru diharapkan — persis
     * yang kejadian di sesi 7 & 11–13. Makanya profil alatnya ikut ditanya lewat
     * [$standarBerkurvaSuhu]; peringatan ini cuma buat standar yang MESTINYA
     * berkurva tapi datanya belum diisi.
     *
     * @param  Collection<int, RawMeasurement>  $pembacaan
     * @param  bool  $standarBerkurvaSuhu  dari `CalibrationProfile::standarBerkurvaSuhu()`
     * @return list<array<string, mixed>>
     */
    private function periksaKoreksiSuhu(
        int $ke,
        Standard $standar,
        $pembacaan,
        bool $standarBerkurvaSuhu = true,
    ): array {
        $suhu = $this->suhuLarutanRataRata($pembacaan);
        $punyaKurva = $standar->nilaiPadaSuhu(25.0) !== null;

        // Jenis alat yang standarnya emang nggak berkurva: NULL itu jawaban yang
        // benar, nggak ada yang perlu dilaporin.
        if (! $standarBerkurvaSuhu && ! $punyaKurva) {
            return [];
        }

        if ($punyaKurva && $suhu === null) {
            return [$this->temuan(
                self::PERINGATAN,
                'suhu_larutan_tidak_dicatat',
                "Titik ke-{$ke}: standar acuannya ({$standar->nama}) punya kurva suhu, tapi suhu "
                .'larutannya nggak dicatat. Nilai acuan kepaksa pakai angka nominal, jadi Correction '
                .'bisa meleset sebesar koreksi suhunya.',
                ['titik_ke' => $ke, 'standard_id' => $standar->id],
            )];
        }

        if (! $punyaKurva && $suhu !== null) {
            return [$this->temuan(
                self::PERINGATAN,
                'standar_tanpa_kurva_suhu',
                "Titik ke-{$ke}: suhu larutan kecatat ({$suhu} °C), tapi standar acuannya "
                ."({$standar->nama}) belum punya kurva suhu di master. Angka suhunya nggak kepakai "
                .'dan nilai acuan tetap pakai nominal.',
                ['titik_ke' => $ke, 'standard_id' => $standar->id, 'suhu' => $suhu],
            )];
        }

        return [];
    }

    /**
     * Suhu larutan rata-rata dari pembacaan yang beneran ikut dihitung.
     *
     * Null kalau nggak ada satu pun yang kecatat — bukan 0, karena 0 °C itu suhu
     * yang sah dan bakal bikin nilai buffer diturunin dari ujung kurva yang
     * salah, bukan dilewati.
     *
     * @param  Collection<int, RawMeasurement>  $pembacaan
     */
    private function suhuLarutanRataRata($pembacaan): ?float
    {
        $suhu = $pembacaan
            ->map(fn (RawMeasurement $m): ?float => $m->suhu === null ? null : (float) $m->suhu)
            ->filter(fn (?float $s): bool => $s !== null)
            ->values();

        return $suhu->isEmpty() ? null : (float) $suhu->avg();
    }

    /**
     * Hitung ulang SEMUA titik sekaligus, baru diadu satu-satu.
     *
     * Sekaligus, bukan satu per satu, karena ada alat yang ketidakpastiannya
     * lahir per KELOMPOK titik. `GumCalculator::hitungTitik()` cuma lihat satu
     * titik, jadi buat alat begitu dia mustahil ngasilin angka yang sama kayak
     * waktu sesinya disimpen — dan bedanya kecetak sebagai peringatan di tiap
     * approve.
     *
     * Kejadian nyatanya Spectrophotometer (sesi `KAL/2026/08/0050`, 14 Agt 2026):
     * SEMBILAN titik, DELAPAN ke-flag `hitung_ulang_beda` — titik 1 "tersimpan
     * 0,432557, hitung ulang 0,100167" — padahal angka tersimpannya persis sama
     * kayak master. Yang beda cuma jalannya: `CalibrationController` nyimpen
     * lewat `CalibrationProfile::hitungPerGrup()` (budget per kelompok filter,
     * dari STDEV terbesar kelompok itu), validator ngitung ulang per titik. Jadi
     * tiap sesi Spectrophotometer cuma bisa disetujui dengan
     * `abaikan_peringatan: true` — dan begitu tombol "SETUJUI TETAP" jadi
     * kebiasaan, peringatan yang beneran penting ikut ketelan.
     *
     * Makanya di sini jalurnya disamain dengan jalur simpan: tanya profilnya
     * dulu. Profil yang nggak butuh balikin `null` dan pemeriksaan ini jatuh ke
     * jalur per-titik, persis kayak sebelumnya.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard, suhu_larutan: float|null, tersimpan: UncertaintyCalculation}>  $siapHitung
     * @return list<array<string, mixed>>
     */
    private function bandingkanHitungUlang(array $siapHitung, Equipment $alat, ?float $suhuRuang): array
    {
        $perGrup = $this->profil->untukAlat($alat)->hitungPerGrup(
            array_map(
                static fn (array $t): array => [
                    'titik_ke' => $t['titik_ke'],
                    'titik_ukur' => $t['titik_ukur'],
                    'pembacaan' => $t['pembacaan'],
                    'standard' => $t['standard'],
                    'suhu_larutan' => $t['suhu_larutan'],
                ],
                $siapHitung,
            ),
            $alat,
        );

        $temuan = [];

        if ($perGrup === null) {
            foreach ($siapHitung as $t) {
                $temuan = [...$temuan, ...$this->bandingkanTitik(
                    $t['titik_ke'],
                    $t['tersimpan'],
                    $this->gum->hitungTitik(
                        $t['titik_ke'],
                        $t['titik_ukur'],
                        $t['pembacaan'],
                        $alat,
                        $t['standard'],
                        $t['suhu_larutan'],
                        $suhuRuang,
                    ),
                )];
            }

            return $temuan;
        }

        $hitungan = collect($perGrup['hitungan'])->keyBy('titik_ke');
        $alasan = collect($perGrup['belum_dihitung'])->keyBy('titik_ke');

        foreach ($siapHitung as $t) {
            $ke = $t['titik_ke'];
            $ulang = $hitungan->get($ke);

            // Titik yang PUNYA baris hasil hitung tapi sekarang nggak bisa
            // dihitung ulang itu temuan sendiri, bukan sesuatu yang boleh
            // didiemin: berarti masternya berubah sesudah sesi disimpen.
            if ($ulang === null) {
                $temuan[] = $this->temuan(
                    self::PERINGATAN,
                    'hitung_ulang_gagal',
                    "Titik ke-{$ke}: angkanya tersimpan, tapi sekarang nggak bisa dihitung ulang. "
                        .($alasan->get($ke)['alasan'] ?? 'Alasannya nggak dilaporin profil alat.'),
                    ['titik_ke' => $ke],
                );

                continue;
            }

            $temuan = [...$temuan, ...$this->bandingkanTitik($ke, $t['tersimpan'], $ulang)];
        }

        return $temuan;
    }

    /**
     * @param  array<string, mixed>  $ulang
     * @return list<array<string, mixed>>
     */
    private function bandingkanTitik(int $ke, UncertaintyCalculation $tersimpan, array $ulang): array
    {
        $temuan = [];

        $dibandingkan = [
            'rata_rata' => 'Unit Under Test',
            'koreksi' => 'Correction',
            'ketidakpastian_diperluas' => 'U95%',
        ];

        foreach ($dibandingkan as $kolom => $label) {
            $lama = (float) $tersimpan->{$kolom};
            $baru = (float) $ulang[$kolom];

            if ($this->samaDengan($lama, $baru)) {
                continue;
            }

            $temuan[] = $this->temuan(
                self::PERINGATAN,
                'hitung_ulang_beda',
                "Titik ke-{$ke}: {$label} tersimpan ".Angka::idRingkas($lama, 6)
                    .', hasil hitung ulang '.Angka::idRingkas($baru, 6)
                    .'. Biasanya karena data alat/standar berubah sesudah sesi disubmit.',
                ['titik_ke' => $ke, 'kolom' => $kolom, 'tersimpan' => $lama, 'hitung_ulang' => $baru],
            );
        }

        if ($tersimpan->keputusan !== $ulang['keputusan']) {
            $temuan[] = $this->temuan(
                self::PERINGATAN,
                'keputusan_titik_beda',
                "Titik ke-{$ke}: keputusan tersimpan {$tersimpan->keputusan}, hitung ulang {$ulang['keputusan']}.",
                ['titik_ke' => $ke],
            );
        }

        return $temuan;
    }

    /**
     * U95 yang MELEDAK jauh di atas CMC — hampir selalu satu angka salah ketik.
     *
     * ## Kenapa ini penjagaan, bukan sekadar peringatan
     *
     * CMC itu ketidakpastian TERBAIK yang diakreditasi lab buat besaran itu.
     * Sesi normal keluar di sekitar CMC — kadang sedikit di atasnya kalau alat
     * pelanggannya nggak stabil, dan itu justru temuan kalibrasi yang berharga.
     *
     * Tapi U95 belasan sampai ratusan kali CMC bukan "alatnya jelek": itu
     * aritmetika yang bener di atas angka yang salah. Kejadian nyata
     * `CAL/2026/08/0043`: satu pembacaan Didynium diketik `783,52` — `738,52`
     * dengan digit 3 & 8 ketuker. Satu digit, dan U95 kelompoknya lompat dari
     * 0,40 nm ke 84,84 nm, 212x CMC-nya. Sertifikatnya tetap terbit, dan angka
     * itu nyampe pelanggan sebagai klaim ketidakpastian resmi.
     *
     * Nggak ada satu pun penjagaan lama yang nangkep:
     *  - `pembacaan_di_luar_rentang` — 783,52 masih di dalam 200–700 nm... eh,
     *    di luar, tapi titik 738,5 sendiri juga di luar, jadi dia kena
     *    pengecualian `dekatTitikStandar`;
     *  - `bukan_kelipatan_resolusi` — 783,52 kelipatan 0,01, lolos;
     *  - vonis PASS/FAIL — alat ini emang nggak divonis.
     *
     * ## Kenapa ERROR, bukan peringatan
     *
     * Peringatan bisa di-"SETUJUI TETAP". Sertifikat terakreditasi yang
     * ngeklaim ketidakpastian 212x kemampuan lab itu dokumen yang salah, dan
     * yang mbetulin harus balik ke lembar kerjanya — bukan mengakui klaim yang
     * nggak bisa dipertanggungjawabkan.
     *
     * Ambangnya longgar sengaja ([FAKTOR_U95_MELEDAK]): yang dicari salah
     * ketik, bukan alat pelanggan yang kurang stabil.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaU95MeledakDariCmc(CalibrationSession $sesi): array
    {
        $temuan = [];

        foreach ($sesi->uncertaintyCalculations->sortBy('titik_ke') as $titik) {
            /** @var UncertaintyCalculation $titik */
            $cmc = $this->cmcTitik($titik);

            if ($cmc === null || $cmc <= 0) {
                continue;
            }

            $u95 = (float) $titik->ketidakpastian_diperluas;

            if ($u95 <= $cmc * self::FAKTOR_U95_MELEDAK) {
                continue;
            }

            $temuan[] = $this->temuan(
                self::ERROR,
                'u95_meledak_dari_cmc',
                sprintf(
                    'Titik ke-%d: U95 %s jauh di atas CMC lab (%s) — %.0fx lipat. '
                    .'Hampir selalu ada satu pembacaan yang salah ketik; cek lembar kerjanya, '
                    .'jangan diterbitkan dengan angka ini.',
                    (int) $titik->titik_ke,
                    Angka::idRingkas($u95, 4),
                    Angka::idRingkas($cmc, 4),
                    $u95 / $cmc,
                ),
                [
                    'titik_ke' => (int) $titik->titik_ke,
                    'u95' => $u95,
                    'cmc' => $cmc,
                ],
            );
        }

        return $temuan;
    }

    /**
     * CMC yang dipakai waktu titik ini dihitung, dibaca dari jejak auditnya
     * sendiri — bukan dicari ulang ke master.
     *
     * Dibaca dari `type_b_components` karena di situ angkanya BEKU: kalau
     * baris CMC di master diubah sesudah sesinya dihitung, yang dibandingkan
     * tetap angka yang beneran dipakai. Ngitung ulang di sini bikin sesi lama
     * ke-flag gara-gara master yang berubah.
     */
    private function cmcTitik(UncertaintyCalculation $titik): ?float
    {
        foreach ($titik->type_b_components ?? [] as $k) {
            if (! is_array($k)) {
                continue;
            }

            // `perbandingan_cmc` (jalur per titik) & `cmc` (jalur per kelompok)
            // sama-sama nyimpen angkanya di `nilai`.
            if (in_array($k['sumber'] ?? null, ['perbandingan_cmc', 'lantai_cmc', 'cmc'], true)) {
                return isset($k['nilai']) ? (float) $k['nilai'] : null;
            }
        }

        return null;
    }

    /**
     * Keputusan sesi = keputusan titik terburuk. Satu titik FAIL bikin seluruh
     * sesi FAIL — kalau tersimpannya PASS padahal ada titik FAIL, itu bukan
     * beda angka, itu sertifikat yang isinya salah.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaKeputusanSesi(CalibrationSession $sesi): array
    {
        if ($sesi->uncertaintyCalculations->isEmpty()) {
            return [];
        }

        // Alat yang emang nggak divonis nggak punya "keputusan yang seharusnya"
        // — sesinya `null`, titik-titiknya `null`, dan itu konsisten. Tanpa
        // pengaman ini, tiap sesi Conductivity ke-flag "keputusan sesi salah,
        // seharusnya PASS" dan nggak akan pernah bisa terbit.
        if ($sesi->equipment !== null
            && $this->profil->untukAlat($sesi->equipment)->punyaToleransi() === false) {
            return [];
        }

        $seharusnya = $sesi->uncertaintyCalculations->contains('keputusan', 'FAIL') ? 'FAIL' : 'PASS';

        if ($sesi->keputusan === $seharusnya) {
            return [];
        }

        return [$this->temuan(
            self::ERROR,
            'keputusan_sesi_salah',
            "Keputusan sesi tersimpan {$sesi->keputusan}, padahal dari titik-titiknya seharusnya {$seharusnya}.",
        )];
    }

    /**
     * Kolom sertifikat yang kosong. Nggak nahan penerbitan — cuma ngasih tau
     * admin duluan, sebelum pelanggan yang nemu stripnya.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaKelengkapanSertifikat(CalibrationSession $sesi): array
    {
        $wajibDilihat = [
            'nomor_order' => [filled($sesi->nomor_order), 'Order Number belum diisi.'],
            'tanggal_terima' => [$sesi->tanggal_terima !== null, 'Received Date belum diisi.'],
            'calibration_method' => [
                $sesi->calibration_method_id !== null
                    || $sesi->uncertaintyCalculations->contains(fn ($t) => filled($t->metode)),
                'Calibration Method belum dipilih.',
            ],
            'owner_address' => [
                filled($sesi->equipment?->customer?->alamat),
                'Alamat pemilik alat (Address) belum diisi di data pelanggan.',
            ],
            'env_condition' => [
                $sesi->suhu_ruang !== null && $sesi->kelembaban !== null,
                'Kondisi lingkungan (suhu/kelembaban) belum lengkap.',
            ],
            'serial_number' => [
                filled($sesi->equipment?->serial_number),
                'Serial Number alat belum diisi.',
            ],
        ];

        $temuan = [];

        foreach ($wajibDilihat as $kode => [$terisi, $pesan]) {
            if (! $terisi) {
                $temuan[] = $this->temuan(self::INFO, $kode, $pesan);
            }
        }

        return $temuan;
    }

    /** @return list<Standard> */
    private function standarTerpakai(CalibrationSession $sesi): array
    {
        return $sesi->uncertaintyCalculations
            ->pluck('standard')
            ->filter()
            ->when($sesi->standard, fn ($c) => $c->push($sesi->standard))
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * Banding float dengan toleransi RELATIF. Angka kalibrasi rentangnya jauh
     * (pH 0–14 sampai panjang 0–300 mm), jadi ambang mutlak yang sama nggak
     * masuk akal buat dua-duanya. `1e-6` relatif itu jauh lebih halus daripada
     * resolusi alat mana pun, tapi cukup longgar buat noise pembulatan
     * penyimpanan desimal(20,8).
     */
    private function samaDengan(float $a, float $b): bool
    {
        return abs($a - $b) <= max(1e-8, 1e-6 * max(abs($a), abs($b)));
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @return array<string, mixed>
     */
    private function temuan(string $tingkat, string $kode, string $pesan, array $konteks = []): array
    {
        return [
            'tingkat' => $tingkat,
            'kode' => $kode,
            'pesan' => $pesan,
            ...($konteks === [] ? [] : ['konteks' => $konteks]),
        ];
    }

    /**
     * Peringatan khas alat, diserahin ke profilnya lewat
     * `CalibrationProfile::peringatanSesi()`.
     *
     * Validator ini sengaja nggak tau nama alat mana pun — kalau nggak, tiap
     * alat baru (lab mau sampai 48) nambah satu `if` di sini, dan dalam lima
     * alat aja udah nggak kebaca. Alat yang nggak punya peringatan khusus
     * balikin `[]` dan nggak ngaruh apa-apa.
     *
     * Semuanya PERINGATAN, bukan error: yang dilaporin di sini hal yang mungkin
     * benar tapi perlu dilihat orang — approve tetap bisa dilanjutkan secara
     * sadar lewat `abaikan_peringatan`.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaPeringatanProfil(CalibrationSession $sesi): array
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return [];
        }

        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);

        return array_map(
            fn (array $p): array => $this->temuan(self::PERINGATAN, $p['kode'], $p['pesan']),
            $profil->peringatanSesi($sesi),
        );
    }

    /**
     * Sedekat apa (RELATIF) pembacaan boleh meleset dari titik yang lagi
     * dikalibrasi dan masih dianggap "ya emang segitu".
     *
     * 10% longgar buat pembacaan wajar (110,67 di titik 111 = meleset 0,3%),
     * tapi masih jauh lebih ketat daripada salah ketik geser koma, yang selalu
     * meleset ordebesaran: 1106,7 meleset 897%, 11,067 meleset 90%.
     */
    private const TOLERANSI_DEKAT_TITIK = 0.1;

    private function dekatTitikStandar(float $nilai, float $titik): bool
    {
        if ($titik == 0.0) {
            return false;
        }

        return abs($nilai - $titik) / abs($titik) <= self::TOLERANSI_DEKAT_TITIK;
    }
}
