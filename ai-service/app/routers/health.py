"""Router ``/health`` — selalu 200, meski Ollama/Database mati."""

from __future__ import annotations

import logging

from fastapi import APIRouter

from .. import db
from ..ollama_client import get_client
from ..schemas import HealthResponse

log = logging.getLogger("ai-service.health")

router = APIRouter(tags=["sistem"])


@router.get("/health", response_model=HealthResponse, summary="Status layanan")
async def health() -> HealthResponse:
    """Kembalikan status layanan.

    PHP (``ai_hidup()``) hanya memeriksa respons HTTP 2xx. Endpoint ini **tidak
    pernah** gagal meski Ollama atau basis data sedang mati: nilainya cukup
    dilaporkan pada field ``ollama``/``database``.
    """
    try:
        info = await get_client().health()
    except Exception as exc:  # noqa: BLE001 - kesehatan tidak boleh melempar
        log.warning("Pemeriksaan Ollama gagal: %s", exc)
        info = {"reachable": False, "models": [], "model": "", "model_tersedia": False}

    try:
        db_ok = db.sehat()
    except Exception as exc:  # noqa: BLE001
        log.warning("Pemeriksaan basis data gagal: %s", exc)
        db_ok = False

    return HealthResponse(
        ok=True,
        model=info.get("model") or "",
        ollama=bool(info.get("reachable")),
        database=db_ok,
        models_tersedia=list(info.get("models") or []),
    )
