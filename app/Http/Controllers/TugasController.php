<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tugas;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Pembagian tugas: mentor/admin menugaskan pekerjaan kepada karyawan.
 *
 * Karyawan melihat daftar tugasnya (termasuk yang terlambat) di halaman ini
 * dan dapat menandai selesai; mentor/admin dapat membuat tugas baru dan
 * memantau seluruh tugas anggotanya.
 */
class TugasController extends Controller
{
    /** Daftar tugas sesuai peran pengguna yang masuk. */
    public function index(Request $request)
    {
        $user = Auth::user();
        $q = Tugas::with(['penerima:id,nama', 'pemberiTugas:id,nama'])->latest('id');

        if (($user->role ?? 'karyawan') === 'karyawan') {
            $q->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        $tugas = $q->paginate(20)->withQueryString();

        $daftarKaryawan = in_array($user->role, ['mentor', 'admin'], true)
            ? User::where('role', 'karyawan')->orderBy('nama')->get(['id', 'nama'])
            : collect();

        return view('tugas.index', [
            'menuAktif' => 'tugas',
            'tugas' => $tugas,
            'daftarKaryawan' => $daftarKaryawan,
            'terlambat' => Tugas::terlambat()->count(),
        ]);
    }

    /** Mentor/admin: form tugas baru. */
    public function buat()
    {
        $daftarKaryawan = User::where('role', 'karyawan')->orderBy('nama')->get(['id', 'nama']);
        return view('tugas.buat', ['menuAktif' => 'tugas', 'daftarKaryawan' => $daftarKaryawan]);
    }

    /** Mentor/admin: simpan tugas baru. */
    public function simpan(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'judul' => 'required|string|max:200',
            'deskripsi' => 'nullable|string|max:5000',
            'tenggat' => 'nullable|date|after_or_equal:today',
        ]);

        Tugas::create([
            'user_id' => (int) $request->input('user_id'),
            'ditugaskan_oleh' => (int) Auth::id(),
            'judul' => $request->input('judul'),
            'deskripsi' => $request->input('deskripsi'),
            'tenggat' => $request->input('tenggat'),
            'status' => 'baru',
        ]);

        return redirect()->route('tugas.index')->with('sukses', 'Tugas ditugaskan.');
    }

    /** Penerima: ubah status tugas miliknya (mulai / selesai). */
    public function perbaruiStatus(Request $request, int $id)
    {
        $request->validate(['status' => 'required|in:baru,dikerjakan,selesai']);

        $tugas = Tugas::findOrFail($id);
        $user = Auth::user();
        $boleh = $tugas->user_id === $user->id || in_array($user->role, ['mentor', 'admin'], true);
        if (!$boleh) {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        $tugas->status = $request->input('status');
        $tugas->diselesaikan_pada = $tugas->status === 'selesai' ? now() : null;
        $tugas->save();

        return redirect()->route('tugas.index')->with('sukses', 'Status tugas diperbarui.');
    }
}
