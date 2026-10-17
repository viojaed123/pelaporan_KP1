"""Layanan domain murni untuk pelaporan.

Fungsi-fungsi di sini **tidak menyentuh basis data maupun jaringan** sehingga
mudah diuji. Router dan alat (tools) memakainya untuk validasi kelengkapan,
statistik, tren, dan penyusunan ringkasan deterministik.
"""

from __future__ import annotations

import re
from collections import Counter, OrderedDict
from datetime import date, datetime, timedelta
from typing import Any, Iterable, Sequence

# ---------------------------------------------------------------------------
# Konstanta domain (mirror includes/config.php & validators.php)
# ---------------------------------------------------------------------------
KEGIATAN_MAKS: int = 255
TEKS_MAKS: int = 60000

POLA_TANGGAL = re.compile(r"^\d{4}-\d{2}-\d{2}$")
POLA_JAM = re.compile(r"^(?:[01]\d|2[0-3]):[0-5]\d$")

STATUS_LAPORAN: tuple[str, ...] = ("draft", "dikirim", "diperiksa", "perlu_revisi")
STATUS_ABSENSI: tuple[str, ...] = ("hadir", "izin", "sakit", "alpa")

BULAN_ID: tuple[str, ...] = (
    "Januari", "Februari", "Maret", "April", "Mei", "Juni",
    "Juli", "Agustus", "September", "Oktober", "November", "Desember",
)
HARI_ID: tuple[str, ...] = (
    "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu",
)

#: Bobot kelengkapan (total 100).
BOBOT_KELENGKAPAN: dict[str, int] = {
    "tanggal": 15,
    "kegiatan": 20,
    "deskripsi": 25,
    "jam": 10,
    "hasil": 10,
    "kendala": 10,
    "rencana_berikutnya": 10,
}

#: Bidang yang wajib ada agar laporan dianggap lengkap.
WAJIB: tuple[str, ...] = ("tanggal", "kegiatan", "deskripsi")


# ---------------------------------------------------------------------------
# Utilitas tanggal & format
# ---------------------------------------------------------------------------
def ke_tanggal(nilai: Any) -> date | None:
    """Ubah berbagai representasi tanggal menjadi :class:`datetime.date`."""
    if nilai is None or nilai == "":
        return None
    if isinstance(nilai, datetime):
        return nilai.date()
    if isinstance(nilai, date):
        return nilai
    teks = str(nilai).strip()
    if not POLA_TANGGAL.match(teks):
        return None
    try:
        return datetime.strptime(teks, "%Y-%m-%d").date()
    except ValueError:
        return None


def ke_teks_tanggal(nilai: Any) -> str:
    """Kembalikan tanggal sebagai ``YYYY-MM-DD`` (string kosong bila tidak valid)."""
    d = ke_tanggal(nilai)
    return d.isoformat() if d else ""


def format_tanggal_id(nilai: Any, dengan_hari: bool = False) -> str:
    """Format tanggal ala Indonesia, mis. ``12 Oktober 2026``."""
    d = ke_tanggal(nilai)
    if d is None:
        return "-"
    teks = f"{d.day} {BULAN_ID[d.month - 1]} {d.year}"
    if dengan_hari:
        teks = f"{HARI_ID[d.weekday()]}, {teks}"
    return teks


def label_periode(dari: date, sampai: date, jenis: str) -> str:
    """Label periode manusiawi, mis. ``6–12 Oktober 2026`` atau ``Oktober 2026``."""
    if jenis == "bulanan" and dari.year == sampai.year and dari.month == sampai.month:
        return f"{BULAN_ID[dari.month - 1]} {dari.year}"
    if dari == sampai:
        return format_tanggal_id(dari)
    if dari.year == sampai.year and dari.month == sampai.month:
        return f"{dari.day}–{sampai.day} {BULAN_ID[sampai.month - 1]} {sampai.year}"
    return f"{format_tanggal_id(dari)} – {format_tanggal_id(sampai)}"


