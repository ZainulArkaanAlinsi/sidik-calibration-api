# Pertanyaan lab / tiket: baris tabel Holmium Spektrofotometer, profil vs kertas

Dibuat 23 Nov 2026. Status: **TERBUKA, belum dikerjakan.** Dipisah dari perubahan `NormalisasiAngka`
(`docs/ocr-tanpa-koreksi-otomatis.md`) karena sebabnya berbeda: ketidakcocokan templat dengan kertas, bukan koreksi nilai.

## Temuan

Templat OCR `spectrophotometer` (dari profil, `TemplateLembarKerja::untukKode`) untuk tabel `holmium`:

- **10 baris** dengan `titik_ukur`: 279,6 / 287,7 / 334 / 360,9 / 418,6 / 445,8 / 453,6 / 460 / 536,3 / 637,9;
- `desimal` = 2.

Foto uji lembar kerja UV/VIS (16 Nov 2026) mencetak tabel "Wavelength (nm) - Holmium" dengan **9 baris**, nilai standar
287,4 / 333,6 / 360,6 / 418,4 / 445,5 / 453,3 / 459,1 / 536,1 / 637,2, satu desimal, dan **tanpa baris 279,6**.
Tiap nilai kertas berbeda 0,1 sampai 0,9 dari `titik_ukur` profil (287,4 vs 287,7; 333,6 vs 334; 459,1 vs 460;
637,2 vs 637,9). Pemetaan di pengukuran memakai baris kertas i = baris profil i+1 (dicek lewat `titik_ukur`).

Belum diverifikasi: revisi formulir pada foto itu (kode footer tidak terbaca di OCR). Daftar server mengenal
spektrofotometer sebagai `SIDIK-FM-CAL-0511_Rev.5`.

## Pertanyaan untuk pemilik lab

1. Revisi formulir 0511 mana yang berlaku, dan apakah baris 279,6 pernah/masih tercetak di revisi mana pun?
2. Nilai standar di kertas (mis. 287,4) itu nilai sertifikat filter Holmium milik lab, sedangkan profil memakai nominal
   puncak (mis. 287,7). Mana yang harus menjadi `titik_ukur` profil?
3. Jumlah desimal di kertas satu; profil dua. Mana yang benar untuk pembacaan tangan?

## Dampak bila dibiarkan

Pemetaan baris pindai ke profil bergeser satu baris untuk lembar seperti foto uji. `ValidasiSel` menilai bacaan terhadap
rentang baris yang salah (pita profil lebar, jadi hampir tidak terlihat), dan templat/ledger versi
(`VersiTataLetakTemplatTest`) tidak menangkapnya karena kertas dan profil sama-sama "valid" menurut kode.

## Jawaban pemilik lab

_(belum ada)_
