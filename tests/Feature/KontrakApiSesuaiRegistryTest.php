<?php

namespace Tests\Feature;

use App\Services\Calibration\CalibrationProfileRegistry;
use Tests\TestCase;

/**
 * Angka di `docs/kontrak-api.md` diadu ke registry, bukan ke ingatan.
 *
 * ## Kenapa berkas ini ada
 *
 * `docs/kontrak-api.md` ada di DUA repo dan pernah bercabang diam-diam selama
 * sebulan lebih. Salinan mobile punya banner 39 baris yang mengakui riwayat itu
 * dan menyuruh pembaca menjalankan `diff` antar-repo. Perintah itu dijalankan
 * waktu audit; hasilnya **tidak kosong**.
 *
 * Yang lebih buruk dari dua salinan yang beda: satu salinan yang **berbeda dari
 * dirinya sendiri**. Salinan backend menulis "15 dari 20 profil" di dua tempat,
 * sementara seratus baris di bawahnya ada section penuh "Lembar Timbangan —
 * alat ke-21". Dua pernyataan itu tidak bisa benar bersamaan.
 *
 * Registry-nya yang jadi wasit, dan jawabannya **24 profil, 19 tanpa
 * toleransi** — angka yang dipegang salinan MOBILE. Jadi aturan "salinan API
 * yang menang" di banner itu konvensi buat menyelesaikan drift, bukan jaminan
 * kebenaran: di titik ini yang basi justru salinan yang menang.
 *
 * ## Kenapa test, bukan sekadar dibetulkan
 *
 * Banner itu meminta MANUSIA ingat menjalankan `diff` tiap kali. Itu penjagaan
 * yang sama kelasnya dengan yang sudah gagal sekali di berkas ini, dan dengan
 * konvensi yang gagal di BUG-005. Test tidak lupa.
 *
 * Yang dijaga di sini terbatas dan sengaja: **angka yang bisa dihitung dari
 * kode**. Isi prosa dokumennya tidak, dan tidak seharusnya — dokumen yang
 * dipaksa cocok kata-per-kata dengan kode berhenti bisa menjelaskan apa pun.
 */
class KontrakApiSesuaiRegistryTest extends TestCase
{
    private function kontrak(): string
    {
        return file_get_contents(base_path('docs/kontrak-api.md'));
    }

    /**
     * Jumlah profil yang disebut dokumen harus sama dengan yang benar-benar
     * terdaftar.
     */
    public function test_jumlah_profil_di_dokumen_sama_dengan_registry(): void
    {
        $registry = app(CalibrationProfileRegistry::class);
        $total = count($registry->semua());

        $this->assertGreaterThan(0, $total, 'Registry profilnya kosong — testnya yang salah, bukan dokumennya.');

        $isi = $this->kontrak();

        // Pola "N dari M" muncul di dua tempat, dan dua-duanya harus menyebut
        // total yang sama. Dulu dua-duanya menyebut 20 sementara registry-nya
        // sudah 24.
        preg_match_all('/(\d+) dari (\d+)\*{0,2} profil/', $isi, $cocok, PREG_SET_ORDER);

        $this->assertNotEmpty($cocok, 'Pola "N dari M profil" nggak ketemu di kontrak — polanya berubah?');

        foreach ($cocok as $satu) {
            $this->assertSame(
                $total,
                (int) $satu[2],
                "Kontrak nulis \"{$satu[0]}\" padahal registry punya {$total} profil.",
            );
        }
    }

    /**
     * Dan jumlah yang TANPA toleransi juga — itu angka yang dipakai frontend
     * buat memutuskan kolom `toleransi` wajib diisi atau tidak. Salah di sini
     * bikin form Alat mewajibkan kolom yang tidak punya isi yang benar, dan
     * teknisi mengarang angkanya.
     */
    public function test_jumlah_profil_tanpa_toleransi_sama_dengan_registry(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        $tanpaToleransi = collect($registry->semua())
            ->reject(fn ($profil): bool => $profil->punyaToleransi())
            ->count();

        preg_match_all('/(\d+) dari (\d+)\*{0,2} profil/', $this->kontrak(), $cocok, PREG_SET_ORDER);

        $this->assertNotEmpty($cocok);

        foreach ($cocok as $satu) {
            $this->assertSame(
                $tanpaToleransi,
                (int) $satu[1],
                "Kontrak nulis \"{$satu[0]}\" padahal cuma {$tanpaToleransi} profil yang tanpa toleransi.",
            );
        }
    }

    /**
     * Dokumen tidak boleh berbeda dari dirinya sendiri.
     *
     * Ini yang paling murah dan paling sering bocor: satu angka diperbarui di
     * satu tempat dan tidak di tempat lainnya, dan tidak ada yang menyadarinya
     * sampai ada yang membaca dua-duanya dalam satu duduk.
     */
    public function test_semua_penyebutan_angkanya_konsisten(): void
    {
        preg_match_all('/(\d+) dari (\d+)\*{0,2} profil/', $this->kontrak(), $cocok, PREG_SET_ORDER);

        $unik = collect($cocok)->map(fn (array $s): string => $s[1].'/'.$s[2])->unique()->values();

        $this->assertCount(
            1,
            $unik,
            'Kontrak nyebut angka profil yang beda-beda di dalam berkas yang sama: '.$unik->implode(', '),
        );
    }
}