def rentang_minggu(acuan: date) -> tuple[date, date]:
    """Rentang Senin–Minggu yang memuat ``acuan``."""
    senin = acuan - timedelta(days=acuan.weekday())
    return senin, senin + timedelta(days=6)


def rentang_bulan(acuan: date) -> tuple[date, date]:
    """Rentang tanggal 1 sampai akhir bulan dari ``acuan``."""
    awal = acuan.replace(day=1)
    if awal.month == 12:
        berikut = awal.replace(year=awal.year + 1, month=1)
    else:
        berikut = awal.replace(month=awal.month + 1)
    return awal, berikut - timedelta(days=1)


def daftar_hari(dari: date, sampai: date) -> list[date]:
    """Semua hari dalam rentang (inklusif)."""
    if sampai < dari:
        return []
    return [dari + timedelta(days=i) for i in range((sampai - dari).days + 1)]


def hari_kerja(dari: date, sampai: date) -> list[date]:
    """Hari Senin–Jumat pada rentang (inklusif)."""
    return [d for d in daftar_hari(dari, sampai) if d.weekday() < 5]


# ---------------------------------------------------------------------------
# Validasi kelengkapan
# ---------------------------------------------------------------------------
def validasi_laporan(data: dict[str, Any]) -> dict[str, Any]:
    """Periksa kelengkapan satu laporan.

    Mengembalikan dict berisi ``bersih`` (nilai ternormalisasi), ``galat``
    (field -> pesan), ``peringatan``, ``skor`` (0–100), ``lengkap`` dan
    ``verdict``. Aturan meniru ``includes/validators.php``.
    """
    data = data or {}
    galat: dict[str, str] = {}
    peringatan: list[str] = []
    bersih: dict[str, Any] = {}

    # --- Tanggal -------------------------------------------------------
    tanggal = ke_tanggal(data.get("tanggal"))
    batas_depan = date.today() + timedelta(days=1)
    if tanggal is None:
        galat["tanggal"] = "Tanggal wajib diisi dengan format YYYY-MM-DD yang benar."
    elif tanggal > batas_depan:
        galat["tanggal"] = "Tanggal tidak boleh melewati hari ini."
        tanggal = None
    bersih["tanggal"] = tanggal.isoformat() if tanggal else None

    # --- Jam -----------------------------------------------------------
    jam_mulai = _val_jam(data.get("jam_mulai"))
    jam_selesai = _val_jam(data.get("jam_selesai"))
    mentah_mulai = str(data.get("jam_mulai") or "").strip()
    mentah_selesai = str(data.get("jam_selesai") or "").strip()

    if mentah_mulai and jam_mulai is None:
        galat["jam_mulai"] = "Jam mulai tidak valid (format HH:MM, 00:00–23:59)."
    if mentah_selesai and jam_selesai is None:
        galat["jam_selesai"] = "Jam selesai tidak valid (format HH:MM, 00:00–23:59)."
    if jam_mulai and jam_selesai and jam_selesai < jam_mulai:
        galat["jam_selesai"] = "Jam selesai tidak boleh lebih awal dari jam mulai."
    bersih["jam_mulai"] = jam_mulai
    bersih["jam_selesai"] = jam_selesai

    # --- Kegiatan ------------------------------------------------------
    kegiatan = str(data.get("kegiatan") or "").strip()
    if not kegiatan:
        galat["kegiatan"] = "Nama kegiatan wajib diisi."
    elif len(kegiatan) > KEGIATAN_MAKS:
        galat["kegiatan"] = f"Nama kegiatan maksimal {KEGIATAN_MAKS} karakter."
    elif len(kegiatan) < 5:
        peringatan.append("Nama kegiatan sangat pendek; sebaiknya lebih deskriptif.")
    bersih["kegiatan"] = kegiatan

    # --- Deskripsi -----------------------------------------------------
    deskripsi = str(data.get("deskripsi") or "").strip()
    if not deskripsi:
        galat["deskripsi"] = "Deskripsi kegiatan wajib diisi."
    elif len(deskripsi.encode("utf-8")) > TEKS_MAKS:
        galat["deskripsi"] = "Deskripsi terlalu panjang."
    elif len(deskripsi) < 20:
        peringatan.append("Deskripsi cukup singkat; tambahkan rincian pekerjaan.")
    bersih["deskripsi"] = deskripsi

    # --- Bidang opsional ----------------------------------------------
    for kunci in ("hasil", "kendala", "rencana_berikutnya"):
        nilai = data.get(kunci)
        if kunci == "rencana_berikutnya" and (nilai in (None, "")):
            nilai = data.get("rencana")
        teks = str(nilai or "").strip()
        if len(teks.encode("utf-8")) > TEKS_MAKS:
            galat[kunci] = "Kolom ini terlalu panjang."
        bersih[kunci] = teks

    skor = _skor(bersih)
    lengkap = not galat
    verdict = _verdict(lengkap, skor)

    return {
        "lengkap": lengkap,
        "verdict": verdict,
        "skor": skor,
        "bersih": bersih,
        "galat": galat,
        "peringatan": peringatan,
    }


