<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\CostAvoidance;
use App\Models\Kasus;
use App\Models\LaporanHarian;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Ekspor XLSX dari API (untuk halaman rekap SPA & kebutuhan lain).
 *
 * Berkas .xlsx asli (bukan CSV) -- sama seperti ekspor legacy
 * `rekap_absensi.php`/`export_excel.php`. Warna kepala memakai biru
 * korporat #003D7D (teks putih tebal) agar konsisten dengan cetak
 * legacy. Semua endpoint memakai token Sanctum; rekap & kasus
 * dibatasi mentor/admin lewat middleware `role` di routes/api.php.
 */
class EksporController extends Controller
{
    /** Warna korporat: kepala tabel. */
    private const WARNA_KEPALA = '003D7D';

    /** GET /api/v1/ekspor/laporan — rekap laporan (mentor/admin). */
    public function laporan(Request $request)
    {
        $request->validate([
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_akhir' => ['nullable', 'date'],
            'status'        => ['nullable', 'string', 'max:30'],
            'karyawan'      => ['nullable', 'integer'],
        ]);

        $q = LaporanHarian::query()
            ->join('users', 'users.id', '=', 'laporan_harian.user_id')
            ->where('users.role', 'karyawan')
            ->select(
                'laporan_harian.tanggal', 'laporan_harian.jam_mulai', 'laporan_harian.jam_selesai',
                'laporan_harian.kegiatan', 'laporan_harian.lokasi_teks', 'laporan_harian.hasil',
                'laporan_harian.kendala', 'laporan_harian.status',
                'users.nama as karyawan', 'users.divisi',
            )
            ->orderBy('laporan_harian.tanggal')->orderBy('laporan_harian.id');

        if ($request->filled('tanggal_mulai')) {
            $q->whereDate('laporan_harian.tanggal', '>=', (string) $request->query('tanggal_mulai'));
        }
        if ($request->filled('tanggal_akhir')) {
            $q->whereDate('laporan_harian.tanggal', '<=', (string) $request->query('tanggal_akhir'));
        }
        $status = (string) $request->query('status', '');
        if ($status !== '') {
            $q->where('laporan_harian.status', $status);
        }
        if ($request->filled('karyawan')) {
            $q->where('laporan_harian.user_id', (int) $request->query('karyawan'));
        }

        $baris = $q->get()->map(fn ($r) => [
            (string) optional($r->tanggal)->toDateString(),
            (string) $r->karyawan,
            $r->divisi !== null ? (string) $r->divisi : '',
            (string) $r->kegiatan,
            $r->jam_mulai !== null ? (string) $r->jam_mulai : '',
            $r->jam_selesai !== null ? (string) $r->jam_selesai : '',
            (string) ($r->lokasi_teks ?? ''),
            (string) ($r->status ?? ''),
            (string) ($r->hasil ?? ''),
            (string) ($r->kendala ?? ''),
        ])->values();

        $buku = $this->buku(
            'Rekap Laporan Harian',
            $this->periodeTeks((string) $request->query('tanggal_mulai'), (string) $request->query('tanggal_akhir')),
            ['Tanggal', 'Karyawan', 'Divisi', 'Kegiatan', 'Jam Mulai', 'Jam Selesai', 'Lokasi', 'Status', 'Hasil', 'Kendala'],
            $baris->all(),
        );

        return $this->kirimXlsx($buku, 'rekap_laporan_' . now()->format('Ymd_His') . '.xlsx');
    }

    /** GET /api/v1/ekspor/absensi — rekap absensi (mentor/admin). */
    public function absensi(Request $request)
    {
        $request->validate([
            'awal'  => ['nullable', 'date'],
            'akhir' => ['nullable', 'date'],
        ]);

        $awal  = (string) ($request->query('awal') ?: now()->startOfMonth()->toDateString());
        $akhir = (string) ($request->query('akhir') ?: now()->toDateString());
        if ($awal > $akhir) {
            [$awal, $akhir] = [$akhir, $awal];
        }

        $baris = Absensi::query()
            ->join('users', 'users.id', '=', 'absensi.user_id')
            ->whereDate('absensi.tanggal', '>=', $awal)
            ->whereDate('absensi.tanggal', '<=', $akhir)
            ->select(
                'absensi.tanggal', 'absensi.jam_masuk', 'absensi.jam_pulang', 'absensi.status',
                'absensi.keterangan', 'absensi.kegiatan_hari_ini', 'absensi.kondisi_kesehatan',
                'users.nama', 'users.email', 'users.divisi',
            )
            ->orderBy('absensi.tanggal')->orderBy('users.nama')
            ->get()
            ->map(fn ($r) => [
                (string) optional($r->tanggal)->toDateString(),
                (string) $r->nama,
                (string) ($r->divisi ?? ''),
                $r->jam_masuk !== null ? (string) $r->jam_masuk : '',
                $r->jam_pulang !== null ? (string) $r->jam_pulang : '',
                (string) ($r->status ?? ''),
                (string) ($r->kondisi_kesehatan ?? ''),
                (string) ($r->kegiatan_hari_ini ?? ''),
                (string) ($r->keterangan ?? ''),
            ])->values();

        $buku = $this->buku(
            'Rekap Absensi',
            $this->periodeTeks($awal, $akhir),
            ['Tanggal', 'Nama', 'Divisi', 'Jam Masuk', 'Jam Pulang', 'Status', 'Kondisi Kesehatan', 'Kegiatan', 'Keterangan'],
            $baris->all(),
        );

        return $this->kirimXlsx($buku, 'rekap_absensi_' . now()->format('Ymd_His') . '.xlsx');
    }

