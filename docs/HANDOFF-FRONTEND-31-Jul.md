# Handoff Frontend — peringatan koreksi suhu & tata letak sertifikat, 31 Juli 2026

Lanjutan dari [`HANDOFF-FRONTEND-30-Jul.md`](HANDOFF-FRONTEND-30-Jul.md) — **baca yang
itu dulu** kalau belum, karena dokumen ini nerusin perubahan yang sama (koreksi suhu
buffer pH).

Isinya empat hal: dua peringatan baru di layar approval admin, perubahan tata letak
sertifikat, satu celah yang belum ketutup, dan satu tambahan data master.

Semua bentuk request/respons dicek langsung ke controller & resource-nya.

---

## 1. ⚠️ Dua peringatan baru di layar approval admin

`GET /calibrations/{id}/validasi` sekarang bisa ngeluarin dua kode temuan baru.
Bentuk responsnya **nggak berubah** — cuma nambah kode:

```jsonc
{
  "data": {
    "valid": false,          // true = nggak ada temuan sama sekali di luar info
    "boleh_terbit": true,    // true = nggak ada yang FATAL. Peringatan masih bisa dilewatin
    "temuan": [
      {
        "tingkat": "peringatan",              // error | peringatan | info
        "kode": "suhu_larutan_tidak_dicatat",
        "pesan": "Titik ke-1: standar acuannya (pH Buffer Solution 10) punya kurva suhu, tapi suhu larutannya nggak dicatat. Nilai acuan kepaksa pakai angka nominal, jadi Correction bisa meleset sebesar koreksi suhunya.",
        "konteks": { "titik_ke": 1, "standard_id": 4 }
      }
    ],
    "ringkasan": { "error": 0, "peringatan": 1, "info": 2 }
  }
}
```

| Kode | Nyala kalau | Yang salah |
|---|---|---|
| `suhu_larutan_tidak_dicatat` | Standar acuannya punya kurva suhu, tapi kolom suhu larutan kosong | **Lembar kerjanya** — teknisi belum ngisi suhu |
| `standar_tanpa_kurva_suhu` | Suhu larutan dicatat, tapi standarnya belum punya kurva di master | **Data masternya** — bukan salah teknisi |

**Dua-duanya `peringatan`, bukan `error`.** `boleh_terbit` tetap `true`, dan admin
tetap bisa lanjut dengan `abaikan_peringatan: true` di `POST /calibrations/{id}/approve`
— persis kayak peringatan lain yang udah ada.

### Yang perlu dibangun

Layar approval **harus nampilin `temuan` bertingkat `peringatan`**, bukan cuma yang
`error`. Kalau sekarang layarnya cuma nyaring `error` (karena itu yang nahan approve),
dua peringatan ini nggak akan pernah kelihatan — dan seluruh gunanya hilang.

Bedakan tindak lanjutnya, karena beda orang yang benerin:

- `suhu_larutan_tidak_dicatat` → arahin admin ke tombol **Kembalikan untuk revisi**,
  dan `revisi_field` bisa diisi kolom suhu titik yang kesebut (`konteks.titik_ke`).
- `standar_tanpa_kurva_suhu` → **jangan** arahin ke revisi. Teknisinya udah bener;
  yang kurang data master standar. Arahin ke layar master Standar.

### Kenapa ini ada

Nilai acuan yang bener itu nilai larutan **pada suhu pengukuran**. Kalau kurvanya nggak
ada atau suhunya nggak dicatat, backend balik ke nilai nominal botol — dan itu disengaja,
karena teknisi di lapangan nggak boleh keblokir gara-gara satu kolom.

Masalahnya sampai kemarin nggak ada satu pun yang ngasih tau. Sertifikat terbit rapi,
angkanya masuk akal, nol error di mana pun — padahal Correction meleset sebesar koreksi
suhu yang nggak kepakai. Di titik pH 10 itu **0,065 pH pada alat bertoleransi 0,2**:
sepertiga anggaran toleransi, cukup buat mbalik keputusan PASS/FAIL.

Yang berubah bukan perilakunya — kegagalannya yang jadi kelihatan **sebelum** admin
menyetujui, bukan sesudah pelanggan nanya.

---

## 2. Tata letak sertifikat berubah

