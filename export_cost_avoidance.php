<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

wajib_login();

if (!function_exists('ca_sel_aman')) {
    function ca_sel_aman(mixed $nilai): mixed
    {
        if (!is_string($nilai) || $nilai === '') {
            return $nilai;
        }

        if (in_array($nilai[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $nilai;
        }

        return $nilai;
    }
}

$uid   = (int) sesi_user_id();
$role  = (string) sesi_role();
$penuh = cost_avoidance_peran_penuh($role);

$format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    http_response_code(400);
    exit('Format ekspor tidak dikenal.');
}

$status = trim((string) ($_GET['status'] ?? ''));
if (!in_array($status, cost_avoidance_status_daftar(), true)) {
    $status = '';
}

$kategori = trim((string) ($_GET['kategori'] ?? ''));
if (!in_array($kategori, cost_avoidance_kategori_daftar(), true)) {
    $kategori = '';
}

$bulan = trim((string) ($_GET['bulan'] ?? ''));
if (preg_match('/^\d{4}-\d{2}$/', $bulan) !== 1) {
    $bulan = '';
}

$filter = ['status' => $status, 'kategori' => $kategori, 'bulan' => $bulan];
if (!$penuh) {
    $filter['pemilik'] = $uid;
}

$hasil = cost_avoidance_ambil($conn, $filter, 1000000, 0);
$baris = $hasil['baris'];

$totalDisetujui  = 0.0;
$jumlahDisetujui = 0;
$jumlahDiajukan  = 0;

foreach ($baris as $r) {
    $st = (string) $r['status'];
    if ($st === 'disetujui') {
        $totalDisetujui += (float) $r['nilai_avoidance'];
        $jumlahDisetujui++;
    } elseif ($st === 'diajukan') {
        $jumlahDiajukan++;
    }
}

$periodeTeks   = $bulan === '' ? 'Semua periode' : cost_avoidance_periode_label($bulan . '-01');
$statusTeks    = $status === '' ? 'Semua status' : cost_avoidance_label_status($status);
$kategoriTeks  = $kategori === '' ? 'Semua kategori' : $kategori;
$cakupanTeks   = $penuh ? 'Seluruh pengguna' : 'Data Anda';

if ($format === 'xlsx') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Cost Avoidance');

    $sheet->mergeCells('A1:L1');
    $sheet->setCellValue('A1', 'COST AVOIDANCE');

    $sheet->getStyle('A1:L1')->applyFromArray([
        'font' => [
            'bold'  => true,
            'size'  => 16,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '003D7D'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
        ],
    ]);

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

    $sheet->setCellValue('A3', 'Periode');
    $sheet->setCellValue('B3', ca_sel_aman($periodeTeks));
    $sheet->setCellValue('C3', 'Status');
    $sheet->setCellValue('D3', ca_sel_aman($statusTeks));
    $sheet->setCellValue('E3', 'Kategori');
    $sheet->setCellValue('F3', ca_sel_aman($kategoriTeks));
    $sheet->setCellValue('G3', 'Cakupan');
    $sheet->setCellValue('H3', ca_sel_aman($cakupanTeks));

    $sheet->setCellValue('A4', 'Total Disetujui');
    $sheet->setCellValue('B4', $totalDisetujui);
    $sheet->setCellValue('C4', 'Entri Disetujui');
    $sheet->setCellValue('D4', $jumlahDisetujui);
    $sheet->setCellValue('E4', 'Menunggu Verifikasi');
    $sheet->setCellValue('F4', $jumlahDiajukan);
    $sheet->getStyle('B4')->getNumberFormat()->setFormatCode('#,##0');

    $headers = [
        'A6' => 'Kode',
        'B6' => 'Uraian',
        'C6' => 'Kategori',
        'D6' => 'Unit',
        'E6' => 'Periode',
        'F6' => 'Estimasi',
        'G6' => 'Realisasi',
        'H6' => 'Nilai Avoidance',
        'I6' => 'Status',
        'J6' => 'Pembuat',
        'K6' => 'Verifikator',
        'L6' => 'Tanggal Verifikasi',
    ];

    foreach ($headers as $sel => $nilai) {
        $sheet->setCellValue($sel, $nilai);
    }

    $sheet->getStyle('A6:L6')->applyFromArray([
        'font' => [
            'bold'  => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '005EB8'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    $row = 7;

    foreach ($baris as $r) {
        $sheet->setCellValue('A' . $row, ca_sel_aman((string) $r['kode']));
        $sheet->setCellValue('B' . $row, ca_sel_aman((string) $r['judul']));
        $sheet->setCellValue('C' . $row, ca_sel_aman((string) $r['kategori']));
        $sheet->setCellValue('D' . $row, ca_sel_aman((string) ($r['unit'] ?? '')));
        $sheet->setCellValue('E' . $row, ca_sel_aman(substr((string) $r['periode'], 0, 7)));
        $sheet->setCellValue('F' . $row, (float) $r['estimasi']);
        $sheet->setCellValue('G' . $row, (float) $r['realisasi']);
        $sheet->setCellValue('H' . $row, (float) $r['nilai_avoidance']);
        $sheet->setCellValue('I' . $row, ca_sel_aman(cost_avoidance_label_status((string) $r['status'])));
        $sheet->setCellValue('J' . $row, ca_sel_aman((string) ($r['nama_pembuat'] ?? '')));
        $sheet->setCellValue('K' . $row, ca_sel_aman((string) ($r['nama_verifikator'] ?? '')));
        $sheet->setCellValue('L' . $row, ca_sel_aman((string) ($r['diverifikasi_pada'] ?? '')));
        $row++;
    }

    $lastRow = max(6, $row - 1);

    $sheet->getStyle("A6:L{$lastRow}")
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(
            \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
        );

    foreach (['F', 'G', 'H'] as $kolom) {
        $sheet->getStyle("{$kolom}7:{$kolom}{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0');
    }

    foreach (range('A', 'L') as $kolom) {
        $sheet->getColumnDimension($kolom)->setAutoSize(true);
    }

    $sheet->getColumnDimension('B')->setWidth(42);
    $sheet->getStyle("B7:B{$lastRow}")->getAlignment()->setWrapText(true);

    $sheet->freezePane('A7');
    $sheet->setAutoFilter("A6:L{$lastRow}");

    $filename = 'cost_avoidance_' . date('Ymd_His') . '.xlsx';

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
}

$berkasLogo = __DIR__ . '/assets/img/logo-phr.png';
$kop = is_file($berkasLogo)
    ? '<div class="kop"><img src="data:image/png;base64,'
        . base64_encode((string) file_get_contents($berkasLogo)) . '" alt="PHR"></div>'
    : '';

$tabel = '';
$nomor = 1;

foreach ($baris as $r) {
    $tabel .= '
        <tr>
            <td class="center">' . $nomor++ . '</td>
            <td>' . e((string) $r['kode']) . '</td>
            <td>' . e((string) $r['judul']) . '</td>
            <td>' . e((string) $r['kategori']) . '</td>
            <td class="center">' . e(substr((string) $r['periode'], 0, 7)) . '</td>
            <td class="kanan">' . e(cost_avoidance_rupiah($r['estimasi'])) . '</td>
            <td class="kanan">' . e(cost_avoidance_rupiah($r['realisasi'])) . '</td>
            <td class="kanan"><strong>' . e(cost_avoidance_rupiah($r['nilai_avoidance'])) . '</strong></td>
            <td>' . e(cost_avoidance_label_status((string) $r['status'])) . '</td>
            <td>' . e((string) ($r['nama_pembuat'] ?? '-')) . '</td>
        </tr>
    ';
}

if ($tabel === '') {
    $tabel = '
        <tr>
            <td colspan="10" class="center">Tidak ada data pada filter ini.</td>
        </tr>
    ';
}

$html = '
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 24px; }
    body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 8px; }
    h1 { text-align: center; font-size: 16px; margin: 0 0 3px; }
    .subjudul { text-align: center; color: #475569; margin-bottom: 14px; }
    .kop { text-align: center; margin-bottom: 4px; }
    .kop img { height: 54px; }
    .filter { margin-bottom: 12px; line-height: 1.7; }
    .ringkas { margin-top: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; }
    th { background-color: #e2e8f0; text-align: center; }
    .center { text-align: center; }
    .kanan { text-align: right; }
    tr { page-break-inside: avoid; }
    .footer { margin-top: 12px; text-align: right; color: #64748b; }
</style>
</head>
<body>
' . $kop . '
<h1>COST AVOIDANCE</h1>
<div class="subjudul">Nilai penghematan yang berhasil dihindari</div>

<div class="filter">
    <strong>Periode:</strong> ' . e($periodeTeks) . '<br>
    <strong>Status:</strong> ' . e($statusTeks) . '<br>
    <strong>Kategori:</strong> ' . e($kategoriTeks) . '<br>
    <strong>Cakupan:</strong> ' . e($cakupanTeks) . '
</div>

<table>
    <thead>
        <tr>
            <th style="width: 4%;">No.</th>
            <th style="width: 9%;">Kode</th>
            <th style="width: 29%;">Uraian</th>
            <th style="width: 15%;">Kategori</th>
            <th style="width: 7%;">Periode</th>
            <th style="width: 10%;">Estimasi</th>
            <th style="width: 9%;">Realisasi</th>
            <th style="width: 10%;">Avoidance</th>
            <th style="width: 9%;">Status</th>
            <th style="width: 9%;">Pembuat</th>
        </tr>
    </thead>
    <tbody>
        ' . $tabel . '
    </tbody>
</table>

<div class="ringkas">
    <strong>Total disetujui:</strong> ' . e(cost_avoidance_rupiah($totalDisetujui))
        . ' (' . $jumlahDisetujui . ' entri) &middot; '
        . $jumlahDiajukan . ' menunggu verifikasi
</div>

<div class="footer">Diekspor ' . e(date('d-m-Y H:i')) . '</div>
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

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="cost_avoidance_' . date('Ymd_His') . '.pdf"');
header('Cache-Control: max-age=0');

echo $pdf->output();
exit;
