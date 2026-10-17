"""Alat ekspor dokumen: ``export_excel`` dan ``export_word``."""

from __future__ import annotations

from datetime import date
from typing import Any

from ..config import get_settings
from ..services import exporter
from ..services.reporting import bangun_narasi, bangun_ringkasan, ke_tanggal, label_periode, rentang_bulan, rentang_minggu
from .data import ambil_laporan, rentang_default, scope_user
from .registry import Tool, ToolContext, skema_objek


def _rentang(args: dict[str, Any]) -> tuple[date, date, str]:
    """Tentukan rentang tanggal dari argumen alat."""
    jenis = str(args.get("jenis") or "periode").lower()
    if jenis == "mingguan":
        acuan = ke_tanggal(args.get("tanggal_acuan")) or date.today()
        dari, sampai = rentang_minggu(acuan)
    elif jenis == "bulanan":
        acuan = ke_tanggal(args.get("tanggal_acuan")) or date.today()
        dari, sampai = rentang_bulan(acuan)
    else:
        dari, sampai = rentang_default(args.get("tanggal_dari"), args.get("tanggal_sampai"))
    return dari, sampai, jenis


def export_excel(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Buat berkas ``.xlsx`` berisi daftar laporan pada suatu periode."""
    dari, sampai, jenis = _rentang(args)
    user_diminta = args.get("user_id")
    uid = int(user_diminta) if user_diminta else None
    rows = ambil_laporan(ctx, dari=dari, sampai=sampai, user_id=uid)
    _, label = scope_user(ctx, uid)

    periode = label_periode(dari, sampai, jenis)
    judul = str(args.get("judul") or f"Laporan Kegiatan {label}")
    nama = exporter.nama_berkas("laporan", judul, periode, "xlsx")
    tujuan = get_settings().export_dir_path / nama

    exporter.ekspor_excel(rows, tujuan=tujuan, judul=judul, periode=periode)
    ukuran = tujuan.stat().st_size

    return {
        "ok": True,
        "jenis": "excel",
        "nama_file": nama,
        "jalur": str(tujuan),
        "url": f"/exports/{nama}",
        "ukuran_bytes": ukuran,
        "jumlah_baris": len(rows),
        "periode": {"dari": dari.isoformat(), "sampai": sampai.isoformat(), "label": periode},
        "untuk": label,
        "pesan": f"Berkas Excel dibuat: {nama}",
    }


def export_word(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Buat berkas ``.docx`` berisi narasi laporan (dan lampiran tabel)."""
    dari, sampai, jenis = _rentang(args)
    user_diminta = args.get("user_id")
    uid = int(user_diminta) if user_diminta else None
    rows = ambil_laporan(ctx, dari=dari, sampai=sampai, user_id=uid)
    _, label = scope_user(ctx, uid)

    periode = label_periode(dari, sampai, jenis)
    judul = str(args.get("judul") or "Laporan Kegiatan Kegiatan")
    nama = exporter.nama_berkas("laporan", judul, periode, "docx")
    tujuan = get_settings().export_dir_path / nama

    if args.get("narasi"):
        narasi = str(args["narasi"])
        tabel: list[dict[str, Any]] = []
    else:
        dasar = bangun_ringkasan(rows, dari=dari, sampai=sampai, jenis=jenis, nama=ctx.nama if not uid else label)
        narasi = bangun_narasi(rows, dari=dari, sampai=sampai, jenis=jenis, nama=ctx.nama if not uid else label, judul=judul)["narasi"]
        tabel = dasar["tabel"]

    exporter.ekspor_word(narasi, tujuan=tujuan, judul=judul, periode=periode, tabel=tabel)
    ukuran = tujuan.stat().st_size

    return {
        "ok": True,
        "jenis": "word",
        "nama_file": nama,
        "jalur": str(tujuan),
        "url": f"/exports/{nama}",
        "ukuran_bytes": ukuran,
        "jumlah_baris": len(rows),
        "periode": {"dari": dari.isoformat(), "sampai": sampai.isoformat(), "label": periode},
        "untuk": label,
        "pesan": f"Berkas Word dibuat: {nama}",
    }


def bangun() -> list[Tool]:
    """Kembalikan daftar alat ekspor."""
    properti = {
        "jenis": {"type": "string", "enum": ["mingguan", "bulanan", "periode"]},
        "tanggal_acuan": {"type": "string", "description": "Tanggal acuan (YYYY-MM-DD)."},
        "tanggal_dari": {"type": "string", "description": "Tanggal awal (YYYY-MM-DD)."},
        "tanggal_sampai": {"type": "string", "description": "Tanggal akhir (YYYY-MM-DD)."},
        "judul": {"type": "string", "description": "Judul dokumen."},
        "user_id": {"type": "integer", "description": "Khusus mentor/admin: pengguna tertentu."},
    }
    return [
        Tool(
            nama="export_excel",
            deskripsi=(
                "Buat berkas Excel (.xlsx) berisi daftar laporan kegiatan pada suatu periode. "
                "Mengembalikan nama berkas dan jalurnya."
            ),
            parameter=skema_objek(properti),
            fungsi=export_excel,
        ),
        Tool(
            nama="export_word",
            deskripsi=(
                "Buat berkas Word (.docx) berisi narasi laporan kegiatan beserta lampiran tabel. "
                "Mengembalikan nama berkas dan jalurnya."
            ),
            parameter=skema_objek(properti),
            fungsi=export_word,
        ),
    ]
