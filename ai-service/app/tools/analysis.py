"""Alat analisis: ``validate_report``, ``statistics``, dan ``trend_analysis``."""

from __future__ import annotations

from typing import Any

from ..services.reporting import (
    analisis_tren,
    label_periode,
    statistik_kegiatan,
    validasi_laporan,
    validasi_many,
)
from .data import (
    ambil_absensi,
    ambil_laporan,
    ambil_laporan_id,
    rentang_default,
    scope_user,
)
from .registry import Tool, ToolContext, skema_objek


# ---------------------------------------------------------------------------
# validate_report
# ---------------------------------------------------------------------------
def validate_report(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Periksa kelengkapan satu laporan, atau seluruh laporan pada suatu periode."""
    laporan_langsung = args.get("laporan")
    if isinstance(laporan_langsung, dict) and laporan_langsung:
        hasil = validasi_laporan(laporan_langsung)
        return {
            "ok": True,
            "mode": "laporan_langsung",
            "verdict": {
                "lengkap": hasil["lengkap"],
                "verdict": hasil["verdict"],
                "skor": hasil["skor"],
                "galat": hasil["galat"],
                "peringatan": hasil["peringatan"],
            },
            "rincian": [{"lengkap": hasil["lengkap"], "skor": hasil["skor"], "galat": hasil["galat"]}],
            "jumlah": 1,
        }

    laporan_id = args.get("laporan_id")
    if laporan_id:
        try:
            baris = ambil_laporan_id(ctx, int(laporan_id))
        except (TypeError, ValueError):
            return {"ok": False, "pesan": "Parameter 'laporan_id' harus berupa angka."}
        if baris is None:
            return {"ok": False, "pesan": f"Laporan #{laporan_id} tidak ditemukan atau bukan milik Anda."}
        hasil = validasi_laporan(baris)
        return {
            "ok": True,
            "mode": "satu_laporan",
            "laporan_id": int(laporan_id),
            "verdict": {
                "lengkap": hasil["lengkap"],
                "verdict": hasil["verdict"],
                "skor": hasil["skor"],
                "galat": hasil["galat"],
                "peringatan": hasil["peringatan"],
            },
            "rincian": [
                {
                    "id": baris.get("id"),
                    "tanggal": str(baris.get("tanggal")),
                    "lengkap": hasil["lengkap"],
                    "skor": hasil["skor"],
                    "galat": hasil["galat"],
                }
            ],
            "jumlah": 1,
            "sumber": [{"tipe": "laporan_harian", "id": baris.get("id"), "tanggal": str(baris.get("tanggal"))}],
        }

    # Mode periode
    dari, sampai = rentang_default(args.get("tanggal_dari"), args.get("tanggal_sampai"))
    user_diminta = args.get("user_id")
    rows = ambil_laporan(
        ctx,
        dari=dari,
        sampai=sampai,
        user_id=int(user_diminta) if user_diminta else None,
    )
    _, label = scope_user(ctx, int(user_diminta) if user_diminta else None)
    agregat = validasi_many(rows)
    return {
        "ok": True,
        "mode": "periode",
        "periode": {"dari": dari.isoformat(), "sampai": sampai.isoformat()},
        "untuk": label,
        "jumlah": agregat["jumlah"],
        "jumlah_lengkap": agregat["jumlah_lengkap"],
        "jumlah_perlu_perbaikan": agregat["jumlah_perlu_perbaikan"],
        "skor_rata_rata": agregat["skor_rata_rata"],
        "rincian": agregat["rincian"],
    }


# ---------------------------------------------------------------------------
# statistics
# ---------------------------------------------------------------------------
def statistics(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Hitung jumlah/statistik kegiatan per hari, pekan, dan bulan."""
    dari, sampai = rentang_default(args.get("tanggal_dari"), args.get("tanggal_sampai"))
    user_diminta = args.get("user_id")
    rows = ambil_laporan(
        ctx,
        dari=dari,
        sampai=sampai,
        user_id=int(user_diminta) if user_diminta else None,
    )
    stat = statistik_kegiatan(rows, dari=dari, sampai=sampai)
    _, label = scope_user(ctx, int(user_diminta) if user_diminta else None)
    return {
        "ok": True,
        "untuk": label,
        "periode": {
            "dari": dari.isoformat(),
            "sampai": sampai.isoformat(),
            "label": label_periode(dari, sampai, args.get("jenis") or "periode"),
        },
        "statistik": stat,
    }


# ---------------------------------------------------------------------------
# trend_analysis
# ---------------------------------------------------------------------------
def trend_analysis(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Bandingkan periode & temukan celah (hari tanpa laporan / tanpa absensi)."""
    dari, sampai = rentang_default(args.get("tanggal_dari"), args.get("tanggal_sampai"))
    user_diminta = args.get("user_id")
    uid = int(user_diminta) if user_diminta else None

    rows = ambil_laporan(ctx, dari=dari, sampai=sampai, user_id=uid)
    absen = ambil_absensi(ctx, dari=dari, sampai=sampai, user_id=uid)
    tren = analisis_tren(rows, dari=dari, sampai=sampai, baris_absensi=absen)
    _, label = scope_user(ctx, uid)

    return {
        "ok": True,
        "untuk": label,
        "periode": {"dari": dari.isoformat(), "sampai": sampai.isoformat()},
        "tren": tren,
        "catatan": (
            "hari_kosong = hari kerja tanpa laporan; absensi_hilang = hari kerja tanpa "
            "catatan absensi."
        ),
    }


# ---------------------------------------------------------------------------
# Definisi alat
# ---------------------------------------------------------------------------
def bangun() -> list[Tool]:
    """Kembalikan daftar alat analisis."""
    properti_val: dict[str, Any] = {
        "laporan": {
            "type": "object",
            "description": "Objek laporan (tanggal/kegiatan/deskripsi/...) untuk diperiksa langsung.",
        },
        "laporan_id": {"type": "integer", "description": "ID laporan yang diperiksa."},
        "tanggal_dari": {"type": "string", "description": "Tanggal awal periode (YYYY-MM-DD)."},
        "tanggal_sampai": {"type": "string", "description": "Tanggal akhir periode (YYYY-MM-DD)."},
        "user_id": {"type": "integer", "description": "Khusus mentor/admin: pengguna tertentu."},
    }
    properti_stat: dict[str, Any] = {
        "tanggal_dari": {"type": "string", "description": "Tanggal awal (YYYY-MM-DD)."},
        "tanggal_sampai": {"type": "string", "description": "Tanggal akhir (YYYY-MM-DD)."},
        "jenis": {"type": "string", "enum": ["harian", "mingguan", "bulanan", "periode"]},
        "user_id": {"type": "integer", "description": "Khusus mentor/admin: pengguna tertentu."},
    }
    return [
        Tool(
            nama="validate_report",
            deskripsi=(
                "Periksa kelengkapan/kelayakan laporan. Bisa untuk satu laporan (lewat objek 'laporan' "
                "atau 'laporan_id') atau untuk seluruh periode. Mengembalikan verdict, skor 0-100, "
                "daftar galat, dan saran."
            ),
            parameter=skema_objek(properti_val),
            fungsi=validate_report,
        ),
        Tool(
            nama="statistics",
            deskripsi=(
                "Statistik jumlah kegiatan per hari/pekan/bulan beserta sebaran status laporan "
                "pada suatu periode."
            ),
            parameter=skema_objek(properti_stat),
            fungsi=statistics,
        ),
        Tool(
            nama="trend_analysis",
            deskripsi=(
                "Analisis tren: perbandingan pekan, hari kerja tanpa laporan (celah), dan absensi "
                "yang hilang pada suatu periode."
            ),
            parameter=skema_objek(properti_stat),
            fungsi=trend_analysis,
        ),
    ]
