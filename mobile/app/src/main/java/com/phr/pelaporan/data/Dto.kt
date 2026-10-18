package com.phr.pelaporan.data

import com.google.gson.annotations.SerializedName

/*
 * Model data (DTO) untuk API /api/v1 (seluruh endpoint).
 *
 * Nama field JSON memakai gaya snake_case; anotasi @SerializedName
 * memetakannya ke properti Kotlin yang lebih mudah dibaca.
 */

data class Pengguna(
    val id: Int = 0,
    val nama: String = "",
    val email: String = "",
    val role: String = "karyawan",
    val status: String = "aktif",
)

data class PenggunaRingkas(
    val id: Int = 0,
    val nama: String = "",
    val email: String = "",
    val role: String = "karyawan",
    val status: String = "aktif",
)

/* ---------- Masuk / 2FA ---------- */

data class PermintaanMasuk(
    val email: String,
    val password: String,
)

data class JawabanMasuk(
    val sukses: Boolean = false,
    val token: String? = null,
    val pengguna: PenggunaRingkas? = null,
    @SerializedName("dua_faktor") val duaFaktor: Boolean = false,
    val ticket: String? = null,
    val kedaluwarsa: Int = 0,
    val pesan: String? = null,
)

data class PermintaanVerifikasi(
    val ticket: String,
    val kode: String,
)

data class JawabanProfil(
    val sukses: Boolean = false,
    val pengguna: PenggunaRingkas? = null,
)

data class PermintaanGantiSandi(
    @SerializedName("password_lama") val sandiLama: String,
    @SerializedName("password_baru") val sandiBaru: String,
    @SerializedName("password_baru_confirmation") val konfirmasi: String,
)

/* ---------- Ringkasan ---------- */

data class AbsensiHariIni(
    val ada: Boolean = false,
    @SerializedName("jam_masuk") val jamMasuk: String? = null,
    @SerializedName("jam_pulang") val jamPulang: String? = null,
    val status: String? = null,
)

data class RingkasanData(
    @SerializedName("laporan_bulan_ini") val laporanBulanIni: Int = 0,
    @SerializedName("absensi_hari_ini") val absensiHariIni: AbsensiHariIni = AbsensiHariIni(),
    @SerializedName("tugas_aktif") val tugasAktif: Int = 0,
    @SerializedName("tugas_terlambat") val tugasTerlambat: Int = 0,
)

data class JawabanRingkasan(
    val sukses: Boolean = false,
    val ringkasan: RingkasanData = RingkasanData(),
)

/* ---------- Halaman (paginator Laravel) ---------- */

data class Halaman<T>(
    val data: List<T> = emptyList(),
    val total: Int = 0,
    @SerializedName("current_page") val halamanSekarang: Int = 1,
    @SerializedName("last_page") val halamanTerakhir: Int = 1,
    @SerializedName("per_page") val perHalaman: Int = 20,
)

data class BungkusHalaman<T>(
    val sukses: Boolean = false,
    val data: Halaman<T> = Halaman(),
)

/* ---------- Laporan ---------- */

data class Laporan(
    val id: Int = 0,
    val tanggal: String = "",
    val kegiatan: String = "",
    @SerializedName("jam_mulai") val jamMulai: String? = null,
    @SerializedName("jam_selesai") val jamSelesai: String? = null,
    @SerializedName("lokasi_teks") val lokasi: String? = null,
    val deskripsi: String? = null,
    val hasil: String? = null,
    val kendala: String? = null,
    val status: String = "-",
    val pengguna: String? = null,
)

data class PermintaanLaporan(
    val tanggal: String,
    @SerializedName("jam_mulai") val jamMulai: String? = null,
    @SerializedName("jam_selesai") val jamSelesai: String? = null,
    val kegiatan: String,
    @SerializedName("lokasi_teks") val lokasi: String? = null,
    @SerializedName("garis_lintang") val garisLintang: String? = null,
    @SerializedName("garis_bujur") val garisBujur: String? = null,
    val deskripsi: String? = null,
    val hasil: String? = null,
    val kendala: String? = null,
    @SerializedName("rencana_berikutnya") val rencanaBerikutnya: String? = null,
)

