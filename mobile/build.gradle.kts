/*
 * Proyek akar APK Android "Sistem Pelaporan Kegiatan Harian -- PHR".
 *
 * Versi plugin dikunci agar build dapat direproduksi:
 *   - Android Gradle Plugin 8.11.1  (mendukung compileSdk 36)
 *   - Kotlin 2.1.21 + plugin Compose
 * Gradle 8.14.3 dipakai lewat wrapper (lihat gradle/wrapper).
 */
plugins {
    id("com.android.application") version "8.11.1" apply false
    id("org.jetbrains.kotlin.android") version "2.1.21" apply false
    id("org.jetbrains.kotlin.plugin.compose") version "2.1.21" apply false
}
