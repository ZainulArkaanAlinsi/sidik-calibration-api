<?php

namespace Database\Seeders\Concerns;

use App\Models\CalibrationSession;
use App\Services\RumusKalibrasi;

/**
 * Stempel versi rumus buat hasil hitung yang dibikin seeder.
 *
 * ## Kenapa perlu
 *
 * Jalur API (`CalibrationController`) udah nyetempel `formula_version_id` di tiap
 * baris `uncertainty_calculations` — itu Keputusan 5, yang bikin hasil hitung lama
 * tetap bisa dijelasin sesudah rumusnya diubah. Seeder NGGAK ikut, jadi tiap sesi
 * demo keluar dengan kolom itu kosong.
 *
 * Efeknya bukan cuma kolom bolong. Yang kelihatan waktu DB diperiksa adalah
 * "seperempat hasil hitung nggak ketelusur ke versi rumus" — temuan yang persis
 * sama bentuknya dengan kebocoran ketertelusuran beneran, tapi sebabnya cuma data
 * demo. Audit 12 Agt 2026 sempat kejebak ini dan nyimpulin fitur versinya belum
 * kepasang, padahal udah.
 *
 * Data yang bohong soal kelemahannya sendiri lebih mahal daripada kolom kosong:
 * yang meriksa jadi ngabisin waktu ngejar hantu, atau lebih buruk — mulai
 * ngabaikan temuan sejenis.
 *
 * ## Cara pakai
 *
 * Panggil SEKALI per sesi, di luar loop titik: semua titik di satu sesi pakai
 * versi yang sama, dan manggil per titik cuma nambah query tanpa nambah
 * informasi. Alasan yang sama kayak di `CalibrationController`.
 */
trait MenstempelVersiRumus
{
    /**
     * `null` kalau organisasi/alatnya belum ketauan — sama persis kayak jalur
     * API. Dibiarin null, bukan diisi tebakan: baris yang ngaku dihitung pakai
     * versi yang nggak pernah berlaku lebih menyesatkan daripada baris kosong.
     */
    protected function versiRumusUntuk(CalibrationSession $sesi): ?int
    {
        return app(RumusKalibrasi::class)->versiUntukSesi($sesi)?->id;
    }
}
