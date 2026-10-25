<?php
/**
 * admin_dashboard.php - ruang kerja administrator.
 *
 * Ringkasan:
 *   - bootstrap + penjaga peran admin
 *   - layout terpusat (memakai kelas app.css)
 *   - sambutan, peringatan akun menunggu verifikasi
 *   - ringkasan data (8 kartu statistik)
 *   - pintasan administrasi
 *   - aktivitas terbaru dari tabel audit_log
 *
 * Catatan: halaman ini sengaja ditulis tanpa emoji dan memakai ikon garis
 * (ikon_sp()) agar tampilan tetap bersih dan konsisten.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

$user_id = (int) sesi_user_id();

$admin = user_dari_db($conn, $user_id);
if ($admin === null) {
    $admin = ['nama' => sesi_nama(), 'role' => sesi_role()];
}

/* ---------------------------------------------------------------
 * 1. Ringkasan pengguna
 * ------------------------------------------------------------- */

$jumlahPengguna = ['total' => 0, 'menunggu' => 0, 'karyawan' => 0, 'mentor' => 0, 'admin' => 0];
$res = $conn->query(
    "SELECT role, status, COUNT(*) AS jumlah FROM users GROUP BY role, status"
);
while ($res && ($r = $res->fetch_assoc())) {
    $jumlahPengguna['total'] += (int) $r['jumlah'];
    if (($r['status'] ?? '') === 'menunggu') {
        $jumlahPengguna['menunggu'] += (int) $r['jumlah'];
    }
    $role = (string) ($r['role'] ?? '');
    if (isset($jumlahPengguna[$role])) {
        $jumlahPengguna[$role] += (int) $r['jumlah'];
    }
}

/* ---------------------------------------------------------------
 * 2. Ringkasan laporan
 * ------------------------------------------------------------- */

$statLaporan = ['draft' => 0, 'dikirim' => 0, 'diperiksa' => 0, 'perlu_revisi' => 0];
$res = $conn->query('SELECT status, COUNT(*) AS jumlah FROM laporan_harian GROUP BY status');
while ($res && ($r = $res->fetch_assoc())) {
    $k = (string) ($r['status'] ?? '');
    if (array_key_exists($k, $statLaporan)) {
        $statLaporan[$k] = (int) $r['jumlah'];
    }
}
$totalLaporan = array_sum($statLaporan);

/* ---------------------------------------------------------------
 * 3. Ringkasan data sosial
 * ------------------------------------------------------------- */

$jumlahSrm = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM srm_kegiatan');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahSrm = (int) $r['jumlah'];
}

$jumlahCid = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM cid_program');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahCid = (int) $r['jumlah'];
}

$jumlahWilayah = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM wilayah WHERE garis_lintang IS NOT NULL AND garis_bujur IS NOT NULL');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahWilayah = (int) $r['jumlah'];
}

$jumlahNotifikasi = jumlah_notifikasi($conn, $user_id);

/* ---------------------------------------------------------------
 * 4. Akun menunggu verifikasi (peringatan)
 * ------------------------------------------------------------- */

$akunMenunggu = [];
$stmt = $conn->prepare(
    "SELECT id, nama, email, role, created_at FROM users
      WHERE status = 'menunggu' ORDER BY created_at DESC LIMIT 6"
);
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $akunMenunggu[] = $r;
    }
    $stmt->close();
}

/* ---------------------------------------------------------------
 * 5. Aktivitas terbaru (audit_log)
 * ------------------------------------------------------------- */

$aktivitas = [];
$stmt = $conn->prepare(
    'SELECT a.id, a.aksi, a.tabel_ref, a.baris_id, a.ringkasan, a.dibuat_pada,
            u.nama AS nama_pengguna, u.role AS role_pengguna
       FROM audit_log a
       LEFT JOIN users u ON u.id = a.user_id
      ORDER BY a.id DESC LIMIT 8'
);
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $aktivitas[] = $r;
    }
    $stmt->close();
}

/* Label ramah untuk jenis aksi audit. */
$labelAksiAudit = static function (string $aksi): string {
    return match (strtolower(trim($aksi))) {
        'login'            => 'Masuk',
        'logout'           => 'Keluar',
        'login_gagal'      => 'Gagal masuk',
        'login_terkunci'   => 'Akun terkunci',
        'buat'             => 'Membuat data',
        'ubah', 'update'   => 'Memperbarui data',
        'hapus'            => 'Menghapus data',
        'impor_unggah'     => 'Unggah berkas impor',
        'impor_simpan'     => 'Simpan hasil impor',
        'impor_srm_cid'    => 'Impor SRM & CID',
        'kirim'            => 'Mengirim laporan',
        'periksa'          => 'Memeriksa laporan',
        'backup'           => 'Cadangan data',
        'arsip'            => 'Pengarsipan',
        default            => $aksi !== '' ? ucwords(str_replace('_', ' ', $aksi)) : 'Aktivitas',
    };
};

$jumlahAktivitasHariIni = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM audit_log WHERE DATE(dibuat_pada) = CURDATE()');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahAktivitasHariIni = (int) $r['jumlah'];
}

/* ---------------------------------------------------------------
 * 6. Data grafik (Chart.js vendor lokal: assets/js/vendor + grafik.js)
 * ------------------------------------------------------------- */

/* Tren 30 hari terakhir: jumlah laporan per tanggal & status. */
$trenLaporan = [];  // tanggal => [status => jumlah]
$res = $conn->query(
    "SELECT DATE(tanggal) AS tgl, status, COUNT(*) AS jumlah
       FROM laporan_harian
      WHERE tanggal >= CURDATE() - INTERVAL 29 DAY
      GROUP BY DATE(tanggal), status"
);
while ($res && ($r = $res->fetch_assoc())) {
    $trenLaporan[(string) $r['tgl']][(string) $r['status']] = (int) $r['jumlah'];
}

$labelTren = [];
$trenDraft = [];
$trenDikirim = [];
$trenDiperiksa = [];
$trenRevisi = [];
for ($i = 29; $i >= 0; $i--) {
    $tgl              = date('Y-m-d', strtotime("-{$i} days"));
    $baris            = $trenLaporan[$tgl] ?? [];
    $labelTren[]      = tanggal_id($tgl, 'd M');
    $trenDraft[]      = $baris['draft'] ?? 0;
    $trenDikirim[]    = $baris['dikirim'] ?? 0;
    $trenDiperiksa[]  = $baris['diperiksa'] ?? 0;
    $trenRevisi[]     = $baris['perlu_revisi'] ?? 0;
}

/* Distribusi tingkat urgensi kasus. */
$urgensiKasus = ['Rendah' => 0, 'Sedang' => 0, 'Tinggi' => 0, 'Kritis' => 0];
$res = $conn->query('SELECT tingkat_urgensi, COUNT(*) AS jumlah FROM kasus GROUP BY tingkat_urgensi');
while ($res && ($r = $res->fetch_assoc())) {
    $k = (string) ($r['tingkat_urgensi'] ?? '');
    if (array_key_exists($k, $urgensiKasus)) {
        $urgensiKasus[$k] = (int) $r['jumlah'];
    }
}

/* Absensi 30 hari terakhir (seluruh pengguna). */
$absensi30 = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
$res = $conn->query(
    "SELECT status, COUNT(*) AS jumlah FROM absensi
      WHERE tanggal >= CURDATE() - INTERVAL 29 DAY GROUP BY status"
);
while ($res && ($r = $res->fetch_assoc())) {
    $k = strtolower((string) ($r['status'] ?? ''));
    if (array_key_exists($k, $absensi30)) {
        $absensi30[$k] = (int) $r['jumlah'];
    }
}

