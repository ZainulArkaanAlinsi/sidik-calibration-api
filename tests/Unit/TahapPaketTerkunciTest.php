<?php

namespace Tests\Unit;

use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\OrderItem;
use App\Services\TahapPaket;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KUNCI urutan tahap dan label pelanggan -- penjaga salinan Dart.
 *
 * ## Kenapa ini terkunci
 *
 * Aplikasi Pelanggan (repo `sidik-pelanggan-mobile`) menyimpan SALINAN urutan
 * tahap di `lib/models/garis_tahap_pelanggan.dart` (enam langkah, tujuh saat
 * "Sedang dikerjakan ulang") dan mem-portasi `TahapPaket::garisWaktu` di
 * `test/beranda_jejak_test.dart` (`_garisServer`). Salinan itu dipakai selama
 * aplikasi belum membaca `sedang_dikerjakan.garis_waktu` dari server (build
 * terpasang yang lama) dan sebagai cadangan bila kuncinya tidak ada. Mengubah
 * URUTAN atau LABEL di sini tanpa mengubah salinan itu membuat dua tampilan
 * menggambar jejak yang berbeda untuk alat yang sama -- dan itu TIDAK
 * menghasilkan error di mana pun.
 *
 * Tes ini sengaja memakai nilai TERKETIK (bukan dihitung dari konstanta kelas):
 * perubahan apa pun pada `TahapPaket::URUTAN`, `LABEL_PELANGGAN`, atau
 * `garisWaktu()` membuatnya merah, dan orang yang mengubahnya diminta memberi
 * tahu pemilik app Pelanggan DAN memperbarui nilai terkunci di sini (serta
 * `docs/kontrak-api-pelanggan.md` §15).
 *
 * ## Kode tahap yang tidak dikenal
 *
 * Perilaku terdokumentasi: `garisWaktu()` menandai langkah PERTAMA
 * (`diterima`) sebagai `sekarang` dan tidak menandai satu pun `lewat`. Kolom
 * `order_items.tahap_fisik` berupa enum (`siap_diambil`, `diserahkan`), jadi
 * kode asing hanya bisa lahir dari perubahan `TahapPaket` itu sendiri -- tes
 * `garisWaktu_kode_tak_dikenal_...` mengunci apa yang terjadi saat itu.
 *
 * Ini SENGAJA berbeda dari aplikasi Pelanggan: salinan Dart tidak menandai
 * langkah mana pun sebagai `sekarang` untuk kode asing (aplikasi tidak
 * mengarang tik "sekarang"). Server yang mengirim `garis_waktu` memakai
 * perilaku di atas; salinan Dart hanya cadangan.
 *
 * ## Asumsi prasaring `RingkasanBerandaPelanggan::paketSiapDiambil`
 *
 * `siap_diambil` dan `diserahkan` hanya lahir dari `tahap_fisik`, tidak pernah
 * diturunkan dari sesi/sertifikat. Prasaring SQL `whereHas('items', tahap_fisik
 * = siap_diambil)` bersandar pada itu; tes
 * `siap_diambil_dan_diserahkan_hanya_lahir_dari_tahap_fisik` mengunci asumsinya.
 */
class TahapPaketTerkunciTest extends TestCase
{
    private const PESAN = 'TahapPaket berubah: BERI TAHU app Pelanggan (salinan Dart di '
        .'`sidik-pelanggan-mobile` lib/models/garis_tahap_pelanggan.dart dan portasi `_garisServer` di '
        .'test/beranda_jejak_test.dart), lalu perbarui nilai terkunci di tes ini dan docs/kontrak-api-pelanggan.md §15.';

    /** @var list<string> */
    private const URUTAN_TERKUNCI = [
        'diterima',
        'dikalibrasi',
        'perlu_diulang',
        'menunggu_pemeriksaan',
        'menunggu_pengesahan',
        'sertifikat_terbit',
        'siap_diambil',
        'diserahkan',
    ];

    /** @var array<string, string> */
    private const LABEL_TERKUNCI = [
        'diterima' => 'Alat diterima lab',
        'dikalibrasi' => 'Sedang dikalibrasi',
        'perlu_diulang' => 'Sedang dikerjakan ulang',
        'menunggu_pemeriksaan' => 'Hasil sedang diperiksa',
        'menunggu_pengesahan' => 'Hasil sedang diperiksa',
        'sertifikat_terbit' => 'Sertifikat sudah terbit',
        'siap_diambil' => 'Siap diambil',
        'diserahkan' => 'Sudah diserahkan',
    ];

