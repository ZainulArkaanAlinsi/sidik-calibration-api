<?php

namespace App\Jobs;

use App\Events\PerubahanDataOrganisasi;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SertifikatGagal;
use App\Notifications\SertifikatTerbit;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Services\FolderOrganizer;
use App\Services\PenerimaNotifikasi;
use App\Services\SertifikatSatuHalaman;
use App\Services\SinkronJadwalAlat;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Terbitin sertifikat dari sesi kalibrasi yang udah disetujui.
 *
 * Jalan di QUEUE (async) — pembuatan PDF bisa lama, jadi jangan nahan request
 * approve. Sesuai kontrak: sehabis approve, `certificate_id` boleh masih null
 * sesaat sampai job ini kelar.
 *
 * FAIL tetap diterbitin — hasil "tidak laik pakai" itu sertifikat yang sah.
 *
 * Urutannya: hitung ulang & periksa (spesifikasi poin 11) → bekukan snapshot
 * (poin 9) → cetak PDF → taruh di Folder Manager (poin 3) → kabarin yang
 * bersangkutan (poin 6). Snapshot dibekukan DULUAN, sebelum PDF, biar kalau
 * cetaknya gagal isinya tetap kesimpen & tinggal di-retry.
 */
class GenerateCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Render PDF di worker Render yang CPU-nya kecil bisa melampaui batas
     * Laravel bawaan (60 detik). `DB_QUEUE_RETRY_AFTER` harus lebih besar dari
     * nilai ini; keduanya dipatok di `render.yaml` dan `entrypoint.sh`.
     */
    public int $timeout = 600;

    /**
     * Kegagalan sementara boleh dicoba ulang, tetapi sesudah seluruh percobaan
     * habis `failed()` harus mengembalikan tombol retry ke admin.
     */
    public int $tries = 3;

    /**
     * @param  string|null  $berlakuSampai  Masa berlaku pilihan admin (`Y-m-d`).
     *                                      Null → pakai default masa berlaku organisasi.
     * @param  string|null  $nomorSertifikat  No. Sertifikat yang diketik Master Data/super
     *                                        admin saat Setujui (K-50-04). Null hanya untuk
     *                                        data lama & seeder: penomoran otomatis.
     * @param  int|null  $revisiDariId  Sertifikat `ditarik` yang digantikan (K-50-03):
     *                                  terbit sebagai `<nomor>-R<n>` dengan `revision_of`.
     */
    public function __construct(
        public int $calibrationSessionId,
        public ?int $issuedBy = null,
        public ?string $berlakuSampai = null,
        public ?string $nomorSertifikat = null,
        public ?int $revisiDariId = null,
    ) {}

    public function handle(): void
    {
        // Diambil dari container di dalam sini, bukan lewat parameter handle().
        // Job ini juga dipanggil langsung (`(new GenerateCertificate($id))->handle()`)
        // dari test & aksi panel admin — kalau dependensinya jadi parameter,
        // semua pemanggilan langsung itu ikut pecah.
        $snapshotBuilder = app(CertificateSnapshotBuilder::class);
        $validator = app(CalibrationValidator::class);
        $tampilan = app(DataTampilanSertifikat::class);
        $satuHalaman = app(SertifikatSatuHalaman::class);
        $folder = app(FolderOrganizer::class);
        $sinkronJadwal = app(SinkronJadwalAlat::class);

        $sesi = CalibrationSession::with([
            'equipment.customer', 'organization', 'teknisi', 'reviewer', 'standard', 'thermohygro',
            'standarDicek', 'calibrationMethod', 'room', 'rawMeasurements', 'uncertaintyCalculations.standard',
        ])->find($this->calibrationSessionId);

        if (! $sesi || $sesi->status !== CalibrationSession::STATUS_DISETUJUI) {
            return;
        }

        // Penjagaan cepat di luar transaksi — nahan retry job yang jelas-jelas
        // sudah selesai tanpa membuka transaksi sama sekali. BUKAN penjagaan
        // yang sebenarnya: lihat lock di dalam transaksi di bawah.
        if ($this->sudahTerbit($sesi)) {
            return;
        }

        $dasar = $this->revisiDariId === null ? null : Certificate::query()->find($this->revisiDariId);

        $sertifikat = DB::transaction(function () use ($sesi, $dasar): ?Certificate {
            // Penjagaan yang sebenarnya, dan sebabnya perlu dua.
            //
            // Penjagaan di atas cuma menahan sertifikat yang SUDAH `terbit` —
            // status yang baru tercapai sesudah transaksi ini selesai DAN
            // PDF-nya berhasil ditulis. Selama jendela itu, permintaan kedua
            // melihat "belum terbit" dan masuk juga.
            //
            // Yang menyerialkan dua permintaan itu lock di baris SESI-nya:
            // yang kedua menunggu di sini sampai yang pertama commit, lalu
            // menemukan sertifikatnya sudah ada dan berhenti.
            //
            // Kenapa lock di baris sesi, bukan unique index di
            // `certificates.calibration_session_id`: kolom `revision_of` di
            // tabel itu menyiratkan satu sesi suatu hari punya lebih dari satu
            // baris sertifikat (yang asli + revisinya). Unique index bakal
            // menutup jalan itu untuk menyelesaikan balapan yang bisa
            // diselesaikan lock — menukar satu masalah dengan batasan
            // arsitektur.
            CalibrationSession::whereKey($sesi->id)->lockForUpdate()->first();

            // Syaratnya `STATUS_TERBIT`, BUKAN "ada barisnya".
            //
            // Sempat saya tulis `certificate()->exists()` waktu menambal
            // balapan ini, dan itu kebablasan: baris sertifikat yang `gagal`
            // juga "ada", jadi tombol "Terbitkan ulang" milik admin —
            // satu-satunya jalan keluar dari sertifikat yang gagal dirender —
            // ikut mati. Ketahuan `CertificateApiTest::test_admin_retry_
            // sertifikat_gagal_nerbitin_ulang_langsung`, bukan oleh saya.
            //
            // Yang menutup balapannya lock di atas, bukan syarat ini. Dua
            // permintaan berbarengan diserialkan di sana; yang kedua lalu
            // sampai ke `updateOrCreate` di bawah, menemukan barisnya sudah
            // ada, dan MENGUBAHnya — bukan menyisipkan baris kedua. Itu yang
            // dulu tidak terjadi: tanpa lock, dua transaksi sama-sama tidak
            // melihat baris apa pun di snapshot masing-masing dan sama-sama
            // menyisipkan.
            if ($this->sudahTerbit($sesi)) {
                return null;
            }

            // Identitasnya DICETAK SEKALI per baris sertifikat, bukan tiap
            // percobaan.
            //
            // Dua hal yang diperbaiki sekaligus, dan yang kedua yang berbahaya:
            //
            // 1. Retry sertifikat yang gagal dulu menghabiskan satu nomor dari
            //    urutan lab tiap kali dicoba — untuk dokumen yang tidak pernah
            //    terbit sama sekali.
            //
            // 2. Dua `handle()` yang jalan bersamaan (dua klik "Terbitkan
            //    ulang", atau dua worker antrean yang mengambil job yang sama)
            //    dulu MENCETAK NOMOR SENDIRI-SENDIRI. Lock di atas cuma
            //    menyerialkan penulisan barisnya: yang kedua menimpa nomor &
            //    token yang pertama, lalu keduanya merender PDF DI LUAR
            //    transaksi ini memakai nilai yang dipegang masing-masing. Baris
            //    yang tersisa bisa menyimpan nomor milik yang satu sambil
            //    `pdf_path`-nya menunjuk PDF berisi nomor yang lain — dan itu
            //    dokumen terakreditasi yang isinya tidak sama dengan catatannya.
            //
            // Dengan identitas yang dipakai ulang, dua pemanggil menghasilkan
            // PDF yang identik di jalur yang identik. Yang tersisa cuma
            // pekerjaan ganda, dan itu tidak merusak apa pun.
            $sudahAda = $dasar !== null
                ? Certificate::query()->where('calibration_session_id', $sesi->id)->where('revision_of', $dasar->id)->first()
                : $sesi->certificate()->first();

            $ke = $dasar === null ? 0 : ((int) $dasar->revisi_ke + 1);

            // Urutan sumber nomor: baris yang sudah ada (retry) → nomor revisi
            // `<dasar>-R<n>` (sertifikat ditarik, K-50-03) → nomor yang diketik
            // admin (K-50-04) → penomoran otomatis, HANYA data lama & seeder.
            $nomor = $sudahAda?->nomor
                ?: ($dasar !== null
                    ? Certificate::nomorDasar((string) $dasar->nomor).'-R'.$ke
                    : ($this->nomorSertifikat ?: $this->nomorBerikutnya($sesi->organization_id)));
            $token = $sudahAda?->qr_token ?: $this->tokenUnik();

            $penulis = $dasar !== null ? Certificate::query() : $sesi->certificate();

            return $penulis->updateOrCreate(
                $dasar !== null
                    ? ['calibration_session_id' => $sesi->id, 'revision_of' => $dasar->id]
                    : ['calibration_session_id' => $sesi->id],
                [
                    ...($dasar !== null ? [
                        'revisi_ke' => $ke,
                        'alasan_revisi' => 'Sertifikat '.$dasar->nomor.' ditarik: '.$dasar->alasan_ditarik,
                    ] : []),
                    'organization_id' => $sesi->organization_id,
                    'issued_by' => $this->issuedBy,
                    'nomor' => $nomor,
                    'qr_token' => $token,
                    // WAJIB dari `config('app.url')`, BUKAN `url()`.
                    //
                    // `url()` ngambil host dari request yang lagi jalan. Sertifikat
                    // diterbitkan lewat request admin dari HP/laptop di LAN, jadi
                    // yang kecetak ke QR itu alamat sementara mesin dev —
                    // ketemu `http://192.168.1.8:8000/verify/...` di sertifikat
                    // yang udah terbit. Begitu IP-nya pindah (sehari bisa 3x),
                    // QR-nya mati; dan dari luar kantor emang nggak pernah bisa
                    // dibuka sama sekali.
                    //
                    // Ini bukan cuma "nggak bisa di-scan sekarang": nomor QR
                    // nempel PERMANEN di dokumen terkendali yang udah dikirim ke
                    // pelanggan. Alamatnya harus satu, stabil, dan nggak ikut
                    // berubah-ubah — makanya diambil dari konfigurasi, bukan dari
                    // request yang kebetulan lagi jalan.
                    //
                    // Konsekuensinya: `APP_URL` di `.env` HARUS diisi alamat yang
                    // beneran kejangkau publik. Selama dia masih `localhost` atau
                    // IP LAN, QR-nya nggak akan pernah bisa dibuka pelanggan —
                    // itu batas infrastruktur, bukan batas kode.
                    'qr_payload' => rtrim((string) config('app.url'), '/')."/verify/{$token}",
                    'diterbitkan_pada' => now(),
                    'berlaku_sampai' => $this->berlakuSampai($sesi),
                    'status' => Certificate::STATUS_MENUNGGU_GENERATE,
                ],
            );
        });

        // `null` = permintaan lain menang balapannya dan sertifikatnya sudah
        // dibikin di dalam transaksi yang barusan kita tunggu. Berhenti di
        // sini — bukan error, dan bukan alasan buat menulis apa pun lagi.
        if ($sertifikat === null) {
            return;
        }

        try {
            // Hasil pemeriksaan disimpan APA ADANYA, termasuk kalau ada temuan.
            // Approve udah nahan yang fatal; yang nyampe sini paling banter
            // peringatan yang sengaja dilewatin admin — dan justru itu yang
            // paling penting ketinggalan jejaknya.
            $snapshot = $snapshotBuilder->bangun($sesi, $sertifikat);

            if ($dasar !== null) {
                // Sama dengan `RevisiSertifikat::snapshotRevisi()`: lembar terbit ulang
                // menyebut yang digantikannya. Angkanya dihitung ulang dari sesi yang
                // sudah dikoreksi (bukan disalin), karena inilah alasan penarikan.
                $snapshot['header']['catatan_revisi'] = "Revisi ke-{$sertifikat->revisi_ke}, menggantikan {$dasar->nomor}";
                $snapshot['meta']['revisi'] = [
                    'ke' => (int) $sertifikat->revisi_ke,
                    'menggantikan' => $dasar->nomor,
                    'kunci_berubah' => ['hasil_diterbitkan_ulang'],
                ];
            }

            $sertifikat->update([
                'validasi' => $validator->periksa($sesi),
                'snapshot' => $snapshot,
            ]);

            // Bahannya dirakit `DataTampilanSertifikat`, bukan di sini — halaman
            // hasil scan QR merender blade yang SAMA dengan bahan yang sama,
            // jadi lembar yang dipegang pelanggan nggak bisa beda dari lembar
            // yang muncul waktu QR-nya discan.
            // Dirender lewat SertifikatSatuHalaman, bukan `Pdf::loadView` langsung:
            // dia menghitung halaman hasil render dan memadatkan ulang kalau
            // meluap. Headernya mencetak `Page : 1 of 1` — angka itu ditulis mati
            // di snapshot, jadi lembar dua halaman bikin dokumen terkendali ini
            // menyatakan hal yang tidak benar tentang dirinya sendiri.
            $isiPdf = $satuHalaman->isi($sertifikat);

            $path = "certificates/{$sertifikat->qr_token}.pdf";

            // Nilai balik `put()` DIPERIKSA, jangan dihapus penjagaannya.
            //
            // Disk di project ini disetel `throw => false`, jadi tulis yang
            // gagal balik `false` tanpa suara — dan baris di bawah bakal
            // nyetempel sertifikatnya `terbit` sambil nyimpen `pdf_path` yang
            // nggak nunjuk ke berkas apa pun.
            //
            // Itu kegagalan paling mahal yang bisa terjadi di sini: dari sisi
            // lab semuanya kelihatan beres, dan baru ketahuan waktu pelanggan
            // ngeklik unduh. Dilempar ke `catch` di bawah biar dapat perlakuan
            // yang sama kayak kegagalan render — status `gagal`, admin
            // dikabarin, tombol retry muncul di mobile.
            if (Storage::disk('arsip')->put($path, $isiPdf) === false) {
                throw new RuntimeException("Gagal nulis PDF sertifikat ke {$path}.");
            }

            // Status `terbit` dan jadwal alatnya ditulis dalam SATU transaksi.
            //
            // `berlaku_sampai` yang dipilih admin waktu approve cuma mendarat di
            // baris sertifikat, sementara pengingat pagi membaca
            // `equipments.tanggal_jatuh_tempo` — kolom yang sebelum ini cuma
            // berubah lewat form manual & impor Excel. Dua angka yang seharusnya
            // sama, diisi dari dua jalan yang nggak pernah ketemu, dan
            // selisihnya nggak pernah memunculkan error.
            //
            // Kenapa transaksi BARU, bukan transaksi yang di atas: yang di atas
            // sudah commit jauh sebelum PDF-nya dirender, dan status `terbit`
            // memang baru sah sesudah berkasnya beneran ada. Memperbesar
            // transaksi itu sampai mencakup render dompdf berarti menahan
            // `lockForUpdate` baris sesi selama seluruh pencetakan.
            //
            // Kalau sinkronnya meledak, status `terbit` ikut dibatalkan dan
            // jatuh ke `catch` di bawah — sertifikatnya `gagal`, admin
            // dikabarin, tombol retry muncul. Itu disengaja: sertifikat terbit
            // yang jadwal alatnya nggak ikut ter-update adalah persis keadaan
            // yang perbaikan ini ada untuk mencegahnya.
            DB::transaction(function () use ($sertifikat, $sesi, $path, $sinkronJadwal): void {
                $sertifikat->update([
                    'pdf_path' => $path,
                    'status' => Certificate::STATUS_TERBIT,
                ]);

                // Dibaca ulang di dalam transaksi: instance `$sesi->equipment`
                // dimuat sebelum PDF dirender, dan `untuk()` membandingkan nilai
                // yang berlaku sekarang buat memutuskan perlu nulis atau nggak.
                $alat = $sesi->equipment?->fresh();

                if ($alat !== null) {
                    $sinkronJadwal->untuk($alat);
                }
            });
        } catch (\Throwable $e) {
            // Status `gagal` bikin tombol retry muncul di mobile, bukan diem-diem
            // ngilang. Sesi tetap `disetujui`.
            $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

            // Kabarin admin (fase-2 §2). Ini kegagalan paling sunyi di sistem:
            // sesinya udah disetujui, teknisi udah dikabarin "disetujui", tapi
            // PDF-nya nggak pernah jadi. Sebelum ini cuma keliatan kalau ada yang
            // kebetulan buka layar sertifikatnya — sementara pelanggan nungguin
            // dokumen yang dari sisi lab kelihatan udah selesai.
            $this->kabarinKegagalan($sertifikat, $e);

            throw $e;
        }

        // Dua langkah di bawah ini pelengkap, bukan syarat sahnya sertifikat.
        // Kalau salah satunya meledak, sertifikatnya udah terbit & PDF-nya udah
        // ada — jangan sampai job-nya di-retry cuma gara-gara ini, karena retry
        // bakal ngulang dari nol dan bikin nomor baru.
        try {
            $folder->tautkanSertifikat($sertifikat->fresh()->load('session.equipment.customer'));
            $this->kabarin($sertifikat);
            // Sinyal realtime: sertifikat baru terbit → HP & desktop refresh barengan.
            PerubahanDataOrganisasi::dispatch(
                $sertifikat->organization_id, 'sertifikat', 'diterbitkan', $sertifikat->id,
            );
        } catch (\Throwable $e) {
            Log::warning('Sertifikat terbit, tapi penautan folder/notifikasi gagal.', [
                'certificate_id' => $sertifikat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Timeout worker tidak melewati `catch` di `handle()`. Tanpa penutup ini,
     * sertifikat berhenti di `menunggu_generate` ketika seluruh percobaan habis
     * dan mobile tidak punya tombol pemulihan.
     */
    public function failed(?\Throwable $exception): void
    {
        $penyebab = $exception ?? new RuntimeException('Penerbitan sertifikat berhenti tanpa pesan galat.');

        $sertifikat = Certificate::query()
            ->where('calibration_session_id', $this->calibrationSessionId)
            ->where('status', Certificate::STATUS_MENUNGGU_GENERATE)
            ->first();

        if ($sertifikat === null) {
            $this->tinggalkanJalanPulih($penyebab);

            return;
        }

        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        $this->kabarinKegagalan($sertifikat, $penyebab);
    }

    /**
     * Beri sesi yang sudah `disetujui` baris sertifikat `gagal`, kalau
     * penerbitannya berhenti SEBELUM barisnya sempat lahir.
     *
     * Transaksi pertama `handle()` — yang mengalokasikan nomor — duduk di luar
     * `try`, dan `dispatch()` di `approve()` jalan sesudah status sesi
     * di-commit. Gagal di salah satu titik itu (deadlock, koneksi putus,
     * antrean menolak job) meninggalkan sesi disetujui tanpa baris sertifikat
     * sama sekali: `sertifikat:sapu-tertunda` cuma menyapu `menunggu_generate`,
     * dan tombol "Terbitkan ulang" cuma muncul untuk `gagal`. Tidak ada jalan
     * keluar selain menyunting database.
     *
     * Kenapa baris `gagal`, bukan menyuruh penyapu mengirim ulang job untuk
     * sesi yang tidak punya baris: masa berlaku pilihan admin HANYA hidup di
     * argumen job ini. Pengirim ulang yang menebak argumennya mencetak masa
     * berlaku default di dokumen terakreditasi, tanpa satu pun error. Di sini
     * nilainya masih dipegang, jadi ditulis ke baris yang kemudian diwariskan
     * `CertificateController::retry()`.
     *
     * Tanpa nomor, dengan sengaja: dokumen yang tidak pernah terbit tidak
     * boleh menghabiskan satu nomor dari urutan lab. `handle()` memberinya
     * nomor waktu diterbitkan ulang.
     */
    public function tinggalkanJalanPulih(\Throwable $penyebab): void
    {
        $sertifikat = DB::transaction(function (): ?Certificate {
            // Lock yang sama dengan `handle()`: job lain untuk sesi ini yang
            // kebetulan sedang membuat barisnya ditunggu, bukan ditabrak.
            $sesi = CalibrationSession::whereKey($this->calibrationSessionId)->lockForUpdate()->first();

            $dasar = $this->revisiDariId === null ? null : Certificate::query()->find($this->revisiDariId);

            $sudahAda = $dasar !== null
                ? Certificate::query()->where('calibration_session_id', $this->calibrationSessionId)->where('revision_of', $dasar->id)->exists()
                : $sesi?->certificate()->exists();

            if (! $sesi || $sesi->status !== CalibrationSession::STATUS_DISETUJUI || $sudahAda) {
                return null;
            }

            $token = $this->tokenUnik();

            $atribut = [
                'organization_id' => $sesi->organization_id,
                'issued_by' => $this->issuedBy,
                'qr_token' => $token,
                'qr_payload' => rtrim((string) config('app.url'), '/')."/verify/{$token}",
                'berlaku_sampai' => $this->berlakuSampai,
                'status' => Certificate::STATUS_GAGAL,
            ];

            if ($dasar !== null) {
                // Nomor revisi sudah pasti (`<dasar>-R<n>`) — dipegang barisnya supaya
                // retry tidak mencari nomor lain.
                return Certificate::query()->create([
                    ...$atribut,
                    'calibration_session_id' => $sesi->id,
                    'revision_of' => $dasar->id,
                    'revisi_ke' => (int) $dasar->revisi_ke + 1,
                    'nomor' => Certificate::nomorDasar((string) $dasar->nomor).'-R'.((int) $dasar->revisi_ke + 1),
                    'alasan_revisi' => 'Sertifikat '.$dasar->nomor.' ditarik: '.$dasar->alasan_ditarik,
                ]);
            }

            // Nomor yang diketik admin (K-50-04) ikut disimpan: nomor manual
            // sudah dipesan pemiliknya, tidak boleh hilang gara-gara antrean macet.
            return $sesi->certificate()->create([...$atribut, 'nomor' => $this->nomorSertifikat]);
        });

        if ($sertifikat === null) {
            return;
        }

        Log::error('Penerbitan sertifikat berhenti sebelum barisnya lahir; baris `gagal` dibuat supaya bisa diterbitkan ulang.', [
            'certificate_id' => $sertifikat->id,
            'calibration_session_id' => $this->calibrationSessionId,
            'penyebab' => $penyebab->getMessage(),
        ]);

        $this->kabarinKegagalan($sertifikat, $penyebab);
    }

    /** Kabarin teknisi yang ngerjain + admin yang nerbitin. */
    private function kabarin(Certificate $sertifikat): void
    {
        $sertifikat->loadMissing('session.equipment', 'session.teknisi');

        $penerima = User::query()
            ->whereIn('id', array_filter([$sertifikat->session?->teknisi_id, $sertifikat->issued_by]))
            ->where('status', User::STATUS_AKTIF)
            ->get();

        /** @var User $user */
        foreach ($penerima as $user) {
            $user->notify(SertifikatTerbit::dariSertifikat($sertifikat));
        }
    }

    /**
     * Kabarin admin kalau pembuatan sertifikat gagal.
     *
     * Dibungkus `try` sendiri: kalau ngirim notifikasinya juga meledak, yang harus
     * kelihatan di log tetap error ASLINYA (yang bikin sertifikatnya gagal), bukan
     * error notifikasinya. Salah nutupin di sini bikin penyebab sebenernya ilang.
     */
    private function kabarinKegagalan(Certificate $sertifikat, \Throwable $penyebab): void
    {
        try {
            $sertifikat->loadMissing('session');
            $notifikasi = SertifikatGagal::dariSertifikat($sertifikat, $penyebab->getMessage());

            foreach (app(PenerimaNotifikasi::class)->adminAktif($sertifikat->organization_id) as $admin) {
                $admin->notify($notifikasi);
            }
        } catch (\Throwable $e) {
            Log::warning('Sertifikat gagal, dan ngabarin admin soal itu juga gagal.', [
                'certificate_id' => $sertifikat->id,
                'penyebab_asli' => $penyebab->getMessage(),
                'error_notifikasi' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Masa berlaku sertifikat: pilihan admin dulu, baru default organisasi.
     *
     * Interval kalibrasi itu keputusan teknis — tergantung jenis alat, seberapa
     * sering dipakai, dan permintaan pelanggan — jadi nggak bisa dipaksa satu
     * angka buat semua. Yang dipatok backend cuma batasnya (lihat validasi di
     * `CalibrationController::approve()`); angkanya milik admin.
     *
     * Dihitung dari TANGGAL KALIBRASI, bukan tanggal terbit. Sertifikat bisa
     * terbit beberapa hari sesudah alat dikerjain (di contoh Tirta Contoh:
     * kalibrasi 26 Mei, terbit 30 Mei) — kalau dihitung dari tanggal terbit,
     * masa berlakunya diam-diam kepanjangan, dan alat lewat jatuh tempo tanpa
     * ada yang sadar.
     */
    private function berlakuSampai(CalibrationSession $sesi): CarbonInterface
    {
        if ($this->berlakuSampai !== null) {
            return Carbon::parse($this->berlakuSampai)->startOfDay();
        }

        $dasar = $sesi->tanggal_kalibrasi ?? now();

        return $dasar->copy()->addMonthsNoOverflow(
            $sesi->organization?->masaBerlakuBulan() ?? Organization::DEFAULT_MASA_BERLAKU_BULAN,
        )->startOfDay();
    }

    /**
     * Nomor sertifikat urut per organisasi per bulan: CAL/2026/07/0001.
     *
     * Nomor revisi (`…/0011-R1`) WAJIB disaring. Tanpa itu `orderByDesc('nomor')`
     * memilih `…/0011-R1` sebagai yang terbesar, `substr(..., -4)` memulangkan
     * `1-R1`, `(int)` jadi 1, dan sertifikat baru berikutnya mendapat `0002` —
     * nomor yang sudah terbit (§38.3). Dijaga `NomorSertifikatSesudahRevisiTest`.
     */
    private function nomorBerikutnya(int $organizationId): string
    {
        $prefix = sprintf('CAL/%s/', now()->format('Y/m'));

        $terakhir = Certificate::where('organization_id', $organizationId)
            ->where('nomor', 'like', $prefix.'%')
            ->where('nomor', 'not like', '%-R%')
            ->lockForUpdate()
            ->orderByDesc('nomor')
            ->value('nomor');

        $urutan = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Sudah ada sertifikat `terbit` untuk penerbitan INI: yang asli, atau —
     * kalau ini terbit ulang sesudah penarikan (K-50-03) — yang menggantikan
     * [$revisiDariId].
     */
    private function sudahTerbit(CalibrationSession $sesi): bool
    {
        if ($this->revisiDariId !== null) {
            return Certificate::query()
                ->where('revision_of', $this->revisiDariId)
                ->where('status', Certificate::STATUS_TERBIT)
                ->exists();
        }

        return $sesi->certificate()->where('status', Certificate::STATUS_TERBIT)->exists();
    }

    private function tokenUnik(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (Certificate::where('qr_token', $token)->exists());

        return $token;
    }
}
