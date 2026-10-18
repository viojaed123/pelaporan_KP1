<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class KasusController extends Controller
{
    public function index(Request $request): View
    {
        return view('kasus.index', [
            'menuAktif' => 'kasus',
        ]);
    }
}