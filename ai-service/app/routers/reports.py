"""Router ``/summarize``, ``/narrative``, ``/validate``.

Ketiganya mengembalikan ``balasan`` (untuk ditampilkan) sekaligus data
terstruktur. Bila Ollama tidak tersedia: default mengembalikan HTTP 503 dengan
``detail``; set ``AI_DEGRADE_DETERMINISTIK=true`` untuk memakai hasil
deterministik (tanpa model) sebagai gantinya.
"""

from __future__ import annotations

import logging
from datetime import date
from typing import Any

from fastapi import APIRouter

from ..config import get_settings
from ..db import DatabaseError
from ..ollama_client import ChatResult, ModelUnavailable
from ..prompts import domain, render
from ..schemas import (
    NarrativeRequest,
    NarrativeResponse,
    SummarizeRequest,
    SummarizeResponse,
    ValidateRequest,
    ValidateResponse,
)
from ..services.reporting import (
    bangun_ringkasan,
    ke_tanggal,
    validasi_many,
)
from ..tools import ToolContext
from ..tools import analysis as alat_analisis
from ..tools import summaries as alat_ringkas
from ..tools.data import ambil_laporan, rentang_default, scope_user
from .common import cegah_galat_db, galat_layanan, konteks_dari, minta_narasi

log = logging.getLogger("ai-service.reports")

router = APIRouter(tags=["laporan"])


# ---------------------------------------------------------------------------
# Pembantu
# ---------------------------------------------------------------------------
def _uid(parameter: dict[str, Any]) -> int | None:
    nilai = parameter.get("user_id")
    try:
        return int(nilai) if nilai else None
    except (TypeError, ValueError):
        return None


def _bangun_ringkasan(ctx: ToolContext, jenis: str, parameter: dict[str, Any]) -> dict[str, Any]:
    """Pilih builder ringkasan sesuai ``jenis`` dan kembalikan data terstruktur."""
    j = (jenis or "").strip().lower()

    if j in ("minggu", "mingguan", "weekly"):
        data = alat_ringkas.weekly_summary(ctx, parameter)
        return {**data, "jenis": "mingguan"}
    if j in ("bulan", "bulanan", "monthly"):
        data = alat_ringkas.monthly_summary(ctx, parameter)
        return {**data, "jenis": "bulanan"}

    # Harian / periode generik
    if j in ("hari", "harian", "daily"):
        acuan = ke_tanggal(parameter.get("tanggal_acuan")) or date.today()
        dari = sampai = acuan
        jenis_label = "harian"
    else:
        dari, sampai = rentang_default(parameter.get("tanggal_dari"), parameter.get("tanggal_sampai"))
        jenis_label = "periode"

    uid = _uid(parameter)
    rows = ambil_laporan(ctx, dari=dari, sampai=sampai, user_id=uid)
    _, label = scope_user(ctx, uid)
    ringkas = bangun_ringkasan(
        rows, dari=dari, sampai=sampai, jenis=jenis_label, nama=ctx.nama if not uid else label
    )
    return {
        "ok": True,
        "jenis": jenis_label,
        "untuk": label,
        "periode": ringkas["periode"],
        "statistik": ringkas["statistik"],
        "tabel": ringkas["tabel"],
        "kegiatan_utama": ringkas["kegiatan_utama"],
        "kendala": ringkas["kendala"],
        "ringkasan": ringkas["narasi"],
    }


