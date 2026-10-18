<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Rules\KebijakanSandi;
use App\Services\RiwayatSandi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfilController extends Controller
{
    public function __construct(private readonly RiwayatSandi $riwayatSandi)
    {
    }

    public function index(Request $request)
    {
        return view('profil.index', [
            'menuAktif' => 'profil',
            'pengguna' => $request->user(),
        ]);
    }

    public function gantiPassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rules = [
            'password_lama' => ['required', 'string'],
            'password_baru' => [
                'required',
                'string',
                'confirmed',
                new KebijakanSandi($user?->email, $user?->nama),
            ],
        ];

        $data = $request->validate($rules);

        if (! Hash::check($data['password_lama'], (string) $user->password)) {
            return back()->withErrors([
                'password_lama' => 'Kata sandi lama tidak sesuai.',
            ]);
        }

        if ($this->riwayatSandi->terpakaiSebelumnya((int) $user->id, $data['password_baru']) !== null) {
            return back()->withErrors([
                'password_baru' => 'Kata sandi baru pernah dipakai sebelumnya. Pilih kata sandi berbeda.',
            ]);
        }

        $user->password = $data['password_baru'];
        $user->save();

        $this->riwayatSandi->simpan((int) $user->id, (string) $user->password);

        return back()->with('sukses', 'Kata sandi berhasil diubah.');
    }
}