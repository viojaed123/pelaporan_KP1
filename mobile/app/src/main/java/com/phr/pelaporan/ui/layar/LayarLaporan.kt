package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
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
import com.phr.pelaporan.data.Laporan
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import com.phr.pelaporan.ui.komponen.KartuKosong
import com.phr.pelaporan.ui.komponen.Lencana
import com.phr.pelaporan.ui.komponen.TeksRendah
import com.phr.pelaporan.ui.komponen.warnaStatus
import kotlinx.coroutines.launch

/** Riwayat laporan milik pengguna (mentor/admin: semua). */
@Composable
fun LayarLaporan() {
    var data by remember { mutableStateOf<List<Laporan>>(emptyList()) }
    var halaman by remember { mutableStateOf(1) }
    var totalHalaman by remember { mutableStateOf(1) }
    var galat by remember { mutableStateOf<String?>(null) }
    var memuat by remember { mutableStateOf(true) }
    val korutin = rememberCoroutineScope()

    suspend fun muat(h: Int) {
        memuat = true
        try {
            val jawab = Jaringan.api.riwayatLaporan(halaman = h, perHalaman = 10)
            data = jawab.data.data
            halaman = jawab.data.halamanSekarang
            totalHalaman = jawab.data.halamanTerakhir
            galat = null
        } catch (e: Exception) {
            galat = "Gagal memuat riwayat laporan."
        } finally {
            memuat = false
        }
    }

    LaunchedEffect(Unit) { muat(1) }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        if (memuat) {
            CircularProgressIndicator(modifier = Modifier.padding(16.dp))
        }
        galat?.let { Text(it, color = Merah, modifier = Modifier.padding(8.dp)) }

        if (data.isEmpty() && !memuat) {
            Kartu { KartuKosong("Belum ada laporan.") }
        }

        data.forEach { l ->
            Kartu {
                Row(Modifier.fillMaxWidth()) {
                    Column(Modifier.weight(1f)) {
                        Text(l.kegiatan, fontWeight = FontWeight.Bold)
                        TeksRendah("${l.tanggal.take(10)} · ${l.lokasi ?: "—"}")
                        TeksRendah("Jam ${l.jamMulai?.take(5) ?: "—"}–${l.jamSelesai?.take(5) ?: "—"}")
                        l.pengguna?.let { TeksRendah("Oleh: $it") }
                    }
                    Lencana(l.status, warnaStatus(l.status))
                }
            }
        }

        if (totalHalaman > 1) {
            Row(
                Modifier.fillMaxWidth().padding(top = 8.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                OutlinedButton(
                    onClick = { korutin.launch { muat(halaman - 1) } },
                    enabled = halaman > 1 && !memuat,
                ) { Text("← Sebelumnya") }
                Text("$halaman / $totalHalaman", Modifier.padding(top = 12.dp))
                OutlinedButton(
                    onClick = { korutin.launch { muat(halaman + 1) } },
                    enabled = halaman < totalHalaman && !memuat,
                ) { Text("Berikutnya →") }
            }
        }
    }
}
