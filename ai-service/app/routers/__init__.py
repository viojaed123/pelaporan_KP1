"""Router FastAPI: endpoint yang cocok dengan kontrak PHP."""

from __future__ import annotations

from . import chat, health, jobs, reports

__all__ = ["chat", "health", "jobs", "reports"]
