<?php

namespace App\Services;

use App\Models\CalibrationSession;
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
            ...$this->periksaTiapTitik($sesi),
            ...$this->periksaKeputusanSesi($sesi),
            ...$this->periksaKelengkapanSertifikat($sesi),
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

        if ($sesi->equipment === null || $sesi->equipment->toleransi === null) {
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
     * Hitung ulang tiap titik dari pembacaan mentah, terus adu sama yang
     * tersimpan.
     *
     * @return list<array<string, mixed>>
     */
    private function periksaTiapTitik(CalibrationSession $sesi): array
    {
        $temuan = [];
        $alat = $sesi->equipment;

        // Cuma pembacaan SESUDAH adjustment yang masuk hitungan — as-found itu
        // dokumentasi kondisi awal alat, bukan dasar sertifikat.
        $pembacaanPerTitik = $sesi->rawMeasurements
            ->where('tahap', 'sesudah_adjustment')
            ->groupBy('titik_ke');

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

            $ulang = $this->gum->hitungTitik(
                $ke,
                (float) $titik->titik_ukur,
                $pembacaan->sortBy('pembacaan_ke')
                    ->map(fn (RawMeasurement $m): float => (float) $m->pembacaan)
                    ->values()
                    ->all(),
                $alat,
                $standar,
                // Suhu larutan yang kecatat di pembacaan, biar hitung ulang di
                // sini nurunin nilai acuan dengan cara yang SAMA kayak waktu
                // disimpen. Kalau nggak dikirim, pemeriksaan ini nerima
                // `titik_ukur` tersimpan apa adanya — dan `titik_ukur` yang
                // nggak cocok kurva suhu buffernya nggak akan pernah ketangkep.
                $this->suhuLarutanRataRata($pembacaan),
            );

            $temuan = [...$temuan, ...$this->bandingkanTitik($ke, $titik, $ulang)];
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
}
