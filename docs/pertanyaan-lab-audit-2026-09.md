# Pertanyaan lab dari audit bug 2 Sep 2026

Dua hal yang **tidak bisa diputuskan dari kode**. Keduanya menyangkut angka atau
tulisan di dokumen terakreditasi, dan menebaknya berarti menulis sesuatu ke
sertifikat pelanggan berdasarkan tebakan.

Status: **menunggu jawaban.** Selama belum dijawab, kodenya berperilaku seperti
yang ditulis di bawah — dan perilaku itu dipilih supaya yang salah kelihatan,
bukan supaya diam.

---

## T1 — Termometer Gelas: satu pembacaan UUT itu sah atau tidak?

**Konteks.** Empat kalkulator suhu di sistem ini menuntut minimal dua pembacaan
di kedua sisi (standar & UUT), kecuali satu:

```
ThermometerGlassCalculator   count($standar) < 2 || count($uut) < 1   ← sebelum diperbaiki
ThermocoupleCalculator       count($standar) < 2 || count($uut) < 2
ThermohygroCalculator        count($standar) < 2 || count($uut) < 2
```

**Kenapa itu masalah, apa pun jawabannya.** `stdev()` memulangkan `0.0` untuk
n < 2, dan nilai itu masuk komponen budget `pengulangan_uut` yang
`'disertakan' => true` tanpa syarat. Jadi satu pembacaan tidak menghasilkan
"tidak ada sebaran" — dia menghasilkan **"sebarannya nol"**, dan U95% yang
tercetak jadi lebih kecil dari yang bisa dipertanggungjawabkan.

**Yang sudah dilakukan.** Ambangnya disamakan dengan tiga saudaranya
(`count($uut) < 2`). Titik yang belum punya dua pembacaan **ditahan dari
perhitungan dengan alasan yang kebaca** — bukan menolak kirimannya: sesinya
tetap tersimpan, teknisi tidak terhalang di lapangan, dan penerbitan
sertifikatnya yang ketahan.

**Yang ditanyakan.** Apakah prosedur Termometer Gelas memang hanya mengambil
SATU pembacaan UUT (misalnya karena dibaca sekali pada kesetimbangan)?

- **Kalau TIDAK** — tidak ada yang perlu diubah lagi. Ambang 2 sudah benar.
- **Kalau IYA** — yang benar bukan mengembalikan `< 1`, tapi **mengeluarkan
  komponen `pengulangan_uut` dari budget** untuk alat ini (`'disertakan' =>
  false`). Menyimpan nolnya berarti budgetnya mengklaim komponen yang tidak
  pernah diukur, dan itu justru yang bikin U95-nya terlalu kecil.

Yang perlu dari lab: satu kalimat di prosedur/IK Termometer Gelas yang menyebut
berapa kali UUT dibaca per titik.

---

## T2 — Gas Detector: tekanan dicetak di baris Kondisi Lingkungan atau tidak?

**Konteks.** `CertificateSnapshotBuilder::kondisiLingkungan()` merakit baris
Kondisi Lingkungan dari dua cabang saja — suhu dan kelembaban. Untuk Gas
Detector, `tekanan_udara` **dihitung, disimpan, dan dipakai sebagai komponen
budget ketidakpastian** — lalu tidak ikut tercetak di baris yang justru
menyatakan kondisi lingkungan pengukurannya.

**Kenapa tidak ditebak.** Tiga hal harus datang dari master lab, dan salah satu
saja meleset berarti angka yang salah di dokumen terakreditasi:

1. **Dicetak atau tidak?** Mungkin memang sengaja tidak — sebagian lab hanya
   mencantumkan tekanan di lembar data, bukan di sertifikat.
2. **Satuannya apa?** hPa, mbar, atau kPa. Nilainya tersimpan dalam satu satuan;
   mencetak angkanya dengan label satuan yang berbeda adalah kesalahan yang
   tidak kelihatan.
3. **Berapa desimal, dan pakai `±` atau tidak?** Baris suhu ditulis
   `21,0 °C ± 1,7 °C`. Tekanan mengikuti pola yang sama, atau tanpa
   ketidakpastian?

**Yang sedang berlaku.** Tidak ada yang berubah — tekanannya tetap tidak
tercetak. Itu keadaan sebelum audit, dan sengaja dibiarkan: mencetak angka yang
formatnya salah lebih buruk daripada tidak mencetak.

Yang perlu dari lab: satu contoh sertifikat Gas Detector yang sudah terbit, atau
baris Environmental Condition dari master-nya.

---

## Cara menjawab

Balas di isu/PR-nya, atau langsung sunting berkas ini: ganti judul
pertanyaannya jadi `## T1 — SUDAH DIJAWAB: <ringkasan>` dan tulis jawabannya di
bawah. Yang penting jawabannya tinggal di repo, bukan di percakapan — supaya
orang berikutnya tidak menanyakan hal yang sama.
