{{--
  Badan email sertifikat ke pelanggan (fase-2 §3d).

  Sengaja polos & pendek. Ini email resmi ke pelanggan, bukan materi promosi: yang
  dicari penerima cuma "sertifikat apa, alat mana, berlaku sampai kapan, dan mana
  filenya". Email panjang bergambar lebih sering kena filter spam, dan yang paling
  penting di sini lampirannya nyampe.

  NOL hitungan di sini. Semua angka yang mengikat ada di PDF-nya — kalau badan email
  ikut nyebut angka, dia bisa beda dari PDF sesudah sertifikat direvisi.
--}}
<x-mail::message>
# Sertifikat Kalibrasi {{ $sertifikat->nomor }}

@if ($pelanggan?->nama)
Kepada **{{ $pelanggan->nama }}**,
@else
Selamat siang,
@endif

Berikut kami kirimkan sertifikat kalibrasi untuk alat Anda. Dokumen resminya
terlampir dalam berkas PDF.

@component('mail::table')
| | |
|:---|:---|
| **Nomor sertifikat** | {{ $sertifikat->nomor ?? '—' }} |
| **Alat** | {{ $alat?->nama_alat ?? '—' }} |
| **Serial number** | {{ $alat?->serial_number ?? '—' }} |
| **Tanggal kalibrasi** | {{ $sesi?->tanggal_kalibrasi?->translatedFormat('d F Y') ?? '—' }} |
| **Tanggal terbit** | {{ $sertifikat->diterbitkan_pada?->translatedFormat('d F Y') ?? '—' }} |
| **Berlaku sampai** | {{ $sertifikat->berlaku_sampai?->translatedFormat('d F Y') ?? '—' }} |
@endcomponent

@if ($sertifikat->qr_payload)
Keaslian sertifikat ini bisa diperiksa kapan saja di:
[{{ $sertifikat->qr_payload }}]({{ $sertifikat->qr_payload }})

{{-- Tautan verifikasi ikut karena itu yang bikin penerima bisa mastiin
     lampirannya asli tanpa nelepon lab. --}}
@endif

Kalau ada pertanyaan mengenai hasil kalibrasi ini, silakan balas email ini.

Hormat kami,<br>
{{ $organisasi?->nama ?? config('app.name') }}
@if ($organisasi?->no_akreditasi)
<br>{{ $organisasi->no_akreditasi }}
@endif
</x-mail::message>
