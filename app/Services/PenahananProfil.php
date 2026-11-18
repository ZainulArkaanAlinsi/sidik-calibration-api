<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Illuminate\Support\Facades\Log;

/**
 * Penahanan PENERBITAN sertifikat per jenis alat, menunggu keputusan Technical
 * Manager (keputusan pemilik 18 Nov 2026: sertifikat salah di lab terakreditasi
 * lebih berbahaya daripada sertifikat tertunda).
 *
 * ## Satu daftar, satu hook, tiga pintu
 *
 * - DAFTAR: `config/sertifikat.php` kunci `profil_ditahan` (`kode profil =>
 *   alasan, rujukan TM, varian`). Tidak ada daftar kedua; pelepasannya menghapus
 *   satu entri lewat PR kecil.
 * - HOOK: `CalibrationProfile::penahanTerbit()` — hook yang SUDAH dipakai profil
 *   Tekanan untuk penahanan dari log metode, dan yang sudah dibaca
 *   `CalibrationValidator` (ERROR `menunggu_keputusan_tm`). Kelas dasarnya kini
 *   membaca daftar di atas lewat [butirDariConfig]; kelas ini TIDAK menghitung
 *   penahanan sendiri, hanya menanyakannya ke hook itu. Bukan mekanisme kedua.
 * - PINTU, semuanya memanggil [periksa]: `CalibrationController::approve()`,
 *   tombol Setujui panel, dan job `GenerateCertificate`.
 *
 * ## Tujuh jenis alat, dua sumber
 *
 * Timbangan (varian kg), Proving Ring, Bath, dan Picnometer ada di config.
 * Tekanan (pressure_gauge, vacuum_gauge, differential_pressure) SUDAH ditahan
 * sebelum kelas ini lahir, lewat log metode (`TekananProfile::penahanTerbit()`):
 * bersyarat, hanya sesi yang memicu cacat master P-1/P-2/P-11 (SPMK tidak).
 * Mekanismenya dipakai ulang apa adanya; gerbang di sini hanya mengubah cara
 * penolakannya sampai ke pemakai (422 `profil_ditahan` yang jelas, sebelum
 * pemeriksaan lain) dan menambah jejak audit.
 *
 * ## Yang TIDAK ditahan
 *
 * Pengisian lembar, pratinjau, dan hitung. Angkanya tidak diubah sedikit pun
 * (aturan olah data butir 5); yang berubah hanya "boleh terbit atau belum".
 * Sertifikat yang sudah `terbit` dan sesi yang sudah `disetujui` tidak disentuh.
 */
class PenahananProfil
{
    /** Kode galat 422 dan nilai `penyimpangan` pada butir dari config. */
    public const KODE = 'profil_ditahan';

    public const PINTU_APPROVE_API = 'approve_api';

    public const PINTU_APPROVE_PANEL = 'approve_panel';

    public const PINTU_JOB_TERBIT = 'job_terbit';

    private const ALASAN_KOSONG = 'Alasan penahanan belum ditulis di config/sertifikat.php; hubungi pemilik proyek sebelum melepasnya.';

    /**
     * Daftar penahanan dari config, sudah dinormalkan. Entri yang rusak
     * (bukan array, alasan kosong, `varian` kosong) TETAP menahan: salah ketik di
     * sini tidak boleh diam-diam melepas penahanan.
     *
     * @return array<string, array{alasan: string, tm: ?string, varian: ?list<string>}>
     */
    public static function daftar(): array
    {
        $mentah = config('sertifikat.profil_ditahan', []);

        if (! is_array($mentah)) {
            return [];
        }

        $hasil = [];

        foreach ($mentah as $kode => $entri) {
            if (! is_string($kode) || trim($kode) === '') {
                continue;
            }

            $entri = is_array($entri) ? $entri : [];
            $alasan = trim((string) ($entri['alasan'] ?? ''));
            $tm = trim((string) ($entri['tm'] ?? ''));
            $varian = $entri['varian'] ?? null;
            $varian = is_string($varian) ? [$varian] : $varian;

            $hasil[trim($kode)] = [
                'alasan' => $alasan !== '' ? $alasan : self::ALASAN_KOSONG,
                'tm' => $tm !== '' ? $tm : null,
                'varian' => is_array($varian) && $varian !== []
                    ? array_values(array_map(static fn (mixed $v): string => strtolower(trim((string) $v)), $varian))
                    : null,
            ];
        }

        return $hasil;
    }

