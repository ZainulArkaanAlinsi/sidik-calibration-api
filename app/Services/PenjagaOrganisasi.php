<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Satu tempat yang memutuskan "baris ini milik lab pemanggil atau bukan".
 *
 * ## Kenapa ini ada, padahal `abort_if()` satu baris sudah jalan
 *
 * Hari ini pemeriksaannya diulang di tujuh controller dengan bentuk yang sama:
 *
 *     abort_if($x->organization_id !== $request->user()->organization_id, 404);
 *
 * Yang bekerja benar. Yang **tidak** ada di sana: jejaknya. Percobaan lab A
 * membuka sertifikat lab B hari ini menghasilkan 404 yang tidak meninggalkan
 * apa pun — tidak di `audit_logs`, tidak di log aplikasi. Jadi pertanyaan
 * "pernah ada yang mencoba?" tidak punya jawaban, dan itu pertanyaan yang
 * ditanyakan asesor waktu menilai kerahasiaan antar pelanggan (ISO/IEC 17025
 * klausul 4.2).
 *
 * Alasan kedua: tujuh salinan berarti yang kedelapan bisa lupa ditulis, dan
 * yang lupa **tidak memunculkan error** — dia cuma memulangkan data lab lain
 * dengan HTTP 200. Bentuk kegagalan yang sama dengan yang ditutup
 * `LembarKerjaTidakBocorLintasLabTest`.
 *
 * ## Kenapa 404, bukan 403
 *
 * 403 berarti "ada, tapi bukan punyamu" — dan itu **mengonfirmasi keberadaan
 * barisnya**. Dengan menyapu id 1..5000 lewat 403/404, lab A bisa menghitung
 * berapa sertifikat yang diterbitkan lab B bulan ini tanpa pernah membaca satu
 * pun isinya. Untuk lab yang pelanggannya saling bersaing, jumlah itu sendiri
 * informasi.
 *
 * 404 tidak membedakan "tidak ada" dari "bukan punyamu", jadi tidak ada yang
 * bisa dihitung. Pola ini yang sudah dipakai tujuh controller itu — kelas ini
 * mempertahankannya, bukan menggantinya.
 */
class PenjagaOrganisasi
{
    /**
     * Pastikan `$baris` milik lab pemanggil; kalau bukan, catat lalu 404.
     *
     * Pengganti langsung untuk `pastikanSatuOrganisasi()` privat di controller.
     * Perilakunya identik dari luar — yang ditambahkan cuma jejaknya.
     */
    public static function pastikanSatu(Request $request, Model $baris): void
    {
        $milikPemanggil = $request->user()?->organization_id;
        $milikBaris = $baris->getAttribute('organization_id');

        if ($milikBaris === $milikPemanggil) {
            return;
        }

        self::catat($request, $baris->getTable(), (int) $baris->getKey(), $milikBaris);

        abort(404);
    }

    /**
     * Catat percobaan lintas lab.
     *
     * Dipisah dari `pastikanSatu()` supaya middleware jaring pengaman
     * (`CatatAksesLintasOrganisasi`) bisa memakainya juga, dan supaya cuma ada
     * satu bentuk baris untuk kejadian ini di seluruh sistem.
     *
     * ## Barisnya ditulis untuk lab PEMANGGIL, bukan lab pemilik data
     *
     * Ini keputusan yang gampang salah dan akibatnya terbalik. Kalau dicatat di
     * `audit_logs` lab pemilik, maka lab B melihat baris "seseorang dari lab
     * lain mencoba membuka sertifikat 412" — dan riwayat lab B jadi berisi
     * keberadaan lab A. Itu kebocoran baru, dibuat oleh alat yang seharusnya
     * mencegah kebocoran.
     *
     * Dicatat di lab pemanggil: yang terbaca adalah "akun ini mencoba membuka
     * data di luar lab-nya", tanpa menyebut lab mana. Itu yang berguna buat
     * yang menyelidiki, dan tidak membocorkan apa pun ke arah mana pun.
     */
    public static function catat(Request $request, string $tabel, ?int $id, mixed $organisasiPemilik = null): void
    {
        $pemanggil = $request->user();

        if ($pemanggil === null) {
            return;
        }

        // Tanda buat `CatatAksesLintasOrganisasi`: kejadian ini SUDAH tercatat,
        // jangan dicatat lagi dari sisi respons. Riwayat yang menggandakan
        // kejadian bikin orang menghitung salah waktu menyelidiki.
        $request->attributes->set('lintas_lab_tercatat', true);

        try {
            AuditLog::create([
                'organization_id' => $pemanggil->organization_id,
                'entity_type' => $tabel,
                'entity_id' => $id,
                'action' => AuditLog::ACTION_AKSES_LINTAS_LAB,
                'old_data' => null,
                // Path & method dicatat, `organization_id` pemiliknya TIDAK —
                // lihat penjelasan di atas. Yang cukup buat menyelidiki: siapa,
                // kapan, ke endpoint apa, id berapa.
                'new_data' => [
                    'method' => $request->getMethod(),
                    'path' => $request->path(),
                    'role' => $pemanggil->role,
                    'ip' => $request->ip(),
                ],
                'changed_by' => $pemanggil->id,
                'note' => 'Percobaan akses data di luar lab sendiri — ditolak 404.',
            ]);
        } catch (\Throwable $e) {
            // Gagal mencatat TIDAK BOLEH mengubah hasil pemeriksaan izinnya.
            // Kalau `audit_logs` penuh atau tabelnya terkunci, yang harus tetap
            // terjadi adalah 404 — bukan 500 yang, di beberapa penangan galat,
            // membocorkan pesan berisi nama tabel & id yang barusan diperiksa.
            report($e);
            Log::warning('Gagal mencatat percobaan akses lintas lab.', [
                'tabel' => $tabel,
                'id' => $id,
                'user_id' => $pemanggil->id,
            ]);
        }
    }
}
