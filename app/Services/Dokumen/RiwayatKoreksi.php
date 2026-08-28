<?php

namespace App\Services\Dokumen;

use App\Models\DokumenBacaanNilai;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat koreksi teknisi -> "posisi mana di lembar ini yang sering salah baca".
 *
 * ## Yang BOLEH dilakukan riwayat, dan yang TIDAK
 *
 * Yang TIDAK: mengganti nilai bacaan baru dengan koreksi lama. Kelihatan
 * pintar, tapi itu cara paling rapi buat merusak data diam-diam — teknisi
 * pernah membetulkan `7,2` jadi `7,02` di SATU lembar, dan menerapkannya buta
 * ke lembar berikutnya menimpa angka yang sebenarnya sudah benar. Nggak ada
 * yang merah, nggak ada yang tahu, dan angkanya mendarat di sertifikat.
 *
 * Yang BOLEH: menaikkan kecurigaan. Kalau satu posisi di pola lembar yang sama
 * sudah berkali-kali salah baca, sel itu ditandai WAJIB DIPERIKSA — walau model
 * kali ini ngaku yakin. Nilainya nggak disentuh sama sekali; yang berubah cuma
 * apakah manusia diminta melihatnya.
 *
 * Bedanya menentukan: yang pertama mengambil keputusan menggantikan teknisi,
 * yang kedua justru memanggil teknisi ke tempat yang paling butuh dia.
 *
 * ## Identitas posisi: kenapa BUKAN `kunci`
 *
 * `kunci` (`bagian-0.tabel-0.sel-0-1`) stabil DI DALAM satu pembacaan, tapi
 * nggak dijamin stabil ANTAR pembacaan: model bisa mengembalikan urutan bagian
 * yang beda, atau memecah satu tabel jadi dua. Begitu itu terjadi,
 * `bagian-0.field-2` menunjuk sel yang sama sekali lain, dan riwayatnya
 * nyasar ke posisi yang salah — persis kesalahan yang bikin fitur ini lebih
 * berbahaya daripada nggak ada.
 *
 * Yang dipakai: nama bagian + label + baris + kolom. Itu juga yang diminta
 * spesifikasinya — pola dokumen, konteks field, dan konteks spasial.
 *
 * ## Disaring per organisasi
 *
 * Statistik agregat tetap membocorkan informasi, dan tulisan tangan teknisi
 * lab lain juga bukan bukti apa pun soal lembar di sini.
 */
class RiwayatKoreksi
{
    /**
     * Berapa kali satu posisi minimal harus pernah dibaca sebelum riwayatnya
     * dipercaya.
     *
     * Tanpa lantai ini, SATU koreksi bikin posisi itu ditandai selamanya —
     * dan koreksi pertama sering justru salah ketik teknisi, bukan salah baca
     * model.
     */
    private const MINIMAL_RIWAYAT = 3;

    /** Sesering apa harus meleset sebelum dianggap posisi bermasalah. */
    private const AMBANG_MELESET = 0.5;

    /**
     * Identitas pola lembar: kode dokumen + revisi, dirapikan.
     *
     * `null` kalau kodenya nggak kebaca — dan itu BUKAN pola. Dua lembar yang
     * sama-sama nggak berkode belum tentu lembar yang sama, jadi riwayatnya
     * nggak boleh dicampur.
     */
    public static function pola(?string $kodeDokumen, ?string $revisi): ?string
    {
        $rapikan = static fn (?string $s): string => preg_replace(
            '/[^a-z0-9]+/', '', strtolower((string) $s),
        ) ?? '';

        $kode = $rapikan($kodeDokumen);

        if ($kode === '') {
            return null;
        }

        return $kode.'|'.$rapikan($revisi);
    }

    /**
     * Posisi mana saja di pola ini yang sudah terbukti sering salah baca.
     *
     * @return array<string, array{dibaca: int, meleset: int}> kunci = [tanda]
     */
    public function posisiBermasalah(int $organizationId, ?string $pola): array
    {
        if ($pola === null) {
            return [];
        }

        // Lewat join ke induknya yang tersaring organisasi — `dokumen_bacaan_nilai`
        // nggak punya `organization_id` sendiri, jadi dikueri dari akarnya itu
        // query lintas-organisasi walau nggak ada kolom yang kelihatan salah.
        $baris = DokumenBacaanNilai::query()
            ->join(
                'dokumen_bacaan',
                'dokumen_bacaan.id',
                '=',
                'dokumen_bacaan_nilai.dokumen_bacaan_id',
            )
            ->where('dokumen_bacaan.organization_id', $organizationId)
            ->where('dokumen_bacaan.pola', $pola)
            // Cuma yang BENAR-BENAR sudah diperiksa teknisi yang dihitung.
            // `cocok` null artinya dia nggak pernah melihat selnya — dan
            // "belum diperiksa" bukan bukti apa pun soal benar atau salah.
            ->whereNotNull('dokumen_bacaan_nilai.cocok')
            ->groupBy(
                'dokumen_bacaan_nilai.bagian_nama',
                'dokumen_bacaan_nilai.label',
                'dokumen_bacaan_nilai.baris_ke',
                'dokumen_bacaan_nilai.kolom_ke',
            )
            ->get([
                'dokumen_bacaan_nilai.bagian_nama',
                'dokumen_bacaan_nilai.label',
                'dokumen_bacaan_nilai.baris_ke',
                'dokumen_bacaan_nilai.kolom_ke',
                DB::raw('COUNT(*) as dibaca'),
                DB::raw('SUM(CASE WHEN dokumen_bacaan_nilai.cocok = 0 THEN 1 ELSE 0 END) as meleset'),
            ]);

        $hasil = [];

        foreach ($baris as $b) {
            $dibaca = (int) $b->dibaca;
            $meleset = (int) $b->meleset;

            if ($dibaca < self::MINIMAL_RIWAYAT) {
                continue;
            }

            if ($meleset / $dibaca <= self::AMBANG_MELESET) {
                continue;
            }

            $hasil[self::tanda(
                $b->bagian_nama,
                $b->label,
                $b->baris_ke === null ? null : (int) $b->baris_ke,
                $b->kolom_ke === null ? null : (int) $b->kolom_ke,
            )] = ['dibaca' => $dibaca, 'meleset' => $meleset];
        }

        return $hasil;
    }

    /**
     * Identitas satu posisi di lembar — yang dipakai mencocokkan riwayat.
     */
    public static function tanda(
        ?string $bagian,
        ?string $label,
        ?int $baris,
        ?int $kolom,
    ): string {
        return implode('|', [
            (string) $bagian,
            (string) $label,
            $baris === null ? '' : (string) $baris,
            $kolom === null ? '' : (string) $kolom,
        ]);
    }
}
