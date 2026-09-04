<?php

namespace App\Http\Requests;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Rules\AngkaTerhingga;
use App\Rules\PenunjukanWaktu;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\Calibration\TabelKalibratorSuhu;
use App\Support\MicrometerMentah;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalibrationRequest extends FormRequest
{
    /**
     * Batas total sel grid enclosure (termokopel + Indikator) satu request.
     *
     * Longgar 10x dari sesi terbesar yang masuk akal (6 set point × 9 termokopel
     * × 5 = 270); gunanya menutup beban tulis, bukan membatasi metrologi.
     * Lihat alasannya di `withValidator()`.
     */
    public const MAKS_SEL_GRID = 3000;

    /**
     * Buang field administratif dari kiriman non-admin (spesifikasi poin 1).
     *
     * DIBUANG, bukan ditolak 422: layar teknisi versi lama masih ngirim
     * `nomor_order`, dan bikin mobile lama gagal submit di lapangan itu
     * kerugian yang jauh lebih besar daripada nolak satu field yang emang
     * bakal diisi ulang admin. Yang penting nilainya nggak nyampe DB.
     */
    protected function prepareForValidation(): void
    {
        $this->bakukanKeterulanganTimbangan();
        $this->bakukanPraEvaluasiMicrometer();

        if ($this->user()?->isAdmin()) {
            return;
        }

        $this->replace(Arr::except($this->all(), CalibrationSession::fieldAdmin()));
    }

    /**
     * Ubah blok keterulangan bentuk-TABEL dari HP jadi bentuk baku
     * `{mid, maks}` yang dibaca kalkulator.
     *
     * ## Kenapa dua bentuk, dan kenapa yang menerjemahkan di sini
     *
     * Tabel Repeatability lembar Timbangan isinya besaran tingkat-SESI, jadi
     * dia menyatakan `simpan_ke: spesifikasi_alat.keterulangan` dan HP
     * mengirimkannya sebagai **cerminan tabel yang digambarnya**:
     *
     *     keterulangan.baris[] = { titik_ukur, <kode kolom>: [nilai per ulangan] }
     *
     * Bentuk itu tidak menyebut "mid" maupun "maks" — dan memang tidak boleh:
     * kalau HP yang menamai slotnya, nama itu jadi daftar yang ditulis tangan
     * di layar, persis pola yang sudah bikin lembar lain ketinggalan diam-diam
     * waktu bentuknya berubah. Yang tahu bahwa baris pertama Middle Capacity
     * dan kolom `zero` itu `zi` adalah profil alatnya, di sisi ini.
     *
     * Bentuk baku `{mid: {nominal, zi, mi}, maks: {...}}` dibiarkan apa adanya
     * — itu yang tersimpan di sesi-sesi contoh, yang dibaca
     * `TimbanganCalculator`, dan yang diadu `TimbanganMasterTest` ke master.
     * Jadi jalur ini murni penerjemah, bukan bentuk ketiga.
     *
     * Diletakkan di `prepareForValidation()` supaya yang TERSIMPAN sudah baku:
     * jalur hitung ulang (`kalibrasi:hitung-ulang`) membaca
     * `calibration_sessions.spesifikasi_alat` apa adanya, jadi bentuk mentah
     * yang lolos ke DB bakal menghitung nol keterulangan di setiap sesi —
     * tanpa error, cuma `Sr` yang diam-diam jatuh ke lantai resolusi.
     */
    private function bakukanKeterulanganTimbangan(): void
    {
        $baris = $this->input('spesifikasi_alat.keterulangan.baris');

        if (! is_array($baris) || $baris === []) {
            return;
        }

        // Urutan baris = urutan di kertas: Middle dulu, Maximum kedua. Itu
        // urutan yang dikirim `bagianKeterulangan()`, dan HP menggambar tabel
        // mengikuti `baris` apa adanya.
        $slot = ['mid', 'maks'];
        $baku = [];

        foreach (array_values($baris) as $i => $b) {
            if (! isset($slot[$i]) || ! is_array($b)) {
                continue;
            }

            $baku[$slot[$i]] = [
                'nominal' => $b['titik_ukur'] ?? null,
                // `zero` & `pembacaan` itu kode KOLOM yang dikirim bentuknya;
                // `zi` & `mi` nama yang dipakai master. Dipetakan di sini,
                // satu-satunya tempat kedua kosakata itu bertemu.
                'zi' => array_values((array) ($b['zero'] ?? [])),
                'mi' => array_values((array) ($b['pembacaan'] ?? [])),
            ];

            /*
             * Tebakan mesin ikut diterjemahkan, dan ini BUKAN kelengkapan.
             *
             * Penerjemah ini membuang kunci yang nggak dikenalnya. Jadi tanpa
             * dua baris di bawah, tebakan kamera lembar Timbangan mendarat di
             * HP, terkirim ke server, lalu hilang TANPA JEJAK tepat di sini —
             * dan `ocr:akurasi-kamera` bakal melaporkan nol sel Timbangan,
             * yang kebaca sebagai "kameranya bagus" padahal artinya nol data.
             *
             * Cuma ditulis kalau memang ada isinya: sesi yang seluruhnya
             * diketik tangan nggak boleh menyimpan kunci kosong di blok yang
             * dibaca kalkulator.
             */
            foreach (['zi' => 'zero_ocr', 'mi' => 'pembacaan_ocr'] as $nama => $kunci) {
                $tebakan = array_values((array) ($b[$kunci] ?? []));

                if (array_filter($tebakan, static fn ($v): bool => $v !== null) !== []) {
                    $baku[$slot[$i]][$nama.'_ocr'] = $tebakan;
                }
            }
        }

        if ($baku === []) {
            return;
        }

        $spek = (array) $this->input('spesifikasi_alat', []);
        $spek['keterulangan'] = $baku;

        $this->merge(['spesifikasi_alat' => $spek]);
    }

    /**
     * Ratakan baris `Evaluasi` ke deret angka, dan konversikan satuannya ke mm.
     *
     * ## 1. Bentuknya — kenapa ada dua
     *
     * Tabel `Evaluasi` menyatakan
     * `simpan_ke: spesifikasi_alat.micrometer.pra_evaluasi`, dan HP mengirim
     * SETIAP tabel ber-`simpan_ke` sebagai cerminan tabel yang digambarnya —
     * sama seperti Repeatability Timbangan di atas:
     *
     *     pra_evaluasi = { baris: [ { titik_ukur, pembacaan: [10 angka] } ] }
     *
     * (Kode kolomnya `pembacaan`, sama seperti dua puluh empat lembar
     * lain.) Yang dibaca [MicrometerMentah::blokSesi] deret angka DATAR. Tanpa
     * perataan ini bentuk HP kena aturan
     * `spesifikasi_alat.micrometer.pra_evaluasi.* => numeric` dan pulang
     * **422** — jadi kegagalannya memang kelihatan, tapi yang gagal SETIAP
     * sesi Micrometer dari HP, dengan keluhan yang menunjuk sepuluh angka yang
     * sudah benar diisi teknisi.
     *
     * ## 2. Satuannya — dan kenapa dikonversi di KEDUA bentuk
     *
     * Pembacaan baris Evaluasi diketik dalam satuan ALAT, persis seperti lima
     * pembacaan tiap titik — dan yang itu dikonversi
     * `CalibrationController::susunBlokMicrometer()`. Blok ini lewat jalur lain
     * (`spesifikasi_alat` ikut apa adanya ke `konteks`), jadi konversinya harus
     * terjadi di sini.
     *
     * Deret datar ikut dikonversi, bukan dilewati. Melewatkannya bikin dua
     * bentuk yang membawa angka SAMA berarti beda — nested dibaca satuan alat,
     * datar dibaca mm — dan tidak ada satu pun error yang membedakannya. Satuan
     * itu sifat SESI-nya, bukan sifat pembungkus payload-nya.
     *
     * Akibat kalau ini luput: simpangan bakunya ~25× terlalu kecil pada sesi
     * `inch`, komponen pengulangan nyaris hilang dari budget, dan U95 mendarat
     * di lantai CMC — **kelihatan wajar**. Persis rantai yang bikin master lab
     * menerbitkan 0,735 µm di bawah pitanya sendiri.
     *
     * Yang TIDAK lewat sini: sesi contoh ter-seed. Seeder menulis langsung ke
     * `calibration_sessions`, sudah dalam mm, tanpa menyentuh FormRequest —
     * jadi `MicrometerMasterTest` tetap mengadu angka master apa adanya.
     *
     * Diletakkan di `prepareForValidation()` dengan alasan yang sama seperti
     * Timbangan: yang TERSIMPAN harus sudah baku, karena
     * `kalibrasi:hitung-ulang` membaca `spesifikasi_alat` apa adanya.
     */
    private function bakukanPraEvaluasiMicrometer(): void
    {
        $pra = $this->input('spesifikasi_alat.micrometer.pra_evaluasi');

        if (! is_array($pra) || $pra === []) {
            return;
        }

        // Bentuk tabel HP diratakan; deret datar dipakai apa adanya. Kode
        // kolomnya `pembacaan`, dari `MicrometerProfile::bagianEvaluasi()`.
        // Kertasnya cuma punya satu baris, tapi baris kedua dan seterusnya
        // (kalau kertas revisi berikutnya menambahnya) ikut disambung — bukan
        // dibuang diam-diam.
        $mentah = isset($pra['baris'])
            ? array_merge(...array_map(
                static fn ($b): array => array_values((array) (is_array($b) ? ($b['pembacaan'] ?? []) : [])),
                array_values((array) $pra['baris']) ?: [[]],
            ))
            : array_values($pra);

        $nilai = array_values(array_filter($mentah, static fn ($v): bool => is_numeric($v)));

        $spek = (array) $this->input('spesifikasi_alat', []);
        $faktor = MicrometerProfile::SATUAN_PILIHAN[(string) ($spek['micrometer']['satuan'] ?? 'mm')] ?? 1.0;

        $spek['micrometer']['pra_evaluasi'] = array_map(
            static fn ($v): float => (float) $v * $faktor,
            $nilai,
        );

        $this->merge(['spesifikasi_alat' => $spek]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    /**
     * Lembar alat yang dikirim ini dibaca per BLOK WAKTU (Timer/Stopwatch)?
     *
     * Dipanggil dari `rules()`, jadi `equipment_id` belum tervalidasi: yang
     * belum bisa dipastikan dijawab `false` — bentuk objeknya ditolak, persis
     * perilaku sebelum aturan ini ada. Menolak kiriman yang sah lebih baik
     * daripada menerima kiriman yang pembacaannya bakal dibuang diam-diam.
     */
    private function lembarBerblokWaktu(): bool
    {
        $id = $this->input('equipment_id');

        if (! is_numeric($id)) {
            return false;
        }

        $alat = Equipment::query()
            ->where('organization_id', $this->user()->organization_id)
            ->find((int) $id);

        return $alat !== null
            && app(CalibrationProfileRegistry::class)->untukAlat($alat)->butuhBlokWaktu();
    }

    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        // Bentuk objek {jam,menit,detik,milidetik} cuma sah di lembar yang
        // memang dibaca per BLOK WAKTU. Dibuka buat semua alat, lembar
        // Thermocouple yang mengirim bentuk itu diterima 200 lalu pembacaannya
        // dibuang diam-diam — teknisi kehilangan seluruh lembarnya tanpa satu
        // pun pesan. Lihat docblock `PenunjukanWaktu`.
        $bolehObjekWaktu = $this->lembarBerblokWaktu();

        $aturan = [
            'equipment_id' => [
                'required',
                Rule::exists('equipments', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            // "7. Satuan Refracto" — satu-satunya kolom lembar kerja yang nulis
            // balik ke DATA MASTER alat (`equipments.satuan`), bukan ke sesinya.
            //
            // Bukan kemewahan: satu refractometer fisik bisa dipindah antara
            // skala n20D & °Brix, dan RefractometerProfile milih koefisien suhu
            // (0,00045/°C vs 0,07/°C) sama komponen CMC dari kolom itu. Teknisi
            // yang mindahin skalanya di lapangan tapi nggak bisa nyatetnya bikin
            // pembacaan °Brix dikoreksi pakai koefisien n20D — meleset 155 kali,
            // tanpa satu pun error.
            //
            // Mobile cuma ngirim ini buat lembar yang PUNYA kolomnya, jadi sesi
            // pH/Turbidimeter/Chlorine nggak pernah nyentuh satuan alatnya.
            'equipment_satuan' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Ketidakpastian standar acuan itu komponen Type B terbesar. DULUNYA
            // wajib — sekarang boleh kosong, ngikutin lembar kerja: teknisi di
            // lapangan boleh ngirim lembar yang belum lengkap. Konsekuensinya
            // titik tanpa standar NGGAK dihitung ketidakpastiannya (lihat
            // CalibrationController::isiUlangPengukuran), dan sertifikatnya
            // ketahan di validasi admin — bukan angka ngasal yang terbit.
            'standard_id' => [
                'nullable',
                Rule::exists('standards', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            // Draft boleh disimpen sebelum tanggalnya kepikiran; begitu dikirim
            // buat approval, tanggal kalibrasi wajib ada — itu identitas
            // pekerjaannya, bukan detail tambahan.
            'tanggal_kalibrasi' => [
                $this->disimpanSebagaiDraft() ? 'nullable' : 'required',
                'date', 'before_or_equal:today',
            ],
            // Cuma nyampe sini kalau yang ngirim admin — kiriman teknisi udah
            // dibuang di prepareForValidation().
            'nomor_order' => ['sometimes', 'nullable', 'string', 'max:100'],
            'calibration_method_id' => [
                'sometimes', 'nullable',
                Rule::exists('calibration_methods', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'thermohygro_standard_id' => [
                'sometimes', 'nullable',
                Rule::exists('standards', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'suhu_ketidakpastian' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'kelembaban_ketidakpastian' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Tanggal alat diterima dari customer — logisnya nggak setelah
            // tanggal kalibrasinya sendiri.
            'tanggal_terima' => ['sometimes', 'nullable', 'date', 'before_or_equal:tanggal_kalibrasi'],

            // UUID yang mobile generate sekali per submission — kalau request-nya
            // di-retry (mis. sinyal putus pas nunggu respons) dengan key yang
            // sama, backend balikin sesi yang udah ada, bukan bikin dobel.
            // Opsional & backward-compatible: mobile lama yang belum kirim ini
            // tetap jalan seperti biasa (lihat CalibrationController::store()).
            'client_request_id' => ['sometimes', 'nullable', 'uuid'],
            // `ocr` disimpen buat kompatibilitas app lama; sumber baru dari
            // kamera adalah `ai_vision` (Claude Vision di server, ganti OCR).
            'input_method' => ['sometimes', Rule::in(['manual', 'ocr', 'ai_vision'])],
            'lokasi' => ['sometimes', Rule::in(['lab', 'onsite'])],
            // Nama tempat buat sesi `onsite` — yang tercetak `Insitu (PT. LDC)`.
            'lokasi_nama' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Ruangan lab tempat sesi dikerjain — jadi "Calibration Location"
            // di sertifikat. Kosong buat sesi onsite.
            'room_id' => [
                'sometimes', 'nullable',
                Rule::exists('rooms', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'suhu_ruang' => ['nullable', 'numeric'],
            'kelembaban' => ['nullable', 'numeric', 'between:0,100'],
            // "Env. Condition" di lembar kerja dicatat dua kali: awal & akhir
            // kerja. Semua opsional — thermohygro-nya nggak selalu ada di
            // lokasi pelanggan.
            'suhu_awal' => ['sometimes', 'nullable', 'numeric'],
            'suhu_akhir' => ['sometimes', 'nullable', 'numeric'],
            'kelembaban_awal' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'kelembaban_akhir' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            // Kolom `Time` di tabel yang sama (lembar Spectrophotometer
            // SIDIK-FM-CAL-0511_Rev.5). `H:i:s` ikut diterima karena itu bentuk
            // yang dipulangkan kolom `time` MySQL — draft yang dibuka lagi lalu
            // dikirim balik apa adanya nggak boleh ditolak.
            'waktu_awal' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'waktu_akhir' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            // Kolom "Catatan:" di lembar kerja.
            'catatan_teknisi' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // Identitas alat & pemilik yang DIKETIK TEKNISI dari badan alat /
            // surat jalan (lembar kerja poin 3-5 & OWNER 1-2). Bukan field
            // admin: yang megang alat fisiknya teknisi. Semuanya opsional —
            // lembar kerja boleh dikirim belum lengkap.
            'alat_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alat_serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alat_merk' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Rentang ukur / kapasitas / resolusi yang DIBACA teknisi dari
            // badan alat. Kuncinya datang dari bentuk lembar kerja; nilainya
            // teks apa adanya (`0-100`, `0,001`) karena yang tercetak di
            // sertifikat juga teks, bukan hasil hitung.
            'spesifikasi_alat' => ['sometimes', 'nullable', 'array'],
            // Kebanyakan kunci di sini teks pendek. TIGA kunci milik lembar
            // Timbangan isinya BLOK — keterulangan (2 kapasitas × 10
            // pengulangan × 2 angka), eksentrisitas (5 posisi), histeresis
            // (2 deret × 8). Ketiganya besaran tingkat-SESI yang nggak punya
            // `titik_ke`, jadi nggak bisa lewat `measurements`.
            //
            // Aturan `string|max:64` yang lama nolak ketiganya dengan 422 —
            // dan yang ketolak bukan cuma bloknya, tapi SELURUH sesi. Ketangkap
            // `TimbanganSesiTest` waktu jalur simpannya pertama kali diadu ke
            // endpoint beneran; sebelum itu jalur simpan Timbangan mustahil
            // dipakai dari HP tanpa satu pun test merah.
            'spesifikasi_alat.*' => ['nullable', $this->spekBolehBerbentukBlok()],
            // Batas ukuran tiap blok — bukan metrologi, tapi beban. Tanpa ini
            // satu request bisa nitip array sebesar apa pun ke kolom JSON.
            'spesifikasi_alat.keterulangan' => ['sometimes', 'nullable', 'array', 'max:4'],
            'spesifikasi_alat.eksentrisitas' => ['sometimes', 'nullable', 'array', 'max:4'],
            'spesifikasi_alat.histeresis' => ['sometimes', 'nullable', 'array', 'max:4'],
            // Dua blok CATATAN — tidak dibaca kalkulator mana pun, tapi tetap
            // wajib punya tempat: teknisi mengisinya dari kertas, dan isian
            // yang tidak punya tempat simpan hilang tanpa satu pun error.
            // Batasnya menghitung kunci TINGKAT ATAS: `scale_observation` punya
            // TIGA — dua tahap (`sebelum_adjustment`, `sesudah_adjustment`)
            // plus `sd_tahun_lalu` yang berdiri sendiri di bawahnya di kertas.
            // `effect_of_tare` lima kotak datar.
            //
            // Sempat `max:2` sesudah kotak SD ditambah, dan akibatnya bukan
            // kotak itu yang ditolak melainkan SELURUH sesi — 422 buat semua
            // yang kebetulan mengisinya.
            'spesifikasi_alat.scale_observation' => ['sometimes', 'nullable', 'array', 'max:3'],
            'spesifikasi_alat.effect_of_tare' => ['sometimes', 'nullable', 'array', 'max:5'],
            // Blok Micrometer: empat kunci tingkat atas (satuan, kapasitas,
            // resolusi, deret pra-evaluasi). Batasnya dilonggarkan ke 12 supaya
            // kunci kelima yang menyusul tidak menolak SELURUH sesi —
            // kesalahan yang sudah kejadian pada `scale_observation`.
            'spesifikasi_alat.micrometer' => ['sometimes', 'nullable', 'array', 'max:12'],
            'spesifikasi_alat.micrometer.pra_evaluasi' => ['sometimes', 'nullable', 'array', 'max:20'],
            'spesifikasi_alat.micrometer.pra_evaluasi.*' => ['nullable', 'numeric'],
            // Satuan alat MEMILIH faktor konversi ke mm, jadi nilainya dibatasi
            // ke daftar yang dikenal — bukan teks bebas. Satuan yang tidak
            // dikenal jatuh ke faktor 1,0 dan angkanya salah diam-diam.
            'spesifikasi_alat.micrometer.satuan' => ['sometimes', 'nullable', 'string', 'in:mm,inch,µm'],
            'spesifikasi_alat.micrometer.kapasitas_mm' => ['sometimes', 'nullable', 'numeric'],
            'spesifikasi_alat.micrometer.resolusi_mm' => ['sometimes', 'nullable', 'numeric'],
            // Deret pembacaan per titik. Tumpukan balok ukurnya TIDAK diterima
            // dari HP: nominalnya dipatok kertas dan tumpukannya diturunkan
            // server dari varian — lihat
            // `CalibrationController::susunBlokMicrometer()`. Menerimanya dari
            // luar berarti membuka jalan buat sesi yang balok ukurnya berbeda
            // dari yang tercetak di lembarnya sendiri.
            // Tidak ada aturan `measurements.*.mikro_pembacaan` di sini, dan itu
            // disengaja: tabel `Data Kalibrasi` satu kolom, jadi HP mengirimnya
            // lewat jalur DATAR `measurements.*.pembacaan` yang sudah punya
            // aturannya sendiri di bawah. Kosakata `mikro_*` cuma hidup sebagai
            // `peran_sensor` di `raw_measurements`.
            // Mode kalibrasi & tipe sensor — cuma TITS yang mengirimnya, dan
            // dua-duanya nentuin ANGKA (arah koreksi & tabel kalibrator mana
            // yang dibaca), jadi nilainya dibatasi ke daftar yang dikenal
            // ketimbang diterima sebagai teks bebas. Tetap opsional: sepuluh
            // alat lain nggak punya kolom ini.
            'mode_kalibrasi' => ['sometimes', 'nullable', Rule::in([
                TabelKalibratorSuhu::MODE_MEASURE,
                TabelKalibratorSuhu::MODE_SOURCE,
            ])],
            'tipe_sensor' => ['sometimes', 'nullable', Rule::in(TabelKalibratorSuhu::TIPE_SENSOR)],
            // Alat bantu (dryblock `A`/`B` buat Thermocouple, oilbath
            // `satu`/`dua` buat Termometer Gelas). Nilainya nentuin DUA
            // komponen budget, jadi dibatasi ke daftar yang dikenal — teks
            // bebas di sini berarti sesi tersimpan dengan alat bantu yang nggak
            // punya tabel, dan itu baru ketahuan waktu dihitung.
            'alat_bantu' => ['sometimes', 'nullable', Rule::in(['A', 'B', 'satu', 'dua'])],
            // Tipe pencelupan termometer gelas — tercetak di sertifikat.
            'tipe_pencelupan' => ['sometimes', 'nullable', 'string', 'max:30'],
            // Uji titik es termometer gelas: tiga pembacaan, yang dipakai
            // RENTANGNYA. `max:10` longgar supaya lab yang membaca lebih dari
            // tiga kali nggak ditolak.
            'titik_es' => ['sometimes', 'nullable', 'array', 'max:10'],
            'titik_es.*' => ['nullable', 'numeric'],
            'pemilik_nama' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pemilik_alamat' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Kolom "Usage Check": standar mana aja yang dicentang teknisi.
            //
            // `max:40` sama dengan batas `sensor_grid`, dan alasannya sama: tiap
            // elemen memicu satu query `exists` di baris berikutnya, jadi tanpa
            // batas, jumlah query dan waktu validasi ditentukan pengirim.
            // Lembar terpanjang di sistem ini mencetak ENAM baris standar, jadi
            // 40 itu kelonggaran, bukan patokan.
            'standar_dicek' => ['sometimes', 'array', 'max:40'],
            'standar_dicek.*.standard_id' => [
                'required',
                Rule::exists('standards', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'standar_dicek.*.dipakai' => ['sometimes', 'boolean'],
            'standar_dicek.*.keterangan' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Teknisi boleh nyimpen dulu sebagai draft & nerusin nanti. Kalau
            // nggak dikirim, sesi langsung masuk antrean approval admin.
            'status' => ['sometimes', Rule::in([
                CalibrationSession::STATUS_DRAFT,
                CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            ])],

            // Lembar kerja boleh dikirim walau belum penuh — kolom yang belum
            // keisi tetap lolos. Yang dijaga BUKAN kelengkapan formulirnya, tapi
            // penerbitan sertifikatnya: titik yang datanya kurang nggak dihitung,
            // dan sertifikat cuma bisa terbit sesudah lolos pemeriksaan admin
            // (lihat CalibrationValidator). Jadi teknisi nggak pernah keblokir
            // di lapangan, tapi angka setengah jadi juga nggak pernah nyampe
            // dokumen resmi.
            // Dibatasi 60 titik. Alat paling panjang di sistem ini memakai 9–18
            // titik, jadi 60 longgar sekali buat pemakaian nyata — gunanya
            // menutup beban, bukan membatasi metrologi.
            //
            // Tanpa batas, satu request bisa menulis puluhan ribu baris
            // `raw_measurements` satu per satu di dalam SATU transaksi. Enclosure
            // yang bikin ini kerasa: tiap titiknya grid (sensor × pengulangan),
            // jadi jumlah barisnya perkalian TIGA sisi, bukan dua. Produksi
            // jalan di satu proses 512 MB yang dipakai semua organisasi.
            'measurements' => ['sometimes', 'array', 'max:60'],
            'measurements.*.titik_ukur' => ['required', 'numeric'],
            'measurements.*.satuan' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Batasnya `MAKS_KOLOM_PENGULANGAN` — jumlah kolom pengulangan
            // TERBANYAK yang boleh digambar lembar mana pun (lihat
            // `bentukLembarKerja()`), jadi kiriman yang sah nggak mungkin
            // melebihinya; lembar terpanjang di test cuma memakai enam.
            //
            // Sebelumnya sumbu ini SATU-SATUNYA yang nggak berbatas, padahal
            // dia yang paling banyak menulis `raw_measurements` di jalur datar:
            // satu elemen = satu baris. `measurements` dibatasi 60 justru
            // dengan alasan itu, dan batas itu jadi nggak ada artinya kalau tiap
            // titiknya boleh membawa deret sepanjang apa pun.
            'measurements.*.pembacaan' => [
                'sometimes', 'nullable', 'array',
                'max:'.CalibrationProfile::MAKS_KOLOM_PENGULANGAN,
            ],
            // Sel kosong di lembar kerja dikirim sebagai null — diterima, terus
            // disaring waktu ngitung.
            'measurements.*.pembacaan.*' => ['nullable', 'numeric'],
            // GRID sensor — cuma enclosure yang ngirim. Tiap set point punya 9
            // termokopel (`no` 1..N, `channel` opsional buat kalibrator
            // Recorder) masing-masing 5 pembacaan, plus baris `indikator`
            // enclosure. Sepuluh alat lain nggak nyentuh field ini.
            // Dibatasi 40 sensor per set point: master pakai 9, recorder paling
            // banyak 20 kanal. Batas ini bukan soal metrologi tapi soal beban —
            // tiap sensor jadi 5 baris `raw_measurements`, jadi grid tanpa batas
            // bikin satu request nulis puluhan ribu baris.
            'measurements.*.sensor_grid' => ['sometimes', 'nullable', 'array', 'max:40'],
            // CATATAN: TANPA `distinct` — sengaja. Larangan nomor kembar ada di
            // `withValidator()`, dicek per set point.
            //
            // `distinct` pada wildcard bertingkat membandingkan SELURUH atribut
            // hasil ekspansi aturan ini, bukan cuma yang satu `sensor_grid`.
            // Jadi grid normal (sensor 3..11 di TIAP set point) ditolak 422 —
            // padahal memakai termokopel yang sama di beberapa set point itu
            // justru cara alat ini dipakai. Yang benar-benar terlarang cuma dua
            // baris bernomor sama dalam SATU set point: satu termokopel fisik
            // kehitung dua kali dan menggeser U95, keseragaman, & kestabilan.
            'measurements.*.sensor_grid.*.no' => ['required_with:measurements.*.sensor_grid', 'integer', 'min:1', 'max:99'],
            // Recorder GL840 punya CH1..CH20 — di luar itu pasti salah ketik,
            // dan kanal yang nggak ada bikin koreksi meter-nya nggak ketemu.
            'measurements.*.sensor_grid.*.channel' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'measurements.*.sensor_grid.*.pembacaan' => ['sometimes', 'nullable', 'array', 'max:20'],
            'measurements.*.sensor_grid.*.pembacaan.*' => ['nullable', 'numeric'],
            /*
             * Tebakan mesin per pembacaan grid, SEJAJAR INDEKS sama `pembacaan`
             * di baris yang sama.
             *
             * Kenapa perlu: teknisi mengoreksi angka hasil foto di kotak yang
             * sama, jadi tanpa ini tebakan mesinnya tertimpa dan akurasi jalur
             * kamera nggak bisa dihitung — termasuk HIJAU PALSU, satu-satunya
             * kegagalan yang nggak ada yang lihat sampai sertifikatnya terbit.
             *
             * `photo_path` nggak ada di sini, beda dari `measurements.*.ocr`:
             * jalur foto grid nggak mengunggah citranya ke server sama sekali.
             */
            'measurements.*.sensor_grid.*.ocr' => ['sometimes', 'nullable', 'array', 'max:20'],
            'measurements.*.sensor_grid.*.ocr.*' => ['nullable', 'array'],
            'measurements.*.sensor_grid.*.ocr.*.raw_text' => ['nullable', 'string', 'max:255'],
            'measurements.*.sensor_grid.*.ocr.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'measurements.*.indikator' => ['sometimes', 'nullable', 'array', 'max:20'],
            'measurements.*.indikator.*' => ['nullable', 'numeric'],
            /*
             * Padanan `sensor_grid.*.ocr` buat dua baris yang bentuknya DERET
             * ANGKA POLOS, bukan objek — jadi tebakannya nggak bisa dititipkan
             * di dalam barisnya sendiri dan harus jadi kunci sebelah.
             *
             * Namanya sengaja beda (`_ocr`) supaya nggak ada yang mengira ini
             * deret angka biasa dan menjumlahkannya.
             */
            'measurements.*.indikator_ocr' => ['sometimes', 'nullable', 'array', 'max:20'],
            'measurements.*.indikator_ocr.*' => ['nullable', 'array'],
            'measurements.*.indikator_ocr.*.raw_text' => ['nullable', 'string', 'max:255'],
            'measurements.*.indikator_ocr.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            // Alat ber-PASANGAN deret — keempat profil yang
            // `butuhPasanganStandarUut()`-nya menyala (Thermocouple, Termometer
            // Gelas, Thermohygrometer, TIDS): tiap titik dibaca dua kali — sisi
            // standar & sisi UUT. Dua-duanya opsional supaya lembar setengah
            // jadi tetap bisa dikirim dari lapangan.
            //
            // Daftarnya disebut lewat predikatnya, bukan cuma dieja: TIDS
            // menyusul 28 Agt dan ejaan di komentar ini ketinggalan berminggu-
            // minggu tanpa satu pun test merah — aturannya memang berlaku tanpa
            // syarat, jadi yang basi cuma keterangannya.
            'measurements.*.standar' => ['sometimes', 'nullable', 'array', 'max:20'],
            // BUKAN `numeric`: kolom ini dipakai DUA bentuk lembar — angka
            // biasa (ketiga alat suhu berpasangan) dan objek empat kotak
            // {jam,menit,detik,milidetik} (Timer/Stopwatch). Lihat docblock
            // `PenunjukanWaktu` buat kegagalan yang ditutupnya: bentuk kedua
            // dulu SELALU ditolak 422, jadi lembar Timer mustahil dikirim.
            'measurements.*.standar.*' => ['nullable', new PenunjukanWaktu($bolehObjekWaktu)],
            'measurements.*.uut' => ['sometimes', 'nullable', 'array', 'max:20'],
            'measurements.*.uut.*' => ['nullable', new PenunjukanWaktu($bolehObjekWaktu)],
            /*
             * Tebakan mesin per sisi, SEJAJAR INDEKS sama deret sisinya sendiri.
             *
             * Dua sisi dipisah karena sisi standar & sisi UUT punya tebakan yang
             * beda, dan menukarnya bikin kolom `Correction` — selisih dua sisi
             * itu — bergeser tanpa satu pun error.
             *
             * BENTUKNYA IKUT DERET NILAINYA, persis seperti
             * `measurements.*.standar.*` di atas yang dijaga `PenunjukanWaktu`:
             *
             *  - lembar satu kolom (ketiga alat suhu) → satu tebakan per
             *    ulangan: `{raw_text, confidence}`;
             *  - lembar Timer/Stopwatch → satu penunjukan ditulis di EMPAT
             *    kotak, jadi tebakannya juga per kotak:
             *    `{jam: {raw_text, …}, menit: {…}, …}`.
             *
             * Dua bentuk, satu sumber kebenaran: `lembarBerblokWaktu()` yang
             * sama menentukan keduanya. Kalau dipisah jadi dua penanda,
             * lembar yang berubah bentuk bakal lolos di satu sisi dan ditolak
             * di sisi lain.
             */
            ...$this->aturanTebakanPasangan($bolehObjekWaktu),
            // No. Termokopel: probe standar mana yang dicelup di baris ini.
            // Batas 28 = jumlah kolom tabel koreksi probe (RTD + TCK-01..16 +
            // TCN3..12); nomor di luar itu nggak menunjuk probe mana pun.
            'measurements.*.no_probe' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:28'],
            // Lembar TIMBANGAN — sampai enam nominal anak timbangan per titik
            // (slot Mass 1..6 master) plus empat pembacaan yang artinya
            // berbeda-beda: nol sebelum beban, dua pembacaan berbeban, nol
            // sesudah. Dua puluh alat lain nggak nyentuh field ini.
            //
            // `max:6` bukan angka karangan: master menyediakan persis enam
            // slot, dan slot ketujuh nggak punya baris drift di budget — jadi
            // kepingnya bakal ikut ke massa total tapi ketidakpastiannya
            // hilang, tanpa error.
            'measurements.*.nominal' => ['sometimes', 'nullable', 'array', 'max:6'],
            'measurements.*.nominal.*' => ['nullable', 'numeric', 'min:0'],
            'measurements.*.z1' => ['sometimes', 'nullable', 'numeric'],
            'measurements.*.m' => ['sometimes', 'nullable', 'numeric'],
            'measurements.*.m_aksen' => ['sometimes', 'nullable', 'numeric'],
            'measurements.*.z2' => ['sometimes', 'nullable', 'numeric'],
            // Thermohygro: satu lembar memuat dua parameter, dan baris tabelnya
            // yang membedakan — bukan alatnya.
            'measurements.*.parameter' => ['sometimes', 'nullable', Rule::in(['suhu', 'kelembaban'])],
            // Baris "Suhu Ruang" di grid — DICATAT, tapi nggak ikut ngitung.
            //
            // Di master dia beneran nggak punya konsumen: nol rumus membacanya,
            // dan di master Recorder rumus ringkasannya bahkan salah baris
            // (nunjuk baris Indikator) sampai keluar 67 °C padahal suhu ruang
            // aslinya 24,6 °C. Selisih 43 °C yang nggak pernah ketahuan siapa
            // pun — cuma mungkin kalau angkanya emang nggak pernah dipakai.
            //
            // Jadi kenapa diterima? Karena barisnya ADA di kertas dan teknisi
            // MENULISNYA di lapangan. Sebelum ini angkanya dibuang diam-diam di
            // HP, dan "diketik lalu lenyap" itu yang paling jelek dari tiga
            // pilihan: kertas dan basis data jadi beda tanpa satu pun jejak.
            //
            // Yang HIDUP dan kecetak di sertifikat itu `suhu_awal`/`suhu_akhir`
            // di blok Kondisi Lingkungan — beda hal, nama saja yang mirip.
            'measurements.*.suhu_ruang' => ['sometimes', 'nullable', 'array', 'max:20'],
            'measurements.*.suhu_ruang.*' => ['nullable', 'numeric'],
            // Baris Suhu Ruang nggak ikut menghitung apa pun, tapi teknisi
            // TETAP memotretnya — jadi tebakan mesinnya tetap bahan ukur yang
            // sah. Membuangnya berarti diam-diam mengecilkan sampel.
            'measurements.*.suhu_ruang_ocr' => ['sometimes', 'nullable', 'array', 'max:20'],
            'measurements.*.suhu_ruang_ocr.*' => ['nullable', 'array'],
            'measurements.*.suhu_ruang_ocr.*.raw_text' => ['nullable', 'string', 'max:255'],
            'measurements.*.suhu_ruang_ocr.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            // Suhu larutan per pembacaan, sejajar per-index sama `pembacaan`.
            'measurements.*.suhu' => ['sometimes', 'nullable', 'array'],
            'measurements.*.suhu.*' => ['nullable', 'numeric'],
            // As-found (sebelum alat di-adjustment) — murni dokumentasi kondisi
            // alat, TIDAK ikut hitungan GUM.
            'measurements.*.pembacaan_sebelum' => ['sometimes', 'nullable', 'array'],
            'measurements.*.pembacaan_sebelum.*' => ['nullable', 'numeric'],
            'measurements.*.suhu_sebelum' => ['sometimes', 'nullable', 'array'],
            'measurements.*.suhu_sebelum.*' => ['nullable', 'numeric'],
            // Spindle & kecepatan putar titik ini — cuma Viscometer yang
            // ngisi. Dua-duanya nentuin Fullscale, jadi ikut nentuin batas
            // keberterimaan (MPE) titik itu; lihat ViscometerProfile.
            //
            // Kode spindle-nya divalidasi APA ADANYA di sini (string pendek),
            // bukan diadu ke daftar Tabel D-1: alat yang spindle-nya nggak ada
            // di daftar tetap boleh dicatat apa adanya, dan yang nolak ngitung
            // MPE-nya nanti profilnya — dengan alasan yang kebaca, bukan 422
            // yang bikin lembar kerja lapangan nggak bisa dikirim sama sekali.
            'measurements.*.spindle' => ['sometimes', 'nullable', 'string', 'max:32'],
            // `gt:0` bukan `min:0`: RPM nol bikin Fullscale bagi nol. Brookfield
            // DV2T bisa serendah 0,01 rpm, jadi jangan dipaksa bulat.
            'measurements.*.rpm' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            // Sebagian kategori alat (mis. pH) butuh standar BEDA per titik ukur
            // (buffer 4/7/10) — kosong berarti titik ini ikut `standard_id` sesi.
            'measurements.*.standard_id' => [
                'sometimes', 'nullable',
                Rule::exists('standards', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            // Metadata OCR — opsional, sejajar sama `pembacaan` (index ke-i punya
            // pembacaan[i] + ocr[i]). OCR jalan di mobile; backend cuma nyimpen
            // fotonya, skor keyakinan, & teks mentahnya buat jejak audit. Pembacaan
            // yang datang lewat OCR mulai belum terverifikasi (lihat controller).
            'measurements.*.ocr' => ['sometimes', 'array'],
            'measurements.*.ocr.*.photo_path' => [
                'nullable', 'string',
                // Wajib nunjuk ke hasil upload /calibrations/photos — cegah path
                // sembarangan (path traversal) nyelip ke DB.
                'regex:/^measurements\/[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$/',
            ],
            'measurements.*.ocr.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'measurements.*.ocr.*.raw_text' => ['nullable', 'string', 'max:255'],
        ];

        // Tiap aturan ber-`numeric` ikut dijaga dari INF/NAN, dan penjagaannya
        // dipasang DI SINI — bukan diketik satu per satu di 26 baris di atas.
        //
        // Alasannya bukan kerapian: aturan ke-27 yang ditambahkan besok akan
        // otomatis ikut terjaga, sementara daftar yang diketik tangan adalah
        // daftar yang pasti kelupaan. Lihat docblock `AngkaTerhingga` buat
        // kegagalan yang ditutupnya (HTTP 500 + jejak tumpukan bocor).
        foreach ($aturan as $kolom => $baris) {
            if (is_array($baris) && in_array('numeric', $baris, true)) {
                $aturan[$kolom][] = new AngkaTerhingga;
            }
        }

        return $aturan;
    }

    /**
     * Kunci `spesifikasi_alat` yang isinya BLOK, bukan teks pendek.
     *
     * Lima yang pertama milik lembar Timbangan, yang keenam milik Micrometer.
     * Ditulis sebagai daftar tertutup, bukan "terima array apa saja": kolomnya
     * JSON tanpa skema, jadi tanpa daftar ini satu kunci salah ketik dari HP
     * mendarat diam-diam dan baru ketahuan waktu ada yang mencari isinya.
     *
     * Tiga yang pertama MENGGERAKKAN ANGKA (Sr/Sres & LOP, komponen
     * Eccentricity, angka Hysterisis). Dua berikutnya cuma dicatat — tapi
     * tetap masuk daftar ini, karena tanpa tempat simpan yang sah kesepuluh
     * kotak Scale Observation dan kelima kotak Effect of Tare diketik teknisi
     * lalu hilang waktu tombol kirim ditekan.
     *
     * `micrometer` menggerakkan angka paling banyak di antara semuanya: SELURUH
     * budget lembar itu lahir dari situ — pengulangan (pra-evaluasi), suhu,
     * kapasitas (yang memilih pita CMC), dan resolusi. Tanpa tempat simpan yang
     * sah, tiap sesi Micrometer pulang tanpa satu pun titik terhitung.
     */
    private const SPEK_BERBENTUK_BLOK = [
        'keterulangan',
        'eksentrisitas',
        'histeresis',
        'scale_observation',
        'effect_of_tare',
        'micrometer',
    ];

    /**
     * Aturan `spesifikasi_alat.*`: teks pendek buat kunci biasa, array buat
     * ketiga kunci blok.
     *
     * Nggak bisa ditulis sebagai dua baris aturan terpisah — Laravel MENGGABUNG
     * aturan wildcard dengan aturan kunci spesifik, jadi `string` dan `array`
     * berlaku bersamaan dan dua-duanya nggak akan pernah lolos.
     */
    /**
     * Aturan `standar_ocr` / `uut_ocr`, bentuknya ikut deret nilainya.
     *
     * @return array<string, array<int, mixed>>
     */
    private function aturanTebakanPasangan(bool $bolehObjekWaktu): array
    {
        $aturan = [];

        foreach (['standar', 'uut'] as $sisi) {
            $akar = "measurements.*.{$sisi}_ocr";
            // Lembar berblok waktu menaruh tebakannya SATU TINGKAT lebih dalam
            // — per kotak, bukan per ulangan.
            $daun = $bolehObjekWaktu ? $akar.'.*.*' : $akar.'.*';

            $aturan[$akar] = ['sometimes', 'nullable', 'array', 'max:20'];
            $aturan[$akar.'.*'] = ['nullable', 'array'];

            if ($bolehObjekWaktu) {
                // Cuma keempat kotak yang sah. Kunci lain berarti bentuknya
                // bergeser, dan tebakan yang mendarat di kotak yang nggak ada
                // bakal hilang tanpa gejala.
                $aturan[$akar.'.*.*'] = ['nullable', 'array'];
                $aturan[$akar.'.*'][] = function (string $atribut, mixed $nilai, \Closure $gagal): void {
                    if (! is_array($nilai)) {
                        return;
                    }

                    $asing = array_diff(array_keys($nilai), PenunjukanWaktu::KOTAK);

                    if ($asing !== []) {
                        $gagal("Tebakan mesin di `{$atribut}` memuat kotak yang nggak dikenal: ".implode(', ', $asing).'.');
                    }
                };
            }

            $aturan[$daun.'.raw_text'] = ['nullable', 'string', 'max:255'];
            $aturan[$daun.'.confidence'] = ['nullable', 'numeric', 'between:0,1'];
        }

        return $aturan;
    }

    private function spekBolehBerbentukBlok(): \Closure
    {
        return function (string $atribut, mixed $nilai, \Closure $gagal): void {
            $kunci = (string) mb_substr($atribut, (int) mb_strrpos($atribut, '.') + 1);

            if (in_array($kunci, self::SPEK_BERBENTUK_BLOK, true)) {
                if (! is_array($nilai)) {
                    $gagal("Blok `{$kunci}` di spesifikasi alat harus berbentuk objek, bukan teks.");
                }

                return;
            }

            if (is_array($nilai)) {
                $gagal("Kolom `{$kunci}` di spesifikasi alat harus teks, bukan objek.");

                return;
            }

            if (mb_strlen((string) $nilai) > 64) {
                $gagal("Kolom `{$kunci}` di spesifikasi alat kepanjangan (maksimal 64 karakter).");
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'equipment_id.required' => 'Alat yang mau dikalibrasi wajib diisi.',
            'equipment_id.exists' => 'Alat itu nggak ada.',
            'standard_id.exists' => 'Standar acuan itu nggak ada.',
            'tanggal_kalibrasi.required' => 'Tanggal kalibrasi wajib diisi sebelum lembar kerja dikirim.',
            'tanggal_kalibrasi.before_or_equal' => 'Tanggal kalibrasi nggak boleh di masa depan.',
            'kelembaban.between' => 'Kelembaban itu persen, jadi cuma boleh 0–100.',
            'measurements.*.titik_ukur.required' => 'Tiap baris hasil wajib punya nilai larutan standar (Solution Standard).',
        ];
    }

    /**
     * Lembar kerja yang lagi disimpen sebagai draft, belum dikirim ke admin.
     * Kolom wajibnya lebih longgar — draft itu memang catatan setengah jadi.
     */
    private function disimpanSebagaiDraft(): bool
    {
        return $this->input('status') === CalibrationSession::STATUS_DRAFT;
    }

    /**
     * Cek yang nggak bisa diomongin pakai aturan validasi biasa, karena butuh
     * baca isi baris DB-nya dulu.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Toleransi alat yang kosong SENGAJA nggak ditolak di sini, walaupun
            // tanpa itu PASS/FAIL nggak bisa diputuskan. Itu data master yang
            // cuma admin yang bisa benerin — nolak di sini bikin teknisi
            // mentok di lapangan tanpa bisa ngapa-ngapain. Yang kejadian:
            // titiknya disimpen mentah tanpa dihitung, dan penerbitan
            // sertifikatnya ketahan `CalibrationValidator` sampai admin ngisi
            // toleransinya.

            // Batas TOTAL sel grid seluruh request — bukan cuma per set point.
            //
            // Batas per-lapis (`measurements` 60 × `sensor_grid` 40 ×
            // `pembacaan` 20) itu perkalian, jadi masing-masing terlihat wajar
            // sementara hasil kalinya 48.000 baris `raw_measurements` yang
            // ditulis satu per satu di dalam SATU transaksi, di satu proses
            // 512 MB yang dipakai semua organisasi. Nggak ada sesi nyata yang
            // mendekati itu: master paling besar 6 set point × 9 termokopel × 5
            // = 270, dan 20 kanal recorder × 60 set point × 20 pembacaan pun
            // masih 24.000 — jauh di atas kebutuhan lapangan.
            //
            // Ditaruh di sini, bukan jadi aturan per-field, karena yang dijaga
            // hasil kali tiga sisi — nggak ada satu field pun yang bisa
            // menyatakannya sendiri.
            $selGrid = 0;

            foreach ((array) $this->input('measurements', []) as $i => $titik) {
                // Nomor termokopel kembar DALAM SATU set point — satu termokopel
                // fisik kehitung dua kali dan menggeser U95, keseragaman, &
                // kestabilan.
                //
                // Dicek di sini, bukan lewat aturan `distinct`: pada wildcard
                // bertingkat `distinct` membandingkan seluruh set point sekaligus,
                // jadi grid normal yang memakai sensor 3..11 di TIAP set point
                // ikut ditolak. Yang dilarang cuma kembar di dalam satu grid.
                $nomor = array_column((array) ($titik['sensor_grid'] ?? []), 'no');
                $kembar = array_values(array_unique(array_diff_assoc($nomor, array_unique($nomor))));

                if ($kembar !== []) {
                    $validator->errors()->add("measurements.$i.sensor_grid", sprintf(
                        'Nomor termokopel %s muncul lebih dari sekali di set point ini. Satu termokopel cuma '
                        .'boleh sekali per set point — nomor yang sama di set point LAIN nggak masalah.',
                        implode(', ', $kembar),
                    ));
                }

                foreach ((array) ($titik['sensor_grid'] ?? []) as $sensor) {
                    $selGrid += count((array) ($sensor['pembacaan'] ?? []));
                }

                $selGrid += count((array) ($titik['indikator'] ?? []));
            }

            if ($selGrid > self::MAKS_SEL_GRID) {
                $validator->errors()->add('measurements', sprintf(
                    'Total pembacaan grid %s sel, melewati batas %s sel per request. Kirim set point-nya '
                    .'bertahap (satu request per beberapa set point) — sesinya tetap satu.',
                    number_format($selGrid, 0, ',', '.'),
                    number_format(self::MAKS_SEL_GRID, 0, ',', '.'),
                ));
            }

            // Semua standar yang kesebut (sesi + per-titik) dimuat sekaligus di
            // sini, dipakai buat dua-duanya di bawah — biar nggak query
            // Standard berkali-kali buat baris yang sama.
            $idPerTitik = array_column($this->input('measurements', []), 'standard_id');

            /** @var Collection<int, Standard> $standarById */
            $standarById = Standard::query()
                ->whereIn('id', array_filter([$this->integer('standard_id'), ...$idPerTitik]))
                ->get()
                ->keyBy('id');

            $standar = $standarById->get($this->integer('standard_id'));

            // Standar yang sertifikatnya udah lewat masa berlaku nggak boleh
            // jadi acuan — ketertelusurannya putus, dan itu temuan asesor.
            if ($standar && ! $standar->masihBerlaku()) {
                $validator->errors()->add(
                    'standard_id',
                    'Sertifikat standar acuan ini udah kadaluarsa, jadi nggak boleh dipakai kalibrasi.',
                );
            }

            // Sama kayak standar_id sesi: standar per-titik yang di-override juga
            // wajib masih berlaku.
            foreach ((array) $this->input('measurements', []) as $i => $titik) {
                $standardIdTitik = $titik['standard_id'] ?? null;
                if ($standardIdTitik === null) {
                    continue;
                }

                $standarTitik = $standarById->get($standardIdTitik);

                if ($standarTitik && ! $standarTitik->masihBerlaku()) {
                    $validator->errors()->add(
                        "measurements.$i.standard_id",
                        'Sertifikat standar acuan titik ini udah kadaluarsa, jadi nggak boleh dipakai kalibrasi.',
                    );
                }
            }

            // Kalau ada metadata OCR: jumlahnya wajib sama persis dengan pembacaan
            // (dipetakan per-index), dan tiap foto yang dirujuk beneran ada di disk.
            foreach ((array) $this->input('measurements', []) as $i => $titik) {
                if (! isset($titik['ocr'])) {
                    continue;
                }

                $ocr = array_values((array) $titik['ocr']);
                $pembacaan = (array) ($titik['pembacaan'] ?? []);

                if (count($ocr) !== count($pembacaan)) {
                    $validator->errors()->add(
                        "measurements.$i.ocr",
                        'Jumlah data OCR harus sama dengan jumlah pembacaan di titik ini.',
                    );

                    continue;
                }

                foreach ($ocr as $j => $meta) {
                    $path = $meta['photo_path'] ?? null;
                    if ($path !== null && ! Storage::disk('arsip')->exists($path)) {
                        $validator->errors()->add(
                            "measurements.$i.ocr.$j.photo_path",
                            'Foto yang dirujuk nggak ketemu — upload dulu lewat /calibrations/photos.',
                        );
                    }
                }
            }
        });
    }
}
