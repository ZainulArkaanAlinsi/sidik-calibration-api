<?php

namespace App\Services\Pelanggan;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA jalan baris `customer_members` lahir atau dimatikan.
 *
 * Tiga aturan menempel di baris itu, dan ketiganya punya lebih dari satu
 * pemanggil — jadi kalau ditulis di controller, cukup satu pintu yang lupa:
 *
 * - **Peran anggota pertama** (REQ-AUTH-04): `pic_utama` kalau perusahaan belum
 *   punya anggota aktif, `staf` kalau sudah. Dua pintu memakainya (admin
 *   menyetujui pengajuan, dan orang menukar kode undangan).
 * - **Batas anggota aktif** (REQ-ANG-01), dari `customers.maks_anggota`.
 * - **Pencabutan akses** (REQ-AUTH-09): menonaktifkan anggota mencabut SEMUA
 *   tokennya dan menghapus token perangkatnya, di request yang sama.
 */
class Keanggotaan
{
    /**
     * Peran buat anggota yang akan masuk (REQ-AUTH-04).
     *
     * Dihitung dari anggota AKTIF, bukan dari jumlah baris: perusahaan yang
     * semua anggotanya dinonaktifkan harus bisa dapat PIC utama baru, kalau
     * tidak dia terkunci selamanya tanpa ada yang bisa mengundang.
     */
    public function peranUntukAnggotaBaru(Customer $perusahaan): string
    {
        $adaYangAktif = CustomerMember::query()
            ->where('customer_id', $perusahaan->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->exists();

        return $adaYangAktif ? CustomerMember::PERAN_STAF : CustomerMember::PERAN_PIC_UTAMA;
    }

    public function jumlahAktif(Customer $perusahaan): int
    {
        return CustomerMember::query()
            ->where('customer_id', $perusahaan->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->count();
    }

    /**
     * Masih muat satu anggota lagi? Melempar kalau tidak.
     *
     * Dipanggil DUA kali di jalur undangan — sekali waktu undangannya dibuat
     * (supaya PIC utama tahu lebih awal, bukan sesudah orangnya menerima email)
     * dan sekali waktu ditukar. Sekali saja tidak cukup: lima undangan bisa
     * dibuat waktu masih muat, lalu ditukar semua.
     */
    public function pastikanMuat(Customer $perusahaan): void
    {
        $maks = (int) ($perusahaan->maks_anggota ?: 0);

        if ($maks > 0 && $this->jumlahAktif($perusahaan) >= $maks) {
            throw AksiPelangganDitolak::batasAnggota($maks);
        }
    }

    /**
     * Daftarkan orang sebagai anggota perusahaan.
     *
     * `updateOrCreate` pada pasangan (customer_id, user_id) karena tabelnya
     * UNIQUE di situ: orang yang dulu dinonaktifkan lalu diundang lagi harus
     * menghidupkan barisnya, bukan menabrak unique index dengan galat SQL yang
     * tidak menjelaskan apa pun.
     */
    public function daftarkan(
        Customer $perusahaan,
        User $orang,
        ?string $peran = null,
        ?User $oleh = null,
    ): CustomerMember {
        $this->pastikanMuat($perusahaan);

        return CustomerMember::query()->updateOrCreate(
            ['customer_id' => $perusahaan->getKey(), 'user_id' => $orang->getKey()],
            [
                'organization_id' => $perusahaan->organization_id,
                'peran' => $peran ?? $this->peranUntukAnggotaBaru($perusahaan),
                'status' => CustomerMember::STATUS_AKTIF,
                'diundang_oleh' => $oleh?->getKey(),
                'dinonaktifkan_oleh' => null,
                'dinonaktifkan_pada' => null,
            ],
        );
    }

    /**
     * Nonaktifkan anggota — REQ-ANG-02 + REQ-AUTH-09 dalam satu transaksi.
     *
     * Pencabutan tokennya TIDAK boleh ditunda ke pekerjaan latar: selama
     * tokennya masih hidup, orang yang baru saja dicabut aksesnya masih bisa
     * mengunduh sertifikat perusahaan. Itu justru menit-menit yang paling
     * berisiko.
     */
    public function nonaktifkan(CustomerMember $anggota, User $oleh): void
    {
        $this->pastikanBukanPicUtamaTerakhir($anggota);

        DB::transaction(function () use ($anggota, $oleh): void {
            $anggota->forceFill([
                'status' => CustomerMember::STATUS_NONAKTIF,
                'dinonaktifkan_oleh' => $oleh->getKey(),
                'dinonaktifkan_pada' => now(),
            ])->save();

            $orang = $anggota->user;

            if ($orang === null) {
                return;
            }

            // Token & perangkat cuma dicabut kalau dia TIDAK lagi jadi anggota
            // perusahaan mana pun. Konsultan yang dilepas satu perusahaan tapi
            // masih aktif di perusahaan lain tidak boleh ikut ter-logout —
            // yang berubah cuma isi `X-Perusahaan-Id` yang boleh dia pakai.
            if ($this->masihAnggotaDiTempatLain($orang)) {
                return;
            }

            $orang->tokens()->delete();

            DeviceToken::query()
                ->where('user_id', $orang->getKey())
                ->where('aplikasi', DeviceToken::APLIKASI_PELANGGAN)
                ->delete();
        });
    }

    /**
     * Lepas SEMUA keanggotaan orang ini — jalur hapus akun (REQ-AUTH-11).
     *
     * SENGAJA melewati `pastikanBukanPicUtamaTerakhir()`, dan itu satu-satunya
     * tempat di repo ini yang boleh. REQ-AUTH-11 menyebutnya eksplisit: orang
     * berhak keluar dari layanan, dan menahannya dengan alasan "perusahaanmu
     * nanti tidak punya PIC" itu menyandera orang buat masalah organisasi yang
     * bukan miliknya.
     *
     * Namanya panjang dan menyebut alasannya justru supaya tidak terpakai
     * sebagai jalan pintas dari `nonaktifkan()` waktu penjagaannya terasa
     * merepotkan.
     *
     * Token & perangkat TIDAK disentuh di sini — pemanggilnya (`PenganonimAkun`)
     * yang menghapusnya, karena dia menghapus semuanya tanpa kecuali sedangkan
     * `nonaktifkan()` menyisakan sesi buat perusahaan lain.
     *
     * @return Collection<int, Customer> perusahaan yang jadi TANPA PIC utama aktif
     */
    public function lepaskanSemuaUntukHapusAkun(User $orang): Collection
    {
        $keanggotaan = CustomerMember::query()
            ->with('customer')
            ->where('user_id', $orang->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->get();

        foreach ($keanggotaan as $anggota) {
            $anggota->forceFill([
                'status' => CustomerMember::STATUS_NONAKTIF,
                'dinonaktifkan_oleh' => $orang->getKey(),
                'dinonaktifkan_pada' => now(),
            ])->save();
        }

        // Dihitung SESUDAH semuanya dilepas, bukan sebelum: perusahaan yang
        // kehilangan PIC utama-nya cuma bisa diketahui dari keadaan akhir, dan
        // menghitungnya lebih awal melewatkan kasus orang yang jadi PIC utama
        // di dua perusahaan sekaligus.
        return $keanggotaan
            ->map(fn (CustomerMember $anggota) => $anggota->customer)
            ->filter()
            ->unique('id')
            ->filter(fn (Customer $perusahaan) => ! CustomerMember::query()
                ->where('customer_id', $perusahaan->getKey())
                ->where('peran', CustomerMember::PERAN_PIC_UTAMA)
                ->where('status', CustomerMember::STATUS_AKTIF)
                ->exists())
            ->values();
    }

    /** REQ-ANG-02 — PIC utama terakhir tidak boleh menghilang. */
    public function pastikanBukanPicUtamaTerakhir(CustomerMember $anggota): void
    {
        if (! $anggota->adalahPicUtama() || $anggota->status !== CustomerMember::STATUS_AKTIF) {
            return;
        }

        $picLain = CustomerMember::query()
            ->where('customer_id', $anggota->customer_id)
            ->where('peran', CustomerMember::PERAN_PIC_UTAMA)
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->whereKeyNot($anggota->getKey())
            ->exists();

        if (! $picLain) {
            throw AksiPelangganDitolak::picUtamaTerakhir();
        }
    }

    private function masihAnggotaDiTempatLain(User $orang): bool
    {
        return CustomerMember::query()
            ->where('user_id', $orang->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->exists();
    }
}
