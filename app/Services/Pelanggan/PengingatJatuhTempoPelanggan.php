<?php

namespace App\Services\Pelanggan;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Pelanggan\AlatAndaJatuhTempo;
use App\Services\PenjagaNotifikasiUlang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Pengingat jatuh tempo kalibrasi — ke HP PELANGGAN.
 *
 * Kembaran `App\Services\PengingatJatuhTempo`, yang sudah ada dan mengabari
 * ADMIN. Kelas ini tidak menggantikannya dan tidak menyalin isinya: dia
 * mengabari pihak lain, dengan ambang lain, dengan isi yang lain.
 *
 * ## Tiga hal yang membedakannya dari versi admin, dan semuanya perlu
 *
 * **1. Dikelompokkan per PELANGGAN, bukan per alat.**
 * Pelanggan dengan 14 alat jatuh tempo bulan ini harus menerima SATU notifikasi,
 * bukan 14. Empat belas notifikasi dari satu aplikasi dalam satu pagi bukan
 * pengingat — itu alasan orang mematikan notifikasi aplikasi itu selamanya, dan
 * sesudah dimatikan pengingat yang penting pun tidak sampai.
 *
 * **2. Tangga pengingat, bukan satu ambang.**
 * Versi admin memakai satu ambang (default 30 hari) + masa tenang 7 hari, dan itu
 * benar untuk admin: dia merencanakan kapasitas lab. Pelanggan butuh yang lain —
 * satu kabar sebulan sebelumnya akan dilupakan, dan pengingat yang datang tepat
 * waktu justru yang paling berguna. Jadi: **H-30, H-7, H-1, lalu tiap 7 hari
 * sesudah lewat**.
 *
 * Tangga itulah yang membuat ini "alarm" dan bukan "pemberitahuan". Sengaja
 * TIDAK harian: alarm harian dimatikan dalam seminggu.
 *
 * **3. Isinya cuma alat pelanggan itu.**
 * Kebocoran paling mudah terjadi di fitur seperti ini — satu kueri yang lupa
 * `where customer_id` dan pelanggan A menerima daftar alat pelanggan B di
 * notifikasinya, lengkap dengan nomor seri. Kerahasiaan antar pelanggan, ISO/IEC
 * 17025 klausul 4.2. Test `test_pelanggan_tidak_menerima_alat_pelanggan_lain`
 * yang menjaganya.
 */
class PengingatJatuhTempoPelanggan
{
    /**
     * Tangga pengingat, dalam hari SEBELUM jatuh tempo.
     *
     * Angka-angkanya bukan selera: 30 = masih cukup waktu menjadwalkan ulang
     * tanpa mengganggu produksi; 7 = tenggat sudah terasa dan alat masih bisa
     * dikirim; 1 = kesempatan terakhir. Yang lebih rapat dari itu masuk ke
     * wilayah "mengganggu", dan yang diganggu akan mematikan notifikasinya.
     *
     * @var list<int>
     */
    public const TANGGA_HARI = [30, 7, 1];

    /** Sesudah lewat jatuh tempo, diulang sepekan sekali — bukan tiap hari. */
    public const ULANG_SESUDAH_LEWAT_HARI = 7;

    /**
     * Isi yang sama tidak diulang selama ini.
     *
     * Enam hari, bukan tujuh seperti versi admin — dan satu hari itu bukan
     * kelalaian. Pengulangan lewat-jatuh-tempo dijadwalkan tiap 7 hari; masa
     * tenang yang JUGA tepat 7 hari membuat keduanya berlomba di hari yang sama,
     * dan yang kalah beda-beda tergantung jam scheduler jalan. Hasilnya pengingat
     * yang kadang datang di hari ke-7, kadang ke-14, tanpa pola yang bisa
     * dijelaskan ke pelanggan yang menanyakannya.
     */
    public const MASA_TENANG_HARI = 6;

    public function __construct(private readonly PenjagaNotifikasiUlang $penjaga) {}

