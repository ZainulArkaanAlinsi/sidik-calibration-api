@extends('layouts.verifikasi')

@section('judul', 'Sertifikat tidak ditemukan')

@section('isi')
    <section class="kartu kartu-status kedaluwarsa" aria-labelledby="h-status">
        <span class="ubin-status" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9" />
                <path d="M9 9l6 6M15 9l-6 6" />
            </svg>
        </span>
        <div>
            <span class="label">Status sertifikat</span>
            <h2 class="status" id="h-status">Tidak ditemukan</h2>
            <p class="kecil">
                Kode pada QR ini <strong>tidak terdaftar</strong> di sistem
                {{ $organization?->nama ?? 'laboratorium' }}.
            </p>
        </div>
    </section>

    <div class="kartu catatan">
        <p>
            Sertifikat tidak ditemukan. Kemungkinan QR-nya rusak/tidak terbaca utuh, atau
            sertifikatnya memang bukan terbitan laboratorium ini. Kalau kamu yakin sertifikatnya
            asli, hubungi laboratorium
            @if ($organization?->telepon) di {{ $organization->telepon }} @endif
            @if ($organization?->email) ({{ $organization->email }}) @endif
            sambil menyebutkan nomor sertifikatnya.
        </p>
    </div>
@endsection
