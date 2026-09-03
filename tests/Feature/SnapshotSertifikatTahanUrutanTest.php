<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\UncertaintyCalculation;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Snapshot sertifikat tidak bergeser gara-gara urutan baris yang disodorkan.
 *
 * ## Kejadian yang melahirkan test ini
 *
 * 3 Sep 2026: `sertifikat:bangun-ulang` dijalankan dua kali berturut-turut di
 * MySQL yang sama, dengan kode yang sama. Hasilnya beda. Tiga sertifikat
 * berpindah antara "berubah" dan "nggak berubah" tiap jalan, dan tiap jalan
 * menulis ulang PDF-nya — padahal perintah itu docblock-nya menjanjikan
 * "aman diulang: hasilnya cuma bergantung data sesi + kode yang lagi jalan".
 *
 * Sebabnya `standarDigunakan()` membaca `uncertaintyCalculations` apa adanya,
 * dan hasilnya dicetak apa adanya jadi tabel "Standards Used" di sertifikat
 * terakreditasi: pH Buffer 4/7/10 bisa keluar 10/7/4 di dokumen yang sudah
 * dipegang pelanggan. Nol error, dan 2.938 test tetap hijau.
 *
 * ## Kenapa diuji dengan menyodorkan koleksi, bukan dengan mengacak database
 *
 * Versi pertama test ini menyusun ulang barisnya di database supaya urutan
 * `id` berlawanan dengan `titik_ke` — dan LOLOS di baseline, hijau tanpa
 * menguji apa pun. Sebabnya migrasinya punya `index(['calibration_session_id',
 * 'titik_ke'])`: query tanpa `ORDER BY` KEBETULAN keluar urut titik kalau
 * perencana query memakai indeks itu, dan SQLite selalu memakainya.
 *
 * Justru itu bentuk bahayanya: yang salah bukan "urutannya pasti kebalik", tapi
 * "urutannya tidak dijanjikan siapa pun". Di MySQL pilihan indeksnya bergantung
 * statistik, dan itu bisa berubah sesudah `UPDATE` — persis yang dilakukan
 * perintah bangun-ulang tiap jalan.
 *
 * Jadi yang dijaga di sini sifat yang benar-benar dibutuhkan: snapshot itu
 * fungsi dari DATA, bukan dari urutan koleksi yang kebetulan sampai ke builder.
 */
class SnapshotSertifikatTahanUrutanTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_sama_walau_urutan_koleksinya_dibalik(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sertifikat = Certificate::query()
            ->where('status', Certificate::STATUS_TERBIT)
            ->firstOrFail();

        $sesi = $this->sesiSegar($sertifikat->calibration_session_id);

        $this->assertGreaterThan(
            1,
            $sesi->uncertaintyCalculations->count(),
            'Sesi ujinya cuma punya satu titik — urutan apa pun kelihatan benar.',
        );

        $builder = app(CertificateSnapshotBuilder::class);
        $asli = $builder->bangun($sesi, $sertifikat);

        // Persis yang bisa dilakukan MySQL tanpa `ORDER BY`: baris yang sama,
        // nilai yang sama, cuma sampai ke builder dengan urutan lain.
        $dibalik = $this->sesiSegar($sertifikat->calibration_session_id);
        $dibalik->setRelation(
            'uncertaintyCalculations',
            $dibalik->uncertaintyCalculations->reverse()->values(),
        );
        $dibalik->setRelation(
            'standarDicek',
            $dibalik->standarDicek->reverse()->values(),
        );

        $this->assertSame(
            array_column($asli['standar_digunakan'] ?? [], 'name'),
            array_column($builder->bangun($dibalik, $sertifikat)['standar_digunakan'] ?? [], 'name'),
            'Tabel ketertelusuran berubah urutan cuma gara-gara koleksinya datang '
            .'terbalik. Itu bergeser di dokumen terkendali tanpa satu pun error.',
        );

        $this->assertEquals(
            $asli,
            $builder->bangun($dibalik, $sertifikat),
            'Ada bagian LAIN dari snapshot yang ikut bergeser gara-gara urutan. '
            .'Yang ketahuan 3 Sep 2026 baru `standar_digunakan`.',
        );
    }

    /**
     * Baris yang `titik_ke`-nya KEMBAR tetap urut, bukan ikut urutan datangnya.
     *
     * Temuan review 3 Sep 2026, dan benar. `sortBy` itu stabil: baris kembar
     * mempertahankan urutan koleksinya — yaitu urutan yang tidak dijanjikan
     * siapa pun. Perbaikan pertama cuma memasang sumbu kedua di
     * `standarDigunakan()`, sementara `hasil()` — tabel hasil kalibrasi itu
     * sendiri — masih `sortBy('titik_ke')` saja.
     *
     * Yang paling kena alat yang satu titiknya memang punya beberapa baris:
     * Chlorine Meter (Free/Total) dan Spectrophotometer (tiga blok). Sesi contoh
     * bawaan tidak punya titik kembar, jadi keadaannya dibikin di sini.
     */
    public function test_titik_ke_kembar_tetap_urut_walau_koleksinya_dibalik(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sertifikat = Certificate::query()
            ->where('status', Certificate::STATUS_TERBIT)
            ->firstOrFail();

        $this->kembarkanTitikPertama($sertifikat->calibration_session_id);

        $builder = app(CertificateSnapshotBuilder::class);
        $asli = $builder->bangun($this->sesiSegar($sertifikat->calibration_session_id), $sertifikat);

        $dibalik = $this->sesiSegar($sertifikat->calibration_session_id);
        $dibalik->setRelation(
            'uncertaintyCalculations',
            $dibalik->uncertaintyCalculations->reverse()->values(),
        );

        $this->assertEquals(
            $asli,
            $builder->bangun($dibalik, $sertifikat),
            'Titik kembar bergeser urutannya cuma gara-gara koleksinya datang '
            .'terbalik. `sortBy` stabil tidak cukup — sumbu keduanya wajib.',
        );
    }

    /**
     * Bikin satu titik ukur punya DUA baris, dengan `id` yang lebih besar.
     *
     * Nilainya sengaja dibedakan: kalau dua barisnya identik, tertukar pun
     * snapshot-nya sama dan test ini hijau tanpa menguji apa pun. Versi
     * pertama kena persis itu — kolomnya diketik `pembacaan_rata`, padahal
     * namanya `rata_rata`; atribut yang bukan kolom dibuang mass-assignment
     * tanpa satu pun error, dan kembarannya lahir identik.
     */
    private function kembarkanTitikPertama(int $sesiId): void
    {
        $pertama = UncertaintyCalculation::query()
            ->where('calibration_session_id', $sesiId)
            ->orderBy('titik_ke')
            ->firstOrFail();

        $kembar = $pertama->getAttributes();
        unset($kembar['id']);
        $kembar['rata_rata'] = (float) $pertama->rata_rata + 0.01;
        $kembar['koreksi'] = (float) $pertama->koreksi - 0.01;

        UncertaintyCalculation::query()->create($kembar);
    }

    /**
     * Sesi dimuat SEGAR tiap kali.
     *
     * Relasi yang sudah nempel di memori bakal membekukan urutan lama dan bikin
     * test ini hijau tanpa menguji apa pun.
     */
    private function sesiSegar(int $id): CalibrationSession
    {
        return CalibrationSession::query()
            ->with('uncertaintyCalculations.standard', 'standarDicek', 'standard',
                'equipment.customer', 'organization', 'teknisi', 'reviewer',
                'thermohygro', 'rawMeasurements')
            ->findOrFail($id);
    }
}
