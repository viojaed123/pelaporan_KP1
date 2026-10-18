package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.layout.Column
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.PermintaanKasus
import com.phr.pelaporan.data.pesanGalat
import com.phr.pelaporan.ui.Hijau
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import kotlinx.coroutines.launch

/**
 * Formulir kasus baru. Penanggung jawab dan anggota tim DIKETIK BEBAS
 * (bukan pilihan dropdown); server mencocokkannya ke pengguna terdaftar
 * dan menyimpan sebagai teks bila tidak ada yang cocok.
 */
@Composable
fun LayarKasusForm(onSukses: () -> Unit) {
    val korutin = rememberCoroutineScope()
    var judul by remember { mutableStateOf("") }
    var deskripsi by remember { mutableStateOf("") }
    var kategori by remember { mutableStateOf("") }
    var urgensi by remember { mutableStateOf("Sedang") }
    var status by remember { mutableStateOf("Terbuka") }
    var progres by remember { mutableStateOf("0") }
    var lokasi by remember { mutableStateOf("") }
    var target by remember { mutableStateOf("") }
    var penanggungJawab by remember { mutableStateOf("") }
    var anggota by remember { mutableStateOf("") }
    var pesan by remember { mutableStateOf<String?>(null) }
    var sukses by remember { mutableStateOf(false) }
    var sibuk by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        Kartu(judul = "Buat Kasus") {
            OutlinedTextField(value = judul, onValueChange = { judul = it },
                label = { Text("Judul kasus *") },
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = deskripsi, onValueChange = { deskripsi = it },
                label = { Text("Deskripsi") }, minLines = 2,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = kategori, onValueChange = { kategori = it },
                label = { Text("Kategori") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = urgensi, onValueChange = { urgensi = it },
                label = { Text("Tingkat urgensi (Rendah/Sedang/Tinggi/Kritis)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = status, onValueChange = { status = it },
                label = { Text("Status (Terbuka/Dalam Proses/Selesai)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = progres, onValueChange = { progres = it },
                label = { Text("Progres (%)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = lokasi, onValueChange = { lokasi = it },
                label = { Text("Lokasi") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = target, onValueChange = { target = it },
                label = { Text("Target selesai (YYYY-MM-DD)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = penanggungJawab, onValueChange = { penanggungJawab = it },
                label = { Text("Penanggung jawab (ketik nama)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 4.dp))
            Text("Nama akan dicocokkan otomatis dengan pengguna terdaftar.",
                style = MaterialTheme.typography.bodySmall,
                color = com.phr.pelaporan.ui.AbuTeks,
                modifier = Modifier.padding(bottom = 8.dp))
            OutlinedTextField(value = anggota, onValueChange = { anggota = it },
                label = { Text("Anggota tim (pisahkan dengan koma)") }, minLines = 2,
                modifier = Modifier.fillMaxWidth())

            pesan?.let {
                Text(it, color = if (sukses) Hijau else Merah,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 8.dp))
            }

            Button(
                onClick = {
                    if (judul.isBlank()) {
                        pesan = "Judul wajib diisi."; sukses = false; return@Button
                    }
                    sibuk = true; pesan = null
                    korutin.launch {
                        try {
                            val jawab = Jaringan.api.buatKasus(
                                PermintaanKasus(
                                    judul = judul.trim(),
                                    deskripsi = deskripsi.trim().ifBlank { null },
                                    kategori = kategori.trim().ifBlank { null },
                                    tingkatUrgensi = urgensi.trim(),
                                    statusKasus = status.trim(),
                                    progres = progres.trim().toIntOrNull() ?: 0,
                                    targetSelesai = target.trim().ifBlank { null },
                                    namaLokasi = lokasi.trim().ifBlank { null },
                                    penanggungJawab = penanggungJawab.trim().ifBlank { null },
                                    anggota = anggota.trim().ifBlank { null },
                                ),
                            )
                            if (jawab.sukses) {
                                sukses = true
                                pesan = jawab.pesan ?: "Kasus dibuat."
                                onSukses()
                            } else {
                                sukses = false
                                pesan = jawab.pesan ?: "Gagal membuat kasus."
                            }
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
                Text("Simpan Kasus")
            }
        }
    }
}
