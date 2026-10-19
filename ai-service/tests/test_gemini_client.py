"""Uji terjemahan pesan/skema klien Gemini dan perilaku rantai fallback.

Seluruh pengujian di sini **murni** (tanpa jaringan): fungsi terjemahan diuji
langsung, dan rantai klien memakai klien palsu.
"""

from __future__ import annotations

import asyncio
from typing import Any

import pytest

from app.gemini_client import (
    GeminiClient,
    _baca_respon,
    _ke_contents,
    _ke_sistem,
    _ke_tools,
    _rapikan_skema,
)
from app.llm import RantaiKlien
from app.ollama_client import ChatResult, ModelUnavailable


# ---------------------------------------------------------------------------
# Terjemahan pesan
# ---------------------------------------------------------------------------
def test_sistem_digabung() -> None:
    pesan = [
        {"role": "system", "content": "Anda asisten."},
        {"role": "user", "content": "halo"},
        {"role": "system", "content": "Jawab ringkas."},
    ]
    assert _ke_sistem(pesan) == "Anda asisten.\n\nJawab ringkas."


def test_contents_user_saja() -> None:
    isi = _ke_contents([{"role": "user", "content": "halo"}])
    assert isi == [{"role": "user", "parts": [{"text": "halo"}]}]


def test_contents_tool_calls_model() -> None:
    pesan = [
        {"role": "user", "content": "cari x"},
        {
            "role": "assistant",
            "content": "",
            "tool_calls": [
                {"function": {"name": "web_search", "arguments": {"kueri": "x"}}}
            ],
        },
        {"role": "tool", "tool_name": "web_search", "content": '{"ok": true}'},
    ]
    isi = _ke_contents(pesan)

    model = isi[1]
    assert model["role"] == "model"
    assert model["parts"][0]["functionCall"] == {"name": "web_search", "args": {"kueri": "x"}}

    alat = isi[2]
    assert alat["role"] == "user"  # Gemini menerima functionResponse pada peran user
    fr = alat["parts"][0]["functionResponse"]
    assert fr["name"] == "web_search"
    assert fr["response"] == {"ok": True}


def test_contents_tool_tanpa_nama_pakai_antrean() -> None:
    """Nama alat diambil dari panggilan model sebelumnya bila `tool_name` kosong."""
    pesan = [
        {
            "role": "assistant",
            "content": "",
            "tool_calls": [
                {"function": {"name": "statistics", "arguments": "{}"}},
            ],
        },
        {"role": "tool", "content": '{"total": 3}'},
    ]
    isi = _ke_contents(pesan)
    assert isi[1]["parts"][0]["functionResponse"]["name"] == "statistics"


def test_contents_argumen_string_json() -> None:
    pesan = [
        {
            "role": "assistant",
            "content": "",
            "tool_calls": [
                {"function": {"name": "alat", "arguments": '{"a": 1}'}},
            ],
        },
    ]
    isi = _ke_contents(pesan)
    assert isi[0]["parts"][0]["functionCall"]["args"] == {"a": 1}


# ---------------------------------------------------------------------------
# Terjemahan skema alat
# ---------------------------------------------------------------------------
def test_ke_tools_membuang_additional_properties() -> None:
    tools = [
        {
            "type": "function",
            "function": {
                "name": "web_search",
                "deskripsi": None,
                "description": "Cari di internet.",
                "parameters": {
                    "type": "object",
                    "properties": {"kueri": {"type": "string", "description": "Kata kunci"}},
                    "required": ["kueri"],
                    "additionalProperties": False,
                },
            },
        }
    ]
    hasil = _ke_tools(tools)
    assert len(hasil) == 1
    decl = hasil[0]["function_declarations"][0]
    assert decl["name"] == "web_search"
    assert decl["description"] == "Cari di internet."
    assert "additionalProperties" not in decl["parameters"]
    assert decl["parameters"]["required"] == ["kueri"]


def test_ke_tools_kosong() -> None:
    assert _ke_tools(None) == []
    assert _ke_tools([]) == []


def test_rapikan_skema_rekursif() -> None:
    skema = {
        "type": "object",
        "additionalProperties": False,
        "properties": {"a": {"type": "string", "default": "x"}},
    }
    hasil = _rapikan_skema(skema)
    assert "additionalProperties" not in hasil
    assert "default" not in hasil["properties"]["a"]
    assert hasil["properties"]["a"]["type"] == "string"


