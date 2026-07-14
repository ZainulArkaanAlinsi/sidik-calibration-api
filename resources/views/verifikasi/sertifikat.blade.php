@extends('layouts.publik')

@section('judul', 'Verifikasi Sertifikat '.$certificate->nomor)

@section('isi')
    <div class="kartu">
        <h1>Sertifikat terverifikasi</h1>
        <p class="catatan" style="margin-top:0">
            Sertifikat dengan nomor di bawah ini <strong>terdaftar</strong> di sistem
            {{ $organization?->nama ?? 'laboratorium' }}.
        </p>

        <dl>
            <div>
                <dt>Nomor sertifikat</dt>
                <dd>{{ $certificate->nomor }}</dd>
            </div>
            <div>
                <dt>Hasil kalibrasi</dt>
                <dd>
                    {{-- Sertifikat FAIL tetap sah & tetap terbit — statusnya aja beda.
                         Jangan ditampilin seolah-olah sertifikatnya nggak valid. --}}
                    <span class="lencana lencana-{{ strtolower($certificate->session->keputusan ?? 'pass') }}">
                        {{ $certificate->session->keputusan ?? '—' }}
                    </span>
                </dd>
            </div>
            <div>
                <dt>Alat</dt>
                <dd>{{ $certificate->session->equipment->nama_alat }}</dd>
            </div>
            <div>
                <dt>Nomor seri</dt>
                <dd>{{ $certificate->session->equipment->serial_number }}</dd>
            </div>
            <div>
                <dt>Pemilik alat</dt>
                <dd>{{ $certificate->session->equipment->customer->nama }}</dd>
            </div>
            <div>
                <dt>Tanggal kalibrasi</dt>
                <dd>{{ $certificate->session->tanggal_kalibrasi?->translatedFormat('d F Y') }}</dd>
            </div>
            <div>
                <dt>Diterbitkan</dt>
                <dd>{{ $certificate->diterbitkan_pada?->translatedFormat('d F Y') ?? '—' }}</dd>
            </div>
            <div>
                <dt>Berlaku sampai</dt>
                <dd>
                    {{ $certificate->berlaku_sampai?->translatedFormat('d F Y') ?? '—' }}
                    @if ($certificate->berlaku_sampai?->isPast())
                        <span class="lencana lencana-fail" style="margin-left:6px">Kadaluarsa</span>
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    @if ($catatanKetidakpastian || $catatanPenggandaan)
        <div class="kartu catatan">
            @if ($catatanKetidakpastian)
                <p><strong>*)</strong> {{ $catatanKetidakpastian }}</p>
            @endif
            @if ($catatanPenggandaan)
                <p>{{ $catatanPenggandaan }}</p>
            @endif
        </div>
    @endif
@endsection
