<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class ImporController extends Controller
{
    public function index(Request $request)
    {
        return view('impor.index', ['menuAktif' => 'impor']);
    }
}