def _val_jam(nilai: Any) -> str | None:
    teks = str(nilai or "").strip()
    if not teks:
        return None
    if POLA_JAM.match(teks):
        return teks
    if len(teks) == 5:
        return None
    # Terima "7:30" -> "07:30"
    m = re.match(r"^(\d{1,2}):(\d{2})$", teks)
    if m:
        hh, mm = int(m.group(1)), int(m.group(2))
        if 0 <= hh <= 23 and 0 <= mm <= 59:
            return f"{hh:02d}:{mm:02d}"
    return None


def _skor(bersih: dict[str, Any]) -> int:
    skor = 0
    if bersih.get("tanggal"):
        skor += BOBOT_KELENGKAPAN["tanggal"]
    if bersih.get("kegiatan"):
        skor += BOBOT_KELENGKAPAN["kegiatan"]
    if bersih.get("deskripsi"):
        skor += BOBOT_KELENGKAPAN["deskripsi"]
    if bersih.get("jam_mulai") and bersih.get("jam_selesai"):
        skor += BOBOT_KELENGKAPAN["jam"]
    for kunci in ("hasil", "kendala", "rencana_berikutnya"):
        if bersih.get(kunci):
            skor += BOBOT_KELENGKAPAN[kunci]
    return min(skor, 100)


def _verdict(lengkap: bool, skor: int) -> str:
    if not lengkap:
        return "PERLU PERBAIKAN"
    if skor >= 85:
        return "LENGKAP"
    if skor >= 60:
        return "CUKUP"
    return "PERLU PERBAIKAN"


def validasi_many(laporan: Sequence[dict[str, Any]]) -> dict[str, Any]:
    """Validasi banyak laporan sekaligus; kembalikan ringkasan agregat."""
    rincian = []
    jumlah_lengkap = 0
    total_skor = 0
    for idx, item in enumerate(laporan):
        hasil = validasi_laporan(item)
        if hasil["lengkap"]:
            jumlah_lengkap += 1
        total_skor += int(hasil["skor"])
        rincian.append(
            {
                "indeks": idx,
                "id": item.get("id"),
                "tanggal": ke_teks_tanggal(item.get("tanggal")),
                "kegiatan": str(item.get("kegiatan") or "")[:120],
                "lengkap": hasil["lengkap"],
                "verdict": hasil["verdict"],
                "skor": hasil["skor"],
                "galat": hasil["galat"],
                "peringatan": hasil["peringatan"],
            }
        )
    rata = round(total_skor / len(laporan)) if laporan else 0
    return {
        "jumlah": len(laporan),
        "jumlah_lengkap": jumlah_lengkap,
        "jumlah_perlu_perbaikan": len(laporan) - jumlah_lengkap,
        "skor_rata_rata": rata,
        "rincian": rincian,
    }


