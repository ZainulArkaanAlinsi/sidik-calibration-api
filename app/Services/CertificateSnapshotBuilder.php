<?php

namespace App\Services;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use App\Services\Calibration\Profiles\FlowmeterProfile;
use App\Services\Calibration\Profiles\TekananProfile;
use App\Support\Angka;
use App\Support\TataLetakSertifikat;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Nyusun ISI sertifikat sesuai struktur baku (spesifikasi poin 9), lalu
 * dibekukan ke `certificates.snapshot`.
 *
 * Kenapa dibekukan, bukan di-join ulang tiap dicetak: sertifikat itu dokumen
 * resmi yang salinannya udah dipegang pelanggan. Kalau alamat PT-nya berubah
 * tahun depan, atau alatnya dijual & datanya diedit, cetakan ulang HARUS tetap
 * sama persis kayak yang dulu diterbitin. Snapshot bikin PDF, Excel, halaman
 * verifikasi QR, dan API mustahil beda isi — semuanya baca dari sini.
 *
 * Dulu strukturnya dikunci di empat bagian spesifikasi (header informasi, tabel
 * hasil, tabel standar, footer) dan field di luar itu dilarang. K-50-15
 * (pemilik, 18 Okt 2026, `docs/permintaan-user-7.md` §52) membatalkannya:
 * sertifikat wajib persis lembar `SERTIFIKAT` master alatnya, termasuk blok
 * catatan, kepala, dan End of Certificate yang beda-beda per master. Bentuk itu
 * dinyatakan profil (`tataLetakSertifikat`, `catatanSertifikat`,
 * `isiTambahanKepala`, `isiTambahanStandarDigunakan`, `formatKolomSertifikat`,
 * `titikDicetakSertifikat`) dan dibekukan di sini. Kunci barunya hanya ditulis
 * bila profil menyatakan sesuatu — snapshot alat lain tidak berubah satu byte.
 */
class CertificateSnapshotBuilder
{
    /**
     * Dinaikin kalau bentuk snapshot berubah — snapshot lama tetap kebaca
     * apa adanya, yang berubah cuma cara view baca versi baru.
     */
    public const VERSI = 1;

    /** Catatan baku di bawah tabel hasil. Bunyinya nggak boleh diubah. */
    public const CATATAN_HASIL = [
        'The Uncertainty is taken at a Confidence Level 95% and Coverage Factor (k) = 2',
        'Calibration results are not to be announced and only apply to related tools',
    ];

    public const KODE_DOKUMEN_DEFAULT = 'SIDIK-FM-CAL-2403_Rev. 0';