def _teks_validasi(verdict: dict[str, Any], rincian: list[dict[str, Any]] | None = None) -> str:
    """Susun teks validasi deterministik (dipakai sebagai cadangan)."""
    lengkap = verdict.get("lengkap")
    skor = verdict.get("skor")
    potongan = [
        f"Hasil pemeriksaan: {'LENGKAP' if lengkap else 'PERLU PERBAIKAN'}"
        + (f" (skor kelengkapan {skor}/100)." if skor is not None else ".")
    ]
    galat = verdict.get("galat") or {}
    if galat:
        potongan.append("Bagian yang perlu diperbaiki: " + "; ".join(f"{k}: {v}" for k, v in galat.items()) + ".")
    peringatan = verdict.get("peringatan") or []
    if peringatan:
        potongan.append("Catatan tambahan: " + "; ".join(str(p) for p in peringatan) + ".")
    if rincian:
        kurang = [r for r in rincian if not r.get("lengkap")]
        if kurang:
            potongan.append(f"Terdapat {len(kurang)} laporan yang belum lengkap dari total {len(rincian)}.")
    if lengkap and not galat:
        potongan.append("Laporan sudah memuat seluruh bagian wajib. Pertahankan kelengkapannya.")
    return " ".join(potongan)


# ---------------------------------------------------------------------------
# /summarize
# ---------------------------------------------------------------------------
@router.post("/summarize", response_model=SummarizeResponse, summary="Ringkasan mingguan/bulanan")
async def summarize(request: SummarizeRequest) -> SummarizeResponse:
    """Buat ringkasan kegiatan (mingguan/bulanan/harian/periode).

    Body::

        {"jenis": "mingguan", "parameter": {"user_id": 1, "role": "karyawan"}}
    """
    settings = get_settings()
    parameter = dict(request.parameter or {})
    ctx = konteks_dari(parameter)

    try:
        data = _bangun_ringkasan(ctx, request.jenis, parameter)
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc

    ringkasan_det: str = data.get("ringkasan") or ""
    balasan = ringkasan_det
    mode = "deterministik"
    model: str | None = None

    try:
        hasil: ChatResult = await minta_narasi(
            render("system_summary", domain=domain()),
            "Susun ringkasan kegiatan kegiatan berikut menjadi paragraf Bahasa Indonesia "
            "yang rapi. Gunakan hanya data yang diberikan.",
            {
                "periode": data.get("periode"),
                "statistik": data.get("statistik"),
                "kegiatan_utama": data.get("kegiatan_utama"),
                "kendala": data.get("kendala"),
            },
        )
        if hasil.content.strip():
            balasan = hasil.content.strip()
            mode = "model"
            model = hasil.model or None
    except ModelUnavailable as exc:
        if not settings.ai_degrade_deterministic:
            raise galat_layanan(
                "Layanan AI (Ollama) sedang tidak tersedia sehingga ringkasan tidak dapat disusun."
            ) from exc
        log.warning("Ollama mati; memakai ringkasan deterministik: %s", exc)

    return SummarizeResponse(
        ok=True,
        jenis=str(data.get("jenis") or request.jenis),
        balasan=balasan,
        ringkasan=ringkasan_det,
        periode=data.get("periode") or {},
        statistik=data.get("statistik") or {},
        tabel=data.get("tabel") or [],
        model=model,
        mode=mode,
    )


# ---------------------------------------------------------------------------
# /narrative
# ---------------------------------------------------------------------------
@router.post("/narrative", response_model=NarrativeResponse, summary="Narasi laporan")
async def narrative(request: NarrativeRequest) -> NarrativeResponse:
    """Susun narasi laporan panjang (bahan Word/PDF)."""
    settings = get_settings()
    parameter = dict(request.parameter or {})
    ctx = konteks_dari(parameter)

    try:
        data = alat_ringkas.narrative_report(ctx, parameter)
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc

    narasi_det: str = data.get("narasi") or ""
    balasan = narasi_det
    mode = "deterministik"
    model: str | None = None

    try:
        hasil: ChatResult = await minta_narasi(
            render("system_narrative", domain=domain()),
            "Susun narasi laporan kegiatan kegiatan berikut menjadi laporan formal Bahasa "
            "Indonesia. Gunakan hanya data yang diberikan.",
            {
                "judul": data.get("judul"),
                "periode": data.get("periode"),
                "statistik": data.get("statistik"),
                "kendala": data.get("kendala"),
            },
        )
        if hasil.content.strip():
            balasan = hasil.content.strip()
            mode = "model"
            model = hasil.model or None
    except ModelUnavailable as exc:
        if not settings.ai_degrade_deterministic:
            raise galat_layanan(
                "Layanan AI (Ollama) sedang tidak tersedia sehingga narasi tidak dapat disusun."
            ) from exc
        log.warning("Ollama mati; memakai narasi deterministik: %s", exc)

    return NarrativeResponse(
        ok=True,
        balasan=balasan,
        narasi=narasi_det,
        parameter=parameter,
        statistik=data.get("statistik") or {},
        model=model,
        mode=mode,
    )


