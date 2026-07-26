<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Laporan kalibrasi berpenyaring (spesifikasi poin 08, fase-2 §5).
 *
 * SATU tempat yang nyusun query-nya, dipakai layar (JSON berpaginasi) DAN file
 * export (PDF/Excel). Ini bukan soal rapi-rapi: kalau layar dan export nyusun
 * query sendiri-sendiri, laporan yang dilihat admin di HP bisa beda isi dari file
 * yang dia kirim ke asesor — dan dua laporan dengan angka beda buat periode yang
 * sama itu temuan audit, bukan cuma nggak enak.
 *
 * Sama alasannya kenapa rekapnya nggak dirakit di HP (fase-2 §5): pembulatan &
 * zona waktu di device bisa geser sedikit dari server.
 */
class LaporanKalibrasi
{
    /** Batas baris buat export — nahan satu request narik puluhan ribu baris. */
    public const MAKS_BARIS_EXPORT = 5000;

    /**
     * Query sesi kalibrasi sesuai penyaring + hak akses pemanggil.
     *
     * @param  array<string, mixed>  $filter
     * @return Builder<CalibrationSession>
     */
    public function query(array $filter, User $user): Builder
    {
        return CalibrationSession::query()
            ->with([
                'equipment.customer', 'equipment.category', 'teknisi', 'reviewer', 'certificate',
                'uncertaintyCalculations',
            ])
            ->where('organization_id', $user->organization_id)
            // Teknisi SELALU cuma dapat pekerjaannya sendiri — aturan yang sama
            // kayak /calibrations. Kalau di laporan dilonggarin, penyaringan di
            // layar riwayat jadi nggak ada artinya: tinggal buka Laporan.
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn (Builder $q) => $q->where('teknisi_id', $user->id),
            )
            // Rentang tanggal dibandingin per-TANGGAL, bukan per-jam. `sampai`
            // pakai `<=` biar tanggal yang diketik user ikut kehitung — kalau
            // pakai `<`, laporan "1–31 Juli" diam-diam ngilangin tanggal 31.
            ->when(
                filled($filter['dari'] ?? null),
                fn (Builder $q) => $q->whereDate('tanggal_kalibrasi', '>=', $filter['dari']),
            )
            ->when(
                filled($filter['sampai'] ?? null),
                fn (Builder $q) => $q->whereDate('tanggal_kalibrasi', '<=', $filter['sampai']),
            )
            ->when(
                filled($filter['pelanggan_id'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'equipment',
                    fn (Builder $e) => $e->where('customer_id', (int) $filter['pelanggan_id']),
                ),
            )
            ->when(
                filled($filter['teknisi_id'] ?? null),
                fn (Builder $q) => $q->where('teknisi_id', (int) $filter['teknisi_id']),
            )
            // `kategori` itu KODE kategori (mis. `suhu-dan-kelembapan`), bukan id —
            // sama kayak penyaring di /equipments, biar mobile nggak perlu dua
            // gaya penyaring buat hal yang sama.
            ->when(
                filled($filter['kategori'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'equipment.category',
                    fn (Builder $k) => $k->where('kode', $filter['kategori']),
                ),
            )
            ->when(
                filled($filter['status'] ?? null),
                fn (Builder $q) => $q->where('status', $filter['status']),
            )
            ->when(
                filled($filter['keputusan'] ?? null),
                fn (Builder $q) => $q->where('keputusan', $filter['keputusan']),
            )
            ->orderBy('tanggal_kalibrasi')
            ->orderBy('id');
    }

    /**
     * Angka ringkasan buat kepala laporan.
     *
     * Dihitung lewat query agregat terpisah, BUKAN dari koleksi yang udah
     * dipaginasi — kalau dari halaman, "total 15" cuma berarti "15 di halaman
     * ini", dan itu angka yang menyesatkan di dokumen yang dikirim ke asesor.
     *
     * @param  array<string, mixed>  $filter
     * @return array{total: int, pass: int, fail: int, belum_ada_keputusan: int, disetujui: int}
     */
    public function ringkasan(array $filter, User $user): array
    {
        // Satu query agregat, bukan lima COUNT terpisah — penyaringnya bisa
        // nyentuh whereHas (pelanggan/kategori), dan ngulang subquery itu lima
        // kali bikin laporan lambat di lab yang datanya udah setahun.
        $hasil = $this->query($filter, $user)
            // Relasi & urutan nggak relevan buat COUNT, dan `orderBy` di query
            // beragregat bikin sebagian DB ngeluh.
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN keputusan = 'PASS' THEN 1 ELSE 0 END) as pass")
            ->selectRaw("SUM(CASE WHEN keputusan = 'FAIL' THEN 1 ELSE 0 END) as fail")
            // Sesi yang belum lewat perhitungan (draft / data belum cukup) —
            // dipisah biar total nggak kelihatan nggak nyambung sama pass+fail.
            ->selectRaw('SUM(CASE WHEN keputusan IS NULL THEN 1 ELSE 0 END) as belum')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as disetujui', [
                CalibrationSession::STATUS_DISETUJUI,
            ])
            ->first();

        return [
            'total' => (int) ($hasil->total ?? 0),
            'pass' => (int) ($hasil->pass ?? 0),
            'fail' => (int) ($hasil->fail ?? 0),
            'belum_ada_keputusan' => (int) ($hasil->belum ?? 0),
            'disetujui' => (int) ($hasil->disetujui ?? 0),
        ];
    }

    /**
     * Baris siap-tulis buat PDF & Excel — bentuk yang SAMA buat dua-duanya.
     *
     * Sengaja array datar, bukan model: dua exporter beda format nggak boleh
     * masing-masing mutusin cara ngambil nilainya dari relasi, soalnya di situ
     * selisih kecil (mis. satu pakai nama alat dari sesi, satu dari sertifikat)
     * mulai muncul tanpa ada yang sadar.
     *
     * @param  Collection<int, CalibrationSession>  $sesi
     * @return list<array<string, mixed>>
     */
    public function baris(Collection $sesi): array
    {
        return $sesi->map(function (CalibrationSession $s): array {
            $penentu = $s->titikPenentu();

            return [
                'nomor_sesi' => $s->nomor_sesi,
                'tanggal_kalibrasi' => $s->tanggal_kalibrasi?->toDateString(),
                'pelanggan' => $s->equipment?->customer?->nama,
                'nama_alat' => $s->equipment?->nama_alat,
                'serial_number' => $s->equipment?->serial_number,
                'kategori' => $s->equipment?->category?->nama,
                'teknisi' => $s->teknisi?->name,
                'status' => $s->status,
                'keputusan' => $s->keputusan,
                // Diambil dari titik PENENTU (yang paling mepet batas toleransi) —
                // angka yang sama yang dipajang di `hasil` detail sesi, bukan
                // rata-rata semua titik.
                'ketidakpastian_diperluas' => $penentu?->ketidakpastian_diperluas,
                'nomor_sertifikat' => $s->certificate?->nomor,
            ];
        })->all();
    }

    /**
     * Judul kolom buat PDF & Excel — urutannya nyocokin `baris()`.
     *
     * @return list<string>
     */
    public function judulKolom(): array
    {
        return [
            'Nomor Sesi', 'Tanggal Kalibrasi', 'Pelanggan', 'Nama Alat',
            'Serial Number', 'Kategori', 'Teknisi', 'Status', 'Keputusan',
            'U95% (±)', 'Nomor Sertifikat',
        ];
    }
}
