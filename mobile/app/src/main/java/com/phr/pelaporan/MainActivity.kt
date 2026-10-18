package com.phr.pelaporan

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.Pref
import com.phr.pelaporan.ui.NavApp
import com.phr.pelaporan.ui.TemaPHR

/**
 * Satu Activity untuk seluruh aplikasi (Jetpack Compose).
 *
 * Saat mulai: muat sesi tersimpan dari preferensi ke memori, bangun klien
 * HTTP dengan alamat server tersimpan, lalu tampilkan navigasi.
 */
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            var siap by remember { mutableStateOf(false) }

            LaunchedEffect(Unit) {
                Pref.muat(applicationContext)
                Jaringan.bangunUlang()
                siap = true
            }

            TemaPHR {
                Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
                    if (siap) {
                        NavApp()
                    } else {
                        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator()
                        }
                    }
                }
            }
        }
    }
}
