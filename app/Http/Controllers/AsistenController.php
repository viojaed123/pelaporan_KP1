<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class AsistenController extends Controller
{
    public function index(Request \): View
    {
        return view('asisten.index', [
            'menuAktif' => 'asisten',
        ]);
    }

    public function kirim(Request \)
    {
        \ = (string) \->input('pesan', '');
        return response()->json([
            'sukses' => true,
            'jawaban' => 'Halo! Saya Asisten Pelaporan PHR. Pesan Anda: ' . \,
        ]);
    }
}
