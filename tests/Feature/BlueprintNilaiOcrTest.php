<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Menjaga NILAI saklar OCR produksi di `render.yaml`, bukan hanya keberadaan kuncinya.
 *
 * Blueprint menimpa setelan dashboard Render di tiap deploy (kasus ARSIP_DRIVER,
 * 1 Sep 2026, lihat `render.yaml` §ARSIP_DRIVER). Pemilik sudah menyalakan
 * `GOOGLE_VISION_AKTIF` di dashboard, tetapi selama blueprint masih "false" nilai itu
 * kembali mati di deploy berikutnya, dan aplikasi menjawab pembacaan sel dengan
 * 503 `ocr_cloud_belum_aktif` tanpa ada satu pun tes yang merah. Tes ini membuat
 * perubahan semacam itu terlihat di CI.
 *
 * Mematikan salah satu saklar ini sengaja butuh DUA perubahan sekaligus: nilai di
 * blueprint dan konstanta di bawah. Jangan melonggarkan tes ini supaya diam; ubah
 * konstantanya bersama keputusan pemilik.
 */
class BlueprintNilaiOcrTest extends TestCase
{
    /** Saklar yang dinyalakan pemilik di produksi dan wajib tetap "true" di blueprint. */
    private const HARUS_NYALA = [
        'GOOGLE_VISION_AKTIF',
        'OCR_FORMULIR_ASLI_UJI',
    ];

    public function test_saklar_ocr_produksi_bernilai_true_di_blueprint(): void
    {
        $blueprint = (string) file_get_contents(base_path('render.yaml'));
        $this->assertNotSame('', $blueprint, 'render.yaml kosong atau tidak terbaca.');

        foreach (self::HARUS_NYALA as $kunci) {
            $nilai = $this->nilaiDiBlueprint($blueprint, $kunci);

            $this->assertNotNull(
                $nilai,
                "{$kunci} tidak punya baris `value:` di render.yaml (mis. `sync: false`). "
                .'Tanpa nilai di blueprint, setelan dashboard tidak punya jangkar dan bisa berubah diam-diam.',
            );
            $this->assertSame(
                'true',
                $nilai,
                "{$kunci} di render.yaml bernilai \"{$nilai}\", bukan \"true\". "
                .'Blueprint menimpa dashboard tiap deploy, jadi nilai ini yang berlaku di produksi.',
            );
        }
    }

    /** Nilai `value:` tepat di bawah `- key: <kunci>`, tanpa tanda kutip; null kalau barisnya tidak ada. */
    private function nilaiDiBlueprint(string $blueprint, string $kunci): ?string
    {
        $pola = '/^[ \t]*-[ \t]*key:[ \t]*'.preg_quote($kunci, '/').'[ \t]*\r?\n[ \t]*value:[ \t]*"?([^"\r\n]*?)"?[ \t]*\r?$/m';

        return preg_match($pola, $blueprint, $cocok) === 1 ? $cocok[1] : null;
    }
}
