<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Pencocokan nama ketikan (teks bebas) ke pengguna terdaftar.
 *
 * Modul kasus mengizinkan pembuat MENGETIK bebas nama PIC & anggota tim
 * (bukan memilih dropdown). Agar notifikasi dan hak akses tetap bekerja,
 * setiap nama yang bisa dicocokkan dengan pengguna nyata otomatis diisi
 * `user_id`-nya; yang tidak cocok disimpan sebagai teks murni (kasus
 * tetap bisa dibuat walau PIC bukan pengguna sistem).
 *
 * Pencocokan, dengan urutan keandalan menurun:
 *   1. surel persis (case-insensitive);
 *   2. nama persis (case-insensitive, tanpa spasi ganda);
 *   3. nama berawalan ketikan (prefix), unik saja.
 *
 * Pemakaian prefix HANYA diterima bila cocok TEPAT SATU pengguna --
 * bila lebih dari satu (mis. ketik "Budi" saat ada "Budi A" dan
 * "Budi B"), dianggap ambigu dan TIDAK dicocokkan (tetap teks), supaya
 * tidak pernah menugaskan orang yang salah.
 */
class CocokPengguna
{
    /**
     * Cari pengguna yang cocok dengan satu baris nama ketikan.
     *
     * @return array{user_id: int|null, teks: string}
     */
    public function cocok(string $ketikan): array
    {
        $teks = $this->bersih($ketikan);

        if ($teks === '') {
            return ['user_id' => null, 'teks' => ''];
        }

        /* 1) Surel persis. */
        if (filter_var($teks, FILTER_VALIDATE_EMAIL)) {
            $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($teks)])->first();
            if ($user !== null) {
                return ['user_id' => (int) $user->id, 'teks' => $teks];
            }
        }

        $rendah = mb_strtolower($teks);

        /* 2) Nama persis. */
        $user = User::whereRaw('LOWER(nama) = ?', [$rendah])->first();
        if ($user !== null) {
            return ['user_id' => (int) $user->id, 'teks' => $teks];
        }

        /* 3) Nama berawalan, unik saja. */
        $kandidat = User::whereRaw('LOWER(nama) LIKE ?', [$rendah . '%'])
            ->limit(2)
            ->get();

        if ($kandidat->count() === 1) {
            return ['user_id' => (int) $kandidat->first()->id, 'teks' => $teks];
        }

        /* Tidak cocok atau ambigu: teks murni. */
        return ['user_id' => null, 'teks' => $teks];
    }

    /**
     * Pecah kotak teks multi-baris anggota menjadi daftar tercocok.
     *
     * @param  string  $kotak  Nama anggota, satu per baris (koma juga
     *                         diterima sebagai pemisah).
     * @return list<array{user_id: int|null, teks: string}>
     */
    public function cocokBanyak(string $kotak): array
    {
        $baris = preg_split('/[\r\n,]+/', $kotak) ?: [];
        $hasil = [];

        foreach ($baris as $b) {
            $c = $this->cocok($b);
            if ($c['teks'] === '') {
                continue;
            }

            /* Hindari pengulangan user_id yang sama dalam satu kasus. */
            if ($c['user_id'] !== null) {
                $sudah = false;
                foreach ($hasil as $h) {
                    if ($h['user_id'] === $c['user_id']) {
                        $sudah = true;
                        break;
                    }
                }
                if ($sudah) {
                    continue;
                }
            }

            $hasil[] = $c;
        }

        return $hasil;
    }

    /** Rapikan ketikan: trim + hilangkan spasi ganda. */
    private function bersih(string $t): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }
}