    /**
     * Jalanin buat semua organisasi (dipakai scheduler harian).
     *
     * @return array<int, array{organization_id: int, pelanggan_dikabarin: int, pelanggan_dilewat: int, alat: int}>
     */
    public function jalankan(): array
    {
        return Organization::query()->get()
            ->map(fn (Organization $org): ?array => $this->untukOrganisasi($org))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{organization_id: int, pelanggan_dikabarin: int, pelanggan_dilewat: int, alat: int}|null
     */
    public function untukOrganisasi(Organization $org): ?array
    {
        // Ambil SEKALI untuk seluruh organisasi, lalu dikelompokkan di PHP.
        // Alternatifnya satu kueri per pelanggan — dan lab dengan 300 pelanggan
        // membuat scheduler harian menjalankan 300 kueri tiap pagi untuk
        // pekerjaan yang hasilnya biasanya kosong.
        $alat = Equipment::query()
            ->where('organization_id', $org->id)
            ->where('status', Equipment::STATUS_AKTIF)
            ->whereNotNull('customer_id')
            ->whereNotNull('tanggal_jatuh_tempo')
            // Batas atas = tangga tertinggi. Yang lebih jauh dari itu belum
            // waktunya dikabari, dan mengambilnya cuma membebani memori.
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->addDays(max(self::TANGGA_HARI)))
            ->get()
            ->filter(fn (Equipment $a): bool => $this->waktunyaDikabari($a));

        if ($alat->isEmpty()) {
            return null;
        }

        $dikabarin = 0;
        $dilewat = 0;

        foreach ($alat->groupBy('customer_id') as $customerId => $alatPelanggan) {
            $pelanggan = Customer::find($customerId);

            if ($pelanggan === null) {
                continue;
            }

            $penerima = $this->penerimaPelanggan($pelanggan);

            if ($penerima->isEmpty()) {
                // Pelanggan tanpa akun aktif di apk. Bukan kegagalan — sebagian
                // pelanggan memang belum diundang. Dihitung supaya admin bisa
                // melihat berapa banyak pengingat yang TIDAK sampai, dan itu
                // angka yang berguna: dia yang menjelaskan kenapa pelanggan
                // tertentu selalu terlambat mengirim alat.
                $dilewat++;

                continue;
            }

            $notifikasi = AlatAndaJatuhTempo::dariAlat($pelanggan, $alatPelanggan);
            $tandaTangan = $notifikasi->tandaTanganUntukPenjaga();

            // Masa tenang diperiksa PER ORANG, bukan per pelanggan — itu kontrak
            // `PenjagaNotifikasiUlang::bolehKirim()`, dan alasannya ada di
            // docblock-nya: dia melihat riwayat notifikasi penerimanya sendiri.
            // Anggota yang baru diundang minggu ini belum pernah menerima apa
            // pun, jadi dia harus dapat kabarnya walau rekannya sudah.
            //
            // Tanda tangannya dihitung dari daftar alat UTUH (lihat
            // `AlatAndaJatuhTempo::tandaTangan()`), bukan dari daftar yang sudah
            // dipotong untuk tampilan. Kalau dari yang terpotong, alat ke-11 dan
            // seterusnya bisa berubah tanpa mengubah tanda tangannya — dan kabar
            // BARU justru yang tertahan. Bug persis itu pernah ada di versi admin
            // dan sudah diperbaiki di sana; jangan diulang di sini.
            $bolehTerima = $penerima->filter(fn (User $u): bool => $this->penjaga->bolehKirim(
                $u,
                AlatAndaJatuhTempo::class,
                $tandaTangan,
                self::MASA_TENANG_HARI,
            ));

            if ($bolehTerima->isEmpty()) {
                $dilewat++;

                continue;
            }

            Notification::send($bolehTerima, $notifikasi);
            $dikabarin++;
        }

        return [
            'organization_id' => $org->id,
            'pelanggan_dikabarin' => $dikabarin,
            'pelanggan_dilewat' => $dilewat,
            'alat' => $alat->count(),
        ];
    }

    /**
     * Hari ini tepat di salah satu anak tangga?
     *
     * Kenapa `===` dan bukan `<=`: dengan `<=`, alat yang lewat H-30 akan
     * memenuhi syarat SETIAP HARI sampai jatuh tempo, dan yang menahannya cuma
     * masa tenang 7 hari — hasilnya pengingat tiap minggu sepanjang bulan, bukan
     * tangga. Pencocokan tepat membuat tangganya benar-benar tangga.
     *
     * Konsekuensinya jujur dan harus diketahui: kalau scheduler tidak jalan satu
     * hari (server mati, deploy), anak tangga hari itu **terlewat** dan tidak
     * dikejar. Itu pilihan sadar — mengejarnya berarti mengirim H-7 di hari H-5,
     * dan pengingat yang tanggalnya tidak cocok dengan isinya membuat orang
     * berhenti mempercayainya. Yang lewat jatuh tempo tetap tertangkap cabang
     * kedua, jadi tidak ada alat yang hilang dari radar sama sekali.
     */
    private function waktunyaDikabari(Equipment $alat): bool
    {
        $jatuhTempo = $alat->tanggal_jatuh_tempo;

        if ($jatuhTempo === null) {
            return false;
        }

        $selisih = now()->startOfDay()->diffInDays($jatuhTempo->startOfDay(), false);

        // Belum lewat: cocokkan dengan anak tangga.
        if ($selisih >= 0) {
            return in_array((int) $selisih, self::TANGGA_HARI, true);
        }

        // Sudah lewat: tiap 7 hari. `abs(-7) % 7 === 0` → H+7, H+14, H+21…
        // H+0 (hari jatuh tempo itu sendiri) sudah tertangkap cabang di atas
        // lewat `$selisih === 0`? Tidak — 0 tidak ada di TANGGA_HARI. Jadi
        // hari-H sengaja tidak mengirim apa pun: H-1 baru kemarin, dan dua kabar
        // dua hari berturut-turut adalah awal dari notifikasi yang dimatikan.
        return abs((int) $selisih) % self::ULANG_SESUDAH_LEWAT_HARI === 0;
    }

    /**
     * Akun pelanggan yang aktif dan memang punya akses ke perusahaan ini.
     *
     * Lewat `customer_members`, bukan `users.customer_id` — satu orang bisa jadi
     * anggota beberapa perusahaan, dan pengingat yang dikirim berdasarkan kolom
     * di `users` akan melewatkan anggota kedua dan seterusnya.
     *
     * @return Collection<int, User>
     */
    private function penerimaPelanggan(Customer $pelanggan): Collection
    {
        return User::query()
            ->where('role', User::ROLE_PELANGGAN)
            ->where('status', User::STATUS_AKTIF)
            ->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('customer_members')
                ->whereColumn('customer_members.user_id', 'users.id')
                ->where('customer_members.customer_id', $pelanggan->id)
                // Anggota yang sudah dinonaktifkan PIC-nya tidak boleh terus
                // menerima daftar alat perusahaan yang sudah bukan urusannya —
                // itu kebocoran yang pelan tapi pasti.
                ->where('customer_members.status', 'aktif'))
            ->get();
    }
}
