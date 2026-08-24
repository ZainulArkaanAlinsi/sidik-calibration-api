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

## Instruksi Compaction
Saat konteks dipadatkan, pertahankan hal berikut:
- Penunjuk ke `docs/permintaan-user-7.md` beserta permintaan mana yang sedang dikerjakan.
- Nama branch yang sedang dipakai dan file yang sudah diubah tapi belum di-commit.
- Keputusan teknis yang sudah diambil beserta alasannya, bukan proses perdebatannya.
- Status verifikasi: perintah test/tinker yang sudah dijalankan di MySQL berikut hasilnya.
- Blocker yang belum selesai dan langkah berikutnya.
Boleh dibuang: isi file yang sudah dibaca utuh, output test yang sudah hijau, dan eksplorasi yang tidak jadi dipakai.
