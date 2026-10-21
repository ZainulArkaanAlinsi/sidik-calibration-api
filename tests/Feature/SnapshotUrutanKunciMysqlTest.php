<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\CalibrationValidator;
use App\Services\CertificateExcelExporter;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Kolom JSON MySQL TIDAK menyimpan urutan kunci objek: dia mengurutkannya
 * (panjang kunci, lalu byte). Snapshot sertifikat yang dibaca balik dari
 * produksi sudah berurutan lain dari waktu dibangun. Daftar (JSON array)
 * tetap urut.
 *
 * Test ini menjaga bahwa TIDAK ADA yang membaca urutan kunci peta snapshot
 * (`catatan_blok[*]`, `format`, `tambahan`, `header`, `header_tambahan`,
 * `tata_letak`, …): tiap sertifikat sesi contoh disimpan ulang dengan kunci
 * tiap peta diurutkan seperti MySQL, lalu dengan urutan DIBALIK, dibaca balik
 * lewat cast kolom (`Certificate::$casts['snapshot']`), dan teks PDF serta
 * isi Excel-nya harus sama persis dengan aslinya — termasuk urutan blok,
 * kolom, dan baris.
 */
class SnapshotUrutanKunciMysqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_urutan_kunci_peta_snapshot_tidak_mengubah_pdf_maupun_excel(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
        $validator = app(CalibrationValidator::class);
        $diperiksa = 0;

        foreach (CalibrationSession::query()->get() as $sesi) {
            if (collect($validator->periksa($sesi)['temuan'])->contains('kode', CalibrationValidator::MENUNGGU_KEPUTUSAN_TM)) {
                continue;
            }

            $sertifikat = $sesi->certificate()->first();

            if ($sertifikat === null) {
                if ($sesi->status !== CalibrationSession::STATUS_MENUNGGU_APPROVAL) {
                    continue;
                }

                $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                    ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                    ->assertOk();
                $sertifikat = $sesi->fresh()->certificate()->firstOrFail();
            }

            if (! is_array($sertifikat->snapshot) || $sertifikat->snapshot === []) {
                continue;
            }

            $asli = $sertifikat->snapshot;
            $teksAsli = $this->teks($sertifikat);
            $excelAsli = $this->excel($sertifikat);

            foreach (['mysql' => false, 'dibalik' => true] as $nama => $balik) {
                $acak = self::urutUlang($asli, $balik);
                $this->assertNotSame(json_encode($asli), json_encode($acak), "{$sesi->nomor_sesi}: urutan {$nama} tidak mengubah apa pun — simulasinya kosong.");

                $sertifikat->forceFill(['snapshot' => $acak])->saveQuietly();
                $dibaca = Certificate::findOrFail($sertifikat->id);
                $this->assertSame(json_encode($acak), json_encode($dibaca->snapshot), 'Cast kolom harus memulangkan urutan yang disimpan.');

                $this->assertSame($teksAsli, $this->teks($dibaca), "{$sesi->nomor_sesi}: teks PDF berubah dengan urutan kunci {$nama}.");
                $this->assertSame($excelAsli, $this->excel($dibaca), "{$sesi->nomor_sesi}: Excel berubah dengan urutan kunci {$nama}.");
            }

            $sertifikat->forceFill(['snapshot' => $asli])->saveQuietly();
            $diperiksa++;
        }

        $this->assertGreaterThan(40, $diperiksa, 'Sesi contoh yang diterbitkan terlalu sedikit.');
    }

    /** Uji alat bantu: urutan MySQL = panjang kunci, lalu byte; daftar tidak disentuh. */
    public function test_urut_ulang_meniru_mysql(): void
    {
        $this->assertSame(
            ['k' => 1, 'u95' => 3, 'uut' => 2, 'koreksi' => 5, 'standar' => 4],
            self::urutUlang(['standar' => 4, 'uut' => 2, 'koreksi' => 5, 'u95' => 3, 'k' => 1], false),
        );
        $this->assertSame([3, 1, 2], self::urutUlang([3, 1, 2], false));
        $this->assertSame([['b' => 1, 'aa' => 2]], self::urutUlang([['aa' => 2, 'b' => 1]], false));
    }

    /**
     * Urutkan ulang kunci setiap PETA (bukan daftar) secara rekursif: seperti
     * kolom JSON MySQL (`$balik` false) atau kebalikannya.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function urutUlang(array $data, bool $balik): array
    {
        $data = array_map(static fn ($v) => is_array($v) ? self::urutUlang($v, $balik) : $v, $data);

        if (array_is_list($data)) {
            return $data;
        }

        uksort($data, static function ($a, $b) use ($balik): int {
            $hasil = [strlen((string) $a), (string) $a] <=> [strlen((string) $b), (string) $b];

            return $balik ? -$hasil : $hasil;
        });

        return $data;
    }

    private function teks(Certificate $sertifikat): string
    {
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<style>.*?</style>#su', '', $html))));
    }

    /** @return list<list<mixed>> */
    private function excel(Certificate $sertifikat): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'uji-urut-').'.xlsx';
        app(CertificateExcelExporter::class)->satu($sertifikat, $berkas);
        $sel = [];
        $pembaca = new Reader;
        $pembaca->open($berkas);

        foreach ($pembaca->getSheetIterator() as $lembar) {
            foreach ($lembar->getRowIterator() as $baris) {
                $sel[] = array_map(static fn ($c) => $c->getValue(), $baris->getCells());
            }
        }

        $pembaca->close();
        @unlink($berkas);

        return $sel;
    }
}
