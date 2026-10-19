"""Pembantu bersama untuk router: konteks, pemanggilan model, pemetaan galat."""

from __future__ import annotations

import logging
from datetime import date
from typing import Any

from fastapi import HTTPException, status

from ..db import DatabaseError, json_aman
from ..llm import get_client
from ..ollama_client import ChatResult
from ..tools import ToolContext

log = logging.getLogger("ai-service.routers")


def konteks_dari(parameter: dict[str, Any] | None, *, default_role: str = "karyawan") -> ToolContext:
    """Bangun :class:`ToolContext` dari ``parameter`` (dipakai /summarize, dll.).

    Kunci yang dikenali: ``user_id``, ``role``, ``nama``, ``session``.
    """
    parameter = parameter or {}
    role = str(parameter.get("role") or default_role).strip().lower()
    if role not in ("admin", "karyawan", "mentor"):
        role = default_role
    try:
        user_id = int(parameter.get("user_id") or 0)
    except (TypeError, ValueError):
        user_id = 0
    return ToolContext(
        user_id=user_id,
        role=role,
        nama=str(parameter.get("nama") or ""),
        session=int(parameter.get("session") or 0),
    )


def galat_layanan(pesan: str, kode: int = status.HTTP_503_SERVICE_UNAVAILABLE) -> HTTPException:
    """Bentuk HTTPException dengan field ``detail`` yang dibaca PHP."""
    return HTTPException(status_code=kode, detail=pesan)


async def minta_narasi(
    sistem: str,
    instruksi: str,
    data: Any,
    *,
    format_json: bool = False,
) -> ChatResult:
    """Minta model menyusun narasi berdasarkan ``data``.

    Raises:
        ModelUnavailable: bila Ollama tidak dapat dipanggil.
    """
    client = get_client()
    pesan = f"{instruksi}\n\nDATA:\n{json_aman(data)}"
    return await client.chat(
        [
            {"role": "system", "content": sistem},
            {"role": "user", "content": pesan},
        ],
        format="json" if format_json else None,
    )


def cegah_galat_db(exc: DatabaseError) -> HTTPException:
    """Petakan galat basis data menjadi HTTP 503."""
    return galat_layanan(f"Basis data tidak dapat diakses: {exc}")


def hari_ini() -> date:
    return date.today()
