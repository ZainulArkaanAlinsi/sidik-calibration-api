<?php

namespace App\Services\Vision;

use RuntimeException;

/**
 * Google dihubungi tetapi tidak menjawab dengan benar saat meminta token: jaringan
 * putus, waktu habis, atau gangguan sementara di sisi Google (HTTP 5xx / 429).
 *
 * Kelas tersendiri (bukan kode angka pada `RuntimeException`) supaya tidak tertukar
 * dengan `RuntimeException` lain yang lewat jalur yang sama, mis. galat cache. Pemanggil
 * menghitungnya kegagalan biasa (502, `unit_vision` 0, ikut batas kegagalan per lembar);
 * `RuntimeException` biasa dari `GoogleVisionToken` berarti salah setup (kredensial kosong
 * atau rusak, token ditolak Google 4xx), tidak sampai ke Vision, dan tidak ikut batas apa pun.
 */
final class GagalMenghubungiGoogle extends RuntimeException {}
