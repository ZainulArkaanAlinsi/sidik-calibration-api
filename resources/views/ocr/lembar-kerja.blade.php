{{--
  Lembar kerja SIAP PINDAI — digambar dari berkas geometri yang sama yang
  dipakai server buat motong sel.

  Ini yang membalik arah kerja fitur OCR. Rencana awalnya: cetak formulir, ukur
  koordinat tiap selnya dari kertas pakai penggaris, isi ke JSON, adu ke 20 foto,
  baru `terverifikasi: true`. Itu pekerjaan berhari-hari yang hasilnya tetap
  perkiraan, dan tiap revisi formulir mengulang semuanya dari nol.

  Di sini kebalikannya: koordinatnya yang jadi SUMBER, kertasnya yang mengikuti.
  Tiap kotak di halaman ini digambar persis di `x/y/w/h` yang tertulis di
  `database/ocr-templates/{kode}-v{versi}.json`, jadi geometrinya eksak menurut
  definisi — bukan hasil ukur yang bisa meleset 2 mm.

  Satuannya PIKSEL di ruang `ukuran_referensi` (1654x2339 @200dpi = A4), sama
  persis dengan ruang yang dipakai HP sesudah warp. Konversi ke mm dilakukan
  sekali di `$mm()`.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $judul }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; }

        /* Semua ditaruh absolut di ruang piksel template. */
        .lembar { position: relative; width: {{ $mm($lebar) }}mm; height: {{ $mm($tinggi) }}mm; }
        .abs { position: absolute; }

        /*
          MARKER SUDUT — kotak hitam pejal, bukan ArUco.
          Bentuk ini dipilih supaya HP nggak perlu OpenCV: deteksinya cukup
          ambang gelap + titik berat gumpalan di tiap sudut, dan itu bisa Dart
          murni. ArUco lebih kaya (punya id & orientasi), tapi keempat sudut
          halaman ini urutannya udah pasti dari posisinya sendiri.

          Kotak dalam putih bikin sudutnya nggak nyatu sama garis tabel kalau
          fotonya agak gelap.
        */
        .marker { background: #000; }
        .marker-dalam { background: #fff; }

        .sel { border: 1px solid #333; }
        .label { font-size: 8pt; }
        .judul-tabel { font-size: 9pt; font-weight: bold; }
        .kop { font-size: 8pt; color: #333; }
    </style>
</head>
<body>
<div class="lembar">
    {{-- Empat penanda sudut. Urutannya id 0..3 = kiri-atas, kanan-atas,
         kanan-bawah, kiri-bawah — sama dengan yang diharapkan server. --}}
    @foreach ($marker as $m)
        @php($u = $m['ukuran'])
        <div class="abs marker" style="
            left: {{ $mm($m['x'] - $u / 2) }}mm;
            top: {{ $mm($m['y'] - $u / 2) }}mm;
            width: {{ $mm($u) }}mm;
            height: {{ $mm($u) }}mm;">
            <div class="abs marker-dalam" style="
                left: {{ $mm($u * 0.28) }}mm;
                top: {{ $mm($u * 0.28) }}mm;
                width: {{ $mm($u * 0.44) }}mm;
                height: {{ $mm($u * 0.44) }}mm;"></div>
        </div>
    @endforeach

    {{-- QR versi formulir. Isinya `{template_id}|v{versi}` — ini yang bikin
         lembar Rev.4 nggak bisa dipakai buat template Rev.5 tanpa ketahuan. --}}
    <div class="abs" style="
        left: {{ $mm($qr['kotak']['x']) }}mm;
        top: {{ $mm($qr['kotak']['y']) }}mm;
        width: {{ $mm($qr['kotak']['w']) }}mm;
        height: {{ $mm($qr['kotak']['h']) }}mm;">
        <img src="{{ $qrGambar }}" style="width: 100%; height: 100%;" alt="QR versi lembar">
    </div>

    <div class="abs kop" style="left: {{ $mm(90) }}mm; top: {{ $mm(150) }}mm;">
        {{ $judul }}<br>
        {{ $kodeDokumen }} &middot; {{ $templateId }} v{{ $versi }}
    </div>

    @foreach ($tabel as $t)
        <div class="abs judul-tabel" style="
            left: {{ $mm($t['judul_x']) }}mm;
            top: {{ $mm($t['judul_y']) }}mm;">{{ $t['judul'] }}</div>

        {{-- Label baris (nilai standar) ditaruh di kiri barisnya. Ini juga yang
             dibaca HP sebagai JANGKAR: kalau grid kegeser satu baris, label
             yang kebaca nggak cocok sama yang diharapkan template. --}}
        @foreach ($t['label_baris'] as $l)
            <div class="abs label" style="
                left: {{ $mm($l['x']) }}mm;
                top: {{ $mm($l['y']) }}mm;
                width: {{ $mm($l['w']) }}mm;">{{ $l['teks'] }}</div>
        @endforeach

        @foreach ($t['label_kolom'] as $l)
            <div class="abs label" style="
                left: {{ $mm($l['x']) }}mm;
                top: {{ $mm($l['y']) }}mm;
                width: {{ $mm($l['w']) }}mm;
                text-align: center;">{{ $l['teks'] }}</div>
        @endforeach

        {{-- KOTAK SELNYA. Inilah yang bikin geometrinya eksak: tiap kotak
             digambar di koordinat yang sama persis dengan yang dipakai server
             buat motong crop-nya. --}}
        @foreach ($t['sel'] as $kotak)
            <div class="abs sel" style="
                left: {{ $mm($kotak['x']) }}mm;
                top: {{ $mm($kotak['y']) }}mm;
                width: {{ $mm($kotak['w']) }}mm;
                height: {{ $mm($kotak['h']) }}mm;"></div>
        @endforeach
    @endforeach
</div>
</body>
</html>
