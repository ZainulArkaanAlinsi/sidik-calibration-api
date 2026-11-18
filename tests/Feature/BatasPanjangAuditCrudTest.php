<?php

namespace Tests\Feature;

use App\Http\Requests\KategoriRequest;
use App\Models\AuditLog;
use App\Support\CatatanAlasan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Batas panjang paket CRUD 18 Nov 2026 dibuktikan terhadap kolom yang SEBENARNYA.
 *
 * ## Kenapa berkas ini ada
 *
 * 18 Nov 2026 CI MySQL menangkap bug yang SQLite loloskan: `alasan` 500
 * karakter + awalan melebihi `audit_logs.note` `string(500)` dan MySQL strict
 * mode menjawab 500 `Data too long`. SQLite tidak pernah memeriksa panjang
 * `varchar`, jadi test Feature biasa tidak bisa menangkapnya. Di sini panjang
 * kolom DIBACA DARI BERKAS MIGRASI (satu-satunya sumber yang sama untuk SQLite
 * dan MySQL) dan setiap string yang paket ini tulis ditagih terhadapnya.
 *
 * Test Feature tiap fitur menambah sisi lainnya: alasan 400 karakter dengan
 * huruf 2, 3, dan 4 byte benar-benar dikirim lewat HTTP dan `note`-nya
 * ditagih (`ArsipDrafSesiTest`, `PenugasanUbahBatalTest`, `KategoriKelolaTest`).
 * Gerbang MySQL CI tetap yang menutup celah terakhir.
 */
class BatasPanjangAuditCrudTest extends TestCase
{
    /** Panjang `string('<kolom>', N)` (atau 255 bila tanpa angka) dari satu migrasi. */
    private function panjangKolom(string $migrasi, string $kolom): int
    {
        $sumber = (string) file_get_contents(database_path('migrations/'.$migrasi));

        $this->assertSame(
            1,
            preg_match("/string\\('{$kolom}'(?:,\\s*(\\d+))?\\)/", $sumber, $m),
            "Kolom `{$kolom}` tidak ditemukan sebagai string() di {$migrasi}",
        );

        return isset($m[1]) && $m[1] !== '' ? (int) $m[1] : 255;
    }

    public function test_panjang_note_di_kode_sama_dengan_migrasi_dan_tidak_pernah_dilebarkan_migrasi_lain(): void
    {
        $this->assertSame(
            $this->panjangKolom('2026_07_27_100000_create_audit_logs_table.php', 'note'),
            CatatanAlasan::PANJANG_NOTE,
            'CatatanAlasan::PANJANG_NOTE harus cermin audit_logs.note.',
        );
        $this->assertSame(
            $this->panjangKolom('2026_07_27_100000_create_audit_logs_table.php', 'note'),
            AuditLog::NOTE_MAKS,
            'AuditLog::NOTE_MAKS (pemotong AuditLog::catatan) harus cermin audit_logs.note.',
        );

        // Migrasi lain yang menyentuh audit_logs.note akan mengubah kenyataannya
        // tanpa test ini tahu -- paksa orangnya membaca ulang batas di sini.
        foreach (glob(database_path('migrations/*.php')) ?: [] as $berkas) {
            if (str_ends_with($berkas, '2026_07_27_100000_create_audit_logs_table.php')) {
                continue;
            }

            $isi = (string) file_get_contents($berkas);

            $this->assertFalse(
                str_contains($isi, "'audit_logs'") && str_contains($isi, "'note'"),
                basename($berkas).' menyentuh audit_logs.note; periksa ulang CatatanAlasan::PANJANG_NOTE.',
            );
        }
    }

