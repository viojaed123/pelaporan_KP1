<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LaporanController extends Controller
{
    public function buat(Request $request): View
    {
        return view('laporan.buat', [
            'menuAktif' => 'buat',
        ]);
    }

    public function riwayat(Request $request): View
    {
        return view('laporan.riwayat', [
            'menuAktif' => 'riwayat',
        ]);
    }
}