    /**
     * @return array<string, mixed>
     */
    public function bangun(CalibrationSession $sesi, Certificate $sertifikat): array
    {
        $alat = $sesi->equipment;
        $pengaturan = $sesi->organization?->settings ?? [];
        $desimal = $this->desimal($alat, $sesi->organization);
        $profil = $alat ? app(CalibrationProfileRegistry::class)->untukAlat($alat) : null;

        // Sesi tanpa alat sama sekali dianggap DI DALAM lingkup — perilaku lama
        // dipertahankan. Yang mencabut klaim harus pernyataan eksplisit sebuah
        // profil, bukan ketiadaan data.
        // Per SESI, bukan per profil — lampiran membatasi rentang (Vernier
        // Caliper 0-300 mm), dan caliper 600 mm tidak boleh terbit membawa klaim.
        $dalamLingkup = $profil?->dalamLingkupAkreditasiSesi($sesi) ?? true;

        $stempelAcuan = app(PenentuVersiAcuan::class)->ringkasanStempel($sesi);

        // Bentuk lembar SERTIFIKAT master (K-50-15). Diperiksa SEKARANG, waktu
        // terbit: kunci tata letak yang salah ketik melempar, dan
        // `GenerateCertificate` mengirim pesannya ke admin — bukan terbit
        // berbentuk lama tanpa tanda.
        $kepalaTambahan = $this->kepalaTambahan($profil, $sesi);
        $tataLetak = $profil === null
            ? []
            : TataLetakSertifikat::normalisasi($profil->tataLetakSertifikat($sesi), array_keys($kepalaTambahan));
        $catatanBaku = $tataLetak['catatan'] ?? self::CATATAN_HASIL;
        unset($tataLetak['catatan']);
        $blokCatatan = $profil === null ? [] : TataLetakSertifikat::normalisasiBlok($profil->catatanSertifikat($sesi));
        // Teks berangka yang DIBEKUKAN sebagai teks (kepala, catatan) ikut
        // pemisah desimal tata letaknya (P3, pemilik 20 Okt 2026: titik seperti
        // master). Aplikasi HP membaca teks ini apa adanya, jadi dikonversi di
        // sini, bukan waktu render. Angka di `hasil` tetap angka presisi penuh.
        $tl = TataLetakSertifikat::untuk(['tata_letak' => $tataLetak]);
        $teksAngka = static fn (?string $teks): ?string => $teks === null ? null : TataLetakSertifikat::angka($teks, $tl);
        $blokCatatan = array_map(static fn (array $b): array => [
            ...$b,
            'judul' => $teksAngka($b['judul']),
            'baris' => array_map($teksAngka, $b['baris']),
            'tabel' => array_map(static fn (array $baris): array => array_map($teksAngka, $baris), $b['tabel']),
        ], $blokCatatan);
        // Kepala tambahan (`Class/Permitted Error : A/±0.15 ml` Volumetrik)
        // juga teks berangka yang dibekukan — ikut aturan yang sama.
        $kepalaTambahan = array_map($teksAngka, $kepalaTambahan);

        return [
            'versi' => self::VERSI,
            'desimal' => $desimal,
            // Judul kolom kedua tabel hasil. Lima master nulis
            // `Unit Under Test`, master Spectrophotometer nulis `UUT` — di
            // ketiga bloknya, jadi itu pilihan lab.
            //
            // Ikut DIBEKUKAN sama alasannya kayak `desimal`: sertifikat yang
            // udah terbit nggak boleh ganti judul kolom gara-gara profilnya
            // diedit sesudahnya. Snapshot lama yang belum punya kunci ini
            // dibaca `?? 'Unit Under Test'` — persis judul lamanya.
            'judul_uut' => $profil?->judulKolomUut(),
            // Judul kolom PERTAMA. Dua puluh master nulis `Standard`, master
            // Hydrometer nulis `Nominal Value` — kolomnya emang bukan nilai
            // standar melainkan angka yang terbaca di skala alatnya. Ikut
            // DIBEKUKAN sama alasannya kayak `judul_uut`; snapshot lama yang
            // belum punya kunci ini dibaca `?? 'Standard'`.
            'judul_standar' => $profil?->judulKolomStandar(),
            // `U95` jadi KOLOM per baris (Viscometer) atau satu baris ringkas
            // di bawah tabel (lima alat lain). Ikut DIBEKUKAN sama alasannya
            // kayak `judul_uut`. Snapshot lama yang belum punya kunci ini
            // dibaca `?? false` — bentuk cetaknya nggak berubah.
            'u95_per_titik' => $profil?->u95PerTitik() ?? false,
            // Judul kolom KETIGA & KEEMPAT. Ikut DIBEKUKAN sama alasannya
            // kayak `judul_uut`: sertifikat yang udah terbit nggak boleh
            // berubah bentuk gara-gara profilnya diedit sesudahnya. Snapshot
            // lama yang belum punya kunci ini jatuh ke perilaku lamanya —
            // `Correction`, dan tanpa kolom keempat.
            'judul_koreksi' => $profil?->judulKolomKoreksi(),
            'judul_sebaran' => $profil?->judulKolomSebaran(),
            'desimal_koreksi' => $profil?->desimalKolomKoreksi(),
            // Faktor cakupan yang DIKUNCI alat ini, atau null kalau `k`-nya
            // dihitung per titik dari `v_eff`. Yang membacanya cuma judul
            // kolom `U95`: Viscometer menulis `U95%, k=2` karena `k`-nya
            // memang dikunci 2 dan angka ketidakpastian tanpa faktor
            // cakupannya tidak berarti apa-apa.
            //
            // Gas Detector tidak boleh ikut: `k` keempat gasnya 1,9715 /
            // 2,0106 / 1,9744 / 1,9717, dan judul `k=2` di atas kolom itu
            // pernyataan yang SALAH di dokumen terakreditasi — masternya
            // sendiri menulis `U95% (±)`. Snapshot lama yang belum punya
            // kunci ini dibaca `?? 2.0` di blade, jadi sertifikat Viscometer
            // yang sudah terbit tidak berubah bentuk.
            'faktor_cakupan_tetap' => $profil?->faktorCakupanTetap(),
            // Baris di ATAS tabel hasil — `Spindel No. : 1,2,7` / `Speed
            // (rpm) : 63,62,62` buat Viscometer. `null` di lima alat lain,
            // baris nggak muncul. Lihat `CalibrationProfile::catatanAtasTabelHasil`.
            //
            // Ikut DIBEKUKAN sama alasannya kayak `judul_uut`: dihitung SEKALI
            // waktu sertifikat terbit, bukan dibaca ulang tiap cetak.
            'catatan_atas_hasil' => $teksAngka($profil?->catatanAtasTabelHasil($sesi)),
            'satuan' => $sesi->rawMeasurements->first()?->satuan ?? $alat?->satuan,
            // Satuan yang DICETAK di baris satuan bentuk master bila baris hasil
            // tidak membawanya. Satuan ALAT (profil, lalu `equipments.satuan`)
            // menang atas satuan pembacaan mentah: Piston/Dispensett/Buret
            // Digital menimbang dalam `g` tapi masternya mencetak `ml`
            // (`164!E20:S20`). `satuan` di atas dibiarkan — HP membacanya.
            ...($tataLetak === [] ? [] : ['satuan_cetak' => $this->satuanCetak($sesi, $profil)]),
            'header' => $this->header($sesi, $sertifikat, (bool) ($tataLetak['env_bertumpuk'] ?? false), $teksAngka),
            // Enclosure TIDAK punya tabel `Standard | UUT | Correction`. Baris
            // `uncertainty_calculations`-nya memang menyimpan Keseragaman di
            // kolom `koreksi` (wakil "koreksi terbesar", lihat
            // `EnclosureProfileBase::barisHitungan`), jadi kalau dicetak lewat
            // tabel bawaan keluarnya `Correction 0,8` — dibaca pelanggan
            // sebagai "tambahkan 0,8 °C ke pembacaan alat", padahal angka itu
            // selisih antar-posisi di dalam chamber. Isi sertifikatnya ada di
            // kunci `enclosure` di bawah; tabel titiknya sengaja kosong, sama
            // seperti Autoklaf.
            'hasil' => $profil instanceof EnclosureProfileBase ? [] : $this->hasil($sesi),
            // Autoklaf nggak punya tabel hasil empat kolom — sertifikatnya TIGA
            // bagian (A Sebaran Suhu, B Kinerja, C Tekanan) yang bentuknya beda
            // satu sama lain. `null` di tujuh alat lain, dan waktu null lembar
            // cetaknya nggak berubah sama sekali. Dibekukan apa adanya:
            // `AutoclaveCalculator` udah presisi penuh diadu ke master, tinggal
            // diformat di blade.
            'autoclave' => $sesi->adalahAutoclave() ? $sesi->hasil_autoclave : null,
            // Timbangan: DELAPAN bagian, dan tujuh di antaranya nggak punya
            // kolom `Standard`/`UUT` sama sekali (Repeatability, Effect of
            // Tare, Loading Influence, Hysterisis, Limit of Performance,
            // Weighing Uncertainty, Standard Used). Dipaksa masuk tabel empat
            // kolom, tujuh bagian itu hilang tanpa satu pun error.
            //
            // Beda dari `autoclave` di atas — yang dibaca dari kolom sesi —
            // blok ini DISUSUN waktu sertifikat terbit: angka jadinya
            // (STDEV keterulangan, selisih tiap posisi, perbandingan
            // histeresis) nggak pernah disimpan di kolom mana pun, cuma
            // masukannya yang ada di `spesifikasi_alat`. Alasan lengkapnya di
            // `TimbanganProfile::ringkasanSertifikat`.
            //
            // Dua puluh alat lain balik `null` di sini dan lewat jalur lama
            // tanpa berubah sama sekali.
            'timbangan' => $profil?->ringkasanSertifikat($sesi),
            // Flowmeter: spesifikasi pipa & pemasangan sensor. Blok kecil, tapi
            // dia satu-satunya bagian sertifikat alat ini yang TIDAK punya
            // padanan di master — sertifikat Flowrate punya labelnya (`B19`
            // Material of pipe .. `B22` Methode UFM Clamp On, hasil revisi
            // 20 Mei 2026) dengan sel isi KOSONG tanpa rumus, dan sertifikat
            // Totalizer bahkan tidak punya labelnya.
            //
            // Dipisah dari kunci `timbangan` di atas dengan sengaja: kunci itu
            // terikat ke bagian blade bergaya Timbangan, dan isi Flowmeter yang
            // masuk ke situ bakal dirender sebagai delapan bagian penimbangan —
            // nol error, lembar yang salah.
            //
            // `instanceof`, bukan hook boolean baru di `CalibrationProfile`:
            // yang butuh cuma dua profil, dan hook di sana berarti dua puluh
            // enam alat lain ikut membawa method yang selalu `null`.
            'flowmeter' => $profil instanceof FlowmeterProfile
                ? $profil->spesifikasiPipaSertifikat($sesi)
                : null,
            // Tekanan: tabel dua arah (UP/DOWN + histeresis). `instanceof` dengan
            // alasan yang sama seperti `flowmeter` di atas — yang butuh cuma
            // keluarga ini. Snapshot lama tidak punya kuncinya dan blade
            // melewatinya.
            'tekanan' => $profil instanceof TekananProfile
                ? $profil->tabelSertifikatTekanan($sesi)
                : null,
            // Enclosure (Oven, Furnace, Bath, Inkubator, Refrigerator): DUA
            // bagian, persis sheet `SERTIFIKAT` kedua master enclosure —
            // A) Sebaran Suhu per posisi termokopel, B) Kinerja Enklosur.
            // `instanceof` dengan alasan yang sama seperti `flowmeter` &
            // `tekanan`. Snapshot lama tidak punya kuncinya dan blade
            // melewatinya. Lihat [enclosure()].
            'enclosure' => $profil instanceof EnclosureProfileBase
                ? $this->enclosure($sesi, $profil)
                : null,
            // Kalimat baku di bawah tabel hasil — tetap list<string> (aplikasi HP
            // membacanya sebagai teks). Profil boleh menggantinya dengan bunyi
            // persis masternya (`tataLetakSertifikat()['catatan']`, mis. penafian
            // berstrip " - Calibration results … -").
            'catatan' => $catatanBaku,
            // Tiga kunci K-50-15 di bawah HANYA ditulis bila profil menyatakan
            // sesuatu. Kunci `null`/kosong di semua snapshot membuat
            // `sertifikat:bangun-ulang` (pembanding `==`) menulis ulang seluruh
            // sertifikat terbit beserta PDF-nya padahal isinya tidak bergeser —
            // alasan yang sama dengan `paket_acuan_versi` di bawah.
            //
            // `tata_letak`: TIMPAAN saja, bukan hasil gabung — dibaca
            // `TataLetakSertifikat::untuk()` di atas bawaan bentuk lama.
            ...($tataLetak === [] ? [] : ['tata_letak' => $tataLetak]),
            // `catatan_blok`: blok catatan/legenda khas alat dalam urutan master.
            // Kunci terpisah dari `catatan` karena HP membaca `catatan` sebagai
            // daftar teks; blok berstruktur di sana tercetak sebagai `{…}`.
            ...($blokCatatan === [] ? [] : ['catatan_blok' => $blokCatatan]),
            // Field kepala di luar enam belas field baku (`header` tetap enam
            // belas — dijaga `CertificateSnapshotTest`).
            ...($kepalaTambahan === [] ? [] : ['header_tambahan' => $kepalaTambahan]),
            'standar_digunakan' => $this->standarDigunakan($sesi, $profil),
            'footer' => $this->footer($sesi, $sertifikat, $pengaturan),
            // Bukan bagian dari dokumen cetak — dipakai halaman verifikasi &
            // API buat nampilin status alat. Ditaruh di luar empat bagian baku
            // supaya jelas ini metadata, bukan field sertifikat.
            'meta' => [
                'keputusan' => $sesi->keputusan,
                'qr_token' => $sertifikat->qr_token,
                'qr_payload' => $sertifikat->qr_payload,
                // Klaim akreditasinya BERSYARAT — lihat
                // `CalibrationProfile::dalamLingkupAkreditasi()`.
                //
                // Dibekukan di snapshot, bukan dievaluasi ulang waktu cetak:
                // begitu KAN menambah satu jenis alat ke lampiran, sertifikat
                // LAMA untuk alat itu tidak boleh mendadak mengklaim akreditasi
                // surut. Alasannya sama dengan `desimal` & `judul_uut` —
                // cetakan ulang tahun depan harus keluar sama.
                //
                // Nilainya `null`, bukan string kosong: blade membedakan
                // keduanya lewat `! empty()`, dan `''` yang lolos sebagai "ada"
                // mencetak "Terakreditasi KAN · No. —".
                'organization' => [
                    'nama' => $sesi->organization?->nama,
                    'alamat' => $sesi->organization?->alamat,
                    'no_akreditasi' => $dalamLingkup ? $sesi->organization?->no_akreditasi : null,
                    'standar_akreditasi' => $dalamLingkup ? $sesi->organization?->standar_akreditasi : null,
                    // Dipisah dari kedua kunci di atas supaya pembaca snapshot
                    // bisa membedakan "di luar lingkup" dari "organisasinya
                    // memang belum mengisi nomor akreditasi".
                    'dalam_lingkup_akreditasi' => $dalamLingkup,
                ],
            ],
            // Versi data acuan yang menghitung sesi ini, DIBEKUKAN dari stempel
            // `uncertainty_calculations.paket_acuan_versi_id` (Studio Data
            // Acuan FR-20, BR-11) — bukan dicari ulang dari tanggal. Kunci
            // tambahan saja: `VERSI` tidak naik, pembaca memakai `?? null`.
            //
            // HANYA ditulis bila hasilnya berstempel. Tanpa stempel (sumber
            // JSON, sesi lama, stempel tidak seragam) kuncinya TIDAK ADA —
            // bukan `null`: `sertifikat:bangun-ulang` membandingkan seluruh
            // snapshot dengan `==`, dan kunci `null` baru membuat semua
            // sertifikat terbit "berubah" lalu ditulis ulang beserta
            // `validasi` & PDF-nya, padahal isinya tidak bergeser satu angka
            // pun (temuan tinjau P1 S-1, 9 Okt 2026). Lembar perhitungan dan
            // respons validasi tetap selalu memuat kuncinya (boleh `null`).
            ...($stempelAcuan === null ? [] : ['paket_acuan_versi' => $stempelAcuan]),
        ];
    }

