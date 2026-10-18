package com.phr.pelaporan.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ExitToApp
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Done
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Send
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ModalDrawerSheet
import androidx.compose.material3.ModalNavigationDrawer
import androidx.compose.material3.NavigationDrawerItem
import androidx.compose.material3.NavigationDrawerItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.rememberDrawerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.phr.pelaporan.data.Jaringan
import com.phr.pelaporan.data.Pref
import com.phr.pelaporan.data.Sesi
import com.phr.pelaporan.ui.layar.LayarAbsensi
import com.phr.pelaporan.ui.layar.LayarAsisten
import com.phr.pelaporan.ui.layar.LayarBuatLaporan
import com.phr.pelaporan.ui.layar.LayarDasbor
import com.phr.pelaporan.ui.layar.LayarKasus
import com.phr.pelaporan.ui.layar.LayarKasusForm
import com.phr.pelaporan.ui.layar.LayarLaporan
import com.phr.pelaporan.ui.layar.LayarMasuk
import com.phr.pelaporan.ui.layar.LayarNotifikasi
import com.phr.pelaporan.ui.layar.LayarProfil
import com.phr.pelaporan.ui.layar.LayarTugas
import kotlinx.coroutines.launch

/** Satu butir menu samping (padanan sidebar pada web). */
private enum class Tujuan(val rute: String, val label: String, val ikon: ImageVector) {
    DASBOR("dasbor", "Dasbor", Icons.Filled.Home),
    BUAT("buatLaporan", "Buat Laporan", Icons.Filled.Add),
    RIWAYAT("laporan", "Riwayat Laporan", Icons.AutoMirrored.Filled.List),
    ABSENSI("absensi", "Absensi", Icons.Filled.CheckCircle),
    TUGAS("tugas", "Tugas", Icons.Filled.Done),
    KASUS("kasus", "Kasus", Icons.Filled.Warning),
    ASISTEN("asisten", "Asisten AI", Icons.Filled.Send),
    NOTIFIKASI("notifikasi", "Notifikasi", Icons.Filled.Notifications),
    PROFIL("profil", "Profil", Icons.Filled.Person),
}

/** Kerangka aplikasi: laci menu + grafik navigasi seluruh layar. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NavApp() {
    val nav = rememberNavController()
    val korutin = rememberCoroutineScope()
    val konteks = LocalContext.current
    val laci = rememberDrawerState(DrawerValue.Closed)

    val tumpukan by nav.currentBackStackEntryAsState()
    val ruteSekarang = tumpukan?.destination?.route
    val diMasuk = ruteSekarang == "masuk"
    val judul = Tujuan.entries.firstOrNull { it.rute == ruteSekarang }?.label
        ?: if (ruteSekarang == "kasusBaru") "Buat Kasus" else "PHR"

    ModalNavigationDrawer(
        drawerState = laci,
        drawerContent = {
            ModalDrawerSheet {
                Column(Modifier.padding(16.dp)) {
                    Text("PHR", style = androidx.compose.material3.MaterialTheme.typography.titleLarge,
                        fontWeight = FontWeight.Bold, color = BiruTua)
                    Text(
                        Sesi.pengguna?.nama ?: "Belum masuk",
                        style = androidx.compose.material3.MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        Sesi.pengguna?.role?.replaceFirstChar { it.uppercase() } ?: "",
                        style = androidx.compose.material3.MaterialTheme.typography.bodySmall,
                        color = AbuTeks,
                    )
                }
                HorizontalDivider()
                Tujuan.entries.forEach { t ->
                    NavigationDrawerItem(
                        label = { Text(t.label) },
                        selected = ruteSekarang == t.rute,
                        icon = { Icon(t.ikon, contentDescription = t.label) },
                        onClick = {
                            korutin.launch { laci.close() }
                            nav.navigate(t.rute) { launchSingleTop = true }
                        },
                        modifier = Modifier.padding(NavigationDrawerItemDefaults.ItemPadding),
                    )
                }
                HorizontalDivider()
                NavigationDrawerItem(
                    label = { Text("Keluar") },
                    selected = false,
                    icon = { Icon(Icons.AutoMirrored.Filled.ExitToApp, contentDescription = "Keluar") },
                    onClick = {
                        korutin.launch {
                            try { Jaringan.api.keluar() } catch (_: Exception) {}
                            Pref.hapusSesi(konteks)
                            Sesi.token = null
                            Sesi.pengguna = null
                            laci.close()
                            nav.navigate("masuk") {
                                popUpTo(nav.graph.startDestinationId) { inclusive = true }
                            }
                        }
                    },
                    modifier = Modifier.padding(NavigationDrawerItemDefaults.ItemPadding),
                )
            }
        },
    ) {
        Scaffold(
            topBar = {
                if (!diMasuk) {
                    TopAppBar(
                        title = { Text(judul) },
                        navigationIcon = {
                            IconButton(onClick = { korutin.launch { laci.open() } }) {
                                Icon(Icons.Filled.Menu, contentDescription = "Buka menu")
                            }
                        },
                        colors = TopAppBarDefaults.topAppBarColors(
                            containerColor = BiruTua,
                            titleContentColor = Color.White,
                            navigationIconContentColor = Color.White,
                        ),
                    )
                }
            },
        ) { padding ->
            Box(Modifier.fillMaxSize().padding(padding)) {
                NavHost(
                    navController = nav,
                    startDestination = if (Sesi.sudahMasuk) "dasbor" else "masuk",
                ) {
                    composable("masuk") {
                        LayarMasuk(onMasuk = {
                            nav.navigate("dasbor") { popUpTo("masuk") { inclusive = true } }
                        })
                    }
                    composable("dasbor") { LayarDasbor() }
                    composable("laporan") { LayarLaporan() }
                    composable("buatLaporan") {
                        LayarBuatLaporan(onSukses = {
                            nav.navigate("laporan") { launchSingleTop = true }
                        })
                    }
                    composable("absensi") { LayarAbsensi() }
                    composable("tugas") { LayarTugas() }
                    composable("kasus") {
                        LayarKasus(onBuat = { nav.navigate("kasusBaru") { launchSingleTop = true } })
                    }
                    composable("kasusBaru") {
                        LayarKasusForm(onSukses = { nav.popBackStack() })
                    }
                    composable("asisten") { LayarAsisten() }
                    composable("notifikasi") { LayarNotifikasi() }
                    composable("profil") {
                        LayarProfil(onKeluar = {
                            nav.navigate("masuk") {
                                popUpTo(nav.graph.startDestinationId) { inclusive = true }
                            }
                        })
                    }
                }
            }
        }
    }
}
