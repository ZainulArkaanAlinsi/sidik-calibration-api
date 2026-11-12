<?php

namespace App\Services\Pelanggan;

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\User;
use App\Notifications\Pelanggan\SertifikatSudahTersedia;
use App\Services\PenjagaNotifikasiUlang;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Support\Facades\Log;

/**
 * Kabar SUSULAN satu kali untuk anggota perusahaan yang bergabung SESUDAH
 * sertifikat perusahaannya terbit.
 *
 * `KabarSertifikatTerbit` hanya menjangkau anggota yang sudah aktif saat
 * sertifikat terbit. Orang yang diundang/diaktifkan belakangan tetap bisa
 * membuka sertifikat perusahaannya (isolasinya per `customer_id`, bukan per
 * tanggal masuk), tapi tidak pernah diberi tahu bahwa ada yang menunggunya.
 *
 * ## Aturan yang tidak boleh longgar
 *
 * **1. Tidak pernah menggagalkan penerimaan undangan.** Anggotanya sudah
 * terdaftar; kabar ini pelengkap. Semua galat ditelan dan dicatat TANPA data
 * pelanggan (id anggota + kelas galat saja).
 *
 * **2. SATU kabar ringkas, bukan satu per sertifikat.** Orang yang masuk ke
 * perusahaan dengan 40 sertifikat tidak boleh dibanjiri 40 notifikasi.
 *
 * **3. Hanya perusahaannya sendiri.** Jumlahnya dihitung lewat `LingkupData`
 * dengan `customer_id` dari anggota yang SUDAH terdaftar di database (bukan
 * dari request), dan perusahaan yang organisasinya tidak sama dengan anggotanya
 * dilewati. Kerahasiaan antar pelanggan, ISO/IEC 17025 klausul 4.2.
 *
 * **4. Saklar dihormati.** Lewat `PreferensiNotifikasi::penerima(...,
 * STATUS_PERMINTAAN)`, sama dengan kabar "sertifikat terbit" pertama. Anggota
 * yang dulu mematikannya lalu diundang kembali tetap tidak dikabari.
 *
 * **5. Sekali per orang per perusahaan.** Dijaga lewat `tanda_tangan` di
 * `notifications.data` (tanpa tabel/kolom baru), jadi undangan ulang atau
 * pengaktifan kembali tidak mengirim dua kali. Batasnya jujur: notifikasi yang
 * masih mengantre (belum jadi baris) belum terlihat oleh pemeriksaan ini.
 *
 * **6. Hanya sertifikat yang BERLAKU** (terbit dan belum digantikan) yang
 * dihitung. Tidak ada yang berlaku = tidak ada kabar.
 */
class KabarSertifikatUntukAnggotaBaru
{
    public function __construct(private readonly PreferensiNotifikasi $preferensi) {}

    /**
     * @return int jumlah notifikasi yang dikirim (0 atau 1)
     */
    public function kabari(CustomerMember $anggota): int
    {
        try {
            return $this->kirim($anggota);
        } catch (\Throwable $e) {
            // Sengaja BUKAN `$e->getMessage()`: pesan galat bisa memuat email
            // atau nama anggota.
            Log::warning('Anggota pelanggan bergabung, tapi kabar susulan sertifikat gagal.', [
                'customer_member_id' => $anggota->getKey(),
                'galat' => $e::class,
            ]);

            return 0;
        }
    }

    private function kirim(CustomerMember $anggota): int
    {
        // Dibaca ulang dari database: instance yang dipegang pemanggil bisa
        // basi, dan orang yang barusan dinonaktifkan tidak boleh dikabari.
        $baris = CustomerMember::query()->with(['customer', 'user'])->find($anggota->getKey());

        if ($baris === null || $baris->status !== CustomerMember::STATUS_AKTIF) {
            return 0;
        }

        $pelanggan = $baris->customer;
        $user = $baris->user;

        if (! $pelanggan instanceof Customer
            || ! $user instanceof User
            || (int) $pelanggan->organization_id !== (int) $baris->organization_id) {
            return 0;
        }

        // Saklar `status_permintaan` + akun pelanggan aktif + anggota aktif:
        // satu-satunya definisi "boleh dikabari" ada di `PreferensiNotifikasi`.
        $boleh = $this->preferensi
            ->penerima($pelanggan, PreferensiNotifikasi::STATUS_PERMINTAAN)
            ->contains(fn (User $u) => $u->is($user));

        if (! $boleh) {
            return 0;
        }

        $tandaTangan = SertifikatSudahTersedia::tandaTanganUntuk((int) $pelanggan->getKey());

        if ($this->sudahDikabari($user, $tandaTangan)) {
            return 0;
        }

        $jumlah = (new LingkupData(Konteks::dari($baris)))->sertifikat()
            ->where('status', Certificate::STATUS_TERBIT)
            ->belumDigantikan()
            ->count();

        if ($jumlah === 0) {
            return 0;
        }

        $user->notify(SertifikatSudahTersedia::untuk((int) $pelanggan->getKey(), $jumlah));

        return 1;
    }

    private function sudahDikabari(User $user, string $tandaTangan): bool
    {
        return $user->notifications()
            ->where('type', SertifikatSudahTersedia::class)
            ->where('data', 'like', '%"'.PenjagaNotifikasiUlang::KEY_TANDA_TANGAN.'":"'.$tandaTangan.'"%')
            ->exists();
    }
}
