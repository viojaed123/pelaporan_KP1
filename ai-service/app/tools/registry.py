"""Purwarupa (prototipe) alat yang dapat dipanggil model.

Setiap alat mendeklarasikan skema JSON, deskripsi, pembatasan peran, dan fungsi
Python yang menjalankannya. Registri ini menjadi jembatan antara *function
calling* Ollama dan logika aplikasi.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any, Callable

log = logging.getLogger("ai-service.tools")

#: Tipe fungsi alat: menerima konteks dan argumen, mengembalikan dict.
FungsiAlat = Callable[["ToolContext", dict[str, Any]], dict[str, Any]]


@dataclass
class ToolContext:
    """Konteks pemanggil alat (dari body ``/chat``)."""

    user_id: int = 0
    role: str = "karyawan"
    nama: str = ""
    session: int = 0
    konteks: dict[str, Any] = field(default_factory=dict)

    @property
    def bisa_lintas_pengguna(self) -> bool:
        """True bila peran boleh melihat data pengguna lain (mentor/admin)."""
        return self.role in ("mentor", "admin")


@dataclass
class Tool:
    """Definisi satu alat."""

    nama: str
    deskripsi: str
    parameter: dict[str, Any]
    fungsi: FungsiAlat
    peran: tuple[str, ...] = ()  # kosong = semua peran
    butuh_persetujuan: bool = False

    def skema(self) -> dict[str, Any]:
        """Skema *function calling* untuk Ollama."""
        return {
            "type": "function",
            "function": {
                "name": self.nama,
                "description": self.deskripsi,
                "parameters": self.parameter,
            },
        }

    def boleh_dipakai(self, role: str) -> bool:
        return not self.peran or role in self.peran


class ToolRegistry:
    """Kumpulan alat yang terdaftar."""

    def __init__(self) -> None:
        self._alat: dict[str, Tool] = {}

    def daftar(self, tool: Tool) -> Tool:
        """Tambahkan alat ke registri."""
        if tool.nama in self._alat:
            raise ValueError(f"Alat ganda: {tool.nama}")
        self._alat[tool.nama] = tool
        return tool

    def get(self, nama: str) -> Tool | None:
        return self._alat.get(nama)

    def semua(self) -> list[Tool]:
        return list(self._alat.values())

    def nama_semua(self) -> list[str]:
        return list(self._alat.keys())

    def skema_untuk(self, role: str) -> list[dict[str, Any]]:
        """Skema alat yang boleh dipakai peran tertentu."""
        return [t.skema() for t in self._alat.values() if t.boleh_dipakai(role)]

    def jalankan(self, nama: str, ctx: ToolContext, argumen: dict[str, Any]) -> dict[str, Any]:
        """Jalankan alat; bungkus galat agar model tetap menerima balasan rapi."""
        tool = self._alat.get(nama)
        if tool is None:
            return {"ok": False, "pesan": f"Alat '{nama}' tidak dikenal."}
        if not tool.boleh_dipakai(ctx.role):
            return {
                "ok": False,
                "pesan": f"Peran '{ctx.role}' tidak berhak memakai alat '{nama}'.",
            }
        try:
            hasil = tool.fungsi(ctx, argumen or {})
            if not isinstance(hasil, dict):
                hasil = {"ok": True, "hasil": hasil}
            hasil.setdefault("ok", True)
            hasil.setdefault("alat", nama)
            return hasil
        except Exception as exc:  # noqa: BLE001 - galat alat tidak boleh mematikan agen
            log.exception("Alat %s gagal: %s", nama, exc)
            return {"ok": False, "alat": nama, "pesan": f"Alat gagal dijalankan: {exc}"}


def skema_objek(properti: dict[str, Any], wajib: list[str] | None = None) -> dict[str, Any]:
    """Bantu membentuk skema objek parameter JSON."""
    return {
        "type": "object",
        "properties": properti,
        "required": wajib or [],
        "additionalProperties": False,
    }


S = {
    "string": lambda desc, **extra: {"type": "string", "description": desc, **extra},
    "integer": lambda desc, **extra: {"type": "integer", "description": desc, **extra},
    "boolean": lambda desc, **extra: {"type": "boolean", "description": desc, **extra},
    "number": lambda desc, **extra: {"type": "number", "description": desc, **extra},
}
