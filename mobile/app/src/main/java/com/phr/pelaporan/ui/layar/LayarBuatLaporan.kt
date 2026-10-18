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
import com.phr.pelaporan.data.PermintaanLaporan
import com.phr.pelaporan.data.pesanGalat
import com.phr.pelaporan.ui.Hijau
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Formulir laporan kegiatan harian (padanan buat laporan pada web). */
@Composable
fun LayarBuatLaporan(onSukses: () -> Unit) {
    val korutin = rememberCoroutineScope()
    val hariIni = remember { SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date()) }

    var tanggal by remember { mutableStateOf(hariIni) }
    var jamMulai by remember { mutableStateOf("08:00") }
    var jamSelesai by remember { mutableStateOf("17:00") }
    var kegiatan by remember { mutableStateOf("") }
    var lokasi by remember { mutableStateOf("") }
    var deskripsi by remember { mutableStateOf("") }
    var hasil by remember { mutableStateOf("") }
    var kendala by remember { mutableStateOf("") }
    var rencana by remember { mutableStateOf("") }
    var pesan by remember { mutableStateOf<String?>(null) }
    var sukses by remember { mutableStateOf(false) }
    var sibuk by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        Kartu(judul = "Laporan Kegiatan") {
            OutlinedTextField(value = tanggal, onValueChange = { tanggal = it },
                label = { Text("Tanggal (YYYY-MM-DD)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = jamMulai, onValueChange = { jamMulai = it },
                label = { Text("Jam mulai (HH:MM)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = jamSelesai, onValueChange = { jamSelesai = it },
                label = { Text("Jam selesai (HH:MM)") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = kegiatan, onValueChange = { kegiatan = it },
                label = { Text("Kegiatan *") },
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = lokasi, onValueChange = { lokasi = it },
                label = { Text("Lokasi") }, singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = deskripsi, onValueChange = { deskripsi = it },
                label = { Text("Deskripsi") }, minLines = 2,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = hasil, onValueChange = { hasil = it },
                label = { Text("Hasil") }, minLines = 2,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = kendala, onValueChange = { kendala = it },
                label = { Text("Kendala") }, minLines = 2,
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            OutlinedTextField(value = rencana, onValueChange = { rencana = it },
                label = { Text("Rencana berikutnya") }, minLines = 2,
                modifier = Modifier.fillMaxWidth())

            pesan?.let {
                Text(it, color = if (sukses) Hijau else Merah,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 8.dp))
            }

            Button(
                onClick = {
                    if (kegiatan.isBlank()) {
                        pesan = "Kegiatan wajib diisi."
                        sukses = false
                        return@Button
                    }
                    sibuk = true
                    pesan = null
                    korutin.launch {
                        try {
                            val jawab = Jaringan.api.simpanLaporan(
                                PermintaanLaporan(
                                    tanggal = tanggal.trim(),
                                    jamMulai = jamMulai.trim().ifBlank { null },
                                    jamSelesai = jamSelesai.trim().ifBlank { null },
                                    kegiatan = kegiatan.trim(),
                                    lokasi = lokasi.trim().ifBlank { null },
                                    deskripsi = deskripsi.trim().ifBlank { null },
                                    hasil = hasil.trim().ifBlank { null },
                                    kendala = kendala.trim().ifBlank { null },
                                    rencanaBerikutnya = rencana.trim().ifBlank { null },
                                ),
                            )
                            if (jawab.sukses) {
                                sukses = true
                                pesan = jawab.pesan ?: "Laporan tersimpan."
                                kegiatan = ""; deskripsi = ""; hasil = ""; kendala = ""; rencana = ""
                                onSukses()
                            } else {
                                sukses = false
                                pesan = jawab.pesan ?: "Gagal menyimpan laporan."
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
                Text("Kirim Laporan")
            }
        }
    }
}
