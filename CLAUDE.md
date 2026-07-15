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