    /** GET /api/v1/ekspor/kasus — rekap kasus (mentor/admin). */
    public function kasus(Request $request)
    {
        $request->validate([
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_akhir' => ['nullable', 'date'],
            'status'        => ['nullable', 'string', 'max:30'],
            'urgensi'       => ['nullable', 'string', 'max:30'],
            'kategori'      => ['nullable', 'string', 'max:60'],
        ]);

        $q = Kasus::query()
            ->with(['penanggungJawab:id,nama', 'dibuatOleh:id,nama'])
            ->orderBy('kasus.id');

        if ($request->filled('tanggal_mulai')) {
            $q->whereDate('kasus.dibuat_pada', '>=', (string) $request->query('tanggal_mulai'));
        }
        if ($request->filled('tanggal_akhir')) {
            $q->whereDate('kasus.dibuat_pada', '<=', (string) $request->query('tanggal_akhir'));
        }
        if ($request->filled('status')) {
            $q->where('kasus.status_kasus', (string) $request->query('status'));
        }
        if ($request->filled('urgensi')) {
            $q->where('kasus.tingkat_urgensi', (string) $request->query('urgensi'));
        }
        if ($request->filled('kategori')) {
            $q->where('kasus.kategori', (string) $request->query('kategori'));
        }

        $baris = $q->get()->map(fn (Kasus $k) => [
            (string) $k->kode_kasus,
            (string) $k->judul,
            (string) ($k->kategori ?? ''),
            (string) ($k->tingkat_urgensi ?? ''),
            (string) ($k->status_kasus ?? ''),
            (string) ($k->progres ?? 0),
            (string) ($k->penanggungJawab?->nama ?? $k->penanggung_jawab_teks ?? ''),
            (string) ($k->nama_lokasi ?? ''),
            (string) ($k->dibuatOleh?->nama ?? ''),
            optional($k->target_selesai)->toDateString() ?? '',
            $k->anggota()->count(),
            optional($k->dibuat_pada)->toDateString(),
        ])->values();

        $buku = $this->buku(
            'Rekap Kasus',
            $this->periodeTeks((string) $request->query('tanggal_mulai'), (string) $request->query('tanggal_akhir')),
            ['Kode', 'Judul', 'Kategori', 'Urgensi', 'Status', 'Progres (%)', 'Penanggung Jawab',
             'Lokasi', 'Dibuat Oleh', 'Target Selesai', 'Jumlah Anggota', 'Tanggal Dibuat'],
            $baris->all(),
        );

        return $this->kirimXlsx($buku, 'rekap_kasus_' . now()->format('Ymd_His') . '.xlsx');
    }

