"""Alat ringkasan: ``weekly_summary``, ``monthly_summary``, ``narrative_report``."""

from __future__ import annotations

from datetime import date, datetime
from typing import Any

from ..services.reporting import (
    bangun_narasi,
    bangun_ringkasan,
    ke_tanggal,
    rentang_bulan,
    rentang_minggu,
)
from .data import ambil_laporan, rentang_default, scope_user, sumber_laporan
from .registry import Tool, ToolContext, skema_objek


def _bulan_ke_tanggal(bulan: str | None) -> date | None:
    """Ubah ``YYYY-MM`` menjadi tanggal hari pertama, atau ``None``."""
    if not bulan:
        return None
    teks = str(bulan).strip()
    for pola in ("%Y-%m", "%Y-%m-%d", "%m-%Y"):
        try:
            d = datetime.strptime(teks, pola)
            return d.date().replace(day=1)
        except ValueError:
            continue
    return ke_tanggal(teks)


def weekly_summary(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Susun ringkasan mingguan (Senin–Minggu)."""
    acuan = ke_tanggal(args.get("tanggal_acuan")) or date.today()
    from_hari = args.get("tanggal_dari")
    sampai_hari = args.get("tanggal_sampai")
    if from_hari or sampai_hari:
        dari, sampai = rentang_default(from_hari, sampai_hari, hari=7)
    else:
        dari, sampai = rentang_minggu(acuan)

    user_diminta = args.get("user_id")
    uid = int(user_diminta) if user_diminta else None
    rows = ambil_laporan(ctx, dari=dari, sampai=sampai, user_id=uid)
    _, label = scope_user(ctx, uid)

    ringkas = bangun_ringkasan(
        rows, dari=dari, sampai=sampai, jenis="mingguan", nama=ctx.nama if not uid else label
    )
    return {
        "ok": True,
        "jenis": "mingguan",
        "untuk": label,
        "periode": ringkas["periode"],
        "statistik": ringkas["statistik"],
        "tabel": ringkas["tabel"],
        "kegiatan_utama": ringkas["kegiatan_utama"],
        "kendala": ringkas["kendala"],
        "ringkasan": ringkas["narasi"],
        "sumber": sumber_laporan(rows),
    }


def monthly_summary(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Susun ringkasan bulanan."""
    acuan = _bulan_ke_tanggal(args.get("bulan")) or ke_tanggal(args.get("tanggal_acuan")) or date.today()
    from_hari = args.get("tanggal_dari")
    sampai_hari = args.get("tanggal_sampai")
    if from_hari or sampai_hari:
        dari, sampai = rentang_default(from_hari, sampai_hari, hari=30)
    else:
        dari, sampai = rentang_bulan(acuan)

    user_diminta = args.get("user_id")
    uid = int(user_diminta) if user_diminta else None
    rows = ambil_laporan(ctx, dari=dari, sampai=sampai, user_id=uid)
    _, label = scope_user(ctx, uid)

    ringkas = bangun_ringkasan(
        rows, dari=dari, sampai=sampai, jenis="bulanan", nama=ctx.nama if not uid else label
    )
    return {
        "ok": True,
        "jenis": "bulanan",
        "untuk": label,
        "periode": ringkas["periode"],
        "statistik": ringkas["statistik"],
        "tabel": ringkas["tabel"],
        "kegiatan_utama": ringkas["kegiatan_utama"],
        "kendala": ringkas["kendala"],
        "ringkasan": ringkas["narasi"],
    }


def narrative_report(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Susun narasi laporan panjang (bahan Word/PDF)."""
    jenis = str(args.get("jenis") or "periode").lower()
    if jenis == "mingguan":
        acuan = ke_tanggal(args.get("tanggal_acuan")) or date.today()
        dari, sampai = rentang_minggu(acuan)
    elif jenis == "bulanan":
        acuan = _bulan_ke_tanggal(args.get("bulan")) or ke_tanggal(args.get("tanggal_acuan")) or date.today()
        dari, sampai = rentang_bulan(acuan)
    else:
        dari, sampai = rentang_default(args.get("tanggal_dari"), args.get("tanggal_sampai"))

    user_diminta = args.get("user_id")
    uid = int(user_diminta) if user_diminta else None
    rows = ambil_laporan(ctx, dari=dari, sampai=sampai, user_id=uid)
    _, label = scope_user(ctx, uid)

    judul = str(args.get("judul") or "Laporan Kegiatan Kegiatan")
    hasil = bangun_narasi(rows, dari=dari, sampai=sampai, jenis=jenis, nama=ctx.nama if not uid else label, judul=judul)
    return {
        "ok": True,
        "jenis": jenis,
        "untuk": label,
        "judul": judul,
        "periode": hasil["periode"],
        "statistik": hasil["statistik"],
        "narasi": hasil["narasi"],
    }


# ---------------------------------------------------------------------------
# Definisi alat
# ---------------------------------------------------------------------------
def bangun() -> list[Tool]:
    """Kembalikan daftar alat ringkasan."""
    umum = {
        "tanggal_acuan": {"type": "string", "description": "Tanggal acuan (YYYY-MM-DD) di dalam periode."},
        "tanggal_dari": {"type": "string", "description": "Override tanggal awal (YYYY-MM-DD)."},
        "tanggal_sampai": {"type": "string", "description": "Override tanggal akhir (YYYY-MM-DD)."},
        "user_id": {"type": "integer", "description": "Khusus mentor/admin: pengguna tertentu."},
    }
    bulan = {
        **umum,
        "bulan": {"type": "string", "description": "Bulan acuan format YYYY-MM."},
    }
    narasi = {
        **bulan,
        "jenis": {"type": "string", "enum": ["mingguan", "bulanan", "periode"]},
        "judul": {"type": "string", "description": "Judul laporan."},
    }
    return [
        Tool(
            nama="weekly_summary",
            deskripsi=(
                "Buat ringkasan kegiatan mingguan (Senin–Minggu): statistik, tabel per hari, "
                "kegiatan utama, kendala, dan narasi deterministik siap pakai."
            ),
            parameter=skema_objek(umum),
            fungsi=weekly_summary,
        ),
        Tool(
            nama="monthly_summary",
            deskripsi=(
                "Buat ringkasan kegiatan bulanan: statistik, tabel per hari, kegiatan utama, "
                "kendala, dan narasi deterministik."
            ),
            parameter=skema_objek(bulan),
            fungsi=monthly_summary,
        ),
        Tool(
            nama="narrative_report",
            deskripsi=(
                "Susun narasi laporan panjang (pendahuluan, uraian, hasil, kendala, rencana, penutup) "
                "untuk keperluan dokumen Word/PDF."
            ),
            parameter=skema_objek(narasi),
            fungsi=narrative_report,
        ),
    ]
