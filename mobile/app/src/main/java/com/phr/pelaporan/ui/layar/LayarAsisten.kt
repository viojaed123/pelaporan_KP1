package com.phr.pelaporan.ui.layar

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.PermintaanAsisten
import com.phr.pelaporan.ui.Biru
import com.phr.pelaporan.ui.BiruTua
import com.phr.pelaporan.ui.Merah
import com.phr.pelaporan.ui.komponen.Kartu
import kotlinx.coroutines.launch

private data class Gelembung(val dariSaya: Boolean, val teks: String)

/** Asisten AI: obrolan sederhana dengan asisten berbasis data internal. */
@Composable
fun LayarAsisten() {
    val korutin = rememberCoroutineScope()
    var pesan by remember { mutableStateOf("") }
    var percakapan by remember {
        mutableStateOf(
            listOf(
                Gelembung(
                    dariSaya = false,
                    teks = "Halo! Saya asisten PHR. Tanyakan ringkasan laporan, tugas, atau kasus Anda.",
                ),
            ),
        )
    }
    var galat by remember { mutableStateOf<String?>(null) }
    var sibuk by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxWidth().padding(12.dp)) {
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()),
        ) {
            percakapan.forEach { g ->
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 3.dp),
                    horizontalArrangement = if (g.dariSaya) Arrangement.End else Arrangement.Start,
                ) {
                    Text(
                        g.teks,
                        color = if (g.dariSaya) Color.White else Color(0xFF1A1C1E),
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier
                            .background(
                                if (g.dariSaya) Biru else Color.White,
                                RoundedCornerShape(12.dp),
                            )
                            .padding(10.dp),
                    )
                }
            }
            if (sibuk) CircularProgressIndicator(modifier = Modifier.padding(8.dp))
        }

        galat?.let { Text(it, color = Merah, modifier = Modifier.padding(4.dp)) }

        Kartu {
            OutlinedTextField(
                value = pesan,
                onValueChange = { pesan = it },
                label = { Text("Tulis pertanyaan…") },
                modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp),
            )
            Button(
                onClick = {
                    val tanya = pesan.trim()
                    if (tanya.isEmpty()) return@Button
                    percakapan = percakapan + Gelembung(true, tanya)
                    pesan = ""
                    sibuk = true; galat = null
                    korutin.launch {
                        try {
                            val j = Jaringan.api.tanyaAsisten(PermintaanAsisten(tanya))
                            percakapan = percakapan + Gelembung(false, j.jawaban)
                        } catch (e: Exception) {
                            galat = "Asisten tidak terjangkau."
                        } finally {
                            sibuk = false
                        }
                    }
                },
                enabled = !sibuk,
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Kirim") }
        }
    }
}
