<?php

namespace App\Services\Pelanggan;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\PengajuanAkunPelanggan;
use App\Models\User;
use App\Notifications\Pelanggan\AnggotaBaruBergabung;
use App\Notifications\Pelanggan\PengajuanDiputus;
use App\Support\KemiripanNama;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin lab memutuskan pengajuan akun pelanggan (REQ-AUTH-04, REQ-AUTH-05).
 *
 * ## Yang dijaga paling keras: dua admin menekan tombol bersamaan
 *
 * Tanpa penjagaan, dua admin yang membuka antrean di waktu yang sama dan
 * sama-sama menyetujui menghasilkan DUA baris `customer_members` — dan kalau
 * keduanya memilih pelanggan yang berbeda, satu orang jadi anggota dua
 * perusahaan yang tidak dia minta, lengkap dengan akses ke sertifikat keduanya.
 *
 * Yang menahan: transisinya `UPDATE ... WHERE status = 'menunggu'`, dan jumlah
 * baris terpengaruh yang menentukan siapa yang menang. Bukan `if ($status ===
 * 'menunggu')` di PHP — itu membaca dan menulis di dua waktu yang berbeda, dan
 * celah di antaranya persis yang sedang dijaga.
 *
 * ## Klaim tidak pernah otomatis jadi pelanggan
 *
 * `nama_perusahaan` yang diketik pendaftar itu KLAIM (R-D02: orang mengaku dari
 * PT X lalu melihat sertifikat PT X). Dia baru jadi data lab sesudah admin
 * menautkannya ke pelanggan yang benar — atau membuat yang baru dengan sadar.
 * Itu sebabnya [setujui()] menuntut salah satu dari dua, tidak pernah menebak.
 */
class PersetujuanAkun
{
    public function __construct(private readonly Keanggotaan $keanggotaan) {}

    /**
     * Pelanggan yang namanya mirip dengan klaim pendaftar.
     *
     * Dipakai admin buat memilih, BUKAN buat memilihkan. Aturannya dipinjam
     * dari jalur impor pelanggan (`KemiripanNama`) supaya dua fitur yang
     * menjawab pertanyaan yang sama tidak menjawabnya dengan dua cara.
     *
     * @return Collection<int, Customer>
     */
    public function saranPelanggan(PengajuanAkunPelanggan $pengajuan): Collection
    {
        $normal = Customer::normalkanNama((string) $pengajuan->nama_perusahaan);

        if ($normal === '') {
            return new Collection;
        }

        // Disaring kasar dulu di database (kata pertama), baru diadu aturan
        // kemiripan di PHP. Tanpa saringan kasar, tiap pembukaan antrean
        // menarik SELURUH tabel pelanggan ke memori — lab ini punya ribuan.
        $kataPertama = explode(' ', $normal)[0] ?? '';

        return Customer::query()
            ->where('organization_id', $pengajuan->organization_id)
            ->where(function ($kueri) use ($normal, $kataPertama): void {
                $kueri->where('nama_normal', $normal)
                    ->orWhere('nama_normal', 'like', $kataPertama.'%');
            })
            ->orderBy('nama')
            ->limit(50)
            ->get()
            ->filter(fn (Customer $pelanggan) => KemiripanNama::mirip($normal, (string) $pelanggan->nama_normal))
            ->values();
    }

    /**
     * REQ-AUTH-04 — setujui, tautkan ke pelanggan, jadikan anggota.
     *
     * @param  array<string, mixed>|null  $pelangganBaru  dipakai kalau `$pelanggan` null
     */
    public function setujui(
        PengajuanAkunPelanggan $pengajuan,
        User $admin,
        ?Customer $pelanggan,
        ?array $pelangganBaru = null,
    ): PengajuanAkunPelanggan {
        return DB::transaction(function () use ($pengajuan, $admin, $pelanggan, $pelangganBaru) {
            $this->kunciTransisi($pengajuan, PengajuanAkunPelanggan::STATUS_DISETUJUI, $admin);

            $pelanggan ??= $this->pelangganBaru($pengajuan, $admin, $pelangganBaru ?? []);

            $pemohon = $pengajuan->pemohon;

            if ($pemohon === null) {
                throw new AksiPelangganDitolak(
                    'pemohon_hilang',
                    'Akun pemohon sudah tidak ada. Pengajuan ini tidak bisa disetujui.',
                    422,
                );
            }

            // PIC utama yang SUDAH ada dicatat sebelum anggota baru masuk —
            // sesudahnya, orang baru itu sendiri bisa ikut terjaring dan
            // dikabari "ada anggota baru" soal dirinya sendiri.
            $picUtamaLama = $this->picUtamaAktif($pelanggan);

            $anggota = $this->keanggotaan->daftarkan($pelanggan, $pemohon, oleh: $admin);

            $pemohon->forceFill(['status' => User::STATUS_AKTIF])->save();

            $pengajuan->forceFill([
                'status' => PengajuanAkunPelanggan::STATUS_DISETUJUI,
                'customer_id' => $pelanggan->getKey(),
                'diputus_oleh' => $admin->getKey(),
                'diputus_pada' => now(),
            ])->save();

            $this->kabari($pemohon, PengajuanDiputus::disetujui($pengajuan->fresh(), $pelanggan, $anggota));

            foreach ($picUtamaLama as $pic) {
                if ($pic->user !== null) {
                    $this->kabari($pic->user, AnggotaBaruBergabung::dari($pemohon, $pelanggan, $anggota));
                }
            }

            return $pengajuan->refresh();
        });
    }

