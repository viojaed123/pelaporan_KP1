<?php
/**
 * api_notifikasi_jumlah.php — jumlah notifikasi belum dibaca (JSON).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!sudah_masuk()) {
    http_response_code(401);
    echo json_encode(['jumlah' => 0, 'galat' => 'unauthorized']);
    exit;
}

$uid = sesi_user_id();

try {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM notifikasi WHERE user_id = ? AND sudah_dibaca = 0'
    );
    if (!$stmt) {
        throw new RuntimeException('stmt');
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();

    echo json_encode(['jumlah' => (int) $n]);
} catch (Throwable $t) {
    echo json_encode(['jumlah' => 0]);
}
