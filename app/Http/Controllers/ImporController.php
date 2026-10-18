<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class ImporController extends Controller
{
    public function index(Request $request)
    {
        return view('impor.index', ['menuAktif' => 'impor']);
    }
    public function unggah(Request $request)
    {
        $request->validate([
            'jenis' => 'required|in:laporan,absensi',
            'berkas' => 'required|file|mimes:xlsx,xls,csv|max:5120'
        ]);
        $jenis = $request->input('jenis');
        $file = $request->file('berkas');
        $helper = new \App\Services\Impor\ImporHelper();
        $helper->ensureDir();
        $ext = strtolower($file->getClientOriginalExtension());
        $token = bin2hex(random_bytes(16));
        $nama = $token.'.'.$ext;
        $file->move($helper->dir(), $nama);
        session(['impor_token' => $token, 'impor_jenis' => $jenis, 'impor_ext' => $ext, 'impor_nama' => $file->getClientOriginalName()]);
        return redirect()->route('impor.pemetaan', ['token'=>$token,'jenis'=>$jenis,'ext'=>$ext]);
    }
    public function pemetaan(Request $request)
    {
        $token = (string)$request->query('token');
        $jenis = (string)$request->query('jenis');
        $ext = (string)$request->query('ext');
        $helper = new \App\Services\Impor\ImporHelper();
        $berkas = $helper->dir().'/'.$token.'.'.$ext;
        if (!is_file($berkas)) {
            return redirect()->route('impor.index')->withErrors(['berkas'=>'Berkas tidak ditemukan']);
        }
        require_once __DIR__.'/../../includes/impor_excel.php';
        $baca = impor_baca($berkas, null, 5000);
        if (!$baca['ok']) {
            return redirect()->route('impor.index')->withErrors(['berkas'=>$baca['galat']]);
        }
        $petaTebak = impor_tebak_pemetaan($baca['header'], $jenis);
        $field = impor_field($jenis);
        $uid = Auth::id();
        $conn = db_koneksi();
        $role = session('role') ?? '';
        $paksaUser = $role==='karyawan' ? (int)$uid : 0;
        $profil = impor_daftar_profil($conn, (int)$uid, $jenis);
        return view('impor.pemetaan', compact('token','jenis','ext','baca','petaTebak','field','paksaUser','profil','role'));
    }
    public function pratinjau(Request $request)
    {
        $token = (string)$request->input('token');
        $jenis = (string)$request->input('jenis');
        $ext = (string)$request->input('ext');
        $helper = new \App\Services\Impor\ImporHelper();
        $berkas = $helper->dir().'/'.$token.'.'.$ext;
        if (!is_file($berkas)) {
            return redirect()->route('impor.index')->withErrors(['berkas'=>'Berkas tidak ditemukan']);
        }
        require_once __DIR__.'/../../includes/impor_excel.php';
        $baca = impor_baca($berkas, null, 5000);
        if (!$baca['ok']) {
            return redirect()->route('impor.index')->withErrors(['berkas'=>$baca['galat']]);
        }
        $petaInput = $request->input('peta', []);
        $peta = [];
        foreach ($petaInput as $idx => $v) {
            if ($v !== '') { $peta[(string)$v] = (int)$idx; }
        }
        $conn = db_koneksi();
        $uid = Auth::id();
        $role = session('role') ?? '';
        $paksaUser = $role==='karyawan' ? (int)$uid : 0;
        $valid = impor_validasi($baca['baris'], $peta, $jenis, impor_peta_pengguna($conn), ['paksa_user_id'=>$paksaUser]);
        return view('impor.pratinjau', compact('token','jenis','ext','baca','peta','valid'));
    }
}
