<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pesan Validasi (Bahasa Indonesia)
|--------------------------------------------------------------------------
|
| Berkas ini menerjemahkan SELURUH kunci pada validation.php bawaan
| framework, bukan hanya yang sering muncul. Bila ada kunci yang belum
| diterjemahkan, Laravel akan diam-diam memakai bahasa fallback (en) dan
| pesan campur bahasa akan muncul di antarmuka -- persis yang ingin
| dihindari pada aplikasi pelaporan berbahasa Indonesia.
|
| Kolom "attributes" di bagian bawah menukar nama kolom database (mis.
| "user_id") dengan sebutan yang terbaca manusia ("pengguna"). Tanpa ini,
| pesan validasi akan menyebut nama kolom mentah.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Aturan dasar
    |----------------------------------------------------------------------
    */

    'accepted' => 'Kolom :attribute harus diterima.',
    'accepted_if' => 'Kolom :attribute harus diterima ketika :other bernilai :value.',
    'active_url' => 'Kolom :attribute harus berupa URL yang valid.',
    'after' => 'Kolom :attribute harus berupa tanggal setelah :date.',
    'after_or_equal' => 'Kolom :attribute harus berupa tanggal setelah atau sama dengan :date.',
    'alpha' => 'Kolom :attribute hanya boleh berisi huruf.',
    'alpha_dash' => 'Kolom :attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => 'Kolom :attribute hanya boleh berisi huruf dan angka.',
    'any_of' => 'Kolom :attribute tidak valid.',
    'array' => 'Kolom :attribute harus berupa larik.',
    'ascii' => 'Kolom :attribute hanya boleh berisi karakter alfanumerik dan simbol satu byte.',
    'before' => 'Kolom :attribute harus berupa tanggal sebelum :date.',
    'before_or_equal' => 'Kolom :attribute harus berupa tanggal sebelum atau sama dengan :date.',

    'between' => [
        'array' => 'Kolom :attribute harus berisi antara :min dan :max butir.',
        'file' => 'Ukuran berkas :attribute harus antara :min dan :max kilobyte.',
        'numeric' => 'Kolom :attribute harus bernilai antara :min dan :max.',
        'string' => 'Kolom :attribute harus berisi antara :min dan :max karakter.',
    ],

    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'can' => 'Kolom :attribute berisi nilai yang tidak diizinkan.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'contains' => 'Kolom :attribute kehilangan nilai yang wajib ada.',
    'current_password' => 'Kata sandi tidak benar.',
    'date' => 'Kolom :attribute harus berupa tanggal yang valid.',
    'date_equals' => 'Kolom :attribute harus berupa tanggal yang sama dengan :date.',
    'date_format' => 'Kolom :attribute harus sesuai format :format.',
    'decimal' => 'Kolom :attribute harus memiliki :decimal angka di belakang koma.',
    'declined' => 'Kolom :attribute harus ditolak.',
    'declined_if' => 'Kolom :attribute harus ditolak ketika :other bernilai :value.',
    'different' => 'Kolom :attribute dan :other harus berbeda.',
    'digits' => 'Kolom :attribute harus terdiri dari :digits digit.',
    'digits_between' => 'Kolom :attribute harus terdiri dari :min sampai :max digit.',
    'dimensions' => 'Kolom :attribute memiliki dimensi gambar yang tidak valid.',
    'distinct' => 'Kolom :attribute memiliki nilai yang kembar.',
    'doesnt_contain' => 'Kolom :attribute tidak boleh mengandung salah satu dari: :values.',
    'doesnt_end_with' => 'Kolom :attribute tidak boleh diakhiri salah satu dari: :values.',
    'doesnt_start_with' => 'Kolom :attribute tidak boleh diawali salah satu dari: :values.',
    'email' => 'Kolom :attribute harus berupa alamat surel yang valid.',
    'encoding' => 'Kolom :attribute harus dikodekan dalam :encoding.',
    'ends_with' => 'Kolom :attribute harus diakhiri salah satu dari: :values.',
    'enum' => 'Nilai :attribute yang dipilih tidak valid.',
    'exists' => 'Nilai :attribute yang dipilih tidak valid.',
    'extensions' => 'Kolom :attribute harus memiliki salah satu ekstensi berikut: :values.',
    'file' => 'Kolom :attribute harus berupa berkas.',
    'filled' => 'Kolom :attribute harus diisi.',

    'gt' => [
        'array' => 'Kolom :attribute harus berisi lebih dari :value butir.',
        'file' => 'Ukuran berkas :attribute harus lebih besar dari :value kilobyte.',
        'numeric' => 'Kolom :attribute harus lebih besar dari :value.',
        'string' => 'Kolom :attribute harus lebih panjang dari :value karakter.',
    ],

    'gte' => [
        'array' => 'Kolom :attribute harus berisi minimal :value butir.',
        'file' => 'Ukuran berkas :attribute harus minimal :value kilobyte.',
        'numeric' => 'Kolom :attribute harus minimal :value.',
        'string' => 'Kolom :attribute harus minimal :value karakter.',
    ],

    'hex_color' => 'Kolom :attribute harus berupa warna heksadesimal yang valid.',
    'image' => 'Kolom :attribute harus berupa gambar.',
    'in' => 'Nilai :attribute yang dipilih tidak valid.',
    'in_array' => 'Kolom :attribute harus ada di :other.',
    'in_array_keys' => 'Kolom :attribute harus memuat minimal salah satu kunci berikut: :values.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'ip' => 'Kolom :attribute harus berupa alamat IP yang valid.',
    'ipv4' => 'Kolom :attribute harus berupa alamat IPv4 yang valid.',
    'ipv6' => 'Kolom :attribute harus berupa alamat IPv6 yang valid.',
    'json' => 'Kolom :attribute harus berupa string JSON yang valid.',
    'list' => 'Kolom :attribute harus berupa daftar.',
    'lowercase' => 'Kolom :attribute harus berupa huruf kecil seluruhnya.',

    'lt' => [
        'array' => 'Kolom :attribute harus berisi kurang dari :value butir.',
        'file' => 'Ukuran berkas :attribute harus lebih kecil dari :value kilobyte.',
        'numeric' => 'Kolom :attribute harus lebih kecil dari :value.',
        'string' => 'Kolom :attribute harus lebih pendek dari :value karakter.',
    ],

    'lte' => [
        'array' => 'Kolom :attribute tidak boleh berisi lebih dari :value butir.',
        'file' => 'Ukuran berkas :attribute harus maksimal :value kilobyte.',
        'numeric' => 'Kolom :attribute harus maksimal :value.',
        'string' => 'Kolom :attribute harus maksimal :value karakter.',
    ],

    'mac_address' => 'Kolom :attribute harus berupa alamat MAC yang valid.',

    'max' => [
        'array' => 'Kolom :attribute tidak boleh berisi lebih dari :max butir.',
        'file' => 'Ukuran berkas :attribute tidak boleh lebih dari :max kilobyte.',
        'numeric' => 'Kolom :attribute tidak boleh lebih besar dari :max.',
        'string' => 'Kolom :attribute tidak boleh lebih panjang dari :max karakter.',
    ],

    'max_digits' => 'Kolom :attribute tidak boleh memiliki lebih dari :max digit.',
    'mimes' => 'Kolom :attribute harus berupa berkas bertipe: :values.',
    'mimetypes' => 'Kolom :attribute harus berupa berkas bertipe: :values.',

    'min' => [
        'array' => 'Kolom :attribute harus berisi minimal :min butir.',
        'file' => 'Ukuran berkas :attribute harus minimal :min kilobyte.',
        'numeric' => 'Kolom :attribute harus minimal :min.',
        'string' => 'Kolom :attribute harus minimal :min karakter.',
    ],

    'min_digits' => 'Kolom :attribute harus memiliki minimal :min digit.',
    'missing' => 'Kolom :attribute seharusnya tidak ada.',
    'missing_if' => 'Kolom :attribute seharusnya tidak ada ketika :other bernilai :value.',
    'missing_unless' => 'Kolom :attribute seharusnya tidak ada kecuali :other bernilai :value.',
    'missing_with' => 'Kolom :attribute seharusnya tidak ada ketika :values tersedia.',
    'missing_with_all' => 'Kolom :attribute seharusnya tidak ada ketika :values tersedia.',
    'multiple_of' => 'Kolom :attribute harus kelipatan dari :value.',
    'not_in' => 'Nilai :attribute yang dipilih tidak valid.',
    'not_regex' => 'Format :attribute tidak valid.',
    'numeric' => 'Kolom :attribute harus berupa angka.',

    'password' => [
        'letters' => 'Kolom :attribute harus memuat minimal satu huruf.',
        'mixed' => 'Kolom :attribute harus memuat minimal satu huruf besar dan satu huruf kecil.',
        'numbers' => 'Kolom :attribute harus memuat minimal satu angka.',
        'symbols' => 'Kolom :attribute harus memuat minimal satu simbol.',
        'uncompromised' => ':attribute yang diberikan telah muncul dalam kebocoran data. Silakan pilih :attribute lain.',
    ],

    'present' => 'Kolom :attribute harus ada.',
    'present_if' => 'Kolom :attribute harus ada ketika :other bernilai :value.',
    'present_unless' => 'Kolom :attribute harus ada kecuali :other bernilai :value.',
    'present_with' => 'Kolom :attribute harus ada ketika :values tersedia.',
    'present_with_all' => 'Kolom :attribute harus ada ketika :values tersedia.',
    'prohibited' => 'Kolom :attribute tidak boleh diisi.',
    'prohibited_if' => 'Kolom :attribute tidak boleh diisi ketika :other bernilai :value.',
    'prohibited_if_accepted' => 'Kolom :attribute tidak boleh diisi ketika :other diterima.',
    'prohibited_if_declined' => 'Kolom :attribute tidak boleh diisi ketika :other ditolak.',
    'prohibited_unless' => 'Kolom :attribute tidak boleh diisi kecuali :other bernilai salah satu dari :values.',
    'prohibits' => 'Kolom :attribute melarang :other untuk diisi.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => 'Kolom :attribute wajib diisi.',
    'required_array_keys' => 'Kolom :attribute harus memuat entri untuk: :values.',
    'required_if' => 'Kolom :attribute wajib diisi ketika :other bernilai :value.',
    'required_if_accepted' => 'Kolom :attribute wajib diisi ketika :other diterima.',
    'required_if_declined' => 'Kolom :attribute wajib diisi ketika :other ditolak.',
    'required_unless' => 'Kolom :attribute wajib diisi kecuali :other bernilai salah satu dari :values.',
    'required_with' => 'Kolom :attribute wajib diisi ketika :values tersedia.',
    'required_with_all' => 'Kolom :attribute wajib diisi ketika :values tersedia.',
    'required_without' => 'Kolom :attribute wajib diisi ketika :values tidak tersedia.',
    'required_without_all' => 'Kolom :attribute wajib diisi ketika tidak ada satu pun dari :values yang tersedia.',
    'same' => 'Kolom :attribute harus sama dengan :other.',

    'size' => [
        'array' => 'Kolom :attribute harus berisi :size butir.',
        'file' => 'Ukuran berkas :attribute harus :size kilobyte.',
        'numeric' => 'Kolom :attribute harus bernilai :size.',
        'string' => 'Kolom :attribute harus berisi :size karakter.',
    ],

    'starts_with' => 'Kolom :attribute harus diawali salah satu dari: :values.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'timezone' => 'Kolom :attribute harus berupa zona waktu yang valid.',
    'unique' => ':attribute sudah dipakai.',
    'uploaded' => 'Berkas :attribute gagal diunggah.',
    'uppercase' => 'Kolom :attribute harus berupa huruf besar seluruhnya.',
    'url' => 'Kolom :attribute harus berupa URL yang valid.',
    'ulid' => 'Kolom :attribute harus berupa ULID yang valid.',
    'uuid' => 'Kolom :attribute harus berupa UUID yang valid.',

    /*
    |----------------------------------------------------------------------
    | Pesan khusus per-aturan (custom)
    |----------------------------------------------------------------------
    |
    | Dipakai bila satu atribut membutuhkan pesan tersendiri, dengan pola
    | "nama_kolom.nama_aturan". Saat ini masih kosong -- isi saat sebuah
    | halaman membutuhkan pesan yang lebih spesifik daripada pesan umum.
    |
    */

    'custom' => [],

    /*
    |----------------------------------------------------------------------
    | Nama atribut yang terbaca manusia
    |----------------------------------------------------------------------
    |
    | Tanpa daftar ini, pesan validasi akan menyebut nama kolom mentah
    | (mis. "wilkayah_id wajib diisi"). Dengan daftar ini, pesan menjadi
    | "Wilayah wajib diisi".
    |
    | Hanya kolom yang benar-benar muncul di formulir yang perlu dicantumkan
    | di sini agar tetap singkat dan mudah dipelihara.
    |
    */

    'attributes' => [

        /* --- Identitas pengguna --- */
        'nama' => 'Nama',
        'email' => 'Surel',
        'password' => 'Kata sandi',
        'password_confirmation' => 'Konfirmasi kata sandi',
        'kata_sandi' => 'Kata sandi',
        'role' => 'Peran',
        'status' => 'Status',
        'divisi' => 'Divisi',
        'telepon' => 'Nomor telepon',
        'foto_profil' => 'Foto profil',
        'remember_token' => 'Token ingat saya',

        /* --- Wilayah --- */
        'wilayah_id' => 'Wilayah',
        'level' => 'Tingkat wilayah',
        'kabupaten' => 'Kabupaten/Kota',
        'kecamatan' => 'Kecamatan',
        'garis_lintang' => 'Garis lintang',
        'garis_bujur' => 'Garis bujur',

        /* --- Laporan kegiatan --- */
        'tanggal' => 'Tanggal',
        'laporan_id' => 'Laporan',
        'judul' => 'Judul',
        'isi' => 'Isi laporan',
        'kegiatan' => 'Kegiatan',
        'lokasi' => 'Lokasi',
        'waktu_mulai' => 'Waktu mulai',
        'waktu_selesai' => 'Waktu selesai',
        'gambar' => 'Gambar',
        'bukti' => 'Bukti',

        /* --- Kasus --- */
        'kasus_id' => 'Kasus',
        'penanggung_jawab_id' => 'Penanggung jawab',
        'urgensi' => 'Urgensi',
        'kategori' => 'Kategori',

        /* --- Jadwal & absensi --- */
        'jadwal_id' => 'Jadwal',
        'user_id' => 'Pengguna',
        'kehadiran' => 'Kehadiran',
        'keterangan' => 'Keterangan',

        /* --- Notifikasi & pengingat --- */
        'judul_notifikasi' => 'Judul notifikasi',
        'pesan' => 'Pesan',
        'link' => 'Tautan',

        /* --- Kolom umum lintas tabel --- */
        'id' => 'Nomor induk',
        'dibuat_pada' => 'Waktu dibuat',
        'diperbarui_pada' => 'Waktu diperbarui',
        'created_at' => 'Waktu dibuat',
        'updated_at' => 'Waktu diperbarui',
    ],

];