Kena kalau mobile nampilin pratinjau sertifikat sendiri, atau kalau ada layar
pencocokan yang nyusun ulang tabelnya.

| Yang berubah | Dari | Jadi |
|---|---|---|
| Kop surat | Logo kecil + nama PT & alamat sebagai teks | **Banner selebar halaman** (logo + nama + alamat + KAN LK-285-IDN, semuanya di dalam gambar) |
| Issuance Date + tanda tangan | Kanan | **Kiri** |
| Tabel `STANDARD USED` | Ikut baris thermohygro (`TH-2 · — · TH-2`) | **Thermohygro nggak masuk lagi** |

Thermohygro dikeluarin karena dia alat pemantau kondisi ruangan, bukan acuan yang
nilainya dipakai ngoreksi pembacaan — dan kontribusinya udah kelaporan di kolom
`Env. Condition` di header. Barisnya juga keluar setengah jadi karena master
thermohygro nggak nyimpen merk/model/serial kayak standar acuan.

**Sertifikat lama nggak berubah** — snapshot-nya beku. Jadi dua sertifikat dari tanggal
berbeda bisa beda tata letak, dan itu benar.

---

## 3. 🕳️ Celah yang BELUM ketutup: desimal nggak kekirim ke mobile

Jumlah desimal tabel hasil di sertifikat sekarang **diturunin dari resolusi alat**
(0,01 → 2 desimal; 0,001 → 3), dan bisa ditimpa pengaturan organisasi. Nilainya
dibekukan ke snapshot sertifikat.

**Tapi angka itu nggak ada di API.** `GET /certificates/{id}` nggak ngirim `snapshot`
maupun `desimal`. Jadi kalau mobile nampilin tabel hasil sendiri (dari
`GET /calibrations/{id}` → `data.titik[]`), dia **nggak punya cara tau** sertifikatnya
bakal dibulatkan ke berapa desimal.

Efeknya: layar mobile bisa nampilin `0,0234` sementara sertifikat nyetak `0,023` — dan
pelanggan yang mencocokkan dua-duanya bakal nanya mana yang bener.

Dua jalan, dan ini perlu diputusin bareng:

1. **Mobile nurunin sendiri dari `equipment.resolusi`** (udah ada di
   `GET /equipments/{id}`). Cocok buat kasus normal, tapi **meleset kalau organisasi
   nyetel override** — mobile nggak tau setelan itu ada.
2. **Backend ngirim `desimal`** di `CertificateResource` dan/atau
   `CalibrationResource`. Ini yang bener, karena satu sumber angka.

Saranku nomor 2, dan itu perubahan kecil di backend. **Belum dikerjain** — bilang aja
kalau mau, biar nggak dua sisi bikin aturan pembulatan masing-masing.

---

## 4. Tambahan data master (buat pengujian)

Ada alat & pelanggan baru di data demo, diambil dari lembar olah data manual lab
(sertifikat `0558-CAL-525`):

- **PT THE MAGNUM ICE CREAM INDONESIA**
- **pH Meter · SI Analytics · Lab. 855 · `IMTE-WQ-129`** — resolusi **0,001**,
  toleransi 0,2

Berguna buat nguji layar pH di **3 desimal**, karena alat pH demo yang lain
(`B628755900`, Mettler Toledo Five Easy) resolusinya 0,01 dan kecetak 2 desimal.
Dua-duanya alat nyata milik pelanggan berbeda — jangan disamain.

---

## Ringkasan buat yang buru-buru

| # | Hal | Perlu kerjaan mobile? |
|---|---|---|
| 1 | Dua kode temuan baru di `/validasi` | ✅ **ya** — layar approval harus nampilin tingkat `peringatan`, dan bedain tindak lanjutnya |
| 2 | Tata letak sertifikat (kop, TTD kiri, thermohygro keluar) | ⚠️ cuma kalau ada pratinjau sertifikat di mobile |
| 3 | Desimal nggak kekirim ke mobile | ⏸️ **nunggu keputusan** — jangan bikin aturan pembulatan sendiri dulu |
| 4 | Alat & pelanggan baru di data demo | ℹ️ info doang |

Nomor 1 yang paling penting: kalau layar approval cuma nyaring `error`, dua peringatan
itu nggak akan pernah kelihatan dan seluruh gunanya hilang.
