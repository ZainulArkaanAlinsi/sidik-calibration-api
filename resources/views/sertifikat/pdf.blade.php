{{--
    Sertifikat kalibrasi — LAYOUT BAKU (spesifikasi poin 9).

    Isinya diambil BULAT-BULAT dari `certificates.snapshot`, bukan dari relasi
    model. Snapshot dibekukan waktu terbit, jadi cetak ulang tahun depan tetap
    keluar angka & alamat yang sama persis kayak salinan yang dipegang pelanggan.

    Empat bagian, nggak boleh ditambah: header informasi, tabel hasil kalibrasi
    (+ dua catatan baku), tabel standar yang dipakai, footer. Kalau ada
    permintaan nambah kolom, yang diubah spesifikasinya dulu — bukan file ini.

    Satu pengecualian yang UDAH lewat jalur itu: `catatan_atas_hasil` (baris
    opsional di atas tabel hasil, `Spindel No.` / `Speed (rpm)` Viscometer) —
    spesifikasinya `docs/PRD-viscometer.md` §7 & `SERTIFIKAT.csv` baris 18.
    `null` di enam alat lain, jadi bentuk cetaknya nggak kesenggol sama sekali.
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
        .catatan-atas-hasil { font-size: 10px; margin: 0 0 5px; }
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
          Kolom R² itu SATU kotak setinggi tabelnya (rowspan), niru sel merge di
          master. Tanpa `middle` angkanya nempel di baris pertama dan kotak
          setinggi lima baris itu kebaca kayak nilai punya baris pertama doang.
        */
        table.data td.r2 { vertical-align: middle; }

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

        /* Pemadatan Autoklaf — lebih lembut dari `padat`. Lihat catatan di
           dekat `$lembarAutoclave`. */
        body.lembar-autoclave table.data { font-size: 9.5px; }
        body.lembar-autoclave table.data th,
        body.lembar-autoclave table.data td { padding: 2px 6px; line-height: 1.25; }
        body.lembar-autoclave .judul-kelompok { margin: 6px 0 3px; }
        body.lembar-autoclave .ket-k { margin: 2px 0 5px; }
        body.lembar-autoclave .judul-sub { margin: 9px 0 5px; }

        /* Timbangan nggak punya blok pemadatan sendiri: lembarnya SELALU
           lewat `padat` (lihat catatan di dekat `$padat`), jadi aturan di sini
           cuma buat yang `padat` nggak atur. Nambah `body.lembar-timbangan
           table.data { font-size: … }` di sini justru MELONGGARKAN lembarnya —
           selektornya sama kuat dengan `body.padat` dan menang karena lebih
           belakang. */
        body.lembar-timbangan .judul-kelompok { margin: 4px 0 1px; }
        /* Bagian yang isinya SATU angka (Effect of Tare, Limit of Performance)
           — dicetak sebaris sama judulnya, persis masternya, bukan sebagai
           tabel satu sel. */
        .baris-angka { margin: 4px 0 1px; font-size: 9.5px; }
        .baris-angka .nama { font-weight: bold; }
        body.padat .baris-angka { margin: 2px 0 1px; font-size: 8.5px; }
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
        body.padat .ttd .ruang-ttd { height: 28px; }
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
          Ruang tanda tangan. Tingginya DIPATOK (44px -> 46px -> 80px -> 86px) — BUKAN
          ngikut gambar. Kalau ikut, dua sertifikat dengan format resmi yang sama
          jadi beda tata letak cuma gara-gara yang satu diunggahin gambar TTD,
          dan itu nggak boleh buat dokumen berformat baku.

          Angkanya HARUS sama dengan App\Support\UkuranTandaTangan — dia yang
          ngitung ukuran cetak gambarnya, dan dijaga
          `UkuranTandaTanganTest::test_konstanta_cocok_dengan_css_blade()`.

          ## Kenapa 46px naik ke 80px

          Kotak ini bukan cuma jarak kosong: dia PLAFON ukuran gambar TTD, karena
          gambar yang lebih tinggi dari kotaknya dikecilkan sampai muat. Di 46px
          (12,17 mm) tanda tangan PT Sidik — 2248x2052, ada ekor turun panjang —
          tercetak 13,33 mm di bawah garis 71,3 mm. 19% lebar garisnya.

          ## Batasnya diukur, bukan ditebak

          Catatan lama di sini bilang kasus terberatnya sertifikat pH 3 baris.
          Itu sudah nggak benar lagi. Seluruh 24 sesi bawaan diterbitkan lalu
          dirender sambil kotak ini disapu: yang paling mepet **Conductivity
          Meter** — masih muat mode normal di 86px, kedorong ke mode padat di
          88px. 86px itu batasnya persis, tanpa margin — disengaja, dan yang
          menahan kalau ada yang lewat `SertifikatSemuaAlatSatuHalamanTest`.

          Yang bikin batas itu halus: kedorong ke mode padat BUKAN kegagalan yang
          kelihatan. `App\Services\SertifikatSatuHalaman` menyelamatkannya, jadi
          lembarnya tetap satu halaman — cuma hurufnya mengecil, dan sertifikat
          alat itu jadi beda bentuk dari alat lain di lab yang sama.

          Jadi kalau ada yang mau ngegedein huruf atau kotak ini lagi: ukur ulang
          pakai Conductivity Meter, bukan pH.

          ## Kenapa versi PADAT-nya (24px, di atas) TIDAK ikut digedein

          Percobaan pertama menaikkannya ke 44px, dan itu bikin sertifikat
          **Visible Spectrofotometer** jadi DUA HALAMAN — dia lembar terpadat di
          sistem (24 titik ketidakpastian) dan mode padat nggak punya jaring
          pengaman lagi di bawahnya.

          Disapu ulang: 30px masih muat, 32px sudah meluap. Jadi 24px itu bukan
          angka konservatif, itu nyaris seluruh margin yang ada. Menukarnya buat
          tanda tangan yang 1 mm lebih besar di enam alat jelas rugi.
        */
        .ttd .ruang-ttd { height: 86px; position: relative; }

        /*
          Gambarnya DITENGAHKAN di atas garis, bukan nempel ke tepi kiri.
          Diukur di sertifikat 012-CAL-524: garisnya 10,76 → 82,06 mm, tapi
          gambarnya mendarat 10,76 → 29,98 mm — 26,0 mm di kiri dari tengah
          garis. Sebabnya `left: 0` pada gambar yang `position: absolute`.

          Yang ditengahkan pembungkusnya, BUKAN gambarnya lewat margin negatif:
          `lebar_mm` boleh `null` (lihat [UkuranTandaTangan]) waktu tingginya
          dijepit ke kotak dan lebarnya ikut rasio gambar. Tanpa lebar yang
          diketahui, margin negatif setengah-lebar nggak bisa dihitung sama
          sekali; `text-align` nggak butuh tahu lebarnya.

          `bottom` tetap di pembungkus supaya jangkar bawahnya nggak berubah —
          gambar tumbuh ke ATAS, dan `geser_y_mm` tetap berarti sama.
        */
        .ttd .ruang-ttd .letak-ttd {
          position: absolute; left: 0; width: 100%; text-align: center;
        }

        /* `geser_x_mm` jadi geseran RELATIF dari tengah, bukan dari tepi kiri. */
        .ttd .ruang-ttd img { position: relative; }

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
{{-- Lembar Timbangan SELALU padat. Pemicu `> 12 baris` nggak kena
     lembarnya: tabel `hasil` cuma 10 baris, tapi yang dicetak DELAPAN bagian
     (dua tabel besar, dua tabel kecil, tiga baris satu-angka) — lembar
     tertinggi yang pernah masuk blade ini. Tanpa ini blok tanda tangan &
     kode dokumen kedorong ke halaman dua sementara kepalanya tetap nulis
     `Page : 1 of 1`; jebakan yang persis sama pernah kena Autoklaf. --}}
{{-- `paksaPadat` datang dari App\Services\SertifikatSatuHalaman: dia merender
     sekali, menghitung halamannya, dan kalau meluap merender ULANG dengan ini
     menyala. Pemicu `> 12 baris` di bawah cuma TEBAKAN — dia memakai jumlah
     baris sebagai wakil tinggi halaman, dan wakilnya bocor buat apa pun yang
     nambah tinggi tanpa nambah baris (logo potret, kop tinggi, catatan
     panjang). Yang ini kenyataan hasil render. --}}
@php($padat = ! ($web ?? false) && (($paksaPadat ?? false) || collect($snapshot['hasil'] ?? [])->count() > 12 || ($snapshot['timbangan'] ?? null) !== null))
{{-- Autoklaf punya pemadatan SENDIRI, bukan numpang `padat`.

     `padat` dirancang buat Spectrophotometer: 24 baris angka dalam satu tabel,
     jadi dia menekan sampai 8px dengan padding 0,5px. Autoklaf bentuknya beda —
     tiga tabel pendek berjudul — dan dipadatkan sekeras itu lembarnya muat satu
     halaman tapi menyisakan sepertiga kertas kosong, dengan angka yang lebih
     kecil dari perlunya di dokumen yang justru dibaca buat nyocokin angka.

     Yang ditekan di sini cuma yang perlu: ukuran huruf tabel turun dari 11px ke
     9,5px dan jarak antar bagian dirapatkan — cukup buat menarik blok tanda
     tangan balik ke halaman pertama, tanpa bikin tabelnya kelihatan sesak. --}}
@php($lembarAutoclave = ! ($web ?? false) && ($snapshot['autoclave'] ?? null) !== null)
@php($lembarTimbangan = ! ($web ?? false) && ($snapshot['timbangan'] ?? null) !== null)
<body class="{{ $padat ? 'padat' : '' }}{{ $lembarAutoclave ? ' lembar-autoclave' : '' }}{{ $lembarTimbangan ? ' lembar-timbangan' : '' }}">
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
    {{-- Baris opsional DI ATAS tabel — `catatan_atas_hasil` di snapshot,
         `null` di enam alat lain, baris nggak dicetak sama sekali. Satu-
         satunya pengecualian yang dikasih ke aturan "empat bagian, nggak
         boleh ditambah" di atas: Viscometer butuh nyetak `Spindel No.` &
         `Speed (rpm)` yang dipakai sesi ini (`SERTIFIKAT.csv` baris 18,
         `docs/PRD-viscometer.md` §7) — dan itu SPESIFIKASINYA, bukan
         penambahan sepihak. Alat lain nggak kesenggol sama sekali. --}}
    @if (filled($snapshot['catatan_atas_hasil'] ?? null))
        <div class="catatan-atas-hasil">{{ $snapshot['catatan_atas_hasil'] }}</div>
    @endif
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

    {{-- Autoklaf: TIGA bagian, bukan tabel empat kolom.

         Bukan pilihan gaya — master `Autoclave_CSV/SERTIFIKAT.csv` emang
         mencetak tiga tabel yang strukturnya beda satu sama lain, karena yang
         diukur juga beda: sebaran suhu antar sensor, kinerja kamar
         (Keseragaman/Kestabilan/Variasi), dan satu titik tekanan. Dipaksa masuk
         tabel `Standard | UUT | Correction`, dua dari tiga bagian kehilangan
         kolomnya.

         Dibaca dari bentuk MENTAH `hasil_autoclave` — keluaran
         `AutoclaveCalculator` apa adanya. Snapshot membekukannya utuh, jadi
         sertifikat yang udah terbit tetap kebaca walau kalkulatornya berubah.

         Tujuh alat lain balik `null` di sini dan lewat jalur lama tanpa berubah
         sama sekali. --}}
    @php($autoclave = $snapshot['autoclave'] ?? null)

    {{-- Timbangan: DELAPAN bagian, dan cuma bagian 3 yang bentuknya mirip
         tabel biasa. Alasannya sama persis kayak Autoklaf di atas — master
         `SERTIFIKAT`-nya emang begitu, dan tujuh bagian lain nggak punya
         kolom `Standard`/`UUT` sama sekali.

         Dibaca dari `TimbanganProfile::ringkasanSertifikat`, dibekukan utuh ke
         snapshot waktu terbit. Dua puluh alat lain balik `null` di sini dan
         lewat jalur lama tanpa berubah sama sekali.

         Bagian 8 (STANDARD USED) nggak dicetak di blok ini: tabel bersama di
         bawah udah nyetaknya. Yang beda cuma masternya punya kolom `Nominal
         Mass` — lihat catatan di docs/perintah-frontend-timbangan.md. --}}
    @php($timbangan = $snapshot['timbangan'] ?? null)

    @if ($autoclave)
        {{-- Desimal dibaca dari FORMAT SEL master `Master Olah Data_Autoclave.xlsm`
             (sheet SERTIFIKAT), bukan diturunkan dari resolusi alat. Sampai
             sebelum ini workbook-nya belum kebaca dan angkanya masih tebakan;
             sekarang ketiganya diadu langsung ke selnya:

               blok suhu   B26/F26/K26/P26/U26/F27… = `0.00`   -> 2 desimal
               tekanan     B39/D39/K39              = `0.000`  -> 3 desimal
               tekanan U95 P39/K42                  = `0.0000` -> 4 desimal
               faktor k    P33 (suhu) & P43 (tekanan) = `0`    -> 0 desimal

             Tekanan sengaja DIPISAH: nilai & koreksinya 3 desimal, U95-nya 4.
             Dulu semuanya 4 dengan alasan "3 desimal bakal mbulatin U95 0,0059
             jadi 0,006" — betul buat U95, tapi kebablasan ke tiga kolom
             lainnya, jadi `0,112 MPa` kecetak `0,1120` dan `0,0111` jadi
             `0,0111` di tempat master nulis `0,011`. Nilai cocok, bentuknya
             yang meleset. --}}
        @php($dSuhu = 2)
        @php($dTekan = 3)
        @php($dTekanU95 = 4)
        @php($suhu = $autoclave['suhu'] ?? [])
        @php($tek = $autoclave['tekanan'] ?? [])
        @php($angka = fn ($v, $d) => $v === null ? '—' : \App\Support\Angka::id((float) $v, $d))

        {{-- Bagian A & B cuma dicetak kalau suhunya MEMANG diukur. Sesi
             tekanan-saja itu skenario sah (lihat `AutoclaveCalculator::hitung`),
             dan tabel suhu berisi `—` semua di sertifikat terakreditasi kebaca
             seperti data yang HILANG, bukan seperti besaran yang nggak diukur.
             Dua hal itu ditindaklanjuti orang dengan cara yang berbeda. --}}
        @if (! empty($suhu))
        <div class="judul-kelompok">A) SEBARAN SUHU</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Pembacaan Indikator Enklosur (°C)</th>
                    <th>Standar &amp; Koreksi Indikator (°C)</th>
                    @foreach ($suhu['sensor'] ?? [] as $sensor)
                        <th>Sensor No. {{ $sensor['no'] ?? '' }} (°C)</th>
                    @endforeach
                    <th>U<sub>95%</sub> &plusmn; (°C)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    {{-- Kolom indikator & U95 di-`rowspan` dua baris: satu angka
                         buat blok Terukur + Koreksi, persis sel yang di-merge
                         di master. --}}
                    <td rowspan="2">{{ $angka($suhu['indikator_rata'] ?? null, $dSuhu) }}</td>
                    <td>Terukur</td>
                    @foreach ($suhu['sensor'] ?? [] as $sensor)
                        <td>{{ $angka($sensor['standar_terkoreksi'] ?? null, $dSuhu) }}</td>
                    @endforeach
                    <td rowspan="2">{{ $angka($suhu['u95'] ?? null, $dSuhu) }}</td>
                </tr>
                <tr>
                    <td>Koreksi</td>
                    @foreach ($suhu['sensor'] ?? [] as $sensor)
                        <td>{{ $angka($sensor['koreksi'] ?? null, $dSuhu) }}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>

        <div class="judul-kelompok">B) KINERJA AUTOCLAVE</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Suhu Setting (°C)</th>
                    <th>Suhu yang diukur Indikator Autoclave (°C)</th>
                    <th>Keseragaman (°C)</th>
                    <th>Kestabilan (°C)</th>
                    <th>Variasi Keseluruhan (°C)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $angka($autoclave['set_point'] ?? null, $dSuhu) }}</td>
                    <td>{{ $angka($suhu['indikator_rata'] ?? null, $dSuhu) }}</td>
                    <td>{{ $angka($suhu['keseragaman'] ?? null, $dSuhu) }}</td>
                    <td>{{ $angka($suhu['kestabilan'] ?? null, $dSuhu) }}</td>
                    <td>{{ $angka($suhu['variasi'] ?? null, $dSuhu) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Faktor cakupan dicetak PER BAGIAN. Suhu & tekanan punya derajat
             kebebasan yang beda (v_eff 4 vs 2 di sesi contoh), jadi satu
             kalimat buat dua-duanya pasti salah untuk salah satunya. --}}
        @if (($suhu['k'] ?? null) !== null)
            <div class="ket-k">
                The uncertainty is taken at a confidence level 95 % and coverage factor ( k ) =
                {{-- Master format sel `0` -> `2`, bukan `1,97`. --}}
                {{ \App\Support\Angka::id((float) $suhu['k'], 0) }}
            </div>
        @endif
        @endif

        {{-- Sebaliknya juga: sesi suhu-saja nggak nyetak blok tekanan kosong. --}}
        @if (! empty($tek))
        <div class="judul-kelompok">C) TEKANAN ({{ $tek['satuan'] ?? '' }})</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Unit Under Test</th>
                    <th>Standard Value</th>
                    <th>Correction</th>
                    <th>U<sub>95%</sub> &plusmn;</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $angka($tek['uut_setting'] ?? null, $dTekan) }}</td>
                    <td>{{ $angka($tek['standar_terkoreksi'] ?? null, $dTekan) }}</td>
                    <td>{{ $angka($tek['koreksi'] ?? null, $dTekan) }}</td>
                    <td>{{ $angka($tek['u95'] ?? null, $dTekanU95) }}</td>
                </tr>
            </tbody>
        </table>

        @if (($tek['k'] ?? null) !== null)
            <div class="ket-k">
                The uncertainty is taken at a confidence level 95 % and coverage factor ( k ) =
                {{-- Master format sel `0` -> `2`, bukan `2,09`. --}}
                {{ \App\Support\Angka::id((float) $tek['k'], 0) }}
            </div>
        @endif
        @endif
    @elseif ($timbangan)
        {{-- Desimal DIBEKUKAN di snapshot, bukan dihitung di sini: tiga
             workbook master memformat sel yang sama dengan jumlah desimal yang
             berbeda, jadi aturannya diputus sekali di
             `TimbanganProfile::ringkasanSertifikat` (dan diangkat sebagai T14),
             bukan ditebak ulang tiap cetak. Sertifikat lama yang snapshot-nya
             belum punya kuncinya jatuh ke turunan dari `desimal`. --}}
        @php($sat = $timbangan['satuan'] ?? '')
        @php($satKurung = $sat === '' ? '' : ' ('.$sat.')')
        @php($dT = $timbangan['desimal'] ?? $desimal)
        @php($dS = $timbangan['desimal_stdev'] ?? max($dT, 2))
        @php($dU = $timbangan['desimal_u95'] ?? $dT + 1)
        @php($nT = fn ($v, $d) => $v === null ? '&mdash;' : e(\App\Support\Angka::id((float) $v, $d)))

        {{-- Bagian 1 nggak dicetak sama sekali kalau nggak ada slot yang diisi.
             Kepala tabel tanpa satu pun baris kebaca seperti tabel yang gagal
             dimuat; yang benar bagiannya memang nggak ada. Adminnya sudah
             diperingatkan sebelum menyetujui (`keterulangan_kosong`). --}}
        @if (filled($timbangan['keterulangan'] ?? []))
        <div class="judul-kelompok">1. REPEATABILITY</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Capacity{{ $satKurung }}</th>
                    <th>Deviation Standard{{ $satKurung }}</th>
                    <th>Maximum Deviation With the Next Reading{{ $satKurung }}</th>
                </tr>
            </thead>
            <tbody>
                {{-- DUA baris. Master kg & substitusi nyetak baris ketiga
                     `Penuh` yang nunjuk kolom yang di blok Repeatability-nya
                     nggak ada, jadi isinya 0 · 0 · 0 — kerusakan salin-tempel,
                     bukan pengukuran. Lihat `bagianSertifikatKeterulangan`. --}}
                @foreach ($timbangan['keterulangan'] ?? [] as $rpt)
                    <tr>
                        <td class="kiri">
                            {{ $rpt['label'] ?? '' }} =
                            {{ \App\Support\Angka::nilaiStandar(
                                ($rpt['kapasitas'] ?? null) === null ? null : (float) $rpt['kapasitas'],
                                $dT,
                            ) }}
                        </td>
                        <td>{!! $nT($rpt['stdev'] ?? null, $dS) !!}</td>
                        <td>{!! $nT($rpt['maks_beda'] ?? null, $dT) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @endif

        {{-- `|m1 − m2|`, BUKAN `C = Ms − (M − z)` yang tertulis di petunjuk
             lembar kerjanya. Tanda pisah = kotaknya belum diisi; nol di situ
             kebaca "tare-nya sempurna", yang artinya beda. --}}
        <div class="baris-angka">
            <span class="nama">2. EFFECT OF TARE</span>
            = {!! $nT($timbangan['effect_of_tare'] ?? null, $dT) !!} {{ $sat }}
        </div>

        <div class="judul-kelompok">3. ACCURACY</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Nominal Standard{{ $satKurung }}</th>
                    <th>Correction{{ $satKurung }}</th>
                    <th>Uncertainty &plusmn;{{ $satKurung }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($timbangan['titik'] ?? [] as $t)
                    <tr>
                        <td>{!! $nT($t['titik_ukur'] ?? null, $dT) !!}</td>
                        <td>{!! $nT($t['koreksi'] ?? null, $dT) !!}</td>
                        <td>{!! $nT($t['u95_koreksi'] ?? null, $dU) !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">&mdash;</td></tr>
                @endforelse
            </tbody>
        </table>

        @php($ekc = $timbangan['eksentrisitas'] ?? [])
        <div class="judul-kelompok">4. LOADING INFLUENCE ON SEVERAL POSITION</div>
        <div class="ket-k">
            @if (($ekc['beban'] ?? null) !== null)
                Weight mass {!! $nT($ekc['beban'], $dT) !!} {{ $sat }} was moved to various position
            @else
                {{-- Bebannya nggak dicatat teknisi. Kalimat masternya nyebut
                     angka; nyetak `—` di tengah kalimat itu kebaca kayak data
                     yang hilang, jadi kalimatnya yang menyesuaikan. --}}
                The weight was moved to various position
            @endif
            on the pan, the difference of balance reading at each position are given in the table below:
        </div>
        <table class="data">
            <thead>
                <tr>
                    <th>Position</th>
                    @foreach ($ekc['posisi'] ?? [] as $pos)
                        <th>{{ $pos['label'] ?? '' }}</th>
                    @endforeach
                    <th>Maximum Difference</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    {{-- `Reading` di master itu SELISIH (`beban − pembacaan`),
                         bukan pembacaannya. Judul kolomnya ditiru apa adanya —
                         yang ditiru kertasnya, bukan penamaannya diperbaiki
                         sepihak. --}}
                    <td class="kiri">Reading</td>
                    @foreach ($ekc['posisi'] ?? [] as $pos)
                        <td>{!! $nT($pos['selisih'] ?? null, $dT) !!}</td>
                    @endforeach
                    <td>{!! $nT($ekc['maks_beda'] ?? null, $dT) !!}</td>
                </tr>
            </tbody>
        </table>

        @php($hys = $timbangan['histeresis'] ?? null)
        @if ($hys)
            <div class="judul-kelompok">5. HYSTERISIS</div>
            <table class="data">
                <thead>
                    <tr>
                        <th>Load{{ $satKurung }}</th>
                        <th>Hysterisis{{ $satKurung }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{!! $nT($hys['beban'] ?? null, $dT) !!}</td>
                        {{-- PERBANDINGAN ke resolusi, bukan nilai histeresisnya
                             — `IF(hys <= resolusi, "<", ">")` lalu memajang
                             resolusinya. Nilai mentahnya ada di jejak audit
                             tiap titik (`type_b_components`, sumber
                             `histeresis`). --}}
                        <td>{{ $hys['pembanding'] ?? '' }} {!! $nT($hys['batas'] ?? null, $dT) !!}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        <div class="baris-angka">
            <span class="nama">6. LIMIT OF PERFORMANCE</span>
            = &plusmn; {!! $nT($timbangan['lop'] ?? null, $dU) !!} {{ $sat }}
        </div>

        <div class="judul-kelompok">7. WEIGHING UNCERTAINTY</div>
        {{-- Dua pasang kolom berdampingan, persis masternya: sepuluh titik
             sebagai sepuluh baris nambah satu halaman sendiri di lembar yang
             udah memuat delapan bagian. Titik ganjil bikin pasangan terakhir
             setengah kosong — itu memang bentuk masternya. --}}
        @php($tt = array_values($timbangan['titik'] ?? []))
        @php($separuh = (int) ceil(count($tt) / 2))
        <table class="data">
            <thead>
                <tr>
                    <th>Nominal Standard{{ $satKurung }}</th>
                    <th>Uncertainty &plusmn;{{ $satKurung }}</th>
                    <th>Nominal Standard{{ $satKurung }}</th>
                    <th>Uncertainty &plusmn;{{ $satKurung }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse (array_slice($tt, 0, $separuh) as $i => $kiri)
                    @php($kanan = $tt[$separuh + $i] ?? null)
                    <tr>
                        <td>{!! $nT($kiri['titik_ukur'] ?? null, $dT) !!}</td>
                        <td>{!! $nT($kiri['u95_penimbangan'] ?? null, $dU) !!}</td>
                        <td>{!! $kanan === null ? '' : $nT($kanan['titik_ukur'] ?? null, $dT) !!}</td>
                        <td>{!! $kanan === null ? '' : $nT($kanan['u95_penimbangan'] ?? null, $dU) !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">&mdash;</td></tr>
                @endforelse
            </tbody>
        </table>
        @if (($timbangan['k_penimbangan'] ?? null) !== null)
            <div class="ket-k">
                {{-- Master nulis "mearured"; yang ditiru angkanya, bukan
                     salah ejaannya. Format sel `k`-nya `0` -> `2`. --}}
                That weighing uncertainty is measured at confidence level 95 % &amp; coverage factor ( K ) =
                {{ \App\Support\Angka::id((float) $timbangan['k_penimbangan'], 0) }}
            </div>
        @endif
    @else

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
        {{-- Kolom R² cuma ada di blok %T Spectrophotometer, dan cuma kalau
             definisinya udah diputus lab (`config('kalibrasi.r2_spektro')`).
             Kelompok lain & sertifikat lama yang snapshot-nya belum punya kunci
             ini balik null — kolomnya NGGAK MUNCUL, bukan muncul kosong.
             Alasan lengkapnya: docs/pertanyaan-lab-r2-spektro.md. --}}
        @php($r2 = $barisKelompok->first()['r2'] ?? null)
        {{-- Judul kolom kedua beda per master: lima alat nulis
             `Unit Under Test`, Spectrophotometer nulis `UUT` (di ketiga
             bloknya, jadi bukan sel yang kepotong). Sertifikat lama yang
             snapshot-nya belum punya kunci ini jatuh ke judul lamanya. --}}
        @php($judulUut = $snapshot['judul_uut'] ?? 'Unit Under Test')
        {{-- `U95` jadi kolom keempat (Viscometer) atau satu baris ringkas di
             bawah tabel (lima alat lain). Sertifikat lama yang snapshot-nya
             belum punya kunci ini balik `false` — bentuk cetaknya persis kayak
             waktu diterbitkan. Lihat `CalibrationProfile::u95PerTitik()`. --}}
        @php($u95Kolom = $snapshot['u95_per_titik'] ?? false)
        <table class="data">
            <thead>
                <tr>
                    <th>Standard{{ $sufiks }}</th>
                    <th>{{ $judulUut }}{{ $sufiks }}</th>
                    <th>Correction{{ $sufiks }}</th>
                    {{-- Judulnya nyebut `k` cuma kalau alat ini emang
                         MENGUNCI-nya. Viscometer nulis `U95%, k=2` persis
                         kayak masternya (`SERTIFIKAT` R21): angka
                         ketidakpastian tanpa faktor cakupannya nggak berarti
                         apa-apa, dan `k`-nya emang dikunci 2.

                         Gas Detector nggak ngunci — `k` keempat gasnya beda
                         (1,9715 / 2,0106 / 1,9744 / 1,9717), jadi judul `k=2`
                         di situ pernyataan yang SALAH. Masternya sendiri nulis
                         `U95% (±)`. Angkanya tetap dilaporkan lengkap di
                         kalimat `Coverage Factor ( k ) = …` di bawah tabel.

                         Snapshot lama belum punya `faktor_cakupan_tetap`
                         sama sekali dan jatuh ke 2.0 — sertifikat Viscometer
                         yang udah terbit nggak berubah bentuk.

                         Dipakai `array_key_exists`, BUKAN `??`: alat yang
                         `k`-nya nggak dikunci nyimpen null di kunci itu, dan
                         `??` nggak bisa mbedain "kuncinya belum ada" dari
                         "kuncinya ada, isinya null" — dua-duanya bakal jatuh
                         ke 2.0 dan Gas Detector balik nyetak `k=2`. --}}
                    @if ($u95Kolom)
                        @php($kTetap = array_key_exists('faktor_cakupan_tetap', $snapshot)
                            ? $snapshot['faktor_cakupan_tetap']
                            : 2.0)
                        <th>
                            U<sub>95%</sub>{{ $kTetap === null ? ' (±)' : ', k='.\App\Support\Angka::hasil((float) $kTetap, 0) }}{{ $sufiks }}
                        </th>
                    @endif
                    @if ($r2 !== null)
                        <th>R<sup>2</sup></th>
                    @endif
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
                    {{-- Nol di belakang koma di kolom Standard Value dibuang
                         (`100`) atau ditulis penuh (`100,0`) — beda per master,
                         dibaca dari `CalibrationProfile::nolBelakangStandarDibuang()`.
                         Sertifikat lama yang snapshot-nya belum punya kunci ini
                         jatuh ke `true`, persis perilaku lamanya. --}}
                    @php($standarRingkas = $baris['standar_ringkas'] ?? true)
                    <tr>
                        {{-- Standard Value nulis nilai NOMINAL standarnya.
                             Turbidimeter mau `1` / `100` / `1000` (nol di
                             belakang dibuang), Spectrophotometer mau `334,0` /
                             `460,0` — dua kolom lain selalu ikut desimal
                             barisnya. --}}
                        <td>{{ $standarRingkas
                            ? \App\Support\Angka::nilaiStandar($baris['standard_value'] === null ? null : (float) $baris['standard_value'], $db)
                            : \App\Support\Angka::hasil($baris['standard_value'] === null ? null : (float) $baris['standard_value'], $db, tandaNol: $tandaNol) }}</td>
                        <td>{{ \App\Support\Angka::hasil($baris['unit_under_test'] === null ? null : (float) $baris['unit_under_test'], $db, tandaNol: $tandaNol) }}</td>
                        <td>{{ \App\Support\Angka::hasil($baris['correction'] === null ? null : (float) $baris['correction'], $db, tandaNol: $tandaNol) }}</td>
                        @if ($u95Kolom)
                            {{-- Desimalnya lewat jalur `desimal_u95` yang sama
                                 kayak baris ringkas di bawah — bukan `$db` —
                                 biar alat yang nyetak U95 beda desimal dari
                                 kolom hasilnya tetap kepegang satu aturan. --}}
                            <td>{{ \App\Support\Angka::hasil($baris['u95'] === null ? null : (float) $baris['u95'], $baris['desimal_u95'] ?? $db) }}</td>
                        @endif
                        @if ($r2 !== null && $loop->first)
                            {{-- Angkanya SATU buat seluruh kelompok, jadi
                                 selnya di-`rowspan` setinggi tabelnya — persis
                                 master, yang nge-merge `SERTIFIKAT!R47:R51`
                                 jadi satu kotak. Diulang tiap baris malah
                                 kebaca kayak lima R² yang kebetulan sama.

                                 NOL desimal, bukan empat. Sel masternya nyimpen
                                 `0,9359` tapi diformat 0 desimal, jadi yang
                                 tercetak `1` — dan `1` itu juga yang keluar
                                 kalau angkanya dihitung ulang dari data blok
                                 (`RSQ` = 0,999922). Dua kandidat rumus yang
                                 belum bisa dibedain itu NYETAK ANGKA YANG SAMA,
                                 jadi kolomnya nggak lagi nunggu jawaban lab —
                                 lihat `docs/pertanyaan-lab-r2-spektro.md`. --}}
                            <td class="r2" rowspan="{{ $barisKelompok->count() }}">{{ \App\Support\Angka::id((float) $r2, 0) }}</td>
                        @endif
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
                {{-- Dilewat kalau U95 udah jadi kolom per baris — kalau
                     dua-duanya digambar, angka titik pertama kecetak dobel dan
                     kebaca kayak U95 buat seluruh tabel. --}}
                @if (! $u95Kolom)
                <tr class="u95">
                    <td colspan="2">Uncertainty U<sub>95%</sub> = &plusmn;</td>
                    <td>{{ \App\Support\Angka::hasil($barisU95['u95'] === null ? null : (float) $barisU95['u95'], $dbU95) }}{{ $satKelompok ? ' '.$satKelompok : '' }}</td>
                    {{-- Sel kosong biar baris U95 tetap selebar tabelnya waktu
                         kolom R² muncul. Tanpa ini kolom terakhir kegeser dan
                         angka U95 mendarat di bawah kepala `R²`. --}}
                    @if ($r2 !== null)
                        <td></td>
                    @endif
                </tr>
                @endif
            </tbody>
        </table>

        {{-- Kalimat faktor cakupan dicetak PER KELOMPOK: k-nya beda-beda
             (Holmium 3,18; Didynium 2,36; %T 2,01). Sertifikat lama yang
             snapshot-nya belum punya `faktor_cakupan_k` nggak nyetak baris ini
             sama sekali — bukan ngarang angkanya. --}}
        @php($k = $barisKelompok->first()['faktor_cakupan_k'] ?? null)
        {{-- Desimal `k` punya jalurnya sendiri: master spektro nyimpen
             3,182446… tapi selnya diformat 0 desimal, jadi yang tercetak `3`.
             Sertifikat lama (dan alat yang masternya belum diadu ke cetakan)
             jatuh ke perilaku lama: 2 desimal, nol di belakang dibuang. --}}
        @php($dbK = $barisKelompok->first()['desimal_k'] ?? null)
        {{-- `=` jadi `≈` kalau kelompok ini memuat `k` yang BEDA di presisi yang
             dicetak.

             Kalimat ini dicetak sekali per kelompok `remark`, memakai `k` baris
             PERTAMA. Buat Spektro itu benar — tiap kelompoknya memang satu `k`
             (Holmium 3,18; Didynium 2,36; %T 2,01). Tapi alat yang seluruh
             barisnya ber-`remark` kosong punya SATU kelompok berisi banyak `k`:
             di Tachometer & Centrifuge rentangnya 1,95997…1,96879, dan blok
             terakhir membulat ke 1,97 sementara yang tercetak 1,96.

             Yang diubah cuma satu tanda, dan sengaja: (a) mencetak rentang atau
             (b) satu kalimat per blok mengubah TATA LETAK dokumen terakreditasi
             yang sudah terbit. `≈` menghentikan dokumen mengaku presisi yang
             nggak dia punya tanpa menyentuh satu pun angka maupun susunannya.

             Dihitung dari yang TERCETAK, bukan dari nilai mentahnya: dua `k`
             yang beda di desimal keempat tapi membulat ke angka yang sama
             memang bukan ketidakcocokan yang kebaca pelanggan, dan menandainya
             `≈` cuma bikin tanda itu murah. --}}
        @php($cetakK = fn ($nilai) => $dbK === null
            ? \App\Support\Angka::idRingkas((float) $nilai, 2)
            : \App\Support\Angka::id((float) $nilai, $dbK))
        @php($kBeda = $barisKelompok
            ->pluck('faktor_cakupan_k')
            ->filter(fn ($n) => $n !== null)
            ->map($cetakK)
            ->unique()
            ->count() > 1)
        @if ($k !== null)
            <div class="ket-k">
                The Uncertainty is taken at a Confidence Level 95 % and Coverage Factor ( k ) {{ $kBeda ? '≈' : '=' }}
                {{ $cetakK($k) }}
            </div>
        @endif
    @empty
        <table class="data">
            <thead><tr><th>Standard</th><th>{{ $snapshot['judul_uut'] ?? 'Unit Under Test' }}</th><th>Correction</th></tr></thead>
            <tbody><tr><td colspan="3">—</td></tr></tbody>
        </table>
    @endforelse
    @endif

    {{-- Catatan baku dari snapshot. Kalimat "The Uncertainty is taken at a
         Confidence Level…" DILEWATI kalau tiap kelompok udah nyetak kalimatnya
         sendiri: k-nya beda per kelompok (Holmium 3,18; Didynium 2,36; %T
         2,01), jadi kalimat tingkat-sertifikat yang nyebut satu angka bukan
         cuma dobel — dia mbantah tiga baris di atasnya. --}}
    {{-- `$autoclave` ikut ngitung: tiga bagiannya udah nyetak kalimat faktor
         cakupannya sendiri, dan k suhu beda dari k tekanan. 
         `$timbangan` juga: bagian 7-nya nyetak kalimat `coverage factor ( K )`
         sendiri, dan bagian 3-nya pakai U95 of Correction yang faktor
         cakupannya lain. --}}
    @php($adaKGrup = $autoclave !== null || $timbangan !== null || collect($snapshot['hasil'] ?? [])->contains(fn ($b) => ($b['faktor_cakupan_k'] ?? null) !== null))
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
                <tr><td colspan="4">—</td></tr>
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
                        {{--
                          TINGGI ikut dicetak, bukan cuma lebar.
                          Dulu cuma lebarnya yang disetel, dan tingginya dibiarkan
                          ikut rasio gambar — kotak ini nggak memotong apa pun, jadi
                          gambar yang nggak lebar-mendatar meluber KE ATAS nimpa tabel
                          di atasnya. Gambar 800x800 di lebar 35 mm jadi setinggi 35 mm
                          di kotak yang cuma 12,17 mm: luber 22,8 mm.

                          Angkanya dihitung App\Support\UkuranTandaTangan, bukan di
                          sini — `max-height` nggak diandelin dompdf, dan aritmetika di
                          template nggak bisa diuji sendirian.
                        --}}
                        {{--
                          `$ukuranTtd` dihitung DataTampilanSertifikat dari berkas
                          gambarnya, dan semua jalur produksi lewat sana.

                          Yang dipanggil langsung `view('sertifikat.pdf', [...])`
                          dengan array rakitan sendiri (test template) nggak
                          punya berkasnya, jadi rasio gambarnya nggak ketahuan.
                          Di situ setelan admin dipakai APA ADANYA — tingginya
                          nggak ditulis sama sekali.

                          Sengaja BUKAN menjepit ke tinggi kotak: tanpa rasio,
                          "menjepit" itu menebak, dan tebakannya bikin gambar
                          gepeng plus geseran admin hilang diam-diam. Lebih baik
                          jalur non-produksi ini berperilaku persis seperti dulu
                          daripada berperilaku salah dengan percaya diri.
                        --}}
                        @php($ukuran = ($ukuranTtd ?? [])[$padat ? 'padat' : 'normal'] ?? null)
                        <div
                            class="letak-ttd"
                            style="bottom: {{ $ukuran === null ? ($posisiTtd['geser_y_mm'] ?? 0) : round($ukuran['geser_y_mm'], 2) }}mm;"
                        >
                            <img
                                src="{{ $tandaTangan }}"
                                alt="Tanda tangan"
                                style="
                                    @if ($ukuran === null)width: {{ $posisiTtd['lebar_mm'] ?? 35 }}mm;
                                    @elseif (($ukuran['lebar_mm'] ?? null) !== null)width: {{ round($ukuran['lebar_mm'], 2) }}mm;
                                    height: {{ round($ukuran['tinggi_mm'], 2) }}mm;
                                    @else height: {{ round($ukuran['tinggi_mm'], 2) }}mm;
                                    @endif
                                    left: {{ $posisiTtd['geser_x_mm'] ?? 0 }}mm;
                                "
                            >
                        </div>
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
