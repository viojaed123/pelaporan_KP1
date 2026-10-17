<?php
/**
 * includes/mailer.php
 * Pengiriman surat elektronik tunggal (OTP 2FA, notifikasi, laporan).
 *
 * Mode:
 *   - SMTP_HOST kosong  -> mode pengembangan: pesan ditulis ke
 *                          backups/mail-log/YYYY-MM-DD.log  (OTP tetap terbaca).
 *   - SMTP_HOST diisi   -> dikirim via PHPMailer (SMTP).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('kirim_email')) {
    /**
     * @param string $ke          alamat tujuan
     * @param string $subjek      subjek
     * @param string $isiHtml     isi HTML
     * @param string $isiTeks     isi teks polos (fallback)
     * @param array  $lampiran    [['path'=>..., 'nama'=>..., 'mime'=>...]]
     * @return array{ok: bool, galat: ?string, mode: string}
     */
    function kirim_email(
        string $ke,
        string $subjek,
        string $isiHtml,
        string $isiTeks = '',
        array $lampiran = []
    ): array {
        $ke = trim($ke);

        if ($ke === '' || !filter_var($ke, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'galat' => 'Alamat tujuan tidak valid.', 'mode' => '-'];
        }

        /* ---------- Mode pengembangan: tulis ke log ---------- */
        if (SMTP_HOST === '') {
            try {
                $dir = MAIL_LOG_DIR;
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $baris = sprintf(
                    "[%s] KEPADA: %s\nSUBJEK: %s\n---\n%s\n%s\n===\n\n",
                    date('Y-m-d H:i:s'),
                    $ke,
                    $subjek,
                    strip_tags($isiHtml),
                    $isiTeks
                );
                @file_put_contents(
                    $dir . '/' . date('Y-m-d') . '.log',
                    $baris,
                    FILE_APPEND | LOCK_EX
                );
                return ['ok' => true, 'galat' => null, 'mode' => 'log'];
            } catch (Throwable $t) {
                return ['ok' => false, 'galat' => 'Gagal menulis log surat: ' . $t->getMessage(), 'mode' => 'log'];
            }
        }

        /* ---------- Mode SMTP ---------- */
        $kunciComposer = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($kunciComposer)) {
            return ['ok' => false, 'galat' => 'Autoloader Composer tidak ditemukan.', 'mode' => 'smtp'];
        }
        require_once $kunciComposer;

        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return ['ok' => false, 'galat' => 'PHPMailer belum terpasang.', 'mode' => 'smtp'];
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->SMTPSecure = SMTP_ENCRYPTION === '' ? false : SMTP_ENCRYPTION;

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($ke);
            $mail->Subject = $subjek;

            if ($isiTeks !== '') {
                $mail->AltBody = $isiTeks;
            }
            $mail->Body = $isiHtml;

            foreach ($lampiran as $l) {
                if (!empty($l['path']) && is_file($l['path'])) {
                    if (!empty($l['mime'])) {
                        $mail->addAttachment($l['path'], $l['nama'] ?? basename($l['path']), 'b64', $l['mime']);
                    } else {
                        $mail->addAttachment($l['path'], $l['nama'] ?? basename($l['path']));
                    }
                }
            }

            $mail->send();
            return ['ok' => true, 'galat' => null, 'mode' => 'smtp'];
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            error_log('mailer: ' . $e->getMessage());
            return ['ok' => false, 'galat' => 'Gagal mengirim surat: ' . $e->getMessage(), 'mode' => 'smtp'];
        } catch (Throwable $t) {
            error_log('mailer: ' . $t->getMessage());
            return ['ok' => false, 'galat' => 'Kesalahan pengiriman surat.', 'mode' => 'smtp'];
        }
    }
}

if (!function_exists('template_surat')) {
    /**
     * Kerangka surat sederhana dengan warna korporat PHR.
     */
    function template_surat(string $judul, string $badanHtml, string $catatan = ''): string
    {
        $badge = e(WARNA_BIRU_TUA);
        $biru  = e(WARNA_BIRU);
        $abu   = '#64748b';
        $namaAplikasi = e(APP_SINGKAT);
        $organisasi   = e(APP_ORGANISASI);

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F5F5F5;font-family:Segoe UI,Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F5F5;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E2E8F0;">
        <tr>
          <td style="background:{$badge};padding:18px 24px;">
            <div style="color:#FFFFFF;font-size:18px;font-weight:700;">{$namaAplikasi}</div>
            <div style="color:#CBD5E1;font-size:12px;margin-top:2px;">{$organisasi}</div>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <h1 style="margin:0 0 16px;font-size:18px;color:#003D7D;">{$judul}</h1>
            {$badanHtml}
          </td>
        </tr>
        <tr>
          <td style="padding:16px 24px;background:#F8FAFC;border-top:1px solid #E2E8F0;color:{$abu};font-size:12px;">
            {$catatan}
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
