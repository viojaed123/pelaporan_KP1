"""Router ``/chat`` — chatbot agen dengan function calling."""

from __future__ import annotations

import logging

from fastapi import APIRouter

from ..agent import jalankan_agen, muat_riwayat
from ..config import get_settings
from ..db import DatabaseError, json_aman
from ..ollama_client import ModelUnavailable
from ..prompts import domain, render
from ..schemas import ChatRequest, ChatResponse
from ..tools import ToolContext
from .common import cegah_galat_db, galat_layanan

log = logging.getLogger("ai-service.chat")

router = APIRouter(tags=["asisten"])


def bangun_prompt(request: ChatRequest) -> str:
    """Rakit prompt sistem berbahasa Indonesia untuk percakapan."""
    from datetime import date

    return render(
        "system_chat",
        nama=request.nama or "(tanpa nama)",
        role=request.role,
        user_id=request.user_id,
        tanggal=date.today().isoformat(),
        konteks_json=json_aman(request.konteks or {}),
        domain=domain(),
    )


@router.post("/chat", response_model=ChatResponse, summary="Tanya jawab asisten")
async def chat(request: ChatRequest) -> ChatResponse:
    """Jawab pertanyaan pengguna memakai agen + alat basis data.

    Bentuk permintaan (dari ``api_chat.php``)::

        {"pesan": "...", "user_id": 1, "role": "karyawan",
         "nama": "Vio", "session": 12, "konteks": {...}}

    Bentuk balasan::

        {"ok": true, "balasan": "...", "sumber": [...], "session": 12,
         "model": "qwen2.5:7b", "tools_dipakai": ["search_notes"], "iterasi": 2}
    """
    settings = get_settings()
    ctx = ToolContext(
        user_id=request.user_id,
        role=request.role,
        nama=request.nama,
        session=request.session,
        konteks=request.konteks or {},
    )

    try:
        riwayat = muat_riwayat(request.session, settings.ai_history_limit)
    except DatabaseError:
        riwayat = []

    try:
        hasil = await jalankan_agen(
            sistem=bangun_prompt(request),
            pesan=request.pesan,
            riwayat=riwayat,
            ctx=ctx,
        )
    except ModelUnavailable as exc:
        log.warning("Model tidak tersedia: %s", exc)
        raise galat_layanan(
            "Layanan AI (Ollama) sedang tidak tersedia. Pastikan Ollama berjalan dan model "
            "sudah diunduh (ollama pull qwen2.5:7b)."
        ) from exc
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc

    if not hasil.balasan:
        hasil.balasan = "Maaf, saya belum mendapat jawaban dari model. Coba ulangi pertanyaannya."

    return ChatResponse(
        ok=True,
        balasan=hasil.balasan,
        sumber=hasil.sumber or None,
        session=request.session,
        model=hasil.model or None,
        tools_dipakai=hasil.tools_dipakai,
        iterasi=hasil.iterasi,
    )
