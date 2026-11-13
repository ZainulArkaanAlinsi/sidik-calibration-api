<?php

namespace App\Services\GarisWaktu;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Posisi di garis waktu arsip: tingkat yang diminta dan induk yang dilewati.
 *
 * Penelusuran bertingkat TAHUN > BULAN > PEKAN > HARI. Tingkat menentukan apa
 * yang dikembalikan; induknya menentukan lingkupnya:
 *
 * | tingkat | induk wajib             | isi                                  |
 * |---------|-------------------------|--------------------------------------|
 * | tahun   | -                       | kelompok per tahun                   |
 * | bulan   | tahun                   | kelompok per bulan di tahun itu      |
 * | pekan   | tahun, bulan            | kelompok per pekan di bulan itu      |
 * | hari    | tahun, bulan, pekan     | kelompok per hari + daftar item      |
 *
 * `tanggal` (opsional, hanya tingkat hari) mempersempit ke satu hari.
 *
 * ## Pekan ISO dipotong per bulan
 *
 * Pekan = ISO 8601 (Senin-Minggu). Satu pekan bisa melintasi dua bulan, tetapi
 * garis waktu bertingkat butuh hitungan yang bisa dijumlahkan ke atas: jumlah
 * pekan di sebuah bulan harus sama dengan jumlah bulan itu. Maka `pekan` dibaca
 * BERSAMA `bulan`: pekan 44 di bulan 10 hanya berisi hari-hari Oktober dalam
 * pekan itu, pekan 44 di bulan 11 hanya hari-hari November-nya. Nomor pekan
 * tidak ambigu di dalam satu bulan (satu bulan tidak memuat dua pekan bernomor
 * sama), termasuk pekan 1 yang jatuh di akhir Desember.
 */
final class LingkupGarisWaktu
{
    public const TINGKAT = ['tahun', 'bulan', 'pekan', 'hari'];

    private function __construct(
        public readonly string $tingkat,
        public readonly ?int $tahun,
        public readonly ?int $bulan,
        public readonly ?int $pekan,
        public readonly ?string $tanggal,
    ) {}

    /**
     * Aturan validasi parameter garis waktu (bentuk saja; hubungan antarinduk
     * diperiksa di [dariInput]).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function aturan(): array
    {
        return [
            'tingkat' => ['sometimes', 'nullable', Rule::in(self::TINGKAT)],
            'tahun' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'bulan' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'pekan' => ['sometimes', 'nullable', 'integer', 'between:1,53'],
            'tanggal' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  keluaran `validate()` dengan [aturan]
     *
     * @throws ValidationException bila induk tidak sesuai tingkat atau tidak ada
     */
    public static function dariInput(array $data): self
    {
        $tingkat = (string) ($data['tingkat'] ?? 'tahun');
        $nilai = [
            'tahun' => self::angka($data['tahun'] ?? null),
            'bulan' => self::angka($data['bulan'] ?? null),
            'pekan' => self::angka($data['pekan'] ?? null),
            'tanggal' => filled($data['tanggal'] ?? null) ? (string) $data['tanggal'] : null,
        ];

        // Induk yang wajib, dan satu-satunya yang boleh dikirim, per tingkat.
        $wajib = match ($tingkat) {
            'tahun' => [],
            'bulan' => ['tahun'],
            'pekan' => ['tahun', 'bulan'],
            default => ['tahun', 'bulan', 'pekan'],
        };
        $boleh = $tingkat === 'hari' ? [...$wajib, 'tanggal'] : $wajib;

        foreach ($wajib as $induk) {
            if ($nilai[$induk] === null) {
                throw ValidationException::withMessages([
                    $induk => ["Tingkat `{$tingkat}` memerlukan parameter `{$induk}`."],
                ]);
            }
        }

        foreach ($nilai as $nama => $isi) {
            if ($isi !== null && ! in_array($nama, $boleh, true)) {
                throw ValidationException::withMessages([
                    $nama => ["Parameter `{$nama}` tidak dipakai pada tingkat `{$tingkat}`."],
                ]);
            }
        }

        $lingkup = new self($tingkat, $nilai['tahun'], $nilai['bulan'], $nilai['pekan'], $nilai['tanggal']);
        $lingkup->periksaPekanDanTanggal();

        return $lingkup;
    }

    /**
     * Rentang yang menjadi lingkup kueri, atau null (tingkat tahun = semua).
     * Lingkup = induk TERDEKAT: tahun untuk bulan, bulan untuk pekan, pekan
     * (atau satu `tanggal`) untuk hari.
     */
    public function rentang(): ?RentangTanggal
    {
        return match ($this->tingkat) {
            'tahun' => null,
            'bulan' => new RentangTanggal(
                RentangTanggal::dari($this->tahun),
                RentangTanggal::dari($this->tahun, 12, 31),
            ),
            'pekan' => $this->rentangBulan(),
            default => $this->tanggal !== null
                ? new RentangTanggal(RentangTanggal::tanggal($this->tanggal), RentangTanggal::tanggal($this->tanggal))
                : self::rentangPekanDalamBulan($this->tahun, $this->bulan, $this->pekan),
        };
    }

    /**
     * Hari-hari bulan itu yang termasuk pekan ISO bernomor $pekan, atau null
     * bila bulan itu tidak memuat pekan tersebut.
     */
    public static function rentangPekanDalamBulan(int $tahun, int $bulan, int $pekan): ?RentangTanggal
    {
        $mulai = null;
        $selesai = null;
        $jumlahHari = RentangTanggal::dari($tahun, $bulan)->daysInMonth;

        for ($hari = 1; $hari <= $jumlahHari; $hari++) {
            $tanggal = RentangTanggal::dari($tahun, $bulan, $hari);

            if ($tanggal->isoWeek !== $pekan) {
                continue;
            }

            $mulai ??= $tanggal;
            $selesai = $tanggal;
        }

        return $mulai === null ? null : new RentangTanggal($mulai, $selesai);
    }

    /**
     * Induk yang dikirim, dalam bentuk query string berikutnya (tanpa tingkat).
     *
     * @return array<string, int|string>
     */
    public function induk(): array
    {
        return array_filter(
            ['tahun' => $this->tahun, 'bulan' => $this->bulan, 'pekan' => $this->pekan, 'tanggal' => $this->tanggal],
            fn ($isi) => $isi !== null,
        );
    }

    private function rentangBulan(): RentangTanggal
    {
        $mulai = RentangTanggal::dari($this->tahun, $this->bulan);

        return new RentangTanggal($mulai, $mulai->endOfMonth()->startOfDay());
    }

    private function periksaPekanDanTanggal(): void
    {
        if ($this->tingkat !== 'hari') {
            return;
        }

        $pekan = self::rentangPekanDalamBulan($this->tahun, $this->bulan, $this->pekan);

        if ($pekan === null) {
            throw ValidationException::withMessages([
                'pekan' => ["Pekan {$this->pekan} tidak ada di ".LabelWaktu::bulan(RentangTanggal::dari($this->tahun, $this->bulan)).'.'],
            ]);
        }

        if ($this->tanggal !== null && ! $pekan->mengandung(RentangTanggal::tanggal($this->tanggal))) {
            throw ValidationException::withMessages([
                'tanggal' => ["Tanggal {$this->tanggal} bukan bagian dari pekan {$this->pekan} bulan itu."],
            ]);
        }
    }

    private static function angka(mixed $isi): ?int
    {
        return filled($isi) ? (int) $isi : null;
    }
}