    /** GET /api/v1/ekspor/cost-avoidance — ekspor cost avoidance (semua peran). */
    public function costAvoidance(Request $request)
    {
        $request->validate([
            'format'   => ['nullable', 'in:xlsx,pdf'],
            'status'   => ['nullable', 'string', 'max:30'],
            'kategori' => ['nullable', 'string', 'max:80'],
            'bulan'    => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $user = $request->user();
        $q = CostAvoidance::query()
            ->with(['pembuat:id,nama', 'verifikator:id,nama'])
            ->orderByDesc('periode')
            ->orderByDesc('id');

        if (! in_array($user->role, ['mentor', 'admin'], true)) {
            $q->where('dibuat_oleh', (int) $user->id);
        }

        $status = (string) $request->query('status', '');
        if ($status !== '') {
            $q->where('status', $status);
        }

        $kategori = (string) $request->query('kategori', '');
        if ($kategori !== '') {
            $q->where('kategori', $kategori);
        }

        $bulan = (string) $request->query('bulan', '');
        if ($bulan !== '') {
            $q->whereBetween('periode', [$bulan . '-01', date('Y-m-t', (int) strtotime($bulan . '-01'))]);
        }

        $baris = $q->get();
        $cakupan = in_array($user->role, ['mentor', 'admin'], true)
            ? 'Seluruh pengguna'
            : 'Data pengguna sendiri';

        $periode = 'Seluruh periode data';
        if ($bulan !== '') {
            $ts = strtotime($bulan . '-01');
            $periode = 'Periode ' . ($ts !== false
                ? \Illuminate\Support\Carbon::createFromTimestamp($ts)->locale('id')->translatedFormat('F Y')
                : $bulan);
        }
        $periode .= ' -- diekspor ' . now()->format('d-m-Y H:i');

        $totalDisetujui = (float) $baris->where('status', 'disetujui')->sum('nilai_avoidance');
        $jumlahDisetujui = $baris->where('status', 'disetujui')->count();
        $jumlahDiajukan = $baris->where('status', 'diajukan')->count();

        if ((string) $request->query('format', 'xlsx') === 'pdf') {
            return $this->pdfCostAvoidance(
                $baris,
                $periode,
                $cakupan,
                (string) $request->query('status', ''),
                (string) $request->query('kategori', ''),
                $totalDisetujui,
                $jumlahDisetujui,
                $jumlahDiajukan,
            );
        }

        $kolom = [
            'Kode', 'Uraian', 'Kategori', 'Unit', 'Periode', 'Estimasi',
            'Realisasi', 'Nilai Avoidance', 'Status', 'Pembuat', 'Verifikator',
            'Tanggal Verifikasi',
        ];

        $data = $baris->map(fn (CostAvoidance $r) => [
            (string) $r->kode,
            (string) $r->judul,
            (string) $r->kategori,
            (string) ($r->unit ?? ''),
            $r->periode?->format('Y-m') ?? '',
            (float) $r->estimasi,
            (float) $r->realisasi,
            (float) $r->nilai_avoidance,
            $this->labelStatus((string) $r->status),
            (string) ($r->pembuat?->nama ?? ''),
            (string) ($r->verifikator?->nama ?? ''),
            $r->diverifikasi_pada?->format('d-m-Y H:i') ?? '',
        ])->values()->all();

        $buku = $this->buku(
            'Cost Avoidance',
            $periode . ' -- ' . $cakupan,
            $kolom,
            $data,
        );

        $lembar = $buku->getActiveSheet();
        $lebar = count($kolom);
        $terakhir = max(4, 3 + count($data));

        foreach (range(6, 8) as $kolomAngka) {
            $huruf = $this->kolomExcel($kolomAngka);
            $lembar->getStyle($huruf . '4:' . $huruf . $terakhir)
                ->getNumberFormat()
                ->setFormatCode('#,##0');
        }

        $lembar->getColumnDimension('B')->setWidth(42);
        $lembar->getStyle('B4:B' . $terakhir)->getAlignment()->setWrapText(true);
        $lembar->getStyle('A1:' . $this->kolomExcel($lebar) . '1')->getFont()->setSize(14);

        return $this->kirimXlsx($buku, 'cost_avoidance_' . now()->format('Ymd_His') . '.xlsx');
    }

    /* ================================================================
     * PEMBANTU
     * ============================================================== */

    private function pdfCostAvoidance(
        $baris,
        string $periode,
        string $cakupan,
        string $status,
        string $kategori,
        float $totalDisetujui,
        int $jumlahDisetujui,
        int $jumlahDiajukan,
    ) {
        $berkasLogo = public_path('assets/img/logo-phr.png');
        $kop = is_file($berkasLogo)
            ? '<div class="kop"><img src="data:image/png;base64,'
                . base64_encode((string) file_get_contents($berkasLogo)) . '" alt="PHR"></div>'
            : '';

        $tr = '';
        $nomor = 1;

        foreach ($baris as $r) {
            $tr .= '<tr>'
                . '<td class="center">' . $nomor++ . '</td>'
                . '<td>' . e((string) $r->kode) . '</td>'
                . '<td>' . e((string) $r->judul) . '</td>'
                . '<td>' . e((string) $r->kategori) . '</td>'
                . '<td class="center">' . e($r->periode?->format('Y-m') ?? '-') . '</td>'
                . '<td class="kanan">' . e($this->rupiah($r->estimasi)) . '</td>'
                . '<td class="kanan">' . e($this->rupiah($r->realisasi)) . '</td>'
                . '<td class="kanan"><strong>' . e($this->rupiah($r->nilai_avoidance)) . '</strong></td>'
                . '<td>' . e($this->labelStatus((string) $r->status)) . '</td>'
                . '<td>' . e((string) ($r->pembuat?->nama ?? '-')) . '</td>'
                . '</tr>';
        }

        if ($tr === '') {
            $tr = '<tr><td colspan="10" class="center">Tidak ada data pada filter ini.</td></tr>';
        }

        $filterStatus = $status === '' ? 'Semua status' : $this->labelStatus($status);
        $filterKategori = $kategori === '' ? 'Semua kategori' : $kategori;

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
    .ringkas { margin: 10px 0 0; }
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
    <strong>Periode:</strong> ' . e($periode) . '<br>
    <strong>Status:</strong> ' . e($filterStatus) . '<br>
    <strong>Kategori:</strong> ' . e($filterKategori) . '<br>
    <strong>Cakupan:</strong> ' . e($cakupan) . '
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
        ' . $tr . '
    </tbody>
</table>

<div class="ringkas">
    <strong>Total disetujui:</strong> ' . e($this->rupiah($totalDisetujui))
            . ' (' . $jumlahDisetujui . ' entri) &middot; '
            . $jumlahDiajukan . ' menunggu verifikasi
</div>

<div class="footer">Diekspor ' . e(now()->format('d-m-Y H:i')) . '</div>
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

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cost_avoidance_'
                . now()->format('Ymd_His') . '.pdf"',
        ]);
    }

