package com.phr.pelaporan.ui.komponen

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.phr.pelaporan.ui.AbuTeks
import com.phr.pelaporan.ui.BiruTua

/** Kartu putih dengan judul opsional (padanan .kartu pada web). */
@Composable
fun Kartu(
    judul: String? = null,
    modifier: Modifier = Modifier,
    isi: @Composable ColumnScope.() -> Unit,
) {
    Card(
        modifier = modifier.fillMaxWidth().padding(vertical = 6.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.padding(14.dp)) {
            if (judul != null) {
                Text(
                    judul,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    color = BiruTua,
                    modifier = Modifier.padding(bottom = 8.dp),
                )
            }
            isi()
        }
    }
}

/** Kartu statistik beranda (angka besar + label). */
@Composable
fun KartuStat(angka: String, label: String, warna: Color, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.padding(4.dp),
        colors = CardDefaults.cardColors(containerColor = warna),
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(angka, style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold, color = Color.White)
            Text(label, style = MaterialTheme.typography.bodySmall, color = Color.White)
        }
    }
}

/** Lencana status berwarna (padanan .badge pada web). */
@Composable
fun Lencana(teks: String, warna: Color) {
    Box(
        modifier = Modifier
            .background(warna, RoundedCornerShape(50))
            .padding(horizontal = 10.dp, vertical = 3.dp),
    ) {
        Text(teks, color = Color.White, style = MaterialTheme.typography.labelSmall)
    }
}

/** Pesan ketika daftar kosong. */
@Composable
fun KartuKosong(teks: String) {
    Text(teks, color = AbuTeks, modifier = Modifier.padding(8.dp))
}

/** Baris label–nilai sederhana. */
@Composable
fun Baris(label: String, nilai: String) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 2.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(label, color = AbuTeks, style = MaterialTheme.typography.bodyMedium)
        Text(nilai, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
    }
}

/** Pilih warna lencana dari status teks. */
fun warnaStatus(status: String): Color {
    return when (status.lowercase()) {
        "selesai", "diterima", "hadir", "hijau" -> com.phr.pelaporan.ui.Hijau
        "dikerjakan", "direvisi", "diperiksa", "sedang", "izin", "menunggu" -> Color(0xFFE8A400)
        "ditolak", "terlambat", "kritis", "tinggi", "sakit", "merah" -> com.phr.pelaporan.ui.Merah
        else -> BiruTua
    }
}

/** Pilih warna lencana urgensi kasus. */
fun warnaUrgensi(urgensi: String): Color = when (urgensi.lowercase()) {
    "kritis", "tinggi" -> com.phr.pelaporan.ui.Merah
    "sedang" -> Color(0xFFE8A400)
    else -> BiruTua
}

@Composable
fun JudulBagian(teks: String) {
    Text(
        teks,
        style = MaterialTheme.typography.titleLarge,
        fontWeight = FontWeight.Bold,
        color = BiruTua,
        modifier = Modifier.padding(bottom = 8.dp),
    )
}

@Composable
fun TeksRendah(teks: String) {
    Text(teks, color = AbuTeks, style = MaterialTheme.typography.bodySmall)
}

/** Penyelaras vertical center helper untuk Box. */
val PusatTengah: Alignment = Alignment.Center
