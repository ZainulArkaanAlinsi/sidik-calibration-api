# Handoff Frontend — Conductivity Meter

Backend modul Conductivity Meter (alat ke-5) sudah jadi dan lulus golden test
lawan `Master Olah Data_Conductivity.xlsm` job `0189-CAL-524`. Dokumen ini yang
dibutuhkan frontend buat nyambung.

Tidak ada endpoint baru. Conductivity masuk lewat jalur yang sama dengan pH,
Turbidimeter, Chlorine, dan Refractometer — yang beda cuma **bentuk lembar
kerjanya**, dan bentuk itu datang dari backend.

---

## 1. Ambil bentuk lembar kerja

```
GET /api/calibrations/lembar-kerja?profil=conductivity_meter
GET /api/calibrations/lembar-kerja?instrumen=Conductivitymeter
```

Opsional: `&pengulangan=3` buat ngecilin jumlah kolom repeat (default 5, batas
2–10). Angka ini murni soal berapa kotak yang digambar — rumusnya selalu ngikut
berapa kotak yang beneran diisi.

Respons otomatis menyesuaikan peran yang login:

- **teknisi** → field ber-`hanya_admin: true` sudah dibuang dari respons
- **admin** → dapat semua field, plus satu bagian tambahan `administratif`

Frontend **tidak perlu** menyaring sendiri. Kalau field muncul di respons,
berarti user ini boleh mengisinya.

---

## 2. Yang beda dari alat lain — baca bagian ini

### 2a. Satuan campur dalam satu lembar

Ini satu-satunya alat yang memakai dua satuan berbeda di lembar yang sama.
Bentuknya membawa penanda:

```json
{ "satuan": null, "satuan_campuran": true }
```

Jangan ambil satuan dari level lembar. **Satuan mengikat per baris tabel
hasil**, di field `baris[].satuan`.

| Titik | Satuan | Resolusi | Desimal cetak |
|---|---|---|---|
| 25 | µS/cm | 0,1 | 1 |
| 1412 | µS/cm | 1 | 0 |
| 1,412 | mS/cm | 0,001 | 3 |
| 111 | mS/cm | 0,01 | 2 |

`desimal` dipakai buat mad angka tanpa membuang nol belakang — `25,0` tetap
`25,0`, bukan `25`.

### 2b. Titik tengah punya dua baris yang saling meniadakan

Baris `1412 µS/cm` dan `1,412 mS/cm` adalah **botol larutan yang sama** dibaca
dalam dua satuan. Teknisi mengisi **salah satu**, tidak pernah dua-duanya.

Tiap baris membawa:

```json
{ "titik_ukur": 1412, "satuan": "µS/cm", "eksklusif_dengan": 1.412 }
{ "titik_ukur": 1.412, "satuan": "mS/cm", "eksklusif_dengan": 1412 }
```

**Yang harus frontend lakukan:** begitu salah satu baris mulai diisi, kunci
baris pasangannya (disable + kasih keterangan singkat). Tanpa ini, sistem
menerima dua nilai untuk satu botol dan sertifikatnya jadi ambigu.

Pilihan itu juga yang menentukan style sertifikat — backend yang memutuskan,
frontend tidak perlu mengirim apa-apa:

- titik tengah dalam **mS/cm** → sertifikat **style 1**
- titik tengah dalam **µS/cm** → sertifikat **style 2**

### 2c. Suhu larutan wajib, bukan opsional

Nilai acuan larutan **digeser ikut suhu** (beda dari turbidity/chlorine yang
dibaca nominal). Titik 111 mS/cm bergerak dari 111 ke 111,193568 pada 25,2 °C.

**Aturan validasi frontend:** kalau kolom `pembacaan` sebuah baris diisi, kolom
`suhu` di baris yang sama **wajib** diisi. Jangan biarkan lembar dikirim dengan
pembacaan tanpa suhu.

