<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deret waktu "masuk vs selesai" — dipakai grafik batang `/dashboard` DAN
 * `/dashboard/tren` (permintaan-endpoint.md §2).
 *
 * SATU tempat yang ngitung, sengaja. Grafik 6 bulan di Dashboard dan grafik
 * rentang bebas di layar tren nanya hal yang sama; kalau masing-masing ngitung
 * sendiri, dua grafik di app yang sama bisa nunjukin angka beda buat bulan yang
 * sama — dan yang lihat nggak punya cara tahu mana yang bener.
 *
 * Pengelompokan periodenya dikerjain di PHP, BUKAN `GROUP BY DATE_FORMAT(...)`.
 * Produksi jalan di MySQL tapi test jalan di SQLite, dan fungsi tanggal keduanya
 * beda nama — query yang lolos test bakal meledak di server. Di repo ini pola itu
 * udah kejadian tiga kali (panjang nama index, urutan key JSON, zona waktu), jadi
 * divergensi driver dianggap default sampai kebukti sebaliknya.
 */
class TrenPekerjaan
{
    public const SATUAN_HARI = 'hari';

    public const SATUAN_MINGGU = 'minggu';

    public const SATUAN_BULAN = 'bulan';

    /** @return list<string> */
    public static function satuan(): array
    {
        return [self::SATUAN_HARI, self::SATUAN_MINGGU, self::SATUAN_BULAN];
    }

    /**
     * Batas jumlah titik di satu grafik.
     *
     * Bukan soal berat servernya — soal grafiknya kepakai apa nggak. 800 batang di
     * layar HP itu garis abu-abu, bukan informasi. Kalau kelewat, pemanggilnya
     * dapat `422` yang nyaranin naikin `satuan`, bukan diam-diam dipotong: hasil
     * yang dipotong sepi-sepi bikin orang salah baca tren.
     */
    public const MAKS_PERIODE = 400;

