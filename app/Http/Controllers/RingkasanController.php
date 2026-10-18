<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class RingkasanController extends Controller
{
    public function index(Request $request): View
    {
        return view('ringkasan.index', [
            'menuAktif' => 'ringkasan',
        ]);
    }
}