    private function labelStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'diajukan' => 'Menunggu Verifikasi',
            'disetujui' => 'Disetujui',
            'ditolak' => 'Ditolak',
            default => ucfirst($status),
        };
    }

    private function rupiah(mixed $nilai): string
    {
        return 'Rp ' . number_format((float) $nilai, 0, ',', '.');
    }

    /** Susun buku: baris judul (merge) + periode + kepala + data. */
    private function buku(string $judul, string $periode, array $kolom, array $baris): Spreadsheet
    {
        $book = new Spreadsheet();
        /** @var Worksheet $lembar */
        $lembar = $book->getActiveSheet();
        $lembar->setTitle(mb_substr($judul, 0, 31));

        $lembar->setCellValue('A1', $judul);
        $lembar->mergeCells('A1:' . $this->kolomExcel(count($kolom)) . '1');
        $lembar->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => self::WARNA_KEPALA]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        $lembar->setCellValue('A2', $periode);
        $lembar->getStyle('A2')->getFont()->setItalic(true)->setSize(10);

        foreach ($kolom as $i => $nama) {
            $lembar->setCellValue($this->kolomExcel($i + 1) . '3', $nama);
        }
        $lembar->getStyle('A3:' . $this->kolomExcel(count($kolom)) . '3')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                             'startColor' => ['rgb' => self::WARNA_KEPALA]],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $lembar->setAutoFilter('A3:' . $this->kolomExcel(count($kolom)) . '3');
        $lembar->freezePane('A4');

        if ($baris !== []) {
            $lembar->fromArray($baris, null, 'A4');
        }

        foreach (range(1, count($kolom)) as $i) {
            $lembar->getColumnDimension($this->kolomExcel($i))->setAutoSize(true);
        }

        return $book;
    }

    /** Kirim buku sebagai unduhan .xlsx. */
    private function kirimXlsx(Spreadsheet $buku, string $nama)
    {
        return response()->streamDownload(function () use ($buku) {
            (new Xlsx($buku))->save('php://output');
        }, $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Nomor kolom (1,2,...) menjadi huruf Excel (A,B,...). */
    private function kolomExcel(int $n): string
    {
        $huruf = '';
        while ($n > 0) {
            $sisa = ($n - 1) % 26;
            $huruf = chr(65 + $sisa) . $huruf;
            $n = intdiv($n - $sisa - 1, 26);
        }

        return $huruf;
    }

    /** Teks periode untuk baris judul kedua. */
    private function periodeTeks(string $mulai, string $selesai): string
    {
        if ($mulai === '' && $selesai === '') {
            return 'Seluruh periode data -- diekspor ' . now()->format('d-m-Y H:i');
        }

        return 'Periode ' . ($mulai !== '' ? $mulai : 'awal data')
            . ' s/d ' . ($selesai !== '' ? $selesai : 'hari ini')
            . ' -- diekspor ' . now()->format('d-m-Y H:i');
    }
}