    /**
     * Query sesi sesuai hak akses pemanggil — aturan yang SAMA kayak `/dashboard`.
     *
     * @return Builder<CalibrationSession>
     */
    public function query(User $user): Builder
    {
        return CalibrationSession::query()
            ->where('organization_id', $user->organization_id)
            // Teknisi cuma pekerjaannya sendiri. Diambil dari token, mobile nggak
            // ngirim role — kalau dari client, gampang dibohongin.
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn (Builder $q) => $q->where('teknisi_id', $user->id),
            );
    }

    /**
     * Berapa periode yang bakal dihasilkan rentang ini. Dipakai validasi SEBELUM
     * datanya ditarik.
     */
    public function jumlahPeriode(Carbon $dari, Carbon $sampai, string $satuan): int
    {
        $awal = $this->awalPeriode($dari, $satuan);
        $akhir = $this->awalPeriode($sampai, $satuan);

        return match ($satuan) {
            self::SATUAN_HARI => $awal->diffInDays($akhir) + 1,
            self::SATUAN_MINGGU => $awal->diffInWeeks($akhir) + 1,
            default => $awal->diffInMonths($akhir) + 1,
        };
    }

    /**
     * Deret "masuk vs selesai" per periode.
     *
     * Periode KOSONG tetap keluar dengan nilai 0, nggak di-skip. Kalau dilewat,
     * grafiknya bohong: jeda kosong ketutup dan tren naik-turunnya kelihatan lebih
     * mulus dari kenyataan.
     *
     * @param  Builder<CalibrationSession>  $sesi
     * @return list<array{periode: string, label: string, masuk: int, selesai: int}>
     */
    public function hitung(Builder $sesi, Carbon $dari, Carbon $sampai, string $satuan): array
    {
        $mulai = $this->awalPeriode($dari, $satuan);
        $selesaiRentang = $this->akhirPeriode($sampai, $satuan);

        // `tanggal_kalibrasi` = kapan alat dikerjain. `reviewed_at` = kapan admin
        // nyetujuin — itu yang dipakai buat "selesai", bukan tanggal sertifikat
        // terbit: penerbitan PDF jalan di queue, jadi sesi yang baru di-approve
        // bakal kehitung "belum selesai" kalau worker-nya lagi ngantre, padahal
        // kerjaan teknisinya udah kelar. Sama alasannya kayak kartu
        // "Kalibrasi selesai" di /dashboard.
        $masuk = $this->kelompokkan(
            (clone $sesi)
                ->whereBetween('tanggal_kalibrasi', [$mulai, $selesaiRentang])
                ->pluck('tanggal_kalibrasi'),
            $satuan,
        );

        $selesai = $this->kelompokkan(
            (clone $sesi)
                ->where('status', CalibrationSession::STATUS_DISETUJUI)
                ->whereNotNull('reviewed_at')
                ->whereBetween('reviewed_at', [$mulai, $selesaiRentang])
                ->pluck('reviewed_at'),
            $satuan,
        );

        $hasil = [];
        $kursor = $mulai->copy();

        while ($kursor->lessThanOrEqualTo($selesaiRentang)) {
            $kunci = $this->kunci($kursor, $satuan);

            $hasil[] = [
                'periode' => $kunci,
                // Label siap pakai buat sumbu X — biar mobile nggak nerjemahin
                // nama bulan sendiri di tiap layar, dan nggak beda-beda antar layar.
                'label' => $this->label($kursor, $satuan),
                'masuk' => $masuk->get($kunci, 0),
                'selesai' => $selesai->get($kunci, 0),
            ];

            $this->majukan($kursor, $satuan);
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, Carbon|null>  $tanggal
     * @return Collection<string, int>
     */
    private function kelompokkan(Collection $tanggal, string $satuan): Collection
    {
        return $tanggal
            ->filter()
            ->countBy(fn (Carbon $t): string => $this->kunci($t, $satuan));
    }

    /**
     * Kunci periode — harus stabil & bisa diurut sebagai string.
     *
     * Minggu pakai tahun-minggu ISO (`o-\WW`), bukan `Y-\WW`: di pergantian tahun,
     * 29 Des 2025 itu minggu ke-1 tahun **2026** menurut ISO. Pakai `Y` bikin dua
     * minggu beda dapat kunci yang sama (`2025-W01`) dan angkanya kegabung.
     */
    private function kunci(Carbon $tanggal, string $satuan): string
    {
        return match ($satuan) {
            self::SATUAN_HARI => $tanggal->format('Y-m-d'),
            self::SATUAN_MINGGU => $tanggal->format('o-\WW'),
            default => $tanggal->format('Y-m'),
        };
    }

    private function label(Carbon $awal, string $satuan): string
    {
        return match ($satuan) {
            self::SATUAN_HARI => $awal->translatedFormat('d M'),
            // Minggu dilabeli tanggal MULAInya — "W30" nggak berarti apa-apa buat
            // orang yang lihat grafik.
            self::SATUAN_MINGGU => $awal->translatedFormat('d M'),
            default => $awal->translatedFormat('M Y'),
        };
    }

    private function awalPeriode(Carbon $tanggal, string $satuan): Carbon
    {
        return match ($satuan) {
            self::SATUAN_HARI => $tanggal->copy()->startOfDay(),
            self::SATUAN_MINGGU => $tanggal->copy()->startOfWeek(),
            default => $tanggal->copy()->startOfMonth(),
        };
    }

    private function akhirPeriode(Carbon $tanggal, string $satuan): Carbon
    {
        return match ($satuan) {
            self::SATUAN_HARI => $tanggal->copy()->endOfDay(),
            self::SATUAN_MINGGU => $tanggal->copy()->endOfWeek(),
            default => $tanggal->copy()->endOfMonth(),
        };
    }

    private function majukan(Carbon $kursor, string $satuan): void
    {
        match ($satuan) {
            self::SATUAN_HARI => $kursor->addDay(),
            self::SATUAN_MINGGU => $kursor->addWeek(),
            // `addMonthNoOverflow` — 31 Jan + 1 bulan harus 28/29 Feb, bukan
            // nyelonong ke Maret dan bikin Februari ilang dari grafik.
            // `addMonthNoOverflow` walaupun kursornya SELALU tanggal 1 (dinormalisasi
            // `awalPeriode`), jadi overflow nggak bisa kejadian sekarang. Dipasang
            // biar tetap aman kalau suatu saat normalisasinya diubah — `addMonth()`
            // dari tanggal 31 nyelonong ke bulan berikutnya dan bikin satu bulan
            // ILANG dari grafik tanpa error apa pun.
            default => $kursor->addMonthNoOverflow(),
        };
    }
}
