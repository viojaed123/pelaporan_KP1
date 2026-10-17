<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Smoke test struktur: memastikan berkas penting ada dan berkas rahasia
 * tidak berada di dalam direktori publik yang dapat diakses langsung.
 */
final class StrukturTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // Berkas ini kini di tests/legacy/ -> naik DUA tingkat ke root proyek.
        $this->root = dirname(__DIR__, 2);
    }

    public function testHalamanAkunAda(): void
    {
        foreach ([
            'lupa_sandi.php',
            'reset_sandi.php',
            'verifikasi_email.php',
            'login.php',
            'register.php',
            'dua_faktor.php',
            'status.php',
        ] as $berkas) {
            $this->assertFileExists(
                $this->root . '/' . $berkas,
                "Halaman {$berkas} seharusnya ada."
            );
        }
    }

    public function testBerkasPendukungAda(): void
    {
        foreach ([
            'includes/akun.php',
            'includes/pemberitahuan.php',
            'includes/komentar.php',
            'includes/impor_excel.php',
            'includes/jadwal.php',
            'includes/kasus.php',
            'buat_kasus.php',
            'edit_kasus.php',
            'hapus_kasus.php',
            'komentar_proses.php',
            'impor_laporan.php',
            'jadwal.php',
            'powerbi_ekspor.php',
            'scripts/pengingat_jadwal.php',
            'includes/config_lokal.example.php',
            // Skema SQL lama dipindah ke database/schema/legacy/ (struktur Laravel:
            // database/migrations/ kini dipakai migrasi .php bawaan framework).
            'database/schema/legacy/013_peningkatan_akun.sql',
            'database/schema/legacy/014_komentar_laporan.sql',
            'database/schema/legacy/015_absensi_kegiatan_kesehatan.sql',
            'database/schema/legacy/016_foto_profil.sql',
            'database/schema/legacy/017_impor_laporan.sql',
            'database/schema/legacy/018_jadwal.sql',
            'database/schema/legacy/019_akses_baca_ai.sql',
            'database/schema/legacy/020_otp_jenis.sql',
            'database/schema/legacy/021_srm_cid.sql',
            'database/schema/legacy/022_cid_bigint.sql',
            'database/schema/legacy/023_wilayah_lokasi.sql',
            'database/schema/legacy/024_geokode_cache.sql',
            'database/schema/legacy/025_kasus_multipengguna.sql',
            'ai-service/app/tools/jadwal.py',
            'ai-service/app/tools/search.py',
            'ai-service/skills/pencarian/SKILL.md',
            'ai-service/skills/jadwal/SKILL.md',
            'tools/pemeliharaan.php',
            'tools/backup_db.php',
            'composer.json',
            'phpunit.xml',
            'phpunit-legacy.xml',
            '.gitignore',
            // Kerangka Laravel 12
            'artisan',
            'bootstrap/app.php',
            'bootstrap/providers.php',
            'config/app.php',
            'config/database.php',
            'routes/web.php',
            'app/Http/Controllers/Controller.php',
            'app/Models/User.php',
        ] as $berkas) {
            $this->assertFileExists(
                $this->root . '/' . $berkas,
                "Berkas {$berkas} seharusnya ada."
            );
        }
    }

    public function testGitignoreMengabaikanRahasia(): void
    {
        $isi = (string) file_get_contents($this->root . '/.gitignore');
        $this->assertStringContainsString('includes/config_lokal.php', $isi);
        $this->assertStringContainsString('ai-service/.env', $isi);
    }

    public function testConfigLokalTidakBerisiNilaiPlaceholder(): void
    {
        // Bila config_lokal.php ada, ia harus berisi sandi DB yang sesungguhnya.
        $lokal = $this->root . '/includes/config_lokal.php';
        if (!is_file($lokal)) {
            $this->markTestSkipped('config_lokal.php belum dibuat (memakai nilai bawaan).');
        }
        $this->assertStringNotContainsString('GANTI_SANDI_DB', (string) file_get_contents($lokal));
    }

    public function testKonfigurasiBawaanAmanTanpaRahasia(): void
    {
        // config.php tidak boleh memuat rahasia asli
        $cfg = (string) file_get_contents($this->root . '/includes/config.php');
        $this->assertStringContainsString("cfg('DB_PASS', 'GANTI_SANDI_DB')", $cfg);
        $this->assertStringNotContainsString('Pkcd1b4dea6b019a97eec6f33a14fd5cf54b47087fb7a3c8f4', $cfg);
    }
}
