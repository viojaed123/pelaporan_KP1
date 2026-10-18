<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Uji cangkang SPA React (rute /app).
 *
 * Cangkang harus dapat dimuat tanpa sesi web (autentikasi SPA memakai
 * token API), dan menyiapkan titik masuk React (#app) + aset Vite.
 */
class SpaTest extends TestCase
{
    public function test_cangkang_spa_dimuat_tanpa_sesi(): void
    {
        $this->get('/app')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('assets/css/app.css', false);
    }

    public function test_semua_subhalaman_spa_mengarah_ke_cangkang(): void
    {
        foreach (['/app/masuk', '/app/laporan/buat', '/app/kasus', '/app/profil'] as $uri) {
            $this->get($uri)->assertOk()->assertSee('id="app"', false);
        }
    }

    public function test_cangkang_memuat_bundle_vite(): void
    {
        /*
         * Manifest Vite dihasilkan `npm run build`. Bila belum ada (mis.
         * lingkungan uji tanpa Node), lewati pemeriksaan bundle -- rute
         * tetap harus 200 karena Blade menyajikan kerangkanya.
         */
        $this->get('/app')->assertOk();

        $manifest = public_path('build/manifest.json');
        if (! file_exists($manifest)) {
            $this->markTestSkipped('Bundle Vite belum dibangun (npm run build).');
        }

        $this->get('/app')
            ->assertSee('/build/assets/', false);
    }
}
