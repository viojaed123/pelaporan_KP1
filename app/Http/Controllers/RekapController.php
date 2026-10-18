<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\View\View;
class RekapController extends Controller
{
    public function laporan(Request $request): View
    {
        return view('rekap.laporan', ['menuAktif' => 'rekap']);
    }
    public function absensi(Request $request): View
    {
        return view('rekap.absensi', ['menuAktif' => 'rekap_absensi']);
    }
}
