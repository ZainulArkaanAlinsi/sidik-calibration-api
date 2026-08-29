<?php

namespace App\Services\Direktori;

use RuntimeException;

/**
 * Direktori luarnya nggak bisa menjawab.
 *
 * Dipisah dari "nol hasil" dengan sengaja: yang satu artinya "cari lagi dengan
 * kata lain", yang satu lagi artinya "jangan percaya layar ini sekarang".
 * Disamakan, teknisi mendaftarkan ulang PT yang sebenarnya ada di direktori
 * cuma karena jaringannya lagi jelek.
 */
class DirektoriGagal extends RuntimeException {}
