<?php

namespace App\Services\Studio\Hitung;

use App\Models\WorkbookMaster;
use App\Services\Studio\Workbook\ImporWorkbook;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SensitiveParameter;

/**
 * Model hitung + graf dependensi per sha256 berkas asli (Studio tahap C).
 *
 * Cache di disk privat `local`: `studio-hitung/{sha256}-m{versi}.bin` =
 * Crypt(gzdeflate(serialize(larik skalar))). Terenkripsi kunci aplikasi
 * (isinya setara isi workbook, termasuk lembar DATABASE) dan ber-MAC, jadi
 * `unserialize` hanya pernah membaca byte yang kita tulis sendiri — itupun
 * dengan `allowed_classes => false`.
 *
 * Diisi saat impor (sandi masih di tangan) dan, bila kosong, dari berkas asli
 * yang tersimpan: `.enc` (tanpa sandi, terenkripsi kunci aplikasi) dibuka
 * sendiri; berkas bersandi pemilik TIDAK bisa → `GagalHitung::perluSandi()`
 * (admin menjalankan `studio:siapkan-hitung`). Disapu `studio:sapu-temp-workbook`
 * bila tidak dipakai `studio.hitung.umur_cache_hari` hari (dibaca = disentuh).
 */
final class SumberModelHitung
{
    /**
     * Cache ikut disk AWET berkas asli (`studio.disk_berkas`). Berkas bersandi
     * pemilik tidak bisa dibangun ulang tanpa sandi, dan Render tanpa shell
     * (`studio:siapkan-hitung` tidak terjangkau): cache di disk container yang
     * terhapus tiap deploy berarti `/hitung` workbook bersandi mati sampai
     * diimpor ulang. Isinya terenkripsi kunci aplikasi (lihat atas).
     */
    public static function disk(): string
    {
        return ImporWorkbook::diskBerkas();
    }

    /** Disk berdriver `local` (punya jalur filesystem; `touch`/sapu umur berlaku). */
    public static function diskLokal(): bool
    {
        return config('filesystems.disks.'.self::disk().'.driver') === 'local';
    }

    /** Berkas asli workbook ini bersandi pemilik (paket OLE)? Dibaca dari 8 byte pertama di disknya. */
    public static function berkasBersandi(WorkbookMaster $wb): bool
    {
        $h = Storage::disk((string) $wb->berkas_disk)->readStream((string) $wb->berkas_path);

        if (! is_resource($h)) {
            return false;
        }

        try {
            return (string) fread($h, 8) === PembacaXlsmWorkbook::MAGIC_OLE;
        } finally {
            fclose($h);
        }
    }

    public const FOLDER = 'studio-hitung';

    /** @var array<string, array{0: ModelHitung, 1: GrafDependensi}> memo seproses */
    private static array $memo = [];

    public function __construct(private readonly PembacaXlsmWorkbook $pembaca) {}

    public static function jalur(string $sha256): string
    {
        return self::FOLDER.'/'.$sha256.'-m'.ModelHitung::VERSI.'.bin';
    }

    /**
     * @return array{0: ModelHitung, 1: GrafDependensi}
     *
     * @throws GagalHitung
     */
    public function ambil(WorkbookMaster $wb): array
    {
        if ($wb->sumber !== WorkbookMaster::SUMBER_XLSM || ! $wb->berkasAsliTersimpan()) {
            throw GagalHitung::berkasAsliTidakAda();
        }

        $ada = $this->baca($wb->sha256);

        if ($ada !== null) {
            return $ada;
        }

        return $this->simpan($wb->sha256, $this->muatDariBerkasAsli($wb, null));
    }

