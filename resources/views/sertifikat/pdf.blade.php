{{--
    Sertifikat kalibrasi — LAYOUT BAKU (spesifikasi poin 9).

    Isinya diambil BULAT-BULAT dari `certificates.snapshot`, bukan dari relasi
    model. Snapshot dibekukan waktu terbit, jadi cetak ulang tahun depan tetap
    keluar angka & alamat yang sama persis kayak salinan yang dipegang pelanggan.

    Empat bagian, nggak boleh ditambah: header informasi, tabel hasil kalibrasi
    (+ dua catatan baku), tabel standar yang dipakai, footer. Kalau ada
    permintaan nambah kolom, yang diubah spesifikasinya dulu — bukan file ini.
--}}
@php
    $header = $snapshot['header'] ?? [];
    $footer = $snapshot['footer'] ?? [];
    $meta = $snapshot['meta'] ?? [];
    $organisasi = $meta['organization'] ?? [];
    $desimal = $snapshot['desimal'] ?? \App\Support\Angka::DESIMAL_DEFAULT;

    $tgl = fn (?string $iso): string => $iso
        ? \Illuminate\Support\Carbon::parse($iso)->translatedFormat('d F Y')
        : '—';
    $angka = fn ($nilai): string => \App\Support\Angka::id($nilai === null ? null : (float) $nilai, $desimal);
    $isi = fn (?string $nilai): string => filled($nilai) ? $nilai : '—';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Sertifikat {{ $header['certificate_number'] ?? $sertifikat->nomor }}</title>
    <style>
        /*
          Margin halaman DIPATOK ketat & seragam biar (a) kop surat bisa nempel
          ke pinggir atas/samping (dulu default dompdf ~0,5in bikin kop ngambang
          jauh dari tepi & isinya kedorong ke bawah — kelihatan jelek), dan (b)
          seluruh isi muat SATU halaman. Cuma kena PDF (dompdf baca @page);
          mode web pakai `.lembar` sendiri.
        */
        @page { margin: 0.85cm 1.05cm; }

        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1a1a1a; margin: 0; line-height: 1.4; }

        /*
          Kop surat banner FULL-BLEED: margin negatif nariknya keluar sampai tepi
          halaman (nutup margin @page di atas & dua sisi), jadi kopnya rapat ke
          pinggir kertas — bukan kotak ngambang di tengah. Gambarnya 1289x225
          (5,73:1); `width: 100%` jaga rasionya.

          SENGAJA tanpa garis bawah: kop-nya sendiri udah punya lengkung biru di
          bagian bawah gambarnya.
        */
        .kop-gambar { margin: -0.85cm -1.05cm 10px; }
        .kop-gambar img { width: 100%; height: auto; display: block; }
        .judul-kelompok { font-size: 10.5px; font-weight: bold; margin: 8px 0 3px; }
        .data tr.u95 td { font-weight: bold; text-align: right; }
        .ket-k { font-size: 8.5px; color: #333; margin: 2px 0 6px; }
        .kop { border-bottom: 3px double #333; padding-bottom: 10px; margin-bottom: 14px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td.logo { width: 84px; vertical-align: middle; }
        .kop td.logo img { width: 74px; height: auto; }
        .kop td.teks { vertical-align: middle; }
        .kop h1 { font-size: 17.5px; margin: 0 0 2px; }
        .kop .akr { font-size: 10.5px; color: #555; }

        .judul { text-align: center; font-size: 16px; font-weight: bold; letter-spacing: 1px; margin: 2px 0 12px; }

        table.info { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.info td { padding: 3px 6px; vertical-align: top; }
        /*
          Label ditebelin & digelapin: di kertas cetak, label abu tipis bikin
          mata harus balik-balik nyari mana nama kolom mana isinya. Yang dibaca
          orang di sertifikat itu pasangan label→nilai, jadi labelnya harus
          kebaca sekali lihat.
        */
        table.info td.lbl { width: 17%; color: #222; font-weight: bold; }
        table.info td.val { width: 33%; }

        /* Nama alat ditebelin: itu yang dicari pertama waktu nyocokin lembar
           di tangan sama alat di meja, jadi dia mesti kebaca tanpa nyusurin
           tabel. Label "Equipment Name"-nya udah tebel — nilainya ikut, biar
           yang nempel di mata barisnya, bukan cuma judul kolomnya. */
        table.info td.val.alat { font-weight: bold; }

        /*
          Sel yang isinya boleh BERTUMPUK. Cuma dipakai Capacity/Graduation:
          alat ber-resolusi bertingkat nyetak satu resolusi per baris, persis
          sertifikat asli. Tanpa `pre-line`, `\n` dari snapshot diratakan jadi
          spasi dan barisnya membungkus asal di tengah angka.
        */
        table.info td.val.tumpuk { white-space: pre-line; }

        .judul-sub { font-size: 11.5px; font-weight: bold; margin: 10px 0 5px; letter-spacing: .5px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 11px; }
        /*
          Padding VERTIKAL sengaja lebih kecil dari horizontal, dan itu yang
          nentuin sertifikat muat sehalaman. Tingginya dikali jumlah baris —
          sertifikat 4 standar (Conductivity: 3 larutan + termometer) punya 9
          baris tabel, jadi 1px padding = 18px tinggi halaman. Padding
          horizontalnya nggak ngefek ke sini; jangan ikut dikecilin.
        */
        table.data th, table.data td { border: 1px solid #999; padding: 3px 8px; text-align: center; }
        table.data th { background: #efefef; }
        table.data td.kiri { text-align: left; }

        /*
          Mode PADAT — buat sertifikat berbaris banyak (Spectrophotometer: 24
          titik dalam tiga kelompok). Sertifikat ini WAJIB satu halaman
          ("Page 1 of 1" dicetak di headernya), dan ukuran normal cuma muat
          sampai ±12 baris.

          Yang dikecilin cuma padding & tinggi baris, BUKAN angkanya: lembar
          master lab juga nyetak 24 baris itu rapat dalam satu halaman. Alat
          lain nggak kena sama sekali — kelasnya baru nempel kalau barisnya
          lebih dari 12.
        */
        body.padat table.data { font-size: 8px; }
        body.padat table.data th,
        body.padat table.data td { padding: 0.5px 6px; }
        body.padat table.data th,
        body.padat table.data td { line-height: 1.1; }
        body.padat .judul-kelompok { font-size: 9.5px; margin: 5px 0 2px; }
        body.padat .ket-k { font-size: 7.5px; margin: 1px 0 3px; }
        body.padat .judul-sub { margin: 8px 0 4px; }
        body.padat .catatan { font-size: 8px; margin-top: 4px; line-height: 1.3; }
        body.padat .kode-dokumen { margin-top: 4px; padding-top: 2px; font-size: 8px; }
        /*
          Blok kepala & tanda tangan ikut dirapetin. Diukur ke kasus terberat
          yang ada sekarang: Spectrophotometer 24 titik / 3 kelompok / 3 standar.
          Kalau nanti ada alat yang barisnya lebih banyak lagi, ukur ulang pakai
          alat ITU — bukan nurunin huruf lagi sampai kebacaan sertifikat rusak.
        */
        body.padat .judul { font-size: 14px; margin: 0 0 6px; }
        body.padat table.info { margin-bottom: 4px; font-size: 8.5px; }
        body.padat table.info td { padding: 0.5px 6px; line-height: 1.25; }
        body.padat .judul-sub { font-size: 10.5px; margin: 6px 0 3px; }
        body.padat table.ttd { margin-top: 6px; font-size: 9.5px; }
        body.padat table.ttd td { font-size: 9.5px; }
        body.padat table.ttd td.qr { width: 78px; }
        body.padat table.ttd td.qr img { width: 66px; height: 66px; }
        body.padat .ttd .ruang-ttd { height: 24px; }
        body.padat .ttd td { padding-top: 4px; }
        body.padat .kop { padding-bottom: 5px; margin-bottom: 8px; }
        body.padat .kop-gambar { margin-bottom: 5px; }
        .catatan { font-size: 10px; font-style: italic; color: #444; margin-top: 8px; line-height: 1.5; }
        .catatan div { margin-bottom: 3px; }

        .putusan { text-align: center; font-size: 14.5px; font-weight: bold; padding: 6px; margin: 10px 0; border: 2px solid; }
        .pass { color: #146c2e; border-color: #146c2e; }
        .fail { color: #a01919; border-color: #a01919; }

        table.ttd { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.ttd td { vertical-align: top; font-size: 11.5px; }
        table.ttd td.qr { width: 110px; text-align: center; }
        table.ttd td.qr img { width: 92px; height: 92px; }
        table.ttd td.qr .ket { font-size: 7.5px; color: #666; margin-top: 2px; }
        /*
          `margin-top` DIBUANG dari `.garis` dan dipindah jadi tinggi `.ruang-ttd`
          di bawah. Bukan ditambah — kalau dua-duanya ada, jaraknya jadi 88px dan
          tata letak semua sertifikat yang udah pernah dicetak ikut berubah.
        */
        .ttd .garis { border-top: 1px solid #333; padding-top: 3px; }

        /*
          Ruang tanda tangan. Tingginya DIPATOK (44px -> 46px) — BUKAN ngikut
          gambar. Kalau ikut, dua sertifikat dengan format resmi yang sama jadi
          beda tata letak cuma gara-gara yang satu diunggahin gambar TTD, dan
          itu nggak boleh buat dokumen berformat baku.

          SEMUA angka di blok cetak ini (skala huruf, padding, jarak TTD) diukur
          ke KASUS TERBERAT: sertifikat pH 3 baris hasil + 3 standar. Percobaan
          pertama naikin huruf ke 12px dan jarak TTD ke 46px — turbidimeter yang
          cuma 2 baris tetap muat, tapi pH-nya jadi DUA HALAMAN. Sertifikat ini
          wajib satu halaman ('Page 1 of 1' dicetak di headernya), jadi kalau
          ada yang mau ngegedein huruf lagi: ukur ulang pakai sertifikat 3 baris,
          bukan yang 2.
        */
        .ttd .ruang-ttd { height: 46px; position: relative; }
        .ttd .ruang-ttd img { position: absolute; bottom: 0; }

        .kode-dokumen { font-size: 9.5px; color: #666; margin-top: 8px; border-top: 1px solid #ccc; padding-top: 5px; }

        @if ($web ?? false)
        /*
          Mode web — cuma dipakai halaman hasil scan QR. Isinya NGGAK disentuh
          sama sekali di sini: yang ditambah cuma bingkai layar (latar abu,
          lembar putih di tengah) biar kebaca di HP. Dompdf nggak pernah kena
          blok ini, jadi PDF-nya nggak mungkin ikut berubah.
        */
        body {
            background: #eef1f5;
            /* Ukuran font lembar dipatok px kecil buat cetak; di layar itu
               kekecilan, jadi dinaikin khusus web. */
            font-size: 13px;
            line-height: 1.5;
            padding: 16px 12px 40px;
            -webkit-text-size-adjust: 100%;
        }
        .lembar {
            background: #fff;
            max-width: 820px;
            margin: 0 auto;
            padding: 28px 32px 36px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .06),
                        0 8px 24px rgba(16, 24, 40, .08);
        }

        .bilah {
            max-width: 820px;
            margin: 0 auto 14px;
            background: #0f766e;
            color: #fff;
            border-radius: 12px;
            padding: 16px 18px;
        }
        .bilah .cap { font-size: 15px; font-weight: bold; letter-spacing: .2px; }
        .bilah .ket { font-size: 12px; opacity: .92; margin-top: 4px; }
        .bilah .aksi { margin-top: 14px; }
        .bilah a {
            display: inline-block;
            background: #fff;
            color: #0f766e;
            text-decoration: none;
            font-weight: bold;
            font-size: 12.5px;
            padding: 9px 16px;
            border-radius: 8px;
            margin: 0 8px 8px 0;
        }

        /*
          Tabel hasil & standar dibikin selebar lembarnya.

          Sempat kebalik: `display: block` dipasang biar bisa digeser di HP,
          tapi itu bikin `width: 100%`-nya tabel nggak berlaku lagi — tabelnya
          nyusut jadi selebar isinya doang dan kelihatan nyempil di kiri.
          Sekarang: lebar penuh di layar lebar, geser-horizontal cuma di layar
          sempit (aturan `@media` di bawah).
        */
        .lembar table.data { width: 100%; }
        .lembar table.data th,
        .lembar table.data td { padding: 7px 8px; }

        /* Label header sertifikat: kolomnya nggak ditarik sempit, biar
           labelnya nggak kepotong dua baris di tengah kata. */
        .lembar table.info td { padding: 5px 8px; }
        .lembar table.info td.lbl { width: 22%; white-space: nowrap; }
        .lembar table.info td.val { width: 28%; }

        .lembar .judul { margin: 6px 0 18px; }
        .lembar .judul-sub { margin: 22px 0 8px; }
        .lembar .kop { margin-bottom: 18px; }
        /*
          Di web, kop TETAP di dalam kartu putih — full-bleed margin negatif
          (buat PDF) dibatalin di sini biar banner-nya nggak nyembul keluar
          kartu yang punya sudut membulat.
        */
        .lembar .kop-gambar { margin: 0 0 16px; }

        @media (max-width: 720px) {
            body { padding: 10px 8px 32px; }
            .lembar { padding: 18px 16px 26px; border-radius: 10px; }
            .bilah { border-radius: 10px; padding: 14px; }
            .bilah a { display: block; text-align: center; margin-right: 0; }

            /*
              Header info balik jadi SATU kolom.

              Di kertas dia dua pasang label→nilai bersebelahan; dipaksa muat
              di layar 360px, tiap sel jadi setumpuk kata terpotong yang
              nggak kebaca. Satu kolom lebih panjang, tapi kebaca.
            */
            .lembar table.info,
            .lembar table.info tbody,
            .lembar table.info tr,
            .lembar table.info td { display: block; width: auto; }
            .lembar table.info tr { padding: 2px 0; }
            .lembar table.info td.lbl {
                width: auto;
                padding: 6px 0 0;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: .4px;
                color: #667085;
            }
            .lembar table.info td.val {
                width: auto;
                padding: 0 0 6px;
                font-size: 14px;
                border-bottom: 1px solid #eef1f5;
            }

            /* Tabel angka nggak bisa dilipat — dia yang digeser sendiri,
               bukan seluruh halaman yang ikut goyang. */
            .lembar table.data { display: block; overflow-x: auto; width: 100%; }
        }
        @endif
    </style>
</head>
@php($padat = ! ($web ?? false) && collect($snapshot['hasil'] ?? [])->count() > 12)
<body class="{{ $padat ? 'padat' : '' }}">
@if ($web ?? false)
    <div class="bilah">
        <div class="cap">&#10003; Sertifikat terverifikasi</div>
        <div class="ket">
            Lembar di bawah ini salinan sah dari sistem
            {{ $snapshot['meta']['organization']['nama'] ?? 'laboratorium' }}.
            Cocokkan dengan lembar yang kamu pegang.
        </div>
        <div class="aksi">
            <a href="{{ route('verify.download', $sertifikat->qr_token) }}">Unduh PDF</a>
            <a href="{{ route('verify.download', $sertifikat->qr_token) }}?format=xlsx">Unduh Excel</a>
        </div>
    </div>
    <div class="lembar">
@endif
    {{--
      Kop surat. Kalau kop banner-nya ada, dia dipakai SENDIRIAN — nama PT,
      alamat, dan nomor akreditasi udah tercetak di dalam gambarnya, jadi kop
      teks di bawah nggak ikut dirender. Kalau dua-duanya dicetak, alamat &
      nomor akreditasi muncul dobel, dan yang versi teks bisa basi duluan waktu
      lab pindah kantor sementara gambarnya belum diganti.

      Kop teks tetap dipertahanin sebagai jalur cadangan: organisasi yang belum
      punya berkas kop tetap dapat sertifikat berkepala, bukan lembar telanjang.
    --}}
    @if (! empty($kop))
        <div class="kop-gambar"><img src="{{ $kop }}" alt="Kop surat"></div>
    @else
    <div class="kop">
        <table>
            <tr>
                @if (! empty($logo))
                    <td class="logo"><img src="{{ $logo }}" alt="Logo"></td>
                @endif
                <td class="teks">
                    <h1>{{ $organisasi['nama'] ?? 'Laboratorium Kalibrasi' }}</h1>
                    <div class="akr">
                        Terakreditasi {{ $organisasi['standar_akreditasi'] ?? 'KAN' }}
                        &middot; No. {{ $organisasi['no_akreditasi'] ?? '—' }}
                        @if (! empty($organisasi['alamat'])) <br>{{ $organisasi['alamat'] }} @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>
    @endif

    <div class="judul">CALIBRATION CERTIFICATE</div>

    {{-- Header informasi: 16 field, urutannya dikunci spesifikasi. --}}
    <table class="info">
        <tr>
            <td class="lbl">Certificate Number</td><td class="val">{{ $isi($header['certificate_number'] ?? null) }}</td>
            <td class="lbl">Page</td><td class="val">{{ $isi($header['page'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Owner</td><td class="val">{{ $isi($header['owner'] ?? null) }}</td>
            <td class="lbl">Order Number</td><td class="val">{{ $isi($header['order_number'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Address</td><td class="val">{{ $isi($header['address'] ?? null) }}</td>
            <td class="lbl">Received Date</td><td class="val">{{ $tgl($header['received_date'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Equipment Name</td><td class="val alat">{{ $isi($header['equipment_name'] ?? null) }}</td>
            <td class="lbl">Manufacturer</td><td class="val">{{ $isi($header['manufacturer'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Calibration Location</td><td class="val">{{ $isi($header['calibration_location'] ?? null) }}</td>
            <td class="lbl">Model/Type</td><td class="val">{{ $isi($header['model_type'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Calibration Date</td><td class="val">{{ $tgl($header['calibration_date'] ?? null) }}</td>
            <td class="lbl">Serial Number</td><td class="val">{{ $isi($header['serial_number'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Calibration Method</td><td class="val">{{ $isi($header['calibration_method'] ?? null) }}</td>
            <td class="lbl">Capacity/Graduation</td><td class="val tumpuk">{{ $isi($header['capacity_graduation'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Env. Condition</td><td class="val">{{ $isi($header['env_condition'] ?? null) }}</td>
            <td class="lbl">Technician ID</td><td class="val">{{ $isi($header['technician_id'] ?? null) }}</td>
        </tr>
    </table>

    <div class="judul-sub">CALIBRATION REPORT</div>
    {{-- Kolom "Remark" cuma dicetak kalau alatnya emang punya keterangan
         parameter per titik (Chlorine: Free/Total Chlorine). Sertifikat lama &
         alat lain nggak berubah sama sekali — kolomnya nggak muncul, bukan
         muncul kosong. --}}
    {{-- Alat yang titiknya BERKELOMPOK dicetak per kelompok, persis lembar
         master: satu tabel per blok, dan `Uncertainty U95% = ±` di bawah tiap
         tabel. Bukan gaya-gayaan — U95 alat kayak Spectrophotometer lahir per
         KELOMPOK, jadi sepuluh baris Holmium punya angka yang sama persis. Di
         tabel datar 24 baris angka itu kebaca kayak muncul acak, dan `0,4 nm`
         nggak punya cara dibedain punya Didynium apa Holmium.

         Alat tanpa keterangan titik (pH, Turbidimeter, Refractometer) lewat
         jalur yang SAMA dengan satu kelompok tanpa judul — bentuk cetaknya
         nggak berubah sama sekali. --}}
    @php($kelompok = collect($snapshot['hasil'] ?? [])->groupBy(fn ($b) => $b['remark'] ?? ''))

    @forelse ($kelompok as $judulKelompok => $barisKelompok)
        @if (filled($judulKelompok))
            <div class="judul-kelompok">{{ $judulKelompok }}</div>
        @endif

        {{-- Satuan diambil dari baris pertama kelompoknya: satu kelompok selalu
             satu satuan (nm buat panjang gelombang, %T buat transmitan), dan
             lembar master nulisnya di KEPALA kolom — `Standard (nm)`. Alat
             bersatuan seragam ngirim null dan kepalanya tetap kayak dulu. --}}
        @php($satKelompok = $barisKelompok->first()['satuan'] ?? null)
        @php($sufiks = $satKelompok ? ' ('.$satKelompok.')' : '')
        <table class="data">
            <thead>
                <tr>
                    <th>Standard{{ $sufiks }}</th>
                    <th>Unit Under Test{{ $sufiks }}</th>
                    <th>Correction{{ $sufiks }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($barisKelompok as $baris)
                    {{-- Desimal diambil PER BARIS dulu, baru jatuh ke $desimal
                         tingkat-sertifikat. Alat yang resolusinya berubah
                         menurut rentang (Turbidimeter: 0,01 di bawah 10 NTU,
                         0,1 di 10–100, 1 di atasnya) nggak bisa diwakili satu
                         angka: dipaksa satu, titik 100 NTU kecetak `101,00` —
                         dua digit yang alatnya nggak bisa tampilkan. --}}
                    @php($db = $baris['desimal'] ?? $desimal)
                    {{-- Koreksi negatif yang membulat ke nol: `-0,0` di
                         Turbidimeter/pH/Chlorine, `0,0` di Conductivity. Beda
                         itu dibaca dari master masing-masing, bukan dinalar —
                         lihat `CalibrationProfile::tandaNolDicetak()`. --}}
                    @php($tandaNol = $baris['tanda_nol'] ?? true)
                    <tr>
                        {{-- `nilaiStandar` buat kolom pertama, `hasil` buat
                             sisanya: Standard Value nulis nilai NOMINAL
                             standarnya (`1`, `100`, `1000`), dua kolom lain
                             ikut desimal barisnya. --}}
                        <td>{{ \App\Support\Angka::nilaiStandar($baris['standard_value'] === null ? null : (float) $baris['standard_value'], $db) }}</td>
                        <td>{{ \App\Support\Angka::hasil($baris['unit_under_test'] === null ? null : (float) $baris['unit_under_test'], $db, tandaNol: $tandaNol) }}</td>
                        <td>{{ \App\Support\Angka::hasil($baris['correction'] === null ? null : (float) $baris['correction'], $db, tandaNol: $tandaNol) }}</td>
                    </tr>
                @endforeach

                {{-- U95 satu kelompok — diambil dari baris pertama, BUKAN
                     dihitung ulang. Tiap titik sekelompok emang bawa angka yang
                     sama; kalau suatu saat beda, yang salah datanya, dan
                     ngerata-ratain di sini cuma nyembunyiin itu. --}}
                @php($barisU95 = $barisKelompok->first())
                {{-- Desimal U95 punya jalurnya sendiri: master nyetak
                     `0,50 %T` sementara kolom UUT & Correction di blok yang
                     sama pakai tiga desimal (`9,665`). Sertifikat lama yang
                     snapshot-nya belum punya kunci ini jatuh ke desimal titik,
                     persis kayak waktu diterbitkan. --}}
                @php($dbU95 = $barisU95['desimal_u95'] ?? $barisU95['desimal'] ?? $desimal)
                <tr class="u95">
                    <td colspan="2">Uncertainty U<sub>95%</sub> = &plusmn;</td>
                    <td>{{ \App\Support\Angka::hasil($barisU95['u95'] === null ? null : (float) $barisU95['u95'], $dbU95) }}{{ $satKelompok ? ' '.$satKelompok : '' }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Kalimat faktor cakupan dicetak PER KELOMPOK: k-nya beda-beda
             (Holmium 3,18; Didynium 2,36; %T 2,01). Sertifikat lama yang
             snapshot-nya belum punya `faktor_cakupan_k` nggak nyetak baris ini
             sama sekali — bukan ngarang angkanya. --}}
        @php($k = $barisKelompok->first()['faktor_cakupan_k'] ?? null)
        @if ($k !== null)
            <div class="ket-k">
                The Uncertainty is taken at a Confidence Level 95 % and Coverage Factor ( k ) =
                {{ \App\Support\Angka::idRingkas((float) $k, 2) }}
            </div>
        @endif
    @empty
        <table class="data">
            <thead><tr><th>Standard</th><th>Unit Under Test</th><th>Correction</th></tr></thead>
            <tbody><tr><td colspan="3">—</td></tr></tbody>
        </table>
    @endforelse

    {{-- Catatan baku dari snapshot. Kalimat "The Uncertainty is taken at a
         Confidence Level…" DILEWATI kalau tiap kelompok udah nyetak kalimatnya
         sendiri: k-nya beda per kelompok (Holmium 3,18; Didynium 2,36; %T
         2,01), jadi kalimat tingkat-sertifikat yang nyebut satu angka bukan
         cuma dobel — dia mbantah tiga baris di atasnya. --}}
    @php($adaKGrup = collect($snapshot['hasil'] ?? [])->contains(fn ($b) => ($b['faktor_cakupan_k'] ?? null) !== null))
    <div class="catatan">
        @foreach ($snapshot['catatan'] ?? [] as $catatan)
            @continue ($adaKGrup && str_contains($catatan, 'Coverage Factor'))
            <div>{{ $catatan }}</div>
        @endforeach
    </div>

    {{-- Keputusan PASS/FAIL bukan bagian struktur baku — cuma muncul kalau lab
         sengaja nyalain lewat pengaturan organisasi. --}}
    @if (! empty($keputusan))
        <div class="putusan {{ strtolower($keputusan) }}">KEPUTUSAN: {{ $keputusan }}</div>
    @endif

    <div class="judul-sub">STANDARD USED</div>
    <table class="data">
        <thead>
            <tr>
                <th>Name</th>
                <th>Merk/Type</th>
                <th>Serial Number</th>
                <th>Traceable to SI through</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot['standar_digunakan'] ?? [] as $standar)
                <tr>
                    <td class="kiri">{{ $isi($standar['name'] ?? null) }}</td>
                    <td>{{ $isi($standar['merk_type'] ?? null) }}</td>
                    <td>{{ $isi($standar['serial_number'] ?? null) }}</td>
                    <td>{{ $isi($standar['traceable_to'] ?? null) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $adaRemark ? 5 : 4 }}">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="ttd">
        {{--
          Issuance Date + tanda tangan di KIRI, QR (kalau dicetak) di kanan.
          Urutan sel inilah yang nentuin posisinya — dompdf nggak dukung
          `float`/flexbox di konteks tabel, jadi jangan coba dibalik pakai CSS.
          Sel kosong di tengah yang mendorong keduanya ke pinggir masing-masing.
        --}}
        <tr>
            <td style="width: 38%;">
                <div>Issuance Date: {{ $tgl($footer['issuance_date'] ?? null) }}</div>

                {{--
                  Ruang tanda tangan. Tingginya dipatok di CSS, jadi tata letaknya
                  SAMA entah gambar TTD-nya ada atau nggak — dua sertifikat dengan
                  format resmi yang sama nggak boleh beda tata letak cuma gara-gara
                  yang satu diunggahin TTD.

                  Kalau `$tandaTangan` null, yang tercetak ruang kosong buat tanda
                  tangan basah. Itu state yang SAH, bukan kekurangan.

                  Geserannya relatif ke ruang ini, bukan koordinat absolut halaman:
                  tinggi isi sertifikat berubah-ubah (jumlah titik ukur & standar
                  beda tiap sesi), jadi koordinat absolut yang pas di satu sertifikat
                  bakal nimpa tabel di sertifikat lain.
                --}}
                <div class="ruang-ttd">
                    @if (! empty($tandaTangan))
                        {{--
                          Arah `geser_y_mm`: POSITIF = naik. Gambarnya di-anchor
                          `bottom: 0`, dan nambah `bottom` mendorongnya ke ATAS —
                          jadi nilainya dipakai apa adanya, JANGAN dinegate. Versi
                          pertama file ini nge-negate, dan efeknya arah drag di UI
                          kebalik: geser ke atas, tanda tangannya turun.
                        --}}
                        <img
                            src="{{ $tandaTangan }}"
                            alt="Tanda tangan"
                            style="
                                width: {{ $posisiTtd['lebar_mm'] ?? 35 }}mm;
                                left: {{ $posisiTtd['geser_x_mm'] ?? 0 }}mm;
                                bottom: {{ $posisiTtd['geser_y_mm'] ?? 0 }}mm;
                            "
                        >
                    @endif
                </div>

                <div class="garis">
                    <strong>{{ $isi($footer['penandatangan'] ?? null) }}</strong><br>
                    {{ $isi($footer['jabatan'] ?? null) }}
                </div>
            </td>
            <td></td>
            @if (! empty($qr))
                <td class="qr">
                    <img src="{{ $qr }}" alt="QR verifikasi">
                    <div class="ket">Scan untuk verifikasi &amp; unduh</div>
                </td>
            @endif
        </tr>
    </table>

    <div class="kode-dokumen">{{ $isi($footer['kode_dokumen'] ?? null) }}</div>
@if ($web ?? false)
    </div>
@endif
</body>
</html>
