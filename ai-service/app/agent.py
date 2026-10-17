"""Gelung agen (agentic loop) dengan *function calling*.

Alur: kirim percakapan + skema alat ke model. Bila model meminta memanggil alat,
jalankan alat tersebut, sambungkan hasilnya, lalu ulangi sampai model memberi
jawaban akhir (atau batas putaran tercapai).
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any

from .db import ambil_banyak, json_aman
from .ollama_client import ChatResult, OllamaClient, get_client
from .tools import ToolContext, ToolRegistry, get_registry

log = logging.getLogger("ai-service.agent")

#: Batas ukuran teks hasil alat yang disambungkan kembali ke model.
BATAS_HASIL_ALAT = 16000


@dataclass
class AgentResult:
    """Hasil akhir gelung agen."""

    balasan: str
    sumber: list[dict[str, Any]] = field(default_factory=list)
    tools_dipakai: list[str] = field(default_factory=list)
    iterasi: int = 0
    model: str = ""
    pesan_mentah: list[dict[str, Any]] = field(default_factory=list)


def muat_riwayat(session: int, batas: int = 10) -> list[dict[str, str]]:
    """Muat beberapa pesan terakhir sebuah sesi percakapan sebagai konteks."""
    if not session or session <= 0:
        return []
    try:
        rows = ambil_banyak(
            """
            SELECT peran, isi FROM chat_messages
            WHERE session_id = %s
            ORDER BY id DESC LIMIT %s
            """,
            (int(session), int(max(1, batas))),
        )
    except Exception as exc:  # noqa: BLE001 - riwayat opsional
        log.warning("Tidak dapat memuat riwayat sesi %s: %s", session, exc)
        return []

    riwayat: list[dict[str, str]] = []
    for row in reversed(rows):
        peran = "assistant" if str(row.get("peran")) == "assistant" else "user"
        isi = str(row.get("isi") or "").strip()
        if isi:
            riwayat.append({"role": peran, "content": isi})
    return riwayat


async def jalankan_agen(
    *,
    sistem: str,
    pesan: str,
    riwayat: list[dict[str, str]] | None = None,
    ctx: ToolContext,
    registry: ToolRegistry | None = None,
    client: OllamaClient | None = None,
    max_iterasi: int | None = None,
) -> AgentResult:
    """Jalankan gelung agen sampai jawaban akhir diperoleh.

    Raises:
        ModelUnavailable: bila model tidak dapat dipanggil.
    """
    registry = registry or get_registry()
    client = client or get_client()
    if max_iterasi is None:
        from .config import get_settings

        max_iterasi = get_settings().ai_max_tool_iterations

    messages: list[dict[str, Any]] = [{"role": "system", "content": sistem}]
    messages.extend(riwayat or [])
    messages.append({"role": "user", "content": pesan})

    skema = registry.skema_untuk(ctx.role)
    sumber: list[dict[str, Any]] = []
    tools_dipakai: list[str] = []
    model_terpakai = ""
    terakhir: ChatResult | None = None

    for iterasi in range(1, max(1, max_iterasi) + 1):
        hasil = await client.chat(messages, tools=skema or None)
        terakhir = hasil
        model_terpakai = hasil.model or model_terpakai
        messages.append(hasil.message)

        if not hasil.tool_calls:
            return AgentResult(
                balasan=hasil.content.strip(),
                sumber=sumber,
                tools_dipakai=tools_dipakai,
                iterasi=iterasi,
                model=model_terpakai,
                pesan_mentah=messages,
            )

        for panggilan in hasil.tool_calls:
            fungsi = panggilan.get("function") or {}
            nama = str(fungsi.get("name") or "")
            argumen = fungsi.get("arguments") or {}
            if not isinstance(argumen, dict):
                argumen = {}
            if nama:
                tools_dipakai.append(nama)

            keluaran = registry.jalankan(nama, ctx, argumen)
            for s in keluaran.get("sumber") or []:
                if s not in sumber:
                    sumber.append(s)

            teks = json_aman(keluaran)
            if len(teks) > BATAS_HASIL_ALAT:
                teks = teks[:BATAS_HASIL_ALAT] + '..."dipotong"}'
            messages.append({"role": "tool", "content": teks})

    # Batas putaran tercapai — susun jawaban cadangan dari hasil alat terakhir.
    cadangan = terakhir.content.strip() if terakhir else ""
    if not cadangan:
        cadangan = (
            "Maaf, saya belum bisa menyelesaikan permintaan ini karena terlalu banyak "
            "langkah. Coba persempit pertanyaannya, misalnya sebutkan periode atau nama kegiatan."
        )
    return AgentResult(
        balasan=cadangan,
        sumber=sumber,
        tools_dipakai=tools_dipakai,
        iterasi=max(1, max_iterasi),
        model=model_terpakai,
        pesan_mentah=messages,
    )
