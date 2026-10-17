"""Router ``/jobs`` — memproses & memantau antrean ``ai_jobs``."""

from __future__ import annotations

import logging

from fastapi import APIRouter, Query

from ..db import DatabaseError
from ..schemas import JobRingkas
from ..services import jobs as svc
from .common import cegah_galat_db, galat_layanan

log = logging.getLogger("ai-service.jobs")

router = APIRouter(prefix="/jobs", tags=["antrean"])


@router.post("/proses", summary="Proses job pada antrean")
async def proses(
    maks: int = Query(default=5, ge=1, le=100, description="Jumlah job maksimum yang diproses."),
) -> dict:
    """Klaim dan proses hingga ``maks`` job berstatus ``antre``."""
    try:
        hasil = svc.proses_batch(maks)
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc
    return {"ok": True, "diproses": len(hasil), "job": hasil}


@router.post("/pulihkan-macet", summary="Kembalikan job macet ke antrean")
async def pulihkan(menit: int = Query(default=15, ge=1)) -> dict:
    """Tandai job ``proses`` yang macet agar dapat diproses ulang."""
    try:
        jumlah = svc.pulihkan_macet(menit)
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc
    return {"ok": True, "dipulihkan": jumlah}


@router.get("", summary="Daftar job")
async def daftar(
    limit: int = Query(default=50, ge=1, le=500),
    status: str | None = Query(default=None, pattern="^(antre|proses|selesai|gagal)$"),
) -> dict:
    """Daftar job terbaru."""
    try:
        rows = svc.daftar_jobs(limit=limit, status=status)
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc
    return {"ok": True, "jumlah": len(rows), "job": rows}


@router.get("/{job_id}", response_model=JobRingkas, summary="Status satu job")
async def status(job_id: str) -> JobRingkas:
    """Status & hasil satu job (menyerupai ``ai_antrian_status``)."""
    try:
        baris = svc.ambil_status(job_id)
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc
    if baris is None:
        raise galat_layanan(f"Job '{job_id}' tidak ditemukan.", kode=404)
    return JobRingkas(
        id=str(baris.get("id")),
        jenis=str(baris.get("jenis") or ""),
        status=str(baris.get("status") or ""),
        hasil=baris.get("hasil") if isinstance(baris.get("hasil"), dict) else None,
        pesan_error=baris.get("pesan_error"),
        dibuat_pada=str(baris.get("dibuat_pada") or "") or None,
        mulai_pada=str(baris.get("mulai_pada") or "") or None,
        selesai_pada=str(baris.get("selesai_pada") or "") or None,
    )
