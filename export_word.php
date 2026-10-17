<?php
/**
 * export_word.php — unduh rekap laporan dalam berkas Microsoft Word (.doc).
 *
 * Halaman DOWNLOAD: TIDAK memakai mulai_halaman()/akhiri_halaman() karena
 * keluarannya berupa berkas, bukan HTML.
 *
 * Pendekatan tanpa dependensi: berkas .doc dibuat dari HTML yang dibungkus
 * dengan header XML kompatibel MS Office (<html xmlns:o=... xmlns:w=...>)
 * sehingga Word membukanya secara native. Tidak memakai PhpSpreadsheet/Dompdf.
 *
 * Data, filter GET (tanggal_mulai, tanggal_akhir, status, karyawan), penjaga
 * peran, dan query dipertahankan sama persis dengan export_excel.php agar
 * keluaran Word cocok baris-per-baris dengan keluaran Excel.
 *
 * Batas akses: berkas ini hanya dipanggil dari rekap_laporan.php
 * (mentor/admin), maka ekspor dibatasi untuk peran mentor & admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

/* ---------------------------------------------------------------
 * Filter (nama parameter dipertahankan sama dengan rekap_laporan.php
 * dan export_excel.php).
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

/* ---------------------------------------------------------------
 * Susun baris tabel. Seluruh nilai dinamis di-escape dengan e().
 * ------------------------------------------------------------- */

$baris = '';
$nomor = 1;

while ($data = $result->fetch_assoc()) {
    $kelas = $nomor % 2 === 0 ? ' class="genap"' : '';

    $baris .=
        '<tr' . $kelas . '>' .
        '<td class="tengah">' . $nomor . '</td>' .
        '<td>' . e($data['nama']) . '</td>' .
        '<td>' . e($data['divisi'] !== '' && $data['divisi'] !== null ? $data['divisi'] : '-') . '</td>' .
        '<td>' . e(tanggal_id((string) $data['tanggal'])) . '</td>' .
        '<td>' . nl2br(e($data['kegiatan'])) . '</td>' .
        '<td>' . e(label_status((string) $data['status'])) . '</td>' .
        '</tr>';

    $nomor++;
}

if ($baris === '') {
    $baris =
        '<tr>' .
        '<td colspan="6" class="tengah">' .
        'Tidak ada laporan yang sesuai dengan filter.' .
        '</td>' .
        '</tr>';
}

$total_laporan = $nomor - 1;

/* ---------------------------------------------------------------
 * Nilai tampilan filter.
 * ------------------------------------------------------------- */

$tanggal_mulai_tampil = $tanggal_mulai !== '' ? $tanggal_mulai : 'Semua';
$tanggal_akhir_tampil = $tanggal_akhir !== '' ? $tanggal_akhir : 'Semua';
$status_tampil        = $status !== '' ? label_status($status) : 'Semua Status';

$karyawan_tampil = 'Semua Karyawan';

if ($karyawan !== '' && ctype_digit($karyawan)) {
    $stmtNama = $conn->prepare(
        "SELECT nama FROM users
         WHERE id = ? AND role = 'karyawan'"
    );

    if ($stmtNama) {
        $idKaryawan = (int) $karyawan;
        $stmtNama->bind_param('i', $idKaryawan);
        $stmtNama->execute();
        $resultNama = $stmtNama->get_result();
        $namaRow = $resultNama->fetch_assoc();

        if ($namaRow) {
            $karyawan_tampil = (string) $namaRow['nama'];
        }

        $stmtNama->close();
    }
}

$dibuat_pada = tanggal_id(date('Y-m-d')) . ', ' . date('H:i') . ' WIB';

/* ---------------------------------------------------------------
 * Dokumen Word (HTML + header XML MS Office).
 * ------------------------------------------------------------- */

// Lambang PHR untuk kop laporan Word. Word (HTML) paling andal memuat gambar
// dari jalur berkas lokal, jadi dipakai jalur absolut berkas ini.
$berkasLogo = __DIR__ . '/assets/img/logo-phr.png';
$kopLogo = is_file($berkasLogo)
    ? '<div class="kop"><img src="' . e('file:///' . str_replace('\\', '/', $berkasLogo)) . '" alt="PHR"></div>'
    : '';

$html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
      . 'xmlns:w="urn:schemas-microsoft-com:office:word" '
      . 'xmlns="http://www.w3.org/TR/REC-html40">'
      . '<head>'
      . '<meta charset="utf-8">'
      . '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
      . '<title>Rekap Laporan Kegiatan</title>'
      . '<!--[if gte mso 9]>'
      . '<xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom>'
      . '</w:WordDocument></xml>'
      . '<![endif]-->'
      . '<style>'
      . '@page { size: A4 landscape; margin: 1.5cm; }'
      . 'body { font-family: Calibri, Arial, sans-serif; color: #1e293b; font-size: 10pt; }'
      . 'h1 { color: #12345A; font-size: 16pt; text-align: center; margin: 0 0 4pt 0; }'
      . '.subjudul { text-align: center; color: #475569; font-size: 10pt; margin-bottom: 12pt; }'
      . '.info { margin-bottom: 10pt; font-size: 10pt; line-height: 1.6; }'
      . '.info strong { color: #12345A; }'
      . 'table { border-collapse: collapse; width: 100%; }'
      . 'th { background-color: #12345A; color: #ffffff; border: 1px solid #12345A;'
      . ' padding: 6pt 8pt; text-align: left; font-size: 10pt; }'
      . 'td { border: 1px solid #b9c6d8; padding: 6pt 8pt; vertical-align: top; font-size: 10pt; }'
      . 'tr.genap td { background-color: #F3F7FB; }'
      . 'td.tengah, th.tengah { text-align: center; }'
      . '.footer { margin-top: 10pt; text-align: right; color: #64748b; font-size: 8pt; }'
      . '</style>'
      . '</head>'
      . '.kop { text-align: center; margin-bottom: 4pt; }'
      . '.kop img { height: 40pt; }'
      . '<body>'
      . $kopLogo
      . '<h1>REKAP LAPORAN KEGIATAN</h1>'
      . '<div class="subjudul">Rekapitulasi Laporan Kegiatan Karyawan</div>'
      . '<div class="info">'
      . '<strong>Tanggal Mulai:</strong> ' . e($tanggal_mulai_tampil) . '<br>'
      . '<strong>Tanggal Akhir:</strong> ' . e($tanggal_akhir_tampil) . '<br>'
      . '<strong>Status:</strong> ' . e($status_tampil) . '<br>'
      . '<strong>Karyawan:</strong> ' . e($karyawan_tampil) . '<br>'
      . '<strong>Dibuat:</strong> ' . e($dibuat_pada)
      . '</div>'
      . '<table>'
      . '<thead>'
      . '<tr>'
      . '<th class="tengah" style="width: 6%;">No.</th>'
      . '<th style="width: 20%;">Nama Karyawan</th>'
      . '<th style="width: 13%;">Divisi</th>'
      . '<th style="width: 14%;">Tanggal</th>'
      . '<th style="width: 32%;">Kegiatan</th>'
      . '<th style="width: 15%;">Status</th>'
      . '</tr>'
      . '</thead>'
      . '<tbody>'
      . $baris
      . '</tbody>'
      . '</table>'
      . '<div class="footer">Total laporan: ' . $total_laporan . '</div>'
      . '</body>'
      . '</html>';

$filename = 'rekap-laporan-' . date('Ymd-Hi') . '.doc';

$stmt->close();

// Buang keluaran tak sengaja agar header unduhan tidak terganggu.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/msword; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo $html;

exit;
