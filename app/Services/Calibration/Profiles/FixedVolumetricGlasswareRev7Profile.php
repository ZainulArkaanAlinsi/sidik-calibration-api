<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use App\Support\Angka;
use App\Support\VolumetricGlasswareMentah as M;
use Illuminate\Support\Collection;

/**
 * Labu Ukur & Pipet Volume — keluarga Fixed yang mengikuti **workbook lab
 * Rev.7** (`SIDIK-IK-CAL-0510_Rev.7`; LU-200/250/500-1, PV 0,5/2/3/4).
 * Keputusan pemilik 8 Okt 2026: workbook lab adalah ACUAN.
 *
 * Satu kelas antara supaya kedua alat tidak menyalin aturan yang sama. Yang
 * dibawa di sini, semuanya dibaca dari workbook:
 *
 * | Bagian | Workbook Rev.7 |
 * |---|---|
 * | parameter hitung (ρ_AT 8, γ_A 10e-6, tujuh komponen, sakelar suhu) | `PERHITUNGAN!H58:H59`, `PERHITUNGAN U95%!I36:I42` |
 * | ρ air ulangan 1 & 2 pada 25,5 °C + peringatan bila suhu terukur menggeser angka cetak | `PERHITUNGAN!H40`/`J40` |
 * | neraca Fujitsu di Usage Check | `DATABASE!V19:V21` (Tabel_Standar, `INPUT DATA!Q24`) |
 * | termometer standar Yokogawa CA 150 + sensor PRT, selalu dipakai | `INPUT DATA!Q25` "• Termometer-Yokogawa", `DATABASE!V22:Z23` |
 * | thermobarometer Lutron di pilihan thermohygro | `INPUT DATA!E24` |
 * | Standard Used = neraca + Termometer & Sensor Std. | `SERTIFIKAT!B29:W30` |
 * | tekanan udara di Env. Condition | `SERTIFIKAT!T13:Y13` |
 * | Class/Permitted Error & Suhu Dasar Volume 20 °C | `SERTIFIKAT!O14:U14`, `E18:N18` |
 * | suhu env 1 desimal, RH bulat, nominal dengan nol di belakang | format sel `SERTIFIKAT!U11/U12/E21` |
 *
 * Picnometer TIDAK ikut: workbook-nya masih `Fixed_Volumetric_Glassware_2026`.
 */
abstract class FixedVolumetricGlasswareRev7Profile extends FixedVolumetricGlasswareProfile
{
    /**
     * Termometer standar `DATABASE!V22` workbook ("Termometer & Sensor Std.",
     * Yokogawa/CA 150 Handy Cal, 23P1005) — NAMA dulu, serial belakangan
     * (`cocokkanStandar()`): seri 23P1005 juga dipakai baris kalibrator
     * Yokogawa milik lembar TITS/Enclosure.
     */
    public const COCOK_TERMOMETER = ['Termometer & Sensor Std.', '23P1005'];

    /** Sensor PRT Pt-100 (SH1/20) — `DATABASE!Z23` "SENSOR PT100", tabel koreksi `FC Prt Pt100`. */
    public const COCOK_SENSOR = ['PRT Pt-100', 'SH1/20'];

    /**
     * Usage Check lembar Rev.7 = daftar standar `DATABASE!V19:V22` workbook:
     * tiga neraca (`Tabel_Standar`, dipilih `INPUT DATA!Q24`) dan termometer
     * standar. Neraca Fujitsu TIDAK ada di daftar keluarga lama padahal
     * workbook Labu Ukur memakainya. Baris "RTD Sensor" kertas lama diganti
     * baris termometer workbook — sensornya bagian dari "Termometer & Sensor
     * Std." yang sama, dan dua baris untuk satu rantai suhu membuat sertifikat
     * mencetaknya dua kali.
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Balance Excellent', 'cocok' => ['Electronic Balance Excellent', 'HSEX1403752']],
        ['label' => 'Balance Mettler Toledo', 'cocok' => ['Analytical Balance', '1129063525']],
        ['label' => 'Balance Fujitsu', 'cocok' => ['Electronic Balance Fujitsu', 'SIDIK/134/2024']],
        ['label' => 'Termometer & Sensor Std. (Yokogawa CA 150)', 'cocok' => self::COCOK_TERMOMETER],
    ];

    /**
     * TH-1..TH-7 ditambah Thermobarometer Lutron — `INPUT DATA!E24` ketujuh
     * workbook Rev.7. Lutron satu-satunya alat lingkungan lab yang mengukur
     * TEKANAN, dan densitas udara (`PERHITUNGAN!H57`) tidak bisa dihitung
     * tanpa tekanan.
     */
    public const THERMOHYGRO_TERCETAK = [
        'TH-1', 'TH-2', 'TH-3', 'TH-4', 'TH-5', 'TH-6', 'TH-7', 'Thermobarometer Lutron',
    ];

