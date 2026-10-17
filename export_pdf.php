<?php
/**
 * export_pdf.php — unduh rekap laporan dalam berkas PDF.
 *
 * Halaman DOWNLOAD: TIDAK memakai mulai_halaman()/akhiri_halaman() karena
 * keluarannya berupa berkas, bukan HTML.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran (semula session_start + cek $_SESSION manual)
 *   · logika ekspor, validasi filter, dan nama parameter GET
 *     (tanggal_mulai, tanggal_akhir, status, karyawan) dipertahankan
 *
 * Batas akses: hanya dipanggil dari rekap_laporan.php (mentor/admin), maka
 * ekspor dibatasi untuk peran mentor & admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

// Ambil filter yang sama dengan rekap_laporan.php.
$tanggal_mulai = trim($_GET['tanggal_mulai'] ?? '');
$tanggal_akhir = trim($_GET['tanggal_akhir'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$karyawan_filter = trim($_GET['karyawan'] ?? '');

// Validasi tanggal.
function tanggalValidPDF($tanggal)
{
    if ($tanggal === '') {
        return true;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date && $date->format('Y-m-d') === $tanggal;
}

if (
    !tanggalValidPDF($tanggal_mulai) ||
    !tanggalValidPDF($tanggal_akhir) ||
    (
        $tanggal_mulai !== '' &&
        $tanggal_akhir !== '' &&
        $tanggal_mulai > $tanggal_akhir
    )
) {
    http_response_code(400);
    exit('Filter tanggal tidak valid.');
}

$status_valid = [
    '',
    'draft',
    'dikirim',
    'diperiksa',
    'perlu_revisi'
];

if (!in_array($status_filter, $status_valid, true)) {
    http_response_code(400);
    exit('Filter status tidak valid.');
}

if (
    $karyawan_filter !== '' &&
    (!ctype_digit($karyawan_filter) || (int) $karyawan_filter < 1)
) {
    http_response_code(400);
    exit('Filter karyawan tidak valid.');
}

// Query menggunakan kolom yang sama dengan halaman rekap.
$sql = "
    SELECT
        l.id,
        l.tanggal,
        l.kegiatan,
        l.status,
        u.nama AS nama_karyawan,
        u.divisi
    FROM laporan_harian l
    INNER JOIN users u ON l.user_id = u.id
    WHERE u.role = 'karyawan'
";

$types = '';
$params = [];

if ($tanggal_mulai !== '') {
    $sql .= ' AND l.tanggal >= ?';
    $types .= 's';
    $params[] = $tanggal_mulai;
}

if ($tanggal_akhir !== '') {
    $sql .= ' AND l.tanggal < DATE_ADD(?, INTERVAL 1 DAY)';
    $types .= 's';
    $params[] = $tanggal_akhir;
}

if ($status_filter !== '') {
    $sql .= ' AND l.status = ?';
    $types .= 's';
    $params[] = $status_filter;
}

if ($karyawan_filter !== '') {
    $sql .= ' AND l.user_id = ?';
    $types .= 'i';
    $params[] = (int) $karyawan_filter;
}

$sql .= ' ORDER BY l.tanggal DESC, l.id DESC';

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit('Gagal menyiapkan data laporan.');
}

if ($params) {
    $bindArgs = [$types];

    foreach ($params as $key => $value) {
        $bindArgs[] = &$params[$key];
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $bindArgs
    );
}

$stmt->execute();
$result = $stmt->get_result();

function ePDF($value)
{
    return htmlspecialchars(
        (string) ($value ?? '-'),
        ENT_QUOTES,
        'UTF-8'
    );
}

function labelStatusPDF($status)
{
    $labels = [
        'draft' => 'Draft',
        'dikirim' => 'Menunggu Pemeriksaan',
        'diperiksa' => 'Sudah Diperiksa',
        'perlu_revisi' => 'Perlu Revisi'
    ];

    return $labels[$status] ?? $status;
}

// Buat isi tabel PDF.
$baris = '';
$nomor = 1;

while ($item = $result->fetch_assoc()) {
    $baris .= '
        <tr>
            <td class="center">' . $nomor++ . '</td>
            <td>' . ePDF($item['nama_karyawan']) . '</td>
            <td>' . ePDF($item['divisi'] ?: '-') . '</td>
            <td>' . ePDF($item['tanggal']) . '</td>
            <td>' . nl2br(ePDF($item['kegiatan'])) . '</td>
            <td>' . ePDF(labelStatusPDF($item['status'])) . '</td>
        </tr>
    ';
}

if ($baris === '') {
    $baris = '
        <tr>
            <td colspan="6" class="center">
                Tidak ada laporan yang sesuai dengan filter.
            </td>
        </tr>
    ';
}

// Informasi filter yang digunakan.
$periode = 'Semua tanggal';

if ($tanggal_mulai !== '' || $tanggal_akhir !== '') {
    $periode =
        ($tanggal_mulai !== '' ? $tanggal_mulai : 'Awal') .
        ' sampai ' .
        ($tanggal_akhir !== '' ? $tanggal_akhir : 'Sekarang');
}

$status_tampil = $status_filter === ''
    ? 'Semua status'
    : labelStatusPDF($status_filter);

$karyawan_tampil = 'Semua karyawan';

if ($karyawan_filter !== '') {
    $stmtNama = $conn->prepare(
        "SELECT nama FROM users
         WHERE id = ? AND role = 'karyawan'"
    );

    $idKaryawan = (int) $karyawan_filter;
    $stmtNama->bind_param('i', $idKaryawan);
    $stmtNama->execute();

    $resultNama = $stmtNama->get_result();
    $namaRow = $resultNama->fetch_assoc();

    if ($namaRow) {
        $karyawan_tampil = $namaRow['nama'];
    }

    $stmtNama->close();
}

// Lambang PHR (bila tersedia) untuk kop laporan PDF. Disisipkan sebagai data
// URI agar tidak bergantung pada jalur berkas/berkas eksternal dompdf.
$berkasLogo = __DIR__ . '/assets/img/logo-phr.png';
$kopLogo = is_file($berkasLogo)
    ? '<div class="kop"><img src="data:image/png;base64,'
        . base64_encode((string) file_get_contents($berkasLogo)) . '" alt="PHR"></div>'
    : '';

$html = '
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
    @page {
        margin: 25px;
    }

    body {
        font-family: DejaVu Sans, sans-serif;
        color: #1e293b;
        font-size: 9px;
    }

    h1 {
        text-align: center;
        font-size: 17px;
        margin-bottom: 5px;
    }

    .subjudul {
        text-align: center;
        margin-bottom: 20px;
        color: #475569;
    }

    .kop {
        text-align: center;
        margin-bottom: 4px;
    }

    .kop img {
        height: 58px;
    }

    .filter {
        margin-bottom: 14px;
        line-height: 1.8;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th, td {
        border: 1px solid #cbd5e1;
        padding: 7px;
        vertical-align: top;
    }

    th {
        background-color: #e2e8f0;
        text-align: center;
    }

    .center {
        text-align: center;
    }

    tr {
        page-break-inside: avoid;
    }

    .footer {
        margin-top: 15px;
        text-align: right;
        font-size: 8px;
        color: #64748b;
    }
</style>
</head>
<body>
' . $kopLogo . '
<h1>REKAP LAPORAN KEGIATAN</h1>
<div class="subjudul">
    Rekapitulasi Laporan Kegiatan Karyawan
</div>

<div class="filter">
    <strong>Periode:</strong> ' . ePDF($periode) . '<br>
    <strong>Status:</strong> ' . ePDF($status_tampil) . '<br>
    <strong>Karyawan:</strong> ' . ePDF($karyawan_tampil) . '
</div>

<table>
    <thead>
        <tr>
            <th style="width: 4%;">No.</th>
            <th style="width: 17%;">Nama Karyawan</th>
            <th style="width: 13%;">Divisi</th>
            <th style="width: 12%;">Tanggal</th>
            <th style="width: 36%;">Kegiatan</th>
            <th style="width: 18%;">Status</th>
        </tr>
    </thead>
    <tbody>
        ' . $baris . '
    </tbody>
</table>

<div class="footer">
    Total laporan: ' . ($nomor - 1) . '
</div>

</body>
</html>
';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();

$stmt->close();

$pdf->stream('rekap_laporan_harian.pdf', [
    'Attachment' => true
]);

exit;

