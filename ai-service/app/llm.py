"""Pemilihan klien LLM: **Gemini (utama) → Ollama (cadangan)**.

Modul ini menyediakan klien tunggal yang dipakai seluruh layanan (gelung agen,
ringkasan, narasi, validasi). Bila ``AI_GEMINI_API_KEY`` diisi, panggilan model
pergi ke Google Gemini lebih dulu; bila Gemini gagal/tidak tersedia, otomatis
jatuh ke Ollama (``qwen2.5:7b`` → ``qwen2.5:3b``). Bila keduanya mati,
:class:`ModelUnavailable` dilempar — router memetakannya ke HTTP 503 (atau
mode deterministik bila ``AI_DEGRADE_DETERMINISTIC=true``).
"""

from __future__ import annotations

import logging
from typing import Any

from .config import get_settings
from .gemini_client import GeminiClient
from .ollama_client import ChatResult, ModelUnavailable, OllamaClient

log = logging.getLogger("ai-service.llm")

__all__ = ["ChatResult", "ModelUnavailable", "RantaiKlien", "get_client", "tutup_client"]


class RantaiKlien:
    """Coba beberapa klien LLM berurutan; berhenti di klien pertama yang berhasil."""

    def __init__(self, klien: list[Any]) -> None:
        self._klien = list(klien)

    def daftar(self) -> list[Any]:
        """Klien dalam rantai, berurutan utama lalu cadangan."""
        return list(self._klien)

    def nama_penyedia(self, klien: Any) -> str:
        return "gemini" if isinstance(klien, GeminiClient) else "ollama"

    async def chat(self, messages: list[dict[str, Any]], **kwargs: Any) -> ChatResult:
        """Kirim percakapan ke klien pertama yang berhasil."""
        galat_terakhir: Exception | None = None
        for klien in self._klien:
            try:
                return await klien.chat(messages, **kwargs)
            except ModelUnavailable as exc:
                galat_terakhir = exc
                log.warning(
                    "Penyedia %s gagal, coba cadangan berikutnya: %s",
                    self.nama_penyedia(klien),
                    exc,
                )
        raise ModelUnavailable(
            str(galat_terakhir) if galat_terakhir else "Tidak ada klien LLM yang dikonfigurasi."
        )

    async def health(self) -> dict[str, Any]:
        """Gabungkan status seluruh klien untuk endpoint ``/health``."""
        info: dict[str, Any] = {
            "reachable": False,
            "models": [],
            "model": "",
            "model_tersedia": False,
            "gemini": False,
            "ollama": False,
        }
        models: list[str] = []
        for klien in self._klien:
            nama = self.nama_penyedia(klien)
            try:
                sub = await klien.health()
            except Exception as exc:  # noqa: BLE001 - kesehatan tidak boleh melempar
                log.debug("Cek kesehatan %s gagal: %s", nama, exc)
                continue
            info[nama] = bool(sub.get("reachable"))
            for m in sub.get("models") or []:
                if m and m not in models:
                    models.append(m)
            if sub.get("reachable") and not info["reachable"]:
                info["reachable"] = True
                info["model"] = str(sub.get("model") or "")
                info["model_tersedia"] = bool(sub.get("model_tersedia"))
        info["models"] = models
        if not info["model"] and models:
            info["model"] = models[0]
        return info

    async def tutup(self) -> None:
        """Tutup seluruh klien HTTP dalam rantai."""
        for klien in self._klien:
            tutup = getattr(klien, "tutup", None)
            if tutup is None:
                continue
            try:
                await tutup()
            except Exception as exc:  # noqa: BLE001 - penutupan tidak boleh melempar
                log.debug("Gagal menutup klien %s: %s", self.nama_penyedia(klien), exc)


def bangun_rantai() -> RantaiKlien:
    """Susun rantai klien sesuai konfigurasi (Gemini dulu, lalu Ollama)."""
    settings = get_settings()
    klien: list[Any] = []
    if settings.ai_gemini_api_key.strip():
        klien.append(GeminiClient(settings))
        log.info(
            "Gemini aktif (model utama=%s, cadangan=%s)",
            settings.ai_gemini_model,
            settings.ai_gemini_model_fallback or "-",
        )
    # Ollama selalu disertakan sebagai cadangan lokal; bila mati ia gagal cepat
    # (connection refused) dan rantai sudah menanganinya.
    klien.append(OllamaClient(settings))
    return RantaiKlien(klien)


def model_utama() -> str:
    """Nama model yang dipakai pertama (untuk info & log)."""
    settings = get_settings()
    if settings.ai_gemini_api_key.strip():
        return settings.ai_gemini_model
    return settings.ai_model


# Klien tunggal untuk dipakai seluruh aplikasi (dibuat malas).
_rantai_tunggal: RantaiKlien | None = None


def get_client() -> RantaiKlien:
    """Kembalikan rantai klien LLM bersama."""
    global _rantai_tunggal
    if _rantai_tunggal is None:
        _rantai_tunggal = bangun_rantai()
    return _rantai_tunggal


async def tutup_client() -> None:
    """Tutup rantai klien bersama (dipanggil saat shutdown aplikasi)."""
    global _rantai_tunggal
    if _rantai_tunggal is not None:
        await _rantai_tunggal.tutup()
        _rantai_tunggal = None