    protected function parameterHitung(): array
    {
        return V::parameterRev7();
    }

    /** `SERTIFIKAT!U11` format `0.0` — 20,85 tercetak 20,9. */
    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    /** `SERTIFIKAT!U12` format `0`. */
    public function desimalKelembabanEnv(): ?int
    {
        return 0;
    }

    /** `SERTIFIKAT!E21` format `0.00`/`0.000` — nominal "200,00", "0,500". */
    public function nolBelakangStandarDibuang(): bool
    {
        return false;
    }

    /**
     * Neraca dari centang Usage Check, bukan dropdown kedua — pola Piston
     * Volume & Gaya. Satu baris neraca tercentang = nilainya menang atas isian
     * "Balance Used"; nol = isian lama dibiarkan (APK lama); dua atau lebih =
     * kiriman ditolak (`masalahKolomDariCentang()`): workbook memakai SATU
     * neraca per sesi (`INPUT DATA!Q24`).
     */
    public function kolomDariCentang(): array
    {
        $baris = [];

        foreach (static::STANDARD_TERCETAK as $b) {
            if ((new TabelStandarVolumetric)->neraca($this->keluarga(), $b['cocok'][0]) !== null) {
                $baris[] = ['cocok' => $b['cocok'], 'nilai' => $b['cocok'][0]];
            }
        }

        return [M::KUNCI_SESI.'.neraca' => ['judul' => 'neraca', 'baris' => $baris]];
    }

    /**
     * Standar acuan sesi = neraca yang menghitung V20 (`spesifikasi_alat.
     * volumetric.neraca`), dicocokkan ke master standar lab lewat nama & seri
     * di tabel standar volumetric.
     */
    public function standarSesiDariSpesifikasi(array $spesifikasiAlat, Equipment $equipment): ?Standard
    {
        $blok = M::blokSesi($spesifikasiAlat);
        $neraca = $blok['neraca'] ?? null;

        if ($neraca === null) {
            return null;
        }

        $baris = (new TabelStandarVolumetric)->neraca($this->keluarga(), $neraca);

        if ($baris === null) {
            return null;
        }

        return $this->cocokkanStandar(
            $this->masterStandarLengkap($equipment->organization_id),
            array_values(array_filter([$baris['nama'], $baris['serial']])),
        );
    }

    /**
     * Termometer standar & sensornya SELALU dipakai — tabel koreksi suhu air
     * dan U95-nya (`TabelStandarVolumetric`) milik rantai itu, apa pun yang
     * dicentang. Jadi tidak diserahkan ke centang: diturunkan dari master
     * standar lab tiap kali sesi diperiksa/diterbitkan. Termometer tercetak
     * (`SERTIFIKAT!B30`); sensornya tidak — workbook mencetak keduanya sebagai
     * satu baris "Termometer & Sensor Std.", tapi masa berlaku sensornya
     * diperiksa sendiri (`INPUT DATA!T26`).
     *
     * Thermohygro dan standar yang dicentang "Dipakai" ikut diperiksa masa
     * berlakunya (keduanya sudah punya tempat cetak sendiri).
     */
    public function standarTambahanSesi(CalibrationSession $sesi): array
    {
        $master = $this->masterStandarLengkap($sesi->organization_id);
        $hasil = [];

        $termometer = $this->cocokkanStandar($master, self::COCOK_TERMOMETER);
        if ($termometer !== null) {
            $hasil[] = ['standar' => $termometer, 'dicetak' => true];
        }

        $sensor = $this->cocokkanStandar($master, self::COCOK_SENSOR);
        if ($sensor !== null) {
            $hasil[] = ['standar' => $sensor, 'dicetak' => false];
        }

        if ($sesi->thermohygro !== null) {
            $hasil[] = ['standar' => $sesi->thermohygro, 'dicetak' => false];
        }

        foreach ($sesi->standarDicek as $dicek) {
            if ((bool) $dicek->pivot->dipakai) {
                $hasil[] = ['standar' => $dicek, 'dicetak' => false];
            }
        }

        return $hasil;
    }

