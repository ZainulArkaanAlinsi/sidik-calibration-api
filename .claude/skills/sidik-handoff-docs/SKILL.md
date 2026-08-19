---
name: sidik-handoff-docs
description: Generate dokumen serah-terima berdiri sendiri (docs/perintah-frontend-*.md) saat modul backend kalibrasi selesai, siap ditempel ke sesi kerja frontend/mobile terpisah. Pakai saat user bilang modul/alat baru sudah selesai backend-nya dan perlu diteruskan ke sisi frontend.
---

# Sidik Handoff Docs (Bonus Skill)

Skill tambahan di luar 5 inti — proyek ini bekerja lintas repo (backend
`sidik-calibration-api` terpisah dari frontend/mobile), jadi tiap modul yang
selesai butuh dokumen serah-terima yang BISA BERDIRI SENDIRI: ditempel utuh ke
sesi kerja lain tanpa perlu buka dokumen tambahan (`[[handoff-frontend-lewat-docs]]`).

## Kapan Dipakai
- User bilang backend suatu alat/fitur "sudah selesai" dan minta lanjut ke sisi
  frontend, ATAU
- User eksplisit minta dibikinkan dokumen handoff.

**Jangan** bikin dokumen ini sebelum lolos gate `[[sidik-test-verifier]]`
(test hijau + terverifikasi di MySQL). Dokumen yang menjanjikan "backend
selesai" padahal belum diverifikasi di MySQL itu berbahaya — sisi frontend
akan membangun di atas asumsi yang belum tentu benar.

## Struktur Dokumen (`docs/perintah-frontend-<nama-alat>.md`)

```markdown
# Perintah Frontend — <Nama Alat/Fitur>

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis
ke sesi kerja frontend — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `<branch>`, commit `<hash>` |
| Formulir | `<kode form SIDIK-FM-...>` (kalau relevan) |
| Status backend | Selesai & terverifikasi di MySQL. <N> test hijau |
| Referensi lain (opsional) | dokumen pendukung lain kalau ada |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

<konteks modul, apa yang berubah, kenapa>

## 0. Aturan paling penting
1. Frontend TIDAK menghitung apa pun — semua angka dari API.
2. Jangan hardcode titik ukur/satuan/resolusi/jumlah kolom — ambil dari
   endpoint lembar-kerja.
3. Jangan pasang validasi rentang sendiri — pita datang dari backend.
4. Kalau ambigu, berhenti dan tanya — jangan menambal dengan asumsi.

## 1. <detail endpoint, contoh payload dari tinker/response nyata>
## 2. <edge case yang perlu diketahui frontend>
...
```

## Aturan Isi
- **Contoh JSON dari hasil nyata (tinker/response test), bukan karangan.**
  Jalankan endpointnya, salin outputnya — jangan menulis contoh payload dari
  ingatan/tebakan bentuk (`[[handoff-frontend-lewat-docs]]`).
- Sertakan hash commit & nama branch aktual (`git rev-parse HEAD`,
  `git branch --show-current`), bukan placeholder.
- Tulis "Aturan paling penting" di bagian atas — frontend TIDAK boleh
  menghitung ulang apa pun yang sudah dihitung backend (prinsip satu sumber
  kebenaran, sama seperti alasan preview/store harus identik di
  `[[sidik-kalkulasi-presisi]]`).
- Kalau ada keputusan desain yang berpotensi disalahpahami sisi frontend
  (mis. varian satuan, pembulatan tampilan), jelaskan eksplisit di dokumen
  ini — jangan andalkan frontend membaca kode backend.

## Guidelines
- Dokumen ini BUKAN pengganti `docs/kontrak-api.md` — dia pointer + konteks
  siap-tempel untuk satu sesi kerja, kontrak API tetap sumber kebenaran bentuk
  response jangka panjang.
- Setelah dibuat, tawarkan file ke user lewat pengiriman berkas, jangan cuma
  ditinggal di disk tanpa disebut lokasinya.
