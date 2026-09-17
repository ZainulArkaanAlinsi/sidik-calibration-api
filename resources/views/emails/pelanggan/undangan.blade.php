{{-- Gaya inline, alasannya sama dengan emails/pelanggan/otp.blade.php. --}}
<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2933; line-height: 1.6;">
    <p>Halo,</p>

    <p>
        Anda diundang bergabung di aplikasi <strong>SIDIK Pelanggan</strong> sebagai
        {{ $sebutanPeran }} untuk <strong>{{ $namaPerusahaan }}</strong>.
    </p>

    <p>Buka aplikasi, pilih &ldquo;Punya kode undangan&rdquo;, lalu masukkan kode ini:</p>

    <p style="font-size: 28px; font-weight: bold; letter-spacing: 6px; margin: 24px 0; color: #0E5C68;">
        {{ $kode }}
    </p>

    <p>Kode berlaku {{ $berlakuHari }} hari, sekali pakai, dan hanya cocok untuk alamat email ini.</p>

    <p style="color: #616e7c;">
        Kalau Anda tidak mengenali undangan ini, abaikan email ini &mdash; tanpa kode, tidak ada
        akun yang bisa dibuat atas nama Anda.
    </p>
</div>
