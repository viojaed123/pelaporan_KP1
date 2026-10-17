"""Konfigurasi & fixture bersama pengujian.

Pengujian **tidak** memerlukan Ollama maupun basis data hidup: klien model
dipalsukan, dan endpoint yang diuji memakai jalur deterministik murni.
"""

from __future__ import annotations

import os

# Atur lingkungan SEBELUM mengimpor paket app (settings di-cache).
os.environ.setdefault("AI_SERVICE_KEY", "test-key")
os.environ.setdefault("AI_DEGRADE_DETERMINISTIC", "true")
os.environ.setdefault("AI_DB_PREFER_READONLY", "false")
os.environ.setdefault("AI_DB_APP_USER", "tidak_ada_user_uji")
os.environ.setdefault("AI_DB_APP_PASSWORD", "x")

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402
from app.ollama_client import ModelUnavailable, OllamaClient  # noqa: E402

KUNCI = "test-key"


@pytest.fixture()
def client() -> TestClient:
    """Klien HTTP uji terhadap aplikasi FastAPI."""
    return TestClient(app)


@pytest.fixture()
def headers() -> dict[str, str]:
    """Header kunci layanan yang sah."""
    return {"X-Service-Key": KUNCI}


@pytest.fixture()
def ollama_mati(monkeypatch: pytest.MonkeyPatch) -> None:
    """Paksa setiap panggilan model gagal (mensimulasikan Ollama mati)."""

    async def _gagal(self, *args, **kwargs):  # noqa: ANN001, ANN002, ANN003
        raise ModelUnavailable("Ollama mati (uji)")

    monkeypatch.setattr(OllamaClient, "chat", _gagal)
