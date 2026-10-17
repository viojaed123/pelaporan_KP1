<?php
/**
 * Generator model Eloquent dari metadata skema (database/schema/skema.json).
 *
 *   php scripts/buat_model.php              # tulis semua model
 *   php scripts/buat_model.php --periksa    # tampilkan hasil tanpa menulis
 *   php scripts/buat_model.php --tabel=kasus
 *
 * Keputusan rancangan yang DIHASILKAN otomatis dari skema:
 *
 *   1. Timestamp tidak seragam di database lama, sehingga tiap model
 *      mendapat konstanta CREATED_AT/UPDATED_AT yang tepat:
 *        created_at + updated_at  -> perilaku default Laravel
 *        dibuat_pada + diperbarui_pada -> CREATED_AT/UPDATED_AT diset
 *        hanya satu kolom waktu   -> satunya diisi, yang lain null
 *        tanpa kolom waktu        -> $timestamps = false
 *
 *   2. Kunci utama bukan selalu "id": user_security memakai user_id
 *      (bukan auto-increment). Ini diset lewat $primaryKey/$incrementing.
 *
 *   3. Tabel sistem Laravel (cache, jobs, sessions, ...) TIDAK dimodelkan:
 *      framework menanganinya sendiri.
 *
 *   4. View Power BI (vw_*) TIDAK dimodelkan sama sekali: 22 view itu
 *      dipakai laporan eksternal dan berstatus baca-saja.
 *
 *   5. $fillable tidak memuat kolom PK maupun kolom timestamp: keduanya
 *      dikelola Laravel sendiri, dan memasukkannya membuka celah
 *      mass-assignment.
 *
 *   6. Berkas yang mengandung teks PENANDA_TULIS_TANGAN tidak ditimpa.
 *      Pakai penanda itu pada model yang sudah Anda perkaya dengan relasi
 *      atau accessor tambahan, agar regenerasi aman dijalankan ulang.
 *      Model `users` juga dikecualikan lewat TABEL_TULIS_TANGAN karena
 *      harus mewarisi Authenticatable, bukan Model.
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

/* ------------------------------------------------------------------ */
/* Tabel yang sengaja TIDAK dimodelkan                                  */
/* ------------------------------------------------------------------ */

/** Tabel sistem bawaan Laravel: dikelola framework, jangan disentuh. */
const TABEL_SISTEM = [
    'cache',
    'cache_locks',
    'failed_jobs',
    'jobs',
    'job_batches',
    'login_locks',
    'migrations',
    'password_reset_tokens',
    'sessions',
];

/**
 * Tabel yang sengaja TIDAK dimodelkan: ditulis tangan dan dijaga dari
 * penimpaan generator.
 *
 * Alasan per tabel:
 *   users -> kelas inti autentikasi; harus mewarisi Authenticatable
 *            (bukan Model), dan punya relasi khusus ke 16 tabel turunan.
 */
const TABEL_TULIS_TANGAN = [
    'users',
];

/* ------------------------------------------------------------------ */
/* Utilitas nama                                                        */
/* ------------------------------------------------------------------ */

function studly(string $s): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $s)));
}

function snake(string $s): string
{
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $s));
}

/* ------------------------------------------------------------------ */
/* Peta tabel -> nama model (untuk menyelesaikan kunci asing)           */
/* ------------------------------------------------------------------ */

/**
 * Pengecualian nama model: tabel yang jamak/berimbuhan tetapi modelnya
 * harus singular mengikuti konvensi Laravel (User, bukan Users).
 *
 * Peta ini sengaja EKSPLISIT, bukan hasil stemming otomatis: stemming
 * bahasa Indonesia tidak dapat diandalkan (mis. "penugasan" singular
 * sedangkan "codes" jamak), dan nama model yang salah akan membuat
 * relasi belongsTo() menunjuk kelas yang tidak ada.
 */
const PETA_MODEL = [
    'users'          => 'User',
    'ai_jobs'        => 'AiJob',
    'backup_codes'   => 'BackupCode',
    'user_tokens'    => 'UserToken',
    'login_attempts' => 'LoginAttempt',
    'chat_messages'  => 'ChatMessage',
    'chat_sessions'  => 'ChatSession',
];

