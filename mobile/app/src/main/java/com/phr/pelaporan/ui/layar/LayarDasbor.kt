package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.Sesi
import com.phr.pelaporan.data.Tugas
import com.phr.pelaporan.ui.Biru
import com.phr.pelaporan.ui.BiruTua
import com.phr.pelaporan.ui.Hijau
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import com.phr.pelaporan.ui.komponen.KartuKosong
import com.phr.pelaporan.ui.komponen.KartuStat
import com.phr.pelaporan.ui.komponen.Lencana
import com.phr.pelaporan.ui.komponen.JudulBagian
import com.phr.pelaporan.ui.komponen.warnaStatus

/** Dasbor: kartu ringkasan + daftar tugas terbaru. */
@Composable
fun LayarDasbor() {
    var ringkasan by remember { mutableStateOf<com.phr.pelaporan.data.RingkasanData?>(null) }
    var tugas by remember { mutableStateOf<List<Tugas>>(emptyList()) }
    var galat by remember { mutableStateOf<String?>(null) }
    var memuat by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        try {
            val r = Jaringan.api.ringkasan()
            ringkasan = r.ringkasan
            tugas = Jaringan.api.daftarTugas(5).data.data
        } catch (e: Exception) {
            galat = "Gagal memuat ringkasan. Tarik ulang dengan membuka menu lain."
        } finally {
            memuat = false
        }
    }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        Text(
            "Halo, ${Sesi.pengguna?.nama ?: "Pengguna"}!",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = BiruTua,
            modifier = Modifier.padding(bottom = 8.dp),
        )

        if (memuat) {
            CircularProgressIndicator(modifier = Modifier.padding(16.dp))
            return@Column
        }
        galat?.let { Text(it, color = Merah, modifier = Modifier.padding(bottom = 8.dp)) }

        val r = ringkasan
        if (r != null) {
            Row(Modifier.fillMaxWidth()) {
                KartuStat(r.laporanBulanIni.toString(), "Laporan bulan ini", BiruTua, Modifier.weight(1f))
                KartuStat(
                    if (r.absensiHariIni.ada) (r.absensiHariIni.jamMasuk?.take(5) ?: "—") else "Belum",
                    "Absensi hari ini",
                    if (r.absensiHariIni.ada) Hijau else com.phr.pelaporan.ui.AbuTeks,
                    Modifier.weight(1f),
                )
            }
            Row(Modifier.fillMaxWidth()) {
                KartuStat(r.tugasAktif.toString(), "Tugas aktif", Biru, Modifier.weight(1f))
                KartuStat(r.tugasTerlambat.toString(), "Tugas terlambat", Merah, Modifier.weight(1f))
            }
        }

        Kartu(judul = "Tugas Terbaru") {
            if (tugas.isEmpty()) {
                KartuKosong("Belum ada tugas.")
            } else {
                tugas.forEach { t ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
                        Column(Modifier.weight(1f)) {
                            Text(t.judul, fontWeight = FontWeight.Medium)
                            Text(
                                "Tenggat: ${t.tenggat ?: "—"}",
                                style = MaterialTheme.typography.bodySmall,
                                color = com.phr.pelaporan.ui.AbuTeks,
                            )
                        }
                        Lencana(t.status, warnaStatus(t.status))
                    }
                }
            }
        }

        JudulBagian("Selamat bertugas")
    }
}
