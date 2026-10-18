package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.PermintaanGantiSandi
import com.phr.pelaporan.data.Pref
import com.phr.pelaporan.data.Sesi
import com.phr.pelaporan.data.pesanGalat
import com.phr.pelaporan.ui.Hijau
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Baris
import com.phr.pelaporan.ui.komponen.Kartu
import kotlinx.coroutines.launch

/** Profil pengguna: data akun, ganti kata sandi, dan keluar. */
@Composable
fun LayarProfil(onKeluar: () -> Unit) {
    val konteks = LocalContext.current
    val korutin = rememberCoroutineScope()

    var nama by remember { mutableStateOf(Sesi.pengguna?.nama ?: "") }
    var email by remember { mutableStateOf(Sesi.pengguna?.email ?: "") }
    var role by remember { mutableStateOf(Sesi.pengguna?.role ?: "") }
    var sandiLama by remember { mutableStateOf("") }
    var sandiBaru by remember { mutableStateOf("") }
    var konfirmasi by remember { mutableStateOf("") }
    var pesan by remember { mutableStateOf<String?>(null) }
    var sukses by remember { mutableStateOf(false) }
    var sibuk by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        try {
            val p = Jaringan.api.profil().pengguna
            if (p != null) {
                nama = p.nama; email = p.email; role = p.role
                Sesi.pengguna = p
            }
        } catch (_: Exception) {}
    }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        Kartu(judul = "Akun") {
            Baris("Nama", nama)
            Baris("Surel", email)
            Baris("Peran", role)
            Baris("Server", Sesi.alamatServer)
        }

        Kartu(judul = "Ganti Kata Sandi") {
            OutlinedTextField(value = sandiLama, onValueChange = { sandiLama = it },
                label = { Text("Kata sandi lama") }, singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = sandiBaru, onValueChange = { sandiBaru = it },
                label = { Text("Kata sandi baru") }, singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = konfirmasi, onValueChange = { konfirmasi = it },
                label = { Text("Ulangi kata sandi baru") }, singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth())

            pesan?.let {
                Text(it, color = if (sukses) Hijau else Merah,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 8.dp))
            }

            Button(
                onClick = {
                    sibuk = true; pesan = null
                    korutin.launch {
                        try {
                            val j = Jaringan.api.gantiSandi(
                                PermintaanGantiSandi(sandiLama, sandiBaru, konfirmasi),
                            )
                            sukses = j.sukses
                            pesan = j.pesan ?: if (j.sukses) "Kata sandi diperbarui." else "Gagal."
                            if (j.sukses) { sandiLama = ""; sandiBaru = ""; konfirmasi = "" }
                        } catch (e: Exception) {
                            sukses = false
                            pesan = pesanGalat(e)
                        } finally {
                            sibuk = false
                        }
                    }
                },
                enabled = !sibuk,
                modifier = Modifier.fillMaxWidth().padding(top = 12.dp),
            ) {
                if (sibuk) CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.padding(end = 8.dp))
                Text("Simpan Kata Sandi")
            }
        }

        OutlinedButton(
            onClick = {
                korutin.launch {
                    try { Jaringan.api.keluar() } catch (_: Exception) {}
                    Pref.hapusSesi(konteks)
                    Sesi.token = null
                    Sesi.pengguna = null
                    onKeluar()
                }
            },
            colors = ButtonDefaults.outlinedButtonColors(contentColor = Merah),
            modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
        ) { Text("Keluar") }
    }
}