$skema = json_decode((string) file_get_contents(dirname(__DIR__) . '/database/schema/skema.json'), true);
if (! is_array($skema) || ! isset($skema['tabel'])) {
    fwrite(STDERR, "GAGAL membaca database/schema/skema.json. Jalankan scripts/ekspor_skema.php dulu.\n");
    exit(1);
}

$tableKeModel = [];
foreach (array_keys($skema['tabel']) as $t) {
    if (in_array($t, TABEL_SISTEM, true)) {
        continue;
    }
    $tableKeModel[$t] = PETA_MODEL[$t] ?? studly($t);
}

/* ------------------------------------------------------------------ */
/* Pemetaan timestamp                                                   */
/* ------------------------------------------------------------------ */

function konfigurasiTimestamp(array $kolom): array
{
    $nama = array_column($kolom, 'nama');
    $ada  = fn (string $c): bool => in_array($c, $nama, true);

    $created = $ada('created_at') ? 'created_at' : ($ada('dibuat_pada') ? 'dibuat_pada' : null);
    $updated = $ada('updated_at') ? 'updated_at' : ($ada('diperbarui_pada') ? 'diperbarui_pada' : null);

    if ($created === null && $updated === null) {
        return ['timestamps' => false];
    }

    /* Bila persis pasangan default, biarkan Laravel bekerja tanpa konstanta. */
    if ($created === 'created_at' && $updated === 'updated_at') {
        return ['timestamps' => true];
    }

    return [
        'timestamps'    => true,
        'CREATED_AT'    => $created,
        'UPDATED_AT'    => $updated,
    ];
}

/* ------------------------------------------------------------------ */
/* Pemetaan cast                                                        */
/* ------------------------------------------------------------------ */

function castUntuk(array $k): ?string
{
    $tipe = strtolower($k['tipe']);
    $dasar = strtolower($k['tipe_dasar']);

    /* Kolom waktu yang bukan timestamp Laravel -> biar string/Carbon lewat $casts. */
    if ($dasar === 'tinyint' && str_starts_with($tipe, 'tinyint(1)')) {
        return 'bool';
    }
    if (in_array($dasar, ['int', 'integer', 'bigint', 'mediumint', 'smallint'], true)) {
        return 'int';
    }
    if ($dasar === 'decimal') {
        $p = [];
        preg_match('/decimal\((\d+),(\d+)\)/', $tipe, $p);
        return isset($p[2]) ? 'decimal:' . $p[2] : 'decimal:2';
    }
    if ($dasar === 'float' || $dasar === 'double' || $dasar === 'real') {
        return 'float';
    }
    if ($dasar === 'json' || $dasar === 'longtext' && str_contains($tipe, 'json')) {
        return 'array';
    }
    if ($dasar === 'date') {
        return 'date';
    }
    if ($dasar === 'datetime' || $dasar === 'timestamp') {
        return 'datetime';
    }
    if ($dasar === 'time') {
        return 'string';
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Isi model                                                            */
/* ------------------------------------------------------------------ */

$modePeriksa = in_array('--periksa', $argv, true);
$tabelFilter = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--tabel=')) {
        $tabelFilter = substr($a, 8);
    }
}

$dirTujuan = dirname(__DIR__) . '/app/Models';
if (! is_dir($dirTujuan) && ! $modePeriksa) {
    mkdir($dirTujuan, 0775, true);
}

