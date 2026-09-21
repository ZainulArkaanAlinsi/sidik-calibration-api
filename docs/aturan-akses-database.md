# Aturan Akses Database — Claude Code

> Ditulis 19 Sep 2026. Ini bukan aturan teknis baru — ini menuliskan pola yang sudah berjalan sejak sesi audit hydrometer, supaya sesi berikutnya tidak perlu ditanya ulang.

---

## Siapa pegang apa

**Kredensial database cuma ada di satu tempat: `.env` di mesin kerja lo (Zainul).** Claude Code baca dari situ lewat `config()`, bukan dari mana pun yang lain.

**Claude tidak pernah, dan tidak akan, minta akses langsung ke database.** Bukan karena tidak mampu secara teknis — tapi karena database ini berisi data pelanggan lab, dan akses ke situ harus bisa dilacak balik ke satu akun kerja yang jelas tanggung jawabnya. Kalau ada dua pemegang kredensial, jejak itu pecah.

Jadi alurnya selalu: **Zainul → Claude Code (yang pegang akses) → laporan ditempel ke Claude (yang bantu mikir dari laporan itu).**

---

## Aturan untuk Claude Code

1. **Baca boleh, tulis harus eksplisit diminta.** Query `SELECT` untuk menjawab pertanyaan boleh jalan begitu ada izin sekali di sesi itu. `INSERT`/`UPDATE`/`DELETE`/DDL apa pun — termasuk lewat `tinker`, `migrate`, seeder — butuh instruksi eksplisit yang menyebut tabel dan jumlah barisnya, bukan izin umum "boleh akses database".

2. **Setiap query yang menyentuh data produksi, sebut dulu sebelum jalan:** tabel apa, kira-kira berapa baris, dan apakah ini baca atau tulis. Pola "Fakta sebelum edit" yang sudah dipakai untuk berkas kode berlaku sama untuk query database.

3. **Data sensitif tidak pernah keluar mentah.** Nama pelanggan, alamat, kontak — kalau perlu ditunjukkan sebagai bukti, ringkas jadi jumlah/pola, bukan isi barisnya. Ini yang sudah dipakai waktu sensus akun pelanggan (jumlah per role, bukan daftar nama) dan waktu memeriksa nomor sertifikat yang terpakai seeder.

4. **Penghapusan permanen (tabel tanpa `SoftDeletes`) selalu lewat urutan: rencana tertulis → cadangan terverifikasi → `--kosongan`/dry-run yang ditunjukkan dulu → baru eksekusi, dan eksekusinya nunggu instruksi terpisah, bukan otomatis lanjut dari dry-run.** Ini bukan kelonggaran yang bisa dipercepat kalau "buru-buru" — jumlah baris yang salah hitung di sini tidak bisa ditarik balik.

5. **Kalau nemuin sesuatu yang menyentuh integritas data yang diperiksa pihak luar** (nomor sertifikat, jejak audit, apa pun yang biasa dicek asesor) — laporkan temuannya lengkap, tapi keputusan soal disampaikan ke siapa dan kapan itu bukan keputusan Claude Code untuk diambil sendiri. Cukup pastikan laporannya cukup jelas untuk dibawa ke orang yang tepat.

---

## Kalau ditawari cara mempercepat lewat "kasih akses langsung"

Tolak, dan jelaskan alasannya: bukan soal kecepatan, soal jejak. Alur baca-lapor-putuskan yang sekarang berjalan itu justru yang bikin ketahuan hal-hal seperti nomor sertifikat resmi terpakai data contoh — karena setiap langkah tertulis dan bisa ditelusuri balik.

Ini berlaku juga untuk tawaran MCP atau tool pihak ketiga yang minta kredensial database. Sudah pernah dibahas untuk Aiven MCP (dijawab tidak, dicatat di `AGENTS.md`) — pola keputusannya sama untuk kasus serupa yang muncul nanti.