    /**
     * Langkah yang dilihat pelanggan, urut. `menunggu_pengesahan` TIDAK ada
     * (dilipat ke pemeriksaan); `perlu_diulang` hanya muncul saat sedang terjadi.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const LANGKAH_DASAR = [
        ['diterima', 'Alat diterima lab'],
        ['dikalibrasi', 'Sedang dikalibrasi'],
        ['menunggu_pemeriksaan', 'Hasil sedang diperiksa'],
        ['sertifikat_terbit', 'Sertifikat sudah terbit'],
        ['siap_diambil', 'Siap diambil'],
        ['diserahkan', 'Sudah diserahkan'],
    ];

    /** Jejak yang diharapkan, dibangun dari tabel terketik di atas (bukan dari TahapPaket). */
    private function harapan(string $sekarang, bool $denganUlang = false): array
    {
        $langkah = self::LANGKAH_DASAR;

        if ($denganUlang) {
            array_splice($langkah, 2, 0, [['perlu_diulang', 'Sedang dikerjakan ulang']]);
        }

        $indeks = array_search($sekarang, array_column($langkah, 0), true);
        $this->assertNotFalse($indeks, "`{$sekarang}` tidak ada di jejak terketik (salah tulis tes).");

        return array_map(fn (array $baris, int $i): array => [
            'kode' => $baris[0],
            'label' => $baris[1],
            'lewat' => $i < $indeks,
            'sekarang' => $i === $indeks,
        ], $langkah, array_keys($langkah));
    }

    #[Test]
    public function urutan_tahap_terkunci(): void
    {
        $this->assertSame(self::URUTAN_TERKUNCI, TahapPaket::URUTAN, self::PESAN);
    }

    #[Test]
    public function label_pelanggan_terkunci_beserta_urutan_kuncinya(): void
    {
        $this->assertSame(self::LABEL_TERKUNCI, TahapPaket::LABEL_PELANGGAN, self::PESAN);
        $this->assertSame(self::URUTAN_TERKUNCI, array_keys(TahapPaket::LABEL_PELANGGAN), self::PESAN);
    }

