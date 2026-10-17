"""Pemuatan prompt sistem (berbahasa Indonesia).

Prompt disimpan sebagai berkas ``.md`` di folder ini agar mudah ditinjau dan
diubah tanpa menyentuh kode. Placeholder memakai sintaks :class:`string.Template`
(``$nama``) sehingga tanda kurung kurawal pada contoh JSON tidak terganggu.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from string import Template

PROMPT_DIR: Path = Path(__file__).resolve().parent


@lru_cache(maxsize=32)
def muat(berkas: str) -> str:
    """Muat isi berkas prompt (tanpa ekstensi)."""
    jalur = PROMPT_DIR / f"{berkas}.md"
    if not jalur.is_file():
        raise FileNotFoundError(f"Prompt tidak ditemukan: {jalur}")
    return jalur.read_text(encoding="utf-8").strip()


def render(berkas: str, **nilai: object) -> str:
    """Muat prompt lalu isi placeholder ``$nama``."""
    return Template(muat(berkas)).safe_substitute(**nilai)


def domain() -> str:
    """Aturan domain yang dibagi seluruh prompt."""
    return muat("domain")
