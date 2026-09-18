<?php

namespace App\Services\Pelanggan;

use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\Pelanggan\PerusahaanTanpaPicUtama;
use App\Services\PenerimaNotifikasi;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * REQ-AUTH-11 — hapus akun: data pribadi dianonimkan, rekaman lab TIDAK.
 *
 * ## Yang TIDAK dihapus, dan kenapa itu bukan kompromi
 *
 * Perusahaan, alat, permintaan, dan sertifikat tetap utuh (BR-07). Itu rekaman
 * laboratorium terakreditasi: sertifikat yang terbit lima tahun lalu harus tetap
 * bisa ditelusuri ke sesi kalibrasi dan alat yang diukur, dan ISO/IEC 17025
 * menuntut ketertelusuran itu bertahan jauh melewati masa satu akun. Yang hilang
 * identitas ORANG-nya, bukan jejak pengukurannya.
 *
 * Layar konfirmasi wajib menyebut ini sebelum orangnya menekan tombol — kalau
 * tidak, yang dia kira dia dapatkan berbeda dari yang benar-benar terjadi.
 *
 * ## PIC utama terakhir BOLEH menghapus akunnya
 *
 * Beda dari `Keanggotaan::nonaktifkan()` yang menolak (REQ-ANG-02). REQ-AUTH-11
 * menyebutnya eksplisit: orang berhak keluar dari layanan, dan menahannya
 * dengan alasan "perusahaanmu nanti tidak punya PIC" itu menyandera orang buat
 * masalah organisasi yang bukan miliknya. Gantinya admin lab dikabari.
 *
 * ## Foto profil
 *
 * REQ-AUTH-11 menyebut foto profil ikut dianonimkan. Kolomnya BELUM ADA di
 * `users` (diperiksa 16 Sep 2026) — jadi bukan dilewat, memang tidak ada yang
 * bisa dihapus. Begitu kolomnya lahir, dia WAJIB masuk [kolomPribadi()], dan
 * `HapusAkunTest` yang mengadu isi baris sesudahnya akan memerah kalau lupa.
 */
class PenganonimAkun
{
    public function __construct(
        private readonly Keanggotaan $keanggotaan,
        private readonly PenerimaNotifikasi $penerima,
    ) {}

    /**
     * Anonimkan akun ini, cabut seluruh aksesnya, lepas semua keanggotaannya.
     *
     * Satu transaksi, dan itu mengikat: separuh jalan berarti akun yang namanya
     * sudah hilang tapi tokennya masih hidup — orang yang mengira dirinya sudah
     * keluar padahal sesinya masih bisa membuka sertifikat perusahaan.
     */
    public function untuk(User $orang): void
    {
        /** @var Collection<int, Customer> $yatim */
        $yatim = DB::transaction(function () use ($orang) {
            $kehilangan = $this->keanggotaan->lepaskanSemuaUntukHapusAkun($orang);

            $orang->forceFill($this->kolomPribadi($orang))->save();

            $orang->tokens()->delete();
            DeviceToken::query()->where('user_id', $orang->getKey())->delete();

            return $kehilangan;
        });

        $this->kabariAdmin($orang, $yatim);
    }

    /**
     * Nilai pengganti tiap kolom pribadi.
     *
     * Ditulis sebagai SATU daftar, bukan sebaris-sebaris `forceFill`, supaya
     * test bisa mengadu isinya sebagai himpunan — kolom pribadi yang lupa
     * dibersihkan tidak memunculkan error apa pun, jadi yang harus menangkapnya
     * daftar yang bisa dibaca dua sisi.
     *
     * @return array<string, mixed>
     */
    public function kolomPribadi(User $orang): array
    {
        return [
            'name' => 'Akun Dihapus',
            // `.invalid` dijamin RFC 2606 tidak pernah jadi domain sungguhan,
            // jadi alamat ini mustahil nyasar ke kotak surat orang. NULL bukan
            // pilihan: kolomnya NOT NULL dan unik.
            'email' => 'dihapus-'.$orang->getKey().'@anonim.invalid',
            'telepon' => null,
            'jabatan' => null,
            // Sandi diacak, BUKAN dibiarkan. Kalau dibiarkan, satu-satunya yang
            // menahan masuk tinggal pemeriksaan status — dan pemeriksaan tunggal
            // itu yang paling gampang hilang waktu jalur login disentuh lagi.
            'password' => Hash::make(Str::random(64)),
            'status' => User::STATUS_NONAKTIF,
            'dianonimkan_pada' => now(),
        ];
    }

    /**
     * Kegagalan notifikasi tidak membatalkan penghapusan.
     *
     * Akunnya sudah hilang dan transaksinya sudah commit; membalas 500 di sini
     * bikin orangnya menekan "hapus akun" lagi dan ketemu akun yang sudah tidak
     * bisa dimasuki — jalan buntu, dan tidak ada yang bisa dia lakukan.
     *
     * @param  Collection<int, Customer>  $yatim
     */
    private function kabariAdmin(User $orang, Collection $yatim): void
    {
        if ($yatim->isEmpty()) {
            return;
        }

        try {
            foreach ($this->penerima->adminAktif((int) $orang->organization_id) as $admin) {
                foreach ($yatim as $perusahaan) {
                    $admin->notify(PerusahaanTanpaPicUtama::dari($perusahaan));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal mengabari admin soal perusahaan tanpa PIC utama.', [
                'user_id' => $orang->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