    /**
     * Rata-rata tekanan BACAAN, tanpa koreksi thermobarometer — `SERTIFIKAT!U13
     * = PERHITUNGAN!G19 = AVERAGE(E19:F19)`, padahal suhu & RH di baris yang
     * sama memakai `G + M` (terkoreksi). Ditiru; pertanyaan lab volumetric no. 22.
     */
    public function tekananEnvSertifikat(CalibrationSession $sesi): ?float
    {
        $terisi = array_values(array_filter(
            [$sesi->tekanan_awal, $sesi->tekanan_akhir],
            static fn ($p): bool => $p !== null,
        ));

        return $terisi === [] ? null : array_sum(array_map('floatval', $terisi)) / count($terisi);
    }

    /**
     * `SERTIFIKAT!O14` "Class/Permitted Error : A/±0.15 ml" dan `E18` "Suhu
     * Dasar Volume : 20 °C". Kepala sertifikat dikunci 16 kotak
     * (`sertifikat/pdf.blade.php`), jadi keduanya lewat baris di atas tabel
     * hasil — pintu yang sama dengan `Spindel No.` Viscometer.
     */
    public function catatanAtasTabelHasil(CalibrationSession $sesi): ?string
    {
        $blok = M::blokSesi($sesi->spesifikasi_alat);
        $bagian = [];

        if ($blok !== null && $blok['kelas'] !== null) {
            $bagian[] = 'Class/Permitted Error : '.$blok['kelas'].($blok['toleransi_ml'] === null
                ? ''
                : '/±'.Angka::nilaiStandar($blok['toleransi_ml'], 6).' '.self::SATUAN);
        }

        $bagian[] = 'Suhu Dasar Volume : '.Angka::nilaiStandar(V::SUHU_ACUAN, 2).' °C';

        return implode(' — ', $bagian);
    }