    /**
     * Header informasi — 16 field, persis urutan & nama di spesifikasi.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  (callable(?string): ?string)|null  $teksAngka  pemisah desimal tata letak (lihat [bangun])
     */
    private function header(CalibrationSession $sesi, Certificate $sertifikat, bool $envBertumpuk = false, ?callable $teksAngka = null): array
    {
        $alat = $sesi->equipment;
        $pelanggan = $alat?->customer;

        return [
            'certificate_number' => $sertifikat->nomor,
            // Satu sesi = satu halaman sertifikat. Kalau nanti tabel hasilnya
            // panjang & kepecah, angka ini yang diubah, bukan strukturnya.
            'page' => '1 of 1',
            // Lima field di bawah: YANG DICATAT TEKNISI menang, master cuma
            // cadangan. Teknisi megang alat fisiknya dan nyalin dari badan
            // alat; master diisi admin waktu pendaftaran dan bisa udah basi
            // (unit ketuker, PT pindah alamat). Kalau master yang menang,
            // sertifikat resmi bisa nyebut seri alat yang bukan alat yang
            // beneran dikalibrasi — dan itu temuan asesor.
            //
            // `??` PER FIELD, bukan per blok: teknisi boleh ngisi seri doang
            // dan ngosongin merk, dan yang kosong itu tetap jatuh ke master.
            'owner' => $sesi->pemilik_nama ?: $pelanggan?->nama,
            'order_number' => $sesi->nomor_order,
            'address' => $sesi->pemilik_alamat ?: $pelanggan?->alamat,
            'received_date' => $sesi->tanggal_terima?->toDateString(),
            'equipment_name' => $alat?->nama_alat,
            'manufacturer' => $sesi->alat_merk ?: $alat?->merk,
            'calibration_location' => $this->lokasiKalibrasi($sesi),
            'model_type' => $sesi->alat_model ?: $alat?->model,
            'calibration_date' => $sesi->tanggal_kalibrasi?->toDateString(),
            'serial_number' => $sesi->alat_serial_number ?: $alat?->serial_number,
            'calibration_method' => $this->metodeKalibrasi($sesi),
            'capacity_graduation' => $teksAngka ? $teksAngka($this->kapasitasGraduasi($sesi)) : $this->kapasitasGraduasi($sesi),
            'env_condition' => $teksAngka ? $teksAngka($this->kondisiLingkungan($sesi, $envBertumpuk)) : $this->kondisiLingkungan($sesi, $envBertumpuk),
            'technician_id' => $sesi->teknisi?->kodeTeknisi(),
        ];
    }

    /**
     * Tabel hasil kalibrasi: Standard Value | Unit Under Test | Correction |
     * U95%. Empat kolom, nggak lebih.
     *
     * - Standard Value = nilai acuan di titik itu (`titik_ukur`)
     * - Unit Under Test = rata-rata pembacaan alat yang dikalibrasi
     * - Correction = angka yang harus DITAMBAHKAN ke pembacaan alat biar ketemu
     *   nilai benar, jadi = Standard Value − Unit Under Test
     * - U95% = ketidakpastian diperluas (k=2)
     *
     * @return list<array<string, float|null>>
     */
    private function hasil(CalibrationSession $sesi): array
    {
        $alat = $sesi->equipment;
        $organisasi = $sesi->organization;

        // Profil alat dipakai buat kolom "Remark" — keterangan parameter per
        // titik (Chlorine: "Free Chlorine" / "Total Chlorine"). Alat yang
        // profilnya nggak nyediain remark balikin null, dan kolomnya nggak
        // dicetak sama sekali.
        $profil = $alat
            ? app(CalibrationProfileRegistry::class)->untukAlat($alat)
            : null;

        // Sertifikat dicetak dalam satuan ALAT PELANGGAN, bukan satuan budget.
        //
        // Cuma Flowmeter yang menjawab selain `null` sejauh ini: budget-nya
        // sengaja dipindah ke L/Lpm supaya satu mesin hitung melayani m3/h,
        // usg/min, dan kg/h sekaligus — dan yang tersimpan di
        // `uncertainty_calculations` angka L/Lpm itu. Dicetak apa adanya, alat
        // yang layarnya menunjukkan `3,0 m3/h` terbit `50,0 Lpm`: setara, tapi
        // bukan angka yang dibaca teknisi maupun pelanggan.
        //
        // Dihitung SEKALI di sini, bukan per baris: dia membaca blok sesi dan
        // menyapu seluruh baris mentah buat densitas per titik.
        //
        // Profil lain balik `null` dari kelas dasarnya dan lewat jalur yang
        // persis sama seperti sebelumnya — nol baris kesenggol.
        $cetak = $profil?->cetakDalamSatuanAlat($sesi);

        $baris = self::titikUrut($sesi)
            ->map(function ($titik) use ($sesi, $alat, $organisasi, $profil, $cetak): array {
                // Desimal DIHITUNG PER TITIK, bukan sekali buat seluruh tabel.
                //
                // Alat yang resolusinya berubah per rentang (Turbidimeter:
                // 0,01 / 0,1 / 1) sebelumnya kepaksa ikut satu angka `resolusi`,
                // jadi titik 100 NTU kecetak `101,00` — dua digit yang alatnya
                // nggak bisa tampilkan. Di sertifikat terakreditasi itu ngaku
                // ketelitian yang nggak ada.
                //
                // Aturannya tetap cuma diputus di `Organization::desimalSertifikat`
                // (termasuk override manual di pengaturan organisasi); yang beda
                // di sini cuma resolusi mana yang disodorin.
                $resolusi = $alat?->resolusiPada((float) $titik->titik_ukur);

                // Profil alat menang kalau dia nyatain sendiri. Refractometer
                // nyetak 5 desimal walau resolusinya ngasih 4 — di 4 desimal
                // U95% `0,00053` runtuh jadi `0,0005`, kehilangan angka penting
                // di kolom yang justru jadi inti sertifikat. Angkanya dari
                // sertifikat master yang beneran kecetak, bukan diturunkan.
                // Per BARIS dulu, baru per alat, baru aturan umum. Yang
                // butuh per baris cuma Viscometer — master terbarunya
                // memformat tiap titik beda (`0.00` di 100 cP, `0` di
                // 3000 cP) karena skalanya beda ratusan kali. Delapan alat
                // lain balik null di hook pertama dan jatuh ke jalur yang
                // persis sama seperti sebelumnya.
                //
                // Hook per baris dipanggil lewat varian ber-SESI: Anak
                // Timbangan mencetak desimal resolusi neraca yang menimbang
                // keping itu, dan neracanya dipilih dari centang sesi. Bawaan
                // varian itu meneruskan ke `desimalSertifikatTitik()`, jadi
                // alat lain tidak bergeser. Satuannya satuan yang DICETAK —
                // label yang sama dengan kunci `satuan` di bawah.
                $satuanCetak = $cetak['satuan']
                    ?? $profil?->satuanTitikSesi($sesi, (float) $titik->titik_ukur, (int) $titik->titik_ke, $alat);

                $desimal = $profil?->desimalSertifikatTitikSesi($sesi, (float) $titik->titik_ukur, $satuanCetak)
                    ?? $profil?->desimalSertifikat()
                    ?? ($organisasi
                        ? $organisasi->desimalSertifikat($resolusi)
                        : Angka::desimalDariResolusi($resolusi));

                $remark = $profil?->remarkTitikSesi($sesi, (int) $titik->titik_ke, (float) $titik->titik_ukur);
                // Identitas rentang (sel gabung) & kolom tambahan dari data sesi
                // (K-50-15). Ditulis hanya bila profil menyatakannya.
                $kunciGabung = $profil?->kunciGabungTitik($sesi, (float) $titik->titik_ukur, (int) $titik->titik_ke);
                $tambahan = $profil?->kolomTambahanTitik($sesi, (float) $titik->titik_ukur, (int) $titik->titik_ke) ?? [];

                // Format sel master per kolom & baris yang disembunyikan master
                // (K-50-15). Dua-duanya hanya mengatur CETAK — nilai di baris ini
                // tetap presisi penuh — dan hanya ditulis bila bukan bawaan, jadi
                // baris alat lain tidak berubah satu kunci pun.
                $formatKolom = $profil === null
                    ? []
                    : TataLetakSertifikat::normalisasiFormat(
                        $profil->formatKolomSertifikat($sesi, (float) $titik->titik_ukur, $satuanCetak),
                    );
                $dicetak = $profil?->titikDicetakSertifikat($sesi, (float) $titik->titik_ukur, (int) $titik->titik_ke) ?? true;

                // Nilai acuan yang TERCETAK di kolom `Standard Value`.
                //
                // Buat dua puluh satu alat itu `titik_ukur` apa adanya. Buat
                // kelompok Waktu dan Frekuensi `titik_ukur` menyimpan SET POINT
                // — penunjukan alat pelanggan, bukan nilai acuan — sehingga
                // nilai acuannya harus disusun balik dari koreksinya. Lihat
                // [CalibrationProfile::nilaiStandarDariKoreksi].
                $nilaiStandar = $profil?->nilaiStandarDariKoreksi()
                    ? (float) $titik->rata_rata + (float) $titik->koreksi
                    : (float) $titik->titik_ukur;

                // Identitas buat alat yang nggak minta konversi — jalur lama
                // persis, nol pembungkus.
                //
                // Yang MELEDAK kalau konversinya gagal, bukan jatuh ke angka
                // budget. `null` di sini artinya faktor/densitas yang dipakai
                // waktu menghitung udah nggak bisa ditemukan lagi — barisnya
                // ada di `uncertainty_calculations` tapi bahan baliknya nggak,
                // jadi datanya bergeser sesudah hitungannya tersimpan.
                //
                // Nyetak angka budget dengan label satuan alat itu kekeliruan
                // paling mahal yang bisa terjadi di berkas ini: sertifikat
                // terakreditasi yang angkanya 16,7x, dan nggak ada satu pun
                // kolom yang keliatan ganjil. `GenerateCertificate` nangkep
                // lemparan ini, nyetempel sertifikatnya `gagal`, dan ngirim
                // pesan ini ke admin lewat `SertifikatGagal` — jadi yang
                // kejadian bukan diem, tapi sebabnya kebaca.
                $konversi = $cetak === null
                    ? static fn (float $nilai): float => $nilai
                    : static function (float $nilai) use ($cetak, $titik): float {
                        $hasil = ($cetak['ubah'])($nilai, (int) $titik->titik_ke);

                        if ($hasil === null) {
                            throw new RuntimeException(sprintf(
                                'Titik %d nggak bisa dibalikin ke satuan alat (`%s`). Hitungannya '
                                .'tersimpan dalam satuan budget, dan bahan buat ngebaliknya — faktor '
                                .'satuan atau densitas fluida titik ini — nggak ketemu lagi di baris '
                                .'mentahnya. Sertifikatnya nggak diterbitkan: angka budget yang '
                                .'dicetak berlabel `%s` bakal meleset sampai belasan kali lipat tanpa '
                                .'satu pun kolom yang keliatan ganjil. Periksa `flow_densitas_uut` '
                                .'titik ini, lalu jalanin `php artisan kalibrasi:hitung-ulang`.',
                                (int) $titik->titik_ke,
                                $cetak['satuan'],
                                $cetak['satuan'],
                            ));
                        }

                        return $hasil;
                    };

                return [
                    'titik_ke' => (int) $titik->titik_ke,
                    'standard_value' => $konversi($nilaiStandar),
                    // Kolom "Remark" di sertifikat asli. Null buat alat yang
                    // titiknya nggak punya keterangan parameter.
                    'remark' => $remark,
                    // UUT Proving Ring jumlah DIVISI dial, bukan gaya — ikut
                    // dibagi faktor kgf, angkanya melar seratus kali lipat dan
                    // berlabel satuan yang bukan miliknya.
                    'unit_under_test' => ($profil?->uutIkutSatuanAlat() ?? true)
                        ? $konversi((float) $titik->rata_rata)
                        : (float) $titik->rata_rata,
                    // Kolom sebaran (RRPE / Repeatability). Angkanya PERSEN,
                    // jadi TIDAK ikut konversi satuan — persen tetap persen.
                    // Dibaca dari jejak audit karena `uncertainty_calculations`
                    // tidak punya kolomnya, dan menambah kolom baru buat satu
                    // kelompok alat itu pilihan terakhir (AGENTS §Alur Kerja 4).
                    'sebaran' => self::sebaranTitik($titik),
                    // Kolom ketiga yang memang TIDAK punya nilai di titik ini.
                    //
                    // Titik nol Proving Ring: faktor kalibrasi = nilai standar
                    // dibagi pembacaan, dan pembacaannya nol. Master mencetak
                    // `-`. Kolom `koreksi` di database `NOT NULL`, jadi yang
                    // tersimpan penampung 0 — dan mencetak penampung itu berarti
                    // menerbitkan "0 kgf per divisi", pernyataan yang salah dan
                    // kelihatan wajar.
                    'koreksi_kosong' => self::koreksiKosong($titik),
                    // Tanda cetak per alat — lihat `tandaKoreksiSertifikat()`.
                    //
                    // Dikonversi DULU baru dikasih tanda. Faktornya selalu
                    // positif (densitas dan faktor satuan dua-duanya > 0), jadi
                    // urutannya nggak ngubah hasil — ditulis begini biar yang
                    // dibaca "koreksi dalam satuan alat, lalu tandanya", bukan
                    // "angka bertanda yang dikonversi".
                    'correction' => ($profil?->tandaKoreksiSertifikat() ?? 1) * $konversi((float) $titik->koreksi),
                    // U95 ikut dikonversi. Ketinggalan, dia jadi satu-satunya
                    // kolom yang masih bersatuan budget di tabel yang seluruh
                    // kolom lainnya udah pindah — dan di lembar m3/h angkanya
                    // 16,7x terlalu besar, yang kebaca sebagai alat jelek,
                    // bukan sebagai bug.
                    'u95' => $konversi((float) $titik->ketidakpastian_diperluas),
                    // Faktor cakupan yang dipakai buat U95 titik ini.
                    //
                    // Dibekukan karena sertifikat master nyetaknya di bawah
                    // TIAP tabel hasil: "The Uncertainty is taken at a
                    // Confidence Level 95 % and Coverage Factor ( k ) = 3".
                    // Angkanya beda per kelompok (Holmium 3,18; Didynium 2,36;
                    // %T 2,01), jadi nggak bisa diwakili satu nilai di level
                    // sertifikat.
                    //
                    // Sertifikat yang udah terbit nggak punya kunci ini —
                    // pembacanya ngosongin barisnya, bukan ngarang k.
                    // Desimal khusus baris `Uncertainty U95% = ±`, kalau alat
                    // ini nyetaknya beda dari kolom hasil di atasnya. Master
                    // spektro nulis `0,50 %T` sementara UUT & Correction di
                    // blok yang sama pakai tiga desimal (`9,665`).
                    //
                    // Ikut DIBEKUKAN, sama alasannya kayak `desimal` & `satuan`:
                    // sertifikat yang udah terbit nggak boleh berubah bentuk
                    // gara-gara profilnya diedit sesudahnya. `null` = ikut
                    // `desimal` titik, persis perilaku lama.
                    // Per BARIS dulu, baru per alat — sama pola dengan
                    // `desimal` di atas. Yang butuh per baris cuma Gas
                    // Detector: master memformat U95 keempat gasnya `0.0` ·
                    // `0.0` · `0.0` · `0.00`, dan `U95` oksigen 0,887 runtuh
                    // jadi `0,9` kalau dipukul rata satu desimal.
                    'desimal_u95' => $profil?->desimalU95TitikSesi($sesi, (float) $titik->titik_ukur, $satuanCetak)
                        ?? $profil?->desimalU95(),
                    'faktor_cakupan_k' => $titik->faktor_cakupan_k === null
                        ? null
                        : (float) $titik->faktor_cakupan_k,
                    // Desimal angka `k` di kalimat di bawah tabel. Master
                    // spektro nyimpen 3,182446… tapi nyetak `3`.
                    //
                    // Ikut DIBEKUKAN sama alasannya kayak `desimal_u95`:
                    // sertifikat yang udah terbit nggak boleh berubah bunyi
                    // gara-gara profilnya diedit sesudahnya. `null` = perilaku
                    // lama (2 desimal, nol di belakang dibuang).
                    'desimal_k' => $profil?->desimalFaktorCakupan(),
                    // Nilai mentahnya TETAP presisi penuh — ini cuma ngatur
                    // berapa digit yang ditulis. Nggak ada pembulatan data.
                    'desimal' => $desimal,
                    // Satuan DI TITIK INI, buat alat yang nyampur satuan dalam
                    // satu lembar (Conductivity: 25 & 1412 µS/cm, 111 mS/cm).
                    //
                    // Ikut DIBEKUKAN ke snapshot, sama alasannya kayak
                    // `desimal`: sertifikat yang udah terbit nggak boleh
                    // berubah bentuk gara-gara master alat diedit sesudahnya.
                    //
                    // Ditambah SEBELUM sertifikat Conductivity pertama terbit —
                    // sesudah itu betulinnya mesti lewat `sertifikat:bangun-ulang`
                    // ke dokumen yang mungkin udah dipegang pelanggan.
                    //
                    // `null` buat alat bersatuan seragam; PDF & layar jatuh ke
                    // satuan alat kayak biasa, jadi empat alat lain nggak
                    // berubah sama sekali.
                    //
                    // Kalau alatnya minta dicetak dalam satuannya sendiri,
                    // LABELNYA ikut pindah bareng angkanya. Dua-duanya dari
                    // satu sumber — label yang pindah tanpa angkanya (atau
                    // sebaliknya) itu persis bentuk kekeliruan yang bikin
                    // `cetakDalamSatuanAlat()` ada.
                    'satuan' => $satuanCetak,
                    // Koreksi negatif yang membulat ke nol kecetak `-0,0` atau
                    // `0,0` — beda per alat, dibaca dari master masing-masing
                    // (lihat `CalibrationProfile::tandaNolDicetak()`).
                    //
                    // Ikut dibekukan sama alasannya kayak `desimal` & `satuan`.
                    // Sertifikat yang udah terbit nggak punya kunci ini, dan
                    // pembacanya jatuh ke `true` — persis perilaku lamanya, jadi
                    // dokumen yang udah dipegang pelanggan nggak berubah bentuk.
                    'tanda_nol' => $profil?->tandaNolDicetak() ?? true,
                    // Kolom Standard Value: nol di belakang dibuang (`100`)
                    // atau ditulis penuh (`100,0`). Beda per master — lihat
                    // `CalibrationProfile::nolBelakangStandarDibuang()`.
                    //
                    // Ikut dibekukan; sertifikat lama yang belum punya kunci
                    // ini dibaca `?? true` di PDF, persis perilaku lamanya.
                    'standar_ringkas' => $profil?->nolBelakangStandarDibuang() ?? true,
                    // Format sel master per kolom (`CalibrationProfile::
                    // formatKolomSertifikat`) — menang atas `desimal*` di atas
                    // untuk kolom yang disebutnya. Lihat `TataLetakSertifikat::teksSel`.
                    ...($formatKolom === [] ? [] : ['format' => $formatKolom]),
                    // Baris yang disembunyikan master: tetap di snapshot, tidak
                    // dicetak di PDF maupun Excel (`TataLetakSertifikat::barisDicetak`).
                    ...($dicetak ? [] : ['dicetak' => false]),
                    ...($kunciGabung === null ? [] : ['kunci_gabung' => $kunciGabung]),
                    ...($tambahan === [] ? [] : ['tambahan' => $tambahan]),
                ];
            })
            ->values()
            ->all();

        return $this->bubuhkanR2($baris, $profil);
    }

