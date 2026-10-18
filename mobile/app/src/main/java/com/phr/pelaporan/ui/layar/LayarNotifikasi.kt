package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
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
import com.phr.pelaporan.data.Notifikasi
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import com.phr.pelaporan.ui.komponen.KartuKosong
import com.phr.pelaporan.ui.komponen.TeksRendah
import kotlinx.coroutines.launch

/** Daftar notifikasi pengguna (tandai dibaca satu atau semua). */
@Composable
fun LayarNotifikasi() {
    val korutin = rememberCoroutineScope()
    var data by remember { mutableStateOf<List<Notifikasi>>(emptyList()) }
    var belumDibaca by remember { mutableStateOf(0) }
    var galat by remember { mutableStateOf<String?>(null) }
    var memuat by remember { mutableStateOf(true) }

    suspend fun muat() {
        memuat = true
        try {
            val j = Jaringan.api.daftarNotifikasi(50)
            data = j.data.data
            belumDibaca = j.belumDibaca
            galat = null
        } catch (e: Exception) {
            galat = "Gagal memuat notifikasi."
        } finally {
            memuat = false
        }
    }

    LaunchedEffect(Unit) { muat() }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Belum dibaca: $belumDibaca", fontWeight = FontWeight.Bold)
            Button(onClick = {
                korutin.launch {
                    try { Jaringan.api.tandaiSemuaDibaca(); muat() } catch (_: Exception) {}
                }
            }) { Text("Tandai semua") }
        }

        if (memuat) CircularProgressIndicator(modifier = Modifier.padding(16.dp))
        galat?.let { Text(it, color = Merah, modifier = Modifier.padding(8.dp)) }
        if (data.isEmpty() && !memuat) Kartu { KartuKosong("Tidak ada notifikasi.") }

        data.forEach { n ->
            Kartu {
                Row(Modifier.fillMaxWidth()) {
                    Column(Modifier.weight(1f)) {
                        Text(n.pesan, fontWeight = if (n.sudahDibaca) FontWeight.Normal else FontWeight.Bold)
                        TeksRendah(n.pada ?: "")
                    }
                    if (!n.sudahDibaca) {
                        TextButton(onClick = {
                            korutin.launch {
                                try { Jaringan.api.tandaiDibaca(n.id); muat() } catch (_: Exception) {}
                            }
                        }) { Text("Tandai") }
                    }
                }
            }
        }
    }
}