# ---------------------------------------------------------------------------
# Statistik & tren
# ---------------------------------------------------------------------------
def normalisasi_baris(rows: Iterable[dict[str, Any]]) -> list[dict[str, Any]]:
    """Rapikan baris ``laporan_harian``: tanggal jadi ``date``, teks jadi str."""
    hasil: list[dict[str, Any]] = []
    for row in rows:
        d = ke_tanggal(row.get("tanggal"))
        if d is None:
            continue
        bersih = dict(row)
        bersih["tanggal"] = d
        for kunci in ("kegiatan", "deskripsi", "hasil", "kendala", "rencana_berikutnya", "status"):
            if bersih.get(kunci) is not None:
                bersih[kunci] = str(bersih[kunci])
        hasil.append(bersih)
    hasil.sort(key=lambda r: r["tanggal"])
    return hasil


def statistik_kegiatan(
    rows: Sequence[dict[str, Any]], *, dari: date | None = None, sampai: date | None = None
) -> dict[str, Any]:
    """Hitung statistik kegiatan: per hari, per pekan, per bulan, sebaran status."""
    baris = normalisasi_baris(rows)
    if not baris:
        return {
            "jumlah_laporan": 0,
            "jumlah_hari": 0,
            "status": {},
            "per_hari": [],
            "per_pekan": {},
            "per_bulan": {},
            "rentang": {"dari": None, "sampai": None},
        }

    tanggal = [r["tanggal"] for r in baris]
    awal = dari or min(tanggal)
    akhir = sampai or max(tanggal)

    status = Counter(str(r.get("status") or "draft") for r in baris)

    per_hari_map: dict[date, list[dict[str, Any]]] = OrderedDict()
    for d in daftar_hari(awal, akhir):
        per_hari_map[d] = []
    for r in baris:
        per_hari_map.setdefault(r["tanggal"], []).append(r)

    per_hari = [
        {
            "tanggal": d.isoformat(),
            "label": format_tanggal_id(d),
            "hari": HARI_ID[d.weekday()],
            "jumlah": len(daftar),
            "kegiatan": [str(x.get("kegiatan") or "") for x in daftar],
        }
        for d, daftar in sorted(per_hari_map.items())
        if daftar
    ]

    per_pekan: dict[str, int] = OrderedDict()
    per_bulan: dict[str, int] = OrderedDict()
    for r in baris:
        d: date = r["tanggal"]
        kunci_pekan = f"{d.isocalendar().year}-W{int(d.isocalendar().week):02d}"
        kunci_bulan = f"{d.year}-{d.month:02d}"
        per_pekan[kunci_pekan] = per_pekan.get(kunci_pekan, 0) + 1
        per_bulan[kunci_bulan] = per_bulan.get(kunci_bulan, 0) + 1

    return {
        "jumlah_laporan": len(baris),
        "jumlah_hari": len({r["tanggal"] for r in baris}),
        "status": dict(status),
        "per_hari": per_hari,
        "per_pekan": dict(per_pekan),
        "per_bulan": dict(per_bulan),
        "rentang": {"dari": awal.isoformat(), "sampai": akhir.isoformat()},
    }


