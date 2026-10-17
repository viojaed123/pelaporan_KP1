"""Uji middleware kunci layanan & perilaku endpoint saat Ollama mati."""

from __future__ import annotations

from fastapi.testclient import TestClient

from app.auth import jalur_publik, kunci_sah
from app.config import get_settings


def test_jalur_publik_health_saja() -> None:
    settings = get_settings()
    assert jalur_publik("/health", settings) is True
    assert jalur_publik("/chat", settings) is False
    assert jalur_publik("/validate", settings) is False


def test_kunci_sah() -> None:
    settings = get_settings()
    assert kunci_sah("test-key", settings) is True
    assert kunci_sah("salah", settings) is False
    assert kunci_sah(None, settings) is False
    assert kunci_sah("", settings) is False


def test_health_tanpa_kunci_200(client: TestClient) -> None:
    resp = client.get("/health")
    assert resp.status_code == 200
    badan = resp.json()
    assert badan["ok"] is True
    assert "model" in badan
    assert isinstance(badan["ollama"], bool)


def test_endpoint_tanpa_kunci_401(client: TestClient) -> None:
    resp = client.post("/validate", json={"laporan": {"kegiatan": "x"}})
    assert resp.status_code == 401
    assert "detail" in resp.json()


def test_jobs_tanpa_kunci_401(client: TestClient) -> None:
    assert client.get("/jobs").status_code == 401


def test_validate_dengan_kunci_dan_ollama_mati_deterministik(
    client: TestClient, headers: dict[str, str], ollama_mati: None
) -> None:
    resp = client.post(
        "/validate",
        headers=headers,
        json={
            "laporan": {
                "tanggal": "2026-10-14",
                "kegiatan": "Rapat koordinasi tim",
                "deskripsi": "Menghadiri rapat koordinasi membahas progres mingguan.",
            }
        },
    )
    assert resp.status_code == 200
    badan = resp.json()
    assert badan["ok"] is True
    assert badan["mode"] == "deterministik"
    assert badan["balasan"]
    assert badan["verdict"]["lengkap"] is True


def test_chat_ollama_mati_503(client: TestClient, headers: dict[str, str], ollama_mati: None) -> None:
    resp = client.post(
        "/chat",
        headers=headers,
        json={"pesan": "Halo", "user_id": 1, "role": "karyawan", "nama": "Uji"},
    )
    assert resp.status_code == 503
    assert "detail" in resp.json()