    public function test_alasan_maks_ditambah_awalan_terpanjang_muat_di_note(): void
    {
        $awalan = collect((new ReflectionClass(CatatanAlasan::class))->getConstants())
            ->filter(fn ($nilai, string $nama): bool => str_starts_with($nama, 'AWALAN_'));

        $this->assertGreaterThanOrEqual(5, $awalan->count(), 'daftar awalan menyusut diam-diam');

        foreach ($awalan as $nama => $teks) {
            $terburuk = $teks.': '.str_repeat('漢', CatatanAlasan::ALASAN_MAKS);

            $this->assertLessThanOrEqual(
                CatatanAlasan::PANJANG_NOTE,
                mb_strlen($terburuk),
                "{$nama} + alasan maksimum melewati audit_logs.note",
            );
            $this->assertSame($terburuk, CatatanAlasan::note($teks, str_repeat('漢', CatatanAlasan::ALASAN_MAKS)), 'jalur normal tidak memotong');
        }

        $this->assertContains('max:'.CatatanAlasan::ALASAN_MAKS, CatatanAlasan::aturan());
        $this->assertContains('min:'.CatatanAlasan::ALASAN_MIN, CatatanAlasan::aturan(wajib: false));
    }

    public function test_jaring_pengaman_memotong_bukan_meledak_kalau_validasi_terlewat(): void
    {
        $note = CatatanAlasan::note(CatatanAlasan::AWALAN_ARSIPKAN, str_repeat('😀', 900));

        $this->assertSame(CatatanAlasan::PANJANG_NOTE, mb_strlen($note));
        $this->assertStringStartsWith('Arsipkan draf: ', $note);
        $this->assertStringEndsWith('…', $note, 'dipotong oleh AuditLog::catatan() dan ditutup elipsis');
    }

    public function test_nama_aksi_audit_muat_di_kolom_action(): void
    {
        $batas = $this->panjangKolom('2026_07_27_100000_create_audit_logs_table.php', 'action');
        $this->assertSame(20, $batas);

        $aksi = collect((new ReflectionClass(AuditLog::class))->getConstants())
            ->filter(fn ($nilai, string $nama): bool => str_starts_with($nama, 'ACTION_') && is_string($nilai));

        $this->assertGreaterThanOrEqual(12, $aksi->count());

        foreach ($aksi as $nama => $nilai) {
            $this->assertLessThanOrEqual($batas, mb_strlen($nilai), "{$nama} ('{$nilai}') melewati audit_logs.action");
        }

        // Aksi baru paket ini bisa dipakai sebagai filter `GET /audit-logs?action=`.
        foreach (['diarsipkan', 'dinonaktifkan', 'diaktifkan', 'dibatalkan', 'dipulihkan'] as $baru) {
            $this->assertContains($baru, AuditLog::actions());
        }
    }

    public function test_nama_tabel_entitas_yang_dicatat_muat_di_entity_type(): void
    {
        $batas = $this->panjangKolom('2026_07_27_100000_create_audit_logs_table.php', 'entity_type');

        foreach (['calibration_sessions', 'equipment_categories', 'penugasan', 'penugasan_item'] as $tabel) {
            $this->assertLessThanOrEqual($batas, strlen($tabel));
        }
    }

    public function test_batas_validasi_tidak_melebihi_kolom_yang_menyimpannya(): void
    {
        $this->assertLessThanOrEqual(
            $this->panjangKolom('2026_07_14_120200_create_equipment_categories_table.php', 'nama'),
            KategoriRequest::NAMA_MAKS,
        );
        // `kode` = slug dari nama, dipotong 100 di controller.
        $this->assertLessThanOrEqual(
            $this->panjangKolom('2026_07_14_120200_create_equipment_categories_table.php', 'kode') - 10,
            100,
        );
        // Aturan di PenugasanRequest/PenugasanUbahRequest: judul 150, jenis_alat 120.
        $this->assertLessThanOrEqual($this->panjangKolom('2026_09_27_120000_create_penugasan_table.php', 'judul'), 150);
        $this->assertLessThanOrEqual($this->panjangKolom('2026_09_27_120200_create_penugasan_item_table.php', 'jenis_alat'), 120);
    }
}