Alasannya konkret: master Excel-nya sendiri kena masalah ini. Polinomial suhu
titik 1,412 mS/cm dievaluasi pada T=0 waktu kolom suhunya kosong, dan hasilnya
`0,738 mS/cm` — bukan error, angka yang kelihatan wajar, dan **ikut tercetak**
di sertifikat. Backend menolak menghitung titik tanpa suhu; frontend sebaiknya
menahannya lebih dulu supaya teknisi tahu di lapangan, bukan setelah kirim.

### 2d. Tidak ada PASS/FAIL

Conductivity Meter **tidak divonis lulus/gagal**. Master Excel tidak punya satu
pun sel yang membandingkan hasil dengan batas keberterimaan, dan kedua sheet
sertifikatnya hanya mencetak `Correction` + `U95%` lalu berhenti.

Konsekuensinya untuk frontend:

```json
{ "hasil": { "keputusan": null } }
```

`keputusan` bernilai **`null`**, bukan `"PASS"` dan bukan `"FAIL"`.

**Jangan tampilkan badge PASS/FAIL** kalau nilainya null — tampilkan strip (`—`)
atau sembunyikan kolomnya. Ini berlaku untuk daftar sesi, detail sesi, dan
sertifikat. Alat lain (pH, Turbidimeter, Chlorine, Refractometer) tetap
mengirim PASS/FAIL seperti biasa, jadi komponennya harus bisa menangani
ketiganya.

---

## 3. Admin sekarang bisa mengedit lembar teknisi

Ini perubahan perilaku yang perlu frontend ikuti.

**Sebelumnya:** lembar berstatus `menunggu_approval` terkunci untuk semua orang.
Admin yang menemukan satu angka keliru harus `reject()` — lembar balik ke
teknisi, teknisi betulkan, submit ulang, admin review lagi.

**Sekarang:**

```
PUT /api/calibrations/{id}
```

| Status sesi | Teknisi | Admin |
|---|---|---|
| `draft` | ✅ | ✅ |
| `perlu_revisi` | ✅ | ✅ |
| `menunggu_approval` | ❌ 422 | ✅ **baru** |
| `disetujui` | ❌ 422 | ❌ 422 |

Admin mengedit **seluruh permukaan input** lewat endpoint yang sama: identitas
alat, data pemilik, kondisi lingkungan, seluruh tabel `measurements`, plus field
administratif (`nomor_order`, `calibration_method_id`, dst) yang memang tidak
pernah muncul di layar teknisi.

`disetujui` tetap terkunci untuk semua orang — sertifikatnya sudah terbit dan
sudah dikirim ke pelanggan. Perubahan di sana lewat jalur terbitkan-ulang.

**Yang harus frontend lakukan:** di panel admin, tampilkan tombol Edit pada sesi
berstatus `menunggu_approval` (dulu disembunyikan). Semua field terbuka.
Perubahan tercatat otomatis di audit log beserta siapa yang mengubah —
frontend tidak perlu mengirim apa-apa untuk itu.

Pesan 422 sekarang membedakan dua sebab, jadi bisa ditampilkan apa adanya:

- teknisi mencoba edit lembar `menunggu_approval` → *"…nggak bisa diubah teknisi. Minta admin yang ngedit…"*
- siapa pun mencoba edit lembar `disetujui` → *"…sertifikatnya udah terbit."*

---

## 4. Kondisi lingkungan

Tidak ada yang baru — mekanismenya sama dengan alat lain, tapi perlu diketahui
karena angkanya muncul di sertifikat.

Teknisi mengisi empat angka: suhu awal/akhir dan kelembaban awal/akhir. Backend
yang memilih titik kalibrasi thermohygro, menghitung koreksi, dan menghasilkan
`indexed_value`, `correction`, dan `u95_sertifikat`.