/* Kegiatan SRM & CID per tahun intervensi (digabung pada satu grafik batang). */
$tahunGabung = [];
$res = $conn->query(
    'SELECT tahun_intervensi, COUNT(*) AS jumlah FROM srm_kegiatan GROUP BY tahun_intervensi'
);
while ($res && ($r = $res->fetch_assoc())) {
    $tahun = (int) ($r['tahun_intervensi'] ?? 0);
    if ($tahun > 1900) {
        $tahunGabung[$tahun]['srm'] = (int) $r['jumlah'];
    }
}
$res = $conn->query(
    'SELECT tahun_intervensi, COUNT(*) AS jumlah FROM cid_program GROUP BY tahun_intervensi'
);
while ($res && ($r = $res->fetch_assoc())) {
    $tahun = (int) ($r['tahun_intervensi'] ?? 0);
    if ($tahun > 1900) {
        $tahunGabung[$tahun]['cid'] = (int) $r['jumlah'];
    }
}
ksort($tahunGabung);
$labelTahun  = array_map('strval', array_keys($tahunGabung));
$srmPerTahun = array_map(static fn ($v): int => (int) ($v['srm'] ?? 0), $tahunGabung);
$cidPerTahun = array_map(static fn ($v): int => (int) ($v['cid'] ?? 0), $tahunGabung);

/* Encode aman untuk disisipkan ke <script>. */
$flagsJson = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$json = static fn (array $v): string => json_encode($v, $flagsJson) ?: '[]';

/* ---------------------------------------------------------------
 * 6b. Data grafik lanjutan — cakupan seluruh data proyek
 * ------------------------------------------------------------- */

/* Kepatuhan harian 30 hari: laporan masuk vs karyawan hadir. */
$kepatuhanLaporan = [];
$kepatuhanHadir   = [];
$res = $conn->query(
    "SELECT DATE(tanggal) AS tgl, COUNT(*) AS jumlah
       FROM laporan_harian WHERE tanggal >= CURDATE() - INTERVAL 29 DAY
      GROUP BY DATE(tanggal)"
);
while ($res && ($r = $res->fetch_assoc())) {
    $kepatuhanLaporan[(string) $r['tgl']] = (int) $r['jumlah'];
}
$res = $conn->query(
    "SELECT DATE(tanggal) AS tgl, COUNT(*) AS jumlah
       FROM absensi WHERE status = 'hadir' AND tanggal >= CURDATE() - INTERVAL 29 DAY
      GROUP BY DATE(tanggal)"
);
while ($res && ($r = $res->fetch_assoc())) {
    $kepatuhanHadir[(string) $r['tgl']] = (int) $r['jumlah'];
}
$angkaKepatuhanLaporan = [];
$angkaKepatuhanHadir   = [];
for ($i = 29; $i >= 0; $i--) {
    $tgl                       = date('Y-m-d', strtotime("-{$i} days"));
    $angkaKepatuhanLaporan[]   = $kepatuhanLaporan[$tgl] ?? 0;
    $angkaKepatuhanHadir[]     = $kepatuhanHadir[$tgl] ?? 0;
}

/* Aktivitas sistem 30 hari menurut kelompok aksi (batang bertumpuk). */
$audit30 = [];
$res = $conn->query(
    "SELECT DATE(dibuat_pada) AS tgl,
            CASE
              WHEN aksi IN ('login','logout','login_2fa_diminta','aktifkan_2fa','daftar',
                            'verifikasi_pengguna','reset_sandi','reset_sandi_otp_diminta',
                            'reset_sandi_pengguna','admin_setel_ulang','buat_kode_cadangan') THEN 'akses'
              WHEN aksi IN ('buat_laporan','kirim_laporan','mentor_periksa','ajukan_revisi',
                            'komentar_laporan') THEN 'laporan'
              WHEN aksi LIKE 'impor_%' OR aksi IN ('hapus_pengguna','ubah_status_pengguna',
                    'ubah_kegiatan_absensi','absensi_masuk','absensi_pulang','jadwal_buat',
                    'jadwal_hapus','ai_buat_jadwal','ai_ubah_jadwal','ai_hapus_jadwal',
                    'buat_kasus','hapus_kasus','perbarui_profil','unggah_foto_profil') THEN 'data'
              ELSE 'sistem'
            END AS kelompok,
            COUNT(*) AS jumlah
       FROM audit_log
      WHERE dibuat_pada >= CURDATE() - INTERVAL 29 DAY
      GROUP BY DATE(dibuat_pada), kelompok"
);
while ($res && ($r = $res->fetch_assoc())) {
    $audit30[(string) $r['tgl']][(string) $r['kelompok']] = (int) $r['jumlah'];
}
$auditPerKelompok = ['akses' => [], 'laporan' => [], 'data' => [], 'sistem' => []];
for ($i = 29; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-{$i} days"));
    foreach (array_keys($auditPerKelompok) as $kelompok) {
        $auditPerKelompok[$kelompok][] = $audit30[$tgl][$kelompok] ?? 0;
    }
}

/* Jenis aksi audit terbanyak (7 teratas). */
$topAksi = [];
$res = $conn->query(
    'SELECT aksi, COUNT(*) AS jumlah FROM audit_log GROUP BY aksi ORDER BY jumlah DESC LIMIT 7'
);
while ($res && ($r = $res->fetch_assoc())) {
    $topAksi[] = [
        'aksi'   => $labelAksiAudit((string) $r['aksi']),
        'jumlah' => (int) $r['jumlah'],
    ];
}

/* Penggunaan asisten AI 30 hari (pertanyaan vs balasan). */
$chat30 = [];
$res = $conn->query(
    "SELECT DATE(cm.dibuat_pada) AS tgl, cm.peran, COUNT(*) AS jumlah
       FROM chat_messages cm
       JOIN chat_sessions cs ON cs.id = cm.session_id
      WHERE cm.dibuat_pada >= CURDATE() - INTERVAL 29 DAY
      GROUP BY DATE(cm.dibuat_pada), cm.peran"
);
while ($res && ($r = $res->fetch_assoc())) {
    $chat30[(string) $r['tgl']][(string) $r['peran']] = (int) $r['jumlah'];
}
$chatTanya = [];
$chatBalas = [];
for ($i = 29; $i >= 0; $i--) {
    $tgl         = date('Y-m-d', strtotime("-{$i} days"));
    $chatTanya[] = $chat30[$tgl]['user'] ?? 0;
    $chatBalas[] = $chat30[$tgl]['assistant'] ?? 0;
}

/* Notifikasi: belum vs sudah dibaca. */
$notifDibaca = [0 => 0, 1 => 0];
$res = $conn->query('SELECT sudah_dibaca, COUNT(*) AS jumlah FROM notifikasi GROUP BY sudah_dibaca');
while ($res && ($r = $res->fetch_assoc())) {
    $notifDibaca[(int) $r['sudah_dibaca'] ? 1 : 0] = (int) $r['jumlah'];
}

/* Jadwal per status. */
$jadwalStatus = ['rencana' => 0, 'selesai' => 0, 'batal' => 0];
$res = $conn->query('SELECT status, COUNT(*) AS jumlah FROM jadwal GROUP BY status');
while ($res && ($r = $res->fetch_assoc())) {
    $k = (string) ($r['status'] ?? '');
    if (array_key_exists($k, $jadwalStatus)) {
        $jadwalStatus[$k] = (int) $r['jumlah'];
    }
}

/* Komentar pemeriksaan 30 hari. */
$komentar30 = [];
$res = $conn->query(
    'SELECT DATE(dibuat_pada) AS tgl, COUNT(*) AS jumlah FROM komentar_laporan
      WHERE dibuat_pada >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(dibuat_pada)'
);
while ($res && ($r = $res->fetch_assoc())) {
    $komentar30[(string) $r['tgl']] = (int) $r['jumlah'];
}
$angkaKomentar = [];
for ($i = 29; $i >= 0; $i--) {
    $angkaKomentar[] = $komentar30[date('Y-m-d', strtotime("-{$i} days"))] ?? 0;
}

/* --- SRM: area field, stakeholder, kategori, tren bulanan --- */
$srmArea = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(area_field), ''), '(Tanpa Area)') AS area, COUNT(*) AS jumlah
       FROM srm_kegiatan GROUP BY 1 ORDER BY jumlah DESC LIMIT 8"
);
while ($res && ($r = $res->fetch_assoc())) {
    $srmArea[] = ['label' => (string) $r['area'], 'jumlah' => (int) $r['jumlah']];
}

