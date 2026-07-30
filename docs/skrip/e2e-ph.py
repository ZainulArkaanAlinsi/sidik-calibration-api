#!/usr/bin/env python3
"""
Uji rantai penuh pH -> sertifikat, lawan server yang beneran jalan.

    python docs/skrip/e2e-ph.py [BASE_URL] [FILE_PDF]
    python docs/skrip/e2e-ph.py http://127.0.0.1:8000/api sertifikat.pdf

Yang diuji, berurutan: login teknisi & admin -> nyari alat pH + 3 buffer ->
kirim lembar kerja pH 3 titik -> cek ketidakpastian tersimpan -> buka lembar
perhitungan -> validasi -> approve -> tunggu sertifikat terbit -> unduh PDF-nya.

Keluarnya "SEMUA MATA RANTAI TERSAMBUNG" atau "PUTUS DI: <langkah>".

## Kenapa skrip ini ada

Suite test (`php artisan test`) jalan di SQLite dengan database yang dibangun dari
migrasi, semua job dipanggil langsung, dan nggak ada HTTP beneran. Itu bagus buat
ngunci logika, tapi dia BUTA sama seluruh kelas kegagalan yang cuma muncul di
mesin beneran: kolom sisa skema lama yang nggak ada di migrasi mana pun, migrasi
yang belum jalan, pekerja antrean yang lupa dinyalain, standar yang sertifikatnya
kadaluarsa, `.env` yang nunjuk IP lama. Semua itu pernah kejadian di repo ini dan
NGGAK ADA satu pun yang bikin test merah.

Jadi skrip ini sengaja nembak server beneran lewat HTTP, pakai database beneran,
dan lewat SEMUA lapisan yang app mobile lewatin. Dia bukan pengganti test — dia
nangkep hal yang test secara struktural nggak bisa lihat.

## Ketergantungan

Cuma pustaka standar Python 3.8+. Sengaja nggak pakai `requests`: skrip ini bakal
dijalanin di mesin orang lain waktu ada yang rusak, dan "pip install dulu" itu
satu rintangan tambahan persis di saat orangnya lagi buru-buru.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
import urllib.error
import urllib.request
from typing import Any

BASE_BAWAAN = "http://127.0.0.1:8000/api"
PDF_BAWAAN = "sertifikat.pdf"
SANDI_BAWAAN = "rahasia123"

# Akun dev dari `DatabaseSeeder`. Kalau seeder-nya belum pernah jalan di mesin
# ini, login-nya bakal 401 dan skripnya berhenti di mata rantai pertama —
# itu memang jawaban yang bener, bukan kegagalan skrip.
TEKNISI_BAWAAN = "teknisi@sidik.test"
ADMIN_BAWAAN = "admin@sidik.test"

# Serial dari `database/data/kalibrasi-ph-tirta-gracia.json`. Dicari lewat serial,
# BUKAN id: id auto-increment beda di tiap mesin, sedangkan serial itu identitas
# barang fisiknya dan sama di mana-mana.
SERIAL_ALAT = "B628755900"
SERIAL_BUFFER = {
    4: "HC32513535",
    7: "HC46341939",
    10: "HC45400338",
}

# Tiga titik pH dengan sebaran yang beda-beda, sengaja. Sesudah perbaikan GUM
# 29 Juli (Type A ikut masuk `u_c`), tiga titik ini mestinya keluar U95 yang
# BEDA satu sama lain. Kalau ketiganya keluar angka identik, itu tanda Type A
# kebuang lagi — dan itu justru yang paling gampang lolos dari test.
#
# `titik_ukur` di sini NOMINAL BOTOL, dan tiap titik bawa `suhu` larutan —
# persis yang diketik teknisi di lembar kerja. Backend yang nurunin nilai
# terkoreksinya dari kurva suhu buffer, dan `harusnya` di bawah angka yang
# dibandingin (dihitung dari koefisien di `database/data/...json`).
#
# Versi pertama skrip ini ngirim nilai yang UDAH terkoreksi tanpa suhu, jadi
# dia lolos terus walaupun backend nggak pernah nurunin apa-apa — uji rantai
# yang ngelewatin justru langkah yang paling gampang salah. Jangan dibalik lagi
# ke nilai terkoreksi: yang diuji itu backend bisa nurunin sendiri apa nggak.
TITIK_UJI = [
    {
        "buffer": 4,
        "titik_ukur": 4.01,
        "suhu": 26.3,
        "harusnya": 4.0057607,
        "pembacaan": [4.00, 4.00, 4.00, 4.00, 4.00],
    },
    {
        "buffer": 7,
        "titik_ukur": 7.00,
        "suhu": 25.5,
        "harusnya": 6.9764200,
        "pembacaan": [7.01, 7.01, 7.00, 7.00, 7.00],
    },
    {
        "buffer": 10,
        "titik_ukur": 10.01,
        "suhu": 25.3,
        "harusnya": 9.9451681,
        "pembacaan": [10.11, 10.10, 10.12, 10.11, 10.11],
    },
]


# Konsol Windows sering jalan di codepage non-UTF8, dan pesan error dari Laravel
# bisa bawa karakter di luar ASCII (mis. em dash di pesan validasi). Tanpa ini,
# pesan yang justru paling dibutuhin waktu ada yang rusak keluar jadi `?` atau
# malah bikin UnicodeEncodeError yang nutupin error aslinya.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")


class Putus(Exception):
    """Satu mata rantai gagal. `langkah` yang dicetak di baris terakhir."""

    def __init__(self, langkah: str, sebab: str, petunjuk: str = "") -> None:
        super().__init__(sebab)
        self.langkah = langkah
        self.sebab = sebab
        self.petunjuk = petunjuk


class Api:
    def __init__(self, base: str, timeout: int = 60) -> None:
        self.base = base.rstrip("/")
        self.timeout = timeout

    def panggil(
        self,
        metode: str,
        path: str,
        *,
        token: str | None = None,
        body: Any = None,
        biner: bool = False,
    ) -> tuple[int, Any]:
        url = f"{self.base}{path}"
        data = json.dumps(body).encode("utf-8") if body is not None else None

        req = urllib.request.Request(url, data=data, method=metode)
        req.add_header("Accept", "application/json")
        if data is not None:
            req.add_header("Content-Type", "application/json")
        if token:
            req.add_header("Authorization", f"Bearer {token}")

        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                isi = resp.read()
                return resp.status, (isi if biner else _urai(isi))
        except urllib.error.HTTPError as e:
            # Badan respons error tetap dibaca: pesan 422 dari Laravel itu yang
            # paling ngasih tau kenapa, dan buang-buang kalau nggak ditampilin.
            return e.code, _urai(e.read())
        except urllib.error.URLError as e:
            raise Putus(
                "nyambung ke server",
                f"nggak bisa nyambung ke {url} ({e.reason})",
                "Servernya jalan? `php artisan serve --host=0.0.0.0 --port=8000`",
            ) from e


def _urai(isi: bytes) -> Any:
    try:
        return json.loads(isi.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return {"_mentah": isi[:400].decode("utf-8", errors="replace")}


def _ringkas(data: Any, batas: int = 300) -> str:
    """Pesan error yang muat sebaris-dua, bukan dump JSON sehalaman."""
    if isinstance(data, dict):
        pesan = data.get("message")
        errors = data.get("errors")
        if pesan and errors:
            rinci = "; ".join(f"{k}: {v[0] if isinstance(v, list) else v}" for k, v in errors.items())
            return f"{pesan} ({rinci})"[:batas]
        if pesan:
            return str(pesan)[:batas]
    return json.dumps(data, ensure_ascii=False)[:batas]


class Rantai:
    """Pencatat langkah — biar baris terakhir bisa nunjuk mata rantai yang putus."""

    def __init__(self) -> None:
        self.langkah_kini = "(belum mulai)"
        self.nomor = 0
        # Barisnya udah dicetak tapi belum ditutup OK/GAGAL. Dipakai `tutup()`
        # buat nutup baris yang nggantung — `Putus` bisa dilempar dari dalam
        # `Api.panggil()` (mis. server mati) tanpa lewat `gagal()`, dan tanpa ini
        # keluarannya jadi baris setengah jadi yang nyambung ke pesan error.
        self.menggantung = False

    def mulai(self, nama: str) -> None:
        self.nomor += 1
        self.langkah_kini = nama
        self.menggantung = True
        print(f"  {self.nomor:>2}. {nama} ... ", end="", flush=True)

    def oke(self, catatan: str = "") -> None:
        self.menggantung = False
        print(f"OK{'  -> ' + catatan if catatan else ''}")

    def gagal(self, sebab: str, petunjuk: str = "") -> Putus:
        self.tutup()
        return Putus(self.langkah_kini, sebab, petunjuk)

    def tutup(self) -> None:
        if self.menggantung:
            self.menggantung = False
            print("GAGAL")


def login(api: Api, rantai: Rantai, identifier: str, sandi: str, peran: str) -> str:
    rantai.mulai(f"login {peran} ({identifier})")
    status, data = api.panggil("POST", "/login", body={"identifier": identifier, "password": sandi})

    if status != 200:
        petunjuk = ""
        if status == 401:
            petunjuk = "Seeder udah pernah jalan di database ini? `php artisan db:seed`"
        elif status == 403:
            petunjuk = "Akunnya pending/nonaktif — setujuin dulu lewat panel admin."
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}", petunjuk)

    token = (data.get("data") or {}).get("token")
    if not token:
        raise rantai.gagal("respons login nggak punya `data.token`")

    nama = ((data.get("data") or {}).get("user") or {}).get("nama") or identifier
    rantai.oke(nama)
    return token


def cari_alat(api: Api, rantai: Rantai, token: str) -> dict[str, Any]:
    rantai.mulai(f"nyari alat pH (serial {SERIAL_ALAT})")
    status, data = api.panggil("GET", f"/equipments?search={SERIAL_ALAT}", token=token)

    if status != 200:
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}")

    for alat in data.get("data") or []:
        if alat.get("serial_number") == SERIAL_ALAT:
            rantai.oke(f"id={alat['id']} {alat.get('nama_alat')} (toleransi {alat.get('toleransi')})")
            return alat

    raise rantai.gagal(
        f"alat serial {SERIAL_ALAT} nggak ada di organisasi ini",
        "Seeder pH-nya udah jalan? `php artisan db:seed --class=TirtaGraciaPhMeterSeeder`",
    )


def cari_buffer(api: Api, rantai: Rantai, token: str) -> dict[int, dict[str, Any]]:
    rantai.mulai("nyari 3 standar buffer pH")
    status, data = api.panggil("GET", "/standards", token=token)

    if status != 200:
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}")

    per_serial = {s.get("serial_number"): s for s in (data.get("data") or [])}
    ketemu: dict[int, dict[str, Any]] = {}
    hilang: list[str] = []

    for ph, serial in SERIAL_BUFFER.items():
        if serial in per_serial:
            ketemu[ph] = per_serial[serial]
        else:
            hilang.append(f"pH {ph} ({serial})")

    if hilang:
        raise rantai.gagal(
            "buffer nggak ketemu: " + ", ".join(hilang),
            "Seeder pH-nya udah jalan? `php artisan db:seed --class=TirtaGraciaPhMeterSeeder`",
        )

    # Standar kadaluarsa ditolak `CalibrationRequest` waktu submit, jadi lebih
    # baik ketahuan di sini — pesan di sini nyebut tanggalnya, yang 422 nanti
    # cuma bilang "udah kadaluarsa" tanpa bilang kapan.
    basi = [
        f"pH {ph} habis {s.get('berlaku_sampai')}"
        for ph, s in ketemu.items()
        if s.get("masih_berlaku") is False
    ]
    if basi:
        raise rantai.gagal(
            "sertifikat standar udah kadaluarsa: " + ", ".join(basi),
            "Perpanjang `berlaku_sampai`-nya di master standar, atau pakai buffer lain.",
        )

    rantai.oke(", ".join(f"pH {ph}=id {s['id']}" for ph, s in sorted(ketemu.items())))
    return ketemu


def kirim_lembar_kerja(
    api: Api,
    rantai: Rantai,
    token: str,
    alat: dict[str, Any],
    buffer: dict[int, dict[str, Any]],
) -> int:
    rantai.mulai("kirim lembar kerja pH 3 titik")

    hari_ini = time.strftime("%Y-%m-%d")
    payload = {
        "equipment_id": alat["id"],
        # Standar sesi dipatok ke buffer 7 (titik tengah); tiap titik nimpa
        # sendiri lewat `measurements[].standard_id`. Ini persis bentuk yang
        # dipakai kalibrasi pH beneran: satu sesi, tiga larutan acuan beda.
        "standard_id": buffer[7]["id"],
        "tanggal_kalibrasi": hari_ini,
        "lokasi": "lab",
        "input_method": "manual",
        "status": "menunggu_approval",
        # Identitas alat & pemilik yang diketik teknisi (lembar kerja poin 3-5 &
        # OWNER 1-2). Sengaja diisi beda tipis dari master, biar kelihatan
        # kalau sertifikatnya salah ngambil sumber.
        "alat_model": "Five Easy",
        "alat_serial_number": SERIAL_ALAT,
        "alat_merk": "Mettler Toledo",
        "pemilik_nama": "PT Tirta Gracia",
        "pemilik_alamat": "Jl. Uji Rantai No. 1",
        "suhu_awal": 24.5,
        "suhu_akhir": 25.1,
        "kelembaban_awal": 55.0,
        "kelembaban_akhir": 56.0,
        "catatan_teknisi": "Dikirim otomatis oleh docs/skrip/e2e-ph.py.",
        "measurements": [
            {
                "titik_ukur": t["titik_ukur"],
                "satuan": "pH",
                "standard_id": buffer[t["buffer"]]["id"],
                "pembacaan": t["pembacaan"],
                # Suhu larutan per pembacaan — sejajar per-index sama `pembacaan`.
                # Ini yang dipakai backend buat nurunin nilai buffer dari kurva
                # sertifikatnya. Tanpa ini, backend jatuh ke nominal botol dan
                # kolom Correction di sertifikat kegeser.
                "suhu": [t["suhu"]] * len(t["pembacaan"]),
            }
            for t in TITIK_UJI
        ],
    }

    status, data = api.panggil("POST", "/calibrations", token=token, body=payload)

    if status != 201:
        petunjuk = ""
        if status == 422 and "kadaluarsa" in _ringkas(data).lower():
            petunjuk = "Sertifikat standar acuannya lewat masa berlaku — perpanjang di master standar."
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}", petunjuk)

    sesi = data.get("data") or {}
    sesi_id = sesi.get("id")
    if not sesi_id:
        raise rantai.gagal("respons nggak punya `data.id`")

    rantai.oke(f"sesi id={sesi_id} nomor={sesi.get('nomor_sesi') or '-'}")
    return sesi_id


def _cek_koreksi_suhu(rantai: Rantai, titik: list[dict[str, Any]]) -> None:
    """
    Nilai acuan wajib DITURUNIN dari kurva suhu buffer, bukan dipakai nominalnya.

    Ini mata rantai yang paling gampang putus tanpa kelihatan. Kalau backend
    lupa nurunin, `titik_ukur` balik sama persis kayak yang dikirim, semua angka
    lain tetap konsisten, sertifikatnya tetap terbit rapi — dan kolom Correction
    di dokumen resmi salah sebesar koreksi suhu yang nggak pernah kepakai. Di
    pH 10 itu 0,065 pH pada alat bertoleransi 0,2.

    Dibandingin ke angka yang dihitung dari koefisien kurva di master standar,
    jadi yang dijaga bukan cuma "berubah", tapi "berubah jadi nilai yang bener".
    """
    harusnya = {i + 1: t["harusnya"] for i, t in enumerate(TITIK_UJI)}
    dikirim = {i + 1: t["titik_ukur"] for i, t in enumerate(TITIK_UJI)}
    salah = []

    for t in titik:
        ke = t.get("titik_ke")
        if ke not in harusnya:
            continue

        nilai = float(t.get("titik_ukur") or 0)

        if abs(nilai - harusnya[ke]) > 5e-7:
            mentah = " (nominal botol, koreksi suhunya nggak kepakai)" if abs(nilai - dikirim[ke]) < 5e-7 else ""
            salah.append(f"t{ke}={nilai:.7f} harusnya {harusnya[ke]:.7f}{mentah}")

    if salah:
        raise rantai.gagal(
            "nilai acuan nggak dikoreksi ke suhu larutan: " + "; ".join(salah),
            "Cek `GumCalculator::hitungTitik()` — `$suhuLarutan` kekirim & "
            "`Standard::koefisien_suhu` keisi apa nggak.",
        )


def cek_ketidakpastian(api: Api, rantai: Rantai, token: str, sesi_id: int) -> None:
    rantai.mulai("cek ketidakpastian tersimpan")
    status, data = api.panggil("GET", f"/calibrations/{sesi_id}", token=token)

    if status != 200:
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}")

    titik = (data.get("data") or {}).get("titik") or []
    if len(titik) != len(TITIK_UJI):
        raise rantai.gagal(
            f"titik terhitung {len(titik)}, harusnya {len(TITIK_UJI)}",
            "Titik tanpa standar acuan atau tanpa toleransi alat sengaja nggak dihitung.",
        )

    kosong = [str(t.get("titik_ke")) for t in titik if t.get("ketidakpastian_diperluas") in (None, 0)]
    if kosong:
        raise rantai.gagal(f"U95 kosong di titik ke-{', '.join(kosong)}")

    _cek_koreksi_suhu(rantai, titik)

    u95 = [float(t["ketidakpastian_diperluas"]) for t in titik]
    ringkas = ", ".join(
        f"t{t.get('titik_ke')}={float(t['ketidakpastian_diperluas']):.5f} {t.get('keputusan')}"
        for t in titik
    )
    rantai.oke(ringkas)

    # Peringatan, BUKAN kegagalan: tiga titik yang sebarannya beda mestinya
    # keluar U95 beda sesudah perbaikan GUM 29 Juli. Kalau identik semua, Type A
    # kemungkinan kebuang lagi dan yang dilaporin cuma CMC apa adanya. Nggak
    # digagalin karena secara teori CMC bisa jadi lantai buat semuanya kalau
    # pembacaannya kebetulan rapat banget — tapi tetap layak dilihat manusia.
    if len(set(round(u, 9) for u in u95)) == 1:
        print(
            "      ! U95 ketiga titik identik walau sebarannya beda. "
            "Cek `GumCalculator::hitungDariKemampuan()` — Type A mestinya ikut."
        )


def buka_perhitungan(api: Api, rantai: Rantai, token: str, sesi_id: int) -> None:
    rantai.mulai("buka lembar perhitungan")
    status, data = api.panggil("GET", f"/calibrations/{sesi_id}/perhitungan", token=token)

    if status != 200:
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}")

    isi = data.get("data")
    if not isi:
        raise rantai.gagal("lembar perhitungan kosong")

    rantai.oke(f"{len(isi) if isinstance(isi, list) else len(isi.keys())} bagian")


def validasi(api: Api, rantai: Rantai, token: str, sesi_id: int) -> None:
    rantai.mulai("validasi admin")
    status, data = api.panggil("GET", f"/calibrations/{sesi_id}/validasi", token=token)

    if status != 200:
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}")

    hasil = data.get("data") or {}
    temuan = hasil.get("temuan") or []

    if not hasil.get("boleh_terbit"):
        fatal = [t.get("pesan") or t.get("kode") for t in temuan if t.get("tingkat") == "error"]
        raise rantai.gagal("boleh_terbit=false - " + "; ".join(str(f) for f in fatal[:3]))

    rantai.oke(f"boleh_terbit=true, valid={hasil.get('valid')}, {len(temuan)} temuan")


def approve(api: Api, rantai: Rantai, token: str, sesi_id: int) -> None:
    rantai.mulai("approve sesi")
    # `abaikan_peringatan` dikirim karena `boleh_terbit` di atas udah mastiin
    # nggak ada temuan FATAL. Yang tersisa paling banter peringatan (mis. kolom
    # sertifikat yang kosong), dan itu nggak boleh nahan uji rantai.
    status, data = api.panggil(
        "POST", f"/calibrations/{sesi_id}/approve", token=token, body={"abaikan_peringatan": True}
    )

    if status != 200:
        raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}")

    rantai.oke(f"status={(data.get('data') or {}).get('status')}")


def tunggu_sertifikat(api: Api, rantai: Rantai, token: str, sesi_id: int, batas: int) -> dict[str, Any]:
    rantai.mulai("tunggu sertifikat terbit")

    # Sejak 29 Juli approve nerbitin sertifikat LANGSUNG, jadi percobaan pertama
    # mestinya udah `terbit` dan loop ini nggak pernah muter. Loop-nya tetap ada
    # buat jaga-jaga kalau suatu saat balik ke `dispatch()` — dan kalau itu
    # kejadian tanpa worker jalan, pesan habis-waktunya yang nunjukin.
    tenggat = time.time() + batas
    terakhir = "(belum ada baris sertifikat)"

    while True:
        status, data = api.panggil("GET", f"/calibrations/{sesi_id}", token=token)
        if status != 200:
            raise rantai.gagal(f"HTTP {status} - {_ringkas(data)}")

        sertifikat = (data.get("data") or {}).get("sertifikat")
        if sertifikat:
            terakhir = str(sertifikat.get("status"))
            if terakhir == "terbit":
                rantai.oke(f"{sertifikat.get('nomor')} (id={sertifikat.get('id')})")
                return sertifikat
            if terakhir == "gagal":
                raise rantai.gagal(
                    "sertifikatnya berstatus `gagal`",
                    "Lihat `storage/logs/laravel.log` buat penyebab aslinya.",
                )

        if time.time() >= tenggat:
            raise rantai.gagal(
                f"sertifikat nggak terbit dalam {batas} detik (status terakhir: {terakhir})",
                "Kalau statusnya nyangkut di `menunggu_generate`, kemungkinan besar ada yang "
                "balikin penerbitan ke antrean tanpa pekerja jalan — nyalain `php artisan queue:listen`.",
            )

        time.sleep(1.5)


def unduh_pdf(api: Api, rantai: Rantai, token: str, sertifikat_id: int, tujuan: str) -> None:
    rantai.mulai("unduh PDF sertifikat")
    status, isi = api.panggil(
        "GET", f"/certificates/{sertifikat_id}/download", token=token, biner=True
    )

    if status != 200:
        raise rantai.gagal(f"HTTP {status} - {_ringkas(isi)}")
    if not isinstance(isi, bytes) or not isi.startswith(b"%PDF"):
        raise rantai.gagal("yang keunduh bukan PDF (nggak diawali `%PDF`)")

    with open(tujuan, "wb") as f:
        f.write(isi)

    rantai.oke(f"{tujuan} ({len(isi) / 1024:.0f} KB)")


def jalan(args: argparse.Namespace) -> int:
    api = Api(args.base_url)
    rantai = Rantai()

    print(f"\nUji rantai pH -> sertifikat lawan {api.base}\n")

    try:
        token_teknisi = login(api, rantai, args.teknisi, args.password, "teknisi")
        token_admin = login(api, rantai, args.admin, args.password, "admin")

        alat = cari_alat(api, rantai, token_teknisi)
        buffer = cari_buffer(api, rantai, token_teknisi)

        sesi_id = kirim_lembar_kerja(api, rantai, token_teknisi, alat, buffer)
        cek_ketidakpastian(api, rantai, token_teknisi, sesi_id)

        buka_perhitungan(api, rantai, token_admin, sesi_id)
        validasi(api, rantai, token_admin, sesi_id)
        approve(api, rantai, token_admin, sesi_id)

        sertifikat = tunggu_sertifikat(api, rantai, token_admin, sesi_id, args.batas_tunggu)
        unduh_pdf(api, rantai, token_admin, sertifikat["id"], args.output)

    except Putus as e:
        rantai.tutup()
        print(f"\nPUTUS DI: {e.langkah}")
        print(f"  sebab: {e.sebab}")
        if e.petunjuk:
            print(f"  coba : {e.petunjuk}")
        print()
        return 1

    print("\nSEMUA MATA RANTAI TERSAMBUNG")
    print(f"  sesi        : {sesi_id}")
    print(f"  sertifikat  : {sertifikat.get('nomor')}")
    print(f"  PDF         : {args.output}")
    print(f"  verifikasi  : {sertifikat.get('qr_payload') or '(qr_payload kosong)'}")
    print()
    return 0


def main() -> int:
    p = argparse.ArgumentParser(
        description="Uji rantai penuh pH -> sertifikat lawan server yang beneran jalan.",
    )
    p.add_argument("base_url", nargs="?", default=BASE_BAWAAN, help=f"bawaan: {BASE_BAWAAN}")
    p.add_argument("output", nargs="?", default=PDF_BAWAAN, help=f"bawaan: {PDF_BAWAAN}")
    p.add_argument("--teknisi", default=TEKNISI_BAWAAN)
    p.add_argument("--admin", default=ADMIN_BAWAAN)
    p.add_argument("--password", default=SANDI_BAWAAN)
    p.add_argument(
        "--batas-tunggu",
        type=int,
        default=30,
        dest="batas_tunggu",
        help="detik nunggu sertifikat terbit (bawaan: 30)",
    )

    return jalan(p.parse_args())


if __name__ == "__main__":
    sys.exit(main())
