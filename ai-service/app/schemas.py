"""Skema permintaan & balasan (Pydantic).

Bentuk permintaan mengikuti kontrak di ``includes/ai_client.php`` dan
``api_chat.php`` persis, sehingga lapisan PHP tidak perlu berubah.
"""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field, field_validator

RolePengguna = Literal["admin", "karyawan", "mentor"]


# ---------------------------------------------------------------------------
# Permintaan
# ---------------------------------------------------------------------------
class ChatRequest(BaseModel):
    """Body ``POST /chat``."""

    pesan: str = Field(min_length=1, max_length=8000)
    user_id: int = 0
    role: str = "karyawan"
    nama: str = ""
    session: int = 0
    konteks: dict[str, Any] = Field(default_factory=dict)

    @field_validator("role")
    @classmethod
    def _role_valid(cls, v: str) -> str:
        v = (v or "karyawan").strip().lower()
        return v if v in ("admin", "karyawan", "mentor") else "karyawan"


class SummarizeRequest(BaseModel):
    """Body ``POST /summarize``."""

    jenis: str = "mingguan"
    parameter: dict[str, Any] = Field(default_factory=dict)


class NarrativeRequest(BaseModel):
    """Body ``POST /narrative``."""

    parameter: dict[str, Any] = Field(default_factory=dict)


class ValidateRequest(BaseModel):
    """Body ``POST /validate``.

    ``laporan`` boleh berupa satu objek laporan atau daftar laporan.
    """

    laporan: dict[str, Any] | list[dict[str, Any]] = Field(default_factory=dict)


# ---------------------------------------------------------------------------
# Balasan
# ---------------------------------------------------------------------------
class ChatResponse(BaseModel):
    """Balasan ``POST /chat`` — PHP membaca ``balasan`` dan ``sumber``."""

    ok: bool = True
    balasan: str
    sumber: list[dict[str, Any]] | None = None
    session: int = 0
    model: str | None = None
    tools_dipakai: list[str] = Field(default_factory=list)
    iterasi: int = 0


class SummarizeResponse(BaseModel):
    """Balasan ``POST /summarize`` — disediakan ``balasan`` dan ``ringkasan``."""

    ok: bool = True
    jenis: str
    balasan: str
    ringkasan: str
    periode: dict[str, Any] = Field(default_factory=dict)
    statistik: dict[str, Any] = Field(default_factory=dict)
    tabel: list[dict[str, Any]] = Field(default_factory=list)
    model: str | None = None
    mode: str = "model"


class NarrativeResponse(BaseModel):
    """Balasan ``POST /narrative``."""

    ok: bool = True
    balasan: str
    narasi: str
    parameter: dict[str, Any] = Field(default_factory=dict)
    statistik: dict[str, Any] = Field(default_factory=dict)
    model: str | None = None
    mode: str = "model"


class ValidateResponse(BaseModel):
    """Balasan ``POST /validate``."""

    ok: bool = True
    balasan: str
    verdict: dict[str, Any]
    rincian: list[dict[str, Any]] = Field(default_factory=list)
    jumlah: int = 0
    model: str | None = None
    mode: str = "model"


class HealthResponse(BaseModel):
    """Balasan ``GET /health`` — PHP hanya mengecek status HTTP 2xx."""

    ok: bool = True
    model: str
    ollama: bool
    database: bool = False
    versi: str = "1.0.0"
    models_tersedia: list[str] = Field(default_factory=list)


class JobRingkas(BaseModel):
    """Cuplikan baris ``ai_jobs``."""

    id: str
    jenis: str
    status: str
    hasil: dict[str, Any] | None = None
    pesan_error: str | None = None
    dibuat_pada: str | None = None
    mulai_pada: str | None = None
    selesai_pada: str | None = None
