<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperOrganization
 */
#[Fillable([
    'nama', 'alamat', 'telepon', 'email', 'no_akreditasi', 'standar_akreditasi',
    'akreditasi_mulai', 'akreditasi_berakhir', 'logo_path', 'tanda_tangan_path', 'settings',
])]
class Organization extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** Default ambang peringatan H- kalau org belum ngatur sendiri (± sebulan). */
    public const DEFAULT_AMBANG_HARI = 30;

    /** Kunci setting di `organization.settings` buat ambang peringatan. */
    public const KEY_AMBANG_HARI = 'reminder_hari_sebelum';

    /** Interval kalibrasi baku: 1 tahun. */
    public const DEFAULT_MASA_BERLAKU_BULAN = 12;

    /**
     * Kunci setting buat masa berlaku baku sertifikat.
     *
     * Nama panjang ini dipakai karena `OrganizationSeeder` UDAH nulis kunci ini
     * sejak awal — cuma nggak pernah ada kode yang baca (masa berlakunya dipaku
     * `now()->addYear()`). Bikin kunci baru yang lebih pendek artinya lab yang
     * udah nyetel `masa_berlaku_sertifikat_bulan: 6` diam-diam tetap dapat 12
     * bulan, dan nggak ada yang sadar sampai ada alat lewat jatuh tempo.
     */
    public const KEY_MASA_BERLAKU_BULAN = 'masa_berlaku_sertifikat_bulan';

    /**
     * Jumlah desimal tabel CALIBRATION REPORT di sertifikat.
     *
     * Kosong = turunin dari resolusi alat (`Angka::desimalDariResolusi()`), yang
     * itu perilaku bawaan & biasanya yang bener: nulis desimal lebih banyak
     * daripada yang bisa dibaca alatnya berarti ngaku presisi yang nggak ada.
     *
     * Override-nya ada karena resolusi di master alat nggak selalu ngikut spek
     * fisiknya — dan kalau kekecilan, U95% kehilangan angka penting waktu
     * dibulatkan (0,023 kecetak `0,02`). Sebelum pakai ini, cek dulu
     * `equipments.resolusi`-nya bener apa nggak; itu perbaikan yang lebih tepat.
     */
    public const KEY_DESIMAL_SERTIFIKAT = 'desimal_sertifikat';

    /**
     * Kop surat sertifikat (path di disk publik) — banner selebar halaman.
     *
     * Beda dari `logo_path`: logo itu lambang kecil di samping teks kop; kop
     * surat ini satu gambar yang udah memuat lambang, nama & alamat PT, plus
     * lambang KAN + nomor akreditasi. Kosong = pakai bawaan di
     * `public/images/kop-surat.png`.
     */
    public const KEY_KOP_PATH = 'kop_path';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'akreditasi_mulai' => 'date',
            'akreditasi_berakhir' => 'date',
        ];
    }

    /**
     * Ambang "mendekati kadaluarsa" (H- berapa hari) yang berlaku buat org ini.
     *
     * SATU angka dipakai dua tempat, sengaja:
     * - pengingat alat mendekati jatuh tempo (`PengingatJatuhTempo`)
     * - badge `warning` di standar acuan (`Standard::statusKalibrasi()`)
     *
     * Alasannya: keduanya jawab pertanyaan yang sama ("berapa lama sebelum
     * sesuatu kadaluarsa lab mau diingetin"), dan yang paling penting dari
     * permintaan mobile itu **satu sumber** — kalau HP nentuin ambangnya sendiri,
     * dia bisa bilang VALID padahal backend nolak waktu approve, dan teknisi
     * kerja sia-sia.
     *
     * Konsekuensi yang perlu disadari: admin yang ngecilin ambang biar reminder
     * alat nggak terlalu sering JUGA ngecilin jendela warning standar. Kalau
     * suatu saat perlu dipisah, tambah kunci setting baru — jangan hardcode
     * angka kedua di tempat lain.
     */
    public function ambangPeringatanHari(): int
    {
        $nilai = $this->settings[self::KEY_AMBANG_HARI] ?? null;

        return is_numeric($nilai) && (int) $nilai > 0
            ? (int) $nilai
            : self::DEFAULT_AMBANG_HARI;
    }

    /**
     * Kunci setelan posisi & ukuran tanda tangan di sertifikat.
     *
     * Posisinya RELATIF ke blok tanda tangan, bukan koordinat absolut di halaman.
     * Ini keputusan yang penting: tinggi isi sertifikat BERUBAH-UBAH — jumlah titik
     * ukur dan jumlah standar yang dipakai beda tiap sesi, jadi blok tanda tangannya
     * naik-turun. Koordinat absolut yang pas di satu sertifikat bakal nimpa tabel di
     * sertifikat lain, dan itu baru ketahuan sesudah PDF-nya nyampe pelanggan.
     */
    public const KEY_TTD_GESER_X = 'ttd_geser_x_mm';

    /** POSITIF = naik. Lihat catatan arah di `resources/views/sertifikat/pdf.blade.php`. */
    public const KEY_TTD_GESER_Y = 'ttd_geser_y_mm';

    public const KEY_TTD_LEBAR = 'ttd_lebar_mm';

    /** Lebar cetak bawaan tanda tangan (mm) — sekitar selebar kolom tanda tangan. */
    public const DEFAULT_TTD_LEBAR_MM = 35;

    /** Batas geseran (mm) — di luar ini tanda tangannya keluar dari bloknya. */
    public const MAKS_TTD_GESER_MM = 40;

    /** Batas lebar cetak (mm). Di bawah 10 nggak kebaca, di atas 80 nutupin teks. */
    public const MIN_TTD_LEBAR_MM = 10;

    public const MAKS_TTD_LEBAR_MM = 80;

    /**
     * Setelan tanda tangan yang berlaku, udah dibersihin & dibatasin.
     *
     * Dibatesin di sini (bukan cuma di validasi request) karena nilainya bisa masuk
     * lewat `PUT /organization` yang nerima `settings` sebagai array bebas, dan lewat
     * panel Filament. Nilai ngawur di sini bikin tanda tangan kecetak di luar
     * halaman — dan yang lihat cuma tahu sertifikatnya rusak, bukan kenapa.
     *
     * @return array{geser_x_mm: float, geser_y_mm: float, lebar_mm: float}
     */
    public function pengaturanTandaTangan(): array
    {
        $angka = function (string $kunci, float $bawaan, float $min, float $maks): float {
            $nilai = $this->settings[$kunci] ?? null;

            if (! is_numeric($nilai)) {
                return $bawaan;
            }

            return max($min, min($maks, (float) $nilai));
        };

        return [
            'geser_x_mm' => $angka(self::KEY_TTD_GESER_X, 0, -self::MAKS_TTD_GESER_MM, self::MAKS_TTD_GESER_MM),
            'geser_y_mm' => $angka(self::KEY_TTD_GESER_Y, 0, -self::MAKS_TTD_GESER_MM, self::MAKS_TTD_GESER_MM),
            'lebar_mm' => $angka(
                self::KEY_TTD_LEBAR,
                self::DEFAULT_TTD_LEBAR_MM,
                self::MIN_TTD_LEBAR_MM,
                self::MAKS_TTD_LEBAR_MM,
            ),
        ];
    }

    /**
     * Masa berlaku baku sertifikat, dalam bulan.
     *
     * Ini cuma DEFAULT — admin tetap bisa nimpa per sertifikat waktu approve
     * (`berlaku_sampai`). Gunanya biar interval yang lab ini hampir selalu pakai
     * nggak perlu diketik ulang tiap approve; ngetik tanggal manual tiap kali itu
     * justru sumber salah ketik di kolom yang nentuin kapan alat harus dikalibrasi
     * lagi.
     *
     * Dibatasi 1–120 bulan: 0 bikin sertifikat kadaluarsa di hari terbitnya, dan
     * di atas 10 tahun hampir pasti salah ketik.
     */
    public function masaBerlakuBulan(): int
    {
        $nilai = $this->settings[self::KEY_MASA_BERLAKU_BULAN] ?? null;

        return is_numeric($nilai) && (int) $nilai >= 1 && (int) $nilai <= 120
            ? (int) $nilai
            : self::DEFAULT_MASA_BERLAKU_BULAN;
    }

    /**
     * Lab yang akreditasinya kadaluarsa nggak boleh nerbitin sertifikat
     * terakreditasi. Dipakai waktu generate sertifikat (Minggu 08).
     */
    public function akreditasiMasihBerlaku(): bool
    {
        return $this->akreditasi_berakhir === null || $this->akreditasi_berakhir->isFuture();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** @return HasMany<Equipment, $this> */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
