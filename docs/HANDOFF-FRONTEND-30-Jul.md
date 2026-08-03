# Handoff Frontend — koreksi suhu buffer pH, 30 Juli 2026

Lanjutan dari [`HANDOFF-FRONTEND-29-Jul.md`](HANDOFF-FRONTEND-29-Jul.md). Satu perubahan
saja, tapi dia ngubah **arti** salah satu field yang mobile kirim & terima, jadi tolong
dibaca sampai bagian §2.

Semua angka di dokumen ini hasil uji langsung lewat API lawan server, dibandingin ke
lembar olah data manual lab (sertifikat `0558-CAL-525`). Bukan dari perhitungan di atas
kertas.

Rincian teknis & alasannya di sisi backend: commit `6357611`.

---

## 1. Apa yang berubah

**Nilai larutan buffer sekarang dikoreksi ke suhu pengukuran, bukan dipakai nominal
botolnya.**

pH larutan buffer berubah sama suhu. Sertifikat buffernya ngasih kurva koreksinya, dan
kurva itu udah lama kesimpen di database (`standards.koefisien_suhu`). Yang belum: nggak
ada yang manggil kurva itu waktu ngitung. Jadi sistem pakai angka nominal yang diketik
teknisi — dan yang diketik teknisi itu angka di botol.

Contoh nyata, buffer pH 10 diukur pada 25,3 °C:

```
Seharusnya : 10.01 (botol) --[kurva suhu]--> 9.9451681 --> koreksi -0.0554319
Sebelumnya : 10.01 (botol) ---------------> 10.0100000 --> koreksi +0.0094000
                                  ^
                           langkah ini dilewati
```

Dampaknya ke tiga titik pH, dibanding lembar manual:

| Titik | Koreksi manual | Koreksi sebelumnya | Selisih |
|---|---|---|---|
| pH 4 | −0.0038393 | −0.0096000 | 0.0058 |
| pH 7 | −0.0231800 | **+0.0004000** | 0.0236 — kebalik tanda |
| pH 10 | −0.0554319 | **+0.0094000** | 0.0648 — kebalik tanda |

Yang pH 10 itu 0,065 pH di alat bertoleransi 0,2 — **sepertiga anggaran toleransi**,
cukup buat bikin alat yang seharusnya FAIL kelihatan PASS.

Sesudah diperbaiki, ketiga titik cocok sampai 7 desimal sama lembar manual.

> Ini bukan rumus yang salah. Rumusnya bener dan hasilnya udah persis Excel-nya lab
> (`4.0057607 / 6.9764200 / 9.9451681`, ketiganya cocok) — dia cuma nggak kesambung ke
> jalur hitung. Nggak ada error, nggak ada warning, dan seluruh test hijau. Yang bikin
> ketahuan cuma satu: hasilnya dibandingin ke lembar manual.

---

## 2. ⚠️ Yang perlu dicek di sisi frontend

### 2a. `titik_ukur` yang balik BEDA dari yang dikirim — dan itu benar

Ini yang paling gampang bikin salah paham.

| | Isinya | Siapa yang nentuin |
|---|---|---|
| `measurements[].titik_ukur` (**request**) | Nominal botol: `4.01`, `7.00`, `10.01` | Teknisi ngetik |
| `data.titik[].titik_ukur` (**response**) | Nilai terkoreksi: `4.0057607`, `6.9764200`, `9.9451681` | Backend nurunin dari kurva + suhu |

Jadi: kirim `10.01`, yang balik `9.9451681`. **Itu bukan bug.**

Yang tolong **jangan** dilakukan:

- ❌ Jangan dipakai buat validasi bahwa kiriman gagal / datanya berubah
- ❌ Jangan "diperbaiki" balik ke nilai yang dikirim
- ❌ Jangan dipakai buat nge-refill kolom "Solution Standard" di formulir — kolom itu
  isinya nominal botol. Kalau di-refill pakai nilai terkoreksi, teknisi yang buka lagi
  sesinya bakal lihat `9.9451681` di kolom yang seharusnya `10.01`, dan kalau dia kirim
  ulang, koreksi suhunya kena dua kali.

Nominal botolnya **nggak ada di response**. `pembacaan_mentah[]` cuma ngirim `pembacaan`,
`suhu`, `tahap`, `is_verified`, `input_source`, `photo_path` — nggak ada `titik_ukur`.
Jadi nominal itu cuma ada di sisi mobile (yang teknisi ketik). Simpan lokal kalau layarnya
butuh nampilin dua-duanya.

### 2b. Kolom suhu per pembacaan sekarang PENENTU HASIL, bukan pelengkap

Dulu kolom °C di sebelah tiap pembacaan itu dokumentasi. Sekarang **dia yang nentuin
nilai acuan**, jadi dia ikut nentuin koreksi dan PASS/FAIL.

Aturannya di backend:

