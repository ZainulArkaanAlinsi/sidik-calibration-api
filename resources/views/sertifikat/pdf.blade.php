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
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10.5px; color: #1a1a1a; margin: 0; }

        /*
          Kop surat banner. `width: 100%` + `height: auto` biar rasionya kejaga
          di lebar halaman mana pun; gambarnya 1289x225 (5,73:1).

          SENGAJA tanpa garis bawah: kop-nya sendiri udah punya lengkung biru di
          bagian bawah gambarnya, dan garis ganda di bawahnya bikin dua pembatas
          yang nempel.
        */
        .kop-gambar { margin-bottom: 12px; }
        .kop-gambar img { width: 100%; height: auto; display: block; }
        .kop { border-bottom: 3px double #333; padding-bottom: 10px; margin-bottom: 14px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td.logo { width: 84px; vertical-align: middle; }
        .kop td.logo img { width: 74px; height: auto; }
        .kop td.teks { vertical-align: middle; }
        .kop h1 { font-size: 16px; margin: 0 0 2px; }
        .kop .akr { font-size: 9.5px; color: #555; }

        .judul { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 1px; margin: 2px 0 12px; }

        table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.info td { padding: 2.5px 6px; vertical-align: top; }
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

        .judul-sub { font-size: 11px; font-weight: bold; margin: 12px 0 4px; letter-spacing: .5px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data th, table.data td { border: 1px solid #999; padding: 4px 6px; text-align: center; }
        table.data th { background: #efefef; }
        table.data td.kiri { text-align: left; }

        .catatan { font-size: 9px; font-style: italic; color: #444; margin-top: 6px; }
        .catatan div { margin-bottom: 2px; }

        .putusan { text-align: center; font-size: 13px; font-weight: bold; padding: 6px; margin: 10px 0; border: 2px solid; }
        .pass { color: #146c2e; border-color: #146c2e; }
        .fail { color: #a01919; border-color: #a01919; }

        table.ttd { width: 100%; border-collapse: collapse; margin-top: 22px; }
        table.ttd td { vertical-align: top; font-size: 10px; }
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
          Ruang tanda tangan. Tingginya DIPATOK 44px — persis `margin-top` yang dulu
          ada di `.garis`, jadi sertifikat tanpa gambar TTD tata letaknya sama persis
          kayak sebelum fitur ini ada. Dan tingginya nggak ikut gambar: kalau ikut,
          dua sertifikat dengan format resmi yang sama jadi beda tata letak cuma
          gara-gara yang satu diunggahin TTD.
        */
        .ttd .ruang-ttd { height: 44px; position: relative; }
        .ttd .ruang-ttd img { position: absolute; bottom: 0; }

        .kode-dokumen { font-size: 8.5px; color: #666; margin-top: 18px; border-top: 1px solid #ccc; padding-top: 5px; }

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
<body>
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
            <td class="lbl">Capacity/Graduation</td><td class="val">{{ $isi($header['capacity_graduation'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Env. Condition</td><td class="val">{{ $isi($header['env_condition'] ?? null) }}</td>
            <td class="lbl">Technician ID</td><td class="val">{{ $isi($header['technician_id'] ?? null) }}</td>
        </tr>
    </table>

    <div class="judul-sub">CALIBRATION REPORT</div>
    <table class="data">
        <thead>
            <tr>
                <th>Standard Value</th>
                <th>Unit Under Test</th>
                <th>Correction</th>
                <th>U95% (&plusmn;)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot['hasil'] ?? [] as $baris)
                <tr>
                    <td>{{ $angka($baris['standard_value'] ?? null) }}</td>
                    <td>{{ $angka($baris['unit_under_test'] ?? null) }}</td>
                    <td>{{ $angka($baris['correction'] ?? null) }}</td>
                    <td>{{ $angka($baris['u95'] ?? null) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="catatan">
        @foreach ($snapshot['catatan'] ?? [] as $catatan)
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
