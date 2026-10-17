"""Alat agenda/jadwal untuk agen: ``buat_jadwal``, ``daftar_jadwal``,
``ubah_jadwal``, ``hapus_jadwal``, dan ``ingatkan_jadwal``.

Semua alat hanya menyentuh agenda **milik pemanggil** (``ctx.user_id``) —
tidak ada akses lintas pengguna. Operasi tulis bersifat *approval-gated*:
bawaan hanya pratinjau; set ``konfirmasi=true`` untuk menerapkan.

Tabel: ``jadwal`` (lihat database/migrations/018_jadwal.sql).
"""

from __future__ import annotations

import logging
from datetime import datetime
from typing import Any

from ..db import ambil_banyak, ambil_satu, catat_audit, eksekusi, eksekusi_id
from .registry import Tool, ToolContext, skema_objek

log = logging.getLogger("ai-service.tools.jadwal")

STATUS_JADWAL: tuple[str, ...] = ("rencana", "selesai", "batal")

_POLA_TANGGAL = (
    "%Y-%m-%d %H:%M:%S",
    "%Y-%m-%d %H:%M",
    "%Y-%m-%dT%H:%M:%S",
    "%Y-%m-%dT%H:%M",
    "%Y-%m-%d",
    "%d/%m/%Y %H:%M",
    "%d/%m/%Y",
)


def _benar(nilai: Any) -> bool:
    if isinstance(nilai, bool):
        return nilai
    return str(nilai or "").strip().lower() in {"1", "true", "ya", "yes", "setuju", "benar"}


def _norm_waktu(nilai: Any) -> str | None:
    """Normalisasi berbagai format waktu menjadi 'YYYY-MM-DD HH:MM:SS'."""
    if nilai is None:
        return None
    teks = str(nilai).strip()
    if not teks:
        return None
    for pola in _POLA_TANGGAL:
        try:
            dt = datetime.strptime(teks, pola)
        except ValueError:
            continue
        if pola == "%Y-%m-%d":
            dt = dt.replace(hour=8, minute=0, second=0)
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    return None


def _batas_waktu(nilai: Any, *, akhir: bool) -> str | None:
    """Batas rentang untuk penyaringan.

    Bila masukan hanya tanggal (tanpa jam), batas awal memakai 00:00:00 dan
    batas akhir memakai 23:59:59 agar seluruh jadwal pada hari itu tercakup.
    Format lain diserahkan ke :func:`_norm_waktu`.
    """
    teks = str(nilai or "").strip()
    if not teks:
        return None
    for pola in ("%Y-%m-%d", "%d/%m/%Y"):
        try:
            dt = datetime.strptime(teks, pola)
        except ValueError:
            continue
        dt = dt.replace(hour=23, minute=59, second=59) if akhir else dt.replace(hour=0, minute=0, second=0)
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    return _norm_waktu(teks)



def _validasi(args: dict[str, Any], *, wajib_judul: bool = True) -> tuple[dict[str, Any], list[str]]:
    """Validasi masukan jadwal; kembalikan (bersih, galat)."""
    galat: list[str] = []
    bersih: dict[str, Any] = {}

    if "judul" in args or wajib_judul:
        judul = str(args.get("judul") or "").strip()
        if not judul:
            galat.append("Judul jadwal wajib diisi.")
        elif len(judul) > 200:
            galat.append("Judul maksimal 200 karakter.")
        bersih["judul"] = judul[:200]

    if "mulai" in args:
        mulai = _norm_waktu(args.get("mulai"))
        if mulai is None:
            galat.append("Waktu mulai tidak valid (gunakan YYYY-MM-DD HH:MM).")
        bersih["mulai"] = mulai
    elif wajib_judul:
        galat.append("Waktu mulai wajib diisi.")
        bersih["mulai"] = None

    if "selesai" in args:
        selesai = _norm_waktu(args.get("selesai"))
        if args.get("selesai") and selesai is None:
            galat.append("Waktu selesai tidak valid.")
        if selesai and bersih.get("mulai") and selesai < bersih["mulai"]:
            galat.append("Waktu selesai tidak boleh sebelum waktu mulai.")
        bersih["selesai"] = selesai

    if "pengingat_menit" in args:
        try:
            menit = int(args.get("pengingat_menit"))
        except (TypeError, ValueError):
            menit = 30
        if menit < 0 or menit > 10080:
            galat.append("Pengingat antara 0 dan 10080 menit (7 hari).")
        bersih["pengingat_menit"] = menit

    if "lokasi" in args:
        lokasi = str(args.get("lokasi") or "").strip()
        if len(lokasi) > 200:
            galat.append("Lokasi maksimal 200 karakter.")
        bersih["lokasi"] = lokasi[:200]

    if "deskripsi" in args:
        bersih["deskripsi"] = str(args.get("deskripsi") or "").strip()

    if "status" in args:
        status = str(args.get("status") or "").strip().lower()
        if status and status not in STATUS_JADWAL:
            galat.append(f"Status harus salah satu dari: {', '.join(STATUS_JADWAL)}.")
        bersih["status"] = status or "rencana"

    return bersih, galat


