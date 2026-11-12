r"""Cocokkan sha256 kanonik yang dicetak `acuan:impor-awal` dengan berkas JSON di repo,
secara INDEPENDEN dari PHP.

    python -I docs/skrip/cek-sha-impor-acuan.py <akar-repo-api> <keluaran-atau-deploy-log.txt> [jumlah-paket]

Masukannya teks keluaran `php artisan acuan:impor-awal [--dry-run]` atau potongan deploy
log Render yang memuatnya (awalan tiap baris, mis. cap waktu, tidak masalah). Dari tiap blok

    kode_profil    : micrometer
    berkas         : database/data/tabel-standar-micrometer.json
    sha256 kanonik : ba1fa153...

skrip ini mengkanonikkan berkas itu sendiri dengan aturan `App\Support\JsonKanonik`
(kunci diurutkan per byte UTF-8, tanpa spasi, urutan list dipertahankan, int tetap
int, float = desimal terpendek tanpa eksponen dengan minimal satu digit pecahan,
-0.0 -> 0.0, string tidak di-escape untuk non-ASCII), lalu menghitung sha256-nya
dan mengadu ke angka yang dicetak.

Berkas dibaca sebagai BYTE dan encoding-nya dideteksi dari BOM: `Tee-Object` dan `>` di
PowerShell 5.1 menulis UTF-16LE (BOM `ff fe`), `Out-File -Encoding utf8` menulis UTF-8
ber-BOM (`ef bb bf`). Semuanya terbaca; tanpa BOM dianggap UTF-8.

`jumlah-paket` (bawaan 25 = paket yang punya skemaAcuan() per 12 Nov 2026) adalah jumlah
`kode_profil` BERBEDA yang wajib terbaca. Log yang terpotong tidak boleh lolos sebagai
"semua cocok". Blok tiap profil tercetak SEKALI walau organisasinya banyak.

Hanya berkas di bawah `database/data/` yang dibaca (tanpa `..`, tanpa jalur mutlak):
teks log berasal dari luar dan jalurnya tidak dipercaya.

Kode keluar: 0 semua cocok dan jumlah terpenuhi; 1 ada yang beda/hilang/ditolak atau jumlah
kurang; 2 tidak ada blok yang terbaca atau argumen salah.

Kenapa ada: hash yang hanya dicocokkan dengan PHP yang sama membuktikan "konsisten",
bukan "benar". Ini jalur kedua dengan bahasa dan kode berbeda (Python, tanpa
Laravel, tanpa database).
"""

import hashlib
import json
import os
import re
import sys
from decimal import Decimal

JUMLAH_PAKET_BAWAAN = 25
AWALAN_BERKAS = "database/data/"


def desimal(f: float) -> str:
    if f == 0:
        return "0.0"
    s = format(Decimal(repr(f)), "f")
    return s if "." in s else s + ".0"


def kanonik(x) -> str:
    if x is None:
        return "null"
    if x is True:
        return "true"
    if x is False:
        return "false"
    if isinstance(x, int):
        return str(x)
    if isinstance(x, float):
        return desimal(x)
    if isinstance(x, str):
        return json.dumps(x, ensure_ascii=False)
    if isinstance(x, list):
        return "[" + ",".join(kanonik(i) for i in x) + "]"
    kunci = sorted(x.keys(), key=lambda k: k.encode("utf-8"))
    return "{" + ",".join(json.dumps(k, ensure_ascii=False) + ":" + kanonik(x[k]) for k in kunci) + "}"


# Tiap baris boleh berawalan apa saja (cap waktu Render, [HH:MM:SS]): yang dicari hanya
# tiga baris berurutan kode_profil -> berkas -> sha256 kanonik.
BLOK = re.compile(
    r"kode_profil\s*:\s*(\S+)[^\n]*\n[^\n]*?berkas\s*:\s*(\S+)[^\n]*\n[^\n]*?sha256 kanonik\s*:\s*([0-9a-f]{64})",
    re.IGNORECASE,
)


