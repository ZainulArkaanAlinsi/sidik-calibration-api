# Pertanyaan lab — Sieve Mesh (LK-285-IDN no. 33)

**Untuk:** Manajer Teknis, Lab Kalibrasi PT Sidik
**Sumber:** `Master Olah Data_Sieve Mesh.xlsm` (ber-password), kertas `SIDIK-FM-CAL-0536 Rev.2`
**Sesi contoh:** `0736-CAL-526` — sieve 19 mm, tipe Inspection, 15 opening, Digital Caliper
**Disiapkan:** 15 September 2026

Rumus master dibuktikan ulang di luar Excel: **189 dari 189 sel cocok** (toleransi 5·10⁻⁶).
Yang di bawah ini BUKAN salah hitung sistem — ini hal yang ditemukan di master dan
perlu keputusan lab. Tiap butir menyebut apa yang dilakukan sistem SAMBIL menunggu.

Angka dampak di bawah semuanya dari sesi contoh (mm).

| § | Temuan | Jenis | Sistem sekarang |
|---|---|---|---|
| 1 | Pengulangan dibagi 6, bukan √6 | Metode | **Ditiru** |
| 2 | k dipatok 2 walau veff 6–15 | Metode | **Ditiru** |
| 3 | "Pengulangan 1 opening" = salinan opening 1–6 | Metode | **Ditiru**, tidak diminta dari teknisi |
| 4 | Koreksi standar selalu 0 (VLOOKUP ke kolom kosong) | Salin-tempel | **Dihitung benar** (+0,01 mm) |
| 5 | Minimum opening tipe Calibration ambil kolom Inspection | Salin-tempel | **Dihitung benar** |
| 6 | Nominal dijepret ke ukuran ASTM terdekat | Diam-diam | **Diblokir** |
| 7 | Minimum opening tidak ditegakkan | Diam-diam | **Diblokir** |
| 8 | Pita CMC master ≠ lampiran | Salin-tempel | Lantai = yang **lebih besar**; "cek range" **diblokir** |
| 9 | Salah ketik Tabel_MPE (0,080 mm; 1 mm; 10 mm) | Data | Baris ber-kolom-terpakai **diblokir** |
| 10 | Vonis tanpa U | Metode | **Guarded acceptance** (keputusan 14 Jul), vonis master dicatat |
| 11 | +X, stdev kawat tidak dinilai; max stdev "-" dibaca PASS | Metode | Tidak dinilai, **dicatat** |
| 12 | Status standar dari `NOW()` | Salin-tempel | Dari **tanggal kalibrasi sesi** |
| 13 | Komponen selisih suhu selalu nol, ci pakai Uα | Metode | **Ditiru** |
| 14 | Frame: kertas 3 bacaan, workbook 1, tidak dihitung | Metode | Dicatat saja |
| 15 | Pencarian koreksi mikroskop pakai konstanta 0,0102 | Salin-tempel | Titik **terdekat** |
| 16 | Nominal inch: kolom inch vs 19,05 mm | Tafsir | Cocokkan ke **kolom inch** |

---

## §1 — Pengulangan dibagi 6, bukan √6

`PERHITUNGAN U95%!Q16 = 6`, `S16 = 5` (vi). Ketidakpastian baku pengulangan dari
enam pembacaan lazimnya `s/√6`.

| Parameter | U (÷6, master) | U (÷√6) |
|---|---|---|
| warp | 0,02616 | **0,05303** |
| weft | 0,04628 | **0,10750** |
| kawat | 0,02469 | **0,04864** |

**Pertanyaan:** pembagi 6 disengaja (dengan alasan tertulis) atau salah ketik √6?
Kalau salah ketik, sertifikat yang sudah terbit menyatakan U ≈ separuh seharusnya.

## §2 — k = 2 dipatok

`AC20/AC37/AC55` berisi angka 2. veff dihitung (12,84 / 6,47 / 14,93) tapi tidak
dipakai. Dengan TINV(0,05; veff): warp k 2,179 → U 0,0285; **weft k 2,447 → U 0,0566**;
kawat k 2,145 → U 0,0265. Dial Indicator dan Jangka Sorong di lab yang sama memakai TINV.

**Pertanyaan:** k = 2 kebijakan untuk Sieve, atau ikut TINV seperti alat panjang lain?

## §3 — Blok "pengulangan pada 1 sample opening" ternyata salinan

`INPUT DATA!H89:N91` berisi rumus `=G34`, `=G35`, … — enam opening PERTAMA, bukan
enam pengukuran berulang pada satu lubang. Sebaran enam lubang berbeda ikut dihitung dua
kali (juga di stdev populasi). Kertas FM-CAL-0536 tidak punya blok ini.

**Pertanyaan:** komponen ini dimaksudkan pengulangan pembacaan pada satu lubang? Kalau ya,
kertas perlu blok baru (6 × 3 kotak). Sampai dijawab sistem meniru master: opening 1..6
wajib lengkap.

## §4 — Koreksi standar selalu nol

`PERHITUNGAN!G82 = G80 + VLOOKUP(G81; Koreksi_Caliper; 3; 0)` — kolom ke-3 tabel
`K12:O21` adalah kolom **M yang kosong**; kolom Koreksi ada di N (ke-4). Sama di
`Koreksi_MikroskopX/Y` (kolom E kosong, Koreksi di F).

Sesi contoh: titik caliper terdekat 21 mm, koreksi **+0,01 mm**. Warp tercetak 19,1387,
seharusnya **19,1487**; weft 19,1827 → **19,1927**. Tetap PASS.