    /**
     * Bangun ulang dari berkas asli (perintah `studio:siapkan-hitung`); sandi
     * hanya untuk berkas bersandi pemilik.
     *
     * @return array{0: ModelHitung, 1: GrafDependensi}
     */
    public function siapkan(WorkbookMaster $wb, #[SensitiveParameter] ?string $sandi): array
    {
        if ($wb->sumber !== WorkbookMaster::SUMBER_XLSM || ! $wb->berkasAsliTersimpan()) {
            throw GagalHitung::berkasAsliTidakAda();
        }

        return $this->simpan($wb->sha256, $this->muatDariBerkasAsli($wb, $sandi));
    }

    /** @return array{0: ModelHitung, 1: GrafDependensi} */
    public function simpan(string $sha256, ModelHitung $model): array
    {
        $graf = GrafDependensi::bangun($model);
        $muatan = serialize(['model' => $model->keLarik(), 'graf' => $graf->keLarik()]);
        Storage::disk(self::disk())->put(self::jalur($sha256), Crypt::encryptString((string) gzdeflate($muatan, 6)));

        return self::$memo[$sha256] = [$model, $graf];
    }

    /** @return array{0: ModelHitung, 1: GrafDependensi}|null */
    public function baca(string $sha256): ?array
    {
        if (isset(self::$memo[$sha256])) {
            return self::$memo[$sha256];
        }

        $disk = Storage::disk(self::disk());
        $jalur = self::jalur($sha256);

        if (! $disk->exists($jalur)) {
            return null;
        }

        try {
            $muatan = gzinflate(Crypt::decryptString((string) $disk->get($jalur)));
            $larik = is_string($muatan) ? unserialize($muatan, ['allowed_classes' => false]) : null;
        } catch (\Throwable) {
            $larik = null;
        }

        $model = is_array($larik) && is_array($larik['model'] ?? null) ? ModelHitung::dariLarik($larik['model']) : null;

        if ($model === null || ! is_array($larik['graf'] ?? null)) {
            // Rusak / kunci aplikasi berganti / versi lama: dibuang, dibangun ulang.
            $disk->delete($jalur);

            return null;
        }

        if (self::diskLokal()) {
            @touch($disk->path($jalur));
        }

        return self::$memo[$sha256] = [$model, GrafDependensi::dariLarik($larik['graf'])];
    }

    /** Lupakan memo seproses (test & perintah yang membangun ulang). */
    public static function lupakan(): void
    {
        self::$memo = [];
    }

    private function muatDariBerkasAsli(WorkbookMaster $wb, #[SensitiveParameter] ?string $sandi): ModelHitung
    {
        $disk = Storage::disk((string) $wb->berkas_disk);
        $jalur = (string) $wb->berkas_path;

        if (! $disk->exists($jalur)) {
            throw GagalHitung::berkasAsliTidakAda();
        }

        if (str_ends_with($jalur, ImporWorkbook::AKHIRAN_TERENKRIPSI_APLIKASI)) {
            return $this->pembaca->denganTempPrivat(function (string $dir) use ($disk, $jalur): ModelHitung {
                $polos = $dir.DIRECTORY_SEPARATOR.'asli.zip';

                if (file_put_contents($polos, Crypt::decryptString((string) $disk->get($jalur))) === false) {
                    throw new RuntimeException('Temp berkas asli tidak bisa ditulis.');
                }

                return $this->pembaca->muatModelHitung($polos, '');
            });
        }

        if (self::berkasBersandi($wb) && ($sandi === null || $sandi === '')) {
            throw GagalHitung::perluSandi();
        }

        // Disk awet (R2) tidak punya jalur lokal: salin dulu ke temp privat
        // yang dihapus di `finally` (pola `.enc` di atas).
        return $this->pembaca->denganTempPrivat(function (string $dir) use ($disk, $jalur, $sandi): ModelHitung {
            $lokal = $dir.DIRECTORY_SEPARATOR.'asli.bin';
            $sumber = $disk->readStream($jalur);
            $tujuan = fopen($lokal, 'wb');

            if (! is_resource($sumber) || $tujuan === false) {
                throw new RuntimeException('Temp berkas asli tidak bisa ditulis.');
            }

            try {
                stream_copy_to_stream($sumber, $tujuan);
            } finally {
                fclose($sumber);
                fclose($tujuan);
            }

            return $this->pembaca->muatModelHitung($lokal, (string) $sandi);
        });
    }
}