/* ---------- Absensi ---------- */

data class Absensi(
    val id: Int = 0,
    val tanggal: String = "",
    @SerializedName("jam_masuk") val jamMasuk: String? = null,
    @SerializedName("jam_pulang") val jamPulang: String? = null,
    val status: String = "-",
    @SerializedName("lokasi_teks") val lokasi: String? = null,
)

data class JawabanAbsensi(
    val sukses: Boolean = false,
    val pesan: String? = null,
    val absensi: Absensi? = null,
)

/* ---------- Tugas ---------- */

data class Tugas(
    val id: Int = 0,
    val judul: String = "",
    val deskripsi: String? = null,
    val tenggat: String? = null,
    val status: String = "baru",
    val pemberi: String? = null,
    @SerializedName("diselesaikan_pada") val diselesaikanPada: String? = null,
)

data class PermintaanStatusTugas(val status: String)

data class JawabanTugas(
    val sukses: Boolean = false,
    val pesan: String? = null,
    val tugas: Tugas? = null,
)

/* ---------- Kasus ---------- */

data class AnggotaKasus(
    @SerializedName("user_id") val userId: Int? = null,
    val nama: String = "-",
    val peran: String = "-",
)

data class LogKasus(
    @SerializedName("status_lama") val statusLama: String? = null,
    @SerializedName("status_baru") val statusBaru: String? = null,
    val progres: Int? = null,
    val catatan: String? = null,
    val oleh: String? = null,
    val pada: String? = null,
)

data class Kasus(
    val id: Int = 0,
    val kode: String = "",
    val judul: String = "",
    val urgensi: String = "-",
    val status: String = "-",
    val progres: Int = 0,
    val lokasi: String? = null,
    val kategori: String? = null,
    val deskripsi: String? = null,
    @SerializedName("penanggung_jawab") val penanggungJawab: String? = null,
    @SerializedName("penanggung_jawab_terdaftar") val penanggungJawabTerdaftar: Boolean = false,
    @SerializedName("dibuat_oleh") val dibuatOleh: String? = null,
    @SerializedName("target_selesai") val targetSelesai: String? = null,
    val anggota: List<AnggotaKasus> = emptyList(),
    @SerializedName("log_status") val logStatus: List<LogKasus> = emptyList(),
)

data class JawabanKasus(
    val sukses: Boolean = false,
    val pesan: String? = null,
    val kasus: Kasus? = null,
)

data class PermintaanKasus(
    val judul: String,
    val deskripsi: String? = null,
    val kategori: String? = null,
    @SerializedName("tingkat_urgensi") val tingkatUrgensi: String,
    @SerializedName("status_kasus") val statusKasus: String,
    val progres: Int,
    @SerializedName("target_selesai") val targetSelesai: String? = null,
    @SerializedName("nama_lokasi") val namaLokasi: String? = null,
    @SerializedName("garis_lintang") val garisLintang: String? = null,
    @SerializedName("garis_bujur") val garisBujur: String? = null,
    @SerializedName("penanggung_jawab") val penanggungJawab: String? = null,
    val anggota: String? = null,
)

/* ---------- Notifikasi ---------- */

data class Notifikasi(
    val id: Int = 0,
    val pesan: String = "",
    val link: String? = null,
    @SerializedName("sudah_dibaca") val sudahDibaca: Boolean = false,
    val pada: String? = null,
)

data class JawabanNotifikasi(
    val sukses: Boolean = false,
    val data: Halaman<Notifikasi> = Halaman(),
    @SerializedName("belum_dibaca") val belumDibaca: Int = 0,
)

/* ---------- Asisten ---------- */

data class PermintaanAsisten(val pesan: String)

data class JawabanAsisten(
    val sukses: Boolean = false,
    val jawaban: String = "",
)

/* ---------- Umum ---------- */

data class JawabanUmum(
    val sukses: Boolean = false,
    val pesan: String? = null,
)
