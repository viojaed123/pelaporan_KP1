<?php
/**
 * includes/ai_client.php
 * Klien HTTP tunggal ke layanan AI (Python / FastAPI).
 *
 * PHP TIDAK pernah bicara langsung ke Ollama maupun Hermes — semuanya
 * melewati gateway ini sehingga kunci layanan & model tersimpan di satu tempat.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('ai_panggil')) {
    /**
     * Panggil endpoint layanan AI.
     *
     * @param string $jalur   mis. '/chat', '/validate', '/narrative'
     * @param array  $badan   data JSON
     * @param int    $timeout detik tunggu
     * @return array{ok: bool, data: ?array, galat: ?string, status: int}
     */
    function ai_panggil(string $jalur, array $badan = [], int $timeout = 0, string $metode = 'POST'): array
    {
        if (!AI_AKTIF) {
            return ['ok' => false, 'data' => null, 'galat' => 'Layanan AI dimatikan.', 'status' => 0];
        }

        $timeout = $timeout > 0 ? $timeout : AI_TIMEOUT_DETIK;
        $url = rtrim(AI_BASE_URL, '/') . '/' . ltrim($jalur, '/');

        $payload = json_encode($badan, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['ok' => false, 'data' => null, 'galat' => 'Payload tidak valid.', 'status' => 0];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST  => $metode,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Service-Key: ' . AI_SERVICE_KEY,
            ],
        ]);

        if ($metode === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $balasan = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $gagaldetail = curl_error($ch);
        curl_close($ch);

        if ($balasan === false) {
            return [
                'ok'    => false,
                'data'  => null,
                'galat' => 'Tidak dapat terhubung ke layanan AI (' . $gagaldetail . ').',
                'status' => 0,
            ];
        }

        $json = json_decode((string) $balasan, true);
        if (!is_array($json)) {
            return [
                'ok'    => false,
                'data'  => null,
                'galat' => 'Balasan layanan AI tidak terbaca (status ' . $status . ').',
                'status' => $status,
            ];
        }

        $ok = $status >= 200 && $status < 300;
        return [
            'ok'    => $ok,
            'data'  => $json,
            'galat' => $ok ? null : (string) ($json['detail'] ?? $json['pesan'] ?? 'Galat layanan AI.'),
            'status' => $status,
        ];
    }
}

if (!function_exists('ai_hidup')) {
    /** Uji kesehatan layanan AI. @return bool */
    function ai_hidup(): bool
    {
        $r = ai_panggil('/health', [], 4, 'GET');
        return $r['ok'];
    }
}

if (!function_exists('ai_ringkas')) {
    /** Permintaan ringkas (naskah pendek). */
    function ai_ringkas(string $jenis, array $param, int $timeout = 0): array
    {
        return ai_panggil('/summarize', ['jenis' => $jenis, 'parameter' => $param], $timeout);
    }
}

if (!function_exists('ai_narasi')) {
    /** Narasi laporan (tugas berat — pakai antrian job bila memungkinkan). */
    function ai_narasi(array $param, int $timeout = 0): array
    {
        return ai_panggil('/narrative', ['parameter' => $param], $timeout ?: AI_TIMEOUT_PANJANG);
    }
}

if (!function_exists('ai_validasi')) {
    /** Pemeriksaan kelengkapan laporan oleh AI. */
    function ai_validasi(array $laporan): array
    {
        return ai_panggil('/validate', ['laporan' => $laporan]);
    }
}

if (!function_exists('ai_antrian_buat')) {
    /**
     * Daftarkan pekerjaan berat ke antrean (ai_jobs) — diproses latar
     * belakang oleh layanan AI, hasilnya dibaca dari tabel yang sama.
     * @return string|null id job
     */
    function ai_antrian_buat(mysqli $conn, int $userId, string $jenis, array $parameter): ?string
    {
        try {
            $id = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xffff), random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0x0fff) | 0x4000,
                random_int(0, 0x3fff) | 0x8000,
                random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
            );
            $json = json_encode($parameter, JSON_UNESCAPED_UNICODE);

            $stmt = $conn->prepare(
                'INSERT INTO ai_jobs (id, user_id, jenis, status, parameter)
                 VALUES (?, ?, ?, "antre", ?)'
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('siss', $id, $userId, $jenis, $json);
            $stmt->execute();
            $stmt->close();
            return $id;
        } catch (Throwable $t) {
            error_log('ai_job: ' . $t->getMessage());
            return null;
        }
    }
}

if (!function_exists('ai_antrian_status')) {
    /** @return array|null baris ai_jobs */
    function ai_antrian_status(mysqli $conn, string $id): ?array
    {
        $stmt = $conn->prepare(
            'SELECT id, jenis, status, hasil, pesan_error, dibuat_pada, selesai_pada
             FROM ai_jobs WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($baris && is_string($baris['hasil'])) {
            $baris['hasil'] = json_decode($baris['hasil'], true);
        }
        return $baris ?: null;
    }
}