# ---------------------------------------------------------------------------
# Pembacaan balasan
# ---------------------------------------------------------------------------
def test_baca_respon_teks() -> None:
    data = {
        "candidates": [
            {"content": {"parts": [{"text": "Halo!"}]}, "finishReason": "STOP"}
        ],
        "modelVersion": "gemini-2.5-flash",
    }
    hasil = _baca_respon(data, "gemini-2.5-flash")
    assert isinstance(hasil, ChatResult)
    assert hasil.content == "Halo!"
    assert hasil.tool_calls == []
    assert hasil.model == "gemini-2.5-flash"


def test_baca_respon_function_call() -> None:
    data = {
        "candidates": [
            {
                "content": {
                    "parts": [
                        {"functionCall": {"name": "statistics", "args": {"periode": "bulan"}}}
                    ]
                },
                "finishReason": "STOP",
            }
        ]
    }
    hasil = _baca_respon(data, "gemini-3.8-flash")
    assert hasil.tool_calls == [
        {"function": {"name": "statistics", "arguments": {"periode": "bulan"}}}
    ]
    assert hasil.message["tool_calls"] == hasil.tool_calls


def test_baca_respon_normalisasi_default_api_dan_tanda() -> None:
    """Prefix `default_api:` dibuang dan thoughtSignature dipertahankan."""
    data = {
        "candidates": [
            {
                "content": {
                    "parts": [
                        {
                            "functionCall": {"name": "default_api:web_search", "args": {"kueri": "x"}},
                            "thoughtSignature": "abc123",
                        }
                    ]
                },
                "finishReason": "STOP",
            }
        ]
    }
    hasil = _baca_respon(data, "gemini-3.8-flash")
    assert hasil.tool_calls[0]["function"]["name"] == "web_search"
    assert hasil.tool_calls[0]["function"]["arguments"] == {"kueri": "x"}
    assert hasil.tool_calls[0]["thought_signature"] == "abc123"

    # Tanda ikut terkirim ulang sebagai bagian functionCall.
    isi = _ke_contents(
        [
            {"role": "user", "content": "cari"},
            {"role": "assistant", "content": "", "tool_calls": hasil.tool_calls},
        ]
    )
    part = isi[1]["parts"][0]["functionCall"]
    assert part["name"] == "web_search"
    assert isi[1]["parts"][0]["thoughtSignature"] == "abc123"


def test_baca_respon_kosong_tidak_melempar() -> None:
    data = {"candidates": [{"finishReason": "SAFETY"}]}
    hasil = _baca_respon(data, "gemini-2.5-flash")
    assert hasil.content == ""


# ---------------------------------------------------------------------------
# Rantai fallback
# ---------------------------------------------------------------------------
class _KlienPalsu:
    """Klien uji: selalu gagal atau selalu berhasil."""

    def __init__(self, *, gagal: bool, nama: str = " palsu") -> None:
        self.gagal = gagal
        self.nama = nama
        self.panggilan = 0

    async def chat(self, messages, **kwargs):  # noqa: ANN001, ANN002
        self.panggilan += 1
        if self.gagal:
            raise ModelUnavailable(f"{self.nama} mati")
        return ChatResult(content=f"jawaban {self.nama}", model=self.nama)

    async def tutup(self) -> None:
        return None


def test_rantai_jatuh_ke_cadangan() -> None:
    pertama = _KlienPalsu(gagal=True, nama="gemini-palsu")
    kedua = _KlienPalsu(gagal=False, nama="ollama-palsu")
    rantai = RantaiKlien([pertama, kedua])

    hasil = asyncio.run(rantai.chat([{"role": "user", "content": "halo"}]))
    assert hasil.content == "jawaban ollama-palsu"
    assert pertama.panggilan == 1
    assert kedua.panggilan == 1


def test_rantai_semua_gagal_melempar() -> None:
    rantai = RantaiKlien([_KlienPalsu(gagal=True), _KlienPalsu(gagal=True, nama="2")])
    with pytest.raises(ModelUnavailable):
        asyncio.run(rantai.chat([{"role": "user", "content": "halo"}]))


def test_rantai_berhenti_di_pertama() -> None:
    pertama = _KlienPalsu(gagal=False, nama="utama")
    kedua = _KlienPalsu(gagal=False, nama="cadangan")
    rantai = RantaiKlien([pertama, kedua])

    hasil = asyncio.run(rantai.chat([{"role": "user", "content": "halo"}]))
    assert "utama" in hasil.content
    assert kedua.panggilan == 0


def test_client_gemini_tanpa_kunci_health_gagal() -> None:
    """Health klien Gemini tanpa kunci = tidak reachable (tanpa jaringan)."""
    from app.config import Settings

    klien = GeminiClient(Settings(ai_service_key="x", ai_gemini_api_key=""))
    info: dict[str, Any] = asyncio.run(klien.health())
    assert info["reachable"] is False