    /**
     * Tempelin R² kelompok ke tiap barisnya.
     *
     * Ditempel BERULANG di semua baris sekelompok, sama kayak `u95` &
     * `faktor_cakupan_k` — bukan karena tiap titik punya R² sendiri (nggak,
     * lihat `CalibrationProfile::koefisienDeterminasi()`), tapi supaya
     * pembacanya nggak usah ngerti struktur kelompok buat nemuinnya. Snapshot
     * ini dibaca PDF, halaman verifikasi QR, dan Excel; tiga-tiganya udah
     * ngambil angka kelompok dari baris pertama.
     *
     * Dikelompokkan pakai `remark`, persis kayak PDF-nya. Alat yang `remark`-nya
     * null lewat sebagai satu kelompok tanpa judul, dan profilnya balikin null,
     * jadi lima alat lain nggak kesenggol sama sekali.
     *
     * @param  list<array<string, mixed>>  $baris
     * @return list<array<string, mixed>>
     */
    private function bubuhkanR2(array $baris, ?CalibrationProfile $profil): array
    {
        if ($profil === null) {
            return $baris;
        }

        $r2 = [];

        foreach ($baris as $b) {
            $r2[(string) ($b['remark'] ?? '')][] = $b;
        }

        $r2 = array_map(
            fn (array $kelompok): ?float => $profil->koefisienDeterminasi($kelompok),
            $r2,
        );

        return array_map(
            // Kunci `r2` SELALU ada begitu alatnya lewat sini, walau isinya
            // null. Sertifikat lama yang snapshot-nya belum punya kunci ini
            // dibaca `?? null` di PDF — dokumen yang udah dipegang pelanggan
            // nggak berubah bentuk gara-gara kolomnya ditambahin sekarang.
            fn (array $b): array => [...$b, 'r2' => $r2[(string) ($b['remark'] ?? '')]],
            $baris,
        );
    }