def dekode(data: bytes) -> str:
    """Teks dari byte log: BOM menentukan encoding; tanpa BOM = UTF-8."""
    if data.startswith((b"\xff\xfe", b"\xfe\xff")):
        return data.decode("utf-16", errors="replace")  # codec 'utf-16' membuang BOM sendiri
    if data.startswith(b"\xef\xbb\xbf"):
        return data.decode("utf-8-sig", errors="replace")
    # UTF-16 tanpa BOM (jarang): byte NUL di awal berarti bukan UTF-8 biasa.
    if b"\x00" in data[:400]:
        return data.decode("utf-16-le", errors="replace")
    return data.decode("utf-8", errors="replace")


def jalur_aman(akar: str, berkas: str):
    """Jalur absolut berkas, atau None bila bukan berkas di bawah database/data/."""
    if not berkas.startswith(AWALAN_BERKAS) or ".." in berkas.split("/") or "\\" in berkas or ":" in berkas:
        return None
    dasar = os.path.realpath(os.path.join(akar, AWALAN_BERKAS))
    path = os.path.realpath(os.path.join(akar, berkas))
    if os.path.commonpath([dasar, path]) != dasar:
        return None
    return path


def periksa(akar: str, teks: str, jumlah_diharapkan: int = JUMLAH_PAKET_BAWAAN):
    """Kembalikan (kode_keluar, daftar_baris_laporan)."""
    teks = re.sub(r"\x1b\[[0-9;]*m", "", teks)  # kode warna ANSI tidak boleh memutus blok
    blok = BLOK.findall(teks)
    if not blok:
        return 2, ["Tidak ada blok 'kode_profil / berkas / sha256 kanonik' yang terbaca."]

    laporan = []
    masalah = 0
    kode_terbaca = set()
    for kode, berkas, dicetak in blok:
        kode_terbaca.add(kode)
        path = jalur_aman(akar, berkas)
        if path is None:
            masalah += 1
            laporan.append(f"DITOLAK {kode:22} {berkas}  (bukan berkas di bawah {AWALAN_BERKAS})")
            continue
        if not os.path.isfile(path):
            masalah += 1
            laporan.append(f"HILANG {kode:22} {berkas}")
            continue
        with open(path, encoding="utf-8") as f:
            hitung = hashlib.sha256(kanonik(json.load(f)).encode("utf-8")).hexdigest()
        cocok = hitung == dicetak.lower()
        masalah += 0 if cocok else 1
        laporan.append(
            f"{'COCOK' if cocok else 'BEDA '} {kode:22} {dicetak.lower()}"
            + ("" if cocok else f"  (hitung ulang {hitung})")
        )

    if len(kode_terbaca) < jumlah_diharapkan:
        masalah += 1
        laporan.append(
            f"KURANG: {len(kode_terbaca)} kode_profil berbeda terbaca, diharapkan {jumlah_diharapkan} "
            "(log terpotong atau perintah dibatasi ke sebagian profil)."
        )
    elif len(kode_terbaca) > jumlah_diharapkan:
        laporan.append(f"CATATAN: {len(kode_terbaca)} kode_profil berbeda terbaca, lebih dari {jumlah_diharapkan} yang diharapkan.")

    laporan.append(
        f"HASIL: {'SEMUA COCOK' if not masalah else str(masalah) + ' BERMASALAH'} "
        f"({len(kode_terbaca)} dari {jumlah_diharapkan} paket diharapkan, {len(blok)} blok)"
    )
    return (1 if masalah else 0), laporan


def main(argv) -> int:
    if len(argv) not in (3, 4):
        print(__doc__)
        return 2
    akar, log = argv[1], argv[2]
    try:
        jumlah = int(argv[3]) if len(argv) == 4 else JUMLAH_PAKET_BAWAAN
    except ValueError:
        print("jumlah-paket harus bilangan bulat.")
        return 2
    with open(log, "rb") as f:
        teks = dekode(f.read())
    kode, laporan = periksa(akar, teks, jumlah)
    print("\n".join(laporan))
    return kode


if __name__ == "__main__":
    sys.exit(main(sys.argv))
