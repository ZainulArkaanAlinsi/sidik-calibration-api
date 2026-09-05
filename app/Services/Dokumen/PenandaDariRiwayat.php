<?php

namespace App\Services\Dokumen;

/**
 * Tandai posisi yang riwayatnya sering salah baca sebagai WAJIB DIPERIKSA.
 *
 * ## Yang diubah cuma STATUS, nggak pernah NILAI
 *
 * Ini batas yang paling penting di seluruh berkas ini. Riwayat koreksi boleh
 * memanggil teknisi ke satu sel; dia nggak boleh menjawab menggantikan teknisi.
 * Menimpa nilai dengan koreksi lama itu cara paling rapi buat merusak data
 * diam-diam — angkanya kelihatan wajar, nggak ada yang merah, dan yang salah
 * justru sel yang dulu pernah dibetulkan dengan benar.
 *
 * Konsekuensi yang disengaja: sel yang model-nya sebenarnya SUDAH BENAR bisa
 * ikut ditandai. Itu ongkos yang murah — teknisi melihat sel yang ternyata
 * benar, lalu lanjut. Kebalikannya jauh lebih mahal: sel yang salah lolos
 * karena model kebetulan ngaku yakin.
 *
 * ## Dijalankan SEBELUM skema dibangun
 *
 * Supaya `PembuatSkemaDinamis` menghitung ringkasannya dari data yang sudah
 * ditandai. Ditandai sesudah skema jadi, `perlu_review` di ringkasan bakal
 * lebih kecil dari jumlah sel yang benar-benar merah di layar — dan angka
 * ringkasan yang nggak cocok sama isinya bikin teknisi berhenti mempercayai
 * dua-duanya.
 */
class PenandaDariRiwayat
{
    public function __construct(private readonly RiwayatKoreksi $riwayat) {}

    /**
     * @param  array<string, mixed>  $dokumen  hasil PenguraiStrukturDokumen::urai()
     * @return array<string, mixed>
     */
    public function tandai(array $dokumen, int $organizationId): array
    {
        $pola = RiwayatKoreksi::pola(
            $dokumen['document']['worksheet_code'] ?? null,
            $dokumen['document']['revision'] ?? null,
        );

        $bermasalah = $this->riwayat->posisiBermasalah($organizationId, $pola);

        if ($bermasalah === []) {
            return $dokumen;
        }

        $ditandai = 0;

        foreach (($dokumen['sections'] ?? []) as $i => $bagian) {
            $namaBagian = $bagian['name'] ?? null;

            foreach (($bagian['fields'] ?? []) as $j => $f) {
                $tanda = RiwayatKoreksi::tanda($namaBagian, $f['label'] ?? null, null, null);

                if (! isset($bermasalah[$tanda])) {
                    continue;
                }

                $dokumen['sections'][$i]['fields'][$j]['status'] = AmbangKeyakinan::PERLU_REVIEW;
                $ditandai++;
            }

            foreach (($bagian['tables'] ?? []) as $k => $tabel) {
                foreach (($tabel['rows'] ?? []) as $r => $baris) {
                    foreach ($baris as $c => $sel) {
                        $tanda = RiwayatKoreksi::tanda(
                            $namaBagian,
                            $tabel['headers'][$sel['column']] ?? null,
                            $sel['row'],
                            $sel['column'],
                        );

                        if (! isset($bermasalah[$tanda])) {
                            continue;
                        }

                        $dokumen['sections'][$i]['tables'][$k]['rows'][$r][$c]['status']
                            = AmbangKeyakinan::PERLU_REVIEW;
                        $ditandai++;
                    }
                }
            }
        }

        if ($ditandai > 0) {
            // Dilaporkan ke teknisi, bukan dikerjakan diam-diam. Sel yang
            // tiba-tiba merah padahal angkanya kelihatan wajar bikin dia
            // curiga aplikasinya rusak — kecuali ada yang menjelaskan kenapa.
            $dokumen['warnings'][] = $ditandai.' nilai ditandai perlu diperiksa karena '
                .'posisi itu sering salah baca di lembar yang sama sebelumnya. '
                .'Angkanya TIDAK diubah — cuma diminta dicek.';
        }

        return $dokumen;
    }
}
