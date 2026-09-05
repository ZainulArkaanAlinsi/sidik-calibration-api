# Formulir keputusan — temuan Micrometer §1, §3, §9

**Untuk:** Manajer Teknis, Lab Kalibrasi PT Sidik
**Perihal:** Tiga temuan pada alat Micrometer (lampiran akreditasi LK-285-IDN no. 34, kelompok Panjang)
**Disiapkan:** 5 September 2026

---

## Kenapa berkas ini ada

`docs/pertanyaan-lab-micrometer.md` memuat sebelas temuan; `docs/analisis-pertanyaan-lab-micrometer.md`
memuat analisis teknisnya. Keduanya panjang karena memang harus — tiap angka di
sana bisa diulang dari berkas di repo.

Berkas **ini** beda: dia satu halaman, isinya cuma yang perlu **diputuskan**,
dan tiap butirnya sudah punya usulan yang tinggal disetujui atau ditolak.
Sisanya sudah dikerjakan sistem dan tidak perlu keputusan siapa pun.

Ketiga temuan menunjuk ke **satu sertifikat yang sama**: `095-CAL-324`
(Micrometer Mitutoyo Analog, serial `IMTE-FQS-015`, PT Unilever Indonesia Tbk,
terbit 14 Maret 2024).

---

## Yang SUDAH beres, tanpa perlu keputusan

| Hal | Keadaan sekarang |
|---|---|
| Sesi baru dengan kapasitas di luar keempat pita CMC | **Ditahan** sistem, tidak menghasilkan satu pun baris hitungan |
| Sesi baru dengan pra-evaluasi seragam (simpangan baku nol) | **Ditahan** sistem, alasannya kebaca di `belum_dihitung` |
| Sesi baru dengan resolusi alat kosong | **Ditahan** sistem |
| Desimal koreksi di sertifikat | **Lima desimal**, bukan tiga seperti format sel master |

Semuanya dijaga test dan tidak bisa jebol diam-diam. **Yang tersisa cuma
perlakuan atas sertifikat yang sudah terlanjur terbit ke pelanggan** — dan itu
yang tidak bisa diputuskan sistem.

---

## Keputusan 1 — §1: U95 terbit di bawah lantai CMC

**Fakta.** Sertifikat `095-CAL-324` terbit dengan U95 **0,735 µm**, sementara
pita CMC terakreditasi untuk rentang 0-25 mm adalah **0,83 µm**. Yang
dinyatakan ke pelanggan **lebih kecil** daripada yang diakui KAN.

**Kenapa ini serius, bukan sekadar rapi-rapi.** Ketidakpastian yang dinyatakan
terlalu kecil membuat alat tampak **lebih baik** dari yang bisa dipertanggungjawabkan.
Kalau ada pernyataan kesesuaian di sertifikat itu, keputusan lulus/tidaknya bisa
berbalik saat dihitung dengan U yang benar. Arah kesalahannya merugikan pelanggan,
bukan lab.

**Usulan:** perlakukan sebagai **pekerjaan tidak sesuai** (ISO/IEC 17025, klausa
Pekerjaan Tidak Sesuai), lalu lingkupi seluruh arsip dan nilai per sertifikat.

Untuk melingkupi, jalankan di server:

```bash
php artisan micrometer:audit-cmc --csv=/tmp/audit-micrometer.csv
```

Perintah itu menyisir **seluruh** arsip lintas organisasi dan memulangkan tiap
sesi Micrometer yang U95-nya di bawah lantai pitanya, keterulangannya nol, atau
resolusinya kosong. Keluarannya bahan rapat, bukan daftar penarikan.

> [ ] **Setuju** — angkat sebagai ketidaksesuaian dan jalankan pelingkupan
> [ ] **Tidak setuju** — alasan: ......................................................
> [ ] **Perlu dibahas dulu** dengan: ..................................................

---

## Keputusan 2 — §3: pra-evaluasi berisi 635,0 sepuluh kali

**Fakta.** Blok pra-evaluasi sesi yang sama berisi satu nilai disalin sepuluh
kali (635,0 = 25 × 25,4 — kapasitas alat yang ikut terkonversi bug satuan inch).
Simpangan bakunya **nol**, jadi komponen keterulangan hilang dari budget.

