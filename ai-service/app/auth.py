"""Middleware kunci layanan (``X-Service-Key``).

Setiap permintaan **kecuali** ``/health`` harus menyertakan header
``X-Service-Key`` yang sama dengan konfigurasi. Permintaan tanpa kunci yang sah
dijawab ``401`` dengan bentuk JSON ``{"detail": ...}``.
"""

from __future__ import annotations

import logging
import secrets
from typing import Callable

from fastapi import Header, HTTPException, status
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response

from .config import Settings, get_settings

log = logging.getLogger("ai-service.auth")

#: Jalur yang selalu boleh diakses tanpa kunci.
JALUR_PUBLIK: frozenset[str] = frozenset({"/health", "/health/"})

#: Jalur dokumentasi (hanya publik bila ``AI_DOCS_PUBLIC=true``).
JALUR_DOKS: frozenset[str] = frozenset({"/docs", "/redoc", "/openapi.json", "/docs/oauth2-redirect"})


def kunci_sah(diberikan: str | None, settings: Settings | None = None) -> bool:
    """Bandingkan kunci header dengan kunci konfigurasi secara konstan-waktu."""
    settings = settings or get_settings()
    if not diberikan:
        return False
    return secrets.compare_digest(diberikan, settings.ai_service_key)


def jalur_publik(path: str, settings: Settings | None = None) -> bool:
    """True bila ``path`` tidak memerlukan kunci layanan."""
    settings = settings or get_settings()
    bersih = path.rstrip("/") or "/"
    if path in JALUR_PUBLIK or bersih in {"/health", "/"}:
        # "/" dikembalikan sebagai info layanan tanpa kunci hanya untuk kenyamanan.
        return bersih in {"/health", "/"}
    if settings.ai_docs_public and path in JALUR_DOKS:
        return True
    return False


class ServiceKeyMiddleware(BaseHTTPMiddleware):
    """Tolak permintaan yang tidak membawa ``X-Service-Key`` yang benar."""

    async def dispatch(
        self, request: Request, call_next: Callable[[Request], object]
    ) -> Response:
        settings = get_settings()
        if jalur_publik(request.url.path, settings):
            return await call_next(request)  # type: ignore[arg-type]

        diberikan = request.headers.get("X-Service-Key")
        if not kunci_sah(diberikan, settings):
            log.info("Permintaan ditolak (kunci tidak valid): %s %s", request.method, request.url.path)
            return JSONResponse(
                status_code=status.HTTP_401_UNAUTHORIZED,
                content={"detail": "Kunci layanan (X-Service-Key) tidak valid atau tidak ada."},
            )

        return await call_next(request)  # type: ignore[arg-type]


async def require_service_key(
    x_service_key: str | None = Header(default=None, alias="X-Service-Key"),
) -> None:
    """Dependensi alternatif untuk rute yang perlu pemeriksaan eksplisit."""
    if not kunci_sah(x_service_key):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Kunci layanan (X-Service-Key) tidak valid atau tidak ada.",
        )
