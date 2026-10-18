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
import com.phr.pelaporan.data.AnggotaKasus
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.Kasus
import com.phr.pelaporan.data.pesanGalat
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Baris
import com.phr.pelaporan.ui.komponen.Kartu
import com.phr.pelaporan.ui.komponen.KartuKosong
import com.phr.pelaporan.ui.komponen.Lencana
import com.phr.pelaporan.ui.komponen.TeksRendah
import com.phr.pelaporan.ui.komponen.warnaUrgensi
import kotlinx.coroutines.launch

/** Daftar kasus + panel detail. Menampilkan tombol tambah untuk mentor/admin. */
@Composable
fun LayarKasus(onBuat: () -> Unit) {
    val korutin = rememberCoroutineScope()
    var data by remember { mutableStateOf<List<Kasus>>(emptyList()) }
    var terpilih by remember { mutableStateOf<Kasus?>(null) }
    var galat by remember { mutableStateOf<String?>(null) }
    var memuat by remember { mutableStateOf(true) }

    val bolehBuat = remember { com.phr.pelaporan.data.Sesi.pengguna?.role in listOf("mentor", "admin") }

    suspend fun muat() {
        memuat = true
        try {
            data = Jaringan.api.daftarKasus(50).data.data
            galat = null
        } catch (e: Exception) {
            galat = "Gagal memuat kasus."
        } finally {
            memuat = false
        }
    }

    suspend fun detail(id: Int) {
        try {
            terpilih = Jaringan.api.detailKasus(id).kasus
        } catch (e: Exception) {
            galat = pesanGalat(e)
        }
    }

    LaunchedEffect(Unit) { muat() }

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(12.dp)) {
        if (bolehBuat) {
            Button(onClick = onBuat, modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp)) {
                Text("+ Buat Kasus Baru")
            }
        }

        if (memuat) CircularProgressIndicator(modifier = Modifier.padding(16.dp))
        galat?.let { Text(it, color = Merah, modifier = Modifier.padding(8.dp)) }
        if (data.isEmpty() && !memuat) Kartu { KartuKosong("Belum ada kasus.") }

        data.forEach { k ->
            Kartu {
                Row(Modifier.fillMaxWidth()) {
                    Column(Modifier.weight(1f)) {
                        Text(k.judul, fontWeight = FontWeight.Bold)
                        TeksRendah("${k.kode} · ${k.kategori ?: "—"} · ${k.lokasi ?: "—"}")
                        TeksRendah("Progres: ${k.progres}% · Status: ${k.status}")
                    }
                    Lencana(k.urgensi, warnaUrgensi(k.urgensi))
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                    TextButton(onClick = { korutin.launch { detail(k.id) } }) { Text("Detail") }
                }
            }
        }

        terpilih?.let { k ->
            Kartu(judul = "${k.kode} — ${k.judul}") {
                Baris("Status", k.status)
                Baris("Urgensi", k.urgensi)
                Baris("Progres", "${k.progres}%")
                Baris("Kategori", k.kategori ?: "—")
                Baris("Lokasi", k.lokasi ?: "—")
                Baris("Target selesai", k.targetSelesai ?: "—")
                Baris("Dibuat oleh", k.dibuatOleh ?: "—")
                Baris(
                    "Penanggung jawab",
                    (k.penanggungJawab ?: "—") + if (k.penanggungJawabTerdaftar) "" else " (teks)",
                )
                k.deskripsi?.let {
                    Text("Deskripsi", fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                    TeksRendah(it)
                }
                if (k.anggota.isNotEmpty()) {
                    Text("Anggota Tim", fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                    k.anggota.forEach { a: AnggotaKasus -> TeksRendah("• ${a.nama} (${a.peran})") }
                }
                if (k.logStatus.isNotEmpty()) {
                    Text("Riwayat Status", fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                    k.logStatus.forEach { l ->
                        TeksRendah("• ${l.statusLama ?: "-"} → ${l.statusBaru ?: "-"} (${l.progres ?: 0}%) oleh ${l.oleh ?: "-"}")
                        l.catatan?.let { TeksRendah("   $it") }
                    }
                }
                OutlinedButton(
                    onClick = { terpilih = null },
                    modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
                ) { Text("Tutup") }
            }
        }
    }
}
