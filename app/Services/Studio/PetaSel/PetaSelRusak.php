<?php

namespace App\Services\Studio\PetaSel;

use RuntimeException;

/**
 * Berkas peta sel ada tapi bentuknya tidak sah — kesalahan repo, bukan
 * kesalahan master. Impor berhenti; tidak pernah dianggap "belum terpetakan".
 */
final class PetaSelRusak extends RuntimeException {}
