<?php

namespace Tests\Feature;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lembar kerja kelompok MEKANIK ikut kertas formulir lab — TAMPILAN saja.
 *
 * Label, judul bagian, kepala kolom, urutan bagian, dan tampilan kartu boleh
 * berubah supaya layar HP bisa diadu baris-per-baris dengan kertas
 * `Project-PT-Sidik/worksheet_alat_calibration/`. Yang TIDAK boleh ikut
 * berubah adalah apa yang dikirim dan disimpan: kode field, tipe & pilihan
 * nilainya, tabel (tahap/grup/simpan_ke/offset/kolom/pengulangan/baris), daftar
 * standar tercetak, dan kunci sel OCR. Label yang salah dibaca orang; kunci
 * yang bergeser dibaca server — dan yang kedua tidak menerbitkan error.
 *
 * `sidik_jari()` membuang semua yang cuma dibaca mata, lalu mengadunya ke
 * rekaman SEBELUM revisi (`tests/Fixtures/lembar-kerja-mekanik-sidik-jari.json`).
 * Field boleh pindah bagian (blok "Di luar kertas") karena kodenya global —
 * yang dicatat per field, bukan per letak.
 *
 * Merekam ulang (hanya kalau perubahan kunci memang disengaja dan sudah
 * disetujui): `REKAM_SIDIK_JARI=1 php artisan test --filter=LembarKerjaIkutKertasMekanikTest`.
 * Rekaman awal diambil dari origin/main 19c7b1a, sebelum satu label pun diubah.
 */
class LembarKerjaIkutKertasMekanikTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'Fixtures/lembar-kerja-mekanik-sidik-jari.json';

    /**
     * Kunci yang murni tampilan. Dibuang di level mana pun dia muncul.
     *
     * `grup` sengaja TIDAK di sini: di tabel dia identitas peran (kunci data),
     * dan cuma di level bagian dia panel tampilan — dibuang terpisah di sana.
     */
    private const KUNCI_TAMPILAN = [
        'label', 'judul', 'catatan', 'catatan_pengisian', 'judul_nilai', 'judul_pengulangan',
        'judul_kolom', 'tampilan', 'kartu_sejajar', 'kartu_vertikal', 'nominal_berbintang',
        'halaman', 'di_kertas', 'di_luar_kertas', 'alasan', 'keterangan_tampil',
    ];

    /**
     * Profil yang sengaja menambah BARIS bawaan supaya sama dengan baris kosong
     * di kertasnya. Barisnya bertambah di ujung — kunci baris lama tidak ada
     * yang bergeser — dan rangka geometri OCR-nya dibangkitkan ulang
     * (`ocr:rangka-geometri`, masih `terverifikasi: false`).
     */
    private const BARIS_KERTAS = [
        'dial_indicator' => ['hasil' => 15],
        'load_cell' => ['hasil' => 15],
        'proving_ring' => ['hasil' => 15],
    ];

    public function test_jumlah_baris_bawaan_sama_dengan_kertas(): void
    {
        foreach (self::BARIS_KERTAS as $kode => $perBagian) {
            $bagian = collect($this->bentuk($kode)['bagian'])->keyBy('kode');

            foreach ($perBagian as $kodeBagian => $jumlah) {
                foreach ($bagian[$kodeBagian]['tabel'] as $tabel) {
                    $this->assertCount($jumlah, $tabel['baris'], "{$kode}.{$kodeBagian}.{$tabel['grup']}");
                }
            }
        }

        // UTM SENGAJA tetap 14 (kertas 0519: 10). Mengurangi baris bawaan bisa
        // menyembunyikan baris 11–14 draf yang sudah terisi waktu dibuka ulang
        // di HP — ditunda sampai diuji di perangkat.
        foreach (collect($this->bentuk('utm')['bagian'])->firstWhere('kode', 'hasil')['tabel'] as $tabel) {
            $this->assertCount(14, $tabel['baris']);
        }
    }

    public function test_nomor_formulir_proving_ring_dari_kertasnya(): void
    {
        $this->assertSame('SIDIK-FM-CAL-0521_Rev.3', $this->bentuk('proving_ring')['kode_dokumen']);
    }

    /**
     * Blok "Di luar kertas": isinya ditandai, letaknya sesudah semua bagian
     * yang tercetak dan tepat sebelum tanda tangan.
     */
    #[DataProvider('profil')]
    public function test_isian_di_luar_kertas_ditandai_dan_di_ujung(string $kode): void
    {
        $bagian = array_values($this->bentuk($kode)['bagian']);
        $kodeBagian = array_column($bagian, 'kode');
        $iPenutup = array_search('penutup', $kodeBagian, true);
        $this->assertNotFalse($iPenutup, "{$kode}: tanpa bagian penutup.");

        foreach ($bagian as $i => $b) {
            if (($b['di_luar_kertas'] ?? false) !== true) {
                continue;
            }

            // Hanya blok "Di luar kertas" lain yang boleh berdiri di antara
            // blok ini dan penutup.
            for ($j = $i + 1; $j < $iPenutup; $j++) {
                $this->assertTrue(
                    $bagian[$j]['di_luar_kertas'] ?? false,
                    "{$kode}: bagian tercetak `{$bagian[$j]['kode']}` jatuh sesudah blok di luar kertas `{$b['kode']}`.",
                );
            }

            foreach ($b['field'] ?? [] as $f) {
                $this->assertTrue($f['di_luar_kertas'] ?? false, "{$kode}.{$b['kode']}: `{$f['kode']}` belum ditandai di_luar_kertas.");
            }
        }
    }

    /**
     * Beberapa tulisan kertas yang paling mudah tertukar — dicuplik, bukan
     * disalin semua: yang dijaga bahwa layar membaca seperti kertasnya.
     */
    public function test_kepala_kolom_dan_label_ikut_kertas(): void
    {
        // UTM / Load Cell 0519/0520: baris berkepala "UUT", bacaannya
        // "Standard Reading" — dulu "Nominal" / "Pembacaan UUT" (terbalik).
        foreach (['utm', 'load_cell'] as $kode) {
            $hasil = collect($this->bentuk($kode)['bagian'])->firstWhere('kode', 'hasil');
            $this->assertSame(['0°', '90°', '180°', '270°'], array_column($hasil['tabel'], 'judul'), $kode);
            foreach ($hasil['tabel'] as $t) {
                $this->assertSame('UUT', $t['judul_nilai'], $kode);
                $this->assertSame('Standard Reading', $t['kolom'][0]['label'], $kode);
            }
        }

        // Proving Ring 0521: "Standard ( )" di kiri, "UUT Reading" berjajar.
        foreach (collect($this->bentuk('proving_ring')['bagian'])->firstWhere('kode', 'hasil')['tabel'] as $t) {
            $this->assertSame('Standard', $t['judul_nilai']);
            $this->assertSame('UUT Reading (Div)', $t['kolom'][0]['label']);
        }

        // Flowrate 0538/0538.A: kolom durasi 20" 40" 60", baris "Set Point n".
        $uut = collect(collect($this->bentuk('flowmeter_flowrate')['bagian'])->firstWhere('kode', 'hasil')['tabel'])
            ->firstWhere('grup', 'flow_uut_pembacaan');
        $this->assertSame(['20"', '40"', '60"'], array_column($uut['kolom'], 'label'));
        $this->assertSame(['durasi_1', 'durasi_2', 'durasi_3'], array_column($uut['kolom'], 'kode'));
        $this->assertSame(['Set Point 1', 'Set Point 2', 'Set Point 3'], array_column($uut['baris'], 'label'));

        // Jangka Sorong 0527: judul Pengukuran Luar/Dalam/Kedalaman.
        $jangka = collect($this->bentuk('jangka_sorong')['bagian'])->keyBy('kode');
        $this->assertSame('Pengukuran Luar', $jangka['hasil_outside']['judul']);
        $this->assertSame('Pengukuran Dalam', $jangka['hasil_inside']['judul']);
        $this->assertSame('Pengukuran Kedalaman (< 50 mm)', $jangka['hasil_depth']['judul']);

        // Timbangan 0508/0508.A: nomor 1–7 seperti tercetak.
        $label = collect($this->bentuk('timbangan')['bagian'])->firstWhere('kode', 'identitas_alat')['field'];
        $label = array_column($label, 'label', 'kode');
        $this->assertSame('1. Name', $label['equipment.nama_alat']);
        $this->assertSame('7. Merk/Manufacturing', $label['alat_merk']);

        // Dial 0526: UP X1..X3 lalu DOWN X1..X3.
        $dial = collect($this->bentuk('dial_indicator')['bagian'])->firstWhere('kode', 'hasil')['tabel'][0];
        $this->assertSame(
            ['UP X1', 'UP X2', 'UP X3', 'DOWN X1', 'DOWN X2', 'DOWN X3'],
            array_column($dial['pengulangan_arah'], 'label'),
        );
    }

    /** @return array<string, mixed> */
    private function bentuk(string $kode): array
    {
        return app(CalibrationProfileRegistry::class)->untukKode($kode)->bentukLembarKerja();
    }

    /** @return array<string, array{string}> */
    public static function profil(): array
    {
        $kode = [
            'timbangan', 'pressure_gauge', 'vacuum_gauge', 'differential_pressure',
            'utm', 'load_cell', 'proving_ring', 'hydrometer', 'dial_indicator',
            'jangka_sorong', 'sieve', 'flowmeter_flowrate', 'flowmeter_totalizer', 'anak_timbangan',
        ];

        return array_combine($kode, array_map(static fn (string $k): array => [$k], $kode));
    }

    /**
     * Rekamannya ringkas — daftar kode field, jumlah sel, dan sha256 dari
     * proyeksi data lengkap — supaya fixture tidak jadi ratusan KB. Proyeksi
     * lengkapnya bisa ditulis ke `storage/logs/sidik-jari/` (di-gitignore) untuk diadu (`SIDIK_JARI_LENGKAP=1`)
     * kalau hash-nya meleset dan perlu dicari kunci mana yang bergeser.
     */
    #[DataProvider('profil')]
    public function test_kunci_data_lembar_tidak_berubah_oleh_revisi_tampilan(string $kode): void
    {
        $jalur = base_path('tests/'.self::FIXTURE);
        $lengkap = [
            'teknisi' => $this->sidikJari($kode, false),
            'admin' => $this->sidikJari($kode, true),
        ];

        if (getenv('SIDIK_JARI_LENGKAP')) {
            File::ensureDirectoryExists(storage_path('logs/sidik-jari'));
            File::put(
                storage_path("logs/sidik-jari/{$kode}.json"),
                json_encode($lengkap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        $sekarang = array_map(fn (array $s): array => $this->ringkas($s), $lengkap);

        if (getenv('REKAM_SIDIK_JARI')) {
            $semua = File::exists($jalur) ? json_decode(File::get($jalur), true) : [];
            $semua[$kode] = $sekarang;
            ksort($semua);
            File::put($jalur, json_encode($semua, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestSkipped("Sidik jari {$kode} direkam ulang.");
        }

        $rekaman = json_decode(File::get($jalur), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey($kode, $rekaman, "Belum ada rekaman sidik jari {$kode}.");

        foreach (['teknisi', 'admin'] as $untuk) {
            // Kode field dulu: itu yang paling sering bergeser, dan pesannya
            // langsung menyebut kode mana.
            $this->assertSame(
                $rekaman[$kode][$untuk]['field'],
                $sekarang[$untuk]['field'],
                "Kode field lembar {$kode} ({$untuk}) berubah.",
            );
            $this->assertSame(
                $rekaman[$kode][$untuk]['tabel'],
                $sekarang[$untuk]['tabel'],
                "Identitas tabel lembar {$kode} ({$untuk}) berubah.",
            );
            $this->assertSame(
                $rekaman[$kode][$untuk]['jumlah_sel'],
                $sekarang[$untuk]['jumlah_sel'],
                "Jumlah sel OCR lembar {$kode} ({$untuk}) berubah.",
            );
            $this->assertSame(
                $rekaman[$kode][$untuk]['sha256'],
                $sekarang[$untuk]['sha256'],
                "Kunci data lembar {$kode} ({$untuk}) berubah — tipe/pilihan/tabel/standar/kunci sel. "
                .'Revisi ikut-kertas cuma boleh menyentuh tampilan. Tulis proyeksi lengkapnya dengan '
                .'SIDIK_JARI_LENGKAP=1 lalu adu ke versi sebelum perubahan.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $sidikJari
     * @return array{field: list<string>, tabel: list<string>, jumlah_sel: int, sha256: string}
     */
    private function ringkas(array $sidikJari): array
    {
        return [
            'field' => array_keys($sidikJari['field']),
            'tabel' => array_keys($sidikJari['tabel']),
            'jumlah_sel' => count($sidikJari['sel']),
            'sha256' => hash('sha256', json_encode($sidikJari, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * Proyeksi DATA dari bentuk lembar: yang dikirim/disimpan/dipotong, bukan
     * yang dibaca mata.
     *
     * @return array<string, mixed>
     */
    private function sidikJari(string $kode, bool $admin): array
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);
        $this->assertNotNull($profil, $kode);

        $bentuk = $profil->bentukLembarKerja($admin);

        $field = [];
        $tabel = [];
        $bagian = [];

        foreach ($bentuk['bagian'] ?? [] as $b) {
            foreach (['field', 'field_di_luar_kertas'] as $kunciField) {
                foreach ($b[$kunciField] ?? [] as $f) {
                    $field[(string) $f['kode']][] = $this->buang($f);
                }
            }

            // Tabel dicatat per IDENTITASNYA, bukan per letak: kunci barisnya
            // ditentukan tahap/grup/simpan_ke/offset, bukan bagian tempat dia
            // digambar — jadi tabel yang pindah ke blok "Di luar kertas" tidak
            // mengubah apa pun yang dikirim.
            foreach ($b['tabel'] ?? [] as $t) {
                $id = implode('|', [
                    $t['tahap'] ?? '', $t['grup'] ?? '', $t['simpan_ke'] ?? '', $t['offset_kunci'] ?? '',
                ]);
                $tabel[$id][] = $this->buang($t);
            }

            $sisa = $b;
            unset($sisa['field'], $sisa['field_di_luar_kertas'], $sisa['tabel'], $sisa['kode'], $sisa['grup']);
            $baris = $sisa['baris'] ?? null;
            unset($sisa['baris']);
            $sisa = $this->buang($sisa);

            // Baris bagian = daftar standar tercetak. Labelnya IDENTITAS
            // standar (pencocok ke master), jadi disimpan utuh.
            if ($baris !== null) {
                $sisa['baris'] = $baris;
            }

            if ($sisa !== []) {
                $bagian[(string) $b['kode']] = $sisa;
            }
        }

        ksort($field);
        ksort($tabel);
        ksort($bagian);

        $akar = $bentuk;
        unset($akar['bagian'], $akar['budget_ketidakpastian'], $akar['tanpa_keputusan']);

        $template = app(TemplateLembarKerja::class)->untukKode($kode);
        $sel = array_keys($template['sel'] ?? []);
        sort($sel);

        return [
            'akar' => $this->buang($akar),
            'bagian' => $bagian,
            'field' => $field,
            'tabel' => $tabel,
            'sel' => $sel,
        ];
    }

    private function buang(mixed $nilai): mixed
    {
        if (! is_array($nilai)) {
            return $nilai;
        }

        $hasil = [];

        foreach ($nilai as $k => $v) {
            if (is_string($k) && in_array($k, self::KUNCI_TAMPILAN, true)) {
                continue;
            }

            $hasil[$k] = $this->buang($v);
        }

        return $hasil;
    }
}
