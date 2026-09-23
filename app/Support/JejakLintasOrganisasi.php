<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;

/**
 * Catat tiap layar yang dibuka `super_admin` dengan lingkup lintas lab.
 *
 * ## Kenapa ini wajib, bukan pelengkap
 *
 * Super admin membaca menembus `organization_id`, dan itu menembus kerahasiaan
 * antar pelanggan yang diminta ISO/IEC 17025 klausul 4.2. Keputusan pemilik
 * proyek (§35 `docs/permintaan-user-7.md`) menerima penembusan itu DENGAN
 * syarat: tiap aksesnya tercatat. Tanpa catatan, lab tidak punya jawaban untuk
 * "siapa yang pernah melihat data PT B" — pertanyaan yang ditanyakan asesor,
 * bukan pertanyaan teoretis.
 *
 * ## Yang dicatat, dan kenapa tidak lebih
 *
 * Satu baris per LAYAR per request, bukan per baris data. Mencatat per baris
 * menghasilkan ribuan baris audit untuk satu kali buka tabel, dan jejak yang
 * terlalu berisik sama tidak terbacanya dengan jejak yang kosong.
 *
 * Dan tidak mencatat apa pun selama lab-nya cuma satu: kalau `organizations`
 * berisi satu baris, "lintas organisasi" belum ada artinya — yang terbaca super
 * admin persis sama dengan yang terbaca admin. Mencatatnya cuma menumpuk baris
 * yang tidak menjawab pertanyaan siapa pun. Begitu lab kedua mendarat,
 * pencatatannya menyala sendiri tanpa ada yang perlu mengaktifkan.
 */
final class JejakLintasOrganisasi
{
    /**
     * Layar yang sudah dicatat di request ini — pencegah baris kembar.
     *
     * Filament memanggil `getEloquentQuery()` beberapa kali dalam satu request
     * (tabel, lencana navigasi, hitungan). Tanpa penjaga ini satu kali buka
     * layar meninggalkan tiga sampai lima baris yang isinya sama persis.
     *
     * @var array<string, true>
     */
    private static array $sudah = [];

    public static function catat(string $layar): void
    {
        $user = User::yangLogin();

        if (! $user?->isSuperAdmin()) {
            return;
        }

        if (isset(self::$sudah[$layar])) {
            return;
        }

        // Cuma satu lab: tidak ada yang diseberangi, jadi tidak ada yang perlu
        // dicatat. Tabelnya kecil dan berindeks, jadi hitungan ini murah.
        if (Organization::query()->count() <= 1) {
            return;
        }

        self::$sudah[$layar] = true;

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'entity_type' => 'akses_lintas_organisasi',
            'entity_id' => $user->getKey(),
            'action' => AuditLog::ACTION_DIBACA,
            'new_data' => [
                'layar' => $layar,
                'organisasi_pembaca' => $user->organization_id,
            ],
            'changed_by' => $user->getKey(),
            'note' => "Super admin membuka {$layar} dengan lingkup seluruh organisasi.",
        ]);
    }

    /** Dipakai test: lupakan apa yang sudah dicatat di request ini. */
    public static function lupakan(): void
    {
        self::$sudah = [];
    }
}
