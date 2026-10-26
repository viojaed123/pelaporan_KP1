<?php
declare(strict_types=1);

/**
 * Bootstrap suite LEGACY (phpunit-legacy.xml).
 *
 * Berkas ini berada di tests/legacy/ sehingga rujukan ke includes/ perlu
 * mundur DUA tingkat dari __DIR__ (tests/legacy -> root proyek).
 *
 * Jalankan dengan:  composer test:legacy
 */

$root = dirname(__DIR__, 2);

require_once $root . '/includes/config.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/notifikasi.php';
require_once $root . '/includes/whatsapp.php';
require_once $root . '/includes/akun.php';
require_once $root . '/includes/komentar.php';
require_once $root . '/includes/pemberitahuan.php';
require_once $root . '/includes/keputusan_laporan.php';
require_once $root . '/includes/impor_excel.php';
require_once $root . '/includes/jadwal.php';
require_once $root . '/includes/srm_cid.php';
require_once $root . '/includes/wilayah.php';
require_once $root . '/includes/kasus.php';
require_once $root . '/includes/cost_avoidance.php';
require_once $root . '/includes/impor_dataset.php';
