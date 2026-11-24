<?php

namespace Tests\Unit\Kontrak;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tes kontrak antar-terminal v0.1 (`docs/kontrak/v0.1/`).
 *
 * Yang dijaga bukan "contoh lulus", tapi tiga hal yang bisa bocor diam-diam:
 *
 * 1. Contoh TIDAK sah harus gagal karena SEBAB YANG DITULIS di `MANIFEST.json`
 *    (jalur data + kata kunci), bukan karena salah ketik lain. Tanpa itu satu
 *    contoh jelek bisa gagal di tempat yang salah dan tes tetap hijau.
 * 2. Schema tidak boleh memuat kata kunci yang validator abaikan diam-diam.
 *    Salah ketik `additionalProperty` tidak menimbulkan galat di validator mana
 *    pun; yang terjadi hanya aturannya tidak berlaku.
 * 3. Aturan yang TIDAK bisa ditulis di JSON Schema (KONTRAK.md bagian 7) dipakai
 *    contoh sah sebagai tolok ukur, karena contoh itulah yang disalin tiga
 *    terminal lain menjadi fixture mereka.
 *
 * Tes ini murni berkas: tanpa basis data, tanpa boot aplikasi.
 */
class KontrakV01Test extends TestCase
{
    private const NAMA_SCHEMA = [
        'skema-formulir',
        'usulan-ocr',
        'konfirmasi-permintaan',
        'konfirmasi',
        'data-master',
        'header-pekerjaan',
    ];

    /** Kata kunci JSON Schema yang dipakai kontrak ini. Tambah di sini SAMBIL memastikan validator konsumen mendukungnya. */
    private const KATA_KUNCI = [
        '$schema', '$id', '$ref', '$defs', 'title', 'description', 'type', 'const', 'enum',
        'properties', 'required', 'additionalProperties', 'items', 'prefixItems',
        'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern', 'format',
        'minimum', 'maximum', 'exclusiveMinimum', 'oneOf', 'allOf', 'if', 'then', 'not',
    ];

    private static function akar(): string
    {
        return dirname(__DIR__, 3).'/docs/kontrak/v0.1';
    }

    private static function muatJson(string $berkas): mixed
    {
        $isi = file_get_contents($berkas);
        self::assertIsString($isi, "tidak terbaca: $berkas");

        return json_decode($isi, false, 512, JSON_THROW_ON_ERROR);
    }

    private static function schema(string $nama): object
    {
        return self::muatJson(self::akar()."/schema/$nama.schema.json");
    }

