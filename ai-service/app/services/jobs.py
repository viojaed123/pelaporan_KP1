"""Pemrosesan baris ``ai_jobs`` (antrean pekerjaan berat).

Alur status: ``antre`` -> ``proses`` -> ``selesai`` | ``gagal``. Pengklaiman
dilakukan secara atomik tanpa ``SKIP LOCKED`` (MariaDB 10.4 belum mendukungnya).
"""

from __future__ import annotations

import logging
from datetime import datetime
from typing import Any, Callable

from ..db import DatabaseError, ambil_banyak, ambil_satu, eksekusi, json_aman
from ..tools import ToolContext
from ..tools import analysis as alat_analisis
from ..tools import documents as alat_dokumen
from ..tools import maintenance as alat_pemeliharaan
from ..tools import messaging as alat_pesan
from ..tools import notes as alat_catatan
from ..tools import summaries as alat_ringkas

log = logging.getLogger("ai-service.jobs")

#: Pemetaan ``jenis`` job -> fungsi alat.
PENANGAN: dict[str, Callable[[ToolContext, dict[str, Any]], dict[str, Any]]] = {
    "search_notes": alat_catatan.search_notes,
    "update_note": alat_catatan.update_note,
    "validate": alat_analisis.validate_report,
    "validate_report": alat_analisis.validate_report,
    "statistics": alat_analisis.statistics,
    "statistik": alat_analisis.statistics,
    "trend_analysis": alat_analisis.trend_analysis,
    "tren": alat_analisis.trend_analysis,
    "weekly_summary": alat_ringkas.weekly_summary,
    "ringkasan_mingguan": alat_ringkas.weekly_summary,
    "monthly_summary": alat_ringkas.monthly_summary,
    "ringkasan_bulanan": alat_ringkas.monthly_summary,
    "summarize": alat_ringkas.weekly_summary,
    "narrative": alat_ringkas.narrative_report,
    "narrative_report": alat_ringkas.narrative_report,
    "narasi": alat_ringkas.narrative_report,
    "export_excel": alat_dokumen.export_excel,
    "export_word": alat_dokumen.export_word,
    "backup": alat_pemeliharaan.backup_database,
    "backup_database": alat_pemeliharaan.backup_database,
    "restore": alat_pemeliharaan.restore_database,
    "restore_database": alat_pemeliharaan.restore_database,
    "send_email": alat_pesan.send_email,
    "send_whatsapp": alat_pesan.send_whatsapp,
}


def _role_pengguna(user_id: int) -> str:
    """Ambil peran pengguna dari basis data (agar job admin tetap berhak)."""
    try:
        baris = ambil_satu("SELECT role FROM users WHERE id = %s", (int(user_id),))
        if baris and baris.get("role"):
            return str(baris["role"])
    except Exception as exc:  # noqa: BLE001
        log.warning("Tidak dapat membaca peran pengguna %s: %s", user_id, exc)
    return "karyawan"


def _parameter(row: dict[str, Any]) -> dict[str, Any]:
    param = row.get("parameter")
    if isinstance(param, dict):
        return param
    if isinstance(param, (str, bytes)) and param:
        import json

        try:
            data = json.loads(param)
            return data if isinstance(data, dict) else {}
        except ValueError:
            return {}
    return {}


# ---------------------------------------------------------------------------
# Klaim & pemulihan
# ---------------------------------------------------------------------------
def klaim_job() -> dict[str, Any] | None:
    """Ambil satu job berstatus ``antre`` dan tandai ``proses`` (atomik)."""
    for _ in range(5):
        kandidat = ambil_satu(
            "SELECT id, user_id, jenis, parameter, dibuat_pada FROM ai_jobs "
            "WHERE status = 'antre' ORDER BY dibuat_pada ASC LIMIT 1"
        )
        if not kandidat:
            return None
        jumlah = eksekusi(
            "UPDATE ai_jobs SET status = 'proses', mulai_pada = NOW() "
            "WHERE id = %s AND status = 'antre'",
            (kandidat["id"],),
        )
        if jumlah == 1:
            return kandidat
    return None


def pulihkan_macet(menit: int = 15) -> int:
    """Kembalikan job ``proses`` yang macet (melewati ``menit``) ke ``antre``."""
    return eksekusi(
        "UPDATE ai_jobs SET status = 'antre', mulai_pada = NULL "
        "WHERE status = 'proses' AND mulai_pada IS NOT NULL "
        "AND mulai_pada < (NOW() - INTERVAL %s MINUTE)",
        (int(menit),),
    )


