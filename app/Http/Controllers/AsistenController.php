<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AsistenController extends Controller
{
    public function index(Request \)
    {
        return view('asisten.index', [
            'menuAktif' => 'asisten',
        ]);
    }

    public function kirim(Request \)
    {
        \ = trim((string) \->input('pesan', ''));
        \ = \->jawab(\);
        return response()->json([
            'sukses' => true,
            'jawaban' => \,
        ]);
    }

    private function jawab(string \): string
    {
        if (\ === '') {
            return 'Silakan ketik pertanyaan Anda.';
        }

        \ = strtolower(\);

        // Pencarian konteks sederhana (mirip assistant dengan recall ringan)
        if (str_contains(\, 'rekap') || str_contains(\, 'laporan')) {
            return 'Untuk rekap laporan: bisa melalui menu Rekap Laporan (mentor/admin). Saya bisa membantu filter periode, ringkasan, dan cek kelengkapan.';
        }
        if (str_contains(\, 'absensi')) {
            return 'Absensi: karyawan isi harian, mentor bisa rekap. Minta periode (tanggal/bulan) untuk saya ringkas.';
        }
        if (str_contains(\, 'profil') || str_contains(\, 'sandi') || str_contains(\, 'password')) {
            return 'Profil & Keamanan: ganti kata sandi sesuai kebijakan (min 8 karakter, tidak umum, beda dari riwayat). Aktifkan 2FA jika tersedia.';
        }
        if (str_contains(\, 'notifikasi')) {
            return 'Notifikasi menampilkan pembaruan laporan/kasus. Bisa saya bantu merangkum yang belum dibaca.';
        }
        if (str_contains(\, 'kasus')) {
            return 'Pelacakan Kasus: catat, tindak lanjut, status. Bisa minta daftar kasus terbuka/tertutup.';
        }
        if (str_contains(\, 'ringkas') || str_contains(\, 'summary')) {
            return 'Saya bisa merangkum: kirim teks laporan (atau minta periode), saya akan buat ringkasan ringkas & terstruktur.';
        }
        if (str_contains(\, 'cari') || str_contains(\, 'search') || str_contains(\, 'temukan')) {
            return 'Mode pencarian: jelaskan apa yang dicari (kata kunci, nama, tanggal, modul). Saya akan sarankan langkah & filter yang relevan.';
        }

        return 'Saya bisa menjawab seputar sistem pelaporan (laporan, absensi, kasus, rekap, profil/keamanan). Coba ajak dengan konteks: "Cari laporan bulan Oktober oleh Budi", atau "Ringkas 5 laporan terakhir".';
    }
}