$srmStakeholder = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(jenis_stakeholder), ''), '(Tanpa Stakeholder)') AS jenis,
            COUNT(*) AS jumlah
       FROM srm_kegiatan GROUP BY 1 ORDER BY jumlah DESC LIMIT 7"
);
while ($res && ($r = $res->fetch_assoc())) {
    $srmStakeholder[] = ['label' => (string) $r['jenis'], 'jumlah' => (int) $r['jumlah']];
}

$srmKategori = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(kategori_kegiatan), ''), '(Tanpa Kategori)') AS kategori,
            COUNT(*) AS jumlah
       FROM srm_kegiatan GROUP BY 1 ORDER BY jumlah DESC LIMIT 7"
);
while ($res && ($r = $res->fetch_assoc())) {
    $srmKategori[] = ['label' => (string) $r['kategori'], 'jumlah' => (int) $r['jumlah']];
}

$namaBulanId = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

$srmBulan = [];
$res = $conn->query(
    "SELECT DATE_FORMAT(tanggal, '%Y-%m') AS bulan, COUNT(*) AS jumlah
       FROM srm_kegiatan WHERE tanggal IS NOT NULL GROUP BY 1 ORDER BY bulan"
);
while ($res && ($r = $res->fetch_assoc())) {
    $srmBulan[] = ['bulan' => (string) $r['bulan'], 'jumlah' => (int) $r['jumlah']];
}
$labelSrmBulan = [];
$angkaSrmBulan = [];
foreach ($srmBulan as $b) {
    $nomor           = (int) substr($b['bulan'], 5, 2);
    $labelSrmBulan[] = ($namaBulanId[$nomor] ?? $b['bulan']) . ' ' . substr($b['bulan'], 0, 4);
    $angkaSrmBulan[] = $b['jumlah'];
}

/* --- CID: pilar, kabupaten, penerima manfaat, anggaran bulanan --- */
$cidPilar = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(pilar_program), ''), '(Tanpa Pilar)') AS pilar,
            COUNT(*) AS jumlah, COALESCE(SUM(anggaran), 0) AS anggaran
       FROM cid_program GROUP BY 1 ORDER BY jumlah DESC"
);
while ($res && ($r = $res->fetch_assoc())) {
    $cidPilar[] = [
        'label'    => (string) $r['pilar'],
        'jumlah'   => (int) $r['jumlah'],
        'anggaran' => (int) round((float) $r['anggaran'] / 1000000),
    ];
}

$cidKabupaten = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(kabupaten_kota), ''), '(Tanpa Kabupaten)') AS kabupaten,
            COUNT(*) AS jumlah
       FROM cid_program GROUP BY 1 ORDER BY jumlah DESC LIMIT 8"
);
while ($res && ($r = $res->fetch_assoc())) {
    $cidKabupaten[] = ['label' => (string) $r['kabupaten'], 'jumlah' => (int) $r['jumlah']];
}

$cidGender  = ['Perempuan' => 0, 'Laki-laki' => 0];
$dampakCid  = ['pohon' => 0, 'sampah_kg' => 0, 'luas_ha' => 0.0];
$res = $conn->query(
    'SELECT COALESCE(SUM(perempuan), 0) AS perempuan, COALESCE(SUM(laki_laki), 0) AS laki,
            COALESCE(SUM(penanaman_pohon), 0) AS pohon,
            COALESCE(SUM(sampah_terkelola_kg), 0) AS sampah,
            COALESCE(SUM(luas_penanaman_ha), 0) AS luas
       FROM cid_program'
);
if ($res && ($r = $res->fetch_assoc())) {
    $cidGender['Perempuan']   = (int) $r['perempuan'];
    $cidGender['Laki-laki']   = (int) $r['laki'];
    $dampakCid['pohon']       = (int) $r['pohon'];
    $dampakCid['sampah_kg']   = (int) $r['sampah'];
    $dampakCid['luas_ha']     = (float) $r['luas'];
}

$tahunCidUtama = 0;
$res = $conn->query(
    'SELECT tahun_intervensi, COUNT(*) AS jumlah FROM cid_program
      WHERE tahun_intervensi IS NOT NULL
      GROUP BY tahun_intervensi ORDER BY jumlah DESC LIMIT 1'
);
if ($res && ($r = $res->fetch_assoc())) {
    $tahunCidUtama = (int) $r['tahun_intervensi'];
}
$cidPerBulan = [];
if ($tahunCidUtama > 0) {
    $res = $conn->query(
        'SELECT bulan_angka, COALESCE(SUM(anggaran), 0) AS anggaran
           FROM cid_program WHERE tahun_intervensi = ' . $tahunCidUtama . '
          GROUP BY bulan_angka ORDER BY bulan_angka'
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $cidPerBulan[(int) $r['bulan_angka']] = (int) round((float) $r['anggaran'] / 1000000);
    }
}
$angkaCidBulan = [];
for ($b = 1; $b <= 12; $b++) {
    $angkaCidBulan[] = $cidPerBulan[$b] ?? 0;
}

/* --- SDM, absensi, kasus, wilayah --- */
$statusAkun = ['aktif' => 0, 'menunggu' => 0, 'nonaktif' => 0];
$res = $conn->query('SELECT status, COUNT(*) AS jumlah FROM users GROUP BY status');
while ($res && ($r = $res->fetch_assoc())) {
    $k = (string) ($r['status'] ?? '');
    if (array_key_exists($k, $statusAkun)) {
        $statusAkun[$k] = (int) $r['jumlah'];
    }
}

$divisiAbsensi = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(u.divisi), ''), '(Tanpa Divisi)') AS divisi,
            SUM(a.status = 'hadir') AS hadir, COUNT(*) AS total
       FROM absensi a JOIN users u ON u.id = a.user_id
      WHERE a.tanggal >= CURDATE() - INTERVAL 29 DAY
      GROUP BY 1 ORDER BY total DESC LIMIT 8"
);
while ($res && ($r = $res->fetch_assoc())) {
    $hadir = (int) $r['hadir'];
    $divisiAbsensi[] = [
        'label' => (string) $r['divisi'],
        'hadir' => $hadir,
        'tidak' => (int) $r['total'] - $hadir,
    ];
}

$kesehatan30 = ['sehat' => 0, 'kurang_sehat' => 0, 'sakit' => 0, 'pemulihan' => 0];
$res = $conn->query(
    "SELECT kondisi_kesehatan, COUNT(*) AS jumlah FROM absensi
      WHERE tanggal >= CURDATE() - INTERVAL 29 DAY GROUP BY kondisi_kesehatan"
);
while ($res && ($r = $res->fetch_assoc())) {
    $k = (string) ($r['kondisi_kesehatan'] ?? '');
    if (array_key_exists($k, $kesehatan30)) {
        $kesehatan30[$k] = (int) $r['jumlah'];
    }
}

$progresKasus = [];
$res = $conn->query(
    'SELECT kode_kasus, progres FROM kasus ORDER BY diperbarui_pada DESC LIMIT 8'
);
while ($res && ($r = $res->fetch_assoc())) {
    $progresKasus[] = [
        'label'   => (string) $r['kode_kasus'],
        'progres' => (int) $r['progres'],
    ];
}

$statusKasus = ['Terbuka' => 0, 'Dalam Proses' => 0, 'Selesai' => 0];
$res = $conn->query('SELECT status_kasus, COUNT(*) AS jumlah FROM kasus GROUP BY status_kasus');
while ($res && ($r = $res->fetch_assoc())) {
    $k = (string) ($r['status_kasus'] ?? '');
    if (array_key_exists($k, $statusKasus)) {
        $statusKasus[$k] = (int) $r['jumlah'];
    }
}

