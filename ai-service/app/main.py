"""Titik masuk FastAPI untuk layanan AI Pelaporan Harian.

Jalankan (dari folder ``ai-service``)::

    uvicorn app.main:app --host 127.0.0.1 --port 8000

atau gunakan ``run.ps1`` / ``run.bat``.
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.staticfiles import StaticFiles

from . import __version__
from .auth import ServiceKeyMiddleware
from .config import get_settings
from .db import DatabaseError
from .ollama_client import ModelUnavailable, tutup_client
from .routers import chat, health, jobs, reports

log = logging.getLogger("ai-service.main")


def konfigurasi_log(level: str) -> None:
    """Atur logging dasar bila belum dikonfigurasi."""
    root = logging.getLogger()
    if root.handlers:
        return
    logging.basicConfig(
        level=getattr(logging, level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)-7s %(name)s: %(message)s",
    )


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Siklus hidup aplikasi: catat konfigurasi & tutup klien HTTP."""
    settings = get_settings()
    konfigurasi_log(settings.ai_log_level)
    log.info(
        "Layanan AI mulai — model=%s fallback=%s ollama=%s",
        settings.ai_model,
        settings.ai_model_fallback,
        settings.ai_ollama_base_url,
    )
    try:
        yield
    finally:
        await tutup_client()
        log.info("Layanan AI berhenti.")


def buat_app() -> FastAPI:
    """Bangun instansi :class:`FastAPI` lengkap dengan middleware & router."""
    settings = get_settings()

    app = FastAPI(
        title="Layanan AI Pelaporan Harian",
        description=(
            "Gateway Python/FastAPI antara aplikasi PHP dan Ollama (Qwen 2.5). "
            "Menyediakan chatbot asisten, ringkasan, narasi, validasi, ekspor, "
            "dan pemrosesan antrean ai_jobs."
        ),
        version=__version__,
        lifespan=lifespan,
    )

    # Middleware kunci layanan (X-Service-Key). /health dikecualikan.
    app.add_middleware(ServiceKeyMiddleware)

    # Handler galat terpusat -> selalu JSON {"detail": ...} agar PHP bisa membacanya.
    @app.exception_handler(DatabaseError)
    async def _handler_db(request: Request, exc: DatabaseError) -> JSONResponse:  # noqa: ANN001
        log.error("Galat basis data pada %s: %s", request.url.path, exc)
        return JSONResponse(status_code=503, content={"detail": f"Basis data tidak dapat diakses: {exc}"})

    @app.exception_handler(ModelUnavailable)
    async def _handler_model(request: Request, exc: ModelUnavailable) -> JSONResponse:  # noqa: ANN001
        log.warning("Model tidak tersedia pada %s: %s", request.url.path, exc)
        return JSONResponse(
            status_code=503,
            content={
                "detail": (
                    "Layanan AI (Ollama) sedang tidak tersedia. Pastikan Ollama berjalan dan "
                    "model sudah diunduh (ollama pull qwen2.5:7b)."
                )
            },
        )

    # Router
    app.include_router(health.router)
    app.include_router(chat.router)
    app.include_router(reports.router)
    app.include_router(jobs.router)

    # Berkas ekspor (.xlsx/.docx) — tetap dilindungi middleware kunci.
    export_dir = settings.export_dir_path
    export_dir.mkdir(parents=True, exist_ok=True)
    app.mount("/exports", StaticFiles(directory=str(export_dir)), name="exports")

    @app.get("/", include_in_schema=False)
    async def info() -> dict:
        """Info singkat layanan (tanpa rahasia)."""
        return {
            "ok": True,
            "layanan": "Layanan AI Pelaporan Harian",
            "versi": __version__,
            "model": settings.ai_model,
            "endpoint": ["/health", "/chat", "/summarize", "/narrative", "/validate", "/jobs"],
            "dokumentasi": "/docs",
        }

    return app


app = buat_app()


if __name__ == "__main__":  # pragma: no cover
    import uvicorn

    _s = get_settings()
    uvicorn.run("app.main:app", host=_s.ai_host, port=_s.ai_port, reload=False)
