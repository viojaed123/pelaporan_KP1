<?php
/**
 * Verifikasi seluruh model Eloquent terhadap database nyata.
 *
 *   php artisan model:periksa          # via artisan (disarankan)
 *   php scripts/verifikasi_model.php   # langsung
 *
 * Yang diperiksa per model:
 *   1. SELECT ... LIMIT 1  -> nama tabel & kolom benar
 *   2. relasi belongsTo/hasMany -> kelas rujukan & kunci asing benar
 *   3. konstanta timestamp -> tidak ada error "Unknown column"
 *
 * KEAMANAN: satu-satunya INSERT/UPDATE yang dilakukan dibungkus
 * DB::transaction() lalu di-rollback, sehingga 36 tabel berisi data
 * produksi tidak berubah sama sekali.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$dir = dirname(__DIR__) . '/app/Models';
$berkas = glob($dir . '/*.php');

echo 'Model terdeteksi: ' . count($berkas) . "\n\n";

$gagal = [];
$ok    = 0;

/* ------------------------------------------------------------------ */
/* 1 & 2. SELECT tiap model + cek relasi                               */
/* ------------------------------------------------------------------ */
echo "=== A. SELECT tiap model (menyentuh data ASLI, tanpa mengubah) ===\n";

foreach ($berkas as $b) {
    $nama = basename($b, '.php');
    $fqcn = 'App\\Models\\' . $nama;

    if (! class_exists($fqcn)) {
        $gagal[] = "$nama: kelas tidak ditemukan (autoload?)";
        continue;
    }

    try {
        /** @var \Illuminate\Database\Eloquent\Model $m */
        $m = new $fqcn();
        $tabel = $m->getTable();

        if (! Schema::hasTable($tabel)) {
            $gagal[] = "$nama: tabel `$tabel` tidak ada di database";
            continue;
        }

        $jumlah = $fqcn::query()->count();
        $contoh = $fqcn::query()->first();

        printf(
            "  %-24s tabel=%-24s baris=%-6d %s\n",
            $nama,
            $tabel,
            $jumlah,
            $contoh === null ? '(kosong)' : 'OK'
        );
        $ok++;
    } catch (Throwable $e) {
        $gagal[] = "$nama: " . $e->getMessage();
    }
}

/* ------------------------------------------------------------------ */
/* 3. Relasi: hanya metode yang DIDEKLARASIKAN di kelas model          */
/* ------------------------------------------------------------------ */
echo "\n=== B. Evaluasi relasi (metode yang dideklarasikan di kelas model) ===\n";

$relasiTotal = 0;
$relasiGagal = 0;

foreach ($berkas as $b) {
    $nama = basename($b, '.php');
    $fqcn = 'App\\Models\\' . $nama;

    if (! class_exists($fqcn)) {
        continue;
    }

    /** @var \Illuminate\Database\Eloquent\Model $m */
    $m = new $fqcn();
    $refleksi = new ReflectionClass($m);

    /* getMethods() tanpa filter akan mengembalikan SEMUA metode publik
     * warisan Model (save, fill, hasMany, ...), yang semuanya membutuhkan
     * argumen. Karena itu batasi hanya yang dideklarasikan di kelas ini. */
    foreach ($refleksi->getMethods(ReflectionMethod::IS_PUBLIC) as $metode) {
        if ($metode->getDeclaringClass()->getName() !== $fqcn) {
            continue; /* metode warisan -- lewati */
        }
        if ($metode->getNumberOfRequiredParameters() > 0) {
            continue; /* butuh argumen -- bukan relasi tanpa parameter */
        }
        if ($metode->isStatic() || $metode->isConstructor() || $metode->isAbstract()) {
            continue;
        }

        try {
            $r = $metode->invoke($m);
            if ($r instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                $relasiTotal++;
                /* Paksa resolusi kelas rujukan + kunci asing */
                $r->getQualifiedForeignKeyName();
                $r->getRelated();
                printf(
                    "  %-24s :: %-24s -> %s (fk=%s)\n",
                    $nama,
                    $metode->getName() . '()',
                    class_basename($r->getRelated()),
                    basename($r->getQualifiedForeignKeyName())
                );
            }
        } catch (Throwable $e) {
            $relasiGagal++;
            $gagal[] = "$nama::{$metode->getName()}() -> " . $e->getMessage();
        }
    }
}

