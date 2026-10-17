"""Uji berkas paket: konfigurasi Hermes, skill, dan kelengkapan ``.env.example``.

Tujuannya menjaga agar berkas dokumentasi/konfigurasi tetap sinkron dengan kode
(a.l. setiap variabel pada ``.env.example`` benar-benar dibaca oleh Settings).
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest
import yaml

from app.config import Settings

AKAR = Path(__file__).resolve().parents[1]


def _baca(p: str) -> str:
    return (AKAR / p).read_text(encoding="utf-8")


# ---------------------------------------------------------------------------
# .env.example
# ---------------------------------------------------------------------------
def test_env_example_cocok_dengan_settings() -> None:
    teks = _baca(".env.example")
    envs = {m.lower() for m in re.findall(r"^\s*([A-Z][A-Z0-9_]+)\s*=", teks, re.M)}
    fields = set(Settings.model_fields.keys())

    assert envs, ".env.example seharusnya memuat variabel"
    assert envs <= fields, f"Variabel tak dikenal di .env.example: {sorted(envs - fields)}"


# ---------------------------------------------------------------------------
# Hermes Agent
# ---------------------------------------------------------------------------
def test_hermes_config_valid() -> None:
    data = yaml.safe_load(_baca("hermes/hermes.config.yaml"))
    assert isinstance(data, dict)

    model = data.get("model")
    assert isinstance(model, dict)
    assert model.get("provider") == "custom"
    assert model.get("default")
    assert str(model.get("base_url", "")).startswith("http")
    assert model.get("api_mode") == "chat_completions"


# ---------------------------------------------------------------------------
# Skill laporan-harian
# ---------------------------------------------------------------------------
def test_skill_frontmatter() -> None:
    teks = _baca("skills/laporan-harian/SKILL.md")
    assert teks.startswith("---"), "SKILL.md harus diawali frontmatter YAML"

    _, depan, _ = teks.split("---", 2)
    front = yaml.safe_load(depan)

    assert isinstance(front, dict)
    assert front.get("name") == "laporan-harian"
    assert front.get("description")
    # Isi panduan wajib menyebut kontrak penting.
    assert "/chat" in teks
    assert "X-Service-Key" in teks


@pytest.mark.parametrize(
    "nama,berkas",
    [
        ("pencarian", "skills/pencarian/SKILL.md"),
        ("jadwal", "skills/jadwal/SKILL.md"),
    ],
)
def test_skill_tambahan_frontmatter(nama: str, berkas: str) -> None:
    teks = _baca(berkas)
    assert teks.startswith("---"), f"{berkas} harus diawali frontmatter YAML"

    _, depan, _ = teks.split("---", 2)
    front = yaml.safe_load(depan)

    assert isinstance(front, dict)
    assert front.get("name") == nama
    assert front.get("description")


@pytest.mark.parametrize("berkas", ["README.md", ".env.example", "run.ps1", "run.bat", "requirements.txt"])
def test_berkas_paket_ada(berkas: str) -> None:
    assert (AKAR / berkas).is_file(), f"Berkas paket hilang: {berkas}"
