@extends('layouts.publik')

@section('judul', 'Sistem Kalibrasi')

@section('isi')
    <div class="kartu">
        <h1>Sistem Kalibrasi &amp; Sertifikat Digital</h1>
        <p>
            Halaman ini cuma pintu depan. Pengelolaan data kalibrasi dilakukan lewat
            <strong>aplikasi mobile</strong> — nggak ada panel web.
        </p>
        <p class="catatan" style="margin-bottom:0">
            Kalau kamu ke sini setelah <strong>memindai QR pada sertifikat</strong>, berarti
            kode QR-nya nggak kebaca utuh. Coba pindai ulang, atau hubungi laboratorium
            @if ($organization?->telepon) di {{ $organization->telepon }} @endif
            sambil menyebutkan nomor sertifikatnya.
        </p>
    </div>
@endsection
