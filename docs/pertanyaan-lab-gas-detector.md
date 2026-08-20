# Pertanyaan ke Lab — Gas Detector

Status: menunggu jawaban · Dibuat 20 Agustus 2026 · Sumber:
`Gas Detector Uli Skin (std Rigaz).xlsm` (sesi 001-CAL-226, PT Unilever
Indonesia Skin Care Factory, Honeywell Microclip XL, 2 Februari 2026)

Backend Gas Detector (alat ke-10) sudah jalan penuh dan keempat gasnya
(CO/H2S/CH4/O2) sudah diadu ke workbook sampai digit terakhir —
`GasDetectorBudgetTest` & `GasDetectorSesiTest`. Empat hal di bawah **tidak**
bisa diputuskan dari berkas yang ada, dan semuanya sudah diberi keputusan
sementara supaya pekerjaan tidak berhenti.

---

## 1. Nomor formulir lembar kerjanya belum ada

Satu-satunya nomor formulir di seluruh workbook adalah `SIDIK-FM-CAL-2403_Rev. 0`
di footer sheet `SERTIFIKAT` — itu formulir SERTIFIKAT, dipakai bersama semua
alat. Sheet `FORM VALIDASI`-nya masih salinan mentah template pH (baris 4
menulis "pH Meter"), jadi nomor formulir lembar kerja Gas Detector tidak
tercatat di mana pun.

**Yang dipakai sekarang:** `kode_dokumen` lembar kerja dikosongkan (null).
Nomor formulir itu dokumen terkendali; mengarang satu yang "kelihatan pas"
berarti menaruh nomor palsu di kertas yang ikut diaudit.

**Yang diminta:** nomor formulir lembar kerja Gas Detector (SIDIK-FM-CAL-05xx
berapa, revisi berapa). Yang diganti satu konstanta
(`GasDetectorProfile::KODE_DOKUMEN`).

## 2. Pembagi komponen tekanan: label `rect.` tapi selnya √5

Komponen "Ketidakpastian Pengaruh Tekanan" ditandai distribusi `rect.` di
kolom J — dan distribusi persegi pembaginya √3. Tapi selnya bilang lain:
`P13 = 5` dengan `T13 = M13/SQRT(P13)`, alias **√5 ≈ 2,236**. Baris suhu tepat
di atasnya menulis `P12 = SQRT(3)` — pola beda di dua baris bersebelahan,
konsisten di keempat blok gas.

**Yang dipakai sekarang:** √5, ikut angka sel — itu yang mereproduksi `uc`
keempat gas. Konsisten empat kali berarti disengaja, bukan sel yang keliru
sekali.

**Yang ditanyakan:** yang mana yang dimaksud — pembaginya yang benar (dan
label `rect.`-nya yang perlu dibetulkan), atau sebaliknya? Kalau seharusnya
√3, U95 keempat gas naik sedikit dan
`GasDetectorProfile::PEMBAGI_TEKANAN` tinggal diganti 3.

## 3. CMC Gas Detector kosong — belum ada klaim terakreditasi?

Kolom CMC di `DATABASE!S5:S8` kosong seluruhnya untuk keempat gas, dan sel
`CMC Laboratory` di keempat blok U95 membacanya sebagai 0 — jadi
`MAX(U, 0) = U` dan yang dilaporkan selalu ketidakpastian hitung.

**Yang dipakai sekarang:** baris kemampuan dengan `ketidakpastian_terbaik = 0`
(nol = tidak ada klaim, bukan klaim nol; jejak audit menulisnya "tanpa lantai
CMC").

**Yang ditanyakan:** apakah Gas Detector memang belum masuk ruang lingkup
akreditasi? Kalau sudah/akan, kirimkan CMC per gas — `GasDetectorCapabilitySeeder`
dipecah jadi empat baris dengan angkanya masing-masing.

## 4. `k` di sertifikat diambil dari blok O2 saja

`SERTIFIKAT!U28` (kalimat "…Coverage Factor(k) =") membaca
`'PERHITUNGAN U95%'!AB71` — sel `k` **blok O2**. Padahal keempat gas punya
`k`-nya sendiri: 1,9715 / 2,0106 / 1,9744 / 1,9717, dan yang paling menjauh
(H2S, 2,0106) justru tidak pernah tercetak.

**Yang dipakai sekarang:** tiap baris membawa `k`-nya sendiri
(`faktor_cakupan_k` per titik), seperti alat lain yang U95-nya per baris.
Kalimat penutup sertifikat memakai nilai per baris juga.

**Yang ditanyakan:** apakah satu `k` untuk seluruh sertifikat memang yang
dimau (dan yang mana), atau per gas? Ini murni soal bentuk cetak — angka
U95-nya sendiri sudah dihitung dengan `k` masing-masing di kedua pilihan.

---

## Tambahan: dua kejanggalan master yang TIDAK ditiru (tercatat, tidak perlu jawaban)

| Sel master | Isinya | Yang dipakai di sistem |
|---|---|---|
| `INPUT DATA` T24:T27 (label standar) | "pH 4 / pH 7 / pH 10 / thermo&sensor" — sisa template pH | Isinya (`Standar Gas Mixture (CO)` dst), bukan labelnya |
| `PERHITUNGAN U95%` M28/M46 (resolusi H2S & CH4) | `$G$6*0.5` — dikunci ke sel resolusi **CO** | Resolusi per gas (`GasDetectorProfile::GAS`); kebetulan sama (1) di alat contoh, beda begitu ada alat dengan resolusi H2S 0,1 |
