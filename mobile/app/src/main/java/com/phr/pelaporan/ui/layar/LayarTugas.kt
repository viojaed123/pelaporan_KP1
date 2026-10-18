package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.PermintaanStatusTugas
import com.phr.pelaporan.data.Tugas
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import com.phr.pelaporan.ui.komponen.KartuKosong
import com.phr.pelaporan.ui.komponen.Lencana
import com.phr.pelaporan.ui.komponen.TeksRendah
import com.phr.pelaporan.ui.komponen.warnaStatus
import kotlinx.coroutines.launch

/** Daftar tugas dengan aksi ubah status cepat. */
@Composable
fun LayarTugas() {
    val korutin = rememberCoroutineScope()
    var tugas by remember { mutableStateOf<List<Tugas>>(emptyList()) }
    var galat by remember { mutableStateOf<String?>(null) }
    var memuat by remember { mutableStateOf(true) }

    suspend fun muat() {
        memuat = true
        try {
            tugas = Jaringan.api.daftarTugas(50).data.data
            galat = null
        } catch (e: Exception) {
            galat = "Gagal memuat tugas."
        } finally {
            memuat = false
        }
    }

    LaunchedEffect(Unit) { muat() }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        if (memuat) CircularProgressIndicator(modifier = Modifier.padding(16.dp))
        galat?.let { Text(it, color = Merah, modifier = Modifier.padding(8.dp)) }
        if (tugas.isEmpty() && !memuat) Kartu { KartuKosong("Belum ada tugas.") }

        tugas.forEach { t ->
            Kartu {
                Row(Modifier.fillMaxWidth()) {
                    Column(Modifier.weight(1f)) {
                        Text(t.judul, fontWeight = FontWeight.Bold)
                        t.deskripsi?.let { TeksRendah(it) }
                        TeksRendah("Tenggat: ${t.tenggat ?: "—"}")
                        t.pemberi?.let { TeksRendah("Dari: $it") }
                    }
                    Lencana(t.status, warnaStatus(t.status))
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                    if (t.status != "dikerjakan" && t.status != "selesai") {
                        TextButton(onClick = {
                            korutin.launch {
                                try {
                                    Jaringan.api.ubahStatusTugas(t.id, PermintaanStatusTugas("dikerjakan"))
                                    muat()
                                } catch (_: Exception) {}
                            }
                        }) { Text("Kerjakan") }
                    }
                    if (t.status != "selesai") {
                        TextButton(onClick = {
                            korutin.launch {
                                try {
                                    Jaringan.api.ubahStatusTugas(t.id, PermintaanStatusTugas("selesai"))
                                    muat()
                                } catch (_: Exception) {}
                            }
                        }) { Text("Selesai") }
                    }
                }
            }
        }
    }
}