# ---------------------------------------------------------------------------
# /validate
# ---------------------------------------------------------------------------
@router.post("/validate", response_model=ValidateResponse, summary="Validasi kelengkapan laporan")
async def validate(request: ValidateRequest) -> ValidateResponse:
    """Periksa kelengkapan laporan (satu objek, daftar, atau periode).

    Body contoh (satu laporan)::

        {"laporan": {"tanggal": "2026-10-14", "kegiatan": "...", "deskripsi": "..."}}

    Body contoh (periode)::

        {"laporan": {"user_id": 1, "role": "karyawan",
                     "tanggal_dari": "2026-10-01", "tanggal_sampai": "2026-10-31"}}
    """
    settings = get_settings()
    laporan = request.laporan

    try:
        if isinstance(laporan, list):
            agregat = validasi_many(laporan)
            verdict = {
                "lengkap": agregat["jumlah"] > 0 and agregat["jumlah_perlu_perbaikan"] == 0,
                "verdict": "LENGKAP" if agregat["jumlah_perlu_perbaikan"] == 0 and agregat["jumlah"] > 0 else "PERLU PERBAIKAN",
                "skor": agregat["skor_rata_rata"],
                "galat": {},
                "peringatan": [],
                "jumlah": agregat["jumlah"],
                "jumlah_lengkap": agregat["jumlah_lengkap"],
                "jumlah_perlu_perbaikan": agregat["jumlah_perlu_perbaikan"],
            }
            rincian = agregat["rincian"]
            jumlah = agregat["jumlah"]
        else:
            parameter = dict(laporan) if isinstance(laporan, dict) else {}
            ctx = konteks_dari(parameter)
            if parameter and not any(k in parameter for k in ("tanggal_dari", "tanggal_sampai", "laporan_id")):
                # Dianggap satu objek laporan
                hasil = alat_analisis.validate_report(ctx, {"laporan": parameter})
            else:
                hasil = alat_analisis.validate_report(ctx, parameter)
            verdict = hasil.get("verdict") or {}
            rincian = hasil.get("rincian") or []
            jumlah = int(hasil.get("jumlah") or len(rincian))
    except DatabaseError as exc:
        raise cegah_galat_db(exc) from exc

    balasan = _teks_validasi(verdict, rincian)
    mode = "deterministik"
    model: str | None = None

    try:
        hasil_llm: ChatResult = await minta_narasi(
            render("system_validate", domain=domain()),
            "Periksa kelengkapan laporan berikut dan berikan penilaian singkat dalam Bahasa "
            "Indonesia beserta saran perbaikan. Gunakan hanya data yang diberikan.",
            {"verdict": verdict, "rincian": rincian},
        )
        if hasil_llm.content.strip():
            balasan = hasil_llm.content.strip()
            mode = "model"
            model = hasil_llm.model or None
    except ModelUnavailable as exc:
        if not settings.ai_degrade_deterministic:
            raise galat_layanan(
                "Layanan AI (Ollama) sedang tidak tersedia sehingga pemeriksaan berbasis model "
                "tidak dapat dijalankan."
            ) from exc
        log.warning("Ollama mati; memakai hasil validasi deterministik: %s", exc)

    return ValidateResponse(
        ok=True,
        balasan=balasan,
        verdict=verdict,
        rincian=rincian,
        jumlah=jumlah,
        model=model,
        mode=mode,
    )