    /**
     * Butir penahanan dari config untuk satu profil — bentuk yang sama dengan
     * `CalibrationProfile::penahanTerbit()`.
     *
     * Entri ber-`varian` hanya menahan sesi bervarian itu. Varian yang tidak bisa
     * ditentukan (`null`) ikut ditahan: lebih baik tertahan daripada lolos.
     *
     * @return list<array{penyimpangan: string, pesan: string, konteks: array<string, mixed>}>
     */
    public static function butirDariConfig(string $kodeProfil, ?string $varian = null): array
    {
        $entri = self::daftar()[$kodeProfil] ?? null;

        if ($entri === null) {
            return [];
        }

        $varian = $varian === null ? null : strtolower(trim($varian));

        if ($entri['varian'] !== null && $varian !== null && ! in_array($varian, $entri['varian'], true)) {
            return [];
        }

        return [[
            'penyimpangan' => self::KODE,
            'pesan' => sprintf(
                'Ditahan menunggu keputusan Technical Manager%s: %s Sertifikat tidak terbit sampai butir itu dijawab; '
                .'lembar tetap bisa diisi dan dihitung. Daftar penahanan: config/sertifikat.php (`profil_ditahan`).',
                $entri['tm'] !== null ? " ({$entri['tm']})" : '',
                self::akhiriTitik($entri['alasan']),
            ),
            'konteks' => [
                'profil' => $kodeProfil,
                'varian' => $varian,
                'rujukan_tm' => $entri['tm'],
                'alasan' => $entri['alasan'],
                // Kunci yang sama dengan butir log metode Tekanan (`P-2`): perintah
                // `kalibrasi:sapu-sesi` membaca `konteks.pertanyaan` untuk baris DITAHAN.
                'pertanyaan' => $entri['tm'],
            ],
        ]];
    }