Field `suhu_ruang`, `kelembaban`, dan ketidakpastiannya **otomatis** — jangan
dibuat bisa diedit di form teknisi. Untuk sesi contoh: suhu 25,4/25,5 → tercetak
**25,8 °C**; kelembaban 54/55 → tercetak **51,33 %RH**.

---

## 5. Angka acuan buat QA frontend

Kalau frontend mau memastikan tampilannya benar, ini angka yang harus keluar
untuk sesi `2405.32.A.NK` (cocok dengan master Excel, sudah dikunci di
`ConductivityBudgetTest`):

| Titik | Standard Value | Unit Under Test | Correction | U95% |
|---|---|---|---|---|
| 25 µS/cm | 25,0 | 25,0 | −0,0 | ± 0,5 |
| 1412 µS/cm | 1411 | 1413 | −2 | ± 8 |
| 111 mS/cm | 111,19 | 110,67 | 0,52 | ± 1,70 |

Titik tengah **sengaja berbeda dari master Excel** (master: 1412 dan −1). Nilai
acuannya sekarang dikoreksi suhu — nilai internalnya 1410,84 dan −2,16, dicetak
0 desimal jadi 1411 dan −2. Lihat bagian 6.

Perhatikan pembulatannya berbeda per titik — itu bukan bug, itu format sel
master (`0.0` / `0` / `0.00`). Nilai internal disimpan presisi penuh; yang
dibulatkan hanya tampilan.

Faktor cakupan `k` dicetak sebagai **2** walaupun nilai hitungnya 1,97 — juga
mengikuti master.

---

## 6. Titik 1412 µS/cm sengaja beda dari master

Ini satu-satunya tempat sistem tidak mereproduksi master, dan itu disengaja.

Master mengetik `1412` untuk larutan ini tanpa koreksi suhu, padahal larutan
yang **sama persis** di kolom sebelahnya (varian mS/cm) dikoreksi dengan
polinomial `2E-13·T² + 27·T + 738` — dan sheet koefisien mendokumentasikan
polinomial itu tepat di bawah judul "DR 1412 μS/cm". Satu botol, dua perlakuan,
hanya satu yang punya dasar tertulis. Yang punya dasar itu yang dipakai.

Pada suhu sesi (24,92 °C) nilai acuannya jadi **1410,84** bukan 1412, sehingga
Correction bergeser dari −1 ke −2,16. Geseran 1,16 µS/cm itu jauh di dalam U95%
titiknya sendiri (±8,1) dan CMC-nya (5,1), jadi yang dilaporkan tetap
`1413 ± 8`. Budget ketidakpastiannya **tidak berubah sama sekali** — koefisien
sensitivitasnya dihitung dari nilai nominal.

Yang perlu frontend lakukan: tidak ada. Ini disebutkan supaya kalau ada yang
membandingkan hasil sistem dengan cetakan Excel lama dan menemukan selisih 1 di
titik tengah, jawabannya sudah tertulis di sini — bukan dikira bug.

Untuk sesi yang memakai varian **mS/cm** (sertifikat style 1), backend tetap
mengembalikan peringatan yang harus **ditampilkan ke admin** sebelum approve —
bukan karena rumusnya meragukan lagi, tapi karena jalur itu belum pernah diadu
dengan sesi nyata. Ini peringatan, bukan penghalang.

---

## Ringkasan checklist frontend

- [ ] Ambil bentuk dari `?profil=conductivity_meter`, jangan hardcode titik
- [ ] Baca satuan dari `baris[].satuan`, bukan dari level lembar
- [ ] Kunci baris pasangan lewat `eksklusif_dengan`
- [ ] Wajibkan kolom suhu kalau kolom pembacaan diisi
- [ ] Tangani `keputusan: null` — jangan tampilkan badge PASS/FAIL
- [ ] Pakai `desimal` per baris buat format angka
- [ ] Buka tombol Edit admin untuk sesi `menunggu_approval`
- [ ] Tampilkan peringatan varian mS/cm sebelum approve