$dibuat = [];
$lewat  = [];
foreach ($skema['tabel'] as $tabel => $info) {
    if (in_array($tabel, TABEL_SISTEM, true)) {
        continue;
    }
    if ($tabelFilter !== null && $tabel !== $tabelFilter) {
        continue;
    }

    $model   = PETA_MODEL[$tabel] ?? studly($tabel);

    /* Lewati tabel tulisan tangan, dan berkas apa pun yang menandai dirinya
     * dengan PENANDA_TULIS_TANGAN agar regenerasi tidak menghapus karya
     * manual (relasi tambahan, accessor, method khusus). */
    $path = $dirTujuan . '/' . $model . '.php';
    if (in_array($tabel, TABEL_TULIS_TANGAN, true)
        || (is_file($path) && str_contains((string) file_get_contents($path), 'PENANDA_TULIS_TANGAN'))) {
        $lewat[] = $tabel . ' (' . $model . ')';
        continue;
    }
    $kolom   = $info['kolom'];
    $pk      = $info['kunci_utama'];
    $pkUtama = $pk[0] ?? 'id';

    $ts = konfigurasiTimestamp($kolom);

    /* Kolom yang tidak boleh masuk $fillable. */
    $terlarang = $pk;
    foreach (['created_at', 'updated_at', 'dibuat_pada', 'diperbarui_pada'] as $c) {
        $terlarang[] = $c;
    }

    $fillable = array_values(array_filter(
        array_column($kolom, 'nama'),
        fn (string $n): bool => ! in_array($n, $terlarang, true)
    ));

    $casts = [];
    foreach ($kolom as $k) {
        $c = castUntuk($k);
        if ($c !== null && in_array($k['nama'], $fillable, true)) {
            $casts[$k['nama']] = $c;
        }
    }

    /* ---------------------------------------------------------------- */
    /* Susun berkas                                                      */
    /* ---------------------------------------------------------------- */
    $b = [];
    $b[] = '<?php';
    $b[] = '';
    $b[] = 'declare(strict_types=1);';
    $b[] = '';
    $b[] = 'namespace App\\Models;';
    $b[] = '';
    $b[] = 'use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;';
    $b[] = 'use Illuminate\\Database\\Eloquent\\Model;';
    $b[] = '';
    $b[] = '/**';
    $b[] = ' * Model untuk tabel `' . $tabel . '` (' . count($kolom) . ' kolom).';
    $b[] = ' *';
    $b[] = ' * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.';
    $b[] = ' * Bila menambah relasi atau accessor, tulis di bagian sendiri agar';
    $b[] = ' * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).';
    $b[] = ' */';
    $b[] = 'class ' . $model . ' extends Model';
    $b[] = '{';
    $b[] = '    use HasFactory;';
    $b[] = '';
    $b[] = '    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */';
    $b[] = "    protected \$table = '{$tabel}';";
    $b[] = '';

    /* Kunci utama non-bawaan */
    if ($pkUtama !== 'id') {
        $b[] = '    /** K tabel ini bukan `id` -- kunci utamanya `' . $pkUtama . '` (bukan auto-increment). */';
        $b[] = "    protected \$primaryKey = '{$pkUtama}';";
        $b[] = '';
        $b[] = '    /** `' . $pkUtama . '` bukan kolom auto-increment, sehingga Laravel tidak boleh mengasumsikannya. */';
        $b[] = '    public $incrementing = false;';
        $b[] = '';
    }

    /* Timestamp */
    if ($ts['timestamps'] === false) {
        $b[] = '    /** Tabel ini tidak punya kolom timestamp sama sekali. */';
        $b[] = '    public $timestamps = false;';
    } else {
        /* PERINGATAN: jangan sekali-kali memakai isset() di sini.
         * isset() mengembalikan false untuk nilai null, sehingga pernyataan
         * const UPDATED_AT = null tidak akan pernah ditulis dan Laravel akan
         * memakai default "updated_at" -- kolom yang tidak ada di banyak
         * tabel warisan. array_key_exists() membedakan kunci tidak ada
         * dari nilainya null. */
        if (array_key_exists('CREATED_AT', $ts) && $ts['CREATED_AT'] !== 'created_at') {
            $b[] = '    public $timestamps = true;';
            $b[] = '';
            if ($ts['CREATED_AT'] === null) {
                $b[] = '    /** Tabel ini hanya punya kolom pembaruan, tanpa kolom pembuat. */';
                $b[] = '    public const CREATED_AT = null;';
            } else {
                $b[] = '    /** Kolom pembuat di tabel ini bukan created_at. */';
                $b[] = "    public const CREATED_AT = '" . $ts['CREATED_AT'] . "';";
            }
        }

        if (array_key_exists('UPDATED_AT', $ts) && $ts['UPDATED_AT'] !== 'updated_at') {
            if (! str_contains(implode("\n", $b), 'public $timestamps = true;')) {
                $b[] = '    public $timestamps = true;';
                $b[] = '';
            }
            if ($ts['UPDATED_AT'] === null) {
                $b[] = '    /** Tabel ini hanya mencatat waktu pembuatan, tanpa pembaruan. */';
                $b[] = '    public const UPDATED_AT = null;';
            } else {
                $b[] = "    public const UPDATED_AT = '" . $ts['UPDATED_AT'] . "';";
            }
        }
    }
    $b[] = '';

    /* Kolom yang boleh diisi massal */
    $b[] = '    /**';
    $b[] = '     * Kolom yang boleh diisi lewat create()/update().';
    $b[] = '     *';
    $b[] = '     * Sengaja TIDAK memuat kunci utama maupun kolom timestamp:';
    $b[] = '     * keduanya dikelola Laravel sendiri, dan memasukkannya ke sini';
    $b[] = '     * membuka celah mass-assignment.';
    $b[] = '     */';
    $b[] = '    protected $fillable = [';
    foreach ($fillable as $f) {
        $b[] = "        '{$f}',";
    }
    $b[] = '    ];';
    $b[] = '';

    /* Cast */
    if ($casts !== []) {
        $b[] = '    /**';
        $b[] = '     * Pemetaan tipe database ke tipe PHP, supaya akses atribut';
        $b[] = '     * tidak perlu konversi manual di setiap halaman.';
        $b[] = '     */';
        $b[] = '    protected function casts(): array';
        $b[] = '    {';
        $b[] = '        return [';
        foreach ($casts as $nama => $tipe) {
            $b[] = "            '{$nama}' => '{$tipe}',";
        }
        $b[] = '        ];';
        $b[] = '    }';
        $b[] = '';
    }

    /* Relasi belongsTo dari kunci asing */
    $relasi = [];
    foreach ($info['kunci_asing'] as $fk) {
        $target = $fk['rujukan'];
        if (! isset($tableKeModel[$target])) {
            continue; /* rujukan ke tabel sistem, lewati */
        }
        $methode = snake($fk['kolom']);
        $methode = preg_replace('/_id$/', '', $methode);
        if ($methode === '' || $methode === null) {
            continue;
        }
        $relasi[] = [$methode, $tableKeModel[$target], $fk['kolom'], $fk['kolom_rujukan']];
    }

    if ($relasi !== []) {
        $b[] = '    /* ----------------------------------------------------------------';
        $b[] = '     * Relasi (kunci asing terdeteksi otomatis dari skema)';
        $b[] = '     * -------------------------------------------------------------- */';
        $b[] = '';
        foreach ($relasi as [$methode, $rujukan, $kolom, $kolomRujukan]) {
            $b[] = '    /** ' . $kolom . ' -> ' . snake($rujukan) . '.id */';
            $b[] = '    public function ' . $methode . '()';
            $b[] = '    {';
            $b[] = '        return $this->belongsTo(' . $rujukan . '::class, \'' . $kolom . '\');';
            $b[] = '    }';
            $b[] = '';
        }
        /* rapikan baris kosong ganda di akhir blok relasi */
        while (end($b) === '' && count($b) > 1 && $b[count($b) - 2] === '') {
            array_pop($b);
        }
    }

    /* PSR-12: tidak boleh ada baris kosong sebelum kurung tutup kelas. */
    while ($b !== [] && end($b) === '') {
        array_pop($b);
    }

    $b[] = '}';
    $b[] = '';

    $isi = implode("\n", $b);
    $path = $dirTujuan . '/' . $model . '.php';

    if ($modePeriksa) {
        echo "===== {$path} =====\n{$isi}\n";
    } else {
        file_put_contents($path, $isi);
        $dibuat[] = $model;
    }
}

if (! $modePeriksa) {
    printf("Model ditulis: %d -> %s\n", count($dibuat), $dirTujuan);
    foreach ($dibuat as $m) {
        echo "  - {$m}\n";
    }
    if ($lewat !== []) {
        echo "\nDilewati (tulisan tangan / ditandai PENANDA_TULIS_TANGAN):\n";
        foreach ($lewat as $l) {
            echo "  * {$l}\n";
        }
    }
}
