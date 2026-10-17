"""Klien HTTP asinkron ke Ollama (API native ``/api``).

Memakai endpoint native ``/api/chat`` karena mendukung *function calling*
(tools) dengan baik untuk Qwen 2.5. Menangani timeout, percobaan ulang, dan
penurunan model otomatis (``qwen2.5:7b`` -> ``qwen2.5:3b``) bila memori sempit.
"""

from __future__ import annotations

import asyncio
import logging
from dataclasses import dataclass, field
from typing import Any

import httpx

from .config import Settings, get_settings

log = logging.getLogger("ai-service.ollama")


class ModelUnavailable(RuntimeError):
    """Ollama tidak dapat dijangkau atau tidak ada model yang bisa dipakai."""


class _Retryable(Exception):
    """Galat transien yang layak dicoba ulang."""


class _ModelTidakAda(Exception):
    """Model tidak tersedia (404 / memori tidak cukup) — coba model berikutnya."""


@dataclass
class ChatResult:
    """Hasil satu panggilan ``/api/chat``."""

    content: str = ""
    tool_calls: list[dict[str, Any]] = field(default_factory=list)
    model: str = ""
    raw: dict[str, Any] = field(default_factory=dict)

    @property
    def message(self) -> dict[str, Any]:
        """Pesan asisten dalam format yang siap dikirim ulang ke Ollama."""
        pesan: dict[str, Any] = {"role": "assistant", "content": self.content}
        if self.tool_calls:
            pesan["tool_calls"] = self.tool_calls
        return pesan


class OllamaClient:
    """Pembungkus tipis di atas Ollama dengan penurunan model otomatis."""

    def __init__(self, settings: Settings | None = None) -> None:
        self.settings = settings or get_settings()
        self._client: httpx.AsyncClient | None = None

    # ------------------------------------------------------------------
    # Siklus hidup
    # ------------------------------------------------------------------
    def _httpx(self) -> httpx.AsyncClient:
        if self._client is None or self._client.is_closed:
            self._client = httpx.AsyncClient(
                base_url=self.settings.ai_ollama_base_url.rstrip("/"),
                timeout=httpx.Timeout(
                    self.settings.ai_request_timeout,
                    connect=self.settings.ai_connect_timeout,
                ),
            )
        return self._client

    async def tutup(self) -> None:
        """Tutup koneksi HTTP (dipanggil saat shutdown)."""
        if self._client is not None and not self._client.is_closed:
            await self._client.aclose()
        self._client = None

    # ------------------------------------------------------------------
    # Kesehatan
    # ------------------------------------------------------------------
    async def tag(self) -> dict[str, Any]:
        """Kembalikan daftar model yang tersedia (``{}`` bila Ollama mati)."""
        try:
            resp = await self._httpx().get(
                "/api/tags", timeout=self.settings.ai_health_timeout
            )
            resp.raise_for_status()
            return resp.json()
        except Exception as exc:  # noqa: BLE001 - kesehatan tidak boleh melempar
            log.debug("Ollama tidak dapat dijangkau: %s", exc)
            return {}

    async def health(self) -> dict[str, Any]:
        """Ringkasan status untuk endpoint ``/health``."""
        data = await self.tag()
        models = [str(m.get("name", "")) for m in data.get("models", [])] if data else []
        dipilih = self._pilih_model(models)
        return {
            "reachable": bool(data),
            "models": models,
            "model": dipilih[0] if dipilih else self.settings.ai_model,
            "model_tersedia": bool(dipilih),
        }

    def _pilih_model(self, models: list[str]) -> list[str]:
        """Model yang tersedia menurut Ollama, diurutkan utama lalu cadangan."""
        def normal(nama: str) -> str:
            return nama.split(":")[0].lower()

        tersedia: list[str] = []
        for kandidat in self.settings.model_names:
            for nama in models:
                if nama == kandidat or nama.startswith(kandidat.split(":")[0] + ":"):
                    if normal(nama) == normal(kandidat):
                        tersedia.append(nama)
                        break
        return tersedia

    # ------------------------------------------------------------------
    # Obrolan
    # ------------------------------------------------------------------
    async def chat(
        self,
        messages: list[dict[str, Any]],
        *,
        tools: list[dict[str, Any]] | None = None,
        model: str | None = None,
        format: str | dict[str, Any] | None = None,
        options: dict[str, Any] | None = None,
    ) -> ChatResult:
        """Kirim percakapan ke Ollama dan kembalikan :class:`ChatResult`.

        Raises:
            ModelUnavailable: bila seluruh model gagal dipanggil.
        """
        kandidat = [model] if model else self.settings.model_names
        galat_terakhir: Exception | None = None
        percobaan_total = 0

        for nama_model in kandidat:
            for percobaan in range(self.settings.ai_max_retries + 1):
                percobaan_total += 1
                try:
                    return await self._post_chat(
                        nama_model, messages, tools=tools, format=format, options=options
                    )
                except _ModelTidakAda as exc:
                    galat_terakhir = exc
                    log.warning("Model %s tidak tersedia: %s", nama_model, exc)
                    break  # lanjut ke model berikutnya
                except _Retryable as exc:
                    galat_terakhir = exc
                    log.warning(
                        "Panggilan Ollama gagal (model=%s, percobaan %d): %s",
                        nama_model,
                        percobaan + 1,
                        exc,
                    )
                    if percobaan < self.settings.ai_max_retries:
                        await asyncio.sleep(min(2.0 * (percobaan + 1), 6.0))

        raise ModelUnavailable(
            f"Tidak dapat menghubungi model Ollama ({', '.join(kandidat)}): {galat_terakhir}"
        )

    def _opsi(self, options: dict[str, Any] | None) -> dict[str, Any]:
        gabung: dict[str, Any] = {
            "num_ctx": self.settings.ai_num_ctx,
            "temperature": self.settings.ai_temperature,
            "top_p": self.settings.ai_top_p,
        }
        if options:
            gabung.update(options)
        return gabung

    async def _post_chat(
        self,
        model: str,
        messages: list[dict[str, Any]],
        *,
        tools: list[dict[str, Any]] | None,
        format: str | dict[str, Any] | None,
        options: dict[str, Any] | None,
    ) -> ChatResult:
        badan: dict[str, Any] = {
            "model": model,
            "messages": messages,
            "stream": False,
            "keep_alive": self.settings.ai_keep_alive,
            "options": self._opsi(options),
        }
        if tools:
            badan["tools"] = tools
        if format is not None:
            badan["format"] = format

        try:
            resp = await self._httpx().post("/api/chat", json=badan)
        except httpx.TimeoutException as exc:
            raise _Retryable(f"timeout setelah {self.settings.ai_request_timeout}s") from exc
        except httpx.TransportError as exc:
            raise _Retryable(f"galat transport: {exc}") from exc

        if resp.status_code == 404:
            raise _ModelTidakAda(f"model '{model}' tidak ditemukan (404)")
        if resp.status_code >= 500:
            teks = _ringkas(resp.text)
            if any(k in teks.lower() for k in ("memory", "not found", "no such model")):
                raise _ModelTidakAda(f"model '{model}' tidak dapat dimuat: {teks}")
            raise _Retryable(f"Ollama membalas {resp.status_code}: {teks}")
        if resp.status_code >= 400:
            raise ModelUnavailable(f"Ollama membalas {resp.status_code}: {_ringkas(resp.text)}")

        try:
            data = resp.json()
        except ValueError as exc:
            raise _Retryable("balasan Ollama bukan JSON") from exc

        if isinstance(data, dict) and data.get("error"):
            pesan = str(data["error"])
            if any(k in pesan.lower() for k in ("memory", "not found", "no such model")):
                raise _ModelTidakAda(pesan)
            raise _Retryable(pesan)

        pesan = (data or {}).get("message") or {}
        return ChatResult(
            content=str(pesan.get("content") or ""),
            tool_calls=_normalisasi_tool_calls(pesan.get("tool_calls")),
            model=str(data.get("model") or model),
            raw=data if isinstance(data, dict) else {},
        )