    /**
     * Isi sertifikat Enclosure: A) Sebaran Suhu + B) Kinerja Enklosur.
     *
     * Bentuknya dibaca dari sheet `SERTIFIKAT` kedua master enclosure
     * (`…Enclosure_Constant_Yokogawa.xlsm` & `…Enclosure_Recorder.xlsm`):
     *
     *   A  `B25`  Pembacaan Indikator Enklosur = `PERHITUNGAN FC!R40`
     *      `F24:S24` Sensor No. 1..9 — "(No. 1 Sensor Acuan)"
     *      `F25..`   Terukur = rata-rata terkoreksi sensor (`FC!Z23..`)
     *      `F26..`   Koreksi = Terukur − Indikator (`F25-$B$25`)
     *      `U25`     U95% ± per set point
     *   B  Suhu Setting (`FC!C21`) · Indikator · Keseragaman (`R42`) ·
     *      Kestabilan (`R41`) · Variasi Keseluruhan (`R43`)
     *
     * Angkanya TIDAK dihitung di sini. Semuanya sudah disimpan profil di
     * `type_b_components` (`sumber: sebaran_sensor`) waktu sesi dihitung —
     * jalur yang sama dipakai preview, simpan, validasi, dan
     * `kalibrasi:hitung-ulang`. Yang dikerjakan di sini cuma menyusun ulang
     * jadi tabel, presisi penuh; pembulatan urusan blade.
     *
     * ## Kolom = POSISI termokopel, bukan urutan per set point
     *
     * Master menomori kolom 1..9 (bukan nomor termokopel: Type N mulai no. 3).
     * Di sistem ini posisinya tidak dicatat, jadi diturunkan dari aturan yang
     * sudah dipakai kalkulator untuk Sensor Acuan: termokopel bernomor terkecil
     * = posisi 1 (`docs/pertanyaan-lab-enclosure.md` #13). Urutannya diambil
     * dari GABUNGAN nomor di seluruh sesi, bukan per set point — kalau satu
     * termokopel kosong di satu set point, kolomnya kosong di baris itu, bukan
     * seluruh angka di kanannya bergeser satu kolom ke posisi yang salah.
     *
     * Jejak `sebaran_sensor` yang hilang bikin sertifikat GAGAL terbit, bukan
     * terbit tanpa sebaran: tabel yang cuma separuh terisi di dokumen
     * terakreditasi kebaca seperti hasil pengukuran. `GenerateCertificate`
     * menangkap lemparan ini dan mengirim pesannya ke admin.
     *
     * @return array<string, mixed>
     */
    private function enclosure(CalibrationSession $sesi, EnclosureProfileBase $profil): array
    {
        $titik = self::titikUrut($sesi)->values();
        $jejakPerTitik = [];

        foreach ($titik as $t) {
            $jejak = collect(is_array($t->type_b_components) ? $t->type_b_components : [])
                ->first(fn ($k): bool => is_array($k) && ($k['sumber'] ?? null) === 'sebaran_sensor');

            if (! is_array($jejak) || ! is_array($jejak['sensor'] ?? null) || $jejak['sensor'] === []) {
                throw new RuntimeException(sprintf(
                    'Set point %s °C (titik %d) nggak punya jejak `sebaran_sensor` — tabel Sebaran Suhu & '
                    .'Kinerja Enklosur nggak bisa disusun. Sertifikatnya nggak diterbitkan: tabel yang '
                    .'separuh terisi kebaca seperti hasil pengukuran. Jalankan `php artisan '
                    .'kalibrasi:hitung-ulang` untuk sesi ini, lalu terbitkan ulang.',
                    rtrim(rtrim(number_format((float) $t->titik_ukur, 6, '.', ''), '0'), '.'),
                    (int) $t->titik_ke,
                ));
            }

            $jejakPerTitik[] = $jejak;
        }

        // Nomor termokopel di seluruh sesi, terurut → posisi 1..N.
        $nomor = collect($jejakPerTitik)
            ->flatMap(fn (array $j): array => array_map(static fn ($s): int => (int) $s['no'], $j['sensor']))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $posisiDari = array_flip($nomor);

        $setPoint = [];

        foreach ($titik as $i => $t) {
            $jejak = $jejakPerTitik[$i];
            $sensor = array_fill(0, count($nomor), null);

            foreach ($jejak['sensor'] as $s) {
                $p = $posisiDari[(int) $s['no']];
                $sensor[$p] = [
                    'posisi' => $p + 1,
                    'no' => (int) $s['no'],
                    'channel' => isset($s['channel']) ? (int) $s['channel'] : null,
                    'terukur' => (float) $s['rata_rata_terkoreksi'],
                    'koreksi' => (float) $s['koreksi_vs_indikator'],
                ];
            }

            $acuan = isset($jejak['sensor_acuan']) ? (int) $jejak['sensor_acuan'] : null;

            $setPoint[] = [
                'titik_ke' => (int) $t->titik_ke,
                'set_point' => (float) $t->titik_ukur,
                // Rata-rata pembacaan Indikator enclosure — `rata_rata` baris
                // ini (`EnclosureProfileBase::barisHitungan`). Kolom `Koreksi`
                // tiap sensor diukur relatif ke angka ini.
                'indikator' => (float) $t->rata_rata,
                'sensor_acuan' => $acuan,
                'posisi_acuan' => $acuan !== null && isset($posisiDari[$acuan]) ? $posisiDari[$acuan] + 1 : null,
                'sensor' => $sensor,
                'keseragaman' => (float) $jejak['keseragaman'],
                'kestabilan' => (float) $jejak['kestabilan'],
                'variasi_keseluruhan' => (float) $jejak['variasi_keseluruhan'],
                // U95 YANG DILAPORKAN (sudah MAX dengan lantai CMC).
                'u95' => $t->ketidakpastian_diperluas === null ? null : (float) $t->ketidakpastian_diperluas,
                'faktor_cakupan_k' => $t->faktor_cakupan_k === null ? null : (float) $t->faktor_cakupan_k,
            ];
        }

        // Varian master menentukan format sel cetak (Recorder `0.00`,
        // Constant/Yokogawa `0.0`). Kalibrator dibaca dari jejak `konteks_sesi`
        // yang ditulis profil waktu sesi dihitung.
        $konteks = collect(is_array($titik->first()?->type_b_components) ? $titik->first()->type_b_components : [])
            ->first(fn ($k): bool => is_array($k) && ($k['sumber'] ?? null) === 'konteks_sesi');
        $format = $profil->formatCetakSertifikat(is_array($konteks) ? ($konteks['merk_kalibrator'] ?? null) : null);

        return [
            'satuan' => EnclosureProfileBase::SATUAN,
            // Ikut DIBEKUKAN, sama alasannya seperti `desimal` di tabel hasil.
            // Per KOLOM (`null` = format General), lihat
            // `EnclosureProfileBase::FORMAT_CETAK`.
            'varian_master' => $format['varian'],
            'desimal' => $format['desimal'],
            'tanda_nol' => $profil->tandaNolDicetak(),
            'posisi' => array_map(
                static fn (int $no, int $i): array => ['posisi' => $i + 1, 'no' => $no],
                $nomor,
                array_keys($nomor),
            ),
            'set_point' => $setPoint,
        ];
    }

