# Perintah Frontend — Viscometer

Tempel bagian di bawah garis ini ke sesi kerja frontend. Sudah lengkap; tidak
perlu menjelaskan ulang konteksnya.

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Backend modul **Viscometer** (alat ke-7, viscometer rotasi Brookfield, satuan
cP, metode `SIDIK-IK-CAL-0517_Rev.3`) sudah selesai dan sudah lulus test di
SQLite maupun MySQL. Tugasmu menyambungkan sisi frontend. Kontrak lengkapnya ada
di `docs/handoff-backend-viscometer.md` di repo API — **baca itu lebih dulu dan
perlakukan sebagai satu-satunya sumber kebenaran.** Dokumen ini hanya ringkasan
kerjanya.

## ATURAN PALING PENTING

1. **Frontend TIDAK menghitung apa pun.** Tidak ada rata-rata, tidak ada STDEV,
   tidak ada interpolasi suhu, tidak ada MPE, tidak ada pembulatan yang mengubah
   nilai. Semua angka datang dari API.
2. **Jangan hardcode titik ukur, satuan, resolusi, jumlah kolom pengulangan,
   atau jumlah desimal.** Semuanya datang dari
   `GET /api/calibrations/lembar-kerja?profil=viscometer&equipment_id={id}`.
3. Kalau ada yang ambigu antara kontrak dan kenyataan respons API, **berhenti
   dan tanya**. Jangan menambal dengan asumsi.

## YANG BEDA DARI ENAM ALAT SEBELUMNYA — ini sumber bug-nya

### 1. Spindle dan RPM WAJIB, dan berbeda PER TITIK

Ini yang paling penting di alat ini. Batas keberterimaan Viscometer bukan satu
angka di data alat — kolom `equipments.toleransi` alat ini memang `NULL`.
Batasnya dihitung dari spindle & RPM titik itu:

```
Fullscale = TK × SMC × 10000 / RPM
MPE       = 1 % × Fullscale + 1 % × rata-rata pembacaan
```

Sesi contoh dari lab memakai **tiga spindle berbeda dan dua RPM berbeda** dalam
satu sesi: HA1 @63 rpm, HA2 @62 rpm, HA7 @62 rpm. `SMC` spindle HA1 itu 1 dan
HA7 itu 400 — salah pilih menggeser Fullscale **400 kali lipat**.

Kirim per baris pengukuran:

```json
"measurements": [
  { "titik_ukur": 99.65, "spindle": "HA1", "rpm": 63, "pembacaan": [...], "suhu": [...] },
  { "titik_ukur": 1018,  "spindle": "HA2", "rpm": 62, "pembacaan": [...], "suhu": [...] },
  { "titik_ukur": 59003, "spindle": "HA7", "rpm": 62, "pembacaan": [...], "suhu": [...] }
]
```

Kalau salah satunya kosong, titik itu tetap dihitung tapi `toleransi` dan
`keputusan` keluar `null`. **Tampilkan itu sebagai "belum divonis", jangan
sebagai PASS dan jangan sebagai FAIL.** Backend sengaja tidak mengarang batas.

Spindle **wajib dropdown**, bukan isian bebas — 63 pilihan datang dari
`bagian[kode=hasil].field[spesifikasi_alat.spindle_titik_N].pilihan`, dan
labelnya sudah menyebut SMC-nya (`HA7 (SMC 400)`).

### 2. Model viscometer dipilih sekali per sesi, dan menentukan TK

`bagian` dengan `kode: "model_visco"` mengirim satu field pilihan berisi 12
model (`spesifikasi_alat.model_visco`). Labelnya `DV2THA / HA (TK 2)` — nama
badan alat, nama di layar alat, dan nilai TK-nya.

Kirim di `spesifikasi_alat.model_visco`. Wajib dropdown, alasan yang sama dengan
spindle.

### 3. Setiap sel minta DUA angka: pembacaan dan suhu larutan

Tabel di `bagian[kode=hasil].tabel[]` punya `kolom` berisi dua entri:
`pembacaan` (cP) dan `suhu` (°C). Dua-duanya diisi per pengulangan.