    /**
     * Vonis gerbang untuk satu sesi: `null` = tidak ada yang menahan.
     *
     * Ditanyakan ke hook profilnya, jadi mencakup daftar config DAN penahanan log
     * metode Tekanan. Tidak memeriksa status sesi: pemanggil yang menentukan
     * kapan gerbang ini relevan.
     *
     * @return array{kode: string, message: string, profil: string, varian: ?string, rujukan_tm: ?string, alasan: string, penahan: list<array{penyimpangan: string, pesan: string}>}|null
     */
    public function periksa(CalibrationSession $sesi, ?CalibrationProfile $profil = null): ?array
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return null;
        }

        $profil ??= app(CalibrationProfileRegistry::class)->untukAlat($alat);
        $butir = $profil->penahanTerbit($sesi);

        if ($butir === []) {
            return null;
        }

        $alasan = [];
        $rujukan = [];
        $varian = null;

        foreach ($butir as $b) {
            $konteks = (array) ($b['konteks'] ?? []);
            $alasan[] = self::akhiriTitik((string) ($konteks['alasan'] ?? self::ringkasHook($b)), false);
            $rujukan[] = (string) ($konteks['rujukan_tm'] ?? $konteks['pertanyaan'] ?? '');
            $varian ??= isset($konteks['varian']) ? (string) $konteks['varian'] : null;
        }

        $alasan = implode('; ', array_values(array_unique($alasan)));
        $rujukan = array_values(array_filter(array_unique($rujukan), static fn (string $r): bool => $r !== ''));

        return [
            'kode' => self::KODE,
            'message' => sprintf(
                'Penerbitan sertifikat jenis alat ini ditahan menunggu keputusan Technical Manager: %s. '
                .'Lembar tetap bisa diisi dan dihitung.',
                $alasan,
            ),
            'profil' => $profil->kode(),
            'varian' => $varian,
            'rujukan_tm' => $rujukan === [] ? null : implode(', ', $rujukan),
            'alasan' => $alasan,
            'penahan' => array_map(
                static fn (array $b): array => ['penyimpangan' => (string) $b['penyimpangan'], 'pesan' => (string) $b['pesan']],
                $butir,
            ),
        ];
    }

    /**
     * Ringkasan per sesi untuk klien (`penerbitan_ditahan` di `CalibrationResource`).
     * Sesi yang sertifikatnya sudah `terbit` tidak ditahan lagi: yang ditahan
     * penerbitan baru, bukan dokumen yang sudah di tangan pelanggan.
     *
     * @return array{ditahan: bool, alasan: ?string, rujukan_tm: ?string}
     */
    public function ringkasan(CalibrationSession $sesi, ?CalibrationProfile $profil = null): array
    {
        $tidak = ['ditahan' => false, 'alasan' => null, 'rujukan_tm' => null];

        if ($sesi->certificate?->status === Certificate::STATUS_TERBIT) {
            return $tidak;
        }

        $vonis = $this->periksa($sesi, $profil);

        return $vonis === null
            ? $tidak
            : ['ditahan' => true, 'alasan' => $vonis['alasan'], 'rujukan_tm' => $vonis['rujukan_tm']];
    }

    /**
     * Ringkasan per JENIS alat (tanpa sesi) untuk `GET /categories/{kode}`:
     * klien tahu sebelum teknisi mengirim. `varian` terisi bila hanya sebagian
     * varian yang ditahan (Timbangan: `["kg"]`); klien tidak boleh menyimpulkan
     * "seluruh jenis alat" dari `ditahan: true` kalau `varian` ada.
     *
     * @return array{ditahan: bool, alasan: ?string, rujukan_tm: ?string, varian: ?list<string>}
     */
    public static function ringkasanProfil(?string $kodeProfil): array
    {
        $entri = $kodeProfil === null ? null : (self::daftar()[$kodeProfil] ?? null);

        return $entri === null
            ? ['ditahan' => false, 'alasan' => null, 'rujukan_tm' => null, 'varian' => null]
            : ['ditahan' => true, 'alasan' => $entri['alasan'], 'rujukan_tm' => $entri['tm'], 'varian' => $entri['varian']];
    }

    /**
     * Catat percobaan menerbitkan yang DITOLAK. Entitasnya sesi; `changed_by` =
     * orang yang menekan (atau yang menyetujui, untuk job). Status sesi tidak
     * berubah, jadi baris `diubah` otomatis tidak ada — baris ini satu-satunya
     * bukti bahwa percobaan itu terjadi dan kenapa ditolak.
     *
     * Gagal menulis TIDAK boleh mengubah vonis (tetap ditolak): dicatat ke log.
     *
     * @param  array<string, mixed>  $vonis  keluaran [periksa]
     */
    public function catat(CalibrationSession $sesi, ?User $pelaku, array $vonis, string $pintu): void
    {
        try {
            AuditLog::create([
                'organization_id' => $sesi->organization_id,
                'entity_type' => $sesi->getTable(),
                'entity_id' => $sesi->getKey(),
                'action' => AuditLog::ACTION_PENERBITAN_DITAHAN,
                'old_data' => null,
                'new_data' => [
                    'pintu' => $pintu,
                    'kode' => $vonis['kode'],
                    'profil' => $vonis['profil'],
                    'varian' => $vonis['varian'],
                    'rujukan_tm' => $vonis['rujukan_tm'],
                    'alasan' => $vonis['alasan'],
                    'status_sesi' => $sesi->status,
                    'peran_pelaku' => $pelaku?->role,
                ],
                'changed_by' => $pelaku?->getKey(),
                'note' => AuditLog::catatan((string) $vonis['message']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Percobaan menerbitkan profil ditahan ditolak, tetapi jejak auditnya gagal ditulis.', [
                'calibration_session_id' => $sesi->getKey(),
                'pintu' => $pintu,
                'galat' => $e::class,
            ]);
        }
    }

    /**
     * Kalimat singkat untuk butir dari hook yang BUKAN dari config (penahanan log
     * metode Tekanan): pesan lengkapnya panjang dan memuat dua angka, jadi 422
     * cukup menyebut cacat dan pertanyaannya; angkanya ada di temuan validator.
     *
     * @param  array<string, mixed>  $butir
     */
    private static function ringkasHook(array $butir): string
    {
        $konteks = (array) ($butir['konteks'] ?? []);

        return sprintf(
            'cacat master %s (pertanyaan %s) mengubah angka sesi ini, dan kedua versi angkanya ada di temuan `menunggu_keputusan_tm` validator',
            $butir['penyimpangan'] ?? '?',
            $konteks['pertanyaan'] ?? '?',
        );
    }

    private static function akhiriTitik(string $teks, bool $denganTitik = true): string
    {
        $teks = rtrim(trim($teks), ". \t\n\r");

        return $denganTitik ? $teks.'.' : $teks;
    }
}
