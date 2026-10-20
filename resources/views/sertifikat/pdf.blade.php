{{--
    Sertifikat kalibrasi — LAYOUT BAKU (spesifikasi poin 9).

    Isinya diambil BULAT-BULAT dari `certificates.snapshot`, bukan dari relasi
    model. Snapshot dibekukan waktu terbit, jadi cetak ulang tahun depan tetap
    keluar angka & alamat yang sama persis kayak salinan yang dipegang pelanggan.

    Dulu: empat bagian, nggak boleh ditambah (header informasi, tabel hasil +
    dua catatan baku, tabel standar, footer). K-50-15 (pemilik, 18 Okt 2026,
    `docs/permintaan-user-7.md` §52) membatalkannya: sertifikat wajib persis
    lembar `SERTIFIKAT` master alatnya. Bentuk tiap master dinyatakan profil dan
    dibekukan di snapshot (`tata_letak`, `catatan_blok`, `header_tambahan`,
    `format` & `dicetak` per baris); file ini cuma menggambarnya. Tanpa kunci
    itu — semua sertifikat lama dan alat yang profilnya belum menyatakan apa
    pun — lembarnya keluar persis bentuk lamanya
    (`App\Support\TataLetakSertifikat::BAWAAN`).

    `catatan_atas_hasil` (baris opsional di atas tabel hasil, `Spindel No.` /
    `Speed (rpm)` Viscometer, `docs/PRD-viscometer.md` §7) tetap dibaca seperti
    dulu.
--}}
@php
    $header = $snapshot['header'] ?? [];
    $footer = $snapshot['footer'] ?? [];
    $meta = $snapshot['meta'] ?? [];
    $organisasi = $meta['organization'] ?? [];
    $desimal = $snapshot['desimal'] ?? \App\Support\Angka::DESIMAL_DEFAULT;
    // Bentuk lembar (K-50-15): timpaan profil yang dibekukan di atas bentuk lama.
    $tataLetak = \App\Support\TataLetakSertifikat::untuk($snapshot ?? []);
    $blokCatatanPosisi = collect($snapshot['catatan_blok'] ?? [])->groupBy('posisi');
    $nilaiKepala = [...($snapshot['header_tambahan'] ?? []), ...$header];

    $tgl = fn (?string $iso): string => $iso
        ? \Illuminate\Support\Carbon::parse($iso)->translatedFormat('d F Y')
        : '—';
    $angka = fn ($nilai): string => \App\Support\TataLetakSertifikat::angka(\App\Support\Angka::id($nilai === null ? null : (float) $nilai, $desimal), $tataLetak);
    $isi = fn (?string $nilai): string => filled($nilai) ? $nilai : '—';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    @if ($web ?? false)
        {{-- Halaman hasil pindai QR: dibuka dari HP orang luar dan memuat nama
             pelanggan utuh, jadi (1) layarnya harus kebaca di HP dan (2) tidak
             boleh terindeks mesin pencari. Dompdf tidak pernah kena blok ini. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
    @endif
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
        /* D6 §38: "Revisi ke-n, menggantikan <nomor>" tepat di bawah judul —
           cuma dicetak untuk sertifikat revisi, jadi lembar lama tidak bergeser. */
        .catatan-revisi { text-align: center; font-size: 9.5px; font-weight: bold; margin: -8px 0 10px; }

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

        /* K-50-15: blok catatan khas alat, baris satuan di bawah judul kolom,
           sel gabung, dan "End of Certificate". Hanya dipakai lembar yang
           snapshot-nya menyatakannya — lembar lain tidak punya elemennya. */
        .blok-catatan { font-size: 10px; margin: 5px 0; }
        .blok-catatan .judul-blok { font-weight: bold; }
        table.catatan-tabel { border-collapse: collapse; }
        table.catatan-tabel td { padding: 0 6px 0 0; vertical-align: top; }
        body.padat .blok-catatan { font-size: 8.5px; margin: 3px 0; }
        table.data tr.satuan th { font-weight: normal; }
        /* Kepala dua kelompok master (K-50-15) punya SEPULUH baris, grid lama
           delapan, dan baris satuan menambah satu baris lagi di tiap tabel
           hasil. Tanpa dirapatkan, lembar terpadat (Spectrophotometer 24
           titik, sisa 2 px di bentuk lama) meluap ke halaman dua. Barisnya
           dirapatkan sampai tinggi kepala baru tidak melebihi grid lama.
           Cuma kena kepala bentuk master — lembar bentuk lama tidak berubah. */
        table.info.kepala-master td { padding-top: 1px; padding-bottom: 1px; line-height: 1.3; }
        body.padat table.info.kepala-master td { padding-top: 0; padding-bottom: 0; line-height: 1.05; }
        body.padat table.data tr.satuan th { padding-top: 0; padding-bottom: 0; line-height: 1; font-size: 7.5px; }
        /* Mode padat bentuk master (K-50-15): blok catatan khas alat (Note
           Kerataan Jangka Sorong `153!B64:F65`, Paralelism, Units Used) butuh
           ruang di lembar yang sudah padat. Jarak antar bagian dirapatkan —
           ukuran huruf & tinggi ruang tanda tangan TIDAK disentuh (dipatok
           bareng `UkuranTandaTangan`). Lembar bentuk lama tidak berubah. */
        body.padat.bentuk-master { line-height: 1.3; }
        body.padat.bentuk-master .judul { margin: 0 0 3px; }
        body.padat.bentuk-master .kop { margin-bottom: 4px; }
        body.padat.bentuk-master .kop-gambar { margin-bottom: 3px; }
        body.padat.bentuk-master table.info { margin-bottom: 2px; }
        body.padat.bentuk-master .judul-sub { margin: 4px 0 2px; }
        body.padat.bentuk-master .judul-kelompok { margin: 3px 0 1px; }
        body.padat.bentuk-master .ket-k { margin: 0 0 2px; }
        body.padat.bentuk-master .catatan { margin-top: 2px; line-height: 1.2; }
        body.padat.bentuk-master .catatan div { margin-bottom: 1px; }
        body.padat.bentuk-master .blok-catatan { margin: 1px 0; line-height: 1.15; }
        body.padat.bentuk-master table.ttd { margin-top: 3px; }
        body.padat.bentuk-master .kode-dokumen { margin-top: 2px; }
        table.data td.gabung { vertical-align: middle; }
        .akhir-sertifikat { text-align: center; font-size: 10px; margin-top: 8px; }

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

          ## Kenapa versi PADAT-nya berhenti di 28px

          Percobaan pertama menaikkannya ke 44px, dan itu bikin sertifikat
          **Visible Spectrofotometer** jadi DUA HALAMAN — dia lembar terpadat di
          sistem (24 titik ketidakpastian) dan mode padat nggak punya jaring
          pengaman lagi di bawahnya.

          Disapu 24 / 28 / 32 / 36 / 44px atas seluruh 24 sertifikat bawaan:
          **28px masih muat, 32px sudah meluap.** Jadi 28px bukan angka yang
          dipilih, itu batasnya.
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

        /*
          Mode LONGGAR — kebalikan `padat`, dan lahir dari keluhan pemilik proyek
          15 Sep 2026 atas `CAL/2026/09/0007`: sertifikat berisi sedikit cuma
          memakai ~60 % kertas, hurufnya kecil, dan bawahnya kosong lebar.

          `App\Services\SertifikatSatuHalaman` mencoba `longgar-2` dulu, lalu
          `longgar-1`, lalu normal, lalu padat — yang dipakai tingkat TERBESAR
          yang masih satu halaman. Jadi sertifikat pendek memenuhi kertas dan
          sertifikat panjang tetap muat; tidak ada satu ukuran yang dipaksakan.

          `.cadangan-footer` ikut dirender di kedua tingkat ini. Dia yang menjamin
          footer TIDAK mepet ke tepi bawah: tingkat longgar cuma lolos kalau isi +
          cadangan itu masih satu halaman, jadi selalu tersisa ruang di bawah kode
          dokumen. Tinggi `.ruang-ttd` SENGAJA tidak disentuh — dia dipatok bareng
          `App\Support\UkuranTandaTangan`.
        */
        .cadangan-footer { height: 1.3cm; }

        /*
          Kotak UJI, cuma dirender waktu `SertifikatSatuHalaman` mencari tingkat
          kerapatan — tidak pernah ikut di PDF yang diterbitkan.

          Gunanya menolak tingkat yang muat "pas": lembar bersisa nol piksel
          jatuh ke halaman dua begitu ada satu baris tambahan (nama pelanggan
          lebih panjang, satu titik ukur lagi), sementara headernya tetap
          mencetak `Page : 1 of 1`. Dengan kotak ini tingkat yang dipilih wajib
          menyisakan ruang sebesar dia.
        */
        .cadangan-uji { height: 6px; }

        body.longgar-1 { font-size: 12.5px; line-height: 1.45; }
        body.longgar-1 .kop-gambar { margin-bottom: 14px; }
        body.longgar-1 .judul { font-size: 18px; margin: 4px 0 16px; }
        body.longgar-1 table.info { margin-bottom: 14px; }
        body.longgar-1 table.info td { padding: 4px 7px; }
        /* Label dilebarkan & tidak dilipat: di huruf besar "Certificate Number"
           dan "Calibration Location" terbelah dua baris di kolom 17 %. */
        body.longgar-1 table.info td.lbl,
        body.longgar-2 table.info td.lbl { width: 21%; white-space: nowrap; }
        body.longgar-1 table.info td.val,
        body.longgar-2 table.info td.val { width: 29%; }
        body.longgar-1 .judul-sub { font-size: 13px; margin: 14px 0 7px; }
        body.longgar-1 .judul-kelompok { font-size: 12px; margin: 10px 0 4px; }
        body.longgar-1 table.data { font-size: 12.5px; }
        body.longgar-1 table.data th,
        body.longgar-1 table.data td { padding: 5px 9px; }
        body.longgar-1 .ket-k { font-size: 10px; margin: 4px 0 8px; }
        body.longgar-1 .catatan { font-size: 11px; margin-top: 10px; }
        body.longgar-1 table.ttd { margin-top: 18px; }
        body.longgar-1 table.ttd td { font-size: 12.5px; }
        body.longgar-1 .kode-dokumen { font-size: 10.5px; margin-top: 14px; padding-top: 6px; }

        /*
          Tingkat RAPAT — di antara normal dan `padat`. Tanpa ini sertifikat yang
          meluap sedikit di ukuran normal (Height Gauge, sepuluh titik) langsung
          jatuh ke `padat`, yang dirancang untuk 24 baris Spectrophotometer: tabel
          8px, dan lembarnya cuma memakai ~60 % kertas. Itu persis bentuk
          `CAL/2026/09/0007` yang dikeluhkan.
        */
        body.rapat-1 { font-size: 10.5px; line-height: 1.35; }
        body.rapat-1 .kop-gambar { margin-bottom: 7px; }
        body.rapat-1 .judul { font-size: 15px; margin: 0 0 8px; }
        body.rapat-1 table.info { margin-bottom: 6px; }
        body.rapat-1 table.info td { padding: 1.5px 6px; }
        body.rapat-1 .judul-sub { font-size: 11px; margin: 7px 0 4px; }
        body.rapat-1 table.data { font-size: 10.5px; }
        body.rapat-1 table.data th,
        body.rapat-1 table.data td { padding: 2px 7px; }
        body.rapat-1 .catatan { margin-top: 5px; }
        body.rapat-1 table.ttd { margin-top: 8px; }
        body.rapat-1 .kode-dokumen { margin-top: 5px; }

        body.rapat-2 { font-size: 10px; line-height: 1.3; }
        body.rapat-2 .kop-gambar { margin-bottom: 5px; }
        body.rapat-2 .judul { font-size: 14px; margin: 0 0 6px; }
        body.rapat-2 table.info { margin-bottom: 4px; font-size: 9.5px; }
        body.rapat-2 table.info td { padding: 1px 6px; line-height: 1.25; }
        body.rapat-2 .judul-sub { font-size: 10.5px; margin: 6px 0 3px; }
        body.rapat-2 .judul-kelompok { font-size: 10px; margin: 6px 0 2px; }
        body.rapat-2 table.data { font-size: 9.5px; }
        body.rapat-2 table.data th,
        body.rapat-2 table.data td { padding: 1px 6px; line-height: 1.2; }
        body.rapat-2 .ket-k { font-size: 8px; margin: 1px 0 4px; }
        body.rapat-2 .catatan { font-size: 9px; margin-top: 4px; line-height: 1.35; }
        body.rapat-2 table.ttd { margin-top: 6px; }
        body.rapat-2 table.ttd td { font-size: 10.5px; }
        body.rapat-2 .kode-dokumen { margin-top: 4px; font-size: 8.5px; }
        body.rapat-1 .cadangan-footer,
        body.rapat-2 .cadangan-footer { height: 0.8cm; }

        body.longgar-2 { font-size: 13.5px; line-height: 1.5; }
        body.longgar-2 .kop-gambar { margin-bottom: 18px; }
        body.longgar-2 .judul { font-size: 20px; margin: 6px 0 20px; }
        body.longgar-2 table.info { margin-bottom: 18px; }
        body.longgar-2 table.info td { padding: 5px 8px; }
        body.longgar-2 .judul-sub { font-size: 14px; margin: 18px 0 9px; }
        body.longgar-2 .judul-kelompok { font-size: 13px; margin: 12px 0 5px; }
        body.longgar-2 table.data { font-size: 13.5px; }
        body.longgar-2 table.data th,
        body.longgar-2 table.data td { padding: 7px 10px; }
        body.longgar-2 .ket-k { font-size: 11px; margin: 5px 0 10px; }
        body.longgar-2 .catatan { font-size: 12px; margin-top: 12px; }
        body.longgar-2 table.ttd { margin-top: 24px; }
        body.longgar-2 table.ttd td { font-size: 13.5px; }
        body.longgar-2 .kode-dokumen { font-size: 11px; margin-top: 18px; padding-top: 7px; }

        @if ($web ?? false)
        /*
          Mode web — cuma dipakai halaman hasil scan QR. Isinya NGGAK disentuh
          sama sekali di sini: yang ditambah cuma bingkai layar (latar abu,
          lembar putih di tengah) biar kebaca di HP. Dompdf nggak pernah kena
          blok ini, jadi PDF-nya nggak mungkin ikut berubah.
        */
        body {
            background: #ece8dc;
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

        /*
          Bilah status di atas lembar — gayanya mengikuti artboard
          Web_Verifikasi (kertas hangat, hijau "lulus", tombol biru). Isinya
          TIDAK berubah: teks & dua tautan unduh sama persis dengan sebelumnya.
        */
        .bilah {
            max-width: 820px;
            margin: 0 auto 14px;
            background: #f5f1e7;
            color: #1a1f26;
            border: 1px solid color-mix(in srgb, #125739 45%, #d9d2c0);
            border-left: 6px solid #125739;
            border-radius: 6px;
            padding: 16px 18px;
        }
        .bilah .cap { font-size: 18px; font-weight: bold; color: #125739; }
        .bilah .ket { font-size: 13px; color: #4a5059; margin-top: 4px; }
        .bilah .aksi { margin-top: 14px; }
        .bilah a {
            display: inline-block;
            background: #1d4292;
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            font-size: 13px;
            padding: 10px 16px;
            border-radius: 4px;
            margin: 0 8px 8px 0;
        }
        .bilah a + a {
            background: transparent;
            color: #1d4292;
            border: 1px solid #1d4292;
            padding: 9px 15px;
        }
        /* Digantikan revisi (§38.2) — warna "awas", bukan "gagal": dokumennya
           tidak palsu, cuma sudah ada yang lebih baru. */
        .bilah.digantikan {
            background: #fbf3e0;
            border-color: color-mix(in srgb, #8a5a00 45%, #d9d2c0);
            border-left-color: #8a5a00;
        }
        .bilah.digantikan .cap { color: #8a5a00; }

        /* Kartu ringkas di atas lembar. */
        .ringkas {
            max-width: 820px;
            margin: 0 auto 14px;
            background: #fff;
            border: 1px solid #d9d2c0;
            border-radius: 6px;
            padding: 6px 18px;
        }
        .ringkas-baris {
            display: flex;
            gap: 16px;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid #eee8da;
            font-size: 14px;
        }
        .ringkas-baris:last-child { border-bottom: 0; }
        .ringkas-label { color: #4a5059; flex: 0 0 38%; }
        .ringkas-nilai { font-weight: 600; text-align: right; overflow-wrap: anywhere; }
        .ringkas-nilai.mono { font-family: "JetBrains Mono", "IBM Plex Mono", ui-monospace, monospace; font-weight: 500; }
        .ringkas-catatan { display: block; font-size: 12px; font-weight: 400; color: #6b7280; }
        summary .ringkas-catatan { display: inline; }

        details.lembar-lengkap { max-width: 820px; margin: 0 auto; }
        details.lembar-lengkap > summary {
            cursor: pointer;
            font-weight: bold;
            color: #1d4292;
            padding: 12px 4px;
            font-size: 14px;
        }
        details.lembar-lengkap > summary:focus-visible { outline: 2px solid #1d4292; outline-offset: 2px; }

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
{{-- Tebakan `> 12 baris` cuma berlaku kalau pemanggil TIDAK mengirim tingkat
     `longgar`. Kalau dikirim, `SertifikatSatuHalaman` sudah mengukur hasil
     render sungguhan tingkat demi tingkat — tebakan itu justru membuat
     Centrifuge 13 baris dipaksa ke tabel 8px padahal muat di ukuran rapat. --}}
@php($padat = ! ($web ?? false) && (($paksaPadat ?? false) || (! isset($longgar) && count(\App\Support\TataLetakSertifikat::barisDicetak($snapshot['hasil'] ?? [])) > 12) || ($snapshot['timbangan'] ?? null) !== null))
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
{{-- Tingkat longgar (0/1/2) dari App\Services\SertifikatSatuHalaman. Tidak
     berlaku bersama `padat`, dan tidak untuk Autoklaf/Timbangan yang punya
     pemadatannya sendiri. Lihat `.cadangan-footer`. --}}
@php($longgar = ($web ?? false) || $padat || $lembarAutoclave || $lembarTimbangan ? 0 : max(-2, min(2, (int) ($longgar ?? 0))))
<body class="{{ $padat ? 'padat' : '' }}{{ isset($snapshot['tata_letak']) ? ' bentuk-master' : '' }}{{ $lembarAutoclave ? ' lembar-autoclave' : '' }}{{ $lembarTimbangan ? ' lembar-timbangan' : '' }}{{ $longgar > 0 ? ' longgar-'.$longgar : '' }}{{ $longgar < 0 ? ' rapat-'.abs($longgar) : '' }}">
@if ($web ?? false)
    @if ($ditarik ?? false)
        {{-- K-50-03: sertifikat ditarik ke lembar kerja untuk dikoreksi. Lembar
             lama tampil sebagai RIWAYAT; penggantinya (`<nomor>-R<n>`) ditunjuk
             kalau sudah terbit. Tanpa alasan penarikan (internal). --}}
        <div class="bilah digantikan" role="status">
            <div class="cap">Sertifikat ini ditarik</div>
            <div class="ket">
                @if (($pengganti ?? null) !== null)
                    Digantikan oleh: <strong>{{ $pengganti->nomor }}</strong>@if ($pengganti->diterbitkan_pada), terbit {{ $pengganti->diterbitkan_pada->locale('id')->translatedFormat('j F Y') }}@endif.
                @else
                    Sertifikat pengganti sedang disiapkan. Hubungi laboratorium untuk informasi lebih lanjut.
                @endif
                Lembar di bawah ini riwayat, bukan dokumen yang berlaku.
            </div>
            @if (($pengganti ?? null) !== null)
                <div class="aksi">
                    <a href="{{ route('verify', $pengganti->qr_token) }}">Buka yang berlaku</a>
                </div>
            @endif
        </div>
    @elseif (($pengganti ?? null) !== null)
        {{-- §38.2: kertas lama dipindai sesudah direvisi. Lembarnya tetap
             tampil sebagai RIWAYAT; yang berlaku ditunjuk dengan jelas. Tanpa
             alasan revisi apa pun (D4). --}}
        <div class="bilah digantikan" role="status">
            <div class="cap">Sertifikat ini sudah direvisi</div>
            <div class="ket">
                Yang berlaku: <strong>{{ $pengganti->nomor }}</strong>@if ($pengganti->diterbitkan_pada), terbit {{ $pengganti->diterbitkan_pada->locale('id')->translatedFormat('j F Y') }}@endif.
                @if ($pengganti->status === \App\Models\Certificate::STATUS_DIBATALKAN)
                    Revisi itu kemudian <strong>dibatalkan</strong>.
                @endif
                Lembar di bawah ini riwayat, bukan dokumen yang berlaku.
            </div>
            <div class="aksi">
                <a href="{{ route('verify', $pengganti->qr_token) }}">Buka yang berlaku</a>
                <a href="{{ route('verify.download', $sertifikat->qr_token) }}">Unduh PDF lama</a>
            </div>
        </div>
    @else
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
    @endif

    {{-- Kartu ringkas: yang dicocokkan orang dengan kertasnya. Nama pemilik
         DISAMARKAN sebagian (App\Support\SamarkanNama) — halaman ini sering
         di-screenshot & diteruskan. Nama utuh tetap ada di lembar lengkap. --}}
    <section class="ringkas" aria-label="Ringkasan sertifikat">
        <div class="ringkas-baris">
            <span class="ringkas-label">Nomor sertifikat</span>
            <span class="ringkas-nilai mono">{{ $header['certificate_number'] ?? $sertifikat->nomor }}</span>
        </div>
        @if (filled($keputusan ?? null))
            <div class="ringkas-baris">
                <span class="ringkas-label">Hasil kalibrasi</span>
                <span class="ringkas-nilai">{{ $keputusan }}</span>
            </div>
        @endif
        <div class="ringkas-baris">
            <span class="ringkas-label">Alat</span>
            <span class="ringkas-nilai">{{ $isi(collect([$header['equipment_name'] ?? null, trim(($header['manufacturer'] ?? '').' '.($header['model_type'] ?? ''))])->filter()->implode(' · ')) }}</span>
        </div>
        <div class="ringkas-baris">
            <span class="ringkas-label">Nomor seri</span>
            <span class="ringkas-nilai mono">{{ $isi($header['serial_number'] ?? null) }}</span>
        </div>
        <div class="ringkas-baris">
            <span class="ringkas-label">Pemilik alat</span>
            <span class="ringkas-nilai">{{ $isi($pemilikSamar ?? null) }} <span class="ringkas-catatan">Disamarkan sebagian</span></span>
        </div>
        <div class="ringkas-baris">
            <span class="ringkas-label">Tanggal kalibrasi</span>
            <span class="ringkas-nilai">{{ $tgl($header['calibration_date'] ?? null) }}</span>
        </div>
        <div class="ringkas-baris">
            <span class="ringkas-label">Diterbitkan</span>
            <span class="ringkas-nilai">{{ $tgl($footer['issuance_date'] ?? null) }}</span>
        </div>
        @if ($sertifikat->berlaku_sampai)
            <div class="ringkas-baris">
                <span class="ringkas-label">Berlaku sampai</span>
                <span class="ringkas-nilai">{{ $tgl($sertifikat->berlaku_sampai->toDateString()) }}</span>
            </div>
        @endif
    </section>

    <details class="lembar-lengkap">
        <summary>Tampilkan lembar lengkap <span class="ringkas-catatan">— memuat nama pelanggan utuh</span></summary>
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

      `tata_letak.kop` = false (K-50-15) melewati kop sama sekali: lembar
      SERTIFIKAT master tidak memuat kop gambar, dan kertasnya bisa saja sudah
      berkop cetak. Bawaannya `true` — bentuk lama.
    --}}
    @if (! $tataLetak['kop'])
    @elseif (! empty($kop))
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
                    {{--
                      Baris akreditasi cuma dicetak kalau nomornya ADA di
                      snapshot. Yang mengosongkannya `CertificateSnapshotBuilder`,
                      buat alat yang di luar lampiran LK-285-IDN.

                      Bawaan `?? 'KAN'` dan `?? '—'` yang dulu ada di sini
                      DICABUT: keduanya membuat sertifikat alat di luar lingkup
                      tetap mencetak "Terakreditasi KAN &middot; No. —", yaitu
                      klaim yang sama cuma tanpa nomornya. Alamatnya tetap
                      dicetak — dia bukan klaim akreditasi.
                    --}}
                    <div class="akr">
                        @if (! empty($organisasi['no_akreditasi']))
                            Terakreditasi {{ $organisasi['standar_akreditasi'] ?? 'KAN' }}
                            &middot; No. {{ $organisasi['no_akreditasi'] }}
                            @if (! empty($organisasi['alamat'])) <br>{{ $organisasi['alamat'] }} @endif
                        @elseif (! empty($organisasi['alamat']))
                            {{ $organisasi['alamat'] }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>
    @endif

    @if (filled($tataLetak['judul']))
        <div class="judul">{{ $tataLetak['judul'] }}</div>
    @endif
    @if (filled($header['catatan_revisi'] ?? null))
        <div class="catatan-revisi">{{ $header['catatan_revisi'] }}</div>
    @endif

    {{-- Header informasi. Dulu 16 field dengan urutan yang "dikunci
         spesifikasi"; K-50-15 (18 Okt 2026, `docs/permintaan-user-7.md` §52)
         membatalkan kunci itu — urutan & label grid ikut lembar `SERTIFIKAT`
         master alatnya (`tata_letak.kepala`: baris `[kiri, kanan]`, sel kosong
         boleh). Bawaannya grid lama 8 × 2 (`TataLetakSertifikat::BAWAAN`).
         Sel ber-`\n` (Capacity/Graduation bertingkat, Env. Condition
         bertumpuk) dicetak `pre-line`. --}}
    <table class="info{{ $tataLetak['kepala'] !== \App\Support\TataLetakSertifikat::BAWAAN['kepala'] ? ' kepala-master' : '' }}">
        @php($rentangKepala = \App\Support\TataLetakSertifikat::rentangKepala($tataLetak['kepala']))
        @foreach ($tataLetak['kepala'] as $iKepala => $barisKepala)
            <tr>
                @foreach ($barisKepala as $jKepala => $selKepala)
                    @php($rsKepala = $rentangKepala[$iKepala][$jKepala] ?? 1)
                    @php($atrRs = $rsKepala > 1 ? ' rowspan="'.$rsKepala.'"' : '')
                    @if ($rsKepala === 0)
                    @elseif ($selKepala === null)
                        <td class="lbl"></td><td class="val"></td>
                    @else
                        @php($kunciKepala = $selKepala['kunci'])
                        @php($teksKepala = in_array($kunciKepala, \App\Support\TataLetakSertifikat::KUNCI_TANGGAL, true) ? $tgl($nilaiKepala[$kunciKepala] ?? null) : $isi($nilaiKepala[$kunciKepala] ?? null))
                        <td class="lbl"{!! $atrRs !!}>{{ $selKepala['label'] }}</td><td class="val{{ $kunciKepala === 'equipment_name' ? ' alat' : '' }}{{ $kunciKepala === 'capacity_graduation' || str_contains($teksKepala, "\n") ? ' tumpuk' : '' }}"{!! $atrRs !!}>{{ $teksKepala }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </table>

    @include('sertifikat._blok-catatan', ['blok' => $blokCatatanPosisi->get(\App\Support\TataLetakSertifikat::POSISI_SESUDAH_KEPALA, [])])

    <div class="judul-sub">{{ $tataLetak['judul_laporan'] }}</div>
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
    @include('sertifikat._blok-catatan', ['blok' => $blokCatatanPosisi->get(\App\Support\TataLetakSertifikat::POSISI_ATAS_HASIL, [])])
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
         nggak berubah sama sekali.

         K-50-15, bentuk master saja (`pisah_per_satuan`; snapshot terbit tanpa
         `tata_letak` tetap per `remark`): kelompoknya `remark` DAN satuan PER
         BARIS — satuan baris
         pertama dulu dipakai untuk seluruh tabel, sehingga baris mS/cm
         Conductivity tercetak di bawah kepala `(µS/cm)` dan titik %RH
         Thermohygro di bawah `(°C)`. Master mencetak tabel sendiri per satuan,
         dengan U95 & `k` sendiri. `pisah_per_remark` = false menyatukan remark
         ke satu tabel (Chlorine, Sieve: remark jadi KOLOM). Baris yang
         disembunyikan master (`dicetak: false`) dilewati. --}}
    @php($pisahRemark = (bool) $tataLetak['pisah_per_remark'])
    @php($kelompok = collect(\App\Support\TataLetakSertifikat::barisDicetak($snapshot['hasil'] ?? []))->groupBy(fn ($b) => \App\Support\TataLetakSertifikat::kunciKelompok($b, $pisahRemark, (bool) $tataLetak['pisah_per_satuan'])))

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

    {{-- Flowmeter Ultrasonic: spesifikasi pipa & pemasangan sensor.

         Blok ini SATU-SATUNYA bagian sertifikat alat ini yang nggak punya
         padanan yang jalan di master. Sertifikat Flowrate punya LABEL-nya
         (`B19` Material of pipe, `B20` Outside Diameter, `B21` Thickness of
         Pipe, `B22` Methode UFM Clamp On — hasil revisi 20 Mei 2026) tapi sel
         isinya KOSONG tanpa rumus, dan sertifikat Totalizer bahkan nggak punya
         labelnya. Jadi kertas `SIDIK-FM-CAL-0538` memungut keempatnya,
         sertifikat nyediain tempatnya, dan nggak ada yang nyambungin.

         Dicetak DI ATAS tabel hasil, ngikutin posisi `B19:B22` master.

         Diameter DALAM & luas penampang ikut dicetak walau kertasnya nggak
         minta: keduanya yang beneran masuk hitungan (`u_A` dan `ci` komponen
         cross sectional area). Nyetak diameter LUAR doang bikin pembaca
         sertifikat nggak punya jalan meriksa ulang angkanya.

         Dua puluh enam alat lain balik `null` di sini — termasuk seluruh
         sertifikat yang udah terbit sebelum kunci ini ada — dan blok ini nggak
         dicetak sama sekali. --}}
    @php($flowmeter = $snapshot['flowmeter'] ?? null)

    {{-- Tekanan (Pressure / Vacuum / Differential): satu titik DUA arah.
         Tabel master `SERTIFIKAT!E20:V34` mencetak setelan, penunjukan standar
         UP & DOWN, koreksi UP & DOWN, dan histeresis ketiga pengulangan — tabel
         empat kolom bawaan cuma punya tempat untuk satu arah. Dibaca dari
         `TekananProfile::tabelSertifikatTekanan`, dibekukan ke snapshot waktu
         terbit. Alat lain balik `null` dan blok ini tidak dicetak. --}}
    @php($tekanan = $snapshot['tekanan'] ?? null)

    {{-- Enclosure (Oven/Furnace/Bath/Inkubator/Refrigerator): DUA bagian,
         persis sheet `SERTIFIKAT` kedua master enclosure — A) Sebaran Suhu per
         posisi termokopel (Terukur + Koreksi terhadap Indikator) dan B) Kinerja
         Enklosur (Keseragaman/Kestabilan/Variasi). Tabel bawaan `Standard |
         UUT | Correction` SALAH buat alat ini: kolom `koreksi` barisnya
         menyimpan Keseragaman, dan tercetak sebagai `Correction` dia kebaca
         "tambahkan 0,8 °C ke pembacaan alat". Disusun
         `CertificateSnapshotBuilder::enclosure()`; alat lain balik `null`. --}}
    @php($enclosure = $snapshot['enclosure'] ?? null)

    @if ($flowmeter)
        @php($mm = fn ($v) => $v === null ? '—' : \App\Support\TataLetakSertifikat::angka(\App\Support\Angka::id((float) $v, 2), $tataLetak).' mm')
        <div class="judul-sub">PIPE SPECIFICATION &amp; SENSOR MOUNTING</div>
        <table class="data">
            <tbody>
                <tr>
                    <td class="kiri">Material of pipe</td>
                    <td class="kiri">{{ $isi($flowmeter['material_pipa'] ?? null) }}</td>
                    <td class="kiri">Outside diameter of pipe</td>
                    <td class="kiri">{{ $mm($flowmeter['diameter_luar_mm'] ?? null) }}</td>
                </tr>
                <tr>
                    <td class="kiri">Fluid</td>
                    <td class="kiri">{{ $isi($flowmeter['jenis_fluida'] ?? null) }}</td>
                    <td class="kiri">Thickness of pipe</td>
                    <td class="kiri">{{ $mm($flowmeter['ketebalan_mm'] ?? null) }}</td>
                </tr>
                <tr>
                    {{-- Master menamainya "Methode UFM Clamp On"; kertasnya
                         "Path Configuration (Sensor Mounting Methode)". Yang
                         dicetak nama kertasnya, karena itu yang dilihat teknisi
                         waktu mencentangnya. --}}
                    <td class="kiri">Path configuration</td>
                    <td class="kiri">
                        {{ $flowmeter['path_configuration'] ? $flowmeter['path_configuration'].'-Methode' : '—' }}
                    </td>
                    <td class="kiri">Inside diameter of pipe</td>
                    <td class="kiri">{{ $mm($flowmeter['diameter_dalam_mm'] ?? null) }}</td>
                </tr>
                {{-- Liner cuma dicetak kalau pipanya MEMANG berpelapis. Baris
                     berisi `—` di sertifikat terakreditasi kebaca seperti data
                     yang HILANG, bukan seperti pipa yang emang nggak berliner —
                     dan dua hal itu ditindaklanjuti orang dengan cara berbeda. --}}
                @if (filled($flowmeter['liner_material'] ?? null))
                    <tr>
                        <td class="kiri">Liner material</td>
                        <td class="kiri">{{ $isi($flowmeter['liner_material']) }}</td>
                        <td class="kiri">Liner thickness</td>
                        <td class="kiri">{{ $mm($flowmeter['liner_ketebalan_mm'] ?? null) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

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
        @php($angka = fn ($v, $d) => $v === null ? '—' : \App\Support\TataLetakSertifikat::angka(\App\Support\Angka::id((float) $v, $d), $tataLetak))

        {{-- Bagian A & B cuma dicetak kalau suhunya MEMANG diukur. Sesi
             tekanan-saja itu skenario sah (lihat `AutoclaveCalculator::hitung`),
             dan tabel suhu berisi `—` semua di sertifikat terakreditasi kebaca
             seperti data yang HILANG, bukan seperti besaran yang nggak diukur.
             Dua hal itu ditindaklanjuti orang dengan cara yang berbeda. --}}
        @if (! empty($suhu))
        <div class="judul-kelompok">A) SEBARAN SUHU</div>
        {{-- Bentuk master (K-50-15): satu kepala `Sensor No. (oC)` selebar
             kolom sensor, nomor sensor di baris bawahnya (`155!F23`, `F24`,
             `K24`, `P24`). Snapshot terbit tanpa `tata_letak` tetap bentuk
             lama (`Sensor No. 1 (°C)` per kolom). --}}
        @php($autoMaster = isset($snapshot['tata_letak']))
        <table class="data">
            <thead>
                @if ($autoMaster)
                <tr>
                    <th rowspan="2">Pembacaan Indikator Enklosur (°C)</th>
                    <th rowspan="2">Standar &amp; Koreksi Indikator (°C)</th>
                    <th colspan="{{ max(1, count($suhu['sensor'] ?? [])) }}">Sensor No. (°C)</th>
                    <th rowspan="2">U<sub>95%</sub> &plusmn; (°C)</th>
                </tr>
                <tr>
                    @foreach ($suhu['sensor'] ?? [] as $sensor)
                        <th>{{ $sensor['no'] ?? '' }}</th>
                    @endforeach
                </tr>
                @else
                <tr>
                    <th>Pembacaan Indikator Enklosur (°C)</th>
                    <th>Standar &amp; Koreksi Indikator (°C)</th>
                    @foreach ($suhu['sensor'] ?? [] as $sensor)
                        <th>Sensor No. {{ $sensor['no'] ?? '' }} (°C)</th>
                    @endforeach
                    <th>U<sub>95%</sub> &plusmn; (°C)</th>
                </tr>
                @endif
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
        {{-- Bentuk master (K-50-15): judul `C) Tekanan` (`155!B35`) dan satuan
             di baris sendiri di bawah kepala kolom (`B38:P38`). --}}
        @if (isset($snapshot['tata_letak']))
        <div class="judul-kelompok">C) Tekanan</div>
        @else
        <div class="judul-kelompok">C) TEKANAN ({{ $tek['satuan'] ?? '' }})</div>
        @endif
        <table class="data">
            <thead>
                <tr>
                    <th>Unit Under Test</th>
                    <th>Standard Value</th>
                    <th>Correction</th>
                    <th>U<sub>95%</sub> &plusmn;</th>
                </tr>
                @if (isset($snapshot['tata_letak']) && filled($tek['satuan'] ?? null))
                <tr class="satuan">
                    @for ($j = 0; $j < 4; $j++)
                        <th>{{ $tek['satuan'] }}</th>
                    @endfor
                </tr>
                @endif
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

        {{-- JALAN C (Bagian 3 paket keputusan, 17 Sep 2026): set point di bawah
             titik kalibrasi terendah standar dicetak APA ADANYA, tapi tanpa
             klaim akreditasi dan dengan sebabnya tertulis. Baris SUHU tidak
             tersentuh — standarnya terkalibrasi di rentang yang dipakai.

             Catatan ini hilang sendiri begitu tabel kalibrator tekanan
             diperluas sampai titik yang benar-benar dipakai (jalan A). --}}
        @if (($tek['ekstrapolasi_tekanan'] ?? null) !== null)
            <div class="ket-k">
                Titik tekanan ini di bawah titik kalibrasi terendah standar
                ({{ \App\Support\TataLetakSertifikat::angka(\App\Support\Angka::id((float) ($tek['ekstrapolasi_tekanan']['tabel_min_bar'] ?? 0), 2), $tataLetak) }} bar),
                sehingga koreksi &amp; ketidakpastian standarnya diambil dari titik terdekat dan
                ketidakpastian ekstrapolasinya ikut dihitung.
                <strong>Baris tekanan ini di luar lingkup akreditasi</strong>; baris suhu tidak terpengaruh.
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
        @php($nT = fn ($v, $d) => $v === null ? '&mdash;' : e(\App\Support\TataLetakSertifikat::angka(\App\Support\Angka::id((float) $v, $d), $tataLetak)))

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
                            {{ \App\Support\TataLetakSertifikat::angka(\App\Support\Angka::nilaiStandar(
                                ($rpt['kapasitas'] ?? null) === null ? null : (float) $rpt['kapasitas'],
                                $dT,
                            ), $tataLetak) }}
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
    @elseif ($tekanan)
        @php($sat = $tekanan['satuan'] ?? '')
        @php($satKurung = $sat === '' ? '' : ' ('.$sat.')')
        @php($dP = (int) ($tekanan['desimal'] ?? $desimal))
        @php($dPU = (int) ($tekanan['desimal_u95'] ?? $dP + 1))
        @php($nP = fn ($v, $d) => $v === null ? '&mdash;' : e(\App\Support\TataLetakSertifikat::angka(\App\Support\Angka::id((float) $v, $d), $tataLetak)))
        {{-- Bentuk master (K-50-15, snapshot ber-`tata_letak`): kepala `UUT`
             (`181!E19`, sama di 190/191/192), ejaan master `Hysterisis`
             (`R19`), baris satuan sendiri (`E20:R20`), dan baris U95 terpisah
             dari kalimat `k` (`E35` / `E36`). Format kolom setelan bisa beda
             dari kolom lain (`E22` `0` di 181/190, `0.0` di 191, `0.000` di
             192): profil menyatakannya lewat `tekanan.format_setelan`
             (`TekananProfile::tabelSertifikatTekanan`), bawaannya desimal
             tabel seperti sebelumnya. Snapshot terbit tanpa `tata_letak` tetap
             bentuk lama. --}}
        @php($tekMaster = isset($snapshot['tata_letak']))
        @php($fSetelan = $tekMaster ? ($tekanan['format_setelan'] ?? null) : null)
        @php($nSetelan = fn ($v) => $fSetelan === null || $v === null ? $nP($v, $dP) : e(\App\Support\TataLetakSertifikat::angka(\App\Support\TataLetakSertifikat::formatAngka((float) $v, $fSetelan), $tataLetak)))
        <table class="data">
            <thead>
                @if ($tekMaster)
                <tr>
                    <th>UUT</th>
                    <th colspan="2">Standard Indication</th>
                    <th colspan="2">Correction</th>
                    <th colspan="3">Hysterisis</th>
                </tr>
                @if ($sat !== '')
                <tr class="satuan">
                    <th>{{ $sat }}</th>
                    <th colspan="2">{{ $sat }}</th>
                    <th colspan="2">{{ $sat }}</th>
                    <th colspan="3">{{ $sat }}</th>
                </tr>
                @endif
                <tr>
                    <th></th>
                @else
                <tr>
                    <th rowspan="2">UUT Setting{{ $satKurung }}</th>
                    <th colspan="2">Standard Indication{{ $satKurung }}</th>
                    <th colspan="2">Correction{{ $satKurung }}</th>
                    <th colspan="3">Hysteresis{{ $satKurung }}</th>
                </tr>
                <tr>
                @endif
                    <th>Up</th>
                    <th>Down</th>
                    <th>Up</th>
                    <th>Down</th>
                    <th>1</th>
                    <th>2</th>
                    <th>3</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tekanan['baris'] ?? [] as $b)
                    <tr>
                        <td>{!! $nSetelan($b['setelan'] ?? null) !!}</td>
                        <td>{!! $nP($b['standar_up'] ?? null, $dP) !!}</td>
                        <td>{!! $nP($b['standar_down'] ?? null, $dP) !!}</td>
                        <td>{!! $nP($b['koreksi_up'] ?? null, $dP) !!}</td>
                        <td>{!! $nP($b['koreksi_down'] ?? null, $dP) !!}</td>
                        <td>{!! $nP($b['histeresis'][0] ?? null, $dP) !!}</td>
                        <td>{!! $nP($b['histeresis'][1] ?? null, $dP) !!}</td>
                        <td>{!! $nP($b['histeresis'][2] ?? null, $dP) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if (($tekanan['u95'] ?? null) !== null)
            @if ($tekMaster)
                <div class="ket-k">Uncertainty U95% = &plusmn; {!! $nP($tekanan['u95'], $dPU) !!} {{ $sat }}</div>
            @endif
            <div class="ket-k">
                @unless ($tekMaster)
                Uncertainty U95% = &plusmn; {!! $nP($tekanan['u95'], $dPU) !!} {{ $sat }}.
                @endunless
                The uncertainty is taken at a confidence level 95 % and coverage factor ( k ) =
                {{-- Master: `ROUND(k;1)` berformat General — 1,98 tercetak `2`,
                     4,30 tercetak `4,3`. Bukan `2,0`. --}}
                @php($kP = round((float) ($tekanan['k_cetak'] ?? 2), 1))
                {{ \App\Support\TataLetakSertifikat::angka(\App\Support\Angka::id($kP, floor($kP) == $kP ? 0 : 1), $tataLetak) }}
            </div>
        @endif
    @elseif ($enclosure)
        {{-- Desimal PER KOLOM ikut format sel `SERTIFIKAT` varian masternya
             (`EnclosureProfileBase::FORMAT_CETAK`), dibekukan di snapshot;
             `null` = format General. Pembulatannya tampilan Excel
             (`Angka::tampilanExcel`). --}}
        {{-- Kepala kolom sensor — master: "(No. 1 Sensor Acuan)". Acuan =
             termokopel bernomor terkecil yang terisi DI SET POINT ITU, jadi
             kalau termokopel itu kosong di satu set point acuannya bergeser, dan
             kepala kolom yang tetap menyebut "No. 1" jadi pernyataan yang salah.

             Master mencetak 9 kolom posisi. Grid yang lebih lebar dipecah per
             12 kolom dengan susunan yang sama — satu tabel 20+ kolom meluber
             keluar kertas dan angka di kanannya terpotong tanpa jejak.

             Sengaja direktif php SEBARIS, bukan blok php-endphp: regex blok
             Blade menelan semua direktif sebaris di atasnya sampai penutup
             blok pertama yang ditemuinya — termasuk yang tertulis di komentar. --}}
        @php($fE = is_array($enclosure['desimal'] ?? null) ? $enclosure['desimal'] : [])
        @php($nolE = (bool) ($enclosure['tanda_nol'] ?? true))
        @php($dE = fn ($kolom) => array_key_exists($kolom, $fE) ? $fE[$kolom] : 1)
        @php($nE = fn ($v, $kolom) => $v === null ? '—' : \App\Support\TataLetakSertifikat::angka(($dE($kolom) === null ? \App\Support\Angka::nilaiStandar((float) $v, 10) : \App\Support\Angka::hasil((float) $v, (int) $dE($kolom), tandaNol: $nolE)), $tataLetak))
        @php($spE = array_values($enclosure['set_point'] ?? []))
        @php($posisiE = array_values($enclosure['posisi'] ?? []))
        @php($acuanE = collect($spE)->pluck('posisi_acuan')->filter(fn ($p) => $p !== null)->unique()->values())
        @php($teksAcuanE = $acuanE->count() <= 1 ? 'No. '.($acuanE->first() ?? 1).' Sensor Acuan' : 'Sensor Acuan per set point: No. '.collect($spE)->map(fn ($s) => $s['posisi_acuan'] ?? '—')->implode(' / '))
        @php($blokPosisiE = $posisiE === [] ? [[]] : array_chunk($posisiE, 12, true))
        @php($cetakKE = fn ($n) => $nE($n, 'k'))
        @php($kE = collect($spE)->pluck('faktor_cakupan_k')->filter(fn ($n) => $n !== null)->values())

        <div class="judul-kelompok">A) SEBARAN SUHU</div>
        @foreach ($blokPosisiE as $blokPosisi)
        <table class="data">
            <thead>
                <tr>
                    <th rowspan="2">Pembacaan Indikator Enklosur (°C)</th>
                    <th rowspan="2">Standar dan Koreksi Indikator (°C)</th>
                    <th colspan="{{ max(1, count($blokPosisi)) }}">Sensor No. ({{ $teksAcuanE }})</th>
                    <th rowspan="2">U<sub>95%</sub> &plusmn; (°C)</th>
                </tr>
                <tr>
                    @forelse ($blokPosisi as $pos)
                        <th>{{ $pos['posisi'] }}</th>
                    @empty
                        <th>—</th>
                    @endforelse
                </tr>
            </thead>
            <tbody>
                @foreach ($spE as $sp)
                    <tr>
                        <td rowspan="2">{{ $nE($sp['indikator'] ?? null, 'indikator') }}</td>
                        <td>Terukur (°C)</td>
                        @forelse ($blokPosisi as $idx => $pos)
                            <td>{{ $nE($sp['sensor'][$idx]['terukur'] ?? null, 'terukur') }}</td>
                        @empty
                            <td>—</td>
                        @endforelse
                        <td rowspan="2">{{ $nE($sp['u95'] ?? null, 'u95') }}</td>
                    </tr>
                    <tr>
                        <td>Koreksi (°C)</td>
                        @forelse ($blokPosisi as $idx => $pos)
                            <td>{{ $nE($sp['sensor'][$idx]['koreksi'] ?? null, 'koreksi') }}</td>
                        @empty
                            <td>—</td>
                        @endforelse
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endforeach

        {{-- Satu kalimat, seperti master (`SERTIFIKAT!P37` = `k` set point
             pertama). `≈` kalau `k` set point lain beda di presisi yang
             dicetak — aturan yang sama dengan tabel bawaan di bawah. --}}
        @if ($kE->isNotEmpty())
            <div class="ket-k">
                The uncertainty is taken at a confidence level 95 % and coverage factor ( k )
                {{ $kE->map($cetakKE)->unique()->count() > 1 ? '≈' : '=' }}
                {{ $cetakKE($kE->first()) }}
            </div>
        @endif

        <div class="judul-kelompok">B) KINERJA ENKLOSUR</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Suhu Setting (°C)</th>
                    <th>Suhu yang diukur Indikator Enklosur (°C)</th>
                    <th>Keseragaman (°C)</th>
                    <th>Kestabilan (°C)</th>
                    <th>Variasi Keseluruhan (°C)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($spE as $sp)
                    <tr>
                        <td>{{ $nE($sp['set_point'] ?? null, 'setting') }}</td>
                        <td>{{ $nE($sp['indikator'] ?? null, 'indikator_kinerja') }}</td>
                        <td>{{ $nE($sp['keseragaman'] ?? null, 'kinerja') }}</td>
                        <td>{{ $nE($sp['kestabilan'] ?? null, 'kinerja') }}</td>
                        <td>{{ $nE($sp['variasi_keseluruhan'] ?? null, 'kinerja') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else

    @forelse ($kelompok as $barisKelompok)
        @php($barisKelompok = $barisKelompok->values())
        @php($pertama = $barisKelompok->first())
        @php($judulKelompok = $pisahRemark ? (string) ($pertama['remark'] ?? '') : '')
        @if (filled($judulKelompok))
            <div class="judul-kelompok">{{ $judulKelompok }}</div>
        @endif

        {{-- Satuan KELOMPOK dari baris pertama. Bentuk master (K-50-15,
             `pisah_per_satuan`) menjamin satu kelompok satu satuan; bentuk lama
             dikelompokkan per `remark` saja, persis waktu terbit. Lembar master nulisnya di KEPALA kolom (`Standard (nm)`)
             atau, kalau `satuan_baris_sendiri`, di baris sendiri di bawah judul
             kolom (`D17` "mm"). Alat bersatuan seragam ngirim null di baris.
             Bentuk lama: kepalanya tanpa satuan (tetap begitu untuk snapshot
             lama). Bentuk master (`satuan_baris_sendiri`, K-50-15): baris
             satuannya jatuh ke satuan ALAT (`snapshot.satuan_cetak`, bukan satuan
             pembacaan mentah: Piston menimbang `g`, masternya `ml`) — master
             selalu menulisnya (`177!C21` `NTU`, `178!C20` `cP`). --}}
        @php($satKelompok = $pertama['satuan'] ?? ($tataLetak['satuan_baris_sendiri'] ? ($snapshot['satuan_cetak'] ?? null) : null))
        {{-- Kolom R² cuma ada di blok %T Spectrophotometer, dan cuma kalau
             definisinya udah diputus lab (`config('kalibrasi.r2_spektro')`).
             Kelompok lain & sertifikat lama yang snapshot-nya belum punya kunci
             ini balik null — kolomnya NGGAK MUNCUL, bukan muncul kosong.
             Alasan lengkapnya: docs/pertanyaan-lab-r2-spektro.md. --}}
        @php($r2 = $pertama['r2'] ?? null)
        {{-- Kolom yang dicetak & urutannya. Tanpa `tata_letak.kolom_hasil`
             susunannya bentuk lama: Standard · UUT · Correction · (sebaran,
             kalau `judul_sebaran`) · (U95 per titik, kalau `u95_per_titik` —
             Viscometer) · (R²). Dengan timpaan profil, urutan & judul ikut
             master (Flowmeter: UUT dulu; Chlorine/Sieve: kolom Remark;
             Anak Timbangan: tanpa Correction). Judul bawaan tiap kolom tetap
             dari snapshot (`judul_standar`, `judul_uut`, `judul_koreksi`,
             `judul_sebaran`) — sertifikat lama jatuh ke judul lamanya. --}}
        @php($kolomHasil = \App\Support\TataLetakSertifikat::kolomHasil($snapshot, $tataLetak, $r2 !== null))
        @php($susunanLama = $tataLetak['kolom_hasil'] === null)
        @php($u95Kolom = collect($kolomHasil)->contains(fn ($k) => $k['kolom'] === 'u95'))
        @php($barisSatuan = (bool) $tataLetak['satuan_baris_sendiri'])
        {{-- Judul kolom U95 bawaan nyebut `k` cuma kalau alat ini emang
             MENGUNCI-nya. Viscometer nulis `U95%, k=2` persis kayak masternya
             (`SERTIFIKAT` R21): angka ketidakpastian tanpa faktor cakupannya
             nggak berarti apa-apa, dan `k`-nya emang dikunci 2.

             Gas Detector nggak ngunci — `k` keempat gasnya beda (1,9715 /
             2,0106 / 1,9744 / 1,9717), jadi judul `k=2` di situ pernyataan yang
             SALAH. Masternya sendiri nulis `U95% (±)`.

             Snapshot lama belum punya `faktor_cakupan_tetap` sama sekali dan
             jatuh ke 2.0 — sertifikat Viscometer yang udah terbit nggak berubah
             bentuk. Dipakai `array_key_exists`, BUKAN `??`: alat yang `k`-nya
             nggak dikunci nyimpen null di kunci itu, dan `??` nggak bisa
             mbedain "kuncinya belum ada" dari "kuncinya ada, isinya null". --}}
        @php($kTetap = array_key_exists('faktor_cakupan_tetap', $snapshot) ? $snapshot['faktor_cakupan_tetap'] : 2.0)
        @php($judulTh = function (array $k) use ($snapshot, $tataLetak, $satKelompok, $barisSatuan, $kTetap): string {
            $sat = \App\Support\TataLetakSertifikat::satuanKolom($k, $satKelompok);
            $sufiks = $barisSatuan || $sat === null ? '' : ' ('.e($sat).')';

            if ($k['kolom'] === 'u95' && $k['judul'] === null) {
                return $tataLetak['judul_kolom_u95'] !== null
                    ? e($tataLetak['judul_kolom_u95']).$sufiks
                    : 'U<sub>95%</sub>'.e($kTetap === null ? ' (±)' : ', k='.\App\Support\Angka::hasil((float) $kTetap, 0)).$sufiks;
            }

            if ($k['kolom'] === 'r2' && $k['judul'] === null) {
                return 'R<sup>2</sup>';
            }

            return e(\App\Support\TataLetakSertifikat::judulKolom($k, $snapshot)).$sufiks;
        })
        {{-- Teks tiap sel lewat SATU aturan (`TataLetakSertifikat::teksSel`),
             yang juga dipakai Excel buat desimalnya:

             - desimal PER BARIS dulu (`desimal`), baru tingkat sertifikat —
               Turbidimeter 0,01 / 0,1 / 1 per rentang nggak bisa diwakili satu
               angka;
             - `format` per kolom dari format sel master (K-50-15) menang di
               kolom yang disebutnya — `0.0` tetap `5,0`, `General` presisi
               tampilan;
             - koreksi negatif yang membulat ke nol: `-0,0` atau `0,0` menurut
               `tanda_nol` master masing-masing;
             - Standard Value nol di belakang dibuang (`standar_ringkas`) kecuali
               master menulisnya penuh (Spektro `334,0`);
             - `-` di koreksi titik nol Proving Ring (`koreksi_kosong`) dan di
               sebaran titik nol — bukan `0,00`, yang kebaca "diukur dan
               hasilnya nol sempurna". --}}
        @php($teksBaris = $barisKelompok->map(fn ($b) => collect($kolomHasil)->mapWithKeys(fn ($k) => [$k['kolom'] => isset($k['pecah']) ? \App\Support\TataLetakSertifikat::teksPecah($b, $k, $snapshot) : \App\Support\TataLetakSertifikat::teksSel($b, $k['kolom'], $snapshot, (int) $desimal, $k)])->all())->all())
        {{-- Kolom berpecah (Timer `H | M | S | S(0,001)`, `183!E18:W18`):
             judulnya satu sel selebar pecahannya, judul pecahan di baris
             satuan. --}}
        @php($adaPecah = collect($kolomHasil)->contains(fn ($k) => isset($k['pecah'])))
        {{-- Sel `gabung` = satu kotak setinggi sel-sel bersebelahan yang angkanya
             sama, niru sel merge di master: R² satu kotak per kelompok
             (`SERTIFIKAT!R47:R51`), U95 per rentang. Tanpa `middle` angkanya
             nempel di baris pertama dan kotaknya kebaca kayak nilai baris
             pertama doang. --}}
        @php($rentang = \App\Support\TataLetakSertifikat::rentangGabung($teksBaris, $kolomHasil, $barisKelompok->all()))
        <table class="data">
            <thead>
                <tr>
                    @foreach ($kolomHasil as $kol)
                        <th{!! isset($kol['pecah']) ? ' colspan="'.count($kol['pecah']).'"' : '' !!}>{!! $judulTh($kol) !!}</th>
                    @endforeach
                </tr>
                {{-- Baris satuan (master `D17` "mm") cuma digambar kalau ada
                     satuan yang ditulis — sesi tanpa satuan tidak dapat baris
                     kepala kosong. --}}
                @php($satuanKolom = collect($kolomHasil)->map(fn ($kol) => \App\Support\TataLetakSertifikat::satuanKolom($kol, $satKelompok)))
                @if ($adaPecah || ($barisSatuan && $satuanKolom->filter()->isNotEmpty()))
                    <tr class="satuan">
                        @foreach ($kolomHasil as $iKol => $kol)
                            @if (isset($kol['pecah']))
                                @foreach ($kol['pecah'] as $p)
                                    <th>{{ $p['judul'] }}</th>
                                @endforeach
                            @else
                                <th>{{ $barisSatuan ? ($satuanKolom[$iKol] ?? '') : '' }}</th>
                            @endif
                        @endforeach
                    </tr>
                @endif
            </thead>
            <tbody>
                @foreach ($barisKelompok as $i => $baris)
                    <tr>
                        @foreach ($kolomHasil as $kol)
                            @if (isset($kol['pecah']))
                                @foreach ($teksBaris[$i][$kol['kolom']] as $teksPecah)
                                    <td>{{ $teksPecah }}</td>
                                @endforeach
                            @elseif (! $kol['gabung'])
                                <td>{{ $teksBaris[$i][$kol['kolom']] }}</td>
                            @elseif (($rentang[$kol['kolom']][$i] ?? 0) > 0)
                                <td class="{{ $kol['kolom'] === 'r2' ? 'r2' : 'gabung' }}" rowspan="{{ $rentang[$kol['kolom']][$i] }}">{{ $teksBaris[$i][$kol['kolom']] }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach

                {{-- U95 satu kelompok — diambil dari baris pertama, BUKAN
                     dihitung ulang. Tiap titik sekelompok emang bawa angka yang
                     sama; kalau suatu saat beda, yang salah datanya, dan
                     ngerata-ratain di sini cuma nyembunyiin itu. Desimalnya
                     jalur `desimal_u95` / `format.u95` (master spektro nyetak
                     `0,50 %T` sementara UUT di blok yang sama tiga desimal).

                     Dilewat kalau U95 udah jadi kolom per baris — kalau
                     dua-duanya digambar, angka titik pertama kecetak dobel dan
                     kebaca kayak U95 buat seluruh tabel. --}}
                @if (! $u95Kolom)
                <tr class="u95">
                    <td colspan="{{ \App\Support\TataLetakSertifikat::posisiU95($kolomHasil) }}">{!! $tataLetak['label_u95'] === null ? 'Uncertainty U<sub>95%</sub> = &plusmn;' : e($tataLetak['label_u95']) !!}</td>
                    @php($spekU95 = collect($kolomHasil)->firstWhere('kolom', 'u95'))
                    @php($satU95 = $spekU95 === null ? $satKelompok : \App\Support\TataLetakSertifikat::satuanKolom($spekU95, $satKelompok))
                    <td>{{ \App\Support\TataLetakSertifikat::teksSel($pertama, 'u95', $snapshot, (int) $desimal, $spekU95) }}{{ $satU95 ? ' '.$satU95 : '' }}</td>
                    {{-- Sel kosong biar baris U95 tetap selebar tabelnya waktu
                         kolom R² muncul. Tanpa ini kolom terakhir kegeser dan
                         angka U95 mendarat di bawah kepala `R²`. --}}
                    @for ($j = 0; $j < \App\Support\TataLetakSertifikat::kosongSesudahU95($kolomHasil, $susunanLama); $j++)
                        <td></td>
                    @endfor
                </tr>
                @endif
            </tbody>
        </table>

        {{-- Kalimat faktor cakupan dicetak PER KELOMPOK: k-nya beda-beda
             (Holmium 3,18; Didynium 2,36; %T 2,01). Sertifikat lama yang
             snapshot-nya belum punya `faktor_cakupan_k` nggak nyetak baris ini
             sama sekali — bukan ngarang angkanya.

             Angka `k` lewat `TataLetakSertifikat::teksK`: `format.k` dari sel
             master (K-50-15, 40 lembar `0` → `2`), lalu `desimal_k` (spektro
             0 desimal), lalu perilaku lama (2 desimal, nol di belakang dibuang).

             `=` jadi `≈` kalau kelompok ini memuat `k` yang BEDA di presisi yang
             dicetak. Alat yang seluruh barisnya ber-`remark` kosong punya SATU
             kelompok berisi banyak `k` — di Tachometer & Centrifuge rentangnya
             1,95997…1,96879, dan blok terakhir membulat ke 1,97 sementara yang
             tercetak 1,96. `≈` menghentikan dokumen mengaku presisi yang nggak
             dia punya tanpa menyentuh satu pun angka maupun susunannya.
             Dihitung dari yang TERCETAK, bukan dari nilai mentahnya.

             Bunyi kalimatnya bisa ditimpa profil (`tata_letak.kalimat_k`, mis.
             persis master "… Convidence Level …"); `=`/`≈` dan angkanya tetap
             ditambahkan di sini. --}}
        {{-- `k_tunggal` (K-50-15): master yang mencetak SATU sel `k` walau `k`
             antar titik beda (Timer `183!V29` = `k` satu budget) — satu angka,
             `=`, baris dipilih profil. Bawaan `null` = perilaku di atas. --}}
        @php($barisDenganK = $tataLetak['k_tunggal'] === null ? $pertama : \App\Support\TataLetakSertifikat::barisK($barisKelompok->all(), $tataLetak['k_tunggal']))
        @php($k = $barisDenganK['faktor_cakupan_k'] ?? null)
        @php($kBeda = $tataLetak['k_tunggal'] === null && $barisKelompok
            ->filter(fn ($b) => ($b['faktor_cakupan_k'] ?? null) !== null)
            ->map(fn ($b) => \App\Support\TataLetakSertifikat::teksK($b, (float) $b['faktor_cakupan_k'], $tataLetak))
            ->unique()
            ->count() > 1)
        @if ($k !== null)
            <div class="ket-k">
                {{ $tataLetak['kalimat_k'] ?? 'The Uncertainty is taken at a Confidence Level 95 % and Coverage Factor ( k )' }} {{ $kBeda ? '≈' : '=' }}
                {{ \App\Support\TataLetakSertifikat::teksK($barisDenganK, (float) $k, $tataLetak) }}
            </div>
        @endif
    @empty
        <table class="data">
            <thead><tr><th>{{ $snapshot['judul_standar'] ?? 'Standard' }}</th><th>{{ $snapshot['judul_uut'] ?? 'Unit Under Test' }}</th><th>Correction</th></tr></thead>
            <tbody><tr><td colspan="3">—</td></tr></tbody>
        </table>
    @endforelse
    @endif

    @include('sertifikat._blok-catatan', ['blok' => $blokCatatanPosisi->get(\App\Support\TataLetakSertifikat::POSISI_BAWAH_HASIL, [])])

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
    {{-- `$enclosure` juga: blok A-nya sudah mencetak kalimat `k`-nya sendiri
         (1,96 di sesi contoh), dan kalimat baku `k = 2` di bawahnya akan
         membantahnya. --}}
    @php($adaKGrup = $autoclave !== null || $timbangan !== null || $enclosure !== null || collect($snapshot['hasil'] ?? [])->contains(fn ($b) => ($b['faktor_cakupan_k'] ?? null) !== null))
    <div class="catatan">
        @foreach ($snapshot['catatan'] ?? [] as $catatan)
            @continue ($adaKGrup && str_contains($catatan, 'Coverage Factor'))
            <div>{{ $catatan }}</div>
        @endforeach
    </div>

    @include('sertifikat._blok-catatan', ['blok' => $blokCatatanPosisi->get(\App\Support\TataLetakSertifikat::POSISI_SESUDAH_CATATAN, [])])

    {{-- Keputusan PASS/FAIL bukan bagian struktur baku — cuma muncul kalau lab
         sengaja nyalain lewat pengaturan organisasi. --}}
    @if (! empty($keputusan))
        <div class="putusan {{ strtolower($keputusan) }}">KEPUTUSAN: {{ $keputusan }}</div>
    @endif

    {{-- Judul & kolom ikut master (K-50-15): `Standard used :`, `8. STANDARD
         USED`; Timbangan & Anak Timbangan punya kolom `Nominal Mass`
         (`isiTambahanStandarDigunakan`). Bawaannya bentuk lama empat kolom. --}}
    @php($kolomStandar = $tataLetak['kolom_standar_digunakan'])
    <div class="judul-sub">{{ $tataLetak['judul_standar_digunakan'] }}</div>
    <table class="data">
        <thead>
            <tr>
                @foreach ($kolomStandar as $kolom)
                    <th>{{ $kolom['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot['standar_digunakan'] ?? [] as $standar)
                <tr>
                    @foreach ($kolomStandar as $kolom)
                        <td{!! $kolom['kunci'] === 'name' ? ' class="kiri"' : '' !!}>{{ $isi($standar[$kolom['kunci']] ?? null) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($kolomStandar) }}">—</td></tr>
            @endforelse
        </tbody>
    </table>

    @include('sertifikat._blok-catatan', ['blok' => $blokCatatanPosisi->get(\App\Support\TataLetakSertifikat::POSISI_SESUDAH_STANDAR, [])])

    <table class="ttd">
        {{--
          Issuance Date + tanda tangan di KIRI, QR (kalau dicetak) di kanan.
          Urutan sel inilah yang nentuin posisinya — dompdf nggak dukung
          `float`/flexbox di konteks tabel, jadi jangan coba dibalik pakai CSS.
          Sel kosong di tengah yang mendorong keduanya ke pinggir masing-masing.
        --}}
        <tr>
            <td style="width: 38%;">
                <div>{{ $tataLetak['label_tanggal_terbit'] }} {{ $tgl($footer['issuance_date'] ?? null) }}</div>

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

    @include('sertifikat._blok-catatan', ['blok' => $blokCatatanPosisi->get(\App\Support\TataLetakSertifikat::POSISI_SESUDAH_TTD, [])])

    {{-- "End of Certificate" — 13 master mencetaknya sesudah tanda tangan
         (`139!G68`, `155!E66`, `173!F53`, …), dengan strip persis masternya.
         `null` = tidak dicetak (bentuk lama). --}}
    @if (filled($tataLetak['akhir_sertifikat']))
        <div class="akhir-sertifikat">{{ $tataLetak['akhir_sertifikat'] }}</div>
    @endif

    <div class="kode-dokumen">{{ $isi($footer['kode_dokumen'] ?? null) }}</div>
    @if ($longgar !== 0)
        <div class="cadangan-footer"></div>
    @endif
    {{-- Kotak uji ruang sisa — cuma waktu tingkat kerapatan sedang dicari,
         tidak pernah ikut ke PDF terbit. Lihat `.cadangan-uji`. --}}
    @if ($ujiCadangan ?? false)
        <div class="cadangan-uji"></div>
    @endif
@if ($web ?? false)
    </div>
    </details>
@endif
</body>
</html>