$wilayahKab = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(kabupaten), ''), '(Tanpa Kabupaten)') AS kabupaten,
            COUNT(*) AS total, SUM(garis_lintang IS NOT NULL) AS berkoor
       FROM wilayah GROUP BY 1 ORDER BY total DESC LIMIT 8"
);
while ($res && ($r = $res->fetch_assoc())) {
    $berkoor = (int) $r['berkoor'];
    $wilayahKab[] = [
        'label'   => (string) $r['kabupaten'],
        'berkoor' => $berkoor,
        'belum'   => (int) $r['total'] - $berkoor,
    ];
}

$adminHead = '<script src="assets/js/vendor/chart.umd.min.js" defer></script>'
    . '<script src="assets/js/grafik.js" defer></script>';

mulai_halaman([
    'judul' => 'Dashboard Admin',
    'crumb' => 'Administrator',
    'aktif' => 'dashboard',
    'aksi'  => '<a class="tombol tombol-utama tombol-kecil" href="riwayat_audit.php">Riwayat Perubahan</a>',
    'head'  => $adminHead,
]);
?>

<!-- ================= SAMBUTAN ================= -->
<section class="kartu dasbor-sambutan">
    <div class="kartu-tubuh">
        <span class="badge badge-tanpa-titik" style="background:rgba(255,255,255,.16);color:#cfe6f7">
            PORTAL ADMINISTRATOR <?= e(APP_ORGANISASI) ?>
        </span>
        <h2>Selamat datang, <?= e((string) $admin['nama']) ?></h2>
        <p>
            Kelola pengguna, pantau laporan kegiatan, tinjau data SRM &amp; CID,
            serta awasi seluruh aktivitas sistem dari satu halaman.
        </p>
        <div class="d-flex gap-2 flex-wrap">
            <a class="tombol" style="background:#fff;border-color:#fff;color:var(--navy-600)" href="mentor_dashboard.php">Periksa Laporan</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="impor_laporan.php">Impor Data</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="peta.php">Peta Lokasi</a>
        </div>
    </div>
</section>

