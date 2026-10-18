<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class AsistenController extends Controller
{
    public function index(Request $request)
    {
        return view('asisten.index', ['menuAktif' => 'asisten']);
    }
    public function kirim(Request $request)
    {
        $pesan = trim((string) $request->input('pesan', ''));
        $jawaban = $this->jawab($pesan);
        return response()->json(['sukses' => true, 'jawaban' => $jawaban]);
    }
    private function jawab(string $pesan): string
    {
        if ($pesan === '') return 'Silakan ketik pertanyaan Anda.';
        $q = strtolower($pesan);
        if (str_contains($q, 'rekap') || str_contains($q, 'laporan')) return 'Untuk rekap laporan: bisa melalui menu Rekap Laporan (mentor/admin). Saya bisa membantu filter periode, ringkasan, dan cek kelengkapan.';
        if (str_contains($q, 'absensi')) return 'Absensi: karyawan isi harian, mentor bisa rekap. Minta periode (tanggal/bulan) untuk saya ringkas.';
        if (str_contains($q, 'profil') || str_contains($q, 'sandi') || str_contains($q, 'password')) return 'Profil & Keamanan: ganti kata sandi sesuai kebijakan (min 8 karakter, tidak umum, beda dari riwayat). Aktifkan 2FA jika tersedia.';
        if (str_contains($q, 'notifikasi')) return 'Notifikasi menampilkan pembaruan laporan/kasus. Bisa saya bantu merangkum yang belum dibaca.';
        if (str_contains($q, 'kasus')) return 'Pelacakan Kasus: catat, tindak lanjut, status. Bisa minta daftar kasus terbuka/tertutup.';
        if (str_contains($q, 'ringkas') || str_contains($q, 'summary')) return 'Saya bisa merangkum: kirim teks laporan (atau minta periode), saya akan buat ringkasan ringkas & terstruktur.';
        if (str_contains($q, 'cari') || str_contains($q, 'search') || str_contains($q, 'temukan')) return 'Mode pencarian: jelaskan apa yang dicari (kata kunci, nama, tanggal, modul). Saya akan sarankan langkah & filter yang relevan.';
        return 'Saya bisa menjawab seputar sistem pelaporan (laporan, absensi, kasus, rekap, profil/keamanan). Coba ajak dengan konteks: "Cari laporan bulan Oktober oleh Budi", atau "Ringkas 5 laporan terakhir".';
    }
}