    /** REQ-AUTH-05 — tolak dengan alasan yang sampai ke pemohonnya. */
    public function tolak(PengajuanAkunPelanggan $pengajuan, User $admin, string $alasan): PengajuanAkunPelanggan
    {
        return DB::transaction(function () use ($pengajuan, $admin, $alasan) {
            $this->kunciTransisi($pengajuan, PengajuanAkunPelanggan::STATUS_DITOLAK, $admin);

            $pengajuan->forceFill([
                'status' => PengajuanAkunPelanggan::STATUS_DITOLAK,
                'diputus_oleh' => $admin->getKey(),
                'diputus_pada' => now(),
                'alasan_tolak' => $alasan,
            ])->save();

            // Akun pemohon SENGAJA dibiarkan `pending_verifikasi`, tidak
            // dinonaktifkan: REQ-AUTH-05 minta dia tetap tanpa akses data, dan
            // layar S06 harus tetap bisa dibaca supaya alasan penolakannya
            // sampai. Akun yang dimatikan tidak bisa membaca alasannya sendiri.
            if ($pengajuan->pemohon !== null) {
                $this->kabari($pengajuan->pemohon, PengajuanDiputus::ditolak($pengajuan->fresh()));
            }

            return $pengajuan->refresh();
        });
    }

    /**
     * Transisi yang menang HANYA kalau barisnya masih `menunggu`.
     *
     * Dikerjakan sebagai satu `UPDATE ... WHERE status = 'menunggu'` dan
     * dihitung dari baris terpengaruh — bukan dibaca dulu lalu ditulis, yang
     * menyisakan celah persis di antara keduanya.
     *
     * Kolom yang ditulis di sini cuma penanda sementara; nilai sebenarnya
     * ditulis pemanggil sesudahnya, di transaksi yang sama.
     */
    private function kunciTransisi(PengajuanAkunPelanggan $pengajuan, string $ke, User $admin): void
    {
        $kena = PengajuanAkunPelanggan::query()
            ->whereKey($pengajuan->getKey())
            ->where('status', PengajuanAkunPelanggan::STATUS_MENUNGGU)
            ->update(['status' => $ke, 'diputus_oleh' => $admin->getKey(), 'diputus_pada' => now()]);

        if ($kena === 1) {
            return;
        }

        $terkini = $pengajuan->fresh();

        throw AksiPelangganDitolak::sudahDiputus($terkini?->pemutus?->name);
    }

    /**
     * Pelanggan baru yang lahir dari pengajuan yang disetujui.
     *
     * Tiga kolom diisi SERVER dan tidak pernah dari payload:
     * `organization_id`, `dibuat_oleh_user_id`, dan `sumber`. Dua terakhir
     * memang tidak ada di `#[Fillable]` `Customer` — itu disengaja di sana,
     * jadi `fill()` untuk kolom yang datang dari admin, penugasan langsung
     * untuk yang tidak.
     *
     * `sumber = pelanggan` bukan nilai baru: sudah ada sejak Fase 3 persis buat
     * jalur ini, dan dia yang nanti membedakan pelanggan yang lahir dari
     * pengajuan dari yang diketik admin atau diimpor.
     *
     * @param  array<string, mixed>  $dariAdmin
     */
    private function pelangganBaru(PengajuanAkunPelanggan $pengajuan, User $admin, array $dariAdmin): Customer
    {
        $pelanggan = new Customer;
        $pelanggan->fill($dariAdmin);
        $pelanggan->organization_id = $pengajuan->organization_id;
        $pelanggan->dibuat_oleh_user_id = $admin->getKey();
        $pelanggan->sumber = Customer::SUMBER_PELANGGAN;
        $pelanggan->save();

        return $pelanggan;
    }

    /** @return Collection<int, CustomerMember> */
    private function picUtamaAktif(Customer $pelanggan): Collection
    {
        return CustomerMember::query()
            ->with('user')
            ->where('customer_id', $pelanggan->getKey())
            ->where('peran', CustomerMember::PERAN_PIC_UTAMA)
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->get();
    }

    /**
     * Kegagalan notifikasi TIDAK menggagalkan keputusannya.
     *
     * Persetujuannya sudah tertulis dan orangnya sudah jadi anggota; membalas
     * 500 di sini bikin admin menekan "setujui" lagi dan ketemu 409
     * `sudah_diputus` — jalan buntu yang lebih buruk daripada satu email yang
     * tidak sampai.
     */
    private function kabari(User $orang, object $notifikasi): void
    {
        try {
            $orang->notify($notifikasi);
        } catch (\Throwable $e) {
            Log::warning('Gagal mengabari keputusan pengajuan akun pelanggan.', [
                'user_id' => $orang->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
