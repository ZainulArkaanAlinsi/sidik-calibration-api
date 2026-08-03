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

## 3. ✅ `desimal` sekarang dikirim backend — pakai ini, jangan nebak

Jumlah desimal angka hasil **diturunin dari resolusi alat** (0,01 → 2 desimal;
0,001 → 3), dan bisa ditimpa pengaturan organisasi. Sekarang angkanya ikut kekirim,
jadi mobile nggak perlu bikin aturan pembulatan sendiri:

| Endpoint | Field | Sifatnya |
|---|---|---|
| `GET /calibrations/{id}` (& daftar) | `data.desimal` | **Hidup** — ngikut pengaturan yang berlaku sekarang |
| `GET /certificates/{id}` (& daftar) | `data.desimal` | **Beku** — dari snapshot sertifikat itu |

**Bedanya disengaja.** Sesi belum punya dokumen resmi, jadi angkanya masih boleh
berubah sampai sertifikatnya terbit. Sertifikat yang udah terbit **nggak boleh** berubah
bentuk gara-gara pengaturan diubah sesudahnya — jadi dua sertifikat dari tanggal berbeda
boleh punya `desimal` beda, dan itu benar. Contoh nyata dari data dev sekarang:

```
CAL/2026/07/0022 -> desimal=3   (terbit sesudah resolusi alat dibenerin)
CAL/2026/07/0021 -> desimal=2
CAL/2026/07/0020 -> desimal=2
```

**Yang perlu dilakukan:** bulatkan angka di `data.titik[]` (dan tabel hasil di layar
sertifikat) pakai `desimal` dari respons yang sama. Jangan nurunin sendiri dari
`equipment.resolusi` — itu cocok buat kasus biasa tapi meleset begitu organisasi nyetel
timpaan, dan mobile nggak punya cara tau setelan itu ada.

Kalau `desimal` datang `null` (organisasi nggak kebaca), jatuh ke 4 desimal — sama
dengan bawaan backend.

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

## 5. 🔴 Buffer pH 4 kadaluarsa — kiriman lembar pH bakal ditolak 422

Mulai 31 Juli 2026, `pH Buffer Solution 4` (`HC32513535`) lewat masa berlaku
sertifikatnya. Efeknya ke layar teknisi:

```
POST /calibrations  ->  422
{
  "message": "Sertifikat standar acuan titik ini udah kadaluarsa, jadi nggak boleh dipakai kalibrasi.",
  "errors": { "measurements.0.standard_id": ["Sertifikat standar acuan titik ini udah kadaluarsa, ..."] }
}
```

Karena kalibrasi pH baku 3 titik (4/7/10), **seluruh alur pH berhenti** sampai
buffernya diganti. Dua buffer lain masih berlaku sampai 2027.

**Yang perlu dilakukan mobile: tampilkan pesannya apa adanya**, dan jangan
bikin kelihatan seperti salah input teknisi. Ini masalah data master — teknisinya
nggak bisa ngapa-ngapain selain lapor ke admin. Kalau layarnya cuma nulis
"Gagal menyimpan", teknisi bakal ngulang-ngulang kiriman yang sama.

Layar pilih standar juga udah punya bahannya buat nyegah lebih awal:
`GET /standards` ngirim `masih_berlaku`, `status_kalibrasi`, dan
`hari_menuju_kadaluarsa` per standar. Standar kadaluarsa **tetap dikirim** (biar
teknisi nggak ngira datanya hilang), jadi penandaannya di sisi layar.

## 6. ❌ Order Kalibrasi & penugasan teknisi: BATAL, bukan "nanti"

Branch tempat dua fitur itu dibangun **ditutup 31 Juli** dan nggak akan di-merge.

Dokumen lama (`BACA-DULU-BACKEND.md` §3) nulis entitas `/orders` sebagai "BELUM
ADA — jangan dibangun frontend-nya dulu". Kalimat itu sekarang **permanen**:

| Fitur | Status |
|---|---|
| Entitas `/orders` (Order Kalibrasi) | ❌ batal |
| Penugasan teknisi per alat | ❌ batal |
| Antrean "Tugas Saya" | ❌ batal |

**Yang perlu dilakukan:** buang ketiganya dari rencana layar, jangan disimpan
sebagai "menunggu backend". Kalau ada rancangan atau navigasi yang udah nyiapin
tempat buat mereka, itu bisa dibersihin.

Yang tetap ada dan nggak berubah: `nomor_order` & `tanggal_terima` udah jadi
field di sesi kalibrasi (diisi admin lewat `PATCH /calibrations/{id}/admin`).
Jadi "nomor order" sebagai informasi tetap kepakai — yang batal itu Order sebagai
**entitas tersendiri** dengan layar dan alurnya sendiri.

---

## Ringkasan buat yang buru-buru

| # | Hal | Perlu kerjaan mobile? |
|---|---|---|
| 1 | Dua kode temuan baru di `/validasi` | ✅ **ya** — layar approval harus nampilin tingkat `peringatan`, dan bedain tindak lanjutnya |
| 2 | Tata letak sertifikat (kop, TTD kiri, thermohygro keluar) | ⚠️ cuma kalau ada pratinjau sertifikat di mobile |
| 3 | `desimal` dikirim backend (`data.desimal`) | ✅ **ya** — pakai buat membulatkan, jangan nurunin dari `resolusi` |
| 4 | Alat & pelanggan baru di data demo | ℹ️ info doang |
| 5 | Buffer pH 4 kadaluarsa → kiriman pH ditolak 422 | ✅ **ya** — tampilkan pesannya apa adanya, jangan kayak salah input |
| 6 | Order Kalibrasi & penugasan teknisi **batal** | ✅ **ya** — buang dari rencana layar, bukan ditunda |

Nomor 1 yang paling penting: kalau layar approval cuma nyaring `error`, dua peringatan
itu nggak akan pernah kelihatan dan seluruh gunanya hilang.
