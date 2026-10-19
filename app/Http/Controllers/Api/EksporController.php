<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Kasus;
use App\Models\LaporanHarian;
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

    /* ================================================================
     * PEMBANTU
     * ============================================================== */

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
