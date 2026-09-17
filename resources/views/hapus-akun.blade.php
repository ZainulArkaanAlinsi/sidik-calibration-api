@extends('layouts.publik')

@section('judul', 'Hapus Akun SIDIK Pelanggan')

@section('isi')
    <div class="kartu">
        <h1>Hapus Akun SIDIK Pelanggan</h1>

        @if (session('terkirim'))
            {{--
                Kalimat ini SENGAJA tidak menyebut apakah emailnya terdaftar.
                Kalau dibedakan, halaman publik ini jadi alat menyisir email
                pelanggan PT Sidik dari luar.
            --}}
            <p class="catatan" style="border-left:4px solid #0E5C68; padding-left:12px">
                <strong>Permintaan Anda sudah kami terima.</strong> Kalau email itu memang
                terdaftar, tim PT Sidik memprosesnya paling lambat <strong>7 hari kerja</strong>.
            </p>
        @endif

        <p>
            <strong>Cara tercepat: lewat aplikasi.</strong> Buka SIDIK Pelanggan →
            <em>Profil</em> → <em>Hapus Akun</em>. Akun Anda langsung terhapus saat itu juga,
            tanpa menunggu siapa pun.
        </p>

        <p>Halaman ini untuk Anda yang tidak bisa membuka aplikasinya lagi.</p>

        <h2 style="font-size:1.05rem;margin-top:24px">Yang dihapus</h2>
        <ul>
            <li>Nama, alamat email, nomor HP, dan jabatan Anda</li>
            <li>Seluruh sesi masuk dan perangkat yang terdaftar</li>
            <li>Keanggotaan Anda di perusahaan mana pun</li>
        </ul>

        <h2 style="font-size:1.05rem;margin-top:24px">Yang TETAP tersimpan</h2>
        <ul>
            <li>Data perusahaan, alat, permintaan kalibrasi, dan sertifikat</li>
        </ul>
        <p class="catatan">
            Keempatnya adalah <strong>rekaman laboratorium terakreditasi</strong>. Sertifikat
            yang sudah terbit harus tetap bisa ditelusuri ke alat dan sesi kalibrasinya
            selama masa simpan yang diwajibkan ISO/IEC 17025 &mdash; jadi yang hilang
            identitas Anda, bukan jejak pengukurannya.
        </p>

        <h2 style="font-size:1.05rem;margin-top:24px">Kirim permintaan</h2>

        <form method="POST" action="{{ route('hapus-akun.kirim') }}">
            @csrf

            <p>
                <label for="email">Email akun Anda</label><br>
                <input id="email" type="email" name="email" required maxlength="254"
                       value="{{ old('email') }}" style="width:100%;max-width:420px;padding:8px">
                @error('email')
                    <br><small style="color:#b91c1c">{{ $message }}</small>
                @enderror
            </p>

            <p>
                <label for="alasan">Alasan <small>(opsional)</small></label><br>
                <textarea id="alasan" name="alasan" rows="3" maxlength="500"
                          style="width:100%;max-width:420px;padding:8px">{{ old('alasan') }}</textarea>
            </p>

            <p><button type="submit" style="padding:10px 18px">Kirim permintaan</button></p>
        </form>
    </div>
@endsection
