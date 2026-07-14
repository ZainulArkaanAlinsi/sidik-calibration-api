@extends('layouts.publik')

@section('judul', 'Sertifikat tidak ditemukan')

@section('isi')
    <div class="kartu">
        <h1>Sertifikat tidak ditemukan</h1>
        <p style="margin-bottom:0">
            Kode pada QR ini <strong>tidak terdaftar</strong> di sistem
            {{ $organization?->nama ?? 'laboratorium' }}.
        </p>
        <p class="catatan">
            Kemungkinan QR-nya rusak/tidak terbaca utuh, atau sertifikatnya memang bukan
            terbitan laboratorium ini. Kalau kamu yakin sertifikatnya asli, hubungi
            laboratorium
            @if ($organization?->telepon) di {{ $organization->telepon }} @endif
            @if ($organization?->email) ({{ $organization->email }}) @endif
            sambil menyebutkan nomor sertifikatnya.
        </p>
    </div>
@endsection