def _normalisasi_tool_calls(mentah: Any) -> list[dict[str, Any]]:
    """Rapikan ``tool_calls`` dari Ollama agar seragam (arguments selalu dict)."""
    if not isinstance(mentah, list):
        return []
    hasil: list[dict[str, Any]] = []
    for panggilan in mentah:
        if not isinstance(panggilan, dict):
            continue
        fungsi = panggilan.get("function") or {}
        if isinstance(fungsi, str):  # sebagian versi mengirim string JSON
            try:
                import json

                fungsi = json.loads(fungsi)
            except ValueError:
                fungsi = {}
        argumen = fungsi.get("arguments", {})
        if isinstance(argumen, str):
            try:
                import json

                argumen = json.loads(argumen) if argumen.strip() else {}
            except ValueError:
                argumen = {}
        if not isinstance(argumen, dict):
            argumen = {}
        hasil.append(
            {
                "function": {
                    "name": str(fungsi.get("name", "")),
                    "arguments": argumen,
                }
            }
        )
    return hasil


def _ringkas(teks: str, batas: int = 300) -> str:
    teks = (teks or "").strip().replace("\n", " ")
    return teks[:batas]


# Klien tunggal untuk dipakai seluruh aplikasi (dibuat malas).
_client_tunggal: OllamaClient | None = None


def get_client() -> OllamaClient:
    """Kembalikan klien Ollama bersama."""
    global _client_tunggal
    if _client_tunggal is None:
        _client_tunggal = OllamaClient()
    return _client_tunggal


async def tutup_client() -> None:
    """Tutup klien bersama (dipakai saat shutdown aplikasi)."""
    global _client_tunggal
    if _client_tunggal is not None:
        await _client_tunggal.tutup()
        _client_tunggal = None
