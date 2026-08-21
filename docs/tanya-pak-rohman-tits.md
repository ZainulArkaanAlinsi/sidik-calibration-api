# Mau nanya soal olah data TITS, Pak Rohman

Backend TITS sudah jadi, angkanya sudah saya cocokkan ke dua sesi di file master
(01-CAL-625 & 0159-CAL-626) — cocok semua.

Cuma ada beberapa hal di file-nya yang saya nggak berani putuskan sendiri. Semua
saya ikut apa adanya dulu biar sama persis dengan sertifikat yang sudah keluar.
Kalau ternyata ada yang keliru, tinggal bilang — saya cuma ganti satu angka.

---

**1. Pembagi AC Pick Up diakarin dua kali.**
Selnya `Q22 = SQRT(3)` terus `U22 = N22/SQRT(Q22)`. Jadi pembaginya 1,316, bukan
1,732 — padahal labelnya `rect.`. Baris atas-bawahnya nulisnya bener. Kejadian di
dua file.
→ Kalau dibetulin ke √3, U95 Measure **0,85 jadi 0,84 °C**.
**Yang bener pembaginya atau labelnya, Pak?**

**2. v_eff nggak dibulatkan ke bawah.**
Master TITS cari `k` dari `v_eff` apa adanya. Sepuluh alat lain membulatkan ke
bawah dulu (aturan GUM, dulu cocok sama lembar pH).
→ Kalau diseragamkan, U95 Measure **0,85 jadi 0,87 °C**.
**Ini sengaja beda, atau mau diseragamkan?**

**3. Mode Source ada dua komponen drift.**
Baris 20 normal (lookup sesuai tipe sensor). Baris 22 isinya `='STANDAR
KALIBRATOR'!Y8` — alamat mati ke drift **Constant Type N** (0,38), padahal
sesinya pakai **Yokogawa Type S**. Di file Measure baris ini nggak ada.
**Itu komponen sendiri (angkanya dari mana, kenapa ci = 2), atau sisa
copy-paste?**

**4. Drift dibagi 2 cuma di Measure.**
`Measure: VLOOKUP(...)/2` — `Source: VLOOKUP(...)`. Komponen yang sama.
**Yang dibagi 2 itu konversi dari apa, atau Source-nya yang kelupaan?**

**5. Ketidakpastian kalibrator diambil beda cara.**
Measure ambil yang terbesar se-tabel, Source ambil di titik tertinggi sesi itu.
**Mana yang jadi patokan lab?**

**6. Titik 1100 pas di tengah 1000 dan 1200.**
Rumusnya harusnya ambil 1000 (koreksi −0,15), tapi hasil di selnya 1200
(koreksi −0,20) — dan itu yang kecetak. Saya ikut hasilnya.
**Kalau seri gitu ambil yang mana? Dan titik yang jauh dari tabel sebaiknya
diinterpolasi nggak?**

**7. Kolom U95 Type K Yokogawa di file Source isinya minus** (−0,06 s/d −0,31) —
itu deret koreksi yang kesalin ke kolom U95. Sekarang saya tolak, jadi sesi Type
K mode Source jatuh ke CMC.
**Minta angka U95 yang bener, Pak.**

**8. Tiga hal kecil:**
- Nomor formulir lembar kerja TITS belum ada di file mana pun — sementara saya
  kosongkan.
- `k` kecetak nol desimal (`k = 2,40` jadi `2`). Mau dibetulin formatnya?
- Rentang RLK sensor resistif: lampiran akreditasi **−20…800 °C**, master Excel
  **−10…800 °C**. Saya pakai yang lampiran.

---

**Di luar TITS.** Kondisi lingkungan di sertifikat. Master ambil titik
thermohygro terdekat ke suhu terukur, sistem pakai satu titik tetap. Sesi
01-CAL-625: master **23,38 °C**, sistem **23,61 °C**. Belum saya ubah karena
jalur ini kepakai sebelas alat.

Sekalian: rumus yang nyari titik **kelembaban** di master (`H16`) itu ngitungnya
pakai **suhu akhir**, bukan angka kelembabannya. Kayaknya keliru sel juga.

---

Nggak buru-buru Pak, backend-nya sudah jalan. Detail teknisnya ada di
`docs/pertanyaan-lab-tits.md` kalau perlu.
