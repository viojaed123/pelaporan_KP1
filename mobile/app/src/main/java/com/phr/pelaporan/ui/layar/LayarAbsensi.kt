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
import com.phr.pelaporan.data.Absensi
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.pesanGalat
import com.phr.pelaporan.ui.Hijau
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import com.phr.pelaporan.ui.komponen.KartuKosong
import com.phr.pelaporan.ui.komponen.Lencana
import com.phr.pelaporan.ui.komponen.TeksRendah
import com.phr.pelaporan.ui.komponen.warnaStatus
import kotlinx.coroutines.launch

/** Absensi: tombol check-in/check-out + riwayat kehadiran. */
@Composable
fun LayarAbsensi() {
    val korutin = rememberCoroutineScope()
    var riwayat by remember { mutableStateOf<List<Absensi>>(emptyList()) }
    var pesan by remember { mutableStateOf<String?>(null) }
    var sukses by remember { mutableStateOf(true) }
    var sibuk by remember { mutableStateOf(false) }
    var memuat by remember { mutableStateOf(true) }

    suspend fun muatRiwayat() {
        try {
            riwayat = Jaringan.api.riwayatAbsensi(perHalaman = 14).data.data
        } catch (e: Exception) {
            // biarkan daftar kosong bila gagal
        } finally {
            memuat = false
        }
    }

    LaunchedEffect(Unit) { muatRiwayat() }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        Kartu(judul = "Absensi Hari Ini") {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = {
                        sibuk = true; pesan = null
                        korutin.launch {
                            try {
                                val j = Jaringan.api.checkIn(emptyMap())
                                sukses = j.sukses
                                pesan = j.pesan
                                muatRiwayat()
                            } catch (e: Exception) {
                                sukses = false; pesan = pesanGalat(e)
                            } finally { sibuk = false }
                        }
                    },
                    enabled = !sibuk,
                    modifier = Modifier.weight(1f),
                ) { Text("Check-in") }
                OutlinedButton(
                    onClick = {
                        sibuk = true; pesan = null
                        korutin.launch {
                            try {
                                val j = Jaringan.api.checkOut(emptyMap())
                                sukses = j.sukses
                                pesan = j.pesan
                                muatRiwayat()
                            } catch (e: Exception) {
                                sukses = false; pesan = pesanGalat(e)
                            } finally { sibuk = false }
                        }
                    },
                    enabled = !sibuk,
                    modifier = Modifier.weight(1f),
                ) { Text("Check-out") }
            }
            if (sibuk) CircularProgressIndicator(modifier = Modifier.padding(top = 8.dp), strokeWidth = 2.dp)
            pesan?.let {
                Text(it, color = if (sukses) Hijau else Merah,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 8.dp))
            }
        }

        Kartu(judul = "Riwayat Absensi") {
            if (memuat) {
                CircularProgressIndicator(modifier = Modifier.padding(8.dp))
            } else if (riwayat.isEmpty()) {
                KartuKosong("Belum ada data absensi.")
            } else {
                riwayat.forEach { a ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
                        Column(Modifier.weight(1f)) {
                            Text(a.tanggal.take(10), fontWeight = FontWeight.Medium)
                            TeksRendah("Masuk ${a.jamMasuk?.take(5) ?: "—"} · Pulang ${a.jamPulang?.take(5) ?: "—"}")
                        }
                        Lencana(a.status, warnaStatus(a.status))
                    }
                }
            }
        }
    }
}
