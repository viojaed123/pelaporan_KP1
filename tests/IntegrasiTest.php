<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Uji integrasi yang membutuhkan basis data. Lompat bila DB tak terjangkau.
 *
 * Semua kasus memakai ID laporan/pengguna yang tidak ada, sehingga tidak
 * mengirim surel/WhatsApp sungguhan dan tidak meninggalkan data.
 */
final class IntegrasiTest extends TestCase
{
    private ?mysqli $conn = null;

    /** @var int[] id pengguna buatan yang dibuat test ini */
    private array $tidakUntukDisentuh = [];

    protected function setUp(): void
    {
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $c = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($c->connect_error) {
                $this->markTestSkipped('Basis data tidak terjangkau: ' . $c->connect_error);
            }
            $c->set_charset(DB_CHARSET);
            $this->conn = $c;
        } catch (Throwable $t) {
            $this->markTestSkipped('Basis data tidak terjangkau: ' . $t->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->conn instanceof mysqli) {
            foreach ($this->tidakUntukDisentuh as $uid) {
                foreach (['absensi', 'user_tokens'] as $tabel) {
                    $st = $this->conn->prepare("DELETE FROM {$tabel} WHERE user_id = ?");
                    if ($st) {
                        $st->bind_param('i', $uid);
                        $st->execute();
                        $st->close();
                    }
                }
                $st = $this->conn->prepare('DELETE FROM users WHERE id = ?');
                if ($st) {
                    $st->bind_param('i', $uid);
                    $st->execute();
                    $st->close();
                }
            }
            $this->conn->close();
        }
    }

    public function testKabariStatusStrukturKembalian(): void
    {
        $hasil = kabari_status_laporan($this->conn, 99999999, 'perlu_revisi', 'catatan uji');
        $this->assertSame(
            ['aplikasi', 'surel', 'whatsapp'],
            array_keys($hasil)
        );
        $this->assertIsBool($hasil['aplikasi']);
        $this->assertIsBool($hasil['surel']);
        $this->assertIsBool($hasil['whatsapp']);
        // Laporan tidak ada -> tidak ada yang terkirim
        $this->assertFalse($hasil['aplikasi']);
    }

    public function testNotifikasiKeKaryawanIdTidakAda(): void
    {
        $this->assertFalse(notifikasi_ke_karyawan($this->conn, 99999999, 'pesan uji'));
    }

    public function testAlurTokenSekaliPakai(): void
    {
        // Buat pengguna uji
        $st = $this->conn->prepare(
            "INSERT INTO users (nama, email, password, role, status)
             VALUES ('Pengguna Uji', ?, ?, 'karyawan', 'menunggu')"
        );
        $hash = password_hash('UjiCoba#2026!', PASSWORD_DEFAULT);
        $email = 'pengguna.uji.tes.' . bin2hex(random_bytes(4)) . '@contoh.test';
        $st->bind_param('ss', $email, $hash);
        $st->execute();
        $uid = (int) $this->conn->insert_id;
        $st->close();
        $this->tidakUntukDisentuh[] = $uid;

        // Buat -> ambil -> pakai -> gagal
        $mentah = token_buat($this->conn, $uid, 'verifikasi_email', VERIFIKASI_EMAIL_DETIK);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $mentah);

        $data = token_ambil($this->conn, 'verifikasi_email', $mentah);
        $this->assertNotNull($data);
        $this->assertSame($uid, $data['user_id']);

        token_pakai($this->conn, $data['id']);
        $this->assertNull(token_ambil($this->conn, 'verifikasi_email', $mentah));

