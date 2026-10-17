<?php
/**
 * includes/komentar.php
 * Diskusi/komentar pada laporan harian (karyawan <-> mentor/admin).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('komentar_ambil')) {
    /**
     * Ambil seluruh komentar satu laporan, terurut lama -> baru.
     *
     * @return array<int, array<string, mixed>>
     */
    function komentar_ambil(mysqli $conn, int $laporanId): array
    {
        $hasil = [];
        $stmt = $conn->prepare(
            'SELECT k.id, k.user_id, k.isi, k.dibuat_pada,
                    u.nama, u.role
               FROM komentar_laporan k
               JOIN users u ON u.id = k.user_id
              WHERE k.laporan_id = ?
              ORDER BY k.dibuat_pada ASC, k.id ASC'
        );
        if (!$stmt) {
            return $hasil;
        }
        $stmt->bind_param('i', $laporanId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($b = $res->fetch_assoc())) {
            $hasil[] = $b;
        }
        $stmt->close();
        return $hasil;
    }
}

if (!function_exists('komentar_boleh')) {
    /** Pemilik laporan atau mentor/admin boleh berkomentar. */
    function komentar_boleh(mysqli $conn, int $laporanId, int $userId, string $role): bool
    {
        if (in_array($role, ['mentor', 'admin'], true)) {
            return true;
        }
        $stmt = $conn->prepare('SELECT user_id FROM laporan_harian WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $laporanId);
        $stmt->execute();
        $stmt->bind_result($pemilik);
        $ada = $stmt->fetch();
        $stmt->close();
        return $ada && (int) $pemilik === $userId;
    }
}

if (!function_exists('komentar_tampil')) {
    /**
     * Cetak kartu diskusi (daftar komentar + formulir) untuk sebuah laporan.
     * Hanya dirender bila pengguna berhak.
     */
    function komentar_tampil(mysqli $conn, int $laporanId, int $userId, string $role, string $balik = ''): void
    {
        if (!komentar_boleh($conn, $laporanId, $userId, $role)) {
            return;
        }

        $daftar = komentar_ambil($conn, $laporanId);
        ?>
        <section class="kartu" id="diskusi">
            <div class="kartu-kepala">
                <h3>Diskusi Laporan</h3>
                <span class="text-redup text-kecil" style="margin-left:auto">
                    <?= count($daftar) ?> komentar
                </span>
            </div>
            <div class="kartu-badan">
                <?php if ($daftar === []): ?>
                    <p class="text-redup">Belum ada komentar. Mulai diskusi mengenai laporan ini.</p>
                <?php else: ?>
                    <div class="daftar-komentar">
                        <?php foreach ($daftar as $k): ?>
                            <?php
                            $peran = (string) $k['role'];
                            $kelasAvatar = match ($peran) {
                                'admin'  => 'avatar-admin',
                                'mentor' => 'avatar-mentor',
                                default  => 'avatar-karyawan',
                            };
                            $labelPeran = match ($peran) {
                                'admin'  => 'Admin',
                                'mentor' => 'Mentor',
                                default  => 'Karyawan',
                            };
                            ?>
                            <article class="komentar <?= (int) $k['user_id'] === $userId ? 'komentar-saya' : '' ?>">
                                <span class="avatar avatar-kecil <?= $kelasAvatar ?>">
                                    <?= e(inisial((string) $k['nama'])) ?>
                                </span>
                                <div class="komentar-isi">
                                    <div class="komentar-kepala">
                                        <strong><?= e((string) $k['nama']) ?></strong>
                                        <span class="badge badge-navy badge-tanpa-titik text-kecil"><?= e($labelPeran) ?></span>
                                        <span class="text-redup text-kecil komentar-waktu">
                                            <?= e(tanggal_id((string) $k['dibuat_pada'], 'd M Y H:i')) ?>
                                        </span>
                                    </div>
                                    <div class="komentar-teks"><?= nl2br(e((string) $k['isi'])) ?></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="komentar_proses.php" class="form-komentar">
                    <?= csrf_field('komentar_laporan') ?>
                    <input type="hidden" name="laporan_id" value="<?= (int) $laporanId ?>">
                    <input type="hidden" name="balik" value="<?= e($balik) ?>">
                    <div class="grup-form mb-0">
                        <label for="komentar-isi" class="label-form">Tulis komentar</label>
                        <textarea id="komentar-isi" name="isi" rows="3" maxlength="2000" required
                                  placeholder="Tulis pertanyaan, arahan, atau tanggapan..."></textarea>
                        <span class="text-redup text-kecil">Maksimal 2000 karakter.</span>
                    </div>
                    <div class="mt-2">
                        <button class="tombol tombol-utama" type="submit">Kirim Komentar</button>
                    </div>
                </form>
            </div>
        </section>
        <?php
    }
}