def analisis_tren(
    rows: Sequence[dict[str, Any]],
    *,
    dari: date,
    sampai: date,
    baris_absensi: Sequence[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    """Analisis tren & celah: hari kosong, absensi hilang, perbandingan pekan."""
    baris = normalisasi_baris(rows)
    ada_laporan = {r["tanggal"] for r in baris}
    hk = hari_kerja(dari, sampai)
    hari_kosong = [d for d in hk if d not in ada_laporan]

    # Absensi
    absensi_map: dict[date, str] = {}
    for a in baris_absensi or []:
        d = ke_tanggal(a.get("tanggal"))
        if d is not None:
            absensi_map[d] = str(a.get("status") or "")
    absensi_hilang = [d for d in hk if d not in absensi_map]
    sebaran_absensi = Counter(absensi_map.values())

    # Perbandingan pekan (dua pekan terakhir dalam rentang)
    pekan = _kelompok_pekan(baris)
    pekan_urut = sorted(pekan.items())
    pembanding: dict[str, Any] = {}
    if len(pekan_urut) >= 2:
        (k1, v1), (k2, v2) = pekan_urut[-2], pekan_urut[-1]
        pembanding = {
            "pekan_sebelumnya": {"pekan": k1, "jumlah": len(v1)},
            "pekan_terakhir": {"pekan": k2, "jumlah": len(v2)},
            "selisih": len(v2) - len(v1),
            "arah": "naik" if len(v2) > len(v1) else ("turun" if len(v2) < len(v1) else "stabil"),
        }

    return {
        "periode": {"dari": dari.isoformat(), "sampai": sampai.isoformat()},
        "jumlah_laporan": len(baris),
        "hari_kerja": len(hk),
        "hari_kosong": [d.isoformat() for d in hari_kosong],
        "jumlah_hari_kosong": len(hari_kosong),
        "absensi_hilang": [d.isoformat() for d in absensi_hilang],
        "sebaran_absensi": dict(sebaran_absensi),
        "pembanding_pekan": pembanding,
    }


def _kelompok_pekan(baris: Sequence[dict[str, Any]]) -> dict[str, list[dict[str, Any]]]:
    grup: dict[str, list[dict[str, Any]]] = OrderedDict()
    for r in baris:
        d: date = r["tanggal"]
        iso = d.isocalendar()
        kunci = f"{iso.year}-W{int(iso.week):02d}"
        grup.setdefault(kunci, []).append(r)
    return grup


# ---------------------------------------------------------------------------
# Ringkasan deterministik (tanpa model)
# ---------------------------------------------------------------------------
def bangun_ringkasan(
    rows: Sequence[dict[str, Any]],
    *,
    dari: date,
    sampai: date,
    jenis: str = "mingguan",
    nama: str = "",
) -> dict[str, Any]:
    """Susun ringkasan periode: statistik + tabel + narasi deterministik."""
    baris = normalisasi_baris(rows)
    stat = statistik_kegiatan(baris, dari=dari, sampai=sampai)

    tabel = [
        {
            "tanggal": r["tanggal"].isoformat(),
            "tanggal_label": format_tanggal_id(r["tanggal"]),
            "kegiatan": str(r.get("kegiatan") or ""),
            "status": str(r.get("status") or "draft"),
        }
        for r in baris
    ]

    kendala = [str(r.get("kendala")).strip() for r in baris if str(r.get("kendala") or "").strip()]
    kegiatan_utama = _tema_utama([str(r.get("kegiatan") or "") for r in baris])

    label = label_periode(dari, sampai, jenis)
    narasi = _narasi_deterministik(
        nama=nama,
        label=label,
        jumlah=stat["jumlah_laporan"],
        hari_aktif=stat["jumlah_hari"],
        hari_kerja_n=len(hari_kerja(dari, sampai)),
        status=stat["status"],
        kegiatan_utama=kegiatan_utama,
        kendala=kendala,
    )

    return {
        "periode": {
            "jenis": jenis,
            "dari": dari.isoformat(),
            "sampai": sampai.isoformat(),
            "label": label,
        },
        "statistik": stat,
        "tabel": tabel,
        "kegiatan_utama": kegiatan_utama,
        "kendala": kendala,
        "narasi": narasi,
    }


def _tema_utama(kegiatan: list[str], maks: int = 6) -> list[str]:
    """Ambil beberapa kegiatan utama (unik, urut kemunculan)."""
    terlihat: list[str] = []
    for k in kegiatan:
        k = k.strip()
        if not k:
            continue
        if k.lower() in {t.lower() for t in terlihat}:
            continue
        terlihat.append(k)
        if len(terlihat) >= maks:
            break
    return terlihat


def _narasi_deterministik(
    *,
    nama: str,
    label: str,
    jumlah: int,
    hari_aktif: int,
    hari_kerja_n: int,
    status: dict[str, int],
    kegiatan_utama: list[str],
    kendala: list[str],
) -> str:
    siapa = f"{nama} " if nama else ""
    if jumlah == 0:
        return (
            f"Pada periode {label}, belum ada laporan kegiatan yang tercatat untuk "
            f"{siapa.strip() or 'pengguna ini'}. Disarankan untuk segera melengkapi laporan "
            "agar riwayat kegiatan tetap mutakhir."
        )

    status_teks = ", ".join(f"{k}: {v}" for k, v in status.items()) or "-"
    potongan = [
        f"Pada periode {label}, {siapa}tercatat {jumlah} laporan kegiatan "
        f"yang tersebar pada {hari_aktif} hari aktif dari {hari_kerja_n} hari kerja."
    ]
    if kegiatan_utama:
        potongan.append(
            "Kegiatan utama antara lain: " + "; ".join(kegiatan_utama) + "."
        )
    potongan.append(f"Sebaran status laporan: {status_teks}.")
    if kendala:
        unik = list(dict.fromkeys(k.strip() for k in kendala if k.strip()))[:3]
        potongan.append("Kendala yang tercatat antara lain: " + "; ".join(unik) + ".")
    else:
        potongan.append("Tidak ada kendala berarti yang dilaporkan pada periode ini.")
    if hari_kerja_n and hari_aktif < hari_kerja_n:
        selisih = hari_kerja_n - hari_aktif
        potongan.append(
            f"Masih terdapat {selisih} hari kerja tanpa laporan; sebaiknya dilengkapi."
        )
    return " ".join(potongan)


def bangun_narasi(
    rows: Sequence[dict[str, Any]],
    *,
    dari: date,
    sampai: date,
    jenis: str = "mingguan",
    nama: str = "",
    judul: str = "Laporan Kegiatan Kegiatan",
) -> dict[str, Any]:
    """Susun kerangka narasi panjang (deterministik) untuk laporan formal."""
    dasar = bangun_ringkasan(rows, dari=dari, sampai=sampai, jenis=jenis, nama=nama)
    stat = dasar["statistik"]

    if stat["jumlah_laporan"] == 0:
        badan = (
            f"Pada periode {dasar['periode']['label']}, belum terdapat kegiatan kegiatan "
            "yang tercatat. Bagian ini dapat dilengkapi setelah laporan harian diisi."
        )
        return {
            "judul": judul,
            "periode": dasar["periode"],
            "statistik": stat,
            "narasi": f"{judul}\n\n{badan}",
        }

    per_hari = stat["per_hari"]
    baris_kegiatan = []
    for hari in per_hari:
        daftar = "; ".join(k for k in hari["kegiatan"] if k)
        if daftar:
            baris_kegiatan.append(f"- {hari['label']} ({hari['hari']}): {daftar}")

    kendala = dasar["kendala"]
    kendala_teks = (
        "; ".join(list(dict.fromkeys(kendala))[:5]) if kendala else "tidak ada kendala berarti"
    )

    narasi = "\n".join(
        [
            judul,
            "",
            f"Periode: {dasar['periode']['label']}",
            "",
            "1. Pendahuluan",
            f"Kegiatan kegiatan pada periode {dasar['periode']['label']} dilaksanakan "
            f"selama {len(hari_kerja(dari, sampai))} hari kerja dengan {stat['jumlah_laporan']} "
            f"laporan kegiatan yang tercatat pada {stat['jumlah_hari']} hari aktif.",
            "",
            "2. Uraian Kegiatan",
            *baris_kegiatan,
            "",
            "3. Hasil yang Dicapai",
            "Seluruh kegiatan di atas telah dilaporkan dan didokumentasikan melalui sistem "
            "pelaporan harian.",
            "",
            "4. Kendala",
            f"Kendala yang tercatat: {kendala_teks}.",
            "",
            "5. Rencana Tindak Lanjut",
            "Melanjutkan kegiatan sesuai rencana kerja dan melengkapi laporan yang belum "
            "terisi pada hari kerja berikutnya.",
            "",
            "6. Penutup",
            "Demikian laporan kegiatan ini disusun untuk digunakan sebagaimana mestinya.",
        ]
    )
    return {
        "judul": judul,
        "periode": dasar["periode"],
        "statistik": stat,
        "kendala": kendala,
        "narasi": narasi,
    }
