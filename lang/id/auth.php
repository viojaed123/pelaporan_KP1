<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pesan Autentikasi (Bahasa Indonesia)
|--------------------------------------------------------------------------
|
| Dipakai kerangka autentikasi Laravel pada upaya masuk, dan di-*merge*
| dengan pesan khusus aplikasi bila ada. Pesan "failed" di sini yang akan
| tampil ketika surel dan kata sandi tidak cocok dengan catatan database.
|
*/

return [

    'failed' => 'Surel atau kata sandi tidak sesuai dengan catatan kami.',
    'password' => 'Kata sandi yang diberikan tidak benar.',
    'throttle' => 'Terlalu banyak upaya masuk. Silakan coba lagi dalam :seconds detik.',

];