Ketiga sesi Micrometer lain punya sebaran wajar (3,2 × 10⁻⁴ sampai 5,3 × 10⁻⁴ mm),
jadi ini bukan sifat alatnya — ini kerusakan data pada satu sesi.

**Ini bukan temuan kedua yang terpisah.** Dia sebab-akibat dari §1 pada
sertifikat yang sama, dan satu tinjauan ketidaksesuaian mencakup keduanya.

**Yang perlu lab jawab — satu pertanyaan fakta:**

> **Apakah lembar kerja asli sesi `095-CAL-324` masih ada?**
>
> [ ] **Ada** → sepuluh pembacaan pra-evaluasi yang sebenarnya bisa dimasukkan
> kembali, sesinya dihitung ulang, dan §1 terjawab dengan angka — bukan perkiraan.
> Kirim lembarnya, sisanya urusan sistem.
>
> [ ] **Tidak ada** → sesi itu **tidak bisa** dipulihkan secara metrologis.
> Keterulangan adalah dasar seluruh budget; menggantinya dengan angka lain berarti
> mengarang. Yang tersisa: kalibrasi ulang alatnya, atau tarik sertifikatnya tanpa
> pengganti.

---

## Keputusan 3 — §9: sertifikat master mencetak koreksi `0,000`

**Fakta.** Sel `SERTIFIKAT!D18:L28` di workbook master berformat `0.000`, jadi
cetakannya menampilkan koreksi **0,000 pada kesebelas titik** dan U95 sebagai
**0,001**. Koreksi sebenarnya berorde 10⁻⁴ mm.

**Angkanya tidak salah — yang salah tampilannya.** Nilai di dalam sel benar; yang
tercetak dibulatkan sampai seluruh informasinya hilang. Pelanggan yang menerapkan
koreksi ke pembacaan alatnya mendapat sertifikat yang, buat keperluan itu, kosong.

Ini juga melanggar prinsip GUM bahwa hasil dilaporkan sampai desimal yang sama
dengan ketidakpastiannya.

**Sisi sistem sudah benar** sejak PR #172 — lima desimal, dijaga test.

**Usulan:**

> [ ] **Betulkan format sel master** ke lima desimal, supaya workbook dan sistem
> berhenti mencetak angka berbeda untuk sesi yang sama
>
> [ ] **Terbitkan ulang atas permintaan** untuk sertifikat lama — beda dari §1,
> di sini tidak ada klaim yang salah, jadi penarikan proaktif kemungkinan berlebihan
>
> [ ] **Kecuali** untuk pelanggan yang memang memakai kolom koreksi; buat mereka
> penerbitan ulang sebaiknya **ditawarkan lebih dulu**.
> Siapa saja mereka, cuma lab yang tahu: ..............................................

---

## Yang TIDAK diputuskan di sini

**§11 — komponen termal lenyap karena satuan `ci`.** Kalau dibetulkan, U95 sesi
25-50 mm naik dari 0,872 ke **0,978 µm — di atas pita CMC 0,87 µm**. Artinya
temuan itu bisa menggeser lampiran akreditasi, bukan cuma satu sertifikat. Dia
butuh pembahasan sendiri dan dua fakta lab yang belum ada (kendali suhu ruang
Lab Dimensi, dan apakah `delta_alpha_per_c = 1e-5` itu α atau δα).

Dibahas terpisah di `docs/analisis-pertanyaan-lab-micrometer.md` §11. **Jangan
digabung ke tinjauan §1** — nanti yang satu menyandera yang lain.

---

## Tanda tangan

Sistem sudah menahan ketiga cacat ini untuk sesi baru. Yang di bawah ini
menyangkut sertifikat yang **sudah di tangan pelanggan**, dan tidak ada
perangkat lunak yang boleh memutuskannya.

| | Nama | Tanda tangan | Tanggal |
|---|---|---|---|
| Disiapkan | (sistem — analisis teknis) | — | 5 Sep 2026 |
| Ditinjau | .......................... | .................. | ............ |
| Diputuskan | Manajer Teknis .......... | .................. | ............ |

Sesudah diputuskan: pindahkan keputusannya ke `docs/pertanyaan-lab-micrometer.md`
dan tandai §1, §3, §9 sebagai **[SUDAH DIJAWAB]**, supaya sesi kerja berikutnya
tidak menanyakannya lagi.
