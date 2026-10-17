"""Pekerja latar belakang (antrean ``ai_jobs``).

Atribut modul dimuat malas (PEP 562) agar ``python -m app.jobs.worker`` tidak
memicu peringatan ``runpy`` karena modul diimpor lebih awal.
"""

from __future__ import annotations

from typing import Any

__all__ = ["gelung", "main", "proses_semua"]


def __getattr__(nama: str) -> Any:
    """Muat fungsi pekerja saat benar-benar diakses."""
    if nama in __all__:
        from . import worker

        return getattr(worker, nama)
    raise AttributeError(f"modul {__name__!r} tidak memiliki atribut {nama!r}")
