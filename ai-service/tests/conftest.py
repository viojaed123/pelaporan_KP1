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
# Pengujian tidak boleh menyentuh Gemini (atau .env pengembang yang nyasar).
os.environ.setdefault("AI_GEMINI_API_KEY", "")

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402
from app.gemini_client import GeminiClient  # noqa: E402
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
    """Paksa setiap panggilan model gagal (Gemini & Ollama sama-sama mati)."""

    async def _gagal(self, *args, **kwargs):  # noqa: ANN001, ANN002, ANN003
        raise ModelUnavailable("Model mati (uji)")

    monkeypatch.setattr(OllamaClient, "chat", _gagal)
    monkeypatch.setattr(GeminiClient, "chat", _gagal)
