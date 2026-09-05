<?php

namespace App\Services\Dokumen;

use App\Models\DokumenBacaan;
use App\Models\DokumenBacaanNilai;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Simpan satu hasil baca generik: induknya + tiap nilainya.
 *
 * ## Kenapa simpan, padahal hasilnya cuma USULAN
 *
 * Tiga alasan, dan yang ketiga yang paling sering kelewat:
 *
 *  1. Layar review harus bisa dibuka lagi tanpa memanggil AI ulang. Bukan cuma
 *     soal biaya — pembacaan ulang bisa keluar BEDA dari yang barusan
 *     dikoreksi teknisi, dan koreksinya jadi hilang tanpa ada yang tahu.
 *  2. Pasangan (dibaca, dikoreksi) itu data latih yang paling mahal
 *     dikumpulkan. Yang salah baca justru yang paling berharga.
 *  3. Pembacaan yang GAGAL juga disimpan. Tanpa itu, "lembar ini nggak pernah
 *     kebaca" nggak ninggalin jejak apa pun, dan nggak ada cara tahu lembar
 *     mana yang perlu digarap duluan.
 *
 * Yang TIDAK terjadi di sini: nggak ada `raw_measurements` yang lahir. Itu
 * tetap lewat `POST/PUT /calibrations` sesudah teknisi mengoreksi.
 */
