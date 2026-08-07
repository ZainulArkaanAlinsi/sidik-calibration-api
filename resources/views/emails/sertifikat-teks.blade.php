{{--
  Versi teks polos dari email sertifikat.

  Dulu ini digenerate otomatis oleh mailable markdown. Sejak badan email-nya jadi
  HTML beneran, versi teksnya harus ditulis tangan — dan tetap wajib ada: email
  tanpa alternatif teks naik skor spam-nya, dan sebagian penerima korporat memang
  memblokir HTML.

  Isinya harus SEPADAN dengan versi HTML. Kalau salah satunya diubah, ubah
  dua-duanya — penerima yang dapat versi teks tidak boleh dapat informasi berbeda.
--}}
{{ $organisasi?->nama ?? config('app.name') }}
@if ($organisasi?->no_akreditasi)Laboratorium Kalibrasi Terakreditasi KAN - {{ $organisasi->no_akreditasi }}@endif

SERTIFIKAT KALIBRASI {{ $sertifikat->nomor ?? '-' }}
==============================================================

@if ($pelanggan?->nama)Kepada Yth. {{ $pelanggan->nama }},@else Dengan hormat,@endif

@if ($format === 'tautan')Kalibrasi alat Anda telah selesai kami kerjakan. Sertifikat resminya dapat Anda lihat dan unduh melalui tautan di bawah.
@elseif ($format === 'xlsx')Kalibrasi alat Anda telah selesai kami kerjakan. Sertifikat resminya kami lampirkan pada email ini dalam berkas Excel.
@else Kalibrasi alat Anda telah selesai kami kerjakan. Sertifikat resminya kami lampirkan pada email ini dalam berkas PDF.
@endif

RINCIAN
--------------------------------------------------------------
Nomor sertifikat  : {{ $sertifikat->nomor ?? '-' }}
Alat              : {{ $alat?->nama_alat ?? '-' }}
@if ($alat?->merk || $alat?->model)Merk / Tipe       : {{ collect([$alat?->merk, $alat?->model])->filter()->implode(' / ') }}
@endif
Nomor seri        : {{ $alat?->serial_number ?? '-' }}
Tanggal kalibrasi : {{ $sesi?->tanggal_kalibrasi?->translatedFormat('d F Y') ?? '-' }}
Tanggal terbit    : {{ $sertifikat->diterbitkan_pada?->translatedFormat('d F Y') ?? '-' }}
Berlaku sampai    : {{ $sertifikat->berlaku_sampai?->translatedFormat('d F Y') ?? '-' }}

@if ($sertifikat->qr_payload)
PERIKSA KEASLIAN SERTIFIKAT
--------------------------------------------------------------
Setiap sertifikat kami membawa tautan verifikasi. Anda dapat
memastikan dokumen ini asli dan masih berlaku kapan saja,
tanpa perlu menghubungi kami.

{{ $sertifikat->qr_payload }}

@endif
Bila ada pertanyaan mengenai hasil kalibrasi ini, cukup balas email ini -
tim kami akan menindaklanjuti.

Hormat kami,
{{ $organisasi?->nama ?? config('app.name') }}
@if ($organisasi?->alamat)
{{ $organisasi->alamat }}
@endif
@if ($organisasi?->telepon)Telp {{ $organisasi->telepon }}@endif
@if ($organisasi?->email) - {{ $organisasi->email }}@endif

--------------------------------------------------------------
Email ini dikirim otomatis oleh sistem kalibrasi
{{ $organisasi?->nama ?? config('app.name') }}. Hasil kalibrasi
hanya berlaku untuk alat yang tercantum di atas.
