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
        /*
          Ukurannya ditaruh di atribut `style` elemennya, bukan di sini.
          Blok `<style>` ini sengaja dijaga MURNI CSS: begitu ada echo Blade di
          dalamnya, validator CSS bawaan editor berhenti mem-parse dan seluruh
          aturan di bawahnya ikut dilaporin rusak — puluhan penanda merah buat
          berkas yang sebenarnya benar.

          Catatan buat yang menyunting berkas ini: kurung kurawal ganda tetap
          diproses Blade WALAUPUN ada di dalam komentar CSS seperti ini, jadi
          jangan menuliskannya di sini cuma buat contoh. Komentar yang memuat
          kurawal ganda kosong bikin `ocr:cetak-lembar` mati dengan
          `ParseError: Unclosed '(' does not match '}'` — pesannya nunjuk ke
          berkas tampilan hasil kompilasi, bukan ke baris ini.
        */
        .lembar { position: relative; }
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

        /*
          Label baris dirapatkan ke gridnya. Kolom kirinya selebar 300 px, dan
          label rata kiri berdiri sejauh 3 cm dari sel yang ditandainya —
          mengundang salah baca baris justru di tempat yang paling mahal.
        */
        .label-baris { text-align: right; padding-right: 3mm; }
        .judul-tabel { font-size: 9pt; font-weight: bold; }

        /*
          BLOK KEPALA. Isiannya diambil dari profil alat yang sama yang dipakai
          layar, jadi kertas dan aplikasi minta data yang sama. Yang ditulis
          tangan di sini nggak dipotong OCR — yang dipotong cuma sel bertanda
          di berkas geometri.
        */
        .judul-lembar { font-size: 13pt; font-weight: bold; }
        .kop { font-size: 8pt; color: #333; }
        .garis-kop { border-top: 1px solid #333; }
        /*
          Sebaris, apa pun panjang labelnya. Label yang membungkus jadi dua
          baris nabrak isian di bawahnya — jaraknya cuma satu baris.
        */
        .isian { font-size: 7pt; color: #222; white-space: nowrap; overflow: hidden; }
        .isian-garis { border-bottom: 1px solid #777; height: 0; }
    </style>
</head>
<body>
<div class="lembar" style="width: {{ $mm($lebar) }}mm; height: {{ $mm($tinggi) }}mm;">
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

    {{-- Kepala lembar: judul, baris dokumen, dan isian identitas bergaris.
         Sebelumnya bagian ini cuma dua baris kecil dan separuh atas kertas
         dibiarkan kosong — sementara gridnya sendiri berdesakan di bawah. --}}
    <div class="abs judul-lembar" style="
        left: {{ $mm($kepala['judul_x']) }}mm;
        top: {{ $mm($kepala['judul_y']) }}mm;">{{ $kepala['judul'] }}</div>

    <div class="abs kop" style="
        left: {{ $mm($kepala['judul_x']) }}mm;
        top: {{ $mm($kepala['dokumen_y']) }}mm;">{{ $kepala['dokumen'] }}</div>

    <div class="abs garis-kop" style="
        left: {{ $mm($kepala['judul_x']) }}mm;
        top: {{ $mm($kepala['garis_y']) }}mm;
        width: {{ $mm($kepala['garis_w']) }}mm;"></div>

    @foreach ($kepala['isian'] as $i)
        <div class="abs isian" style="
            left: {{ $mm($i['x']) }}mm;
            top: {{ $mm($i['y']) }}mm;
            width: {{ $mm($i['w']) }}mm;">{{ $i['teks'] }}</div>
        <div class="abs isian-garis" style="
            left: {{ $mm($i['garis_x']) }}mm;
            top: {{ $mm($i['garis_y']) }}mm;
            width: {{ $mm($i['garis_w']) }}mm;"></div>
    @endforeach

    {{-- Kotak catatan, kalau gridnya nyisain ruang di bawah. --}}
    @if ($catatan !== [])
        <div class="abs judul-tabel" style="
            left: {{ $mm($catatan['x']) }}mm;
            top: {{ $mm($catatan['y']) }}mm;">{{ $catatan['teks'] }}</div>

        @foreach ($catatan['garis'] as $g)
            <div class="abs isian-garis" style="
                left: {{ $mm($g['x']) }}mm;
                top: {{ $mm($g['y']) }}mm;
                width: {{ $mm($g['w']) }}mm;"></div>
        @endforeach
    @endif

    @foreach ($tabel as $t)
        <div class="abs judul-tabel" style="
            left: {{ $mm($t['judul_x']) }}mm;
            top: {{ $mm($t['judul_y']) }}mm;">{{ $t['judul'] }}</div>

        {{-- Label baris (nilai standar) ditaruh di kiri barisnya. Ini juga yang
             dibaca HP sebagai JANGKAR: kalau grid kegeser satu baris, label
             yang kebaca nggak cocok sama yang diharapkan template. --}}
        @foreach ($t['label_baris'] as $l)
            <div class="abs label label-baris" style="
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