    #[Test]
    public function label_pelanggan_tidak_memuat_istilah_internal(): void
    {
        foreach (TahapPaket::LABEL_PELANGGAN as $kode => $label) {
            foreach (['pengesahan', 'disahkan', 'ditolak', 'admin', 'revisi'] as $internal) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $internal,
                    $label,
                    "Label pelanggan `{$kode}` memuat istilah internal `{$internal}`.",
                );
            }
        }
    }

    #[Test]
    public function jejak_pelanggan_untuk_tiap_kode_terkunci(): void
    {
        $tahap = new TahapPaket;

        foreach (self::URUTAN_TERKUNCI as $kode) {
            // Pengesahan dilipat ke pemeriksaan; sisanya apa adanya.
            $dilihat = $kode === 'menunggu_pengesahan' ? 'menunggu_pemeriksaan' : $kode;

            $this->assertSame(
                $this->harapan($dilihat, denganUlang: $kode === 'perlu_diulang'),
                $tahap->garisWaktu($kode, untukPelanggan: true),
                "garisWaktu(`{$kode}`, pelanggan) berubah. ".self::PESAN,
            );
        }
    }

    #[Test]
    public function jejak_menunggu_pemeriksaan_terkunci_sebagai_json_terketik(): void
    {
        // Bentuk dan URUTAN KUNCI persis seperti yang dibaca aplikasi.
        $harap = '[{"kode":"diterima","label":"Alat diterima lab","lewat":true,"sekarang":false},'
            .'{"kode":"dikalibrasi","label":"Sedang dikalibrasi","lewat":true,"sekarang":false},'
            .'{"kode":"menunggu_pemeriksaan","label":"Hasil sedang diperiksa","lewat":false,"sekarang":true},'
            .'{"kode":"sertifikat_terbit","label":"Sertifikat sudah terbit","lewat":false,"sekarang":false},'
            .'{"kode":"siap_diambil","label":"Siap diambil","lewat":false,"sekarang":false},'
            .'{"kode":"diserahkan","label":"Sudah diserahkan","lewat":false,"sekarang":false}]';

        $this->assertSame($harap, json_encode((new TahapPaket)->garisWaktu('menunggu_pemeriksaan', true)), self::PESAN);
    }

    #[Test]
    public function jejak_pelanggan_enam_langkah_dan_tujuh_hanya_saat_dikerjakan_ulang(): void
    {
        $tahap = new TahapPaket;

        foreach (['diterima', 'dikalibrasi', 'menunggu_pemeriksaan', 'menunggu_pengesahan', 'sertifikat_terbit', 'siap_diambil', 'diserahkan'] as $kode) {
            $this->assertCount(6, $tahap->garisWaktu($kode, true), "`{$kode}` harus enam langkah. ".self::PESAN);
        }

        $ulang = $tahap->garisWaktu('perlu_diulang', true);
        $this->assertCount(7, $ulang, self::PESAN);
        $this->assertSame(
            ['diterima', 'dikalibrasi', 'perlu_diulang', 'menunggu_pemeriksaan', 'sertifikat_terbit', 'siap_diambil', 'diserahkan'],
            array_column($ulang, 'kode'),
            self::PESAN,
        );
        $this->assertSame(['perlu_diulang'], array_column(array_filter($ulang, fn (array $l): bool => $l['sekarang']), 'kode'));
    }

    #[Test]
    public function pengesahan_tidak_pernah_terlihat_di_jejak_pelanggan_dan_dilipat_ke_pemeriksaan(): void
    {
        $tahap = new TahapPaket;

        $this->assertSame(
            $tahap->garisWaktu('menunggu_pemeriksaan', true),
            $tahap->garisWaktu('menunggu_pengesahan', true),
            self::PESAN,
        );
        $this->assertNotContains('menunggu_pengesahan', array_column($tahap->garisWaktu('menunggu_pengesahan', true), 'kode'), self::PESAN);

        // Lipatan tahap di `/paket` dan `sedang_dikerjakan` (satu fungsi).
        foreach (self::URUTAN_TERKUNCI as $kode) {
            $this->assertSame(
                $kode === 'menunggu_pengesahan' ? 'menunggu_pemeriksaan' : $kode,
                PaketPelangganResource::untukPelanggan($kode),
                "untukPelanggan(`{$kode}`) berubah. ".self::PESAN,
            );
        }
    }

    #[Test]
    public function siap_diambil_dan_diserahkan_hanya_lahir_dari_tahap_fisik(): void
    {
        $tahap = new TahapPaket;
        $statusSesi = [
            null, // belum ada sesi sama sekali
            CalibrationSession::STATUS_DRAFT,
            CalibrationSession::STATUS_PERLU_REVISI,
            CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
            CalibrationSession::STATUS_DISETUJUI,
            'status_yang_belum_ada',
        ];
        $statusSertifikat = [null, Certificate::STATUS_TERBIT, Certificate::STATUS_MENUNGGU_GENERATE, Certificate::STATUS_GAGAL, Certificate::STATUS_DIBATALKAN];

        foreach ($statusSesi as $sesi) {
            foreach ($statusSertifikat as $sertifikat) {
                $item = new OrderItem;
                if ($sesi !== null) {
                    $model = (new CalibrationSession)->forceFill(['status' => $sesi]);
                    $model->setRelation('certificate', $sertifikat === null ? null : (new Certificate)->forceFill(['status' => $sertifikat]));
                    $item->setRelation('sesiTerakhir', $model);
                } else {
                    $item->setRelation('sesiTerakhir', null);
                }

                $this->assertNotContains(
                    $tahap->untukItem($item),
                    [TahapPaket::SIAP_DIAMBIL, TahapPaket::DISERAHKAN],
                    'Tahap fisik diturunkan dari sesi `'.($sesi ?? 'tanpa sesi').'`/sertifikat `'.($sertifikat ?? 'tanpa').'`: prasaring SQL `paketSiapDiambil` ikut salah.',
                );
            }
        }

        foreach ([TahapPaket::SIAP_DIAMBIL, TahapPaket::DISERAHKAN] as $fisik) {
            $item = (new OrderItem)->forceFill(['tahap_fisik' => $fisik]);
            $item->setRelation('sesiTerakhir', null);
            $this->assertSame($fisik, $tahap->untukItem($item));
        }
    }

    #[Test]
    public function garisWaktu_kode_tak_dikenal_menandai_diterima_sebagai_sekarang(): void
    {
        $tahap = new TahapPaket;

        foreach (['kode_baru_dari_masa_depan', '', 'DITERIMA'] as $asing) {
            $jejak = $tahap->garisWaktu($asing, true);

            // Perilaku terdokumentasi: kode asing diperlakukan seperti `diterima`.
            $this->assertSame($this->harapan('diterima'), $jejak, "Kode asing `{$asing}` tidak lagi jatuh ke `diterima`. ".self::PESAN);
            $this->assertSame('diterima', collect($jejak)->firstWhere('sekarang', true)['kode']);
            $this->assertSame([], array_filter($jejak, fn (array $l): bool => $l['lewat']));
        }
    }
}
