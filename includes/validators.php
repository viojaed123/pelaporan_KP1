<?php
/**
 * includes/validators.php
 * Aturan validasi TERPUSAT untuk laporan harian.
 *
 * Sebelumnya aturan berbeda antara pembuatan (`buat_laporan.php` menerima
 * jam "99:99") dan revisi (`edit_revisi.php` ketat). Satu file ini dipakai
 * keduanya sehingga tidak mungkin timpang lagi.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/** Pola jam ketat: 00:00 – 23:59 */
const POLA_JAM = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/';
/** Pola tanggal ISO: YYYY-MM-DD */
const POLA_TANGGAL = '/^\d{4}-\d{2}-\d{2}$/';

/**
 * Validasi satu tanggal ISO.
 * @return string|null nilai Y-m-d yang bersih, atau null bila tidak valid.
 */
function val_tanggal(?string $nilai, bool $toleransi_masa_depan = false): ?string
{
    $nilai = trim((string) $nilai);
    if ($nilai === '' || !preg_match(POLA_TANGGAL, $nilai)) {
        return null;
    }
    $bagian = explode('-', $nilai);
    if (!checkdate((int) $bagian[1], (int) $bagian[2], (int) $bagian[0])) {
        return null;
    }
    if (!$toleransi_masa_depan && $nilai > date('Y-m-d', strtotime('+1 day'))) {
        return null;
    }
    return $nilai;
}

/**
 * Validasi satu jam.
 * @return string|null nilai HH:MM, atau null bila tidak valid ('' bila kosong).
 */
function val_jam(?string $nilai, bool $opsional = true): ?string
{
    $nilai = trim((string) $nilai);
    if ($nilai === '') {
        return $opsional ? '' : null;
    }
    return preg_match(POLA_JAM, $nilai) === 1 ? $nilai : null;
}

/**
 * Validasi teks opsional dengan batas panjang.
 * @return string|null nilai bersih, null bila melebihi batas.
 */
function val_teks(?string $nilai, int $maks_byte): ?string
{
    $nilai = trim((string) $nilai);
    if ($nilai === '') {
        return '';
    }
    if (strlen($nilai) > $maks_byte) {
        return null;
    }
    return $nilai;
}

/**
 * Validasi seluruh isian laporan harian.
 *
 * @param array $post      data POST (boleh kosong untuk GET)
 * @param bool  $wajib_wajibkolom apakah kegiatan & deskripsi wajib terisi
 * @return array{ok: bool, bersih: array<string,string|null>, galat: array<string,string>}
 */
function validasi_laporan(array $post, bool $wajib_isi = true): array
{
    $galat  = [];
    $bersih = [];

    /* Tanggal ---------------------------------------------------- */
    $tanggal = val_tanggal($post['tanggal'] ?? null);
    if ($tanggal === null) {
        $galat['tanggal'] = 'Tanggal wajib diisi dengan format yang benar (tidak boleh melewati hari ini).';
    }
    $bersih['tanggal'] = $tanggal;

    /* Jam -------------------------------------------------------- */
    $jamMulai = val_jam($post['jam_mulai'] ?? null, true);
    $jamSelesai = val_jam($post['jam_selesai'] ?? null, true);

    if (($post['jam_mulai'] ?? '') !== '' && $jamMulai === null) {
        $galat['jam_mulai'] = 'Jam mulai tidak valid (format HH:MM, 00:00–23:59).';
    }
    if (($post['jam_selesai'] ?? '') !== '' && $jamSelesai === null) {
        $galat['jam_selesai'] = 'Jam selesai tidak valid (format HH:MM, 00:00–23:59).';
    }
    if ($jamMulai && $jamSelesai && $jamSelesai < $jamMulai) {
        $galat['jam_selesai'] = 'Jam selesai tidak boleh lebih awal dari jam mulai.';
    }
    $bersih['jam_mulai']   = $jamMulai ?: null;
    $bersih['jam_selesai'] = $jamSelesai ?: null;

    /* Kegiatan --------------------------------------------------- */
    $kegiatan = trim((string) ($post['kegiatan'] ?? ''));
    if ($wajib_isi && $kegiatan === '') {
        $galat['kegiatan'] = 'Nama kegiatan wajib diisi.';
    } elseif (strlen($kegiatan) > LAPORAN_KEGIATAN_MAKS) {
        $galat['kegiatan'] = 'Nama kegiatan maksimal ' . LAPORAN_KEGIATAN_MAKS . ' karakter.';
    }
    $bersih['kegiatan'] = $kegiatan;

    /* Deskripsi -------------------------------------------------- */
    $deskripsi = val_teks($post['deskripsi'] ?? null, LAPORAN_TEKS_MAKS);
    if ($wajib_isi && $deskripsi === '') {
        $galat['deskripsi'] = 'Deskripsi kegiatan wajib diisi.';
    } elseif ($deskripsi === null) {
        $galat['deskripsi'] = 'Deskripsi terlalu panjang (maksimal ' . number_format(LAPORAN_TEKS_MAKS) . ' karakter).';
    }
    $bersih['deskripsi'] = $deskripsi ?? '';

    /* Kolom opsional (normalisasi nama field 'rencana' -> rencana_berikutnya) */
    $opsional = [
        'hasil'              => $post['hasil'] ?? null,
        'kendala'            => $post['kendala'] ?? null,
        'rencana_berikutnya' => $post['rencana_berikutnya'] ?? $post['rencana'] ?? null,
    ];

    foreach ($opsional as $kunci => $nilai) {
        $bersih[$kunci] = val_teks($nilai, LAPORAN_TEKS_MAKS);
        if ($bersih[$kunci] === null) {
            $galat[$kunci] = 'Kolom ini terlalu panjang (maksimal ' . number_format(LAPORAN_TEKS_MAKS) . ' karakter).';
        }
    }

    return [
        'ok'     => $galat === [],
        'bersih' => $bersih,
        'galat'  => $galat,
    ];
}

/**
 * Validasi isian catatan revisi (identik aturannya dengan validasi_laporan).
 */
function validasi_revisi(array $post): array
{
    return validasi_laporan($post, true);
}

/**
 * Ambil nilai POST yang sudah dibersihkan untuk ditampilkan ulang.
 */
function isi_lama(array $post, string $kunci, string $default = ''): string
{
    return (string) ($post[$kunci] ?? $default);
}
