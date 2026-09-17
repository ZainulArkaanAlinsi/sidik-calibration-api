{{--
    Sengaja HTML polos dengan gaya inline.

    Klien email (Gmail, Outlook) membuang `<style>` di `<head>` dan tidak
    memuat CSS dari luar sama sekali, jadi kelas CSS di sini cuma jadi markup
    yang tidak berpengaruh. Yang tampil sama di semua klien: tabel + atribut
    `style` menempel di elemennya.
--}}
<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2933; line-height: 1.6;">
    <p>Halo {{ $nama }},</p>

    <p>Gunakan kode di bawah ini untuk {{ $maksud }} di aplikasi SIDIK Pelanggan.</p>

    <p style="font-size: 30px; font-weight: bold; letter-spacing: 8px; margin: 24px 0; color: #0E5C68;">
        {{ $kode }}
    </p>

    <p>Kode ini berlaku {{ $berlakuMenit }} menit dan hanya bisa dipakai sekali.</p>

    <p style="color: #616e7c;">
        Kalau Anda tidak meminta kode ini, abaikan email ini &mdash; tidak ada yang berubah
        pada akun Anda. Jangan berikan kode ini kepada siapa pun, termasuk kepada orang yang
        mengaku dari PT Sidik.
    </p>
</div>
