<?php
/**
 * Periksa kesiapan tabel `users` untuk autentikasi Laravel.
 *
 *   php scripts/periksa_users.php
 *
 * Menjawab tiga pertanyaan yang menentukan bentuk model User:
 *   - nilai enum role/status (untuk cast dan guard)
 *   - keberadaan remember_token (wajib Authenticatable)
 *   - keberadaan updated_at (menentukan konstanta timestamp)
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

echo "=== 1. DEFINISI ENUM (users.role, users.status) ===\n";
foreach (['role', 'status'] as $c) {
    $s = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $s->execute([$env['DB_NAME'], 'users', $c]);
    printf("  users.%-8s : %s\n", $c, (string) $s->fetchColumn());
}

echo "\n=== 2. DISTRIBUSI DATA (6 pengguna nyata) ===\n";
foreach ($pdo->query('SELECT role, status, COUNT(*) n FROM users GROUP BY role, status') as $r) {
    printf("  role=%-10s status=%-10s %d orang\n", $r['role'], $r['status'], $r['n']);
}

echo "\n=== 3. KOLOM YANG DIBUTUHKAN AUTHENTICATABLE ===\n";
$cek = function (string $kolom) use ($pdo, $env): bool {
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $s->execute([$env['DB_NAME'], 'users', $kolom]);
    return (int) $s->fetchColumn() > 0;
};

foreach (['id', 'email', 'password', 'remember_token', 'email_verified_at', 'created_at', 'updated_at'] as $k) {
    printf(
        "  %-20s : %s\n",
        $k,
        $cek($k) ? 'ADA' : '*** TIDAK ADA ***'
    );
}

echo "\n=== 4. IMPLEMENTASI 'INGAT SAYA' LEGACY (tabel user_security) ===\n";
$kolomS = [];
foreach ($pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = '{$env['DB_NAME']}' AND TABLE_NAME = 'user_security'
      ORDER BY ORDINAL_POSITION"
) as $r) {
    $kolomS[] = $r['COLUMN_NAME'];
}
echo '  ' . implode(', ', $kolomS) . "\n";

echo "\n=== 5. RELASI user_id LAIN YANG MENUNJUK users.id ===\n";
$st = $pdo->prepare(
    "SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME
       FROM information_schema.KEY_COLUMN_USAGE kcu
       JOIN information_schema.TABLE_CONSTRAINTS tc
         ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        AND tc.TABLE_NAME = kcu.TABLE_NAME
        AND tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
      WHERE kcu.TABLE_SCHEMA = ?
        AND kcu.REFERENCED_TABLE_NAME = 'users'
        AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
      ORDER BY kcu.TABLE_NAME"
);
$st->execute([$env['DB_NAME']]);
foreach ($st->fetchAll() as $r) {
    printf("  %-24s.%s\n", $r['TABLE_NAME'], $r['COLUMN_NAME']);
}

echo "\nSelesai.\n";