    /**
     * Tabel standar: Name | Merk/Type | Serial Number | Traceable to SI through.
     *
     * Isinya gabungan standar per titik (mis. buffer pH 4/7/10 — beda tiap
     * titik), standar acuan sesi, dan thermohygro. Di-dedupe by id: standar
     * yang sama dipakai di tiga titik tetap ditulis sekali.
     *
     * @return list<array<string, string|null>>
     */
    /**
     * Titik ukur dalam urutan cetaknya: `titik_ke` dulu, `id` pemecah serinya.
     *
     * ## Kenapa diurutkan di sini, bukan cuma diandalkan ke relasinya
     *
     * Relasi [CalibrationSession::uncertaintyCalculations] memang sudah
     * ber-`ORDER BY` sejak 3 Sep 2026, tapi itu cuma menjaga jalur baca dari
     * database. Koleksinya bisa sampai ke sini lewat jalan lain — `setRelation`
     * di test, hasil `map`/`merge` di pemanggil, relasi yang sudah nempel di
     * memori dari query sebelumnya — dan yang dihasilkan dicetak APA ADANYA ke
     * sertifikat terakreditasi.
     *
     * Yang bikin lapis kedua ini wajib: urutannya bergeser TANPA error. 3 Sep
     * 2026, dua kali `sertifikat:bangun-ulang` berturut-turut di MySQL yang sama
     * dengan kode yang sama memberi hasil beda-beda, dan tiap jalan menulis
     * ulang PDF sertifikat yang sudah dipegang pelanggan.
     *
     * ## Kenapa `id` ikut, bukan cuma `titik_ke`
     *
     * `sortBy` itu stabil: baris yang `titik_ke`-nya KEMBAR mempertahankan
     * urutan datangnya — yaitu urutan yang tidak dijanjikan siapa pun. Alat yang
     * satu titiknya punya beberapa baris (Chlorine Free/Total, Spectrophotometer
     * tiga blok) justru yang paling kena.
     *
     * Temuan review 3 Sep 2026, dan benar: perbaikan pertama cuma memasang sumbu
     * kedua di `standarDigunakan()`, sementara `hasil()` — tabel hasil kalibrasi
     * itu sendiri — dan pemilihan kode metode masih `sortBy('titik_ke')` saja.
     * Ketiganya sekarang lewat satu pintu supaya tidak ada lagi yang tertinggal.
     *
     * `titik_ke` di depan itu pilihan pemilik lab: tabelnya mengikuti urutan
     * titik ukur, sama seperti lembar kerjanya.
     *
     * @return Collection<int, UncertaintyCalculation>
     */
    /**
     * IK yang mewakili satu nama alat — dari kandidat yang disodorkan.
     *
     * ## Kenapa menerima koleksi, bukan menembak database sendiri
     *
     * Supaya urutannya bisa DIADU. Bug yang ditutup di sini cuma muncul kalau
     * kandidatnya datang dalam urutan tertentu, dan urutan itu tidak bisa
     * dipaksa lewat query — persis alasan yang sama dengan
     * `SnapshotSertifikatTahanUrutanTest`, yang versi pertamanya menyusun ulang
     * baris di database dan LOLOS tanpa menguji apa pun.
     *
     * ## Bug-nya
     *
     * Pemanggilnya memakai `->get()` TANPA `ORDER BY`. Dua `sortByDesc`
     * berantai itu stabil, jadi kandidat yang panjang namanya sama DAN
     * revisinya sama mempertahankan urutan datangnya — yaitu urutan yang tidak
     * dijanjikan siapa pun. Di MySQL itu bergantung pilihan indeks, dan itu
     * bisa berubah sesudah `UPDATE`.
     *
     * Yang tercetak dari sini masuk sertifikat terakreditasi sebagai kode IK.
     * Dua sertifikat untuk alat yang sama bisa menyebut IK berbeda tanpa satu
     * pun error, dan tanpa ada yang berubah di data.
     *
     * ## Urutan yang berlaku sekarang
     *
     * `sortBy` Laravel stabil, jadi yang dipanggil TERAKHIR jadi kunci utama:
     *
     *   1. panjang nama, terpanjang menang — supaya "Thermometer Glass" tidak
     *      kalah oleh jenis lain yang kebetulan jadi bagian namanya;
     *   2. revisi, tertinggi menang;
     *   3. **`id` terkecil** — pemecah seri terakhir, dan yang ditambahkan di
     *      sini.
     *
     * Nomor tiga itu sengaja dipilih karena STABIL, bukan karena benar. Kalau
     * dua IK aktif benar-benar seri di panjang nama dan revisi, mana yang
     * seharusnya menang itu keputusan pemilik lab dan **belum diputuskan** —
     * yang pasti salah cuma membiarkannya ditentukan urutan baris database.
     *
     * ## Kenapa `public`
     *
     * Diuji langsung. Fungsinya murni — masuk koleksi, keluar satu baris — dan
     * yang dipertaruhkan justru urutannya, yang cuma bisa diadu kalau
     * kandidatnya bisa disodorkan teracak dari test.
     *
     * @param  Collection<int, CalibrationMethod>  $kandidat
     */
    /**
     * Apakah kolom ketiga titik ini memang tidak punya nilai.
     *
     * Dibaca dari jejak audit, bukan dari kolom tersimpannya: yang tersimpan
     * penampung nol karena kolomnya `NOT NULL`, sementara jejaknya menyimpan
     * `null` yang sesungguhnya.
     *
     * `false` buat empat puluh satu alat lain — jejaknya tidak punya kunci ini
     * sama sekali, jadi perilaku lamanya nol tersentuh.
     */
    private static function koreksiKosong(mixed $titik): bool
    {
        $jejak = $titik->type_b_components ?? null;

        if (! is_array($jejak)) {
            return false;
        }

        $rantai = $jejak['gaya_rantai'] ?? null;

        return is_array($rantai)
            && array_key_exists('CF_faktor_kn_per_divisi', $rantai)
            && $rantai['CF_faktor_kn_per_divisi'] === null;
    }

    /**
     * Angka sebaran satu titik (%), dari jejak auditnya.
     *
     * UTM & Load Cell menyimpannya sebagai `AB_rrpe_persen`, Proving Ring
     * sebagai `L_rsd_persen`. Dua nama karena dua besaran yang beda: RRPE
     * dibagi beban nominal, RSD dibagi rata-rata pembacaan. Yang dicari di
     * sini yang mana pun yang ada.
     *
     * `null` kalau tidak ada — dan itu keadaan normal buat empat puluh alat
     * lain, yang memang tidak mencetak kolom ini.
     */
    private static function sebaranTitik(mixed $titik): ?float
    {
        $jejak = $titik->type_b_components ?? null;

        if (! is_array($jejak)) {
            return null;
        }

        $rantai = $jejak['gaya_rantai'] ?? null;

        if (! is_array($rantai)) {
            return null;
        }

        foreach (['AB_rrpe_persen', 'L_rsd_persen'] as $kunci) {
            if (isset($rantai[$kunci]) && is_numeric($rantai[$kunci])) {
                return (float) $rantai[$kunci];
            }
        }

        return null;
    }

    public static function metodeTerpilih(Collection $kandidat, string $namaAlat): ?CalibrationMethod
    {
        return $kandidat
            ->filter(fn (CalibrationMethod $m): bool => filled($m->nama)
                && str_contains($namaAlat, mb_strtolower(trim($m->nama))))
            ->sortBy(fn (CalibrationMethod $m): int => (int) $m->getKey())
            ->sortByDesc(fn (CalibrationMethod $m): int => (int) $m->revisi)
            ->sortByDesc(fn (CalibrationMethod $m): int => mb_strlen((string) $m->nama))
            ->first();
    }

    private static function titikUrut(CalibrationSession $sesi): Collection
    {
        return $sesi->uncertaintyCalculations->sortBy([['titik_ke', 'asc'], ['id', 'asc']]);
    }

    private function standarDigunakan(CalibrationSession $sesi, ?CalibrationProfile $profil = null): array
    {
        /** @var Collection<int, Standard> $standar */
        $standar = self::titikUrut($sesi)
            ->pluck('standard')
            ->filter()
            ->when($sesi->standard, fn (Collection $c) => $c->push($sesi->standard))
            // Thermohygro SENGAJA nggak masuk tabel ini.
            //
            // Dia alat pemantau kondisi ruangan, bukan acuan yang nilainya
            // dipakai ngoreksi pembacaan — dan kontribusinya udah kelaporan di
            // tempatnya sendiri: kolom "Env. Condition" di header. Waktu ikut
            // ditulis di sini, barisnya keluar setengah jadi (`TH-2` · `—` ·
            // `TH-2`) karena master thermohygro nggak nyimpen merk/model/serial
            // kayak standar acuan, dan baris kosong di tabel ketertelusuran itu
            // lebih buruk daripada nggak ada baris.
            //
            // Kalau nanti thermohygro-nya mau tampil (lembar manual lab nulisnya
            // sebagai "Termometer & Sensor Std." dengan merk, serial, dan dua
            // nomor ketertelusuran), yang dibutuhin itu data masternya dulu —
            // bukan baris ini dibalikin.
            // Kolom "Usage Check" di lembar kerja: standar yang dicentang
            // teknisi tapi nggak nempel ke titik hitung mana pun (mis. RTD
            // Sensor buat baca suhu larutan) tetap harus tercatat — itu bagian
            // dari ketertelusuran, bukan pelengkap.
            // `sortBy('id')` alasannya sama dengan sortBy di atas: ekor daftar
            // ini pun ikut tercetak, jadi urutannya nggak boleh diserahkan ke
            // urutan baris yang kebetulan dikembalikan database.
            ->concat(
                $sesi->standarDicek
                    ->filter(fn (Standard $s): bool => (bool) $s->pivot->dipakai)
                    ->sortBy('id')
            )
            // Standar yang SELALU dipakai rumus profilnya walau tidak dicentang
            // — termometer standar Labu Ukur & Pipet Volume (`SERTIFIKAT!B30`
            // workbook Rev.7). Kosong untuk alat lain, jadi sertifikat mereka
            // tidak berubah. Lihat `CalibrationProfile::standarTambahanSesi()`.
            ->concat($this->standarTambahanDicetak($sesi))
            ->unique('id')
            ->values();

        return $standar
            ->map(fn (Standard $s): array => [
                'name' => $s->nama,
                'merk_type' => trim(($s->merk ?? '').'/'.($s->model ?? ''), '/ ') ?: null,
                'serial_number' => $s->serial_number,
                'traceable_to' => $s->tertelusur_ke,
                // Kolom tambahan master (`Nominal Mass`, K-50-15). Kosong untuk
                // alat lain — barisnya tetap empat kunci.
                ...$this->isiTambahanStandar($sesi, $profil, $s),
            ])
            ->all();
    }

    /** @return array<string, string|null> */
    private function isiTambahanStandar(CalibrationSession $sesi, ?CalibrationProfile $profil, Standard $standar): array
    {
        $tambahan = $profil?->isiTambahanStandarDigunakan($sesi, $standar) ?? [];
        $bentrok = array_intersect(array_keys($tambahan), TataLetakSertifikat::KUNCI_STANDAR);

        if ($bentrok !== []) {
            throw new RuntimeException('isiTambahanStandarDigunakan() tidak boleh menimpa kolom baku Standard Used: '
                .implode(', ', $bentrok).'.');
        }

        return array_map(static fn ($v): ?string => $v === null ? null : (string) $v, $tambahan);
    }

    /**
     * Field kepala tambahan sesi ini (`CalibrationProfile::isiTambahanKepala`).
     * Kuncinya tidak boleh menimpa enam belas field baku — yang baku punya
     * jalurnya sendiri (teknisi menang atas master alat, lihat [header]).
     *
     * @return array<string, string|null>
     */
    private function kepalaTambahan(?CalibrationProfile $profil, CalibrationSession $sesi): array
    {
        $tambahan = $profil?->isiTambahanKepala($sesi) ?? [];
        $bentrok = array_intersect(array_keys($tambahan), TataLetakSertifikat::KUNCI_KEPALA);

        if ($bentrok !== []) {
            throw new RuntimeException('isiTambahanKepala() tidak boleh menimpa field kepala baku: '.implode(', ', $bentrok).'.');
        }

        return array_map(static fn ($v): ?string => $v === null ? null : (string) $v, $tambahan);
    }

    private function satuanCetak(CalibrationSession $sesi, ?CalibrationProfile $profil): ?string
    {
        $alat = $sesi->equipment;
        $titik = self::titikUrut($sesi)->first();
        $dariProfil = $titik === null ? null : $profil?->satuanTitikSesi($sesi, (float) $titik->titik_ukur, (int) $titik->titik_ke, $alat);

        return $dariProfil ?? (filled($alat?->satuan) ? $alat->satuan : $sesi->rawMeasurements->first()?->satuan);
    }

