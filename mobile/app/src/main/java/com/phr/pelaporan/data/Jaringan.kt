package com.phr.pelaporan.data

import com.google.gson.JsonParser
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query
import java.util.concurrent.TimeUnit

/**
 * Status sesi yang dipakai bersama seluruh layar.
 *
 * Nilai disimpan di memori agar dapat dibaca sinkron oleh penyela
 * (interceptor) OkHttp; salinan permanennya ada di Pref (DataStore).
 */
object Sesi {
    @Volatile var token: String? = null
    @Volatile var pengguna: PenggunaRingkas? = null
    @Volatile var alamatServer: String = "http://10.0.2.2/"

    val sudahMasuk: Boolean get() = !token.isNullOrBlank()
}

/**
 * Klien HTTP ke API Laravel (/api/v1).
 *
 * Token Bearer disisipkan otomatis; alamat server dapat diubah pengguna
 * sehingga Retrofit dibangun ulang saat alamat berubah (lihat [bangunUlang]).
 */
object Jaringan {
    @Volatile private var retrofit: Retrofit? = null

    val api: Api
        get() = retrofit?.create(Api::class.java) ?: bangunUlang().create(Api::class.java)

    fun bangunUlang(): Retrofit {
        val penyela = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        }

        val http = OkHttpClient.Builder()
            .connectTimeout(8, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .addInterceptor { rantai ->
                val pembangun = rantai.request().newBuilder()
                    .header("Accept", "application/json")
                Sesi.token?.let { pembangun.header("Authorization", "Bearer $it") }
                rantai.proceed(pembangun.build())
            }
            .addInterceptor(penyela)
            .build()

        val dasar = if (Sesi.alamatServer.endsWith("/")) Sesi.alamatServer else Sesi.alamatServer + "/"

        val r = Retrofit.Builder()
            .baseUrl(dasar)
            .client(http)
            .addConverterFactory(GsonConverterFactory.create())
            .build()

        retrofit = r
        return r
    }
}

/**
 * Terjemahkan galat panggilan API menjadi pesan yang ramah.
 *
 * Retrofit melempar HttpException untuk status non-2xx (401/403/422/429),
 * padahal badan respons tetap berisi `pesan` dari server. Fungsi ini
 * membacanya agar pengguna melihat pesan asli (mis. kredensial salah,
 * sudah check-in, atau validasi gagal), bukan pesan generik.
 */
fun pesanGalat(e: Throwable): String {
    if (e is retrofit2.HttpException) {
        val badan = try {
            e.response()?.errorBody()?.string()
        } catch (_: Exception) {
            null
        }
        if (!badan.isNullOrBlank()) {
            try {
                val obj = JsonParser.parseString(badan).asJsonObject
                obj.get("pesan")?.let { if (it.isJsonPrimitive) return it.asString }
                obj.get("message")?.let { if (it.isJsonPrimitive) return it.asString }
                obj.getAsJsonObject("errors")?.let { errs ->
                    val kunci = errs.keySet().firstOrNull()
                    if (kunci != null) {
                        return errs.getAsJsonArray(kunci).get(0).asString
                    }
                }
            } catch (_: Exception) {
                // jatuh ke pesan umum
            }
        }
        return "Permintaan gagal (kode ${e.code()})."
    }
    return "Tidak dapat menghubungi server. Periksa alamat server & jaringan."
}

/** Antarmuka endpoint API (satu tempat untuk semua panggilan). */
interface Api {
    /* --- Authentication --- */
    @POST("api/v1/auth/login")
    suspend fun masuk(@Body isi: PermintaanMasuk): JawabanMasuk

    @POST("api/v1/auth/verifikasi")
    suspend fun verifikasi(@Body isi: PermintaanVerifikasi): JawabanMasuk

    @GET("api/v1/auth/profil")
    suspend fun profil(): JawabanProfil

    @POST("api/v1/auth/logout")
    suspend fun keluar(): JawabanUmum

    @POST("api/v1/auth/ganti-password")
    suspend fun gantiSandi(@Body isi: PermintaanGantiSandi): JawabanUmum

    /* --- Dashboard --- */
    @GET("api/v1/ringkasan")
    suspend fun ringkasan(): JawabanRingkasan

    /* --- Laporan --- */
    @GET("api/v1/laporan/riwayat")
    suspend fun riwayatLaporan(
        @Query("halaman") halaman: Int = 1,
        @Query("per_halaman") perHalaman: Int = 20,
        @Query("bulan") bulan: String? = null,
    ): BungkusHalaman<Laporan>

    @POST("api/v1/laporan")
    suspend fun simpanLaporan(@Body isi: PermintaanLaporan): JawabanUmum

    /* --- Absensi --- */
    @GET("api/v1/absensi/riwayat")
    suspend fun riwayatAbsensi(
        @Query("halaman") halaman: Int = 1,
        @Query("per_halaman") perHalaman: Int = 20,
    ): BungkusHalaman<Absensi>

    @POST("api/v1/absensi/check-in")
    suspend fun checkIn(@Body isi: Map<String, String> = emptyMap()): JawabanAbsensi

    @POST("api/v1/absensi/check-out")
    suspend fun checkOut(@Body isi: Map<String, String> = emptyMap()): JawabanAbsensi

    /* --- Tugas --- */
    @GET("api/v1/tugas")
    suspend fun daftarTugas(@Query("per_halaman") perHalaman: Int = 50): BungkusHalaman<Tugas>

    @PATCH("api/v1/tugas/{id}/status")
    suspend fun ubahStatusTugas(@Path("id") id: Int, @Body isi: PermintaanStatusTugas): JawabanTugas

    /* --- Kasus --- */
    @GET("api/v1/kasus")
    suspend fun daftarKasus(@Query("per_halaman") perHalaman: Int = 50): BungkusHalaman<Kasus>

    @GET("api/v1/kasus/{id}")
    suspend fun detailKasus(@Path("id") id: Int): JawabanKasus

    @POST("api/v1/kasus")
    suspend fun buatKasus(@Body isi: PermintaanKasus): JawabanKasus

    @PUT("api/v1/kasus/{id}")
    suspend fun perbaruiKasus(@Path("id") id: Int, @Body isi: PermintaanKasus): JawabanKasus

    /* --- Notifikasi --- */
    @GET("api/v1/notifikasi")
    suspend fun daftarNotifikasi(@Query("per_halaman") perHalaman: Int = 50): JawabanNotifikasi

    @POST("api/v1/notifikasi/{id}/dibaca")
    suspend fun tandaiDibaca(@Path("id") id: Int): JawabanUmum

    @POST("api/v1/notifikasi/dibaca-semua")
    suspend fun tandaiSemuaDibaca(): JawabanUmum

    /* --- Asisten --- */
    @POST("api/v1/asisten/kirim")
    suspend fun tanyaAsisten(@Body isi: PermintaanAsisten): JawabanAsisten
}
