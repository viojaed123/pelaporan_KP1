<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Surel kode verifikasi dua faktor (porting otp_kirim dari security.php §3).
 *
 * Versi HTML memakai kerangka korporat PHR; versi teks polos disertakan
 * sebagai alternatif karena sebagian klien surel (dan filter spam) lebih
 * menyukai surel yang punya bagian teks -- surel HTML-saja lebih sering
 * ditandai spam.
 *
 * Judul surel memuat nama aplikasi agar pengguna dapat memastikan kode ini
 * benar berasal dari sistem yang mereka gunakan.
 */
class KodeVerifikasi extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $kode  Kode OTP mentah (hanya ada di memori; tidak
     *                        pernah disimpan -- yang tersimpan hanya hash).
     * @param  string  $nama  Nama penerima, untuk sapaan yang akrab.
     */
    public function __construct(
        public readonly string $kode,
        public readonly string $nama,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode Verifikasi — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kode-verifikasi',
            text: 'emails.kode-verifikasi-teks',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
