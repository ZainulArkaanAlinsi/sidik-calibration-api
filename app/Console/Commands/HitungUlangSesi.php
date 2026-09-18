<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use App\Services\GumCalculator;
use App\Services\RumusKalibrasi;
use App\Support\AnakTimbanganMentah;
use App\Support\Angka;
use App\Support\DialIndicatorMentah;
use App\Support\FlowmeterMentah;
use App\Support\GridSensorMentah;
use App\Support\HeightGaugeMentah;
use App\Support\HydrometerMentah;
use App\Support\JangkaSorongMentah;
use App\Support\MicrometerMentah;
use App\Support\PasanganStandarUutMentah;
use App\Support\SieveMentah;
use App\Support\TimbanganMentah;
use App\Support\WaktuMentah;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hitung ulang satu sesi dari PEMBACAAN MENTAH yang tersimpan.
 *
 * ## Kenapa perlu, dan kenapa bukan lewat API
 *
 * Sesi yang sudah disetujui tidak bisa ditolak lagi (`reject` menolak apa pun
 * di luar `menunggu_approval`) dan tidak bisa di-`PUT` — dua-duanya penjagaan
 * yang benar: sertifikatnya sudah terbit dan sudah bisa dipegang pelanggan.
 *
 * Tapi itu meninggalkan lubang: **kalau angkanya ternyata salah, tidak ada
 * jalan membetulkannya sama sekali.** Kejadian nyata `CAL/2026/08/0043` — satu
 * pembacaan salah ketik, U95 tercetak 212x CMC lab, dan satu-satunya jalan
 * yang tersisa adalah menyunting database dengan tangan.
 *
 * Perintah ini jalan itu, dengan empat pembatas:
 *
 *  1. **Nggak mengarang angka.** Yang dihitung ulang cuma
 *     `uncertainty_calculations`, dari `raw_measurements` yang SUDAH ada. Kalau
 *     pembacaannya sendiri yang salah, betulkan dulu pembacaannya — perintah
 *     ini nggak akan menutupi itu.
 *  2. **Lewat jalur hitung yang SAMA** dengan `POST /calibrations`, bukan
 *     salinan rumus kedua. Dua implementasi berarti dua jawaban yang bisa
 *     berbeda diam-diam, dan buat lab terakreditasi itu temuan audit.
 *  3. **Nggak menyentuh sertifikat.** Sesudah ini jalankan
 *     `sertifikat:bangun-ulang` supaya snapshot & PDF-nya ikut — dipisah
 *     sengaja: menghitung ulang itu keputusan teknis, menerbitkan ulang
 *     dokumen itu keputusan mutu.
 *  4. **Nggak pernah mengganti hasil dengan kekosongan.** Penulisannya hapus
 *     lalu tulis ulang, jadi hitung ulang yang pulang tanpa titik sama sekali
 *     bakal mengosongkan sesi yang sertifikatnya sudah terbit. Kalau itu yang
 *     terjadi perintah ini berhenti sebelum menghapus; kalau cuma sebagian
 *     titik yang hilang, dia menyebut titiknya dan minta jawaban dulu.
 */
class HitungUlangSesi extends Command
{
    protected $signature = 'kalibrasi:hitung-ulang
        {sesi* : id atau nomor sesi}
        {--dry-run : cuma tampilkan yang bakal berubah}';

    protected $description = 'Hitung ulang hasil GUM satu sesi dari pembacaan mentahnya';

