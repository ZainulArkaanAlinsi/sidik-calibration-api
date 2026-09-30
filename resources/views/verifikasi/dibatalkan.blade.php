@extends('layouts.verifikasi')

@section('judul', 'Sertifikat '.$certificate->nomor.' dibatalkan')
@section('subjudul', 'Sertifikat dibatalkan')

{{--
  Sertifikat yang DIBATALKAN (§38.2). Sengaja minimal:
  - TANPA alasan apa pun (D4) — alasan internal bisa menyangkut sengketa atau
    salah ketik orang lab, bukan untuk pihak ketiga.
  - TANPA lembar sertifikat & tanpa tombol unduh — lembar batal tidak boleh
    tampil seolah masih dokumen yang sah (unduhnya dijawab 410).
  Yang tersisa cukup untuk pemindai mencocokkan: nomor, alat, nomor seri.
--}}
@section('isi')
    @php($header = $certificate->snapshot['header'] ?? [])

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
            <h2 class="status" id="h-status">Dibatalkan</h2>
            <p class="kecil">
                Sertifikat ini <strong>dibatalkan</strong>
                @if ($certificate->dibatalkan_pada)
                    pada {{ $certificate->dibatalkan_pada->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y') }}
                @endif
                oleh {{ $organization?->nama ?? 'laboratorium' }} dan tidak berlaku lagi. Berkasnya tidak bisa diunduh.
            </p>
        </div>
    </section>

    <section class="kartu" aria-label="Rincian sertifikat">
        <div class="nomor-blok">
            <span class="label">Nomor sertifikat</span>
            <div class="nomor-baris">
                <span class="nomor">{{ $certificate->nomor }}</span>
            </div>
        </div>

        <dl>
            <div class="baris">
                <dt>Alat</dt>
                <dd>{{ $header['equipment_name'] ?? $certificate->session?->equipment?->nama_alat ?? '—' }}</dd>
            </div>
            <div class="baris">
                <dt>Nomor seri</dt>
                <dd class="mono">{{ $header['serial_number'] ?? $certificate->session?->equipment?->serial_number ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    <div class="kartu catatan">
        <p>
            Kalau kamu memegang lembar sertifikat dengan nomor ini, lembar itu sudah tidak sah.
            Hubungi laboratorium
            @if ($organization?->telepon) di {{ $organization->telepon }} @endif
            @if ($organization?->email) ({{ $organization->email }}) @endif
            untuk informasi lebih lanjut.
        </p>
    </div>
@endsection