**Sistem:** memakai kolom Koreksi. **Pertanyaan:** konfirmasi arah tanda
(Koreksi = Standar − Penunjukan, ditambahkan ke bacaan), dan apakah sertifikat lama perlu ditinjau.

## §5 — Minimum opening tipe Calibration

`INPUT DATA!F16` mengambil kolom 6 (Inspection) untuk tipe Inspection **dan** Calibration.
Sieve 19 mm tipe Calibration: master menuntut 15, Tabel_MPE menuntut **30**. Sistem memakai kolom 7.

## §6 — Nominal dijepret

`INPUT DATA!Z16` mencari ukuran Tabel_MPE TERDEKAT. 19,5 mm dinilai dengan batas 20 mm,
150 mm dengan batas 125 mm, tanpa pesan. Sistem menolak nominal yang tidak persis ada.

## §7 — Minimum opening tidak ditegakkan

Master mencetak `L25` tapi tidak pernah membandingkannya dengan jumlah opening terisi.
Sistem memblokir sesi di bawah minimum. Untuk ukuran ≥ 25 mm kolomnya `all`:
**Pertanyaan:** "all" = seluruh lubang pada sieve? Sistem meminta teknisi mengisi jumlah total
opening dan menuntut semuanya terisi.

## §8 — Pita CMC master ≠ lampiran

| | Master (`DATABASE!S5:T6`, `AC22`) | Lampiran LK-285-IDN no. 33 |
|---|---|---|
| Pita halus | Mikroskop **dan** ≤ 2 mm → 4,33 µm | 45–4000 µm → 4,33 µm |
| Pita kasar | Caliper **dan** > 2 mm → 0,02 mm (label "2–150 mm") | 4–100 mm → 0,02 mm |
| Lainnya | `"cek range"` → U terbit TANPA lantai | di luar lingkup |

Sistem: lantai = `max(lampiran, master)`; di luar lampiran atau "cek range" (mikroskop > 2 mm,
caliper ≤ 2 mm) **diblokir**. **Pertanyaan:** mikroskop untuk 2–4 mm dan caliper untuk 2–4 mm —
mana yang sah, dan pita mana yang berlaku?

## §9 — Salah ketik Tabel_MPE

| Ukuran | Kolom | Tertulis | Mestinya (ASTM E11) | Dipakai hitungan? |
|---|---|---|---|---|
| 0,080 mm | Ø kawat preferred | 0,56 | 0,056 | **Ya** — koreksi kawat salah besar |
| 1 mm | µm | 18000 | 1000 | Tidak |
| 10 mm | inch | 0,279 | 0,394 | Hanya bila satuan inch |

Sistem menandai (tidak membetulkan) dan memblokir baris bila kolom janggalnya dipakai.
**Minta:** betulkan tabel master; generator `docs/skrip/gen-tabel-standar-sieve.py` dijalankan ulang.

## §10 — Vonis tanpa U

`SERTIFIKAT!AE20 = IF(AND(AB20<=AD20; AB20>=−AD20); "PASS"; "NOT PASS")` — tanpa U.
Keputusan proyek (14 Jul) guarded acceptance: `|deviasi| + U ≤ Y`. Sesi contoh tetap PASS
(warp 0,1748; weft 0,2390 vs Y 0,522). Deviasi 0,50 mm akan PASS di master, FAIL di sistem.
**Pertanyaan:** konfirmasi berlaku untuk Sieve.

## §11 — Yang tidak dinilai master

- **+X** (kolom 8, opening individu terbesar) — syarat ASTM E11, tidak diperiksa.
- **Stdev Ø kawat** — tidak ada batas.
- **Max stdev "-"** (≥ 45 mm): `angka <= "-"` di Excel TRUE → selalu PASS. Sistem: "tidak dinilai".

**Pertanyaan:** perlukah +X dinilai dan dicetak?

## §12 — Status standar dari `NOW()`

`DATABASE!Z13 = Y13 − NOW()`. Sistem menilai terhadap tanggal kalibrasi sesi. Digital Caliper
berlaku sampai **25 Jul 2026** — sesi sesudah itu diblokir sampai sertifikat baru dimasukkan.

## §13 — Selisih suhu

`AE5` (suhu sieve) dan `AE7` (suhu standar) sama-sama `=PERHITUNGAN!G17`, jadi komponen
selalu 0; ci-nya `L × Uα` (Uα = 2Δα caliper), bukan `L × Δα`. Tidak berdampak hari ini.

## §14 — Frame

Kertas: 3 bacaan Ø rangka & ketinggian; workbook: 1 angka, tidak dihitung, tidak dicetak.
**Pertanyaan:** dicetak di sertifikat? diberi batas?

## §15 — Koreksi mikroskop

Cabang mikroskop `G81/J81/L81` memakai konstanta 0,0102 (baris pertama) untuk semua ukuran;
cabang caliper memakai titik terdekat. `L81` hanya menyapu 9 dari 10 baris caliper.
Sistem memakai titik terdekat dari seluruh tabel.

## §16 — Nominal inch

Master: `Z15 = 0,75 × 25,4 = 19,05`, dijepret ke 19,0, lalu deviasi sertifikat dihitung
terhadap 19,05 (`K20 = H20 − F14` dalam inch). Sistem mencocokkan 0,75 ke kolom inch
(= sieve 19,0 mm) dan menghitung deviasi terhadap **19,0 mm**. Beda 0,05 mm pada deviasi.
**Pertanyaan:** konfirmasi.
