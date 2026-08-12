<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Nyatet tiap perubahan model ke `audit_logs` (Keputusan 4).
 *
 * Lewat **model event**, bukan ditempel manual di tiap controller. Alasannya satu
 * dan menentukan: kalau dipasang per endpoint, endpoint yang lupa dipasangin nggak
 * keliatan sebagai error — cuma sebagai perubahan yang nggak kecatat. Dan yang
 * paling sering lupa itu jalur yang jarang dipakai, yang justru paling menarik buat
 * asesor. Lewat event, semua jalan yang nyentuh model ini kecatat: API, panel
 * Filament, command artisan, job di queue.
 *
 * Sengaja NGGAK ditelan exception-nya. Kalau nyatet audit gagal, perubahannya ikut
 * gagal. Buat lab terakreditasi, perubahan yang nggak kecatat lebih berbahaya
 * daripada perubahan yang gagal — yang gagal keliatan dan bisa diulang, yang nggak
 * kecatat baru ketahuan waktu audit dan udah nggak bisa direkonstruksi.
 *
 * Trait ini CUMA sah dipasang di Eloquent model — `bootDiaudit()` manggil
 * pendaftar event yang datang dari `HasEvents`. Dideklarasi sebagai `@method`,
 * bukan `@mixin`: analyzer nggak ngikutin `@mixin` buat panggilan `static::`
 * dari dalam trait, jadi `@mixin` di sini cuma jadi hiasan yang nggak ngefek.
 *
 * `restored()` datang dari `SoftDeletes`, bukan `HasEvents` — makanya
 * pemanggilannya dipagerin `class_uses_recursive()` di bawah.
 *
 * @method static void created(callable $callback)
 * @method static void updated(callable $callback)
 * @method static void deleted(callable $callback)
 * @method static void restored(callable $callback)
 */
trait Diaudit
{
    /**
     * Sakelar buat mematikan pencatatan.
     *
     * Dipakai seeder & pabrik data: nyatet 151 baris rentang kemampuan sebagai
     * "perubahan oleh entah siapa" cuma nyampahin riwayat yang harusnya kebaca
     * manusia. BUKAN buat dipakai di jalur produksi.
     */
    protected static bool $auditAktif = true;

    public static function tanpaAudit(callable $aksi): mixed
    {
        $sebelumnya = static::$auditAktif;
        static::$auditAktif = false;

        try {
            return $aksi();
        } finally {
            static::$auditAktif = $sebelumnya;
        }
    }

    public static function matikanAudit(): void
    {
        static::$auditAktif = false;
    }

    public static function nyalakanAudit(): void
    {
        static::$auditAktif = true;
    }

    /**
     * Kolom yang isinya NGGAK boleh masuk riwayat.
     *
     * Bukan cuma soal rapi: `password` itu hash, dan hash yang kesimpen di tabel
     * lain berarti satu kebocoran jadi dua. Nilainya diganti penanda, tapi NAMA
     * kolomnya tetap kecatat — jadi masih kelihatan "password diubah", tanpa
     * nyimpen isinya.
     *
     * @return list<string>
     */
    protected function kolomRahasia(): array
    {
        return ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];
    }

    /**
     * Kolom yang nggak menarik buat riwayat.
     *
     * `updated_at` berubah di SETIAP update, jadi kalau ikut, tiap baris riwayat
     * punya minimal satu "perubahan" walaupun nggak ada yang beneran diubah.
     *
     * @return list<string>
     */
    protected function kolomDilewat(): array
    {
        return ['updated_at', 'created_at'];
    }

    public static function bootDiaudit(): void
    {
        static::created(function (Model $model): void {
            $model->catatAudit(AuditLog::ACTION_DIBIKIN, null, $model->nilaiAudit($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            $berubah = $model->perubahanAudit();

            // Nggak ada yang beneran berubah (mis. cuma `touch()`) → nggak nyatet.
            // Baris riwayat kosong bikin daftarnya panjang tanpa nambah informasi.
            if ($berubah['baru'] === []) {
                return;
            }

            $model->catatAudit(AuditLog::ACTION_DIUBAH, $berubah['lama'], $berubah['baru']);
        });

        static::deleted(function (Model $model): void {
            // Soft delete itu `deleted`, bukan `updated` — dan yang kecatat cuma
            // kolom penanda hapusnya, bukan seluruh baris. Isinya masih ada di DB
            // dan masih bisa dilihat dari riwayat `dibikin`/`diubah` sebelumnya.
            $model->catatAudit(AuditLog::ACTION_DIHAPUS, null, null);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (Model $model): void {
                $model->catatAudit(AuditLog::ACTION_DIPULIHKAN, null, null);
            });
        }
    }

    /**
     * @param  array<string, mixed>|null  $lama
     * @param  array<string, mixed>|null  $baru
     */
    public function catatAudit(string $aksi, ?array $lama, ?array $baru, ?string $catatan = null): void
    {
        if (! static::$auditAktif) {
            return;
        }

        $organizationId = $this->organisasiUntukAudit();

        // Tanpa organisasi, barisnya nggak bisa di-scope dan bakal kelihatan di
        // riwayat lab lain. Lebih baik nggak dicatat daripada dicatat di tempat
        // yang salah — dan model yang kena ini cuma `Organization` sendiri, yang
        // di-handle di `organisasiUntukAudit()`.
        if ($organizationId === null) {
            return;
        }

        AuditLog::create([
            'organization_id' => $organizationId,
            'entity_type' => $this->getTable(),
            'entity_id' => $this->getKey(),
            'action' => $aksi,
            'old_data' => $lama,
            'new_data' => $baru,
            // `auth()->id()` null di queue & command — itu wajar, dan dibiarin null
            // lebih jujur daripada dituduhin ke user acak.
            'changed_by' => Auth::id(),
            'note' => $catatan,
        ]);
    }

    /**
     * Organisasi pemilik barisnya.
     *
     * `Organization` sendiri nggak punya kolom `organization_id` — buat dia,
     * pemiliknya ya dirinya sendiri.
     */
    protected function organisasiUntukAudit(): ?int
    {
        if ($this->getTable() === 'organizations') {
            return $this->getKey();
        }

        $nilai = $this->getAttribute('organization_id');

        return $nilai === null ? null : (int) $nilai;
    }

    /**
     * Kolom yang berubah: nilai lama & nilai baru, udah disaring & disensor.
     *
     * @return array{lama: array<string, mixed>, baru: array<string, mixed>}
     */
    protected function perubahanAudit(): array
    {
        $baru = $this->nilaiAudit($this->getChanges());
        $lama = [];

        foreach (array_keys($baru) as $kolom) {
            $lama[$kolom] = $this->sensor($kolom, $this->getOriginal($kolom));
        }

        return ['lama' => $lama, 'baru' => $baru];
    }

    /**
     * @param  array<string, mixed>  $atribut
     * @return array<string, mixed>
     */
    protected function nilaiAudit(array $atribut): array
    {
        $hasil = [];

        foreach ($atribut as $kolom => $nilai) {
            if (in_array($kolom, $this->kolomDilewat(), true)) {
                continue;
            }

            $hasil[$kolom] = $this->sensor($kolom, $nilai);
        }

        return $hasil;
    }

    protected function sensor(string $kolom, mixed $nilai): mixed
    {
        if ($nilai === null) {
            return null;
        }

        return in_array($kolom, $this->kolomRahasia(), true) ? '[disensor]' : $nilai;
    }
}