class PenyimpanBacaanDokumen
{
    /**
     * @param  array<string, mixed>  $skema  hasil PembuatSkemaDinamis::dari()
     * @param  array<string, mixed>  $usage
     */
    public function simpan(
        User $user,
        array $skema,
        ?string $namaAlatKonteks,
        ?string $model,
        array $usage = [],
        ?int $sesiId = null,
    ): DokumenBacaan {
        $dokumen = $skema['dokumen'] ?? [];
        $ringkasan = $skema['ringkasan'] ?? [];

        return DB::transaction(function () use (
            $user, $skema, $dokumen, $ringkasan, $namaAlatKonteks, $model, $usage, $sesiId
        ): DokumenBacaan {
            $bacaan = DokumenBacaan::create([
                // SELALU dari user yang login, nggak pernah dari input request.
                // `organization_id` yang datang dari body itu parameter
                // serangan, bukan data.
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'calibration_session_id' => $sesiId,
                'nama_alat_konteks' => $namaAlatKonteks,
                'judul' => $dokumen['title'] ?? null,
                'nama_alat' => $dokumen['equipment_name'] ?? null,
                'kode_dokumen' => $dokumen['worksheet_code'] ?? null,
                'revisi' => $dokumen['revision'] ?? null,
                // Identitas pola lembar — yang dipakai mencocokkan riwayat
                // koreksi di pembacaan berikutnya.
                'pola' => RiwayatKoreksi::pola(
                    $dokumen['worksheet_code'] ?? null,
                    $dokumen['revision'] ?? null,
                ),
                'keyakinan' => $dokumen['confidence'] ?? null,
                'status' => ($ringkasan['perlu_review'] ?? 0) > 0
                    ? DokumenBacaan::STATUS_PERLU_REVIEW
                    : DokumenBacaan::STATUS_OK,
                'jumlah_field' => $ringkasan['jumlah_field'] ?? 0,
                'jumlah_sel' => $ringkasan['jumlah_sel'] ?? 0,
                'perlu_review' => $ringkasan['perlu_review'] ?? 0,
                'peringatan' => $skema['peringatan'] ?? [],
                'skema' => $skema,
                'model' => $model,
                'usage' => $usage,
            ]);

            $baris = $this->barisNilai($skema);

            if ($baris !== []) {
                // Sekali insert, bukan satu query per nilai: satu lembar bisa
                // punya ratusan sel, dan ratusan round-trip bikin permintaan
                // yang mestinya sekejap jadi terasa menggantung di lapangan.
                DokumenBacaanNilai::insert(array_map(
                    fn (array $n): array => $n + [
                        'dokumen_bacaan_id' => $bacaan->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    $baris,
                ));
            }

            return $bacaan;
        });
    }

    /**
     * Ratakan skema jadi baris-baris nilai.
     *
     * @param  array<string, mixed>  $skema
     * @return list<array<string, mixed>>
     */
    private function barisNilai(array $skema): array
    {
        $baris = [];

        foreach (($skema['bagian'] ?? []) as $bagian) {
            $namaBagian = $bagian['nama'] ?? null;

            foreach (($bagian['field'] ?? []) as $f) {
                $baris[] = [
                    'kunci' => $f['kunci'],
                    'jenis' => DokumenBacaanNilai::JENIS_FIELD,
                    'bagian_nama' => $namaBagian,
                    'label' => $f['label'] ?? null,
                    'baris_ke' => null,
                    'kolom_ke' => null,
                    'tipe' => $f['tipe'] ?? null,
                    'satuan' => $f['satuan'] ?? null,
                    'nilai_baca' => $this->teks($f['nilai'] ?? null),
                    'nilai_final' => null,
                    'sumber' => $f['sumber'] ?? 'unknown',
                    'keyakinan' => $f['keyakinan'] ?? null,
                    'status' => $f['status'] ?? DokumenBacaanNilai::STATUS_PERLU_REVIEW,
                    'halaman' => $f['halaman'] ?? 1,
                    'kotak' => $this->json($f['bbox'] ?? null),
                    'cocok' => null,
                    'dikoreksi_oleh' => null,
                    'dikoreksi_pada' => null,
                ];
            }

            foreach (($bagian['tabel'] ?? []) as $tabel) {
                foreach (($tabel['baris'] ?? []) as $satuBaris) {
                    foreach ($satuBaris as $sel) {
                        $baris[] = [
                            'kunci' => $sel['kunci'],
                            'jenis' => DokumenBacaanNilai::JENIS_SEL,
                            'bagian_nama' => $namaBagian,
                            // Judul kolomnya, biar barisnya masih bisa dibaca
                            // tanpa merakit ulang seluruh skemanya.
                            'label' => $tabel['kolom'][$sel['kolom']]['judul'] ?? null,
                            'baris_ke' => $sel['baris'],
                            'kolom_ke' => $sel['kolom'],
                            'tipe' => $tabel['kolom'][$sel['kolom']]['tipe'] ?? null,
                            'satuan' => null,
                            'nilai_baca' => $this->teks($sel['nilai'] ?? null),
                            'nilai_final' => null,
                            'sumber' => $sel['sumber'] ?? 'unknown',
                            'keyakinan' => $sel['keyakinan'] ?? null,
                            'status' => $sel['status'] ?? DokumenBacaanNilai::STATUS_PERLU_REVIEW,
                            'halaman' => $sel['halaman'] ?? 1,
                            'kotak' => $this->json($sel['bbox'] ?? null),
                            'cocok' => null,
                            'dikoreksi_oleh' => null,
                            'dikoreksi_pada' => null,
                        ];
                    }
                }
            }
        }

        return $baris;
    }

    /**
     * Nilai disimpan APA ADANYA sebagai teks.
     *
     * `25,4` tetap `25,4` — nggak dinormalkan jadi `25.4`, nggak dibulatkan.
     * Angka yang tampil di layar review harus sama persis dengan yang tertulis
     * di kertas, kalau nggak teknisi yang membandingkan keduanya bakal ngira
     * pembacaannya salah.
     */
    private function teks(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return is_bool($nilai) ? ($nilai ? 'true' : 'false') : (string) $nilai;
    }

    private function json(mixed $nilai): ?string
    {
        // `insert()` massal nggak lewat cast Eloquent, jadi JSON-nya dikodekan
        // di sini. Kelewat, yang masuk kolom `kotak` jadi teks "Array".
        return $nilai === null ? null : (string) json_encode($nilai);
    }
}
