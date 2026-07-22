# ⚠️ Breaking Change — Rename ASMO → Sidik

Semua penyebutan "ASMO" di backend diganti jadi **Sidik / PT. Sidik / Sidik Calibration**. Dua di antaranya **memutus** hal yang dipakai app mobile. Baca sebelum build berikutnya.

---

## 1. 🔴 Kredensial dev berubah — login lama MATI

Akun seed lama sudah tidak ada. Ganti di semua tempat yang masih hardcode kredensial lama (halaman login dev, file test, Postman/Insomnia, dsb).

| Role | Lama ❌ | Baru ✅ | employee_id baru |
|---|---|---|---|
| Admin | `admin@asmo.test` | `admin@sidik.test` | `SDK-0001` |
| Teknisi | `teknisi@asmo.test` | `teknisi@sidik.test` | `SDK-0002` |
| Viewer | `viewer@asmo.test` | `viewer@sidik.test` | `SDK-0003` |
| Pending (buat tes layar "akun belum disetujui") | `eko@asmo.test` | `eko@sidik.test` | `SDK-0099` |

Password semua tetap: `rahasia123`

Field login tetap **`identifier`** — boleh diisi email **atau** employee_id. Dua-duanya sudah diverifikasi jalan.

```jsonc
POST /api/login
{ "identifier": "admin@sidik.test", "password": "rahasia123" }
// atau
{ "identifier": "SDK-0001", "password": "rahasia123" }
```

---

## 2. 🔴 Deep link reset password berubah — WAJIB diubah di app mobile

Skema URL buat link reset password di email:

```
LAMA ❌   asmo://reset-password?token=...&email=...
BARU ✅   sidik://reset-password?token=...&email=...
```

**Yang harus dilakukan di `asmo_mobile`:** daftarkan skema `sidik://` (Android: `AndroidManifest.xml` → `intent-filter` `android:scheme="sidik"`; iOS: `Info.plist` → `CFBundleURLSchemes`). Kalau nggak, email reset password **nggak akan bisa buka app** — user mentok di email.

Backend bisa dioverride lewat env `RESET_PASSWORD_URL` kalau perlu transisi bertahap:
```
RESET_PASSWORD_URL=asmo://reset-password   # sementara, biar app versi lama tetap jalan
```
Saran: kalau app lama masih beredar, set env ini ke skema lama dulu, baru dilepas setelah semua user update.

---

## 3. 🟢 Tidak berdampak ke frontend

Ini diganti juga tapi **tidak mengubah kontrak API** — nggak perlu tindakan:

- Nama tampilan akun seed: "Admin ASMO" → "Admin Sidik", dst.
- Nama database: `asmo_db` → `sidik_db` (internal server; data lama dipindah utuh, DB lama disimpan sebagai cadangan).
- Komentar kode yang path-nya sudah basi.

`APP_NAME` sudah sejak awal **"SIDIK Calibration"** — nggak berubah.

---

## 4. Yang TIDAK diganti (sengaja)

- **Nama folder repo** `asmo-api` & `asmo_mobile` — di GitHub repo backend sudah bernama `sidik-calibration-api`; folder lokal dibiarkan biar remote/PR yang jalan nggak putus.
- **Catatan harian di `Project-PT-Sidik/03 - Catatan Harian/`** — itu rekaman histori, nggak diubah supaya nggak memalsukan catatan lama.

---

## Checklist frontend

- [ ] Ganti kredensial dev ke `@sidik.test` / `SDK-xxxx`
- [ ] Daftarkan skema `sidik://` di Android & iOS
- [ ] Tes alur lupa password end-to-end (email → tap link → app kebuka)
- [ ] Cek nggak ada string `asmo` yang tersisa di konfigurasi app
