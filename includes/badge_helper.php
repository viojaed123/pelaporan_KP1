<?php
if (!function_exists('badge_status')) {
    function badge_status(string $s): string {
        $s = strtolower(trim($s));
        if ($s === 'draft') return '<span class="badge badge-draft">Draft</span>';
        if ($s === 'menunggu' || $s === 'dikirim') return '<span class="badge badge-menunggu">Menunggu</span>';
        if ($s === 'diperiksa') return '<span class="badge badge-diperiksa">Diperiksa</span>';
        if ($s === 'diterima' || $s === 'selesai') return '<span class="badge badge-diterima">Diterima</span>';
        if ($s === 'revisi' || $s === 'perlu_revisi' || $s === 'direvisi') return '<span class="badge badge-direvisi">Perlu Revisi</span>';
        if ($s === 'ditolak') return '<span class="badge badge-tolak">Ditolak</span>';
        if ($s === 'hadir') return '<span class="badge badge-hadir">Hadir</span>';
        if ($s === 'izin') return '<span class="badge badge-izin">Izin</span>';
        if ($s === 'sakit') return '<span class="badge badge-sakit">Sakit</span>';
        if ($s === 'alpha') return '<span class="badge badge-alpha">Alpha</span>';
        return '<span class="badge">' . e(ucwords(str_replace('_',' ',$s))) . '</span>';
    }
}