{{--
  Badan email sertifikat ke pelanggan (fase-2 §3d).

  ## Aturan yang TIDAK berubah dari versi polos sebelumnya

  NOL angka hasil kalibrasi di sini. Nilai koreksi, ketidakpastian, dan keputusan
  PASS/FAIL semuanya cuma hidup di PDF. Kalau badan email ikut menyebut angka, dia
  bisa beda dari PDF begitu sertifikatnya direvisi — dan penerima punya dua versi
  yang saling bertentangan, dua-duanya dari lab yang sama.

  Yang dicetak di sini cuma IDENTITAS dokumen (nomor, alat, tanggal): data yang
  tidak berubah kalau perhitungannya diperbaiki.

  ## Kenapa sekarang HTML, bukan markdown mailable

  Versi lama sengaja polos supaya lolos filter spam. Alasan itu masih dipegang —
  yang berubah cuma cara menatanya, bukan jumlah isinya: tetap satu layar, tanpa
  gambar dekoratif, tanpa kalimat promosi. Satu-satunya gambar adalah logo lab,
  dilampirkan inline (CID) supaya muncul tanpa perlu "tampilkan gambar".

  Tata letaknya pakai <table> dan gaya INLINE. Bukan gaya lama-lamaan: Outlook
  masih merender lewat mesin Word (div+flex berantakan), dan sebagian klien
  membuang blok <style>. Yang di <style> cuma penyempurnaan yang boleh gagal —
  mode gelap & lebar layar kecil.
--}}
@php
    // Diambil sekali di atas biar badan template tetap enak dibaca.
    $biru = '#12389E';        // biru royal dari logo
    $biruTua = '#0C2569';
    $teks = '#1B2333';
    $redup = '#6B7484';
    $garis = '#E3E7EF';

    $judulBerkas = match ($format) {
        'xlsx' => 'Berkas Excel terlampir',
        'tautan' => 'Dokumen tersedia lewat tautan',
        default => 'Dokumen PDF terlampir',
    };

    $baris = array_filter([
        'Nomor sertifikat' => $sertifikat->nomor,
        'Alat' => $alat?->nama_alat,
        'Merk / Tipe' => collect([$alat?->merk, $alat?->model])->filter()->implode(' / ') ?: null,
        'Nomor seri' => $alat?->serial_number,
        'Tanggal kalibrasi' => $sesi?->tanggal_kalibrasi?->translatedFormat('d F Y'),
        'Tanggal terbit' => $sertifikat->diterbitkan_pada?->translatedFormat('d F Y'),
        'Berlaku sampai' => $sertifikat->berlaku_sampai?->translatedFormat('d F Y'),
    ], fn ($v) => filled($v));
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>Sertifikat Kalibrasi {{ $sertifikat->nomor }}</title>
<style>
    /* Boleh gagal tanpa merusak apa pun — lihat catatan di atas. */
    @media (max-width: 620px) {
        .wrap { width: 100% !important; }
        .pad { padding-left: 24px !important; padding-right: 24px !important; }
        .label, .nilai { display: block !important; width: 100% !important; text-align: left !important; }
        .nilai { padding-top: 2px !important; padding-bottom: 10px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .bg { background: #10141C !important; }
        .kartu { background: #191F2B !important; }
        .t-utama { color: #EDF0F6 !important; }
        .t-redup { color: #9AA3B4 !important; }
        .garis { border-color: #2A3242 !important; }
    }
</style>
</head>
<body class="bg" style="margin:0; padding:0; background:#F4F6FA; -webkit-font-smoothing:antialiased;">

{{-- Preheader: baris abu-abu di daftar inbox, sebelum email dibuka. Tanpa ini
     Gmail menariknya dari kalimat pertama dan hasilnya potongan setengah. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
    {{ $judulBerkas }} — {{ $alat?->nama_alat ?? 'alat Anda' }}{{ $alat?->serial_number ? ' ('.$alat->serial_number.')' : '' }}.
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#F4F6FA;">
<tr><td align="center" style="padding:32px 12px;">

<table role="presentation" class="wrap" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px; max-width:600px;">

    {{-- ── Kop: logo + identitas lab ────────────────────────────────── --}}
    <tr>
        <td class="kartu pad" style="background:#FFFFFF; border-radius:14px 14px 0 0; padding:28px 36px 24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr>
                @if ($logo)
                <td width="64" style="width:64px; vertical-align:middle;">
                    <img src="{{ $message->embed($logo) }}" width="56" height="56" alt="{{ $organisasi?->nama }}"
                         style="display:block; width:56px; height:56px; border:0;">
                </td>
                @endif
                <td style="vertical-align:middle; padding-left:{{ $logo ? '14px' : '0' }};">
                    <div class="t-utama" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; font-weight:700; color:{{ $teks }}; line-height:1.35;">
                        {{ $organisasi?->nama ?? config('app.name') }}
                    </div>
                    @if ($organisasi?->no_akreditasi)
                    <div class="t-redup" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:12px; color:{{ $redup }}; padding-top:3px; letter-spacing:.02em;">
                        Laboratorium Kalibrasi Terakreditasi KAN &middot; {{ $organisasi->no_akreditasi }}
                    </div>
                    @endif
                </td>
            </tr>
            </table>
        </td>
    </tr>

    {{-- ── Pita judul ───────────────────────────────────────────────── --}}
    <tr>
        <td class="pad" style="background:{{ $biru }}; padding:22px 36px;">
            <div style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:12px; font-weight:600; color:#AFC0F0; letter-spacing:.14em; text-transform:uppercase;">
                Sertifikat Kalibrasi
            </div>
            <div style="font-family:'SF Mono',Menlo,Consolas,monospace; font-size:26px; font-weight:700; color:#FFFFFF; padding-top:6px; letter-spacing:-.01em;">
                {{ $sertifikat->nomor ?? '—' }}
            </div>
        </td>
    </tr>

    {{-- ── Isi ──────────────────────────────────────────────────────── --}}
    <tr>
        <td class="kartu pad" style="background:#FFFFFF; padding:30px 36px 8px;">
            <p class="t-utama" style="margin:0 0 14px; font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6; color:{{ $teks }};">
                @if ($pelanggan?->nama)
                    Kepada Yth. <strong>{{ $pelanggan->nama }}</strong>,
                @else
                    Dengan hormat,
                @endif
            </p>

            <p class="t-utama" style="margin:0 0 22px; font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.65; color:{{ $teks }};">
                @if ($format === 'tautan')
                    Kalibrasi alat Anda telah selesai kami kerjakan. Sertifikat resminya dapat
                    Anda lihat dan unduh melalui tautan di bawah.
                @elseif ($format === 'xlsx')
                    Kalibrasi alat Anda telah selesai kami kerjakan. Sertifikat resminya kami
                    lampirkan pada email ini dalam berkas Excel.
                @else
                    Kalibrasi alat Anda telah selesai kami kerjakan. Sertifikat resminya kami
                    lampirkan pada email ini dalam berkas PDF.
                @endif
            </p>

            {{-- Rincian identitas dokumen. --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                   style="border:1px solid {{ $garis }}; border-radius:10px;" class="garis">
                @foreach ($baris as $label => $nilai)
                <tr>
                    <td class="label t-redup garis" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:13px; color:{{ $redup }}; padding:11px 18px; width:42%; vertical-align:top; {{ $loop->last ? '' : 'border-bottom:1px solid '.$garis.';' }}">
                        {{ $label }}
                    </td>
                    <td class="nilai t-utama garis" align="right" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:13px; font-weight:600; color:{{ $teks }}; padding:11px 18px; vertical-align:top; {{ $loop->last ? '' : 'border-bottom:1px solid '.$garis.';' }}">
                        {{ $nilai }}
                    </td>
                </tr>
                @endforeach
            </table>
        </td>
    </tr>

    {{-- ── Verifikasi keaslian ──────────────────────────────────────── --}}
    @if ($sertifikat->qr_payload)
    <tr>
        <td class="kartu pad" style="background:#FFFFFF; padding:26px 36px 8px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                   style="background:#F5F7FC; border-radius:10px;">
            <tr>
                <td style="padding:20px 22px;">
                    <div class="t-utama" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:14px; font-weight:700; color:{{ $teks }};">
                        Periksa keaslian sertifikat
                    </div>
                    <div class="t-redup" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:13px; line-height:1.6; color:{{ $redup }}; padding:6px 0 16px;">
                        Setiap sertifikat kami membawa tautan verifikasi. Anda dapat memastikan
                        dokumen ini asli dan masih berlaku kapan saja, tanpa perlu menghubungi kami.
                    </div>
                    {{-- Tombol bulletproof: <a> di dalam <td> berwarna, bukan <button>. --}}
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="background:{{ $biru }}; border-radius:8px;">
                            <a href="{{ $sertifikat->qr_payload }}"
                               style="display:inline-block; padding:11px 22px; font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:14px; font-weight:600; color:#FFFFFF; text-decoration:none;">
                                Verifikasi sekarang
                            </a>
                        </td>
                    </tr>
                    </table>
                </td>
            </tr>
            </table>
        </td>
    </tr>
    @endif

    {{-- ── Penutup ──────────────────────────────────────────────────── --}}
    <tr>
        <td class="kartu pad" style="background:#FFFFFF; padding:24px 36px 30px; border-radius:0 0 14px 14px;">
            <p class="t-utama" style="margin:0 0 20px; font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.65; color:{{ $teks }};">
                Bila ada pertanyaan mengenai hasil kalibrasi ini, cukup balas email ini —
                tim kami akan menindaklanjuti.
            </p>
            <div class="t-redup" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; line-height:1.6; color:{{ $redup }};">
                Hormat kami,
            </div>
            <div class="t-utama" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:15px; font-weight:700; color:{{ $teks }}; padding-top:2px;">
                {{ $organisasi?->nama ?? config('app.name') }}
            </div>
        </td>
    </tr>

    {{-- ── Kaki ─────────────────────────────────────────────────────── --}}
    <tr>
        <td class="pad" style="padding:22px 36px 0;">
            <div class="t-redup" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:12px; line-height:1.65; color:{{ $redup }};">
                @if ($organisasi?->alamat){{ $organisasi->alamat }}<br>@endif
                @if ($organisasi?->telepon)Telp {{ $organisasi->telepon }}@endif
                @if ($organisasi?->telepon && $organisasi?->email) &middot; @endif
                @if ($organisasi?->email){{ $organisasi->email }}@endif
            </div>
            <div class="t-redup" style="font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:11px; line-height:1.6; color:#9AA3B4; padding-top:12px;">
                Email ini dikirim otomatis oleh sistem kalibrasi
                {{ $organisasi?->nama ?? config('app.name') }}.
                Hasil kalibrasi hanya berlaku untuk alat yang tercantum di atas.
            </div>
        </td>
    </tr>

</table>

</td></tr>
</table>
</body>
</html>