    private static function contoh(string $nama, string $berkas): object
    {
        return self::muatJson(self::akar()."/contoh/$nama/$berkas");
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return json_decode(
            (string) file_get_contents(self::akar().'/contoh/MANIFEST.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function contohSah(): array
    {
        $baris = [];
        foreach (self::NAMA_SCHEMA as $nama) {
            foreach (self::manifest()[$nama]['valid'] as $berkas) {
                $baris["$nama/$berkas"] = [$nama, $berkas];
            }
        }

        return $baris;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function contohTidakSah(): array
    {
        $baris = [];
        foreach (self::NAMA_SCHEMA as $nama) {
            foreach (self::manifest()[$nama]['tidak_valid'] as $butir) {
                $baris["$nama/{$butir['berkas']}"] = [$nama, $butir];
            }
        }

        return $baris;
    }

    /**
     * Semua daun galat sebagai [jalur data, kata kunci]. Jalur akar = "".
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function daunGalat(ValidationError $galat): array
    {
        $anak = $galat->subErrors();
        if ($anak === []) {
            $jalur = array_map('strval', $galat->data()->fullPath());

            // JSON Pointer: akar = "" (bukan "/").
            return [[$jalur === [] ? '' : '/'.implode('/', $jalur), $galat->keyword()]];
        }

        $daun = [];
        foreach ($anak as $sub) {
            array_push($daun, ...self::daunGalat($sub));
        }

        return $daun;
    }

    private static function validator(): Validator
    {
        $validator = new Validator;
        $validator->setMaxErrors(100);

        return $validator;
    }

    // ---------------------------------------------------------------- schema

    #[Test]
    public function schema_hanya_memakai_kata_kunci_yang_diizinkan_dan_berdiri_sendiri(): void
    {
        foreach (self::NAMA_SCHEMA as $nama) {
            $salah = [];
            $this->periksaKataKunci(self::schema($nama), '', $salah);
            $this->assertSame([], $salah, "kata kunci tak dikenal di $nama.schema.json");

            $mentah = (string) file_get_contents(self::akar()."/schema/$nama.schema.json");
            preg_match_all('/"\$ref":\s*"([^"]+)"/', $mentah, $cocok);
            foreach ($cocok[1] as $rujukan) {
                $this->assertStringStartsWith('#/$defs/', $rujukan, "$nama.schema.json: \$ref keluar berkas");
                $nama_def = substr($rujukan, strlen('#/$defs/'));
                $this->assertObjectHasProperty($nama_def, self::schema($nama)->{'$defs'}, "$nama.schema.json: \$defs/$nama_def tidak ada");
            }
        }
    }

    /**
     * @param  list<string>  $salah
     */
    private function periksaKataKunci(mixed $simpul, string $jalur, array &$salah): void
    {
        if (! is_object($simpul)) {
            return;
        }

        foreach ($simpul as $kunci => $nilai) {
            if (! in_array($kunci, self::KATA_KUNCI, true)) {
                $salah[] = "$jalur/$kunci";

                continue;
            }

            if ($kunci === 'properties' || $kunci === '$defs') {
                foreach ($nilai as $nama => $sub) {
                    $this->periksaKataKunci($sub, "$jalur/$kunci/$nama", $salah);
                }
            } elseif (in_array($kunci, ['items', 'additionalProperties', 'not', 'if', 'then'], true)) {
                $this->periksaKataKunci($nilai, "$jalur/$kunci", $salah);
            } elseif (in_array($kunci, ['prefixItems', 'allOf', 'oneOf'], true)) {
                foreach ($nilai as $i => $sub) {
                    $this->periksaKataKunci($sub, "$jalur/$kunci/$i", $salah);
                }
            }
        }
    }

    #[Test]
    public function defs_bernama_sama_identik_di_semua_schema(): void
    {
        $pertama = [];
        foreach (self::NAMA_SCHEMA as $nama) {
            foreach (self::schema($nama)->{'$defs'} ?? [] as $def => $isi) {
                $kanonik = json_encode($isi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (! isset($pertama[$def])) {
                    $pertama[$def] = [$nama, $kanonik];

                    continue;
                }
                $this->assertSame(
                    $pertama[$def][1],
                    $kanonik,
                    "\$defs/$def di $nama.schema.json berbeda dari {$pertama[$def][0]}.schema.json",
                );
            }
        }
        $this->assertNotEmpty($pertama);
    }

    #[Test]
    public function hash_schema_cocok_dengan_sha256sums(): void
    {
        $dicatat = [];
        foreach (file(self::akar().'/schema/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $baris) {
            [$hash, $berkas] = preg_split('/\s+/', trim($baris), 2);
            $dicatat[$berkas] = $hash;
        }

        $ada = [];
        foreach (glob(self::akar().'/schema/*.schema.json') as $jalur) {
            $ada[basename($jalur)] = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($jalur)));
        }
        ksort($ada);
        ksort($dicatat);

        $this->assertSame(
            $dicatat,
            $ada,
            'Schema v0.1 berubah tanpa SHA256SUMS.txt diperbarui. Perubahan nyata = folder versi baru (KONTRAK.md bagian 8).',
        );
    }

    // --------------------------------------------------------------- contoh

    #[Test]
    public function manifest_memuat_semua_berkas_contoh_dan_tidak_ada_yang_yatim(): void
    {
        $manifest = self::manifest();
        $this->assertSame('0.1', $manifest['kontrak_versi']);

        foreach (self::NAMA_SCHEMA as $nama) {
            $dicatat = array_merge(
                $manifest[$nama]['valid'],
                array_column($manifest[$nama]['tidak_valid'], 'berkas'),
            );
            $ada = array_map('basename', glob(self::akar()."/contoh/$nama/*.json"));
            sort($dicatat);
            sort($ada);
            $this->assertSame($ada, $dicatat, "contoh/$nama tidak sama dengan MANIFEST.json");

            foreach ($manifest[$nama]['valid'] as $berkas) {
                $this->assertStringStartsWith('valid-', $berkas);
            }
            foreach ($manifest[$nama]['tidak_valid'] as $butir) {
                $this->assertStringStartsWith('tidak-valid-', $butir['berkas']);
                $this->assertNotSame('', trim($butir['alasan']), "{$butir['berkas']}: alasan kosong");
                $this->assertArrayHasKey('jalur_galat', $butir);
                $this->assertNotSame('', $butir['kata_kunci'] ?? '', "{$butir['berkas']}: kata_kunci kosong");
            }
        }
    }

    #[Test]
    #[DataProvider('contohSah')]
    public function contoh_sah_lulus_schema(string $nama, string $berkas): void
    {
        $hasil = self::validator()->validate(self::contoh($nama, $berkas), self::schema($nama));

        $this->assertTrue(
            $hasil->isValid(),
            "$nama/$berkas harus lulus, tetapi gagal: ".json_encode(
                $hasil->error() ? self::daunGalat($hasil->error()) : [],
            ),
        );
    }

    /**
     * @param  array{berkas: string, alasan: string, jalur_galat: string, kata_kunci: string}  $butir
     */
    #[Test]
    #[DataProvider('contohTidakSah')]
    public function contoh_tidak_sah_gagal_karena_sebab_yang_tertulis(string $nama, array $butir): void
    {
        $hasil = self::validator()->validate(self::contoh($nama, $butir['berkas']), self::schema($nama));

        $this->assertFalse($hasil->isValid(), "$nama/{$butir['berkas']} seharusnya TIDAK sah: {$butir['alasan']}");

        $daun = self::daunGalat($hasil->error());
        $this->assertContains(
            [$butir['jalur_galat'], $butir['kata_kunci']],
            $daun,
            "$nama/{$butir['berkas']} gagal, tetapi bukan di ({$butir['jalur_galat']}, {$butir['kata_kunci']}). Galat sebenarnya: "
                .json_encode($daun),
        );
    }

    // -------------------------------------------------- aturan di luar schema

    #[Test]
    public function r1_r2_bbox_di_dalam_halaman_dan_id_unik_pada_semua_skema_contoh(): void
    {
        $skema = self::manifest()['skema-formulir']['valid'];
        $this->assertNotEmpty($skema);

        foreach ($skema as $berkas) {
            $dokumen = self::contoh('skema-formulir', $berkas);
            [$lebar, $tinggi] = $dokumen->halaman_pt;

            $id = [];
            foreach ($dokumen->item as $i => $butir) {
                [$x0, $y0, $x1, $y1] = $butir->bbox_pt;
                $this->assertLessThan($x1, $x0, "$berkas item $i: x0 >= x1");
                $this->assertLessThan($y1, $y0, "$berkas item $i: y0 >= y1");
                $this->assertLessThanOrEqual($lebar, $x1, "$berkas item $i: keluar halaman (lebar)");
                $this->assertLessThanOrEqual($tinggi, $y1, "$berkas item $i: keluar halaman (tinggi)");
                $this->assertArrayNotHasKey($butir->id, $id, "$berkas: id ganda {$butir->id}");
                $id[$butir->id] = true;
            }
        }
    }

    #[Test]
    public function r14_id_yang_dipertahankan_antar_revisi_tidak_berganti_jenis_dan_label(): void
    {
        $lama = $this->indeksItem(self::contoh('skema-formulir', 'valid-dipublikasi-contoh-bentuk.json'));
        $baru = self::contoh('skema-formulir', 'valid-draf-revisi-2-id-dipertahankan.json');

        $this->assertSame($lama['kode'], $baru->formulir->kode, 'revisi baru harus kode yang sama');
        $this->assertGreaterThan($lama['revisi'], $baru->formulir->revisi);

        $dipertahankan = 0;
        foreach ($baru->item as $butir) {
            if (! isset($lama['item'][$butir->id])) {
                continue;
            }
            $dipertahankan++;
            $asal = $lama['item'][$butir->id];
            $this->assertSame($asal->jenis, $butir->jenis, "{$butir->id}: jenis berganti");
            $this->assertSame(
                [$asal->kelompok, $asal->label_baris, $asal->label_kolom],
                [$butir->kelompok, $butir->label_baris, $butir->label_kolom],
                "{$butir->id}: kunci label berganti, berarti ini kotak lain dan butuh id baru",
            );
        }
        $this->assertGreaterThan(0, $dipertahankan);
    }

    /**
     * @return array{kode: string, revisi: int, item: array<string, object>}
     */
    private function indeksItem(object $skema): array
    {
        $item = [];
        foreach ($skema->item as $butir) {
            $item[$butir->id] = $butir;
        }

        return ['kode' => $skema->formulir->kode, 'revisi' => $skema->formulir->revisi, 'item' => $item];
    }

    #[Test]
    public function rantai_contoh_skema_usulan_permintaan_konfirmasi_saling_konsisten(): void
    {
        $skema = self::contoh('skema-formulir', 'valid-dipublikasi-contoh-bentuk.json');
        $usulan = self::contoh('usulan-ocr', 'valid-semua-status.json');
        $permintaan = self::contoh('konfirmasi-permintaan', 'valid-pertama.json');
        $k1 = self::contoh('konfirmasi', 'valid-urutan-1.json');
        $k2 = self::contoh('konfirmasi', 'valid-urutan-2-koreksi.json');

        $peranItem = [];
        foreach ($skema->item as $butir) {
            $peranItem[$butir->id] = $butir->peran;
        }
        $isian = ['isian_angka', 'isian_teks', 'centang', 'tanggal'];

        // R11: skema yang ditunjuk usulan berstatus dipublikasi dan identitasnya sama.
        $this->assertSame('dipublikasi', $skema->status);
        $this->assertSame($skema->formulir->kode, $usulan->formulir->kode);
        $this->assertSame($skema->formulir->revisi, $usulan->formulir->revisi);
        $this->assertSame($skema->versi_skema, $usulan->versi_skema);

        $usulanPerItem = [];
        foreach ($usulan->item as $butir) {
            // R4
            $this->assertArrayHasKey($butir->item_id, $peranItem, "usulan menunjuk item yang tidak ada: {$butir->item_id}");
            $this->assertContains($peranItem[$butir->item_id], $isian, "{$butir->item_id}: bukan kotak isian");
            $usulanPerItem[$butir->item_id] = $butir;

            // R5
            if ($butir->status === 'sepakat_dua_pembacaan') {
                $pembaca = array_unique(array_map(static fn (object $p): string => $p->pembaca, $butir->bacaan));
                $this->assertGreaterThanOrEqual(2, count($pembaca), "{$butir->item_id}: pembaca tidak berbeda");
                foreach ($butir->bacaan as $p) {
                    $this->assertSame($butir->nilai_usulan, $p->teks, "{$butir->item_id}: bacaan tidak sepakat persis");
                }
            }
            // R6
            if ($peranItem[$butir->item_id] === 'centang' && $butir->nilai_usulan !== null) {
                $this->assertContains($butir->nilai_usulan, ['1', '0']);
            }
        }

        // R7: konfirmasi pertama mencakup semua butir ragu/tidak_terbaca.
        $dikonfirmasi = array_map(static fn (object $b): string => $b->item_id, $k1->item);
        foreach ($usulan->item as $butir) {
            if (in_array($butir->status, ['ragu', 'tidak_terbaca'], true)) {
                $this->assertContains($butir->item_id, $dikonfirmasi, "{$butir->item_id}: {$butir->status} belum ditinjau");
            }
        }

        $this->assertSame($usulan->pindai_id, $permintaan->pindai_id);
        $this->assertSame($usulan->pindai_id, $k1->pindai_id);
        $this->assertSame(1, $k1->urutan);

        $permintaanPerItem = [];
        foreach ($permintaan->item as $butir) {
            $permintaanPerItem[$butir->item_id] = $butir;
        }

        foreach ($k1->item as $butir) {
            $u = $usulanPerItem[$butir->item_id];
            // R9 urutan 1
            $this->assertSame($u->nilai_usulan, $butir->nilai_usulan);
            $this->assertSame($u->nilai_usulan, $butir->nilai_sebelum);
            // R10
            $this->assertSame($butir->nilai_final !== $butir->nilai_sebelum, $butir->diubah, "{$butir->item_id}: diubah salah");
            // permintaan klien menghasilkan catatan yang sama (kecuali yang diisi server)
            $minta = $permintaanPerItem[$butir->item_id];
            $this->assertSame($minta->nilai_final, $butir->nilai_final);
            $this->assertSame($minta->alasan_jika_diubah, $butir->alasan_jika_diubah);
            $this->assertSame($minta->master_id, $butir->master_id);
            // R8
            $this->assertNilaiSesuaiPeran($peranItem[$butir->item_id], $butir->nilai_final, $butir->item_id);
        }

        // Koreksi: nilai_sebelum = nilai_final catatan sebelumnya, usulan mesin tidak berubah (R9).
        $this->assertSame(2, $k2->urutan);
        $finalK1 = [];
        foreach ($k1->item as $butir) {
            $finalK1[$butir->item_id] = $butir->nilai_final;
        }
        foreach ($k2->item as $butir) {
            $this->assertSame($finalK1[$butir->item_id], $butir->nilai_sebelum);
            $this->assertSame($usulanPerItem[$butir->item_id]->nilai_usulan, $butir->nilai_usulan);
            $this->assertSame($butir->nilai_final !== $butir->nilai_sebelum, $butir->diubah);
            $this->assertNilaiSesuaiPeran($peranItem[$butir->item_id], $butir->nilai_final, $butir->item_id);
        }
        $this->assertGreaterThan(strtotime($k1->waktu), strtotime($k2->waktu));
    }

    private function assertNilaiSesuaiPeran(string $peran, ?string $nilai, string $itemId): void
    {
        if ($nilai === null) {
            return;
        }

        match ($peran) {
            'isian_angka' => $this->assertMatchesRegularExpression('/^-?[0-9]+(\.[0-9]+)?$/', $nilai, "$itemId: bukan angka berpoin"),
            'tanggal' => $this->assertMatchesRegularExpression('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $nilai, "$itemId: bukan tanggal ISO"),
            'centang' => $this->assertContains($nilai, ['1', '0'], "$itemId: centang harus 1/0"),
            'isian_teks' => $this->assertSame(trim($nilai), $nilai, "$itemId: teks belum dipangkas"),
            default => $this->fail("$itemId: peran $peran bukan isian"),
        };
    }

    #[Test]
    public function status_usulan_tidak_memakai_nama_warna(): void
    {
        $status = self::schema('usulan-ocr')->{'$defs'}->item_usulan->properties->status->enum;

        $this->assertSame(['sepakat_dua_pembacaan', 'ragu', 'tidak_terbaca', 'kosong'], $status);
        foreach (['hijau', 'kuning', 'merah'] as $warna) {
            $this->assertNotContains($warna, $status, 'tulisan tangan tidak pernah hijau (KOORDINASI bagian 10 butir 4)');
        }
    }

    #[Test]
    public function permintaan_konfirmasi_tidak_bisa_menyebut_pelaku_waktu_atau_diubah(): void
    {
        $skema = self::schema('konfirmasi-permintaan');

        $this->assertFalse($skema->additionalProperties);
        foreach (['dikonfirmasi_oleh', 'waktu', 'urutan'] as $dilarang) {
            $this->assertObjectNotHasProperty($dilarang, $skema->properties);
        }
        foreach (['diubah', 'nilai_sebelum', 'nilai_usulan'] as $dilarang) {
            $this->assertObjectNotHasProperty($dilarang, $skema->properties->item->items->properties);
        }
    }
}
