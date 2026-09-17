# ADR-001: Repo aplikasi pelanggan dipisah, backend tetap satu

**Status:** Diusulkan
**Tanggal:** 16 Sep 2026
**Perlu disetujui:** Arkaan, Raihan, supervisor PT Sidik

## Konteks

- App internal (`sidik-calibration-mobile`, package `com.ptsidik.kalibrasi`) sudah besar: OCR ML Kit di perangkat, template lembar kerja, banyak layar admin/teknisi, dan 5 workflow CI. MVP-nya dikejar untuk akhir September 2026.
- App pelanggan akan dipublikasikan di Google Play untuk perusahaan-perusahaan di luar PT Sidik, dengan siklus rilis, listing, dan kewajiban kebijakan Play sendiri.
- Tim dua orang yang bekerja di repo yang sama sering menyentuh file yang sama.
- Backend (`sidik-calibration-api`) memegang seluruh data lab: alat, order, sesi, sertifikat. Data yang dilihat pelanggan adalah data yang sama.
- Sebelumnya (diskusi 16 Sep) sempat diusulkan "satu repo Flutter dengan flavor". Setelah `pubspec.yaml` dicek, usulan itu dikoreksi: flavor di Flutter **berbagi satu `pubspec`**, sehingga `google_mlkit_text_recognition`, `google_mlkit_barcode_scanning`, `file_picker`, dan pustaka native lain ikut terbawa ke APK pelanggan. Akibatnya ukuran membengkak dan izin/SDK yang tidak dipakai ikut dideklarasikan di Data safety.

## Keputusan

1. Aplikasi pelanggan dibuat di **repo baru `sidik-pelanggan-mobile`**, di GitHub Organization milik PT Sidik.
2. **Backend tetap satu repo** (`sidik-calibration-api`). Modul pelanggan diisolasi lewat file rute, namespace controller/resource/request, dan folder test sendiri (03-SDD §2).
3. Kontrak API pelanggan tinggal di repo backend (`docs/kontrak-api-pelanggan.md` + fixture JSON) sebagai satu sumber kebenaran.
4. Kode bersama antar dua app **disalin sekali** (token desain, pola `api_client`) tanpa paket bersama dulu.

## Opsi yang dipertimbangkan

### Opsi A — Satu repo mobile, dua flavor

| Dimensi | Penilaian |
|---|---|
| Kompleksitas | Rendah di awal, naik terus |
| Biaya | Gratis |
| Isolasi | Buruk: dependency, aset, dan CI bercampur |
| Risiko ke MVP internal | Tinggi: tiap perubahan app pelanggan menyentuh repo yang sedang dikejar |

**Plus:** kode bisa dipakai langsung, satu tempat. **Minus:** `pubspec` bersama (ML Kit ikut ke APK pelanggan), konflik merge, CI membangun keduanya tiap PR, risiko layar/logika internal ikut terkompilasi.

### Opsi B — Monorepo dengan pub workspaces (`apps/internal`, `apps/pelanggan`, `packages/sidik_core`)

| Dimensi | Penilaian |
|---|---|
| Kompleksitas | Sedang–tinggi (restrukturisasi, path CI, konfigurasi Firebase per app) |
| Biaya | Gratis |
| Isolasi | Baik: `pubspec` per app, paket bersama eksplisit |
| Risiko ke MVP internal | Tinggi **sekarang**: harus memindahkan struktur repo internal di bulan MVP |

**Plus:** paling rapi jangka panjang, perubahan lintas app bisa satu PR. **Minus:** restrukturisasi di waktu terburuk, golden test & workflow yang ada harus dipindah.

### Opsi C — Repo terpisah untuk app pelanggan, backend tetap satu ✅

| Dimensi | Penilaian |
|---|---|
| Kompleksitas | Rendah |
| Biaya | Gratis |
| Isolasi | Baik untuk app, dijaga aturan modul di backend |
| Risiko ke MVP internal | Rendah: repo internal tidak disentuh |

**Plus:** tidak mengganggu MVP internal, CI/rilis/izin repo terpisah (bisa memberi akses ke kontributor lain tanpa membuka kode internal), APK pelanggan bersih. **Minus:** duplikasi kecil kode UI & client, kontrak API harus dijaga disiplin.

### Opsi D — Backend pelanggan terpisah (layanan/DB sendiri)

Ditolak. Data pelanggan adalah data lab yang sama. Memisahkan backend berarti sinkronisasi dua arah alat, order, sesi, dan sertifikat, yang jauh lebih rawan daripada isolasi modul.

## Analisis trade-off

Yang dikorbankan Opsi C adalah kenyamanan berbagi kode. Yang didapat: MVP internal aman, APK pelanggan bersih, dan batas tanggung jawab jelas. Duplikasi yang realistis hanya token desain Precision Clean dan pola HTTP client, karena model data pelanggan memang berbeda dari model internal (DTO pelanggan sengaja lebih sempit).

Bahaya terbesar Opsi C bukan di repo app, tapi di **backend yang satu**: perubahan untuk pelanggan bisa merusak teknisi, atau data internal bocor ke pelanggan. Itu dijawab dengan: modul terpisah, test isolasi otomatis, test rute internal menolak pelanggan, feature flag, dan migrasi additive (03-SDD, 06-Risk R-A02/A03/D01).

## Konsekuensi

- **Lebih mudah:** rilis app pelanggan tanpa menunggu app internal, menjaga Data safety tetap jujur, onboarding kontributor baru ke app pelanggan saja.
- **Lebih sulit:** menjaga tampilan dua app tetap serasi; perubahan kontrak API harus diumumkan ke dua sisi.
- **Ditinjau ulang kalau:** perubahan yang sama disalin ke dua repo lebih dari 3 kali dalam sebulan, atau iOS/web ditambahkan. Saat itu, pertimbangkan Opsi B atau paket `sidik_ui_kit` berversi (git dependency dengan tag).

## Action items

1. [ ] Buat GitHub Organization PT Sidik dan transfer dua repo yang ada (M0-03)
2. [ ] Buat `sidik-pelanggan-mobile` dengan branch protection & CODEOWNERS (M2-01)
3. [ ] Tambah `docs/kontrak-api-pelanggan.md` + folder fixture di repo backend (M1-06)
4. [ ] Aturan modul pelanggan ditambahkan ke `CLAUDE.md` repo backend supaya asisten coding ikut mematuhinya (08-Prompt)
