package com.phr.pelaporan.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/*
 * Warna korporat PHR -- SATU sumber kebenaran, disamakan dengan web
 * (public/assets/css/app.css) dan config/keamanan.php.
 */
val BiruTua = Color(0xFF003D7D)
val Biru = Color(0xFF005EB8)
val Merah = Color(0xFFEB1C24)
val Hijau = Color(0xFF159447)
val Latar = Color(0xFFF5F5F5)
val AbuTeks = Color(0xFF5B6470)

private val Skema = lightColorScheme(
    primary = BiruTua,
    onPrimary = Color.White,
    primaryContainer = Biru,
    onPrimaryContainer = Color.White,
    secondary = Biru,
    onSecondary = Color.White,
    error = Merah,
    onError = Color.White,
    background = Latar,
    onBackground = Color(0xFF1A1C1E),
    surface = Color.White,
    onSurface = Color(0xFF1A1C1E),
)

/** Tema Compose PHR (warna korporat, tanpa mode gelap agar konsisten). */
@Composable
fun TemaPHR(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = Skema, content = content)
}
