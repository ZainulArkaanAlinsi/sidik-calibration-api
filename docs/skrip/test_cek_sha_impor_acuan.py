r"""Tes kecil untuk cek-sha-impor-acuan.py. Dijalankan manual (bukan bagian suite PHP):

    python -I docs/skrip/test_cek_sha_impor_acuan.py

Hanya memakai berkas sementara; tidak membaca repo, database, atau jaringan.
"""

import contextlib
import hashlib
import importlib.util
import io
import json
import os
import tempfile
import unittest

_SKRIP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cek-sha-impor-acuan.py")
_spec = importlib.util.spec_from_file_location("cek_sha_impor_acuan", _SKRIP)
cek = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(cek)

ISI = {"b": [1, 2.5, 200.0], "a": {"z": None, "y": True}, "teks": "µm — 5/6"}


def sha_kanonik(data) -> str:
    return hashlib.sha256(cek.kanonik(data).encode("utf-8")).hexdigest()


def blok(kode, berkas, sha, awalan=""):
    return (
        f"{awalan}kode_profil    : {kode}\n"
        f"{awalan}berkas         : {berkas}\n"
        f"{awalan}sha256 kanonik : {sha}\n"
    )


class KanonikTest(unittest.TestCase):
    def test_aturan_dasar(self):
        self.assertEqual(
            cek.kanonik({"b": 1, "a": [1.0, 200, 0.00001, -0.0, 2.50014], "c": "µ/é"}),
            '{"a":[1.0,200,0.00001,0.0,2.50014],"b":1,"c":"µ/é"}',
        )

    def test_kunci_diurutkan_per_byte_utf8(self):
        self.assertEqual(cek.kanonik({"é": 1, "z": 2, "B": 3}), '{"B":3,"z":2,"é":1}')


class DekodeTest(unittest.TestCase):
    TEKS = "kode_profil    : micrometer\nberkas         : x — µ\n"

    def test_utf16_le_dengan_bom_seperti_tee_object_ps51(self):
        self.assertEqual(cek.dekode(b"\xff\xfe" + self.TEKS.encode("utf-16-le")), self.TEKS)

    def test_utf16_be_dengan_bom(self):
        self.assertEqual(cek.dekode(b"\xfe\xff" + self.TEKS.encode("utf-16-be")), self.TEKS)

    def test_utf8_dengan_bom_seperti_out_file_utf8_ps51(self):
        self.assertEqual(cek.dekode(b"\xef\xbb\xbf" + self.TEKS.encode("utf-8")), self.TEKS)

    def test_utf8_tanpa_bom(self):
        self.assertEqual(cek.dekode(self.TEKS.encode("utf-8")), self.TEKS)


class PeriksaTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.akar = self._tmp.name
        os.makedirs(os.path.join(self.akar, "database", "data"))
        for nama in ("a", "b", "c"):
            with open(os.path.join(self.akar, "database", "data", f"{nama}.json"), "w", encoding="utf-8") as f:
                json.dump({**ISI, "nama": nama}, f, ensure_ascii=False)

    def sha(self, nama):
        return sha_kanonik({**ISI, "nama": nama})

    def log(self, daftar=("a", "b", "c"), awalan=""):
        return "".join(blok(n, f"database/data/{n}.json", self.sha(n), awalan) for n in daftar)

    def test_semua_cocok(self):
        kode, laporan = cek.periksa(self.akar, self.log(), 3)
        self.assertEqual(kode, 0, laporan)
        self.assertIn("SEMUA COCOK", laporan[-1])

    def test_awalan_cap_waktu_tidak_mengganggu(self):
        kode, laporan = cek.periksa(self.akar, self.log(awalan="Nov 12 10:15:01 AM  "), 3)
        self.assertEqual(kode, 0, laporan)

    def test_log_utf16_dari_powershell_terbaca_lewat_dekode(self):
        data = b"\xff\xfe" + self.log().replace("\n", "\r\n").encode("utf-16-le")
        kode, laporan = cek.periksa(self.akar, cek.dekode(data), 3)
        self.assertEqual(kode, 0, laporan)

    def test_hash_beda_dilaporkan(self):
        teks = self.log().replace(self.sha("b"), "0" * 64)
        kode, laporan = cek.periksa(self.akar, teks, 3)
        self.assertEqual(kode, 1)
        self.assertTrue(any(b.startswith("BEDA") and "b" in b for b in laporan), laporan)

    def test_jumlah_kurang_dari_diharapkan_gagal_walau_yang_ada_cocok(self):
        kode, laporan = cek.periksa(self.akar, self.log(("a", "b")), 3)
        self.assertEqual(kode, 1, laporan)
        self.assertTrue(any(b.startswith("KURANG") for b in laporan), laporan)

    def test_bawaan_25_menolak_log_tiga_paket(self):
        kode, _ = cek.periksa(self.akar, self.log())
        self.assertEqual(kode, 1)

    def test_blok_ganda_satu_profil_dihitung_satu(self):
        kode, laporan = cek.periksa(self.akar, self.log(("a", "a", "b", "c")), 3)
        self.assertEqual(kode, 0, laporan)

    def test_berkas_hilang(self):
        os.remove(os.path.join(self.akar, "database", "data", "c.json"))
        kode, laporan = cek.periksa(self.akar, self.log(), 3)
        self.assertEqual(kode, 1)
        self.assertTrue(any(b.startswith("HILANG") for b in laporan), laporan)

    def test_jalur_di_luar_database_data_ditolak(self):
        rahasia = os.path.join(self.akar, "rahasia.json")
        with open(rahasia, "w", encoding="utf-8") as f:
            json.dump(ISI, f)
        for jahat in (
            "rahasia.json",
            "database/data/../../rahasia.json",
            "/etc/passwd",
            "C:/Windows/win.ini",
            "database\\data\\a.json",
            "database/dataku/a.json",
        ):
            with self.subTest(berkas=jahat):
                teks = blok("a", jahat, sha_kanonik(ISI))
                kode, laporan = cek.periksa(self.akar, teks, 1)
                self.assertEqual(kode, 1, laporan)
                self.assertTrue(any(b.startswith("DITOLAK") for b in laporan), laporan)

    def test_tanpa_blok_kode_2(self):
        kode, _ = cek.periksa(self.akar, "tidak ada apa-apa di sini", 3)
        self.assertEqual(kode, 2)

    def test_main_membaca_byte_dan_argumen_jumlah(self):
        log = os.path.join(self.akar, "log.txt")
        with open(log, "wb") as f:
            f.write(b"\xff\xfe" + self.log().encode("utf-16-le"))
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(cek.main(["x", self.akar, log, "3"]), 0)
            self.assertEqual(cek.main(["x", self.akar, log, "4"]), 1)
            self.assertEqual(cek.main(["x", self.akar, log, "tiga"]), 2)
            self.assertEqual(cek.main(["x", self.akar]), 2)


if __name__ == "__main__":
    unittest.main(verbosity=2)
