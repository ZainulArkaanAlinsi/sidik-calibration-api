# Instruksi Project

## Git Workflow
- Setiap mulai sesi kerja, jalankan `git pull origin main` dulu sebelum mengubah kode apapun.
- JANGAN commit atau push otomatis setiap habis mengubah kode. Tunggu sampai user minta eksplisit, misal: "commit dan push ya", "commit ini dong".
- Begitu diminta commit, baru jalankan urutan ini sekaligus:
  1. `git add` (file yang relevan dengan perubahan, hindari `git add -A`/`.` kalau ada file mencurigakan/besar)
  2. `git commit -m "pesan singkat sesuai perubahan yang dibuat"`
  3. `git push origin main`
- Kalau ada conflict saat pull atau push, jangan force push. Tampilkan conflict-nya ke user dan minta arahan.
- Selalu kasih tau user ringkasan file apa saja yang berubah sebelum commit.

## Pemilihan Model
- Subagent yang tugasnya mengumpulkan data (Explore, pencarian file, penghitungan, pembacaan mentah) jalankan dengan `model="haiku"`.
- Subagent yang tugasnya menganalisis, mereview, atau menyintesis jalankan dengan `model="sonnet"`.
- Sisakan Opus untuk thread utama dan keputusan arsitektur.

## Daftar Permintaan
- Tujuh permintaan besar dari pemilik proyek ada di `docs/permintaan-user-7.md` — itu yang jadi
  pegangan, bukan ingatan percakapan. Baca dulu sebelum mulai kerja, dan perbarui kolom Status di
  commit yang sama dengan perubahannya.
- Berkas itu juga menyimpan keputusan yang SUDAH diambil (jangan ditanya ulang), pertanyaan yang
  masih menunggu jawaban, dan jebakan yang sudah terbukti bikin salah.

## Alur Kerja Fitur Besar (vibe coding)

Dipakai buat pekerjaan sebesar "alat baru" atau modul baru — bukan buat perbaikan sebaris.
Urutannya dari pemilik proyek, disetel ke kenyataan repo ini:

1. **IDE → PRD.** Tanya dulu yang paling menentukan, baru tulis PRD-nya. Di repo ini PRD-nya
   BUKAN berkas baru: dia jadi §baru di `docs/permintaan-user-7.md` + baris di §Gelombang.
   Jangan mengarang persyaratan yang belum jelas — tulis sebagai pertanyaan bernomor di
   `docs/pertanyaan-lab-*.md`.
2. **Tech stack.** Sudah dipatok: Laravel + MySQL + Filament (API), Flutter + Riverpod (mobile).
   Yang masih perlu diputuskan cuma *di mana* kode barunya duduk — dan jawabannya hampir selalu
   "ikut pola alat yang sudah ada", bukan lapisan baru.
3. **Arsitektur.** Sebutkan berkas yang akan dibuat/diubah SEBELUM mengetik. Ini mengikat, bukan
   kebiasaan: §12 spesifikasi permintaan 7 memintanya eksplisit.
4. **Database.** Kolom baru itu pilihan TERAKHIR. Empat alat terakhir mendarat dengan **nol kolom
   baru** di `raw_measurements` — sumbu `peran_sensor`/`sensor_ke`/`tahap` yang sudah ada hampir
   selalu cukup, dan blok tingkat-sesi masuk `spesifikasi_alat`.
5. **Satu fitur satu waktu.** Jangan menyentuh kode yang tidak diminta.
6. **Review.** Jalankan `[[sidik-code-reviewer]]`; untuk angka `[[sidik-kalkulasi-presisi]]`.
7. **Test.** Gate-nya di `[[sidik-test-verifier]]`. Untuk alat baru, yang mengikat: tiap komponen
   budget diadu ke master, bukan cuma U95 akhirnya.
8. **Debug.** Cari akar sebabnya, jangan menebak. **Sebelum menyalahkan perubahan sendiri,
   bandingkan ke baseline** (`git stash` lalu jalankan test yang sama) — kegagalan yang muncul
   belum tentu milikmu.
9. **Deploy.** `render.yaml` + `docs/CHECKLIST-DEPLOY-VPS.md`. Key baru wajib ikut ke
   `.env.example` DAN blueprint.
10. **Refactor.** Perilaku tidak boleh berubah. `vendor/bin/pint` **cuma pada berkas yang kamu
    sentuh** — dijalankan pada direktori, dia merapikan berkas lain dan mengotori diff.
11. **Dokumentasi.** Alat/modul baru belum selesai sebelum ada `docs/perintah-frontend-*.md` yang
    berdiri sendiri.

## Aturan yang Lahir dari Kesalahan Nyata

Ditulis di sini karena keempatnya **tidak menghasilkan error** waktu dilanggar:

- **Master lab ditiru, bukan dibetulkan diam-diam.** Kejanggalan metode → tiru + angkat sebagai
  pertanyaan lab. Kerusakan salin-tempel (rujukan meleset, tautan luar `[n]` ke workbook lain) →
  hitung benar + tulis selisihnya. `IFERROR(…,"")` yang bikin sel kosong dibaca nol → **jangan
  pernah** ditiru; blokir titiknya dengan alasan yang kebaca.
- **Jangan percaya bacaan tabel yang terpotong.** Tabel lampiran akreditasi barisnya menyambung
  dengan kolom kosong. Membacanya sebagian pernah melahirkan peringatan sesi yang salah — dan
  peringatan palsu melatih admin menekan "setujui tetap" tanpa membaca.
- **Daftar nama alat di `EquipmentFactory` wajib tidak memuat nama yang punya profil.** Kalau
  memuat, fixture acak mendarat di lembar alat itu dan yang merah adalah test yang tidak
  berhubungan, bergantian tiap jalan.
- **Alat baru WAJIB lahir bareng jalur hitung ulangnya** (`App\Support\*Mentah`, disambung ke
  `CalibrationValidator` DAN `HitungUlangSesi`). Pola ini sudah menggigit tujuh kali.

Playbook lengkapnya: `[[sidik-alat-baru-dari-master]]`.

## Instruksi Compaction
Saat konteks dipadatkan, pertahankan hal berikut:
- Penunjuk ke `docs/permintaan-user-7.md` beserta permintaan mana yang sedang dikerjakan.
- Nama branch yang sedang dipakai dan file yang sudah diubah tapi belum di-commit.
- Keputusan teknis yang sudah diambil beserta alasannya, bukan proses perdebatannya.
- Status verifikasi: perintah test/tinker yang sudah dijalankan di MySQL berikut hasilnya.
- Blocker yang belum selesai dan langkah berikutnya.
- Untuk pekerjaan alat baru: varian master mana yang sudah dibuktikan cocok, dan penyimpangan
  master mana yang sudah diangkat jadi pertanyaan lab bernomor.
Boleh dibuang: isi file yang sudah dibaca utuh, output test yang sudah hijau, dan eksplorasi yang tidak jadi dipakai.
