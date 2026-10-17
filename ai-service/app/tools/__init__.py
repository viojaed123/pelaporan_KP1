"""Registri alat (tools) yang dapat dipanggil model.

Menggabungkan seluruh modul alat menjadi satu :class:`ToolRegistry`.
"""

from __future__ import annotations

from functools import lru_cache

from . import analysis, documents, jadwal, maintenance, messaging, notes, search, summaries
from .registry import Tool, ToolContext, ToolRegistry, skema_objek

__all__ = [
    "Tool",
    "ToolContext",
    "ToolRegistry",
    "skema_objek",
    "bangun_registri",
    "get_registry",
]


def bangun_registri() -> ToolRegistry:
    """Bentuk registri berisi seluruh alat bawaan."""
    registry = ToolRegistry()
    for modul in (notes, analysis, summaries, documents, maintenance, messaging, jadwal, search):
        for tool in modul.bangun():
            registry.daftar(tool)
    return registry


@lru_cache(maxsize=1)
def get_registry() -> ToolRegistry:
    """Registri alat bersama (di-cache)."""
    return bangun_registri()