<?php if ($jumlahPengguna['menunggu'] > 0): ?>
<!-- ================= PERINGATAN VERIFIKASI ================= -->
<section class="kartu" style="margin-top:20px;border-color:rgba(183,121,31,.45);background:var(--kuning-50)">
    <div class="kartu-tubuh">
        <div class="d-flex items-center gap-3 flex-wrap">
            <span class="ikon" style="width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#fff;color:var(--kuning-600)">
                <?= ikon_sp('lonceng') ?>
            </span>
            <div class="flex-1">
                <h3 style="margin:0;font-size:15px;color:var(--kuning-600)">
                    <?= (int) $jumlahPengguna['menunggu'] ?> akun menunggu verifikasi
                </h3>
                <p class="text-kecil" style="margin:4px 0 0;color:#92400e">
                    Tinjau dan aktifkan akun berikut agar dapat menggunakan aplikasi.
                </p>
            </div>
            <a class="tombol tombol-kecil" href="mentor_dashboard.php">Tindak lanjut</a>
        </div>

        <?php if ($akunMenunggu !== []): ?>
        <div class="bungkus-tabel" style="margin-top:14px">
            <table class="tabel tabel-padat">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Peran</th>
                        <th>Terdaftar</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($akunMenunggu as $akun): ?>
                    <tr>
                        <td><?= e((string) $akun['nama']) ?></td>
                        <td class="text-kecil text-redup"><?= e((string) $akun['email']) ?></td>
                        <td><span class="badge badge-navy badge-tanpa-titik"><?= e(ucfirst((string) $akun['role'])) ?></span></td>
                        <td class="text-kecil text-redup"><?= e(tanggal_id($akun['created_at'] !== null ? (string) $akun['created_at'] : null, 'd M Y H:i')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ================= STATISTIK ================= -->
<h2 class="bagian-judul">Ringkasan Data
    <span class="bagian-ket"><?= (int) $jumlahAktivitasHariIni ?> aktivitas tercatat hari ini</span>
</h2>

<section class="grid grid-4">
    <div class="stat navy">
        <span class="ikon"><?= ikon_sp('pengguna') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahPengguna['total'] ?></div>
            <div class="keterangan">Total Pengguna</div>
            <div class="tambahan"><?= (int) $jumlahPengguna['karyawan'] ?> karyawan &middot; <?= (int) $jumlahPengguna['mentor'] ?> mentor</div>
        </div>
    </div>

    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('jam') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahPengguna['menunggu'] ?></div>
            <div class="keterangan">Menunggu Verifikasi</div>
            <div class="tambahan">Akun belum aktif</div>
        </div>
    </div>

    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('dokumen') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $totalLaporan ?></div>
            <div class="keterangan">Total Laporan</div>
            <div class="tambahan"><?= (int) $statLaporan['draft'] ?> draf belum terkirim</div>
        </div>
    </div>

    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('jam') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $statLaporan['dikirim'] ?></div>
            <div class="keterangan">Menunggu Pemeriksaan</div>
            <div class="tambahan">Perlu ditinjau segera</div>
        </div>
    </div>

    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('centang') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $statLaporan['diperiksa'] ?></div>
            <div class="keterangan">Sudah Diperiksa</div>
            <div class="tambahan">Laporan selesai ditinjau</div>
        </div>
    </div>

    <div class="stat merah">
        <span class="ikon"><?= ikon_sp('silang') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $statLaporan['perlu_revisi'] ?></div>
            <div class="keterangan">Perlu Revisi</div>
            <div class="tambahan">Menunggu perbaikan karyawan</div>
        </div>
    </div>

    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('kegiatan') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahSrm ?></div>
            <div class="keterangan">Kegiatan SRM</div>
            <div class="tambahan">Seluruh catatan hubungan masyarakat</div>
        </div>
    </div>

    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('koin') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahCid ?></div>
            <div class="keterangan">Program CID</div>
            <div class="tambahan"><?= (int) $jumlahWilayah ?> wilayah berkoordinat</div>
        </div>
    </div>
</section>

<!-- ================= SOROTAN CEPAT ================= -->
<section class="sorotan-ringkas" style="margin-top:16px">
    <a class="s-chip" href="pengguna.php">
        <span class="s-ikon">&#128101;</span>
        <span class="s-teks">
            <span class="s-judul">Kelola Pengguna</span>
            <span class="s-ket"><?= (int) $jumlahPengguna['total'] ?> akun terdaftar</span>
        </span>
    </a>
    <a class="s-chip" href="mentor_dashboard.php?status=dikirim">
        <span class="s-ikon">&#9203;</span>
        <span class="s-teks">
            <span class="s-judul">Perlu Ditinjau</span>
            <span class="s-ket"><?= (int) $statLaporan['dikirim'] ?> laporan menunggu</span>
        </span>
    </a>
    <a class="s-chip" href="riwayat_audit.php">
        <span class="s-ikon">&#128203;</span>
        <span class="s-teks">
            <span class="s-judul">Jejak Audit</span>
            <span class="s-ket"><?= (int) $jumlahAktivitasHariIni ?> aktivitas hari ini</span>
        </span>
    </a>
    <a class="s-chip" href="impor_laporan.php">
        <span class="s-ikon">&#128228;</span>
        <span class="s-teks">
            <span class="s-judul">Impor Data</span>
            <span class="s-ket">Excel &amp; dataset baru</span>
        </span>
    </a>
</section>

<!-- ================= GRAFIK ================= -->
<h2 class="bagian-judul">Analitik Visual</h2>

<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Tren Laporan 30 Hari Terakhir</h3>
                <span class="text-redup text-kecil">Jumlah laporan per tanggal, diurutkan berdasarkan status.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikTrenAdmin" aria-label="Tren laporan 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Status Seluruh Laporan</h3>
                <span class="text-redup text-kecil">Perbandingan laporan berdasarkan status.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikStatusAdmin" aria-label="Distribusi status laporan" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Komposisi Pengguna</h3>
                <span class="text-redup text-kecil">Jumlah akun per peran pengguna.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikPenggunaAdmin" aria-label="Pengguna per peran" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Urgensi Kasus</h3>
                <span class="text-redup text-kecil">Jumlah kasus per tingkat urgensi.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikUrgensiAdmin" aria-label="Urgensi kasus" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Absensi 30 Hari Terakhir</h3>
                <span class="text-redup text-kecil">Rekap kehadiran seluruh karyawan.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikAbsensiAdmin" aria-label="Absensi 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>SRM &amp; CID per Tahun Intervensi</h3>
                <span class="text-redup text-kecil">Jumlah kegiatan hubungan masyarakat dan program CID tiap tahun.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikTahunAdmin" aria-label="SRM dan CID per tahun" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= ANALITIK SRM & CID ================= -->
<h2 class="bagian-judul">Analitik SRM &amp; CID</h2>

<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Kegiatan SRM per Bulan</h3>
                <span class="text-redup text-kecil">Jumlah catatan hubungan masyarakat tiap bulan sepanjang data.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikSrmBulanAdmin" aria-label="Kegiatan SRM per bulan" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>SRM per Area Field</h3>
                <span class="text-redup text-kecil">Delapan area field dengan kegiatan terbanyak.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikSrmAreaAdmin" aria-label="SRM per area field" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>SRM per Jenis Stakeholder</h3>
                <span class="text-redup text-kecil">Tujuh jenis stakeholder paling sering dijalin.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikSrmStakeAdmin" aria-label="SRM per jenis stakeholder" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Kategori Kegiatan SRM</h3>
                <span class="text-redup text-kecil">Tujuh kategori kegiatan hubungan masyarakat teratas.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikSrmKategoriAdmin" aria-label="Kategori kegiatan SRM" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Program CID per Pilar</h3>
                <span class="text-redup text-kecil">Jumlah program (batang) dan total anggaran dalam juta rupiah (garis).</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikCidPilarAdmin" aria-label="Program CID per pilar" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Anggaran CID per Bulan<?= $tahunCidUtama > 0 ? ' — ' . (int) $tahunCidUtama : '' ?></h3>
                <span class="text-redup text-kecil">Total anggaran program CID tiap bulan, dalam juta rupiah.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikCidBulanAdmin" aria-label="Anggaran CID per bulan" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Program CID per Kabupaten</h3>
                <span class="text-redup text-kecil">Delapan kabupaten/kota dengan program terbanyak.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikCidKabAdmin" aria-label="Program CID per kabupaten" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Penerima Manfaat CID</h3>
                <span class="text-redup text-kecil">
                    Rasio perempuan vs laki-laki &middot;
                    <?= number_format($dampakCid['pohon'], 0, ',', '.') ?> pohon &middot;
                    <?= number_format($dampakCid['sampah_kg'], 0, ',', '.') ?> kg sampah terkelola &middot;
                    <?= number_format($dampakCid['luas_ha'], 1, ',', '.') ?> ha penanaman
                </span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikCidGenderAdmin" aria-label="Penerima manfaat CID per jenis kelamin" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= ANALITIK SISTEM & AKTIVITAS ================= -->
<h2 class="bagian-judul">Analitik Sistem &amp; Aktivitas</h2>

<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Kepatuhan Harian 30 Hari</h3>
                <span class="text-redup text-kecil">Laporan masuk dibanding jumlah karyawan yang hadir tiap hari.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:270px">
                <canvas id="grafikKepatuhanAdmin" aria-label="Kepatuhan harian 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Aktivitas Sistem 30 Hari</h3>
                <span class="text-redup text-kecil">Jumlah aksi log per hari, dikelompokkan: akses, laporan, data operasional, dan sistem/AI.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:270px">
                <canvas id="grafikAuditAdmin" aria-label="Aktivitas sistem 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Jenis Aksi Terbanyak</h3>
                <span class="text-redup text-kecil">Tujuh aksi yang paling sering tercatat pada log audit.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikAksiAdmin" aria-label="Jenis aksi terbanyak" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Penggunaan Asisten AI 30 Hari</h3>
                <span class="text-redup text-kecil">Jumlah pertanyaan pengguna dan balasan asisten per hari.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikChatAdmin" aria-label="Penggunaan asisten AI 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Status Notifikasi</h3>
                <span class="text-redup text-kecil">Notifikasi yang sudah dan belum dibaca.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikNotifikasiAdmin" aria-label="Status notifikasi" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Jadwal Kegiatan</h3>
                <span class="text-redup text-kecil">Jumlah jadwal per status: rencana, selesai, batal.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikJadwalAdmin" aria-label="Status jadwal" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Komentar Pemeriksaan 30 Hari</h3>
                <span class="text-redup text-kecil">Jumlah komentar mentor pada laporan per hari.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:250px">
                <canvas id="grafikKomentarAdmin" aria-label="Komentar pemeriksaan 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= ANALITIK SDM & WILAYAH ================= -->
<h2 class="bagian-judul">Analitik SDM &amp; Wilayah</h2>

<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Status Akun Pengguna</h3>
                <span class="text-redup text-kecil">Komposisi akun aktif, menunggu verifikasi, dan nonaktif.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikAkunAdmin" aria-label="Status akun pengguna" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Kondisi Kesehatan Karyawan</h3>
                <span class="text-redup text-kecil">Laporan kondisi kesehatan dari absensi 30 hari terakhir.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikKesehatanAdmin" aria-label="Kondisi kesehatan karyawan" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Kehadiran per Divisi 30 Hari</h3>
                <span class="text-redup text-kecil">Catatan hadir vs tidak hadir (izin, sakit, alpa) per divisi.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:270px">
                <canvas id="grafikDivisiAbsAdmin" aria-label="Kehadiran per divisi" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Progres Kasus Terbaru</h3>
                <span class="text-redup text-kecil">Persentase progres delapan kasus yang terakhir diperbarui.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikProgresKasusAdmin" aria-label="Progres kasus terbaru" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Status Kasus</h3>
                <span class="text-redup text-kecil">Jumlah kasus per status penanganan.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikStatusKasusAdmin" aria-label="Status kasus" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Sebaran Wilayah per Kabupaten</h3>
                <span class="text-redup text-kecil">Entri wilayah dengan koordinat lengkap vs belum berkoordinat (8 kabupaten teratas).</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:270px">
                <canvas id="grafikWilayahAdmin" aria-label="Sebaran wilayah per kabupaten" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= PINTASAN ADMINISTRASI ================= -->
<h2 class="bagian-judul">Pintasan Administrasi</h2>

<section class="aksi-cepat">
    <a href="mentor_dashboard.php">
        <span class="ikon"><?= ikon_sp('dokumen') ?></span>
        <span>Periksa Laporan</span>
    </a>
    <a href="rekap_laporan.php">
        <span class="ikon"><?= ikon_sp('grafik') ?></span>
        <span>Rekap Laporan</span>
    </a>
    <a href="mentor_absensi.php">
        <span class="ikon"><?= ikon_sp('kalender') ?></span>
        <span>Absensi Karyawan</span>
    </a>
    <a href="impor_laporan.php">
        <span class="ikon"><?= ikon_sp('unggah') ?></span>
        <span>Impor Data</span>
    </a>
    <a href="impor_srm_cid.php">
        <span class="ikon"><?= ikon_sp('folder') ?></span>
        <span>Impor SRM &amp; CID</span>
    </a>
    <a href="peta.php">
        <span class="ikon"><?= ikon_sp('peta') ?></span>
        <span>Peta Lokasi</span>
    </a>
    <a href="pengguna.php">
        <span class="ikon"><?= ikon_sp('grup') ?></span>
        <span>Kelola Pengguna</span>
    </a>
    <a href="riwayat_audit.php">
        <span class="ikon"><?= ikon_sp('perisai') ?></span>
        <span>Riwayat Perubahan</span>
    </a>
    <a href="backup.php">
        <span class="ikon"><?= ikon_sp('database') ?></span>
        <span>Cadangan Data</span>
    </a>
    <a href="notifikasi.php">
        <span class="ikon"><?= ikon_sp('lonceng') ?></span>
        <span>Notifikasi<?= $jumlahNotifikasi > 0 ? ' (' . (int) $jumlahNotifikasi . ')' : '' ?></span>
    </a>
    <a href="status.php">
        <span class="ikon"><?= ikon_sp('alat') ?></span>
        <span>Status Sistem</span>
    </a>
</section>

<!-- ================= AKTIVITAS TERBARU ================= -->
<section class="kartu" style="margin-top:22px">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Aktivitas Terbaru</h3>
            <span class="text-redup text-kecil">Delapan catatan aktivitas terakhir dari log sistem.</span>
        </div>
        <a class="tombol tombol-kecil" href="riwayat_audit.php">Lihat semua</a>
    </div>
    <div class="kartu-tubuh padat">
        <?php if ($aktivitas === []): ?>
            <div class="empty">
                <div class="empty-ikon"><?= ikon_sp('dokumen') ?></div>
                <div class="empty-judul">Belum ada aktivitas</div>
                <p class="empty-teks">Catatan aktivitas akan muncul di sini.</p>
            </div>
        <?php else: ?>
            <div class="bungkus-tabel">
                <table class="tabel tabel-padat">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Pengguna</th>
                            <th>Aksi</th>
                            <th>Ringkasan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($aktivitas as $a): ?>
                        <tr>
                            <td class="text-kecil text-redup"><?= e(tanggal_id($a['dibuat_pada'] !== null ? (string) $a['dibuat_pada'] : null, 'd M Y H:i')) ?></td>
                            <td>
                                <?= e((string) ($a['nama_pengguna'] ?? 'Sistem')) ?>
                                <?php if (!empty($a['role_pengguna'])): ?>
                                    <span class="badge badge-navy badge-tanpa-titik" style="margin-left:6px"><?= e(ucfirst((string) $a['role_pengguna'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-biru badge-tanpa-titik"><?= e($labelAksiAudit((string) $a['aksi'])) ?></span></td>
                            <td class="text-kecil"><?= e(potong((string) ($a['ringkasan'] ?? '-'), 80)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ================= JAVASCRIPT GRAFIK ================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof grafikSiap === 'undefined') return;

    var W = window.WARNA_GRAFIK;

    grafikSiap(function (Chart) {
        /* Tren 30 hari (bar bertumpuk per status). */
        var cTren = document.getElementById('grafikTrenAdmin');
        if (cTren) {
            new Chart(cTren, {
                type: 'bar',
                data: {
                    labels: <?= $json($labelTren) ?>,
                    datasets: [
                        { label: 'Draf', data: <?= $json($trenDraft) ?>, backgroundColor: W.abu, borderRadius: 3 },
                        { label: 'Menunggu', data: <?= $json($trenDikirim) ?>, backgroundColor: W.kuning, borderRadius: 3 },
                        { label: 'Diperiksa', data: <?= $json($trenDiperiksa) ?>, backgroundColor: W.hijau, borderRadius: 3 },
                        { label: 'Perlu Revisi', data: <?= $json($trenRevisi) ?>, backgroundColor: W.merah, borderRadius: 3 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                }
            });
        }

        /* Donut status laporan. */
        var cStatus = document.getElementById('grafikStatusAdmin');
        if (cStatus) {
            new Chart(cStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Draf', 'Menunggu', 'Diperiksa', 'Perlu Revisi'],
                    datasets: [{
                        data: <?= $json([$statLaporan['draft'], $statLaporan['dikirim'], $statLaporan['diperiksa'], $statLaporan['perlu_revisi']]) ?>,
                        backgroundColor: window.WARNA_STATUS,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }

        /* Pengguna per peran. */
        var cPengguna = document.getElementById('grafikPenggunaAdmin');
        if (cPengguna) {
            new Chart(cPengguna, {
                type: 'bar',
                data: {
                    labels: ['Karyawan', 'Mentor', 'Admin'],
                    datasets: [{
                        label: 'Pengguna',
                        data: <?= $json([$jumlahPengguna['karyawan'], $jumlahPengguna['mentor'], $jumlahPengguna['admin']]) ?>,
                        backgroundColor: [W.biru, W.navy, W.hijau],
                        borderRadius: 6,
                        maxBarThickness: 56
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        /* Urgensi kasus. */
        var cUrgensi = document.getElementById('grafikUrgensiAdmin');
        if (cUrgensi) {
            new Chart(cUrgensi, {
                type: 'doughnut',
                data: {
                    labels: ['Rendah', 'Sedang', 'Tinggi', 'Kritis'],
                    datasets: [{
                        data: <?= $json([$urgensiKasus['Rendah'], $urgensiKasus['Sedang'], $urgensiKasus['Tinggi'], $urgensiKasus['Kritis']]) ?>,
                        backgroundColor: window.WARNA_URGENSI,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }

        /* Absensi 30 hari. */
        var cAbsensi = document.getElementById('grafikAbsensiAdmin');
        if (cAbsensi) {
            new Chart(cAbsensi, {
                type: 'doughnut',
                data: {
                    labels: ['Hadir', 'Izin', 'Sakit', 'Alpa'],
                    datasets: [{
                        data: <?= $json([$absensi30['hadir'], $absensi30['izin'], $absensi30['sakit'], $absensi30['alpa']]) ?>,
                        backgroundColor: window.WARNA_ABSENSI,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }

        /* SRM & CID per tahun. */
        var cTahun = document.getElementById('grafikTahunAdmin');
        if (cTahun) {
            var srmTahun = <?= $json(array_values($srmPerTahun)) ?>;
            var cidTahun = <?= $json(array_values($cidPerTahun)) ?>;
            if (!window.grafikAdaData(srmTahun) && !window.grafikAdaData(cidTahun)) {
                window.grafikKosong('grafikTahunAdmin', 'Belum ada data SRM/CID dengan tahun intervensi.');
            } else {
                new Chart(cTahun, {
                    type: 'bar',
                    data: {
                        labels: <?= $json($labelTahun) ?>,
                        datasets: [
                            { label: 'Kegiatan SRM', data: srmTahun, backgroundColor: W.biru, borderRadius: 5 },
                            { label: 'Program CID', data: cidTahun, backgroundColor: W.hijau, borderRadius: 5 }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        },
                        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                    }
                });
            }
        }
    });

    /* ===== Analitik SRM & CID ===== */

    /* Tren kegiatan SRM per bulan. */
    var cSrmBulan = document.getElementById('grafikSrmBulanAdmin');
    if (cSrmBulan) {
        var dataSrmBulan = <?= $json($angkaSrmBulan) ?>;
        if (!window.grafikAdaData(dataSrmBulan)) {
            window.grafikKosong('grafikSrmBulanAdmin', 'Belum ada data kegiatan SRM bertanggal.');
        } else {
            new Chart(cSrmBulan, {
                type: 'line',
                data: {
                    labels: <?= $json($labelSrmBulan) ?>,
                    datasets: [{
                        label: 'Kegiatan SRM',
                        data: dataSrmBulan,
                        borderColor: W.biru,
                        backgroundColor: 'rgba(0,94,184,.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointBackgroundColor: W.biru
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* SRM per area field (batang horizontal). */
    var cSrmArea = document.getElementById('grafikSrmAreaAdmin');
    if (cSrmArea) {
        var dataSrmArea = <?= $json(array_column($srmArea, 'jumlah')) ?>;
        if (!window.grafikAdaData(dataSrmArea)) {
            window.grafikKosong('grafikSrmAreaAdmin', 'Belum ada data area field SRM.');
        } else {
            new Chart(cSrmArea, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($srmArea, 'label')) ?>,
                    datasets: [{
                        label: 'Kegiatan',
                        data: dataSrmArea,
                        backgroundColor: W.biru,
                        borderRadius: 6,
                        maxBarThickness: 26
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* SRM per jenis stakeholder (batang). */
    var cSrmStake = document.getElementById('grafikSrmStakeAdmin');
    if (cSrmStake) {
        var dataSrmStake = <?= $json(array_column($srmStakeholder, 'jumlah')) ?>;
        if (!window.grafikAdaData(dataSrmStake)) {
            window.grafikKosong('grafikSrmStakeAdmin', 'Belum ada data jenis stakeholder.');
        } else {
            new Chart(cSrmStake, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($srmStakeholder, 'label')) ?>,
                    datasets: [{
                        label: 'Kegiatan',
                        data: dataSrmStake,
                        backgroundColor: [W.navy, W.biru, W.biruMuda, W.hijau, W.kuning, W.merah, W.abu],
                        borderRadius: 6,
                        maxBarThickness: 40
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* Kategori kegiatan SRM (batang horizontal). */
    var cSrmKategori = document.getElementById('grafikSrmKategoriAdmin');
    if (cSrmKategori) {
        var dataSrmKategori = <?= $json(array_column($srmKategori, 'jumlah')) ?>;
        if (!window.grafikAdaData(dataSrmKategori)) {
            window.grafikKosong('grafikSrmKategoriAdmin', 'Belum ada data kategori kegiatan SRM.');
        } else {
            new Chart(cSrmKategori, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($srmKategori, 'label')) ?>,
                    datasets: [{
                        label: 'Kegiatan',
                        data: dataSrmKategori,
                        backgroundColor: W.hijau,
                        borderRadius: 6,
                        maxBarThickness: 26
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* Program CID per pilar: batang jumlah + garis anggaran (sumbu kanan). */
    var cCidPilar = document.getElementById('grafikCidPilarAdmin');
    if (cCidPilar) {
        var dataCidPilar = <?= $json(array_column($cidPilar, 'jumlah')) ?>;
        if (!window.grafikAdaData(dataCidPilar)) {
            window.grafikKosong('grafikCidPilarAdmin', 'Belum ada data pilar program CID.');
        } else {
            new Chart(cCidPilar, {
                data: {
                    labels: <?= $json(array_column($cidPilar, 'label')) ?>,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Jumlah Program',
                            data: dataCidPilar,
                            backgroundColor: W.biru,
                            borderRadius: 6,
                            maxBarThickness: 46,
                            yAxisID: 'y'
                        },
                        {
                            type: 'line',
                            label: 'Anggaran (juta Rp)',
                            data: <?= $json(array_column($cidPilar, 'anggaran')) ?>,
                            borderColor: W.hijau,
                            backgroundColor: W.hijau,
                            tension: 0.3,
                            pointRadius: 3,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        y1: {
                            position: 'right',
                            beginAtZero: true,
                            grid: { drawOnChartArea: false },
                            title: { display: true, text: 'Juta Rp' }
                        }
                    },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                }
            });
        }
    }

    /* Anggaran CID per bulan (garis). */
    var cCidBulan = document.getElementById('grafikCidBulanAdmin');
    if (cCidBulan) {
        var dataCidBulan = <?= $json($angkaCidBulan) ?>;
        if (!window.grafikAdaData(dataCidBulan)) {
            window.grafikKosong('grafikCidBulanAdmin', 'Belum ada data anggaran CID per bulan.');
        } else {
            new Chart(cCidBulan, {
                type: 'line',
                data: {
                    labels: <?= $json(array_values($namaBulanId)) ?>,
                    datasets: [{
                        label: 'Anggaran (juta Rp)',
                        data: dataCidBulan,
                        borderColor: W.navy,
                        backgroundColor: 'rgba(0,61,125,.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointBackgroundColor: W.navy
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* Program CID per kabupaten (batang horizontal). */
    var cCidKab = document.getElementById('grafikCidKabAdmin');
    if (cCidKab) {
        var dataCidKab = <?= $json(array_column($cidKabupaten, 'jumlah')) ?>;
        if (!window.grafikAdaData(dataCidKab)) {
            window.grafikKosong('grafikCidKabAdmin', 'Belum ada data kabupaten pada program CID.');
        } else {
            new Chart(cCidKab, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($cidKabupaten, 'label')) ?>,
                    datasets: [{
                        label: 'Program',
                        data: dataCidKab,
                        backgroundColor: W.hijau,
                        borderRadius: 6,
                        maxBarThickness: 26
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* Penerima manfaat CID: perempuan vs laki-laki (donat). */
    var cCidGender = document.getElementById('grafikCidGenderAdmin');
    if (cCidGender) {
        var dataCidGender = <?= $json([$cidGender['Perempuan'], $cidGender['Laki-laki']]) ?>;
        if (!window.grafikAdaData(dataCidGender)) {
            window.grafikKosong('grafikCidGenderAdmin', 'Belum ada data penerima manfaat CID.');
        } else {
            new Chart(cCidGender, {
                type: 'doughnut',
                data: {
                    labels: ['Perempuan', 'Laki-laki'],
                    datasets: [{
                        data: dataCidGender,
                        backgroundColor: [W.biru, W.navy],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }
    }

    /* ===== Analitik Sistem & Aktivitas ===== */

    /* Kepatuhan harian 30 hari (dua garis). */
    var cKepatuhan = document.getElementById('grafikKepatuhanAdmin');
    if (cKepatuhan) {
        new Chart(cKepatuhan, {
            type: 'line',
            data: {
                labels: <?= $json($labelTren) ?>,
                datasets: [
                    {
                        label: 'Laporan Masuk',
                        data: <?= $json($angkaKepatuhanLaporan) ?>,
                        borderColor: W.biru,
                        backgroundColor: 'rgba(0,94,184,.10)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2.5
                    },
                    {
                        label: 'Karyawan Hadir',
                        data: <?= $json($angkaKepatuhanHadir) ?>,
                        borderColor: W.hijau,
                        backgroundColor: 'rgba(21,148,71,.08)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2.5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
            }
        });
    }

    /* Aktivitas sistem 30 hari (batang bertumpuk per kelompok). */
    var cAudit = document.getElementById('grafikAuditAdmin');
    if (cAudit) {
        if (!window.grafikAdaData(<?= $json(array_merge(...array_values($auditPerKelompok))) ?>)) {
            window.grafikKosong('grafikAuditAdmin', 'Belum ada aktivitas sistem 30 hari terakhir.');
        } else {
            new Chart(cAudit, {
                type: 'bar',
                data: {
                    labels: <?= $json($labelTren) ?>,
                    datasets: [
                        { label: 'Akses & Masuk', data: <?= $json($auditPerKelompok['akses']) ?>, backgroundColor: W.navy, borderRadius: 3 },
                        { label: 'Laporan', data: <?= $json($auditPerKelompok['laporan']) ?>, backgroundColor: W.biru, borderRadius: 3 },
                        { label: 'Data Operasional', data: <?= $json($auditPerKelompok['data']) ?>, backgroundColor: W.biruMuda, borderRadius: 3 },
                        { label: 'Sistem & AI', data: <?= $json($auditPerKelompok['sistem']) ?>, backgroundColor: W.hijau, borderRadius: 3 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                }
            });
        }
    }

    /* Jenis aksi terbanyak (batang horizontal). */
    var cAksi = document.getElementById('grafikAksiAdmin');
    if (cAksi) {
        var dataTopAksi = <?= $json(array_column($topAksi, 'jumlah')) ?>;
        if (!window.grafikAdaData(dataTopAksi)) {
            window.grafikKosong('grafikAksiAdmin', 'Belum ada data log audit.');
        } else {
            new Chart(cAksi, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($topAksi, 'aksi')) ?>,
                    datasets: [{
                        label: 'Jumlah',
                        data: dataTopAksi,
                        backgroundColor: [W.navy, W.biru, W.biruMuda, W.hijau, W.kuning, W.merah, W.abu],
                        borderRadius: 6,
                        maxBarThickness: 26
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* Penggunaan asisten AI 30 hari (dua garis). */
    var cChat = document.getElementById('grafikChatAdmin');
    if (cChat) {
        if (!window.grafikAdaData(<?= $json(array_merge($chatTanya, $chatBalas)) ?>)) {
            window.grafikKosong('grafikChatAdmin', 'Belum ada percakapan dengan asisten AI.');
        } else {
            new Chart(cChat, {
                type: 'line',
                data: {
                    labels: <?= $json($labelTren) ?>,
                    datasets: [
                        {
                            label: 'Pertanyaan Pengguna',
                            data: <?= $json($chatTanya) ?>,
                            borderColor: W.biru,
                            backgroundColor: 'rgba(0,94,184,.10)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2.5
                        },
                        {
                            label: 'Balasan Asisten',
                            data: <?= $json($chatBalas) ?>,
                            borderColor: W.hijau,
                            backgroundColor: 'rgba(21,148,71,.08)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2.5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                }
            });
        }
    }

    /* Status notifikasi (donat). */
    var cNotif = document.getElementById('grafikNotifikasiAdmin');
    if (cNotif) {
        var dataNotif = <?= $json([$notifDibaca[0], $notifDibaca[1]]) ?>;
        if (!window.grafikAdaData(dataNotif)) {
            window.grafikKosong('grafikNotifikasiAdmin', 'Belum ada notifikasi tercatat.');
        } else {
            new Chart(cNotif, {
                type: 'doughnut',
                data: {
                    labels: ['Belum Dibaca', 'Sudah Dibaca'],
                    datasets: [{
                        data: dataNotif,
                        backgroundColor: [W.kuning, W.abuMuda],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }
    }

    /* Jadwal per status (donat). */
    var cJadwal = document.getElementById('grafikJadwalAdmin');
    if (cJadwal) {
        var dataJadwal = <?= $json([$jadwalStatus['rencana'], $jadwalStatus['selesai'], $jadwalStatus['batal']]) ?>;
        if (!window.grafikAdaData(dataJadwal)) {
            window.grafikKosong('grafikJadwalAdmin', 'Belum ada jadwal kegiatan.');
        } else {
            new Chart(cJadwal, {
                type: 'doughnut',
                data: {
                    labels: ['Rencana', 'Selesai', 'Batal'],
                    datasets: [{
                        data: dataJadwal,
                        backgroundColor: [W.biru, W.hijau, W.abu],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }
    }

    /* Komentar pemeriksaan 30 hari (garis). */
    var cKomentar = document.getElementById('grafikKomentarAdmin');
    if (cKomentar) {
        if (!window.grafikAdaData(<?= $json($angkaKomentar) ?>)) {
            window.grafikKosong('grafikKomentarAdmin', 'Belum ada komentar pemeriksaan 30 hari terakhir.');
        } else {
            new Chart(cKomentar, {
                type: 'line',
                data: {
                    labels: <?= $json($labelTren) ?>,
                    datasets: [{
                        label: 'Komentar',
                        data: <?= $json($angkaKomentar) ?>,
                        borderColor: W.kuning,
                        backgroundColor: 'rgba(214,158,46,.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2.5,
                        pointBackgroundColor: W.kuning
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* ===== Analitik SDM & Wilayah ===== */

    /* Status akun pengguna (donat). */
    var cAkun = document.getElementById('grafikAkunAdmin');
    if (cAkun) {
        var dataAkun = <?= $json([$statusAkun['aktif'], $statusAkun['menunggu'], $statusAkun['nonaktif']]) ?>;
        if (!window.grafikAdaData(dataAkun)) {
            window.grafikKosong('grafikAkunAdmin', 'Belum ada data pengguna.');
        } else {
            new Chart(cAkun, {
                type: 'doughnut',
                data: {
                    labels: ['Aktif', 'Menunggu Verifikasi', 'Nonaktif'],
                    datasets: [{
                        data: dataAkun,
                        backgroundColor: [W.hijau, W.kuning, W.abu],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }
    }

    /* Kondisi kesehatan karyawan (donat). */
    var cSehat = document.getElementById('grafikKesehatanAdmin');
    if (cSehat) {
        var dataSehat = <?= $json([$kesehatan30['sehat'], $kesehatan30['kurang_sehat'], $kesehatan30['sakit'], $kesehatan30['pemulihan']]) ?>;
        if (!window.grafikAdaData(dataSehat)) {
            window.grafikKosong('grafikKesehatanAdmin', 'Belum ada laporan kondisi kesehatan 30 hari terakhir.');
        } else {
            new Chart(cSehat, {
                type: 'doughnut',
                data: {
                    labels: ['Sehat', 'Kurang Sehat', 'Sakit', 'Pemulihan'],
                    datasets: [{
                        data: dataSehat,
                        backgroundColor: [W.hijau, W.kuning, W.merah, W.biruMuda],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }
    }

    /* Kehadiran per divisi 30 hari (batang bertumpuk). */
    var cDivAbs = document.getElementById('grafikDivisiAbsAdmin');
    if (cDivAbs) {
        var dataDivAbs = <?= $json(array_map(static fn (array $d): int => $d['hadir'] + $d['tidak'], $divisiAbsensi)) ?>;
        if (!window.grafikAdaData(dataDivAbs)) {
            window.grafikKosong('grafikDivisiAbsAdmin', 'Belum ada catatan absensi 30 hari terakhir.');
        } else {
            new Chart(cDivAbs, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($divisiAbsensi, 'label')) ?>,
                    datasets: [
                        { label: 'Hadir', data: <?= $json(array_column($divisiAbsensi, 'hadir')) ?>, backgroundColor: W.hijau, borderRadius: 4 },
                        { label: 'Tidak Hadir', data: <?= $json(array_column($divisiAbsensi, 'tidak')) ?>, backgroundColor: W.merah, borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                }
            });
        }
    }

    /* Progres kasus terbaru (batang horizontal). */
    var cProgresKasus = document.getElementById('grafikProgresKasusAdmin');
    if (cProgresKasus) {
        var dataProgresKasus = <?= $json(array_column($progresKasus, 'progres')) ?>;
        if (!window.grafikAdaData(dataProgresKasus)) {
            window.grafikKosong('grafikProgresKasusAdmin', 'Belum ada data progres kasus.');
        } else {
            new Chart(cProgresKasus, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($progresKasus, 'label')) ?>,
                    datasets: [{
                        label: 'Progres (%)',
                        data: dataProgresKasus,
                        backgroundColor: W.biruMuda,
                        borderRadius: 6,
                        maxBarThickness: 24
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, max: 100 },
                        y: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    /* Status kasus (donat). */
    var cStatusKasus = document.getElementById('grafikStatusKasusAdmin');
    if (cStatusKasus) {
        var dataStatusKasus = <?= $json([$statusKasus['Terbuka'], $statusKasus['Dalam Proses'], $statusKasus['Selesai']]) ?>;
        if (!window.grafikAdaData(dataStatusKasus)) {
            window.grafikKosong('grafikStatusKasusAdmin', 'Belum ada data status kasus.');
        } else {
            new Chart(cStatusKasus, {
                type: 'doughnut',
                data: {
                    labels: ['Terbuka', 'Dalam Proses', 'Selesai'],
                    datasets: [{
                        data: dataStatusKasus,
                        backgroundColor: [W.kuning, W.biru, W.hijau],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }
    }

    /* Sebaran wilayah per kabupaten (batang bertumpuk). */
    var cWilayah = document.getElementById('grafikWilayahAdmin');
    if (cWilayah) {
        var dataWilayah = <?= $json(array_map(static fn (array $w): int => $w['berkoor'] + $w['belum'], $wilayahKab)) ?>;
        if (!window.grafikAdaData(dataWilayah)) {
            window.grafikKosong('grafikWilayahAdmin', 'Belum ada data wilayah.');
        } else {
            new Chart(cWilayah, {
                type: 'bar',
                data: {
                    labels: <?= $json(array_column($wilayahKab, 'label')) ?>,
                    datasets: [
                        { label: 'Berkoordinat', data: <?= $json(array_column($wilayahKab, 'berkoor')) ?>, backgroundColor: W.biru, borderRadius: 4 },
                        { label: 'Belum Berkoordinat', data: <?= $json(array_column($wilayahKab, 'belum')) ?>, backgroundColor: W.abuMuda, borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                }
            });
        }
    }
});
</script>

<?php akhiri_halaman(); ?>