printf("\n  Relasi tervalidasi: %d   gagal: %d\n", $relasiTotal, $relasiGagal);

/* ------------------------------------------------------------------ */
/* 4. Timestamp: konstanta HARUS menunjuk kolom yang benar-benar ada   */
/* ------------------------------------------------------------------ */
echo "\n=== C. Konsistensi konstanta timestamp terhadap kolom nyata ===\n";

$tsGagal = 0;
foreach ($berkas as $b) {
    $nama = basename($b, '.php');
    $fqcn = 'App\\Models\\' . $nama;

    if (! class_exists($fqcn)) {
        continue;
    }

    /** @var \Illuminate\Database\Eloquent\Model $m */
    $m = new $fqcn();
    if (! $m->usesTimestamps()) {
        continue;
    }

    foreach ([$m::CREATED_AT, $m::UPDATED_AT] as $c) {
        if ($c === null) {
            continue; /* sengaja dimatikan -- sah */
        }
        if (! Schema::hasColumn($m->getTable(), $c)) {
            $gagal[] = "$nama: konstanta menunjuk kolom `$c` yang TIDAK ADA di tabel `{$m->getTable()}`";
            $tsGagal++;
        }
    }
}

printf(
    "  Konstanta menunjuk kolom valid untuk semua model   (gagal: %d)\n",
    $tsGagal
);

/* Satu uji tulis NYATA dibungkus transaksi, untuk membuktikan timestamp
 * benar-benar terisi otomatis oleh Eloquent (bukan hanya kolomnya ada). */
echo "\n=== C2. Uji tulis nyata (INSERT lalu ROLLBACK) ===\n";

DB::beginTransaction();

try {
    /* AuditLog dipilih karena kolomnya paling sederhana dan tidak punya
     * kunci asing yang harus valid. */
    $uji = new \App\Models\AuditLog();
    $isi = array_intersect_key(
        ['aksi' => '__UJI_ROLLBACK__'],
        array_flip($uji->getFillable())
    );
    $uji->forceFill($isi);
    $uji->save();

    $kolomWaktu = array_filter([$uji::CREATED_AT, $uji::UPDATED_AT], fn ($c) => $c !== null);
    foreach ($kolomWaktu as $c) {
        $nilai = $uji->getAttribute($c);
        printf(
            "  AuditLog::create() -> %-14s = %s   %s\n",
            $c,
            $nilai ?? '(NULL)',
            $nilai !== null ? 'TERISI OTOMATIS' : '*** KOSONG -- GAGAL ***'
        );
        if ($nilai === null) {
            $gagal[] = "AuditLog: kolom timestamp `$c` tidak terisi otomatis";
        }
    }
} catch (Throwable $e) {
    $gagal[] = 'AuditLog (uji tulis): ' . $e->getMessage();
}

DB::rollBack();
echo "  (transaksi di-ROLLBACK -- data produksi tidak berubah)\n";

/* ------------------------------------------------------------------ */
/* 5. User: pastikan autentikasi berfungsi                              */
/* ------------------------------------------------------------------ */
echo "\n=== D. User (autentikasi) ===\n";
try {
    $user = User::query()->first();
    if ($user !== null) {
        printf("  Pengguna pertama : id=%d role=%s status=%s\n", $user->id, $user->role, $user->status);
        printf("  Hash kata sandi  : %s...\n", substr((string) $user->password, 0, 7));
        printf("  Metode aktif()   : %s\n", var_export($user->aktif(), true));
        printf("  punyaPeran([admin]) : %s\n", var_export($user->punyaPeran(['admin']), true));
        printf("  relasi security  : %s\n", $user->security()->getRelated()::class);
        printf("  relasi laporan   : %d baris\n", $user->laporanHarian()->count());
    } else {
        echo "  (tidak ada pengguna)\n";
    }
} catch (Throwable $e) {
    $gagal[] = 'User: ' . $e->getMessage();
}

/* ------------------------------------------------------------------ */
/* Ringkasan                                                            */
/* ------------------------------------------------------------------ */
echo "\n========================================\n";
if ($gagal === []) {
    printf("SEMUA LULUS: %d model, %d relasi, 0 kesalahan\n", $ok, $relasiTotal);
    exit(0);
}

printf("GAGAL: %d kesalahan\n\n", count($gagal));
foreach ($gagal as $g) {
    echo "  - {$g}\n";
}
exit(1);
