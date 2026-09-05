<?php

namespace Tests\Unit;

use App\Models\CalibrationMethod;
use App\Services\CertificateSnapshotBuilder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Kode IK di sertifikat tidak ditentukan urutan baris database.
 *
 * ## Bug yang ditutup
 *
 * `kodeMetode()` mengambil kandidat lewat `->get()` TANPA `ORDER BY`, lalu
 * memilih pemenangnya dengan dua `sortByDesc` berantai. `sortBy` Laravel
 * stabil, jadi kandidat yang panjang namanya sama DAN revisinya sama
 * mempertahankan urutan datangnya — yaitu urutan yang tidak dijanjikan siapa
 * pun. Di MySQL itu bergantung pilihan indeks, dan pilihan itu bisa berubah
 * sesudah `UPDATE`.
 *
 * Yang keluar dari sana tercetak di sertifikat terakreditasi sebagai kode IK.
 * Dua sertifikat untuk alat yang sama bisa menyebut IK berbeda tanpa satu pun
 * error dan tanpa ada data yang berubah — kelas yang sama persis dengan tabel
 * "Standards Used" yang tertukar (3 Sep 2026), di tempat yang belum dijaga.
 *
 * ## Kenapa diuji dengan menyodorkan koleksi
 *
 * Sama alasannya dengan `SnapshotSertifikatTahanUrutanTest`: menyusun ulang
 * baris di database TIDAK menguji apa pun. SQLite tanpa `ORDER BY` selalu
 * memulangkan urutan rowid, jadi pemenangnya selalu id terkecil — dengan atau
 * tanpa perbaikan, dan test-nya hijau sejak awal.
 *
 * Karena itu `metodeTerpilih()` menerima koleksi: urutannya jadi bisa diadu.
 *
 * ## Kenapa `TestCase` polos, bukan `Tests\TestCase`
 *
 * Fungsinya murni dan modelnya tidak disimpan. Boot aplikasi penuh cuma
 * menambah waktu tanpa menambah bukti.
 */
class MetodeTerpilihTahanUrutanTest extends TestCase
{
    /** Model TANPA disimpan — `id` dipasang langsung, cukup buat `getKey()`. */
    private function metode(int $id, string $nama, string $revisi): CalibrationMethod
    {
        $m = new CalibrationMethod;
        $m->forceFill(['id' => $id, 'nama' => $nama, 'revisi' => $revisi]);

        return $m;
    }

    /**
     * INTI-nya: dua IK yang benar-benar seri, disodorkan dua urutan.
     *
     * Serinya dibikin sengaja — panjang nama sama (7) dan revisi sama (3) —
     * karena memang cuma itu keadaan yang memicu bug-nya. Nilainya sintetis;
     * yang nyata pertanyaannya: apa yang terjadi kalau seri itu muncul.
     *
     * Sebelum perbaikan, `[a, b]` memulangkan `a` dan `[b, a]` memulangkan `b`
     * — pemenangnya ditentukan siapa yang kebetulan datang duluan dari
     * database.
     */
    public function test_seri_penuh_tidak_bergantung_urutan_datang(): void
    {
        $a = $this->metode(11, 'Meter A', '3');
        $b = $this->metode(22, 'Meter B', '3');
        $namaAlat = 'meter a dan meter b bench';

        $maju = CertificateSnapshotBuilder::metodeTerpilih(new Collection([$a, $b]), $namaAlat);
        $mundur = CertificateSnapshotBuilder::metodeTerpilih(new Collection([$b, $a]), $namaAlat);

        $this->assertNotNull($maju);
        $this->assertNotNull($mundur);
        $this->assertSame(
            $maju->getKey(),
            $mundur->getKey(),
            'Pemenangnya berubah cuma karena kandidatnya datang dalam urutan '
            .'berbeda. Urutan itu tidak dijanjikan siapa pun, dan yang keluar '
            .'dari sini tercetak jadi kode IK di sertifikat terakreditasi.',
        );

        // Dan pemenangnya `id` terkecil — stabil, bukan acak.
        $this->assertSame(11, $maju->getKey());
    }

    /** Nama terpanjang tetap menang — perilaku lama tidak bergeser. */
    public function test_nama_terpanjang_tetap_menang(): void
    {
        $pendek = $this->metode(1, 'Meter', '9');
        $panjang = $this->metode(99, 'pH Meter', '1');

        $pilih = CertificateSnapshotBuilder::metodeTerpilih(
            new Collection([$pendek, $panjang]),
            'ph meter bench',
        );

        $this->assertSame(
            99,
            $pilih?->getKey(),
            'Nama terpanjang harus menang walau revisinya lebih rendah dan '
            .'`id`-nya lebih besar — pemecah seri tidak boleh naik pangkat.',
        );
    }

    /** Panjang sama → revisi tertinggi menang, `id` belum ikut bicara. */
    public function test_revisi_tertinggi_menang_saat_panjang_sama(): void
    {
        $lama = $this->metode(1, 'Meter A', '2');
        $baru = $this->metode(50, 'Meter B', '7');

        $pilih = CertificateSnapshotBuilder::metodeTerpilih(
            new Collection([$lama, $baru]),
            'meter a dan meter b bench',
        );

        $this->assertSame(50, $pilih?->getKey());
    }

    /** Yang tidak cocok namanya tidak ikut dipilih. */
    public function test_yang_tidak_cocok_dibuang(): void
    {
        $cocok = $this->metode(5, 'pH Meter', '1');
        $lain = $this->metode(6, 'Timbangan', '9');

        $pilih = CertificateSnapshotBuilder::metodeTerpilih(
            new Collection([$lain, $cocok]),
            'ph meter bench',
        );

        $this->assertSame(5, $pilih?->getKey());
    }

    /** Nol kandidat cocok → null, bukan melempar. */
    public function test_tidak_ada_yang_cocok_pulang_null(): void
    {
        $this->assertNull(CertificateSnapshotBuilder::metodeTerpilih(
            new Collection([$this->metode(1, 'Timbangan', '1')]),
            'ph meter bench',
        ));
    }
}
