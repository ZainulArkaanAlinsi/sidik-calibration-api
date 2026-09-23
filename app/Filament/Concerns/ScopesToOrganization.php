<?php

namespace App\Filament\Concerns;

use App\Models\User;
use App\Support\JejakLintasOrganisasi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Batasi resource cuma ke data organisasi si admin yang login.
 *
 * Instalasi ini satu-PT, tapi scoping-nya tetap dipasang sebagai jaring pengaman
 * yang sama kayak di API — biar kalau nanti jadi multi-PT, admin nggak keburu
 * kebiasa lihat data PT lain.
 */
trait ScopesToOrganization
{
    /**
     * Disediain `Resource` bawaan Filament. Dideklarasi ulang di sini biar
     * ketergantungan trait ini kelihatan — tanpa itu, `static::getModel()` di
     * bawah cuma "kebetulan ada" dan analyzer nggak bisa mastiin trait-nya
     * dipasang di kelas yang bener.
     *
     * @return class-string<Model>
     */
    abstract public static function getModel(): string;

    public static function getEloquentQuery(): Builder
    {
        $kueri = parent::getEloquentQuery();

        // Super admin membaca menembus organisasi — dan tiap layarnya dicatat.
        //
        // AGENTS.md §Peran butir 2 memberinya bacaan tanpa batas; §35
        // `docs/permintaan-user-7.md` menerima itu DENGAN syarat aksesnya
        // tercatat, karena yang ditembus kerahasiaan antar pelanggan (ISO/IEC
        // 17025 klausul 4.2). Dua-duanya ditulis di percabangan yang sama supaya
        // nggak pernah ada versi yang membuka tanpa mencatat.
        //
        // Dibuka cuma di panel, dan itu disengaja. Sisi API menyaring organisasi
        // di ~60 tempat tulis tangan; melebarkan semuanya demi lab kedua yang
        // BELUM ADA (produksi 23 Sep 2026: satu organisasi) menukar risiko nyata
        // dengan manfaat nol. Kalau lab kedua mendarat, yang benar memindahkan
        // penyaring API ke satu tempat dulu — refactor tersendiri.
        if (User::yangLogin()?->isSuperAdmin()) {
            JejakLintasOrganisasi::catat(class_basename(static::getModel()));

            return $kueri;
        }

        return $kueri
            ->where(static::getModel()::make()->getTable().'.organization_id', User::yangLogin()?->organization_id);
    }

    /*
    |---------------------------------------------------------------------------
    | Super admin: lihat semua, ubah nol
    |---------------------------------------------------------------------------
    |
    | Fase 2 membuka panel buat `super_admin` (`User::canAccessPanel()`), dan
    | tanpa blok di bawah yang ikut terbuka bukan cuma bacaan: tombol `approve`
    | di tabel sesi MENERBITKAN sertifikat berlogo akreditasi. K4 — siapa yang
    | boleh mengesahkan — belum dijawab manajer teknis, jadi peran yang baru
    | dibuka nggak boleh dapat wewenang itu lewat pintu samping.
    |
    | Ditaruh di trait INI, bukan trait baru, karena kesepuluh resource sudah
    | memakainya. Trait terpisah harus dipasang satu per satu, dan resource ke-11
    | yang lupa memasangnya nggak memunculkan error — cuma satu layar yang
    | diam-diam bisa ditulis. Sumbunya memang beda (yang satu menyaring baris,
    | yang satu menyaring aksi), tapi pertanyaannya sama: siapa boleh apa.
    |
    | `parent::` tetap dipanggil, jadi buat role lain perilakunya persis seperti
    | sebelum ini — policy/Gate yang nanti ditambah tetap berlaku.
    |
    | Dijaga `SuperAdminPanelBacaSajaTest`.
    */

    public static function canCreate(): bool
    {
        return ! self::superAdminYangLogin() && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return ! self::superAdminYangLogin() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! self::superAdminYangLogin() && parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return ! self::superAdminYangLogin() && parent::canDeleteAny();
    }

    public static function canForceDelete(Model $record): bool
    {
        return ! self::superAdminYangLogin() && parent::canForceDelete($record);
    }

    public static function canForceDeleteAny(): bool
    {
        return ! self::superAdminYangLogin() && parent::canForceDeleteAny();
    }

    public static function canRestore(Model $record): bool
    {
        return ! self::superAdminYangLogin() && parent::canRestore($record);
    }

    public static function canRestoreAny(): bool
    {
        return ! self::superAdminYangLogin() && parent::canRestoreAny();
    }

    public static function canReplicate(Model $record): bool
    {
        return ! self::superAdminYangLogin() && parent::canReplicate($record);
    }

    public static function canReorder(): bool
    {
        return ! self::superAdminYangLogin() && parent::canReorder();
    }

    private static function superAdminYangLogin(): bool
    {
        return ! HakTulisPanel::boleh();
    }
}