- Suhu terisi → nilai acuan diturunin dari kurva. **Benar.**
- Suhu kosong → jatuh ke nominal botol. **Koreksinya salah, tanpa peringatan apa pun.**

Yang diambil rata-rata suhu dari baris yang **beneran ikut dihitung** — baris yang
pembacaannya kosong nggak nyeret suhunya ke rata-rata.

**Yang perlu dibangun:** buat alat pH, jangan biarkan lembar dikirim dengan kolom suhu
kosong. Minimal peringatan yang jelas sebelum kirim; idealnya diwajibkan. Konsekuensi
salahnya nggak kelihatan di layar mana pun — sertifikatnya terbit rapi dengan angka yang
masuk akal tapi salah.

> Backend sengaja **nggak** nolak lembar tanpa suhu (`422`). Alasannya sama kayak kolom
> lain di lembar kerja: teknisi di lapangan nggak boleh keblokir. Yang nahan itu
> pemeriksaan admin sebelum sertifikat terbit. Tapi itu artinya **layar mobile yang jadi
> pertahanan pertama** — dan satu-satunya yang bisa negur teknisinya waktu dia masih di
> depan alat.

### 2c. Jangan hitung koreksi sendiri di mobile

Kalau ada preview/perhitungan lokal, hasilnya bakal beda — kurva suhu buffer cuma ada di
database backend, mobile nggak punya koefisiennya.

Pakai `POST /calibrations/preview`. Body-nya sama persis kayak `POST /calibrations`, nggak
nyimpen apa pun, dan **udah ikut jalur hitung yang sama** — jadi angka preview identik
dengan yang bakal kecetak di sertifikat.

### 2d. Saran tampilan: tunjukin dua-duanya

Kalau layoutnya muat, tampilkan nominal → terkoreksi berdampingan:

```
Solution Standard : 10.01  →  9.9452 @ 25.3 °C
Reading (average) : 10.0006
Correction        : -0.0554
```

Teknisi yang lihat itu langsung ngerti kenapa koreksinya lebih besar dari dugaannya.
Kalau cuma lihat satu angka, dia bakal ngira sistemnya salah — dan laporan "koreksinya
kok aneh" yang sebenernya benar itu mahal buat dua sisi.

---

## 3. Yang TIDAK berubah

- **Alat non-pH nggak kena sama sekali.** Standar tanpa `koefisien_suhu`, atau titik tanpa
  suhu larutan, balik ke perilaku lama persis. Layar kalibrasi alat lain nggak perlu
  disentuh.
- **Bentuk response nggak berubah** — nggak ada field baru, nggak ada yang dihapus, tipe
  & struktur sama. Yang berubah cuma **nilai** `data.titik[].titik_ukur` dan turunannya
  (`koreksi`, `error`).
- **U95% praktis sama.** Di presisi sertifikat (3 desimal) angkanya identik sama manual:
  `0.023 / 0.021 / 0.031`. Nggak ada yang perlu disesuaikan di layar sertifikat.
- **Sertifikat yang udah terbit nggak ikut berubah** — snapshot-nya beku. Kalau ada yang
  perlu diterbitkan ulang, itu keputusan mutu, bukan keputusan teknis.

---

## 4. Yang masih nggantung (bukan urusan mobile, tapi enak kalau tau)

`U95%` di presisi sertifikat udah cocok, tapi **cara nyampenya masih beda** dari lembar
manual:

| | Manual lab | Sistem sekarang |
|---|---|---|
| Komponen budget | 5 (sertifikat kalibrator, daya baca, suhu × koef. sensitivitas, pengaruh perbedaan suhu, pengulangan) | 2 (CMC + pengulangan) |
| Faktor cakupan `k` | student's t dari Welch-Satterthwaite (mis. 1.9718) | dipatok 2 |

Sekarang hasilnya cocok karena lantai CMC yang dominan. Begitu ada alat yang pembacaannya
lebih berserak, angkanya mulai geser dari manual. Budget penuhnya udah ditulis tapi belum
masuk — masih di branch terpisah, dan itu pekerjaan tersendiri.

Artinya buat mobile: **nggak ada yang perlu dikerjain sekarang**, tapi kalau nanti U95%
mulai keluar angka yang nggak bulat kayak `0.031`, itu bukan bug tampilan — itu budget
penuhnya udah masuk.

---

## Kalau ada yang kelihatan aneh

Rantai penuh pH → sertifikat bisa diuji dari sisi API, tanpa lewat app:

```bash
python docs/skrip/e2e-ph.py http://127.0.0.1:8000/api sertifikat.pdf
```

Keluarnya `SEMUA MATA RANTAI TERSAMBUNG` atau `PUTUS DI: <langkah>` + sebab + petunjuk.
Ini yang paling cepat mbedain "bug mobile atau backend" sebelum ngulik layar.