        // Jenis salah tidak boleh cocok
        $mentah2 = token_buat($this->conn, $uid, 'reset_sandi', RESET_SANDI_DETIK);
        $this->assertNull(token_ambil($this->conn, 'verifikasi_email', $mentah2));
    }

    public function testIndeksKinerjaTerpasang(): void
    {
        $res = $this->conn->query(
            "SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) AS n
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = '" . DB_NAME . "'
               AND INDEX_NAME IN (
                 'idx_laporan_status','idx_laporan_tanggal',
                 'idx_kasus_status','idx_kasus_urgensi','idx_users_status')"
        );
        $baris = $res ? $res->fetch_assoc() : null;
        $res && $res->free();
        $this->assertSame(5, (int) ($baris['n'] ?? 0), 'Indeks performa belum semua terpasang.');
    }

    public function testKomentarAmbilLaporanTidakAda(): void
    {
        $this->assertSame([], komentar_ambil($this->conn, 99999999));
    }

    public function testKomentarBolehMentorAdmin(): void
    {
        $this->assertTrue(komentar_boleh($this->conn, 99999999, 7, 'admin'));
        $this->assertTrue(komentar_boleh($this->conn, 99999999, 7, 'mentor'));
    }

    public function testKomentarBolehKaryawanBukanPemilik(): void
    {
        // Laporan tidak ada -> karyawan mana pun tidak berhak
        $this->assertFalse(komentar_boleh($this->conn, 99999999, 1, 'karyawan'));
    }

    public function testKolomAbsensiKegiatanKesehatan(): void
    {
        $res = $this->conn->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = '" . DB_NAME . "'
                AND TABLE_NAME = 'absensi'
                AND COLUMN_NAME IN ('kegiatan_hari_ini','kondisi_kesehatan')"
        );
        $kolom = [];
        while ($res && ($b = $res->fetch_assoc())) {
            $kolom[$b['COLUMN_NAME']] = $b['COLUMN_TYPE'];
        }
        $res && $res->free();

        $this->assertArrayHasKey('kegiatan_hari_ini', $kolom);
        $this->assertArrayHasKey('kondisi_kesehatan', $kolom);
        $this->assertStringContainsString('enum(', strtolower($kolom['kondisi_kesehatan']));
    }

    public function testAbsensiUpsertKegiatanKesehatan(): void
    {
        // Pengguna uji + absensi hari ini, lalu dibersihkan.
        $st = $this->conn->prepare(
            "INSERT INTO users (nama, email, password, role, status)
             VALUES ('Absen Uji', ?, ?, 'karyawan', 'aktif')"
        );
        $hash  = password_hash('UjiCoba#2026!', PASSWORD_DEFAULT);
        $email = 'absen.uji.' . bin2hex(random_bytes(4)) . '@contoh.test';
        $st->bind_param('ss', $email, $hash);
        $st->execute();
        $uid = (int) $this->conn->insert_id;
        $st->close();
        $this->tidakUntukDisentuh[] = $uid;

        $hari = date('Y-m-d');

        // Insert pertama (seperti absen masuk).
        $st = $this->conn->prepare(
            "INSERT INTO absensi (user_id, tanggal, jam_masuk, status, kegiatan_hari_ini, kondisi_kesehatan)
             VALUES (?, ?, CURTIME(), 'hadir', ?, ?)
             ON DUPLICATE KEY UPDATE
                jam_masuk = IF(jam_masuk IS NULL, CURTIME(), jam_masuk),
                kegiatan_hari_ini = COALESCE(VALUES(kegiatan_hari_ini), kegiatan_hari_ini),
                kondisi_kesehatan = COALESCE(VALUES(kondisi_kesehatan), kondisi_kesehatan)"
        );
        $kegiatan = 'Menyusun laporan uji';
        $kondisi  = 'kurang_sehat';
        $st->bind_param('isss', $uid, $hari, $kegiatan, $kondisi);
        $st->execute();
        $st->close();

        $res = $this->conn->query(
            "SELECT kegiatan_hari_ini, kondisi_kesehatan FROM absensi
              WHERE user_id = {$uid} AND tanggal = '{$hari}'"
        );
        $baris = $res ? $res->fetch_assoc() : null;
        $res && $res->free();

        $this->assertSame('Menyusun laporan uji', (string) ($baris['kegiatan_hari_ini'] ?? ''));
        $this->assertSame('kurang_sehat', (string) ($baris['kondisi_kesehatan'] ?? ''));

        // Bersihkan baris absensi uji (users dibersihkan di tearDown).
        $this->conn->query("DELETE FROM absensi WHERE user_id = {$uid}");
    }

    public function testKolomFotoProfilDanUserFoto(): void
    {
        // 1) Kolom users.foto_profil harus ada.
        $res = $this->conn->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = '" . DB_NAME . "'
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'foto_profil'"
        );
        $kolom = $res ? $res->fetch_assoc() : null;
        $res && $res->free();
        $this->assertNotNull($kolom, 'Kolom users.foto_profil harus ada.');
        $this->assertStringContainsString('varchar', strtolower((string) $kolom['COLUMN_TYPE']));

        // 2) user_foto() membaca nilai yang disimpan.
        $st = $this->conn->prepare(
            "INSERT INTO users (nama, email, password, role, status)
             VALUES ('Foto Uji', ?, ?, 'karyawan', 'aktif')"
        );
        $hash  = password_hash('UjiCoba#2026!', PASSWORD_DEFAULT);
        $email = 'foto.uji.' . bin2hex(random_bytes(4)) . '@contoh.test';
        $st->bind_param('ss', $email, $hash);
        $st->execute();
        $uid = (int) $this->conn->insert_id;
        $st->close();
        $this->tidakUntukDisentuh[] = $uid;

        $this->assertSame('', user_foto($this->conn, $uid), 'Belum ada foto -> string kosong.');

        $namaBerkas = 'u' . $uid . '_abcdef1234567890.png';
        $st = $this->conn->prepare('UPDATE users SET foto_profil = ? WHERE id = ?');
        $st->bind_param('si', $namaBerkas, $uid);
        $st->execute();
        $st->close();

        $this->assertSame($namaBerkas, user_foto($this->conn, $uid));
        // Berkas tidak ada di disk -> tidak menghasilkan URL.
        $this->assertNull(foto_profil_url($namaBerkas));
    }

    public function testLaporanPutuskanValidasiTanpaNotifikasi(): void
    {
        // Keputusan tidak valid -> ditolak sebelum menyentuh basis data.
        $h = laporan_putuskan($this->conn, 99999999, 'ngawur', '');
        $this->assertFalse($h['ok']);
        $this->assertSame('tidak_valid', $h['kode']);

        // Keputusan valid tetapi laporan tidak ada -> tidak_ada (tanpa notifikasi).
        $h = laporan_putuskan($this->conn, 99999999, 'diperiksa', '');
        $this->assertFalse($h['ok']);
        $this->assertSame('tidak_ada', $h['kode']);
    }

    /**
     * Regresi untuk bug pembindan bind_param.
     *
     * Sebelum SQL kasus dipusatkan ke kasus_simpan()/kasus_perbarui(), tiap
     * halaman menulis bind_param inline. Kalaupan urutan tipe tidak selaras
     * dengan urutan nilai (mis. hanya 3 's' untuk 4 kolom teks), mysqli
     * tetap mengeksekusi tanpa galat — hanya kolomnya senyap salah isi:
     * nama_lokasi/status_kasus/kategori menjadi '0' atau '', dan tanggal
     * target 'YYYY-MM-DD' terikat sebagai integer. Karena itu test ini
     * memaksa SEMUA kolom terisi nilai pembeda lalu memeriksanya satu per satu.
     */
    public function testSimpanPerbaruiKasusMenyimpanSemuaKolom(): void
    {
        $kode = 'KAS-UJI' . strtoupper(bin2hex(random_bytes(2)));
        $data = [
            'kode_kasus'          => $kode,
            'judul'               => 'Judul kasus uji',
            'deskripsi'           => 'Deskripsi kasus uji',
            'nama_lokasi'         => 'Duri, Riau',
            'garis_lintang'       => -0.5,
            'garis_bujur'         => 101.5,
            'tingkat_urgensi'     => 'Tinggi',
            'status_kasus'        => 'Dalam Proses',
            'progres'             => 45,
            'kategori'            => 'Lingkungan',
            'sumber'              => 'Manual',
            'target_selesai'      => '2026-12-31',
            'dibuat_oleh'         => 7,
            'penanggung_jawab_id' => 7,
        ];

        $id = kasus_simpan($this->conn, $data);

        try {
            $this->assertGreaterThan(0, $id, 'kasus_simpan harus mengembalikan id baru.');
            $baris = $this->bacaKasus($id);

            $this->assertStringStartsWith('KAS-', (string) $baris['kode_kasus']);
            $this->assertSame($kode, (string) $baris['kode_kasus']);
            $this->assertSame('Judul kasus uji', (string) $baris['judul'], 'judul salah terikat.');
            $this->assertSame('Deskripsi kasus uji', (string) $baris['deskripsi']);
            $this->assertSame('Duri, Riau', (string) $baris['nama_lokasi'], 'nama_lokasi jadi "0" (bind tipe salah).');
            $this->assertSame('Tinggi', (string) $baris['tingkat_urgensi']);
            $this->assertSame('Dalam Proses', (string) $baris['status_kasus'], 'status jadi "" (bind tipe salah).');
            $this->assertSame('45', (string) $baris['progres']);
            $this->assertSame('Lingkungan', (string) $baris['kategori'], 'kategori jadi "0" (bind tipe salah).');
            $this->assertSame('Manual', (string) $baris['sumber']);
            $this->assertSame('2026-12-31', (string) $baris['target_selesai'], 'tanggal target terikat sebagai integer.');
            $this->assertSame('7', (string) $baris['dibuat_oleh']);
            $this->assertSame('7', (string) $baris['penanggung_jawab_id']);
            $this->assertEqualsWithDelta(-0.5, (float) $baris['garis_lintang'], 0.0000001);
            $this->assertEqualsWithDelta(101.5, (float) $baris['garis_bujur'], 0.0000001);

            // ---- UPDATE dengan nilai pembeda ----
            $this->assertTrue(kasus_perbarui($this->conn, [
                'id'                  => $id,
                'judul'               => 'Judul kasus uji revisi',
                'deskripsi'           => 'Deskripsi revisi',
                'nama_lokasi'         => 'Duri Baru',
                'garis_lintang'       => 1.25,
                'garis_bujur'         => 102.75,
                'tingkat_urgensi'     => 'Kritis',
                'status_kasus'        => 'Selesai',
                'progres'             => 100,
                'kategori'            => 'Kesehatan',
                'target_selesai'      => '2027-01-15',
                'penanggung_jawab_id' => 7,
            ]), 'kasus_perbarui harus berhasil.');

            $baris = $this->bacaKasus($id);
            $this->assertSame('Judul kasus uji revisi', (string) $baris['judul']);
            $this->assertSame('Deskripsi revisi', (string) $baris['deskripsi']);
            $this->assertSame('Duri Baru', (string) $baris['nama_lokasi']);
            $this->assertSame('Kritis', (string) $baris['tingkat_urgensi']);
            $this->assertSame('Selesai', (string) $baris['status_kasus']);
            $this->assertSame('100', (string) $baris['progres']);
            $this->assertSame('Kesehatan', (string) $baris['kategori']);
            $this->assertSame('2027-01-15', (string) $baris['target_selesai']);
            $this->assertSame('7', (string) $baris['penanggung_jawab_id']);
            $this->assertEqualsWithDelta(1.25, (float) $baris['garis_lintang'], 0.0000001);
            $this->assertEqualsWithDelta(102.75, (float) $baris['garis_bujur'], 0.0000001);

            // ---- Kosong harus benar-benar NULL, bukan '0' atau string kosong ----
            $this->assertTrue(kasus_perbarui($this->conn, [
                'id'                  => $id,
                'judul'               => 'Judul kasus uji revisi',
                'deskripsi'           => null,
                'nama_lokasi'         => null,
                'garis_lintang'       => null,
                'garis_bujur'         => null,
                'tingkat_urgensi'     => 'Kritis',
                'status_kasus'        => 'Selesai',
                'progres'             => 100,
                'kategori'            => null,
                'target_selesai'      => null,
                'penanggung_jawab_id' => null,
            ]));

            $baris = $this->bacaKasus($id);
            $this->assertNull($baris['nama_lokasi'], 'nama_lokasi kosong harus NULL, bukan "0".');
            $this->assertNull($baris['deskripsi']);
            $this->assertNull($baris['kategori'], 'kategori kosong harus NULL, bukan "0".');
            $this->assertNull($baris['target_selesai']);
            $this->assertNull($baris['penanggung_jawab_id']);
            $this->assertNull($baris['garis_lintang']);
            $this->assertNull($baris['garis_bujur']);
        } finally {
            // Baris uji selalu dibersihkan walau test gagal.
            foreach (['kasus_status_log', 'kasus_penugasan', 'kasus_pihak'] as $tabel) {
                $this->conn->query("DELETE FROM {$tabel} WHERE kasus_id = {$id}");
            }
            $this->conn->query("DELETE FROM riwayat_tindak_lanjut WHERE kasus_id = {$id}");
            $this->conn->query("DELETE FROM kasus WHERE id = {$id}");
        }
    }

    /** Baca satu baris kasus berdasarkan id (atau [] bila tak ada). */
    private function bacaKasus(int $id): array
    {
        $res = $this->conn->query('SELECT * FROM kasus WHERE id = ' . $id . ' LIMIT 1');
        $baris = $res ? $res->fetch_assoc() : null;
        $res && $res->free();
        return is_array($baris) ? $baris : [];
    }
}