    public function handle(
        CalibrationProfileRegistry $profil,
        GumCalculator $gum,
        RumusKalibrasi $rumus,
        CalibrationValidator $validator,
    ): int {
        $kering = (bool) $this->option('dry-run');
        $gagal = 0;

        foreach ((array) $this->argument('sesi') as $kunci) {
            $sesi = CalibrationSession::with([
                'equipment', 'rawMeasurements.standard', 'uncertaintyCalculations',
            ])->where('id', $kunci)->orWhere('nomor_sesi', $kunci)->first();

            if ($sesi === null) {
                $this->error("Sesi `{$kunci}` nggak ketemu.");
                $gagal++;

                continue;
            }

            $alat = $sesi->equipment;

            if ($alat === null) {
                $this->error("{$sesi->nomor_sesi}: sesinya nggak punya alat, nggak bisa dihitung.");
                $gagal++;

                continue;
            }

            // Pembacaan mentah → bentuk yang dimengerti profil, persis seperti
            // yang disusun `CalibrationController::susunPengukuran()` dari
            // payload. Yang dipakai cuma tahap SESUDAH adjustment: as-found itu
            // dokumentasi kondisi awal alat, bukan dasar sertifikat.
            $siapHitung = [];

            foreach ($sesi->rawMeasurements->where('tahap', 'sesudah_adjustment')
                ->groupBy('titik_ke') as $titikKe => $baris) {
                // Grid enclosure, disusun ulang dari `sensor_ke`/`peran_sensor`/
                // `channel`. Kosong buat sepuluh alat lain — profilnya nggak
                // pernah menengok kunci ini.
                $grid = GridSensorMentah::dari($baris);

                // Pasangan standar/UUT ketiga alat suhu. Sama seperti grid di
                // atas, bentuk ini nggak punya deret datar — meratakannya bikin
                // angka standar & UUT campur aduk, dan koreksi yang lahir dari
                // situ nggak berarti apa-apa. Kosong buat lima belas alat lain.
                $pasangan = PasanganStandarUutMentah::dari($baris);

                // Blok Timbangan. Dites SEBELUM `$pasangan`/`$grid` dengan
                // alasan yang persis sama dengan yang sudah ditulis di bawah:
                // baris Timbangan PUNYA `peran_sensor`, cuma kosakatanya lain
                // lagi (`z1`/`m`/`m_aksen`/`z2`/`nominal`). Diperiksa
                // belakangan, tiap titiknya jatuh ke cabang alat lain, ketemu
                // deret kosong, lalu di-`continue` — perintahnya "sukses"
                // tanpa menghitung apa pun.
                $timbangan = TimbanganMentah::dari($baris);

                // Dua deret waktu lembar Timer/Stopwatch. Diperiksa dengan
                // alasan yang persis sama dengan Timbangan di atas: barisnya
                // PUNYA `peran_sensor`, cuma kosakatanya lain lagi
                // (`waktu_standar`/`waktu_uut`). Kalau tidak dites duluan, tiap
                // titiknya jatuh ke cabang alat lain, ketemu deret yang bukan
                // miliknya, dan angkanya salah tanpa satu pun error.
                $waktu = WaktuMentah::dari($baris);

                // Tumpukan balok ukur + deret pembacaan lembar Micrometer.
                // Diperiksa dengan alasan yang persis sama dengan Timbangan &
                // Timer di atas: barisnya PUNYA `peran_sensor`, cuma
                // kosakatanya lain lagi (`mikro_balok`/`mikro_pembacaan`).
                // Kalau tidak dites duluan, tiap titiknya jatuh ke cabang alat
                // lain, ketemu deret yang bukan miliknya, dan angkanya salah
                // tanpa satu pun error.
                $mikro = MicrometerMentah::dari($baris);

                // Slot nominal + deret pembacaan lembar Height Gauge.
                // Diperiksa dengan alasan yang persis sama dengan tiga di atas:
                // barisnya PUNYA `peran_sensor`, cuma kosakatanya lain lagi
                // (`hg_nominal`/`hg_pembacaan`). Kalau tidak dites duluan, tiap
                // titiknya jatuh ke cabang alat lain, ketemu deret yang bukan
                // miliknya, dan angkanya salah tanpa satu pun error.
                $heightGauge = HeightGaugeMentah::dari($baris);

                // Deret UUT + standar + suhu air + densitas lembar Flowmeter,
                // DUA varian metode sekaligus: varian UFM memungut
                // `flow_std_pembacaan`, varian gravimetri (ISO 4185) memungut
                // `flow_berat_isi`/`flow_berat_kosong`/`flow_waktu_menit`.
                // `FlowmeterMentah::dari()` memulangkan kedelapan kunci apa pun
                // variannya — yang tidak dipakai pulang deret kosong, bukan
                // kunci yang hilang; profil yang memilih cabangnya dari
                // `spesifikasi_alat.flowmeter.varian_metode`.
                //
                // Diperiksa dengan alasan yang persis sama dengan empat di
                // atas: barisnya PUNYA `peran_sensor`, cuma kosakatanya lain
                // lagi. Kalau tidak dites duluan, tiap titiknya jatuh ke cabang
                // alat lain, ketemu deret yang bukan miliknya, dan angkanya
                // salah tanpa satu pun error.
                $flow = FlowmeterMentah::dari($baris);

                // Empat penimbangan ABBA satu keping Anak Timbangan.
                // Diperiksa dengan alasan yang persis sama dengan lima di atas:
                // barisnya PUNYA `peran_sensor`, cuma kosakatanya lain lagi
                // (`at_s1`/`at_t1`/`at_t2`/`at_s2`). Kalau tidak dites duluan,
                // tiap titiknya jatuh ke cabang alat lain, ketemu deret yang
                // bukan miliknya, dan angkanya salah tanpa satu pun error.
                $anakTimbangan = AnakTimbanganMentah::dari($baris);

                // Tumpukan balok ukur + penunjukan UP/DOWN lembar Dial
                // Indicator (`di_balok`/`di_pembacaan`). Diperiksa DULUAN dengan
                // alasan yang sama dengan tujuh di atas — kejadian KETIGA BELAS.
                $dial = DialIndicatorMentah::dari($baris);

                // Opening Sieve Mesh (`sieve_*`, `sensor_ke` = nomor opening) dan
                // tiga tabel Jangka Sorong (`js_<grup>_*`). Diperiksa DULUAN
                // dengan alasan yang sama — kejadian ke-14 & ke-15.
                $sieve = SieveMentah::dari($baris);
                $jangkaSorong = JangkaSorongMentah::dari($baris);

                // Deret massa + deret suhu air lembar Hydrometer
                // (`hydro_massa`/`hydro_suhu`). Kejadian ke-16 dengan pola yang
                // sama. Dia TIDAK ikut rantai `elseif` di bawah: `$nilai`
                // jalur datar memang tidak dipakai alat ini, dan profilnya
                // membaca kedua deret dari `konteks` — sama seperti Micrometer
                // & Height Gauge yang juga lewat `hitungPerGrup()`.
                $hydro = HydrometerMentah::dari($baris);

                // Pasangan DILIHAT DULUAN, dan urutannya bukan selera.
                // [GridSensorMentah] balik `[]` cuma kalau nggak ada satu pun
                // baris ber-`peran_sensor` — dan baris ketiga alat suhu PUNYA
                // `peran_sensor`, cuma kosakatanya lain (`standar`/`uut`, bukan
                // `termokopel`/`indikator`). Jadi buat sesi suhu dia balik
                // `['sensor_grid' => [], 'indikator' => []]`, yang secara PHP
                // bukan `[]`. Dites duluan pakai `$grid === []`, ketiga alat
                // suhu jatuh ke cabang enclosure, ketemu grid kosong, lalu tiap
                // titiknya di-`continue` — perintahnya "sukses" tanpa
                // menghitung apa pun, dan angkanya kelihatan utuh karena
                // memang nggak pernah disentuh.
                if ($sieve !== []) {
                    // Minimum opening, opening 1..6, dan MPE ditegakkan
                    // kalkulatornya tingkat SESI dengan alasan kebaca — menahan
                    // di sini membuat grup yang tertahan hilang tanpa alasan.
                    if (array_sum(array_map('count', $sieve)) === 0) {
                        continue;
                    }

                    $nilai = [];
                } elseif ($jangkaSorong !== []) {
                    // Gerbangnya PEMBACAAN: titik yang cuma punya nominal
                    // melahirkan "koreksi" sebesar total nominalnya.
                    if (count($jangkaSorong[JangkaSorongMentah::KONTEKS_PEMBACAAN] ?? []) < 1) {
                        continue;
                    }

                    $nilai = [];
                } elseif ($dial !== []) {
                    // Gerbangnya PENUNJUKAN, bukan jumlah baris: titik yang
                    // cuma punya keping balok tanpa satu penunjukan pun
                    // melahirkan "koreksi" sebesar total nominalnya.
                    if ($dial[DialIndicatorMentah::PERAN_PEMBACAAN] === []) {
                        continue;
                    }

                    $nilai = [];
                } elseif ($anakTimbangan !== []) {
                    // Gerbangnya KEEMPAT peran, bukan jumlah baris: satu keping
                    // berisi sampai 12 baris (4 peran x 3 pengulangan), jadi
                    // hitungan datar tetap lolos walau satu peran kosong — dan
                    // `de` yang lahir dari peran yang kosong justru sebesar
                    // seluruh pembacaan sisanya, angka yang kelihatan masuk akal.
                    $kurangPeran = false;

                    foreach (AnakTimbanganMentah::PERAN_URUT as $peran) {
                        if (($anakTimbangan[$peran] ?? null) === null) {
                            $kurangPeran = true;
                        }
                    }

                    if ($kurangPeran) {
                        continue;
                    }

                    // Deret datar sengaja DIKOSONGKAN, sama seperti enclosure,
                    // Height Gauge, dan Flowmeter: keempat peran ABBA punya
                    // tanda yang berbeda di rumusnya, dan meratakannya jadi satu
                    // `pembacaan` membuat rata-ratanya campur aduk lintas peran.
                    // Yang dibaca profilnya `konteks.at_*`.
                    $nilai = [];
                } elseif ($flow !== []) {
                    // Gerbangnya jumlah ULANGAN UUT, bukan jumlah baris: satu
                    // titik berisi sampai 3 ulangan UUT + 3 pembacaan standar +
                    // 6 suhu + 1 densitas, jadi hitungan datar tetap lolos
                    // walau sisi UUT-nya kosong — dan "deviasi" yang lahir dari
                    // sisi kosong itu justru sebesar seluruh pembacaan
                    // standarnya, angka yang kelihatan masuk akal.
                    //
                    // Dua, bukan tiga: master menyediakan tiga kolom tapi
                    // simpangan baku sudah punya arti mulai dari dua, dan titik
                    // yang cuma sempat dibaca dua kali tetap titik yang sah.
                    if (count($flow[FlowmeterMentah::PERAN_UUT]) < 2) {
                        continue;
                    }

                    // Deret datar sengaja DIKOSONGKAN, sama seperti enclosure &
                    // Height Gauge di bawah: satu titik flowmeter punya dua
                    // deret yang artinya beda (UUT dan standar), dan
                    // meratakannya jadi satu `pembacaan` membuat rata-ratanya
                    // campur aduk lintas peran. Yang dibaca profilnya
                    // `konteks.flow_*`.
                    $nilai = [];
                } elseif ($heightGauge !== []) {
                    // Gerbangnya jumlah PEMBACAAN, bukan jumlah baris: satu
                    // titik berisi sampai 3 slot nominal + 3 pembacaan, jadi
                    // hitungan datar tetap lolos walau sisi pembacaannya
                    // kosong — dan "koreksi" yang lahir dari sisi kosong itu
                    // justru sebesar total nominalnya, angka yang kelihatan
                    // masuk akal.
                    //
                    // Dua, bukan tiga: master menyediakan tiga kolom tapi
                    // simpangan baku sudah punya arti mulai dari dua, dan titik
                    // yang cuma sempat dibaca dua kali tetap titik yang sah.
                    if (count($heightGauge[HeightGaugeMentah::PERAN_PEMBACAAN]) < 2) {
                        continue;
                    }

                    $nilai = [];
                } elseif ($mikro !== []) {
                    // Gerbangnya jumlah pembacaan, bukan jumlah baris: satu
                    // titik berisi sampai 3 nominal balok + 5 pembacaan, jadi
                    // hitungan datar tetap lolos walau sisi pembacaannya
                    // kosong — dan "koreksi" yang lahir dari sisi kosong itu
                    // justru sebesar total nominalnya, angka yang kelihatan
                    // masuk akal.
                    //
                    // Titik NOL (rahang tertutup, tanpa balok ukur) sah dan
                    // memang bernominal kosong, jadi yang diwajibkan cuma sisi
                    // pembacaannya.
                    if (count($mikro[MicrometerMentah::PERAN_PEMBACAAN]) < 2) {
                        continue;
                    }

                    $nilai = [];
                } elseif ($waktu !== []) {
                    // Gerbangnya jumlah pembacaan PER PERAN, bukan jumlah
                    // baris: satu titik berisi 3 standar + 3 UUT, jadi hitungan
                    // datar tetap lolos walau satu sisinya kosong — dan koreksi
                    // yang lahir dari sisi yang kosong itu justru sebesar
                    // koreksi standarnya, angka yang kelihatan masuk akal.
                    if (count($waktu['waktu_standar']) < 2 || count($waktu['waktu_uut']) < 2) {
                        continue;
                    }

                    $nilai = [];
                } elseif ($timbangan !== []) {
                    // Gerbangnya KEEMPAT pembacaan, bukan jumlah baris: satu
                    // titik berisi 4 pembacaan + sampai 6 baris nominal, jadi
                    // hitungan datar selalu lolos walau pembacaannya bolong.
                    $kurang = false;

                    foreach (TimbanganMentah::PERAN_PEMBACAAN as $peran) {
                        if ($timbangan[$peran] === null) {
                            $kurang = true;
                        }
                    }

                    if ($kurang || $timbangan['nominal'] === []) {
                        continue;
                    }

                    $nilai = [];
                } elseif ($pasangan !== []) {
                    // Gerbangnya jumlah pembacaan PER PERAN, bukan jumlah baris:
                    // satu titik berisi 5 standar + 5 UUT, jadi hitungan datar
                    // selalu lolos walau salah satu sisinya kosong.
                    $nilai = [];

                    if (count($pasangan['standar']) < 2 || count($pasangan['uut']) < 2) {
                        continue;
                    }
                } elseif ($grid === []) {
                    // Alat single-channel biasa: satu titik = satu deret
                    // pembacaan datar. Minimal dua, karena satu pembacaan nggak
                    // punya simpangan baku.
                    $nilai = $baris
                        ->sortBy('pembacaan_ke')
                        ->pluck('pembacaan')
                        ->filter(fn ($n): bool => $n !== null)
                        ->map(fn ($n): float => (float) $n)
                        ->values()
                        ->all();

                    if (count($nilai) < 2) {
                        continue;
                    }
                } else {
                    // Enclosure nggak punya deret datar: satu set point itu 9
                    // termokopel × 5 pembacaan + Indikator, dan yang dibaca
                    // profilnya `konteks.sensor_grid`. Meratakan semuanya jadi
                    // satu deret bikin `pembacaan` campur aduk lintas sensor,
                    // jadi dikosongin — persis seperti jalur simpan di
                    // `CalibrationController::susunGridEnclosure()`.
                    $nilai = [];

                    if ($grid['sensor_grid'] === [] && $grid['indikator'] === []) {
                        continue;
                    }
                }

                /** @var RawMeasurement $pertama */
                $pertama = $baris->first();

                // `standard` dikirim sebagai OBJEK, bukan id: profilnya baca
                // ketidakpastian & faktor cakupan sertifikat standarnya
                // langsung dari situ, sama seperti waktu sesi ini pertama
                // dihitung dari payload.
                $siapHitung[] = [
                    'titik_ke' => (int) $titikKe,
                    'titik_ukur' => (float) $pertama->titik_ukur,
                    'pembacaan' => $nilai,
                    'standard' => $pertama->standard,
                    'standard_id' => $pertama->standard_id,
                    'satuan' => $pertama->satuan,
                    'suhu' => null,
                    // Mode & tipe sensor TITS, dibaca balik dari kolom sesi.
                    // Tanpa ini hitung ulang sesi TITS nggak bisa nentuin arah
                    // koreksi maupun tabel kalibrator, dan seluruh titiknya
                    // pulang tanpa angka — bukan salah, tapi juga nggak berguna.
                    'konteks' => [
                        'mode_tits' => $sesi->mode_kalibrasi,
                        'tipe_sensor' => $sesi->tipe_sensor,
                        ...$grid,
                        ...$pasangan,
                        // Empat pembacaan + slot nominal satu titik akurasi
                        // Timbangan. Blok tingkat-sesinya (keterulangan,
                        // eksentrisitas, histeresis) ikut lewat
                        // `spesifikasi_alat` di bawah.
                        ...$timbangan,
                        // Dua deret waktu lembar Timer/Stopwatch. Kejadian
                        // KEDELAPAN dengan pola yang sama, jadi ditulis bareng
                        // profilnya alih-alih ditemukan belakangan lewat
                        // `hitung_ulang_gagal` di tiap titik. Kosong buat dua
                        // puluh tiga alat lain.
                        ...$waktu,
                        // Tumpukan balok ukur + deret pembacaan lembar
                        // Micrometer. Kejadian KESEMBILAN dengan pola yang
                        // sama. Blok tingkat-sesinya (pra-evaluasi, suhu,
                        // kapasitas, resolusi) ikut lewat `spesifikasi_alat`
                        // di bawah. Kosong buat dua puluh empat alat lain.
                        ...$mikro,
                        // Slot nominal + deret pembacaan lembar Height Gauge.
                        // Kejadian KESEPULUH dengan pola yang sama. Blok
                        // tingkat-sesinya (paralelisme, blok Evaluation,
                        // kapasitas, resolusi) ikut lewat `spesifikasi_alat`
                        // di bawah. Kosong buat dua puluh lima alat lain.
                        ...$heightGauge,
                        // Deret UUT + standar + suhu air + densitas lembar
                        // Flowmeter. Kejadian KESEBELAS dengan pola yang sama.
                        // Blok tingkat-sesinya (mode, satuan, geometri pipa,
                        // resolusi) ikut lewat `spesifikasi_alat` di bawah —
                        // dan tanpa `mode` di situ seluruh titiknya pulang
                        // "belum dihitung", karena mode yang menentukan
                        // budgetnya 8 komponen atau 9. Kosong buat dua puluh
                        // enam alat lain.
                        ...$flow,
                        // Empat penimbangan ABBA satu keping Anak Timbangan.
                        // Kejadian KEDUA BELAS dengan pola yang sama, jadi
                        // ditulis bareng profilnya alih-alih ditemukan
                        // belakangan lewat `hitung_ulang_gagal` di tiap titik.
                        // Blok tingkat-sesinya (kelas OIML, neraca, meter,
                        // kondisi ruangan) ikut lewat `spesifikasi_alat`.
                        ...$anakTimbangan,
                        // Tumpukan balok + penunjukan Dial Indicator. Blok
                        // Evaluation ikut lewat `spesifikasi_alat` di bawah.
                        ...$dial,
                        // Opening Sieve Mesh & tiga tabel Jangka Sorong. Blok
                        // tingkat-sesinya ikut lewat `spesifikasi_alat` di bawah.
                        ...$sieve,
                        ...$jangkaSorong,
                        // Deret massa + deret suhu air lembar Hydrometer. Blok
                        // Pre Condition-nya (Ma, yx, tr, beban tambahan,
                        // diameter stem) ikut lewat `spesifikasi_alat` di
                        // bawah, dan tanpa itu seluruh titiknya pulang "belum
                        // dihitung". Kondisi lingkungannya ikut lewat empat
                        // kunci di bawahnya — termasuk TEKANAN, yang tanpa dia
                        // densitas udara tidak bisa dihitung sama sekali.
                        ...$hydro,
                        'suhu_awal' => $sesi->suhu_awal,
                        'suhu_akhir' => $sesi->suhu_akhir,
                        'kelembaban_awal' => $sesi->kelembaban_awal,
                        'kelembaban_akhir' => $sesi->kelembaban_akhir,
                        'tekanan_awal' => $sesi->tekanan_awal,
                        'tekanan_akhir' => $sesi->tekanan_akhir,
                        // Tiga kolom SESI ketiga alat suhu — alasannya sama
                        // seperti `tipe_sensor` di atas: tanpa ini seluruh
                        // titiknya pulang tanpa angka.
                        'alat_bantu' => $sesi->alat_bantu,
                        'tipe_pencelupan' => $sesi->tipe_pencelupan,
                        'titik_es' => $sesi->titik_es ?? [],
                        // Lembar TIDS menaruh dryblock-nya di sini, bukan di
                        // kolom `alat_bantu`. Ketinggalan = hitung ulang sesi
                        // TIDS kehilangan dua komponen budget.
                        'spesifikasi_alat' => $sesi->spesifikasi_alat ?? [],
                        // Titik nol umur drift standar Micrometer — dibaca
                        // balik dari sesinya, bukan `now()`. Master memakai
                        // `NOW()`, dan karena itu U95 sesi yang sama tidak
                        // pernah terulang. Diabaikan profil lain.
                        'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi,
                        // Rata-rata suhu ruangan MENTAH — sumber suhu balok
                        // ukur & suhu UUT Micrometer. Diabaikan profil lain.
                        'suhu_ruang_rata' => MicrometerMentah::rataSuhuRuang($sesi->suhu_awal, $sesi->suhu_akhir),
                    ],
                ];
            }

            if ($siapHitung === []) {
                $this->warn("{$sesi->nomor_sesi}: nggak ada titik yang bisa dihitung, dilewat.");

                continue;
            }

            $perGrup = $profil->untukAlat($alat)->hitungPerGrup($siapHitung, $alat);

            if ($perGrup === null) {
                $this->error(
                    "{$sesi->nomor_sesi}: alat ini pakai jalur hitung per-TITIK, dan perintah ini "
                    .'baru mendukung alat yang hitungnya per-kelompok (Spectrophotometer & TITS). '
                    .'Buat alat lain, betulin lewat jalur revisi biasa.'
                );
                $gagal++;

                continue;
            }

            $baru = $perGrup['hitungan'] ?? [];

            $lama = $sesi->uncertaintyCalculations
                ->sortBy('titik_ke')
                ->mapWithKeys(fn ($t): array => [
                    (int) $t->titik_ke => (float) $t->ketidakpastian_diperluas,
                ])
                ->all();

            // Penjagaan yang paling penting di perintah ini: hasil kosong
            // NGGAK BOLEH menimpa hasil yang sudah ada.
            //
            // Penulisannya `delete()` lalu `create()` per titik, jadi kalau
            // hitung ulangnya pulang tanpa satu pun baris, yang terjadi bukan
            // "nggak ada yang berubah" tapi **seluruh hasil sesi terhapus** —
            // dan `raw_measurements`-nya masih utuh, jadi sertifikat yang sudah
            // terbit tinggal jadi sesi tanpa angka sama sekali. Kejadian nyata:
            // sesi enclosure yang tiap titiknya masuk `belum_dihitung` (mis.
            // nomor termokopelnya nggak ada di tabel kalibrator) turun dari 4
            // baris jadi 0, dan `--dry-run` pun nggak ngasih tanda apa-apa.
            //
            // Sesi yang MEMANG belum pernah punya hasil tetap boleh lewat:
            // nggak ada yang bisa hilang di situ.
            if ($baru === [] && $lama !== []) {
                $this->error(
                    "{$sesi->nomor_sesi}: hitung ulang nggak menghasilkan satu titik pun, "
                    .'padahal sesi ini punya '.count($lama).' hasil tersimpan. '
                    .'DIBATALKAN — kalau diteruskan hasil yang lama kehapus dan nggak ada gantinya.'
                );

                foreach ($perGrup['belum_dihitung'] ?? [] as $b) {
                    $this->line('    • titik '.($b['titik_ke'] ?? '?').': '.($b['alasan'] ?? '-'));
                }

                $gagal++;

                continue;
            }

            // Titik yang dulu punya hasil tapi sekarang nggak — hasil lamanya
            // bakal ikut kehapus tanpa pengganti. Bukan alasan buat berhenti
            // (sisanya masih benar), tapi harus kelihatan sebelum ditulis.
            $hilang = array_values(array_diff(
                array_keys($lama),
                array_map(static fn (array $h): int => (int) $h['titik_ke'], $baru),
            ));

            $this->line("{$sesi->nomor_sesi}:");

            if ($hilang !== []) {
                $this->warn('  titik '.implode(', ', $hilang).' punya hasil tersimpan tapi nggak kehitung ulang — hasil lamanya bakal hilang:');

                foreach ($perGrup['belum_dihitung'] ?? [] as $b) {
                    if (in_array((int) ($b['titik_ke'] ?? 0), $hilang, true)) {
                        $this->line('    • titik '.$b['titik_ke'].': '.($b['alasan'] ?? '-'));
                    }
                }
            }

            foreach ($baru as $h) {
                $ke = (int) $h['titik_ke'];
                $sebelum = $lama[$ke] ?? null;
                $sesudah = (float) $h['ketidakpastian_diperluas'];

                if ($sebelum !== null && abs($sebelum - $sesudah) > 1e-8) {
                    $this->line(sprintf(
                        '  titik %2d: U95 %s → %s',
                        $ke,
                        Angka::idRingkas($sebelum, 6),
                        Angka::idRingkas($sesudah, 6),
                    ));
                }
            }

            if ($kering) {
                $this->comment('  (dry-run, nggak ada yang ditulis)');

                continue;
            }

            // Kehilangan SEBAGIAN nggak dibatalkan otomatis — bisa jadi memang
            // itu maunya (mis. titik yang datanya terbukti salah). Tapi harus
            // dijawab manusia, dan defaultnya TIDAK: dijalankan tanpa terminal
            // (`--no-interaction`, cron, deploy) ini berhenti sendiri ketimbang
            // menghapus diam-diam.
            if ($hilang !== [] && ! $this->confirm('  Terusin dan hapus hasil titik '.implode(', ', $hilang).'?', false)) {
                $this->comment('  dibatalkan, nggak ada yang ditulis.');
                $gagal++;

                continue;
            }

            $versi = $rumus->versiUntukSesi($sesi);

            DB::transaction(function () use ($sesi, $baru, $versi): void {
                $sesi->uncertaintyCalculations()->delete();

                foreach ($baru as $hitungan) {
                    $sesi->uncertaintyCalculations()->create([
                        ...$hitungan,
                        'formula_version_id' => $versi?->id,
                    ]);
                }
            });

            $segar = $sesi->fresh(['equipment', 'rawMeasurements', 'uncertaintyCalculations']);
            $hasil = $validator->periksa($segar);

            $this->info('  hasil hitung diperbarui.');

            if (! $hasil['boleh_terbit']) {
                $this->warn('  MASIH ADA ERROR — sertifikatnya jangan diterbitkan ulang dulu:');

                foreach ($hasil['temuan'] as $t) {
                    if (($t['tingkat'] ?? null) === CalibrationValidator::ERROR) {
                        $this->line('    • '.$t['pesan']);
                    }
                }

                continue;
            }

            $this->line('  pemeriksaan bersih. Terbitkan ulang dokumennya:');
            $this->line("    php artisan sertifikat:bangun-ulang {$sesi->nomor_sesi}");
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