    /**
     * Standar yang tidak ketemu di master tidak boleh hilang diam-diam: masa
     * berlakunya tidak bisa diperiksa dan barisnya tidak tercetak.
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [...parent::peringatanSesi($sesi), ...$this->peringatanSuhuMenggeserCetak($sesi)];
        $blok = M::blokSesi($sesi->spesifikasi_alat);

        // Sesi tanpa blok Volumetric (atau tanpa lab) sudah diperingatkan
        // induknya dan tidak bisa menerbitkan apa pun — standarnya belum
        // relevan, dan master standar tanpa saringan lab tidak boleh dibaca.
        if ($blok === null || $sesi->organization_id === null) {
            return $peringatan;
        }

        $master = $this->masterStandarLengkap($sesi->organization_id);

        foreach (['termometer standar' => self::COCOK_TERMOMETER, 'sensor PRT' => self::COCOK_SENSOR] as $nama => $cocok) {
            if ($this->cocokkanStandar($master, $cocok) === null) {
                $peringatan[] = [
                    'kode' => 'volumetric_standar_suhu_tidak_terdaftar',
                    'pesan' => sprintf(
                        'Standar %s (%s) tidak ada di master standar lab. Koreksi & U95-nya tetap dipakai dari '
                        .'tabel metode, tapi masa berlakunya tidak bisa diperiksa dan tidak tercetak di Standard Used.',
                        $nama, implode(' / ', $cocok),
                    ),
                ];
            }
        }

        if ($sesi->standard === null && $blok['neraca'] !== null) {
            $peringatan[] = [
                'kode' => 'volumetric_neraca_tidak_tertaut',
                'pesan' => sprintf(
                    'Neraca "%s" yang menghitung volume tidak tertaut ke standar terdaftar — masa berlakunya tidak '
                    .'diperiksa dan tidak tercetak di Standard Used. Daftarkan neracanya di master standar, lalu '
                    .'simpan ulang lembarnya.',
                    $blok['neraca'],
                ),
            ];
        }

        return $peringatan;
    }

    /**
     * Penjaga keputusan pemilik 8 Okt 2026 (terakhir): angka sertifikat ikut
     * workbook — ρ air ulangan 1 & 2 pada 25,5 °C (`PERHITUNGAN!H40`/`J40`) —
     * DAN sistem tetap berjaga. Kalau angka CETAK V20 atau Correction (desimal
     * sertifikat titik itu) berbeda antara cabang yang dipakai dan cabang
     * satunya (tercatat di jejak `volumetric_rev7_suhu_densitas_air`), admin
     * diberi PERINGATAN berisi kedua angka cetak.
     *
     * Ambangnya angka cetak, bukan selisih nilai: selisih yang tidak menggeser
     * digit tercetak urusan jejak audit, yang menggeser digit di sertifikat
     * yang dipegang pelanggan urusan orang yang menyetujui. Di ketujuh
     * workbook acuan peringatan ini hanya muncul di LU-500 (500,24 lawan
     * 500,25) — dan memang harus muncul di situ.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    private function peringatanSuhuMenggeserCetak(CalibrationSession $sesi): array
    {
        $peringatan = [];

        foreach ($sesi->uncertaintyCalculations as $titik) {
            $jejak = collect((array) $titik->type_b_components)
                ->first(static fn ($b): bool => is_array($b) && ($b['sumber'] ?? null) === 'volumetric_rev7_suhu_densitas_air');

            if (! is_array($jejak) || ! is_numeric($jejak['nilai'] ?? null)) {
                continue;
            }

            $nominal = (float) $titik->titik_ukur;
            $desimal = $this->desimalSertifikatTitik($nominal) ?? $this->desimalSertifikat() ?? 4;
            $cetak = fn (float $x): string => Angka::hasil($x, $desimal, tandaNol: $this->tandaNolDicetak());

            $v20 = (float) $titik->rata_rata;
            $v20Lain = (float) $jejak['nilai'];
            [$cetakV20, $cetakV20Lain] = [$cetak($v20), $cetak($v20Lain)];
            // Correction tercetak = V20 − Nominal (`tandaKoreksiSertifikat()`).
            [$cetakKoreksi, $cetakKoreksiLain] = [$cetak($v20 - $nominal), $cetak($v20Lain - $nominal)];

            if ($cetakV20 === $cetakV20Lain && $cetakKoreksi === $cetakKoreksiLain) {
                continue;
            }

            // Cabang yang dipakai dibaca dari kalimat jejaknya sendiri (lihat
            // `FixedVolumetricGlasswareProfile::catatanRev7()`): sesi lama bisa
            // dihitung dengan sakelar yang berbeda dari bawaan hari ini.
            $pakaiMaster = str_contains((string) ($jejak['keterangan'] ?? ''), 'dihitung pada 25,5 °C');

            $peringatan[] = [
                'kode' => 'volumetric_suhu_25_5_menggeser_cetak',
                'pesan' => sprintf(
                    'Titik %s mL: angka cetak bergantung pada suhu ρ air ulangan 1 & 2. Workbook Rev.7 '
                    .'`PERHITUNGAN!H40`/`J40` memakai angka mati 25,5 °C; dari suhu air terukur hasilnya lain. '
                    .'Tercetak (%s): V20 %s mL, Correction %s mL. Dengan %s: V20 %s mL, Correction %s mL. '
                    .'Angka ikut workbook sesuai keputusan pemilik 8 Okt 2026 — periksa suhu airnya sebelum '
                    .'menyetujui (docs/pertanyaan-lab-volumetric.md no. 14).',
                    Angka::nilaiStandar($nominal, 6),
                    $pakaiMaster ? '25,5 °C seperti workbook' : 'suhu terukur',
                    $cetakV20, $cetakKoreksi,
                    $pakaiMaster ? 'suhu air terukur' : '25,5 °C seperti workbook',
                    $cetakV20Lain, $cetakKoreksiLain,
                ),
            ];
        }

        return $peringatan;
    }

    /** @return array{tersedia: bool, sumber: string, catatan: string} */
    protected function budgetKetidakpastianLembar(): array
    {
        return [
            'tersedia' => true,
            'sumber' => 'Workbook lab Rev.7 SIDIK-IK-CAL-0510_Rev.7 (Labu Ukur & Pipet Volume, .xlsm)',
            'catatan' => 'Tujuh komponen dalam mL — keterulangan TIDAK masuk budget (PERHITUNGAN U95% I36:I42) — '
                .'k dari t-Student (v_eff dipotong ke bawah), dan lantai CMC dari lampiran akreditasi pada '
                .'kapasitas alat. Sesi dengan kelas selain A/B, neraca yang bukan milik lembarnya, atau kondisi '
                .'lingkungan tak lengkap TIDAK diterbitkan.',
        ];
    }

    /**
     * Master standar lab LENGKAP (semua kolom) — `masterStandarTertaut()`
     * cuma mengambil kolom tautan lembar, tanpa `berlaku_sampai` & `model`
     * yang dibutuhkan pemeriksaan masa berlaku dan baris cetak.
     *
     * @return Collection<int, Standard>
     */
    private function masterStandarLengkap(?int $organizationId): Collection
    {
        return Standard::query()
            ->whereNull('parameter_kondisi')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('id')
            ->get();
    }
}
