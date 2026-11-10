<?php

namespace App\Services\Pelanggan;

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\Pelanggan\SertifikatAndaTerbit;
use App\Notifications\Pelanggan\SertifikatBerubah;
use App\Services\PenjagaNotifikasiUlang;
use Illuminate\Notifications\Notification as NotifikasiLaravel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Mengabari anggota PELANGGAN begitu sertifikat perusahaannya terbit.
 *
 * Dipanggil `GenerateCertificate` sesudah sertifikat benar-benar `terbit`.
 * Sebelum ini yang dikabari cuma teknisi & admin penerbit; pelanggan baru tahu
 * kalau admin mengirim email manual atau kebetulan membuka aplikasi.
 *
 * ## Aturan yang tidak boleh longgar
 *
 * **1. Tidak pernah menggagalkan penerbitan.** Sertifikatnya sudah terbit dan
 * PDF-nya sudah ada; kabar ke pelanggan itu pelengkap. Semua kegagalan
 * ditelan di sini dan dicatat TANPA data pelanggan (id sertifikat + kelas
 * galat saja — pesan galat bisa memuat email atau nama dari kueri). Job yang
 * di-retry gara-gara ini mengulang dari nol dan mencetak nomor baru.
 *
 * **2. Cuma perusahaan pemilik alat.** Penerima dipilih lewat
 * `PreferensiNotifikasi` dari `equipment.customer` milik sesi sertifikat itu —
 * tidak ada jalur lain ke daftar penerima. Kerahasiaan antar pelanggan, ISO/IEC
 * 17025 klausul 4.2. Perusahaan yang tidak sama organisasinya dengan sertifikat
 * dilewati sama sekali.
 *
 * **3. Sekali per sertifikat per orang.** Dicek lewat `tanda_tangan` di
 * `notifications.data` (tanpa tabel/kolom baru). Job yang berjalan ulang
 * sebenarnya sudah berhenti lebih awal di `sudahTerbit()`; ini lapis kedua.
 * Batasnya jujur: notifikasi yang masih mengantre (belum jadi baris) belum
 * terlihat oleh pemeriksaan ini.
 *
 * **4. Saklar dihormati untuk sertifikat BARU; tidak untuk revisi.** Terbit
 * pertama lewat saklar `status_permintaan` (kabar tentang pekerjaan yang
 * diminta). Revisi (`<nomor>-R<n>`) menggantikan dokumen yang SUDAH dipegang
 * pelanggan, jadi memakai `SertifikatBerubah` ke semua anggota aktif — sama
 * dengan `ReviseCertificate` & `PembatalanSertifikat` (ISO/IEC 17025 §7.8.8).
 */
class KabarSertifikatTerbit
{
    public function __construct(private readonly PreferensiNotifikasi $preferensi) {}

    /**
     * @return int jumlah akun yang dikirimi (0 bila tidak ada penerima / gagal)
     */
    public function kabari(Certificate $sertifikat): int
    {
        try {
            return $this->kirim($sertifikat);
        } catch (\Throwable $e) {
            $this->catatGagal($sertifikat, $e);

            return 0;
        }
    }

    private function kirim(Certificate $sertifikat): int
    {
        // Status dibaca dari database, bukan dari instance di memori: yang
        // dipegang pemanggil bisa basi, dan kabar "sudah terbit" untuk dokumen
        // yang barusan dibatalkan/ditarik itu menyesatkan.
        $status = Certificate::query()->whereKey($sertifikat->getKey())->value('status');

        if ($status !== Certificate::STATUS_TERBIT) {
            return 0;
        }

        $sertifikat->loadMissing('session.equipment.customer');

        $pelanggan = $sertifikat->session?->equipment?->customer;

        if (! $pelanggan instanceof Customer
            || (int) $pelanggan->organization_id !== (int) $sertifikat->organization_id) {
            return 0;
        }

        $revisi = $sertifikat->revision_of !== null;

        $penerima = $revisi
            ? $this->preferensi->anggotaAktif($pelanggan)
            : $this->preferensi->penerima($pelanggan, PreferensiNotifikasi::STATUS_PERMINTAAN);

        if ($penerima->isEmpty()) {
            return 0;
        }

        if ($revisi) {
            $notifikasi = SertifikatBerubah::direvisi($sertifikat);
            $tandaTangan = SertifikatBerubah::tandaTanganUntuk($sertifikat->id, false);
        } else {
            $notifikasi = SertifikatAndaTerbit::dariSertifikat($sertifikat);
            $tandaTangan = SertifikatAndaTerbit::tandaTanganUntuk($sertifikat->id);
        }

        return $this->kirimKe($penerima, $notifikasi, $tandaTangan, $sertifikat);
    }

    /**
     * Satu per satu, masing-masing dibungkus sendiri: satu akun yang gagal
     * (mis. antrean menolak) tidak boleh membuat rekannya tidak dikabari.
     *
     * @param  Collection<int, User>  $penerima
     */
    private function kirimKe(Collection $penerima, NotifikasiLaravel $notifikasi, string $tandaTangan, Certificate $sertifikat): int
    {
        $terkirim = 0;

        /** @var User $user */
        foreach ($penerima as $user) {
            try {
                if ($this->sudahDikabari($user, $notifikasi::class, $tandaTangan)) {
                    continue;
                }

                $user->notify(clone $notifikasi);
                $terkirim++;
            } catch (\Throwable $e) {
                $this->catatGagal($sertifikat, $e);
            }
        }

        return $terkirim;
    }

    private function sudahDikabari(User $user, string $kelas, string $tandaTangan): bool
    {
        // `data` berupa teks JSON; `tanda_tangan` hanya berisi huruf, titik,
        // titik dua, dan angka — tidak ada karakter yang di-escape JSON, jadi
        // kecocokan literalnya (dengan tanda kutip penutup) pasti persis.
        return $user->notifications()
            ->where('type', $kelas)
            ->where('data', 'like', '%"'.PenjagaNotifikasiUlang::KEY_TANDA_TANGAN.'":"'.$tandaTangan.'"%')
            ->exists();
    }

    private function catatGagal(Certificate $sertifikat, \Throwable $e): void
    {
        // Sengaja BUKAN `$e->getMessage()`: pesan galat database/mail bisa memuat
        // email, nama, atau nomor telepon anggota. Id sertifikat + kelas galat
        // cukup untuk melacak.
        Log::warning('Sertifikat terbit, tapi mengabari pelanggan gagal.', [
            'certificate_id' => $sertifikat->getKey(),
            'galat' => $e::class,
        ]);
    }
}