Kolom suhu **bukan hiasan**: nilai acuan tiap titik diinterpolasi dari tabel
sertifikat larutan pada suhu rata-rata titik itu. Larutan 60000 cP itu 95192 cP
pada 20 °C dan 19259 cP pada 37,78 °C. Suhu kosong = acuan meleset jauh.

Kirim sebagai dua array sejajar:

```json
{ "pembacaan": [97.3, 96.9, 96.8, 95.9, 96.7],
  "suhu":      [26.6, 26.5, 26.5, 26.6, 26.4] }
```

### 4. Dua tabel: Before dan After Adjustment

`tabel[]` berisi dua blok dengan `tahap` `sebelum_adjustment` dan
`sesudah_adjustment`, masing-masing 3 baris × 5 pengulangan. Bentuknya identik.

### 5. Blok larutan 30000 cP ADA di lembar tapi tidak menerima input

`bagian` dengan `kode: "standar_30000"` sengaja kosong dan bertanda
`sumber_belum_ada`. Tampilkan sebagai blok nonaktif dengan catatannya, jangan
disembunyikan — teknisi yang memegang kertas Rev.3 akan mencarinya, dan blok
yang hilang tanpa penjelasan bikin orang mengira aplikasinya rusak. Jangan
kirim apa pun untuk blok ini.

### 6. Angka di satu lembar bedanya tiga orde magnitudo

96 cP, 918 cP, 63181 cP dalam satu lembar. Konsekuensi buat layar:

- Jangan pakai satu lebar kolom untuk ketiga baris. Baris 60000 cP butuh ruang
  tujuh karakter.
- Jangan pasang validasi rentang sendiri di frontend. Pita per baris sudah
  dikirim backend di template pindai; kalau kamu menambah pita sendiri,
  pembacaan sah pada suhu tinggi (larutan 1000 cP jadi 419,5 cP pada 37,78 °C)
  akan ditolak layar sebelum sempat dikirim.

### 7. Resolusi ditulis PER TITIK

Kertas Rev.3 eksplisit: *"Resolusi tuliskan pada masing-masing titik
kalibrasi"*. Tiga field `spesifikasi_alat.resolusi_titik_N` ada di
`bagian[kode=hasil]`. Ini catatan lapangan — tidak dipakai menghitung sampai lab
memastikan ada alat yang resolusinya memang berbeda per titik.

## Lembar pindai foto (OCR)

Lembar Viscometer **satu-satunya yang lanskap** dari tujuh alat. Ambil ukuran
kertas dari `ukuran_referensi` di template (`2339 × 1654 @200 dpi`) — jangan
mengasumsikan potret seperti enam alat lain, karena perbaikan perspektif
(homography) memakai ukuran itu sebagai ruang tujuan.

`terverifikasi` masih `false`. Selama false, **semua** hasil pindai wajib lewat
layar review sebelum masuk lembar kerja. Jangan ada jalur auto-isi.

Tiga perilaku yang harus dipertahankan di layar review, dan ketiganya sudah
dijamin backend — tugas frontend cuma menampilkannya apa adanya:

| Yang dibaca kamera | Yang terjadi | Yang ditampilkan |
|---|---|---|
| `631.74.2` (dua titik desimal) | ditolak, nilai `null` | sel **merah**, minta teknisi ketik ulang |
| `63.181` di baris 60000 cP | dibaca `63181` lewat bukti pita | sel kuning, nilai terisi |
| `63181.3` di baris 100 cP | ditolak (`di_luar_rentang`) | sel **merah** |

Jangan menampilkan tebakan untuk sel merah. Sel merah artinya backend menolak
mengarang angka, dan layar yang "membantu" dengan menawarkan tebakan membatalkan
seluruh gunanya.

Angka hasil tulisan tangan **tidak pernah hijau**, paling tinggi kuning. Itu
disengaja: skor OCR untuk tulisan tangan tetap tinggi waktu salah.

## Sertifikat

Kolom tabelnya: `Standard Value (cP)`, `Unit Under Test (cP)`,
`Correction (cP)`, `U95%, k=2 (cP)`. Dua desimal — ambil dari field `desimal`
di respons, jangan dihardcode.

Di luar tabel, sertifikat mencetak `Spindel No.` (mis. `1,2,7`) dan
`Speed (rpm)` (mis. `63,62,62`) — dua-duanya dirangkai backend dari spindle &
RPM per titik yang kamu kirim.