# ---------------------------------------------------------------------------
# Menjalankan job
# ---------------------------------------------------------------------------
def jalankan_job(row: dict[str, Any]) -> dict[str, Any]:
    """Jalankan satu job dan kembalikan ``{status, hasil, pesan_error}``."""
    jenis = str(row.get("jenis") or "").strip().lower()
    parameter = _parameter(row)
    user_id = int(row.get("user_id") or 0)

    fungsi = PENANGAN.get(jenis)
    if fungsi is None:
        return {
            "status": "gagal",
            "hasil": None,
            "pesan_error": f"Jenis job '{jenis}' tidak dikenal.",
        }

    role = str(parameter.get("role") or _role_pengguna(user_id))
    ctx = ToolContext(
        user_id=user_id,
        role=role,
        nama=str(parameter.get("nama") or ""),
        session=int(parameter.get("session") or 0),
    )
    argumen = parameter.get("argumen") if isinstance(parameter.get("argumen"), dict) else None
    if argumen is None:
        argumen = {k: v for k, v in parameter.items() if k not in ("role", "nama", "session")}

    try:
        hasil = fungsi(ctx, argumen)
    except DatabaseError as exc:
        return {"status": "gagal", "hasil": None, "pesan_error": f"Basis data: {exc}"}
    except Exception as exc:  # noqa: BLE001 - job tidak boleh mematikan pekerja
        log.exception("Job %s gagal: %s", row.get("id"), exc)
        return {"status": "gagal", "hasil": None, "pesan_error": str(exc)}

    if not isinstance(hasil, dict):
        hasil = {"ok": True, "hasil": hasil}

    if not hasil.get("ok", True):
        return {
            "status": "gagal",
            "hasil": hasil,
            "pesan_error": str(hasil.get("pesan") or "Job melaporkan kegagalan."),
        }
    return {"status": "selesai", "hasil": hasil, "pesan_error": None}


def _tuntaskan(job_id: str, keluaran: dict[str, Any]) -> None:
    status = keluaran["status"]
    hasil = keluaran.get("hasil")
    eksekusi(
        "UPDATE ai_jobs SET status = %s, hasil = %s, pesan_error = %s, selesai_pada = NOW() "
        "WHERE id = %s",
        (
            status,
            json_aman(hasil) if hasil is not None else None,
            (keluaran.get("pesan_error") or None),
            job_id,
        ),
    )


def proses_sekali() -> dict[str, Any] | None:
    """Klaim & proses satu job; kembalikan ringkasannya (atau ``None``)."""
    row = klaim_job()
    if row is None:
        return None
    keluaran = jalankan_job(row)
    _tuntaskan(str(row["id"]), keluaran)
    return {
        "id": row["id"],
        "jenis": row.get("jenis"),
        "user_id": row.get("user_id"),
        "status": keluaran["status"],
        "pesan_error": keluaran.get("pesan_error"),
    }


def proses_batch(maks: int = 1) -> list[dict[str, Any]]:
    """Proses hingga ``maks`` job; kembalikan daftar ringkasannya."""
    hasil: list[dict[str, Any]] = []
    for _ in range(max(1, int(maks))):
        ringkas = proses_sekali()
        if ringkas is None:
            break
        hasil.append(ringkas)
    return hasil


def ambil_status(job_id: str) -> dict[str, Any] | None:
    """Ambil status satu job (bentuknya menyerupai ``ai_antrian_status`` PHP)."""
    baris = ambil_satu(
        """
        SELECT id, user_id, jenis, status, parameter, hasil, pesan_error,
               dibuat_pada, mulai_pada, selesai_pada
        FROM ai_jobs WHERE id = %s LIMIT 1
        """,
        (job_id,),
    )
    if baris is None:
        return None
    if isinstance(baris.get("hasil"), str):
        import json

        try:
            baris["hasil"] = json.loads(baris["hasil"])
        except ValueError:
            baris["hasil"] = None
    if isinstance(baris.get("parameter"), str):
        import json

        try:
            baris["parameter"] = json.loads(baris["parameter"])
        except ValueError:
            baris["parameter"] = None
    for kunci in ("dibuat_pada", "mulai_pada", "selesai_pada"):
        nilai = baris.get(kunci)
        if isinstance(nilai, datetime):
            baris[kunci] = nilai.isoformat(sep=" ", timespec="seconds")
    return baris


def daftar_jobs(limit: int = 50, status: str | None = None) -> list[dict[str, Any]]:
    """Daftar job terbaru, opsional tersaring status."""
    if status in ("antre", "proses", "selesai", "gagal"):
        return ambil_banyak(
            "SELECT id, user_id, jenis, status, pesan_error, dibuat_pada, mulai_pada, selesai_pada "
            "FROM ai_jobs WHERE status = %s ORDER BY dibuat_pada DESC LIMIT %s",
            (status, int(limit)),
        )
    return ambil_banyak(
        "SELECT id, user_id, jenis, status, pesan_error, dibuat_pada, mulai_pada, selesai_pada "
        "FROM ai_jobs ORDER BY dibuat_pada DESC LIMIT %s",
        (int(limit),),
    )
