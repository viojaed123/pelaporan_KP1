"""Klien HTTP asinkron ke Google Gemini (REST API ``v1beta``).

Klien ini dirancang **setara kontrak** dengan :class:`app.ollama_client.OllamaClient`:
metode ``chat()`` menerima daftar pesan bergaya OpenAI (system/user/assistant/tool)
plus skema alat bergaya OpenAI, lalu mengembalikan :class:`ChatResult` yang sama.
Karena itu ``agent.py`` dan seluruh 21 alat tetap dapat dipakai tanpa perubahan.

Perbedaan penting dibanding Ollama (lihat dokumentasi Gemini API):

* Deklarasi alat memakai bentuk ``function_declarations`` (skema OpenAPI parsial;
  kunci ``additionalProperties`` dibuang karena didukung terbatas).
* Hasil alat dikirim sebagai bagian ``functionResponse`` pada peran ``user``.
* ``format="json"`` diterjemahkan menjadi ``generationConfig.responseMimeType``.
* Pencarian web bawaan Google (``tools: [{"google_search": {}}]``) tersedia
  lewat fungsi :func:`pencarian_google` — dipakai alat ``web_search``.
"""

from __future__ import annotations

import asyncio
import copy
import json
import logging
from typing import Any

import httpx

from .config import Settings, get_settings
from .ollama_client import ChatResult, ModelUnavailable

log = logging.getLogger("ai-service.gemini")

#: Kunci skema yang tidak didukung Gemini dan wajib dibuang.
BUANG_SKEMA = {"additionalProperties", "$schema", "title", "default"}


class _Retryable(Exception):
    """Galat transien (429/5xx/timeout) yang layak dicoba ulang."""


class _ModelTidakAda(Exception):
    """Model tidak ditemukan/tidak dapat dipakai — coba model berikutnya."""


# ---------------------------------------------------------------------------
# Terjemahan pesan & skema (fungsi murni, mudah diuji)
# ---------------------------------------------------------------------------
def _ke_sistem(messages: list[dict[str, Any]]) -> str:
    """Gabungkan seluruh pesan ``system`` menjadi satu instruksi sistem."""
    bagian = [str(m.get("content") or "") for m in messages if m.get("role") == "system"]
    return "\n\n".join(b for b in bagian if b.strip())


