package com.phr.pelaporan.data

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first

/** Ekstensi DataStore tingkat aplikasi (satu berkas preferensi). */
private val Context.dataStore by preferencesDataStore(name = "phr_preferensi")

/**
 * Penyimpanan preferensi perangkat: token API, alamat server, dan
 * ringkasan pengguna. Token disimpan agar pengguna tidak perlu masuk
 * ulang setiap membuka aplikasi.
 */
object Pref {
    private val KUNCI_TOKEN = stringPreferencesKey("token_api")
    private val KUNCI_ALAMAT = stringPreferencesKey("alamat_server")
    private val KUNCI_NAMA = stringPreferencesKey("pengguna_nama")
    private val KUNCI_EMAIL = stringPreferencesKey("pengguna_email")
    private val KUNCI_ROLE = stringPreferencesKey("pengguna_role")

    suspend fun simpanSesi(context: Context, token: String, pengguna: PenggunaRingkas) {
        context.dataStore.edit { p ->
            p[KUNCI_TOKEN] = token
            p[KUNCI_NAMA] = pengguna.nama
            p[KUNCI_EMAIL] = pengguna.email
            p[KUNCI_ROLE] = pengguna.role
        }
    }

    suspend fun hapusSesi(context: Context) {
        context.dataStore.edit { p ->
            p.remove(KUNCI_TOKEN)
            p.remove(KUNCI_NAMA)
            p.remove(KUNCI_EMAIL)
            p.remove(KUNCI_ROLE)
        }
    }

    suspend fun simpanAlamat(context: Context, alamat: String) {
        context.dataStore.edit { it[KUNCI_ALAMAT] = alamat }
    }

    /** Muat ke memori (dipanggil sekali saat aplikasi mulai). */
    suspend fun muat(context: Context) {
        val p = context.dataStore.data.first()
        Sesi.token = p[KUNCI_TOKEN]
        Sesi.alamatServer = p[KUNCI_ALAMAT] ?: com.phr.pelaporan.BuildConfig.BASE_URL
        val nama = p[KUNCI_NAMA]
        val email = p[KUNCI_EMAIL]
        val role = p[KUNCI_ROLE]
        if (nama != null && email != null && role != null) {
            Sesi.pengguna = PenggunaRingkas(nama = nama, email = email, role = role)
        }
    }
}
