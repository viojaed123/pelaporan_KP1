package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.PermintaanMasuk
import com.phr.pelaporan.data.PermintaanVerifikasi
import com.phr.pelaporan.data.Pref
import com.phr.pelaporan.data.Sesi
import com.phr.pelaporan.data.pesanGalat
import com.phr.pelaporan.ui.BiruTua
import com.phr.pelaporan.ui.komponen.Kartu
import com.phr.pelaporan.ui.komponen.TeksRendah
import kotlinx.coroutines.launch

/**
 * Layar masuk -- mendukung DUA LANGKAH:
 *   1. email + kata sandi (sama seperti web);
 *   2. bila akun mengaktifkan 2FA, kolom kode verifikasi (OTP) muncul.
 *
 * Alamat server dapat diubah (mis. emulator 10.0.2.2 vs IP LAN).
 */
@Composable
fun LayarMasuk(onMasuk: () -> Unit) {
    val konteks = LocalContext.current
    val korutin = rememberCoroutineScope()

    var email by remember { mutableStateOf("") }
    var sandi by remember { mutableStateOf("") }
    var alamat by remember { mutableStateOf(Sesi.alamatServer) }
    var ticket by remember { mutableStateOf<String?>(null) }
    var kode by remember { mutableStateOf("") }
    var pesan by remember { mutableStateOf<String?>(null) }
    var sibuk by remember { mutableStateOf(false) }

    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text("PHR", style = MaterialTheme.typography.headlineLarge,
            fontWeight = FontWeight.Bold, color = BiruTua)
        Text("Sistem Pelaporan Kegiatan Harian",
            style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(bottom = 20.dp))

        Kartu(judul = if (ticket == null) "Masuk" else "Verifikasi Dua Faktor") {
            if (ticket == null) {
                OutlinedTextField(
                    value = email, onValueChange = { email = it },
                    label = { Text("Alamat surel") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                    modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp),
                )
                OutlinedTextField(
                    value = sandi, onValueChange = { sandi = it },
                    label = { Text("Kata sandi") }, singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp),
                )
                OutlinedTextField(
                    value = alamat, onValueChange = { alamat = it },
                    label = { Text("Alamat server") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    modifier = Modifier.fillMaxWidth().padding(bottom = 4.dp),
                )
                TeksRendah("Contoh: http://10.0.2.2/ (emulator) atau http://192.168.56.1/ (LAN).")
            } else {
                TeksRendah("Kode dikirim ke surel Anda. Masukkan 6 angka.")
                OutlinedTextField(
                    value = kode, onValueChange = { kode = it },
                    label = { Text("Kode verifikasi") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
                )
            }

            pesan?.let {
                Text(it, color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 8.dp))
            }

            Button(
                onClick = {
                    pesan = null
                    sibuk = true
                    korutin.launch {
                        try {
                            if (ticket == null) {
                                Sesi.alamatServer = alamat.trim()
                                Pref.simpanAlamat(konteks, Sesi.alamatServer)
                                Jaringan.bangunUlang()
                                val jawab = Jaringan.api.masuk(PermintaanMasuk(email.trim(), sandi))
                                if (jawab.duaFaktor) {
                                    ticket = jawab.ticket
                                    pesan = jawab.pesan ?: "Masukkan kode verifikasi."
                                } else if (jawab.sukses && jawab.token != null && jawab.pengguna != null) {
                                    Sesi.token = jawab.token
                                    Sesi.pengguna = jawab.pengguna
                                    Pref.simpanSesi(konteks, jawab.token, jawab.pengguna)
                                    Jaringan.bangunUlang()
                                    onMasuk()
                                } else {
                                    pesan = jawab.pesan ?: "Gagal masuk."
                                }
                            } else {
                                val jawab = Jaringan.api.verifikasi(PermintaanVerifikasi(ticket!!, kode.trim()))
                                if (jawab.sukses && jawab.token != null && jawab.pengguna != null) {
                                    Sesi.token = jawab.token
                                    Sesi.pengguna = jawab.pengguna
                                    Pref.simpanSesi(konteks, jawab.token, jawab.pengguna)
                                    Jaringan.bangunUlang()
                                    onMasuk()
                                } else {
                                    pesan = jawab.pesan ?: "Kode tidak valid."
                                }
                            }
                        } catch (e: Exception) {
                            pesan = pesanGalat(e)
                        } finally {
                            sibuk = false
                        }
                    }
                },
                enabled = !sibuk,
                modifier = Modifier.fillMaxWidth().padding(top = 12.dp),
            ) {
                if (sibuk) {
                    CircularProgressIndicator(modifier = Modifier.padding(end = 8.dp), strokeWidth = 2.dp)
                }
                Text(if (ticket == null) "Masuk" else "Verifikasi")
            }

            if (ticket != null) {
                Button(
                    onClick = { ticket = null; kode = ""; pesan = null },
                    modifier = Modifier.fillMaxWidth().padding(top = 4.dp),
                ) { Text("Kembali") }
            }
        }
    }
}