def _ke_contents(messages: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Terjemahkan pesan OpenAI-style menjadi ``contents`` Gemini.

    * ``user`` -> peran ``user`` (teks)
    * ``assistant`` + ``tool_calls`` -> peran ``model`` berisi ``functionCall``
    * ``tool`` -> peran ``user`` berisi ``functionResponse`` (nama diambil dari
      ``tool_name`` bila ada, atau dari antrean panggilan model sebelumnya)
    """
    isi: list[dict[str, Any]] = []
    antrean_panggilan: list[str] = []  # FIFO nama alat dari bagian functionCall model

    for pesan in messages:
        peran = str(pesan.get("role") or "")
        if peran == "system":
            continue

        parts: list[dict[str, Any]] = []
        teks = str(pesan.get("content") or "")
        if teks and peran != "tool":
            # Hasil alat tidak dikirim sebagai teks mentah — lihat cabang "tool".
            parts.append({"text": teks})

        if peran == "assistant":
            for panggilan in pesan.get("tool_calls") or []:
                fungsi = (panggilan or {}).get("function") or {}
                nama = str(fungsi.get("name") or "")
                argumen = fungsi.get("arguments") or {}
                if isinstance(argumen, str):
                    try:
                        argumen = json.loads(argumen) if argumen.strip() else {}
                    except ValueError:
                        argumen = {}
                if not nama:
                    continue
                if not isinstance(argumen, dict):
                    argumen = {}
                antrean_panggilan.append(nama)
                part_panggilan: dict[str, Any] = {
                    "functionCall": {"name": nama, "args": argumen}
                }
                # Gemini 3.x mensyaratkan `thoughtSignature` asli dipertahankan
                # pada bagian functionCall saat percakapan dikirim ulang.
                tanda = panggilan.get("thought_signature")
                if tanda:
                    part_panggilan["thoughtSignature"] = tanda
                parts.append(part_panggilan)

        elif peran == "tool":
            nama = str(pesan.get("tool_name") or "")
            if antrean_panggilan:
                # Selalu konsumsi satu entri agar antrean selaras dengan
                # jumlah hasil alat; pakai bila `tool_name` tidak diisi.
                nama = nama or antrean_panggilan.pop(0)
            nama = nama or "alat"
            try:
                respons = json.loads(teks) if teks.strip() else {}
            except ValueError:
                respons = {"hasil": teks}
            if not isinstance(respons, dict):
                respons = {"hasil": respons}
            parts.append({"functionResponse": {"name": nama, "response": respons}})

        if not parts:
            continue
        if peran == "tool":
            tujuan = "user"  # Gemini menerima functionResponse pada peran user
        elif peran == "assistant":
            tujuan = "model"
        else:
            tujuan = peran
        isi.append({"role": tujuan, "parts": parts})

    return isi


def _rapikan_skema(skema: Any) -> Any:
    """Salin rekursif skema JSON; buang kunci yang tidak didukung Gemini."""
    if isinstance(skema, dict):
        hasil: dict[str, Any] = {}
        for kunci, nilai in skema.items():
            if kunci in BUANG_SKEMA:
                continue
            hasil[kunci] = _rapikan_skema(nilai)
        return hasil
    if isinstance(skema, list):
        return [_rapikan_skema(v) for v in skema]
    return skema


def _ke_tools(tools: list[dict[str, Any]] | None) -> list[dict[str, Any]]:
    """Terjemahkan skema alat OpenAI-style menjadi ``tools`` Gemini."""
    deklarasi: list[dict[str, Any]] = []
    for alat in tools or []:
        if not isinstance(alat, dict):
            continue
        fungsi = alat.get("function") or {}
        nama = str(fungsi.get("name") or "")
        if not nama:
            continue
        decl: dict[str, Any] = {"name": nama}
        if fungsi.get("description"):
            decl["description"] = str(fungsi["description"])
        if fungsi.get("parameters"):
            decl["parameters"] = _rapikan_skema(fungsi["parameters"])
        deklarasi.append(decl)
    return [{"function_declarations": deklarasi}] if deklarasi else []


def _baca_respon(data: dict[str, Any], model: str) -> ChatResult:
    """Ubah balasan ``generateContent`` menjadi :class:`ChatResult`."""
    kandidat = (data.get("candidates") or [{}])[0]
    isi = kandidat.get("content") or {}
    teks: list[str] = []
    panggilan: list[dict[str, Any]] = []

    for part in isi.get("parts") or []:
        if not isinstance(part, dict):
            continue
        if part.get("text"):
            teks.append(str(part["text"]))
        fc = part.get("functionCall")
        if isinstance(fc, dict) and fc.get("name"):
            argumen = fc.get("args") or {}
            # Gemini 3.x dapat menamai fungsi berprefix "default_api:";
            # normalkan agar cocok dengan deklarasi alat (nama asli).
            nama = str(fc["name"])
            if nama.startswith("default_api:"):
                nama = nama[len("default_api:"):]
            entri: dict[str, Any] = {
                "function": {
                    "name": nama,
                    "arguments": argumen if isinstance(argumen, dict) else {},
                }
            }
            tanda = part.get("thoughtSignature")
            if tanda:
                # Wajib dikirim ulang pada putaran berikutnya (lihat _ke_contents).
                entri["thought_signature"] = str(tanda)
            panggilan.append(entri)

    alasan = str(kandidat.get("finishReason") or "")
    if not teks and not panggilan and alasan:
        log.warning("Balasan Gemini tanpa isi (finishReason=%s)", alasan)

    return ChatResult(
        content="".join(teks),
        tool_calls=panggilan,
        model=str(data.get("modelVersion") or model),
        raw=data if isinstance(data, dict) else {},
    )


# ---------------------------------------------------------------------------
# Klien
# ---------------------------------------------------------------------------
class GeminiClient:
    """Pembungkus tipis REST Gemini dengan penurunan model otomatis."""

    def __init__(self, settings: Settings | None = None) -> None:
        self.settings = settings or get_settings()
        self._client: httpx.AsyncClient | None = None

    # -- dasar ---------------------------------------------------------------
    @property
    def _basis(self) -> str:
        return (self.settings.ai_gemini_base_url or "").rstrip("/")

    def _url(self, model: str, metode: str = "generateContent") -> str:
        return f"{self._basis}/models/{model}:{metode}"

    def _header(self) -> dict[str, str]:
        return {"x-goog-api-key": self.settings.ai_gemini_api_key.strip()}

    @property
    def _model_utama(self) -> str:
        return self.settings.ai_gemini_model

    # -- siklus hidup --------------------------------------------------------
    def _httpx(self) -> httpx.AsyncClient:
        if self._client is None or self._client.is_closed:
            self._client = httpx.AsyncClient(
                timeout=httpx.Timeout(
                    self.settings.ai_request_timeout,
                    connect=self.settings.ai_connect_timeout,
                ),
            )
        return self._client

    async def tutup(self) -> None:
        if self._client is not None and not self._client.is_closed:
            await self._client.aclose()
        self._client = None

    # -- kesehatan -----------------------------------------------------------
    async def health(self) -> dict[str, Any]:
        """Cek ketersediaan API (``GET /models``) untuk endpoint ``/health``."""
        if not self.settings.ai_gemini_api_key.strip():
            return {
                "reachable": False,
                "models": [],
                "model": self._model_utama,
                "model_tersedia": False,
            }
        try:
            resp = await self._httpx().get(
                f"{self._basis}/models",
                params={"pageSize": 1},
                headers=self._header(),
                timeout=self.settings.ai_health_timeout,
            )
            ok = resp.status_code == 200
        except Exception as exc:  # noqa: BLE001 - kesehatan tidak boleh melempar
            log.debug("Gemini tidak dapat dijangkau: %s", exc)
            ok = False
        return {
            "reachable": ok,
            "models": [self._model_utama] if ok else [],
            "model": self._model_utama,
            "model_tersedia": ok,
        }

    # -- obrolan -------------------------------------------------------------
    async def chat(
        self,
        messages: list[dict[str, Any]],
        *,
        tools: list[dict[str, Any]] | None = None,
        model: str | None = None,
        format: str | dict[str, Any] | None = None,  # noqa: A002 - kontrak seragam
        options: dict[str, Any] | None = None,  # noqa: ARG002 - diabaikan (Gemini)
    ) -> ChatResult:
        """Kirim percakapan ke Gemini dan kembalikan :class:`ChatResult`.

        Raises:
            ModelUnavailable: bila seluruh kandidat model gagal dipanggil.
        """
        s = self.settings
        kandidat = [model] if model else [
            m for m in (s.ai_gemini_model, s.ai_gemini_model_fallback) if m
        ]
        kandidat = list(dict.fromkeys(kandidat))  # hilangkan duplikat, urutan terjaga
        galat_terakhir: Exception | None = None

        for nama_model in kandidat:
            for percobaan in range(s.ai_max_retries + 1):
                try:
                    return await self._post_chat(
                        nama_model, messages, tools=tools, format=format
                    )
                except _ModelTidakAda as exc:
                    galat_terakhir = exc
                    log.warning("Model Gemini %s tidak tersedia: %s", nama_model, exc)
                    break  # lanjut ke model berikutnya
                except _Retryable as exc:
                    galat_terakhir = exc
                    log.warning(
                        "Panggilan Gemini gagal (model=%s, percobaan %d): %s",
                        nama_model,
                        percobaan + 1,
                        exc,
                    )
                    if percobaan < s.ai_max_retries:
                        await asyncio.sleep(min(2.0 * (percobaan + 1), 6.0))

        raise ModelUnavailable(
            f"Tidak dapat menghubungi model Gemini ({', '.join(kandidat)}): {galat_terakhir}"
        )

    async def _post_chat(
        self,
        model: str,
        messages: list[dict[str, Any]],
        *,
        tools: list[dict[str, Any]] | None,
        format: str | dict[str, Any] | None,  # noqa: A002
    ) -> ChatResult:
        badan: dict[str, Any] = {
            "contents": _ke_contents(messages),
            "generationConfig": {
                "temperature": self.settings.ai_temperature,
                "topP": self.settings.ai_top_p,
            },
        }
        sistem = _ke_sistem(messages)
        if sistem:
            badan["systemInstruction"] = {"parts": [{"text": sistem}]}
        alat = _ke_tools(tools)
        if alat:
            badan["tools"] = alat
        if format == "json":
            badan["generationConfig"]["responseMimeType"] = "application/json"

        try:
            resp = await self._httpx().post(
                self._url(model), json=badan, headers=self._header()
            )
        except httpx.TimeoutException as exc:
            raise _Retryable(
                f"timeout setelah {self.settings.ai_request_timeout}s"
            ) from exc
        except httpx.TransportError as exc:
            raise _Retryable(f"galat transport: {exc}") from exc

        if resp.status_code == 404:
            raise _ModelTidakAda(f"model '{model}' tidak ditemukan (404)")
        if resp.status_code == 429:
            raise _Retryable("batas laju (rate limit) tercapai (429)")
        if resp.status_code >= 500:
            raise _Retryable(f"Gemini membalas {resp.status_code}: {_ringkas(resp.text)}")
        if resp.status_code >= 400:
            teks = _ringkas(resp.text)
            if "api_key_invalid" in teks.lower() or "api key not valid" in teks.lower():
                raise ModelUnavailable(
                    "Kunci API Gemini ditolak (API_KEY_INVALID) — periksa AI_GEMINI_API_KEY."
                )
            raise ModelUnavailable(f"Gemini membalas {resp.status_code}: {teks}")

        try:
            data = resp.json()
        except ValueError as exc:
            raise _Retryable("balasan Gemini bukan JSON") from exc

        if isinstance(data, dict) and data.get("error"):
            raise _Retryable(f"galat Gemini: {data['error']}")

        blokir = (data.get("promptFeedback") or {}).get("blockReason")
        if blokir:
            log.warning("Permintaan Gemini diblokir: %s", blokir)
            return ChatResult(content="", model=model, raw=data if isinstance(data, dict) else {})

        return _baca_respon(data if isinstance(data, dict) else {}, model)


# ---------------------------------------------------------------------------
# Pencarian web bawaan Google (grounding) — dipakai alat ``web_search``
# ---------------------------------------------------------------------------
def pencarian_google(kueri: str, jumlah: int = 5, bahasa: str = "") -> dict[str, Any]:
    """Cari lewat **Google Search grounding** bawaan Gemini (sinkron).

    Mengembalikan ``{"hasil": [{judul, tautan, ringkasan, sumber}], "jawaban": str}``.
    Melempar Exception bila kunci kosong / panggilan gagal — pemanggil
    (alat ``web_search``) menyusul mencoba SearXNG lalu DuckDuckGo.
    """
    settings = get_settings()
    if not settings.ai_gemini_api_key.strip():
        raise RuntimeError("AI_GEMINI_API_KEY kosong")

    teks_kueri = kueri.strip()
    if bahasa:
        teks_kueri = f"Cari dan jawab dalam bahasa '{bahasa}': {teks_kueri}"

    badan = {
        "contents": [{"role": "user", "parts": [{"text": teks_kueri}]}],
        "tools": [{"google_search": {}}],
        "generationConfig": {"temperature": 0.0},
    }
    url = f"{settings.ai_gemini_base_url.rstrip('/')}/models/{settings.ai_gemini_search_model}:generateContent"

    ca = (settings.ai_http_ca_bundle or "").strip() or True
    with httpx.Client(
        timeout=settings.ai_gemini_search_timeout,
        follow_redirects=True,
        verify=ca,
    ) as klien:
        resp = klien.post(url, json=badan, headers={"x-goog-api-key": settings.ai_gemini_api_key.strip()})
        resp.raise_for_status()
        data = resp.json()

    kandidat = (data.get("candidates") or [{}])[0]
    isi = kandidat.get("content") or {}
    jawaban = "".join(
        str(p.get("text") or "") for p in (isi.get("parts") or []) if isinstance(p, dict)
    ).strip()

    meta = kandidat.get("groundingMetadata") or {}
    potongan = [p for p in (meta.get("groundingChunks") or []) if isinstance(p, dict)]
    dukungan = [d for d in (meta.get("groundingSupports") or []) if isinstance(d, dict)]

    # Potongan teks jawaban -> indeks chunk sumbernya.
    cuplikan: dict[int, list[str]] = {}
    for d in dukungan:
        seg = str((d.get("segment") or {}).get("text") or "").strip()
        if not seg:
            continue
        for idx in d.get("groundingChunkIndices") or []:
            try:
                cuplikan.setdefault(int(idx), []).append(seg)
            except (TypeError, ValueError):
                continue

    hasil: list[dict[str, Any]] = []
    for i, chunk in enumerate(potongan[: max(1, jumlah)]):
        web = chunk.get("web") or {}
        tautan = str(web.get("uri") or "").strip()
        if not tautan:
            continue
        hasil.append(
            {
                "judul": str(web.get("title") or "").strip(),
                "tautan": tautan,
                "ringkasan": " ".join(cuplikan.get(i, []))[:500],
                "sumber": "google-search",
            }
        )

    return {"hasil": hasil, "jawaban": jawaban}


def _ringkas(teks: str, batas: int = 300) -> str:
    teks = (teks or "").strip().replace("\n", " ")
    return teks[:batas]
