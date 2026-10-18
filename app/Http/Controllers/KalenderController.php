<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class KalenderController extends Controller
{
    public function index(Request $request): View
    {
        return view('kalender.index', [
            'menuAktif' => 'kalender',
        ]);
    }
}