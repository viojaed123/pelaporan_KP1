"""Pembantu kueri data untuk alat-alat (parameter terikat, sadar-peran)."""

from __future__ import annotations

from datetime import date, timedelta
from typing import Any, Sequence

from ..db import ambil_banyak, ambil_satu
from ..services.reporting import (
    STATUS_LAPORAN,
    ke_tanggal,
    normalisasi_baris,
)
from .registry import ToolContext


def scope_user(ctx: ToolContext, diminta: int | None = None) -> tuple[int | None, str]:
    """Tentukan penyaring ``user_id`` berdasarkan peran pemanggil.

    * Peran `mentor`/`admin` boleh memilih pengguna lain (atau semua).
    * Peran `karyawan` selalu dibatasi ke dirinya sendiri. Bila ``user_id``
      bernilai 0 (dipanggil server-ke-server tanpa pengguna, mis. rekap tim),
      penyaring tidak diterapkan — panggilan tetap terlindungi kunci layanan.

    Returns:
        (``user_id`` atau ``None`` untuk semua, keterangan label).
    """
    if ctx.bisa_lintas_pengguna:
        if diminta:
            nama = nama_pengguna(int(diminta))
            return int(diminta), nama or f"pengguna #{diminta}"
        return None, "seluruh pengguna"
    if ctx.user_id:
        return ctx.user_id, ctx.nama or f"pengguna #{ctx.user_id}"
    return None, "seluruh pengguna"


def nama_pengguna(user_id: int) -> str:
    baris = ambil_satu("SELECT nama FROM users WHERE id = %s", (int(user_id),))
    return str(baris["nama"]) if baris else ""


def rentang_default(dari: Any, sampai: Any, hari: int = 30) -> tuple[date, date]:
    """Isi rentang tanggal yang kosong dengan ``hari`` terakhir sampai hari ini."""
    hari_ini = date.today()
    d = ke_tanggal(dari) or (hari_ini - timedelta(days=hari - 1))
    s = ke_tanggal(sampai) or hari_ini
    if s < d:
        d, s = s, d
    return d, s


def ambil_laporan(
    ctx: ToolContext,
    *,
    dari: date,
    sampai: date,
    status: str | None = None,
    kata_kunci: str | None = None,
    user_id: int | None = None,
    limit: int = 500,
) -> list[dict[str, Any]]:
    """Ambil baris ``laporan_harian`` pada rentang, tersaring sesuai peran."""
    uid, _ = scope_user(ctx, user_id)
    kondisi: list[str] = ["l.tanggal BETWEEN %s AND %s"]
    params: list[Any] = [dari.isoformat(), sampai.isoformat()]

    if uid is not None:
        kondisi.append("l.user_id = %s")
        params.append(int(uid))
    if status:
        status = status.strip().lower()
        if status in STATUS_LAPORAN:
            kondisi.append("l.status = %s")
            params.append(status)
    if kata_kunci:
        kondisi.append("(l.kegiatan LIKE %s OR l.deskripsi LIKE %s OR l.hasil LIKE %s OR l.kendala LIKE %s)")
        pola = f"%{kata_kunci.strip()}%"
        params.extend([pola, pola, pola, pola])

    params.append(int(max(1, min(limit, 2000))))
    sql = f"""
        SELECT l.id, l.user_id, u.nama AS nama_pengguna, l.tanggal, l.jam_mulai, l.jam_selesai,
               l.kegiatan, l.deskripsi, l.hasil, l.kendala, l.rencana_berikutnya,
               l.status, l.catatan_mentor, l.created_at, l.updated_at
        FROM laporan_harian l
        JOIN users u ON u.id = l.user_id
        WHERE {' AND '.join(kondisi)}
        ORDER BY l.tanggal ASC, l.id ASC
        LIMIT %s
    """
    return ambil_banyak(sql, params)


def ambil_laporan_id(ctx: ToolContext, laporan_id: int) -> dict[str, Any] | None:
    """Ambil satu laporan; periksa hak akses pemanggil."""
    baris = ambil_satu(
        """
        SELECT l.*, u.nama AS nama_pengguna
        FROM laporan_harian l JOIN users u ON u.id = l.user_id
        WHERE l.id = %s LIMIT 1
        """,
        (int(laporan_id),),
    )
    if baris is None:
        return None
    if not ctx.bisa_lintas_pengguna and int(baris.get("user_id", 0)) != ctx.user_id:
        return None  # sembunyikan dari non-pemilik
    return baris


def ambil_absensi(
    ctx: ToolContext, *, dari: date, sampai: date, user_id: int | None = None
) -> list[dict[str, Any]]:
    """Ambil baris ``absensi`` pada rentang, tersaring sesuai peran."""
    uid, _ = scope_user(ctx, user_id)
    kondisi = ["a.tanggal BETWEEN %s AND %s"]
    params: list[Any] = [dari.isoformat(), sampai.isoformat()]
    if uid is not None:
        kondisi.append("a.user_id = %s")
        params.append(int(uid))
    sql = f"""
        SELECT a.id, a.user_id, u.nama AS nama_pengguna, a.tanggal,
               a.jam_masuk, a.jam_pulang, a.status, a.keterangan
        FROM absensi a JOIN users u ON u.id = a.user_id
        WHERE {' AND '.join(kondisi)}
        ORDER BY a.tanggal ASC
    """
    return ambil_banyak(sql, params)


def ringkas_baris(rows: Sequence[dict[str, Any]]) -> list[dict[str, Any]]:
    """Potong baris laporan menjadi bentuk ringkas untuk keluaran alat."""
    hasil = []
    for r in normalisasi_baris(rows):
        hasil.append(
            {
                "id": r.get("id"),
                "user_id": r.get("user_id"),
                "nama_pengguna": r.get("nama_pengguna"),
                "tanggal": r["tanggal"].isoformat(),
                "kegiatan": str(r.get("kegiatan") or ""),
                "status": str(r.get("status") or "draft"),
                "jam_mulai": str(r.get("jam_mulai") or "") or None,
                "jam_selesai": str(r.get("jam_selesai") or "") or None,
            }
        )
    return hasil


def sumber_laporan(rows: Sequence[dict[str, Any]]) -> list[dict[str, Any]]:
    """Bangun daftar 'sumber' yang dikembalikan ke PHP."""
    return [
        {
            "tipe": "laporan_harian",
            "id": r.get("id"),
            "tanggal": r["tanggal"].isoformat() if hasattr(r.get("tanggal"), "isoformat") else str(r.get("tanggal")),
            "kegiatan": str(r.get("kegiatan") or "")[:120],
        }
        for r in normalisasi_baris(rows)
    ]