    /** @return list<Standard> */
    private function standarTambahanDicetak(CalibrationSession $sesi): array
    {
        if ($sesi->equipment === null) {
            return [];
        }

        $tambahan = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment)->standarTambahanSesi($sesi);

        return array_values(array_map(
            static fn (array $t): Standard => $t['standar'],
            array_filter($tambahan, static fn (array $t): bool => $t['dicetak']),
        ));
    }

    /**
     * Footer: tanggal terbit, penandatangan, jabatan, kode dokumen.
     *
     * Urutan sumber nama penandatangan:
     *
     *  1. `penandatangan_user_id` di sesi — dipilih PER SERTIFIKAT di layar
     *     pengajuan/pengesahan (gerbang pengesahan 26 Sep; permintaan "bisa Pak
     *     Rohman atau Alex tergantung siapa yang mengesahkan").
     *  2. Pengaturan organisasi — di lab, yang tanda tangan sertifikat itu
     *     Technical Manager, belum tentu admin yang kebetulan mencet approve.
     *  3. Pengesah (`disahkan_oleh`), lalu reviewer — cadangan lama, dipertahankan
     *     supaya sertifikat lab yang belum mengisi pengaturan tidak berubah isi.
     *
     * Null di nomor 1 berarti perilaku persis seperti sebelum gerbang ada.
     *
     * @param  array<string, mixed>  $pengaturan
     * @return array<string, string|null>
     */
    private function footer(CalibrationSession $sesi, Certificate $sertifikat, array $pengaturan): array
    {
        $dipilih = $sesi->penandatangan;

        return [
            'issuance_date' => $sertifikat->diterbitkan_pada?->toDateString(),
            'penandatangan' => $dipilih?->name
                ?? $pengaturan[Organization::KEY_PENANDATANGAN_NAMA]
                ?? $sesi->pengesah?->name
                ?? $sesi->reviewer?->name,
            'jabatan' => ($dipilih !== null ? $dipilih->department : null)
                ?? $pengaturan['penandatangan_jabatan']
                ?? $sesi->reviewer?->department
                ?? 'Technical Manager',
            'kode_dokumen' => $pengaturan['kode_dokumen_form'] ?? self::KODE_DOKUMEN_DEFAULT,
        ];
    }

    /**
     * Kalau onsite, tempat yang DITULIS TEKNISI; selain itu nama ruangannya.
     *
     * `Insitu (PT. Niaga Contoh)` — persis yang tercetak di sertifikat master. Nama
     * tempatnya nggak diturunkan dari pelanggan pemilik alat: satu kunjungan
     * bisa dikerjakan di pabrik lain milik grup yang sama, dan yang sah di
     * dokumen adalah tempat alatnya beneran diukur. Kalau teknisi nggak nulis
     * apa-apa, jatuh ke alamat pelanggan seperti sebelumnya.
     *
     * `lokasi` DIPERIKSA DULUAN, ruangan belakangan — dan urutan ini yang
     * bikin benar, bukan sekadar rapi. Dropdown "Ruangan" di HP selalu tampil
     * dan nggak pernah direset waktu teknisi milih Insitu, jadi sesi kunjungan
     * lumrah nyimpen `room_id` sisa pilihan sebelumnya. Waktu ruangan dicek
     * duluan, sisa itu menang: sertifikat kunjungan ke pabrik pelanggan terbit
     * bertuliskan `Lab. Uji A` — dokumen resmi yang nyatain alatnya diukur di
     * lab kami, padahal alatnya nggak pernah masuk lab. Nggak ada error, nggak
     * ada yang aneh di layar, dan yang megang salinannya cuma bisa curiga
     * kalau dia inget alat itu emang nggak pernah dikirim.
     *
     * Jadi ruangan cuma dipakai kalau sesinya BUKAN onsite. Kalau dropdown-nya
     * nanti beneran direset di HP, baris ini tetap nggak boleh dibalik — dia
     * yang jaga sertifikat dari data sesi lama yang terlanjur bawa `room_id`.
     */
    private function lokasiKalibrasi(CalibrationSession $sesi): ?string
    {
        if ($sesi->lokasi === 'onsite') {
            if (filled($sesi->lokasi_nama)) {
                return 'Insitu ('.trim((string) $sesi->lokasi_nama).')';
            }

            return $sesi->equipment?->customer?->alamat
                ? 'Onsite — '.$sesi->equipment->customer->alamat
                : 'Onsite';
        }

        if ($sesi->room) {
            return $sesi->room->nama;
        }

        return 'Laboratorium';
    }

    /**
     * Nomor IK. Prioritasnya baris `calibration_methods` yang dipilih admin;
     * kalau belum dipilih, jatuh ke nomor IK yang nempel di kemampuan kalibrasi
     * (CMC) yang kepake waktu hitung — itu sumber yang sama, cuma nggak eksplisit.
     */
    private function metodeKalibrasi(CalibrationSession $sesi): ?string
    {
        if ($sesi->calibrationMethod) {
            return $sesi->calibrationMethod->kodeLengkap();
        }

        // Profil alat menang atas cadangan pencocokan nama di bawah.
        //
        // Cadangan itu mencocokkan NAMA ALAT ke kolom "Jenis Pengukuran", dan
        // meleset begitu pelanggan menamai alatnya di luar kosakata master —
        // timbangan bernama "Moisture Analyzer" tidak memuat kata "Timbangan",
        // jadi kolom `Calibration Method` sertifikatnya terbit berisi rujukan
        // pustaka alih-alih nomor IK lab. Lihat `CalibrationProfile::kodeMetode`.
        //
        // Dua puluh profil lain balik `null` di sini dan jatuh ke jalur lama
        // tanpa berubah sama sekali.
        $alatIni = $sesi->equipment;

        $dariProfil = $alatIni
            ? app(CalibrationProfileRegistry::class)->untukAlat($alatIni)?->kodeMetode()
            : null;

        if ($dariProfil !== null) {
            return $dariProfil;
        }

        // Admin nggak selalu milih metode sebelum approve. Kalau nggak dipilih,
        // yang dipakai IK TERBARU buat jenis pengukurannya — itu persis tabel
        // "Jenis Pengukuran → Metode Kalibrasi (Latest IK)" di lembar master,
        // dan `MetodeKalibrasiSeeder` nyimpen jenis pengukurannya di kolom
        // `nama` (mis. "pH Meter").
        //
        // Dicocokkan lewat NAMA ALAT, bukan id: master metode ngelistnya per
        // jenis pengukuran, dan nama alat di sesi ini yang mewakilinya.
        $namaAlat = mb_strtolower(trim((string) $sesi->equipment?->nama_alat));

        if ($namaAlat !== '') {
            // Dicocokkan lewat "nama alat MENGANDUNG jenis pengukuran", bukan
            // sama persis: master ngelist jenisnya ("pH Meter") sementara alat
            // di lapangan namanya lebih panjang ("pH Meter Bench"). Cocok
            // persis bikin cadangan ini nggak pernah kena.
            //
            // Yang dipilih kecocokan TERPANJANG, supaya "Thermometer Glass"
            // nggak kalah sama jenis lain yang kebetulan jadi bagian namanya.
            $metode = self::metodeTerpilih(
                CalibrationMethod::query()
                    ->where('organization_id', $sesi->organization_id)
                    ->where('aktif', true)
                    ->get(),
                $namaAlat,
            );

            if ($metode !== null) {
                return $metode->kodeLengkap();
            }
        }

        // Terakhir: kode yang kesimpen di baris perhitungan. Ini nggak bawa
        // revisi, jadi sengaja jadi cadangan paling akhir — sertifikat yang
        // nyebut IK tanpa revisi nggak bisa dicocokin ke dokumen mutu mana.
        return self::titikUrut($sesi)
            ->first(fn ($titik): bool => filled($titik->metode))?->metode;
    }

    /**
     * Jumlah desimal tabel CALIBRATION REPORT.
     *
     * Bawaannya diturunin dari resolusi alat: alat yang bacanya sampai 0,001
     * dicetak 3 desimal, yang 0,01 dicetak 2. Nulis lebih banyak daripada yang
     * bisa dibaca alatnya itu ngaku-ngaku presisi yang nggak ada.
     *
     * Pengaturan organisasi bisa nimpa, karena resolusi di master alat nggak
     * selalu ngikut spek fisiknya — dan kalau kekecilan, yang paling kena itu
     * U95%: 0,023 kecetak jadi `0,02` dan kehilangan angka penting, padahal
     * ketidakpastian lazimnya dilaporin 2 angka penting.
     *
     * Ikut dibekukan ke snapshot (bukan dibaca ulang waktu render) supaya
     * sertifikat yang udah terbit nggak berubah bentuk gara-gara pengaturan
     * diubah sesudahnya.
     *
     * Timpaan profil alat menang di atas dua-duanya — aturan yang sama persis
     * udah dipakai `hasil()` buat desimal per baris. Sempat cuma dipasang di
     * sana: baris Refractometer kebekuin 5, angka level-sertifikat ini tetap 4.
     * Nggak keliatan di PDF (blade-nya baca per baris duluan), tapi itu yang
     * dipakai sebagai cadangan sama layar mobile & `CertificateResource`, jadi
     * satu sertifikat bisa punya dua jawaban buat pertanyaan yang sama.
     */
    private function desimal(?Equipment $alat, ?Organization $organisasi): int
    {
        $resolusi = $alat?->resolusi !== null ? (float) $alat->resolusi : null;

        $profil = $alat !== null
            ? app(CalibrationProfileRegistry::class)->untukAlat($alat)
            : null;

        // Aturannya sengaja NGGAK diulang di sini — satu-satunya tempat dia
        // diputusin itu `Organization::desimalSertifikat()`, biar angka yang
        // dibekukan ke sertifikat sama persis dengan yang dikirim ke mobile.
        return $profil?->desimalSertifikat()
            ?? ($organisasi
                ? $organisasi->desimalSertifikat($resolusi)
                : Angka::desimalDariResolusi($resolusi));
    }

    /** `0–14 pH / 0,01 pH` — rentang alat / resolusinya. */
    private function kapasitasGraduasi(CalibrationSession $sesi): ?string
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return null;
        }

        $satuan = $alat->satuan ? ' '.$alat->satuan : '';
        $bagian = [];

        if ($alat->range_min !== null || $alat->range_max !== null) {
            // Rentang ukur ditulis TANPA pemisah ribuan: sertifikat asli nyetak
            // `0-1000 NTU`, bukan `0-1.000 NTU`. Di angka rentang alat, titik
            // ribuan malah gampang kebaca sebagai koma desimal oleh pembaca
            // asing — dan ini dokumen dwibahasa.
            $angkaRentang = static fn (?float $n): string => $n === null
                ? '—'
                : rtrim(rtrim(number_format($n, 6, ',', ''), '0'), ',');

            $bagian[] = $angkaRentang((float) $alat->range_min)
                .'–'.$angkaRentang((float) $alat->range_max).$satuan;
        }

        // Alat ber-resolusi BERTINGKAT ditulis semua resolusinya, bukan cuma
        // satu. Sertifikat asli PT Sidik buat HACH 2100Q (0189-CAL-624) nulis:
        //
        //   Capacity/Graduation : 0–1000 NTU / 0,01 NTU
        //                                      0,1 NTU
        //                                      1 NTU
        //
        // Waktu cuma `$alat->resolusi` yang dipakai, dua baris terakhir ilang
        // dan dokumennya jadi ngaku alatnya ber-resolusi 0,01 di SELURUH
        // rentang — padahal di atas 100 NTU dia cuma bisa bilangan bulat.
        // Dipisah koma (bukan baris baru) karena field ini satu baris teks di
        // snapshot; yang penting ketiganya kebawa, bukan tata letaknya.
        $rentang = $alat->resolusi_rentang;
        if (is_array($rentang) && $rentang !== []) {
            $resolusi = [];
            foreach ($rentang as $band) {
                if (is_array($band) && isset($band['resolusi'])) {
                    // Satuannya diambil PER BAND, bukan sekali dari alatnya.
                    //
                    // Spectrophotometer ngukur dua besaran dengan satu alat: 19
                    // band panjang gelombang (`nm`, resolusi 0,01) dan 5 band
                    // transmitansi (`%T`, resolusi 0,001). `equipments.satuan`
                    // cuma muat satu, dan yang kesimpen di situ `nm`.
                    //
                    // Waktu satuan alat dipakai buat semua band, resolusi %T
                    // kecetak `0,001 nm` di sertifikat — angka yang benar
                    // dengan satuan yang salah, dan itu bentuk kekeliruan yang
                    // paling susah ketahuan: nggak ada yang error, dokumennya
                    // kelihatan rapi, dan yang baca cuma bisa curiga kalau dia
                    // hafal alatnya nggak punya resolusi 0,001 nm.
                    //
                    // Ketemu 21 Agt 2026 waktu CAL/2026/08/0052 diadu ke master
                    // Excel-nya baris per baris — seluruh 23 baris pengukuran &
                    // ketiga U95 cocok persis, yang meleset cuma baris ini.
                    $satuanBand = isset($band['satuan']) && is_string($band['satuan'])
                        && trim($band['satuan']) !== ''
                            ? ' '.trim($band['satuan'])
                            : $satuan;

                    $resolusi[] = Angka::idRingkas((float) $band['resolusi'], 6).$satuanBand;
                }
            }
            if ($resolusi !== []) {
                // Dipisah BARIS BARU, bukan koma — sertifikat asli PT Sidik
                // (0189-CAL-624) nyetak resolusinya bertumpuk:
                //
                //   Capacity/Graduation : 0-1000 NTU / 0,01 NTU
                //                                      0,1 NTU
                //                                      1 NTU
                //
                // Dijejer koma, barisnya kepanjangan lalu membungkus asal di
                // tengah angka. Yang ngerender wajib ngehormatin `\n`
                // (`white-space: pre-line` di blade PDF & web).
                //
                // `array_unique` jaga-jaga kalau dua rentang resolusinya sama —
                // nulis "1 NTU / 1 NTU" cuma bikin dokumen kelihatan salah.
                $bagian[] = implode("\n", array_unique($resolusi));

                return implode(' / ', $bagian);
            }
        }

        if ($alat->resolusi !== null) {
            $bagian[] = Angka::idRingkas((float) $alat->resolusi, 6).$satuan;
        }

        return $bagian === [] ? null : implode(' / ', $bagian);
    }

    /**
     * `T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,7%`.
     *
     * Bagian `±`-nya cuma ditulis kalau admin ngisi ketidakpastiannya. Nulis
     * `± 0` waktu angkanya nggak diketahui itu klaim yang nggak benar.
     */
    private function kondisiLingkungan(CalibrationSession $sesi, bool $bertumpuk = false): ?string
    {
        $bagian = [];

        // Desimalnya DISALIN dari sertifikat master, bukan diturunkan:
        //
        //   Turbidimeter 0189-CAL-624  T 23,07 °C ± 1,7 °C · %RH 52 % ± 4,8 %
        //   Chlorine     2406.32.C.NK  T 23,21 °C ± 1,8 °C · %RH 53 % ± 4,9 %
        //
        // Jadi: NILAI suhu 2 desimal, NILAI kelembaban bulat, dan ketidakpastian
        // dua-duanya 1 desimal. Kombinasi itu nggak simetris dan nggak bisa
        // dinalar — makanya sempat salah berkali-kali dari dua arah sekaligus.
        //
        // ## Riwayatnya, biar nggak diputer lagi
        //
        // Baris ini udah tiga kali digeser, dan tiap kali alasannya masuk akal:
        //
        //  - suhu diturunkan ke 1 desimal ("teknisi cuma baca 0,1°C, desimal
        //    ketiga itu hasil hitungan koreksi thermohygro") — masuk akal, dan
        //    salah: master nulis `23,07`.
        //  - kelembaban dinaikin ke 2 desimal ("nilainya emang berdesimal,
        //    `53` di Chlorine itu kebetulan bulat") — masuk akal, dan salah:
        //    Turbidimeter yang nilainya 51,83 pun kecetak `52`.
        //
        // Dua-duanya diputus dari nalar metrologi, bukan dari kertasnya, dan
        // dua-duanya kebalik dari yang lab tulis. Kalau baris ini mau digeser
        // lagi, geser SETELAH ngadu ke halaman masternya — bukan sebelum.
        $profil = $sesi->equipment !== null
            ? app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment)
            : null;

        // `null` = format `General` di Excel: tulis apa adanya, nol di belakang
        // dibuang. Dibatesin 2 desimal dulu supaya derau float
        // (`23.069999999999997`) nggak ikut kecetak — Excel juga mangkas di
        // situ waktu nampilin `General`.
        $suhuDesimal = $profil?->desimalSuhuEnvSesi($sesi);
        $rhDesimal = $profil?->desimalKelembabanEnvSesi($sesi);

        $tulis = static fn (float $nilai, ?int $desimal): string => $desimal === null
            ? Angka::nilaiStandar($nilai, 2)
            : Angka::id($nilai, $desimal);

        // Bunyi tiap bagian. Bertumpuk (`env_bertumpuk`, K-50-15) = bunyi sel
        // master: label, angka, satuan dipisah spasi (`165!N12:T13`
        // `T | 20.2 | ⁰C | ± | 1.7 | ⁰C`, `143!O13` `Tekanan Udara`). Sebaris =
        // bunyi lama, dipertahankan untuk profil yang menimpanya `false`.
        [$labelT, $satT, $labelRh, $satRh, $labelP] = $bertumpuk
            ? ['T ', ' °C', '%RH ', ' %', 'Tekanan Udara ']
            : ['T: ', '°C', '%RH: ', '%', 'P: '];

        if ($sesi->suhu_ruang !== null) {
            $teks = $labelT.$tulis((float) $sesi->suhu_ruang, $suhuDesimal).$satT;

            if ($sesi->suhu_ketidakpastian !== null) {
                $teks .= ' ± '.Angka::id($sesi->suhu_ketidakpastian, 1).$satT;
            }

            $bagian[] = $teks;
        }

        if ($sesi->kelembaban !== null) {
            // Nilainya BULAT, ketidakpastiannya 1 desimal — lihat catatan di
            // blok suhu di atas. Master Turbidimeter nyimpen 51,83 dan nyetak
            // `52`; Chlorine nyimpen 53,0 dan nyetak `53`. Nilai penuhnya tetap
            // utuh di DB dan tetap dipakai ngitung; yang dipangkas cuma yang
            // kecetak.
            $teks = $labelRh.$tulis((float) $sesi->kelembaban, $rhDesimal).$satRh;

            if ($sesi->kelembaban_ketidakpastian !== null) {
                $teks .= ' ± '.Angka::id($sesi->kelembaban_ketidakpastian, 1).$satRh;
            }

            $bagian[] = $teks;
        }

        // Tekanan udara — cuma alat yang profilnya memintanya (Labu Ukur &
        // Pipet Volume, `SERTIFIKAT!T13:Y13` workbook Rev.7); `null` untuk alat
        // lain, jadi baris mereka tidak berubah. Nilainya bulat TANPA pemisah
        // ribuan (`1001`, format sel `0`), ketidakpastiannya satu desimal
        // (`0.0`) — sama dengan suhu & kelembaban di atas.
        $tekanan = $profil?->tekananEnvSertifikat($sesi);

        if ($tekanan !== null) {
            $teks = $labelP.Angka::hasil($tekanan, 0).' hPa';

            if ($sesi->tekanan_ketidakpastian !== null) {
                $teks .= ' ± '.Angka::id($sesi->tekanan_ketidakpastian, 1).' hPa';
            }

            $bagian[] = $teks;
        }

        // Bertumpuk (`env_bertumpuk`, K-50-15): master menulis T, %RH, dan
        // Tekanan Udara di baris sendiri-sendiri (`SERTIFIKAT!N12:T14`). Yang
        // merender wajib menghormati baris baru (`white-space: pre-line`).
        return $bagian === [] ? null : implode($bertumpuk ? "\n" : ' — ', $bagian);
    }
}
