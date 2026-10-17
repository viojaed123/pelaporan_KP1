<?php
/**
 * export_excel.php — unduh rekap laporan dalam berkas Excel (.xlsx).
 *
 * Halaman DOWNLOAD: TIDAK memakai mulai_halaman()/akhiri_halaman() karena
 * keluarannya berupa berkas, bukan HTML.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran (semula session_start + cek $_SESSION manual)
 *   · BUG: $koneksi->close() memakai variabel yang tidak ada ($koneksi) —
 *     dihapus (variabel koneksi bernama $conn; mysqli tidak perlu ditutup
 *     eksplisit karena ditutup otomatis saat request selesai)
 *   · KEAMANAN: seluruh sel data (dan nilai filter) dinetralkan dari
 *     CSV/Excel formula injection lewat amankan_sel()
 *
 * Batas akses: berkas ini hanya dipanggil dari rekap_laporan.php (mentor/admin),
 * maka ekspor dibatasi untuk peran mentor & admin. Bila kelak dipakai karyawan
 * untuk mengekspor laporannya sendiri, ganti dengan wajib_login() + filter
 * WHERE l.user_id = sesi_user_id().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

/**
 * Netralkan nilai sel dari serangan CSV/Excel formula injection.
 * Bila string dimulai dengan = + - @ atau TAB/CR, beri awalan apostrof (')
 * agar aplikasi spreadsheet memperlakukannya sebagai teks biasa.
 */
if (!function_exists('amankan_sel')) {
    function amankan_sel(mixed $nilai): mixed
    {
        if (!is_string($nilai) || $nilai === '') {
            return $nilai;
        }

        $awal = $nilai[0];
        if (in_array($awal, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $nilai;
        }

        return $nilai;
    }
}

/* ---------------------------------------------------------------
 * Filter (nama parameter dipertahankan sama dengan rekap_laporan.php).
 * ------------------------------------------------------------- */

$tanggal_mulai = trim((string) ($_GET['tanggal_mulai'] ?? ''));
$tanggal_akhir = trim((string) ($_GET['tanggal_akhir'] ?? ''));
$status        = trim((string) ($_GET['status'] ?? ''));
$karyawan      = trim((string) ($_GET['karyawan'] ?? ''));

$sql = "SELECT l.id, l.tanggal, u.nama, u.divisi,
               l.kegiatan, l.status
        FROM laporan_harian l
        JOIN users u ON l.user_id = u.id
        WHERE u.role = 'karyawan'";

$params = [];
$types = '';

if ($tanggal_mulai !== '') {
    $sql .= " AND l.tanggal >= ?";
    $params[] = $tanggal_mulai;
    $types .= 's';
}

if ($tanggal_akhir !== '') {
    $sql .= " AND l.tanggal < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $tanggal_akhir;
    $types .= 's';
}

if ($status !== '') {
    $sql .= " AND l.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($karyawan !== '' && ctype_digit($karyawan)) {
    $sql .= " AND u.id = ?";
    $params[] = (int) $karyawan;
    $types .= 'i';
}

$sql .= " ORDER BY l.tanggal DESC, l.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit('Gagal menyiapkan query: ' . $conn->error);
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Rekap Laporan Harian');

$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', 'REKAP LAPORAN KEGIATAN');

$sheet->getStyle('A1:F1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 16,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1F4E78']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER
    ]
]);

// Lambang PHR di kop laporan (kiri judul). Tinggi baris 1 dinaikkan agar
// gambar tidak terpotong.
$berkasLogo = __DIR__ . '/assets/img/logo-phr.png';
if (is_file($berkasLogo)) {
    $sheet->getRowDimension(1)->setRowHeight(52);

    $lambang = new Drawing();
    $lambang->setName('Lambang PHR');
    $lambang->setPath($berkasLogo);
    $lambang->setHeight(40);
    $lambang->setCoordinates('A1');
    $lambang->setOffsetX(8);
    $lambang->setOffsetY(7);
    $lambang->setWorksheet($sheet);
}

// Nilai filter berasal dari input pengguna -> tetap dinetralkan.
$sheet->setCellValue('A3', 'Tanggal Mulai');
$sheet->setCellValue('B3', amankan_sel($tanggal_mulai !== '' ? $tanggal_mulai : 'Semua'));
$sheet->setCellValue('C3', 'Tanggal Akhir');
$sheet->setCellValue('D3', amankan_sel($tanggal_akhir !== '' ? $tanggal_akhir : 'Semua'));
$sheet->setCellValue('E3', 'Status');
$sheet->setCellValue('F3', amankan_sel($status !== '' ? $status : 'Semua'));

$headers = [
    'A5' => 'ID',
    'B5' => 'Tanggal',
    'C5' => 'Nama Karyawan',
    'D5' => 'Divisi',
    'E5' => 'Kegiatan',
    'F5' => 'Status'
];

foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
}

$sheet->getStyle('A5:F5')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '4472C4']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER
    ]
]);

$row = 6;

while ($data = $result->fetch_assoc()) {
    // Setiap sel data dinetralkan sebelum dimasukkan.
    $sheet->setCellValue('A' . $row, amankan_sel($data['id']));
    $sheet->setCellValue('B' . $row, amankan_sel($data['tanggal']));
    $sheet->setCellValue('C' . $row, amankan_sel($data['nama']));
    $sheet->setCellValue('D' . $row, amankan_sel($data['divisi']));
    $sheet->setCellValue('E' . $row, amankan_sel($data['kegiatan']));
    $sheet->setCellValue('F' . $row, amankan_sel($data['status']));
    $row++;
}

$lastRow = max(5, $row - 1);

$sheet->getStyle("A5:F{$lastRow}")
      ->getBorders()
      ->getAllBorders()
      ->setBorderStyle(
          \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
      );

foreach (range('A', 'F') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$sheet->getColumnDimension('E')->setWidth(45);
$sheet->getStyle("E6:E{$lastRow}")
      ->getAlignment()
      ->setWrapText(true);

$sheet->freezePane('A6');
$sheet->setAutoFilter("A5:F{$lastRow}");

$filename = 'rekap_laporan_harian_' . date('Ymd_His') . '.xlsx';

$stmt->close();

// Buang keluaran tak sengaja agar header unduhan tidak terganggu.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
exit;