def _ambil_milik(ctx: ToolContext, jadwal_id: int) -> dict[str, Any] | None:
    return ambil_satu(
        "SELECT * FROM jadwal WHERE id = %s AND user_id = %s LIMIT 1",
        (jadwal_id, ctx.user_id),
    )


def _ringkas(baris: dict[str, Any]) -> dict[str, Any]:
    return {
        "id": int(baris.get("id", 0)),
        "judul": baris.get("judul"),
        "mulai": str(baris.get("mulai")),
        "selesai": str(baris.get("selesai")) if baris.get("selesai") else None,
        "lokasi": baris.get("lokasi") or "",
        "status": baris.get("status"),
        "pengingat_menit": int(baris.get("pengingat_menit") or 0),
    }


# ---------------------------------------------------------------------------
# buat_jadwal
# ---------------------------------------------------------------------------
def buat_jadwal(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    bersih, galat = _validasi(args, wajib_judul=True)
    if galat:
        return {"ok": False, "pesan": " ".join(galat), "galat": galat}

    if not ctx.user_id:
        return {"ok": False, "pesan": "Pengguna tidak dikenali; tidak dapat menyimpan jadwal."}

    menit = int(bersih.get("pengingat_menit", 30))
    usul = {
        "judul": bersih["judul"],
        "mulai": bersih["mulai"],
        "selesai": bersih.get("selesai"),
        "lokasi": bersih.get("lokasi", ""),
        "deskripsi": bersih.get("deskripsi", ""),
        "pengingat_menit": menit,
        "status": bersih.get("status", "rencana"),
    }

    if not _benar(args.get("konfirmasi")):
        return {
            "ok": True,
            "perlu_konfirmasi": True,
            "status": "menunggu_persetujuan",
            "usul": usul,
            "pesan": "Pratinjau jadwal. Panggil ulang dengan konfirmasi=true untuk menyimpan.",
        }

    baru_id = eksekusi_id(
        """
        INSERT INTO jadwal (user_id, judul, deskripsi, mulai, selesai, lokasi, pengingat_menit, status)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (
            ctx.user_id,
            usul["judul"],
            usul["deskripsi"] or None,
            usul["mulai"],
            usul["selesai"],
            usul["lokasi"] or None,
            usul["pengingat_menit"],
            usul["status"],
        ),
    )

    catat_audit(
        user_id=ctx.user_id,
        aksi="ai_buat_jadwal",
        tabel_ref="jadwal",
        baris_id=baru_id,
        ringkasan=f"Jadwal '{usul['judul']}' dibuat lewat asisten AI",
        data_baru=usul,
    )

    return {
        "ok": True,
        "status": "tersimpan",
        "jadwal_id": baru_id,
        "jadwal": {**usul, "id": baru_id},
        "pesan": f"Jadwal '{usul['judul']}' tersimpan untuk {usul['mulai']}.",
    }


# ---------------------------------------------------------------------------
# daftar_jadwal
# ---------------------------------------------------------------------------
def daftar_jadwal(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    if not ctx.user_id:
        return {"ok": False, "pesan": "Pengguna tidak dikenali."}

    dari = _batas_waktu(args.get("tanggal_dari"), akhir=False)
    sampai = _batas_waktu(args.get("tanggal_sampai"), akhir=True)

    kondisi = ["user_id = %s"]
    params: list[Any] = [ctx.user_id]
    if dari:
        kondisi.append("mulai >= %s")
        params.append(dari)
    if sampai:
        kondisi.append("mulai <= %s")
        params.append(sampai)
    status = str(args.get("status") or "").strip().lower()
    if status in STATUS_JADWAL:
        kondisi.append("status = %s")
        params.append(status)

    try:
        limit = int(args.get("limit") or 20)
    except (TypeError, ValueError):
        limit = 20
    limit = max(1, min(100, limit))

    baris = ambil_banyak(
        "SELECT * FROM jadwal WHERE " + " AND ".join(kondisi) + " ORDER BY mulai ASC LIMIT %s",
        params + [limit],
    )

    return {
        "ok": True,
        "jumlah": len(baris),
        "jadwal": [_ringkas(b) for b in baris],
    }


# ---------------------------------------------------------------------------
# ubah_jadwal
# ---------------------------------------------------------------------------
def ubah_jadwal(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    try:
        jadwal_id = int(args.get("jadwal_id") or 0)
    except (TypeError, ValueError):
        jadwal_id = 0
    if jadwal_id <= 0:
        return {"ok": False, "pesan": "Parameter 'jadwal_id' wajib diisi (angka)."}

    baris = _ambil_milik(ctx, jadwal_id)
    if baris is None:
        return {"ok": False, "pesan": f"Jadwal #{jadwal_id} tidak ditemukan atau bukan milik Anda."}

    bersih, galat = _validasi(args, wajib_judul=False)
    if galat:
        return {"ok": False, "pesan": " ".join(galat), "galat": galat}

    kolom = [k for k in ("judul", "deskripsi", "mulai", "selesai", "lokasi", "pengingat_menit", "status") if k in bersih]
    if not kolom:
        return {"ok": False, "pesan": "Tidak ada perubahan yang diusulkan."}

    usul = {k: bersih[k] for k in kolom}
    if not _benar(args.get("konfirmasi")):
        return {
            "ok": True,
            "perlu_konfirmasi": True,
            "status": "menunggu_persetujuan",
            "jadwal_id": jadwal_id,
            "usul": usul,
            "pesan": "Pratinjau perubahan jadwal. Set konfirmasi=true untuk menyimpan.",
        }

    penetapan = ", ".join(f"`{k}` = %s" for k in kolom)
    eksekusi(
        f"UPDATE jadwal SET {penetapan}, pengingat_terkirim = 0 WHERE id = %s AND user_id = %s",
        list(usul.values()) + [jadwal_id, ctx.user_id],
    )
    catat_audit(
        user_id=ctx.user_id,
        aksi="ai_ubah_jadwal",
        tabel_ref="jadwal",
        baris_id=jadwal_id,
        ringkasan=f"Jadwal #{jadwal_id} diubah lewat asisten AI",
        data_lama={k: baris.get(k) for k in kolom},
        data_baru=usul,
    )
    return {"ok": True, "status": "tersimpan", "jadwal_id": jadwal_id, "perubahan": usul}


# ---------------------------------------------------------------------------
# hapus_jadwal
# ---------------------------------------------------------------------------
def hapus_jadwal(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    try:
        jadwal_id = int(args.get("jadwal_id") or 0)
    except (TypeError, ValueError):
        jadwal_id = 0
    if jadwal_id <= 0:
        return {"ok": False, "pesan": "Parameter 'jadwal_id' wajib diisi (angka)."}

    baris = _ambil_milik(ctx, jadwal_id)
    if baris is None:
        return {"ok": False, "pesan": f"Jadwal #{jadwal_id} tidak ditemukan atau bukan milik Anda."}

    if not _benar(args.get("konfirmasi")):
        return {
            "ok": True,
            "perlu_konfirmasi": True,
            "status": "menunggu_persetujuan",
            "jadwal_id": jadwal_id,
            "jadwal": _ringkas(baris),
            "pesan": "Pratinjau penghapusan. Set konfirmasi=true untuk menghapus.",
        }

    eksekusi("DELETE FROM jadwal WHERE id = %s AND user_id = %s", (jadwal_id, ctx.user_id))
    catat_audit(
        user_id=ctx.user_id,
        aksi="ai_hapus_jadwal",
        tabel_ref="jadwal",
        baris_id=jadwal_id,
        ringkasan=f"Jadwal #{jadwal_id} dihapus lewat asisten AI",
        data_lama=_ringkas(baris),
    )
    return {"ok": True, "status": "terhapus", "jadwal_id": jadwal_id}


# ---------------------------------------------------------------------------
# ingatkan_jadwal
# ---------------------------------------------------------------------------
def ingatkan_jadwal(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Kirim pengingat SEGERA (dalam aplikasi) untuk satu jadwal milik pemanggil."""
    try:
        jadwal_id = int(args.get("jadwal_id") or 0)
    except (TypeError, ValueError):
        jadwal_id = 0
    if jadwal_id <= 0:
        return {"ok": False, "pesan": "Parameter 'jadwal_id' wajib diisi (angka)."}

    baris = _ambil_milik(ctx, jadwal_id)
    if baris is None:
        return {"ok": False, "pesan": f"Jadwal #{jadwal_id} tidak ditemukan atau bukan milik Anda."}

    if not _benar(args.get("konfirmasi")):
        return {
            "ok": True,
            "perlu_konfirmasi": True,
            "status": "menunggu_persetujuan",
            "jadwal_id": jadwal_id,
            "jadwal": _ringkas(baris),
            "pesan": "Pratinjau pengingat. Set konfirmasi=true untuk mengirim notifikasi.",
        }

    pesan = f"Pengingat jadwal: '{baris.get('judul')}' pada {baris.get('mulai')}."
    eksekusi(
        "INSERT INTO notifikasi (user_id, laporan_id, pesan, link) VALUES (%s, NULL, %s, %s)",
        (ctx.user_id, pesan[:500], "jadwal.php"),
    )
    catat_audit(
        user_id=ctx.user_id,
        aksi="ai_ingatkan_jadwal",
        tabel_ref="jadwal",
        baris_id=jadwal_id,
        ringkasan=pesan,
    )
    return {"ok": True, "status": "terkirim", "jadwal_id": jadwal_id, "pesan": pesan}


# ---------------------------------------------------------------------------
# Definisi alat
# ---------------------------------------------------------------------------
def bangun() -> list[Tool]:
    """Kembalikan daftar alat jadwal."""
    properti_buat = {
        "judul": {"type": "string", "description": "Judul/kegiatan jadwal (wajib)."},
        "mulai": {"type": "string", "description": "Waktu mulai 'YYYY-MM-DD HH:MM' (wajib)."},
        "selesai": {"type": "string", "description": "Waktu selesai (opsional)."},
        "lokasi": {"type": "string", "description": "Lokasi (opsional)."},
        "deskripsi": {"type": "string", "description": "Catatan tambahan (opsional)."},
        "pengingat_menit": {"type": "integer", "description": "Menit sebelum mulai untuk pengingat (bawaan 30)."},
        "status": {"type": "string", "enum": list(STATUS_JADWAL)},
        "konfirmasi": {"type": "boolean", "description": "true = simpan; bawaan false = pratinjau."},
    }
    properti_daftar = {
        "tanggal_dari": {"type": "string", "description": "Batas awal 'YYYY-MM-DD'."},
        "tanggal_sampai": {"type": "string", "description": "Batas akhir 'YYYY-MM-DD'."},
        "status": {"type": "string", "enum": list(STATUS_JADWAL)},
        "limit": {"type": "integer", "description": "Maksimal baris (bawaan 20)."},
    }
    properti_ubah = {
        "jadwal_id": {"type": "integer", "description": "ID jadwal (wajib)."},
        "judul": {"type": "string"},
        "mulai": {"type": "string", "description": "Waktu mulai baru."},
        "selesai": {"type": "string"},
        "lokasi": {"type": "string"},
        "deskripsi": {"type": "string"},
        "pengingat_menit": {"type": "integer"},
        "status": {"type": "string", "enum": list(STATUS_JADWAL)},
        "konfirmasi": {"type": "boolean", "description": "true = simpan; bawaan false = pratinjau."},
    }
    properti_id = {
        "jadwal_id": {"type": "integer", "description": "ID jadwal (wajib)."},
        "konfirmasi": {"type": "boolean", "description": "true = jalankan; bawaan false = pratinjau."},
    }
    return [
        Tool(
            nama="buat_jadwal",
            deskripsi=(
                "Buat jadwal/agenda pribadi pengguna dengan pengingat. "
                "Bawaan hanya PRATINJAU; set konfirmasi=true setelah pengguna menyetujui."
            ),
            parameter=skema_objek(properti_buat, wajib=["judul", "mulai"]),
            fungsi=buat_jadwal,
        ),
        Tool(
            nama="daftar_jadwal",
            deskripsi=(
                "Tampilkan agenda/jadwal milik pengguna (rentang tanggal & status opsional). "
                "Gunakan sebelum menjawab pertanyaan tentang agenda."
            ),
            parameter=skema_objek(properti_daftar),
            fungsi=daftar_jadwal,
        ),
        Tool(
            nama="ubah_jadwal",
            deskripsi=(
                "Ubah satu jadwal milik pengguna (judul/waktu/lokasi/pengingat/status). "
                "Bawaan PRATINJAU; set konfirmasi=true untuk menyimpan."
            ),
            parameter=skema_objek(properti_ubah, wajib=["jadwal_id"]),
            fungsi=ubah_jadwal,
        ),
        Tool(
            nama="hapus_jadwal",
            deskripsi=(
                "Hapus satu jadwal milik pengguna. Bawaan PRATINJAU; "
                "set konfirmasi=true untuk menghapus."
            ),
            parameter=skema_objek(properti_id, wajib=["jadwal_id"]),
            fungsi=hapus_jadwal,
        ),
        Tool(
            nama="ingatkan_jadwal",
            deskripsi=(
                "Kirim pengingat SEGERA (notifikasi aplikasi) untuk satu jadwal. "
                "Bawaan PRATINJAU; set konfirmasi=true untuk mengirim."
            ),
            parameter=skema_objek(properti_id, wajib=["jadwal_id"]),
            fungsi=ingatkan_jadwal,
        ),
    ]
