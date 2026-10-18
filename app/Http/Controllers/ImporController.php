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
            'jenis' => 'required|in:auto,laporan,absensi',
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
        if ($jenis === 'auto') {
            require_once __DIR__.'/../../includes/impor_excel.php';
            $baca = impor_baca($helper->dir().'/'.$nama, null, 50);
            $jenis = $this->deteksiJenis($baca['header'] ?? []);
        }
        session(['impor_token' => $token, 'impor_jenis' => $jenis, 'impor_ext' => $ext, 'impor_nama' => $file->getClientOriginalName()]);
        return redirect()->route('impor.pemetaan', ['token'=>$token,'jenis'=>$jenis,'ext'=>$ext]);
    }

    /** Deteksi jenis data dari header: cocokkan jumlah alias yang terpetakan. */
    private function deteksiJenis(array $header): string
    {
        require_once __DIR__.'/../../includes/impor_excel.php';
        $skor = [];
        foreach (['laporan', 'absensi'] as $j) {
            $peta = impor_tebak_pemetaan($header, $j);
            $wajib = array_filter(impor_field($j), fn ($d) => $d['wajib']);
            $wajibTerkunci = count(array_intersect_key($wajib, $peta));
            $skor[$j] = [count($peta), $wajibTerkunci];
        }
        arsort($skor);
        $teratas = array_key_first($skor);
        return ($skor[$teratas][1] === 0 && $skor[$teratas][0] === 0) ? 'laporan' : $teratas;
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
        // Terapkan profil bila diminta (pemetaan tersimpan berupa kunci=>indeks kolom).
        $profilAktif = (int) $request->query('profil', 0);
        if ($profilAktif > 0) {
            foreach ($profil as $p) {
                if ((int)$p['id'] === $profilAktif && $p['pemetaan'] !== []) {
                    $petaTebak = $p['pemetaan'];
                    break;
                }
            }
        }
        return view('impor.pemetaan', compact('token','jenis','ext','baca','petaTebak','field','paksaUser','profil','role'));
    }

    /** Simpan pemetaan kolom saat ini sebagai profil milik pengguna. */
    public function simpanProfil(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'jenis' => 'required|in:laporan,absensi',
            'ext' => 'required|string|max:8',
            'nama_profil' => 'required|string|max:100',
            'peta' => 'array',
        ]);
        require_once __DIR__.'/../../includes/impor_excel.php';
        $peta = [];
        foreach ((array) $request->input('peta', []) as $idx => $v) {
            if ($v !== '') { $peta[(string)$v] = (int)$idx; }
        }
        $ok = impor_simpan_profil(db_koneksi(), (int) Auth::id(), $request->input('jenis'), $request->input('nama_profil'), $peta);
        return redirect()
            ->route('impor.pemetaan', ['token'=>$request->input('token'),'jenis'=>$request->input('jenis'),'ext'=>$request->input('ext')])
            ->with($ok ? 'sukses' : 'galat', $ok ? 'Profil pemetaan tersimpan.' : 'Gagal menyimpan profil.');
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
        session(['impor_peta_'.$token => $peta]);
        return view('impor.pratinjau', compact('token','jenis','ext','baca','peta','valid'));
    }

    public function simpan(Request $request)
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
        $peta = session('impor_peta_' . $token) ?: [];
        $conn = db_koneksi();
        $uid = Auth::id();
        $role = session('role') ?? '';
        $paksaUser = $role==='karyawan' ? (int)$uid : 0;
        $valid = impor_validasi($baca['baris'], $peta, $jenis, impor_peta_pengguna($conn), ['paksa_user_id'=>$paksaUser]);
        $res = impor_commit($conn, $valid['baris'], $jenis, ['duplikat'=>'lewati']);
        @unlink($berkas);
        return view('impor.hasil', compact('res','valid','jenis'));
    }
}