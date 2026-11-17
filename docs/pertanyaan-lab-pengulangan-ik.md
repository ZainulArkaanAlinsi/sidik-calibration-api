# Pertanyaan lab — pengulangan minimum menurut Instruksi Kerja (Micrometer & Timer/Stopwatch)

**Sumber:** audit sertifikat 16 Nov 2026 (G5, keputusan D1); `docs/permintaan-user-7.md` §55
pertanyaan terbuka no. 4; kode `CalibrationProfile::minPengulanganIK()`.
**Untuk:** Manajer Teknis Lab.
**Dari:** tim backend SIDIK, 17 November 2026.

Server sekarang MENEGAKKAN batas yang IK sebut — **Micrometer 5 pembacaan per titik,
Timer/Stopwatch 3 ulangan per set point** — sebagai default aman: draf tetap bisa disimpan
setengah jadi, tetapi sertifikat tidak terbit dari pengulangan di bawah batas itu. Longgarkan
HANYA dengan jawaban tertulis lab; angkanya satu konstanta per profil
(`MicrometerProfile::MIN_PENGULANGAN_IK`, `TimerStopwatchProfile::MIN_PENGULANGAN_IK`).

Butir di bawah **tidak menahan** kode; masing-masing punya default yang sudah dipakai.

---

## §1 — Apakah angka IK-nya memang BATAS BAWAH 5 dan 3? · **konfirmasi**

Yang kami pegang: kertas `SIDIK-FM-CAL-0522.*` (Micrometer, lima kolom `X1..X5`),
`SIDIK-FM-CAL-0512_Rev.4` (Timer, tiga ulangan), master (`PERHITUNGAN` kolom `I..M` dan blok
`Set Point` baris 1..3), dan kalimat "IK menyebut 3 (Timer) / 5 (Micrometer)" di
`permintaan-user-7.md` §55. Nomor halaman/butir IK-nya belum kami lihat sendiri.

**Yang kami lakukan:** angka itu jadi batas bawah. Di atasnya boleh (sampai 10 kolom, batas
lembar kerja yang sudah ada); di bawahnya `422` di lembar kerja & skema titik sesi, dan ERROR
`pengulangan_kurang_dari_ik` saat approve.

> [ ] Benar, 5 dan 3 adalah batas bawah.
> [ ] Bukan — batas bawahnya: Micrometer ___ , Timer ___ (nomor IK/butir: ______ ).
> [ ] Teknisi boleh di bawahnya dengan alasan tertulis (sebutkan siapa yang berwenang).

## §2 — Micrometer: Type A & veff lahir dari baris EVALUASI, bukan dari lima pembacaan · **prioritas tinggi**

`MicrometerCalculator::budget()`: komponen `pengulangan` = `STDEV(Evaluasi) / √n` dengan `v = n − 1`,
dan `n` = jumlah pembacaan di baris **Evaluasi** (`PERHITUNGAN` baris 25, sepuluh kolom; master:
`PERHITUNGAN U95%` baris `repeatability` `v = 9`). Lima pembacaan tiap titik hanya menentukan
**rata-rata dan koreksi** titik itu — bukan u(A) dan bukan veff.

Jadi batas "Micrometer 5" di atas menjaga koreksi tercetak, tetapi **tidak** menjaga U95. Yang
menjaga U95 adalah jumlah pembacaan Evaluasi, dan itu sekarang hanya dituntut `>= 2` dan tidak
seragam (`MicrometerCalculator::hitungSesi`, `boleh_terbit`).

**Angkanya** (master 25–50 mm: `u(A)` = 0,3162 µm, `v` = 9, U95 = 0,8762 µm): dengan `n` lebih
kecil `u(A)` naik (`s/√n`) dan `v` turun, jadi `veff` dan `k` ikut bergeser dan U95 berbeda dari
master.

**Pertanyaan:** IK mewajibkan **berapa** pembacaan Evaluasi — sepuluh, seperti kertas dan master?
Kalau ya, jawaban ini menambah batas kedua (`pra_evaluasi >= 10`) di profil yang sama.

> [ ] Sepuluh, wajib.
> [ ] Minimal ___ ; boleh lebih? ___
> [ ] IK tidak menyebut; keputusan diserahkan ke Manajer Teknis: ___

**Default sementara:** tidak ada penegakan jumlah Evaluasi (perilaku lama: `>= 2` dan tidak seragam).
Lihat juga §3 — ada jalur UI yang bisa memperkecil Evaluasi tanpa disengaja.

## §3 — `?pengulangan=` ikut mengecilkan tabel Evaluasi (temuan teknis, butuh persetujuan pemilik)

`CalibrationProfile::setelKolomPengulangan()` menulis ulang SETIAP daftar `pengulangan` yang
berbentuk `[1..n]` di bentuk lembar. Tabel **Evaluasi** Micrometer (`grup = pra_pembacaan`,
sepuluh kolom) berbentuk persis itu, sehingga `GET /calibrations/lembar-kerja?profil=micrometer&pengulangan=5`
mengembalikan Evaluasi **lima** kolom (terukur 17 Nov 2026; tanpa `?pengulangan=` tetap sepuluh).
Layar lembar kerja menawarkan pilihan 2–10 untuk semua alat, jadi memilih jumlah kolom
memperkecil pembacaan Evaluasi diam-diam — jalur nyata menuju U95 yang beda dari master.

Profil lain dengan tabel `pra_pembacaan` yang sama: Dial Indicator, Height Gauge, Jangka Sorong.

**Belum diubah** karena perbaikannya mengubah isi respons lembar kerja (lembar kerja app beku,
perubahan tampilan butuh persetujuan pemilik): `setelKolomPengulangan()` melewati tabel
`grup` berawalan `pra_pembacaan`. Satu perubahan kecil + tes; menunggu keputusan.

> [ ] Setuju diperbaiki (Evaluasi tetap sepuluh kolom apa pun `?pengulangan=`).
> [ ] Tidak — alasan: ______

## §4 — Sesi yang SUDAH disetujui dengan pengulangan di bawah IK

Sesi `disetujui` tidak berubah (hitungan, pembacaan, sertifikat). Validator hanya melaporkannya
sebagai INFO `pengulangan_kurang_dari_ik`, dan `kalibrasi:hitung-ulang` mencetak peringatan
yang sama. Catatan 23 Sep 2026 menyebut produksi tanpa sesi disetujui; sensus sesi
Micrometer/Timer yang ada di basis data belum diulang sesudah batas ini ditegakkan.

**Pertanyaan:** kalau ada sertifikat Micrometer/Timer yang terbit dari pengulangan di bawah IK
(mis. dari data lama atau seeder), apakah itu perlu tinjauan ketidaksesuaian, atau cukup dicatat?

> [ ] Cukup dicatat (INFO).
> [ ] Tinjauan ketidaksesuaian per sertifikat.

## §5 — Alat lain: apakah IK-nya juga menyebut jumlah pengulangan?

Mekanismenya satu method per profil — `minPengulanganIK()` — jadi menambah alat lain murah:
override satu method, tanpa mengubah validator atau controller. Hari ini hanya Micrometer dan
Timer/Stopwatch yang IK-nya kami tahu menyebut angka. Alat yang kemungkinan perlu dicek:
Centrifuge, Tachometer, Jangka Sorong, Height Gauge, Dial Indicator.

> Tulis di sini alat + angka + nomor IK-nya: ______
