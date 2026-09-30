@extends('layouts.verifikasi')

@section('judul', 'Verifikasi Sertifikat '.$certificate->nomor)
@section('subjudul', 'Sertifikat terverifikasi')

@section('isi')
    @php($kedaluwarsa = (bool) $certificate->berlaku_sampai?->isPast())

    {{-- Status diturunkan dari "berlaku sampai" yang memang sudah tampil di
         bawah — bukan data baru. Sertifikat FAIL tetap sah & tetap terbit;
         statusnya di sini soal masa berlaku, bukan soal lulus/tidaknya. --}}
    <section class="kartu kartu-status {{ $kedaluwarsa ? 'kedaluwarsa' : '' }}" aria-labelledby="h-status">
        <span class="ubin-status" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                stroke-linecap="round" stroke-linejoin="round">
                @if ($kedaluwarsa)
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7.5V12l3 2" />
                @else
                    <circle cx="12" cy="12" r="9" />
                    <path d="M8 12.5l2.8 2.8L16.5 9.5" />
                @endif
            </svg>
        </span>
        <div>
            <span class="label">Status sertifikat</span>
            <h2 class="status" id="h-status">{{ $kedaluwarsa ? 'Kedaluwarsa' : 'Berlaku' }}</h2>
            <p class="kecil">
                Sertifikat dengan nomor di bawah ini <strong>terdaftar</strong> di sistem
                {{ $organization?->nama ?? 'laboratorium' }}.
            </p>
        </div>
    </section>

    <section class="kartu" aria-label="Rincian sertifikat">
        <div class="nomor-blok">
            <span class="label">Nomor sertifikat</span>
            <div class="nomor-baris">
                <span class="nomor">{{ $certificate->nomor }}</span>
                {{-- Sertifikat FAIL tetap sah & tetap terbit — statusnya aja beda.
                     Jangan ditampilin seolah-olah sertifikatnya nggak valid. --}}
                <span class="lencana lencana-{{ strtolower($certificate->session->keputusan ?? 'pass') }}">
                    {{ $certificate->session->keputusan ?? '—' }}
                </span>
            </div>
        </div>

        <dl>
            <div class="baris">
                <dt>Alat</dt>
                <dd>{{ $certificate->session->equipment->nama_alat }}</dd>
            </div>
            <div class="baris">
                <dt>Nomor seri</dt>
                <dd class="mono">{{ $certificate->session->equipment->serial_number }}</dd>
            </div>
            <div class="baris">
                <dt>Pemilik alat</dt>
                <dd>{{ $certificate->session->equipment->customer->nama }}</dd>
            </div>
            <div class="baris">
                <dt>Tgl kalibrasi</dt>
                <dd>{{ $certificate->session->tanggal_kalibrasi?->translatedFormat('d F Y') }}</dd>
            </div>
            <div class="baris">
                <dt>Diterbitkan</dt>
                <dd>{{ $certificate->diterbitkan_pada?->translatedFormat('d F Y') ?? '—' }}</dd>
            </div>
            <div class="baris">
                <dt>Berlaku sampai</dt>
                <dd>
                    {{ $certificate->berlaku_sampai?->translatedFormat('d F Y') ?? '—' }}
                    @if ($kedaluwarsa)
                        <span class="lencana lencana-fail" style="margin-left:6px">Kadaluarsa</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($organization)
            <div class="lab">
                Dikalibrasi oleh <strong>{{ $organization->nama }}</strong>
                @if ($organization->alamat), {{ $organization->alamat }} @endif
                @if ($organization->no_akreditasi)
                    · Terakreditasi KAN No. <span style="font-family:var(--mono)">{{ $organization->no_akreditasi }}</span>
                    @if ($organization->standar_akreditasi) — {{ $organization->standar_akreditasi }} @endif
                @endif
            </div>
        @endif
    </section>

    <p class="petunjuk">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:0 0 auto;margin-top:2px">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5.5M12 7.5v.01" />
        </svg>
        <span>Cocokkan nomor, alat, dan nomor seri dengan kertas yang kamu pegang.</span>
    </p>

    @if ($catatanKetidakpastian || $catatanPenggandaan)
        <div class="kartu catatan" style="margin-top:10px">
            @if ($catatanKetidakpastian)
                <p><strong>*)</strong> {{ $catatanKetidakpastian }}</p>
            @endif
            @if ($catatanPenggandaan)
                <p>{{ $catatanPenggandaan }}</p>
            @endif
        </div>
    @endif
@endsection
