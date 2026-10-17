"""Alat pencarian web: ``web_search``.

Tujuan: memberi agen kemampuan mencari informasi terkini di internet yang
tidak tersedia di basis data aplikasi. Dua sumber:

  1. **SearXNG** (utama) — instans meta-search yang dihosting sendiri
     (variabel ``AI_SEARXNG_URL``). Hasilnya JSON, ramah privasi.
  2. **DuckDuckGo Lite** (cadangan) — dipakai bila SearXNG belum diatur
     atau gagal. Best-effort, tanpa kunci API.

Alat ini HANYA membaca internet; tidak pernah menulis apa pun. Bila
``AI_WEB_SEARCH_AKTIF=false`` alat menolak dengan pesan jelas.
"""

from __future__ import annotations

import html as _html
import logging
import re
import time
from typing import Any

import httpx

from ..config import get_settings
from .registry import Tool, ToolContext, skema_objek

log = logging.getLogger("ai-service.tools.search")

#: Cache sederhana: kueri -> (kedaluwarsa_epoch, daftar_hasil).
_CACHE: dict[str, tuple[float, list[dict[str, Any]]]] = {}

_TAG = re.compile(r"<[^>]+>")
_SPASI = re.compile(r"\s+")


def _bersihkan(teks: str) -> str:
    """Buang tag HTML dan rapikan spasi."""
    teks = _TAG.sub(" ", teks or "")
    teks = _html.unescape(teks)
    return _SPASI.sub(" ", teks).strip()


def _cache_ambil(kunci: str) -> list[dict[str, Any]] | None:
    item = _CACHE.get(kunci)
    if not item:
        return None
    kedaluwarsa, hasil = item
    if time.time() >= kedaluwarsa:
        _CACHE.pop(kunci, None)
        return None
    return hasil


def _cache_simpan(kunci: str, hasil: list[dict[str, Any]], menit: int) -> None:
    if menit <= 0:
        return
    if len(_CACHE) > 200:  # batas pertumbuhan memori
        _CACHE.clear()
    _CACHE[kunci] = (time.time() + menit * 60, hasil)


def _klien(settings: Any, **ubah: Any) -> Any:
    """Buat klien httpx dengan opsi verifikasi TLS terpusat.

    Bila ``AI_HTTP_CA_BUNDLE`` diisi (mis. sertifikat root proxy perusahaan),
    berkas itu dipakai untuk verifikasi TLS; jika tidak, pakai bawaan certifi.
    """
    ca = (getattr(settings, "ai_http_ca_bundle", "") or "").strip()
    kwargs: dict[str, Any] = {
        "timeout": float(settings.ai_web_search_timeout),
        "follow_redirects": True,
    }
    if ca:
        kwargs["verify"] = ca
    kwargs.update(ubah)
    return httpx.Client(**kwargs)


def _cari_searxng(kueri: str, jumlah: int, bahasa: str) -> list[dict[str, Any]]:
    """Cari lewat instans SearXNG (format JSON)."""
    settings = get_settings()
    basis = (settings.ai_searxng_url or "").strip().rstrip("/")
    if not basis:
        return []

    params: dict[str, Any] = {"q": kueri, "format": "json", "safesearch": 1}
    if bahasa:
        params["language"] = bahasa

    with _klien(settings) as klien:
        resp = klien.get(
            basis + "/search",
            params=params,
            headers={"User-Agent": "PelaporanAI/1.0 (+searxng)"},
        )
        resp.raise_for_status()
        data = resp.json()

    hasil: list[dict[str, Any]] = []
    for item in (data.get("results") or [])[:jumlah]:
        tautan = str(item.get("url") or "").strip()
        if not tautan:
            continue
        hasil.append(
            {
                "judul": _bersihkan(str(item.get("title") or "")),
                "tautan": tautan,
                "ringkasan": _bersihkan(str(item.get("content") or ""))[:500],
                "sumber": str(item.get("engine") or "searxng"),
            }
        )
    return hasil


def _cari_duckduckgo(kueri: str, jumlah: int) -> list[dict[str, Any]]:
    """Cadangan: urai halaman DuckDuckGo Lite (best-effort)."""
    settings = get_settings()
    with _klien(settings) as klien:
        resp = klien.post(
            "https://lite.duckduckgo.com/lite/",
            data={"q": kueri},
            headers={
                "User-Agent": (
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36"
                ),
            },
        )
        resp.raise_for_status()
        halaman = resp.text

    hasil: list[dict[str, Any]] = []
    # Tautan hasil lite: <a rel="nofollow" href="...">
    for m in re.finditer(r'<a[^>]+class="result-link"[^>]*href="([^"]+)"[^>]*>(.*?)</a>', halaman, re.S):
        tautan = _html.unescape(m.group(1))
        judul = _bersihkan(m.group(2))
        if tautan.startswith("//"):
            tautan = "https:" + tautan
        if not tautan.startswith("http"):
            continue
        hasil.append({"judul": judul, "tautan": tautan, "ringkasan": "", "sumber": "duckduckgo"})
        if len(hasil) >= jumlah:
            break

    # Ringkasan (snippet) lite: <td class="result-snippet">...</td>
    snippet = [_bersihkan(s) for s in re.findall(r'<td[^>]+class="result-snippet"[^>]*>(.*?)</td>', halaman, re.S)]
    for i, s in enumerate(snippet[: len(hasil)]):
        hasil[i]["ringkasan"] = s[:500]

    return hasil


def web_search(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Cari informasi terkini di internet (SearXNG, cadangan DuckDuckGo)."""
    settings = get_settings()

    if not settings.ai_web_search_aktif:
        return {
            "ok": False,
            "pesan": "Pencarian web sedang dinonaktifkan oleh administrator (AI_WEB_SEARCH_AKTIF=false).",
        }

    kueri = str(args.get("kueri") or "").strip()
    if not kueri:
        return {"ok": False, "pesan": "Parameter 'kueri' wajib diisi."}

    try:
        jumlah = int(args.get("jumlah") or settings.ai_web_search_hasil)
    except (TypeError, ValueError):
        jumlah = int(settings.ai_web_search_hasil)
    jumlah = max(1, min(10, jumlah))

    bahasa = str(args.get("bahasa") or "").strip()
    segar = str(args.get("segar") or "").strip().lower() in {"1", "true", "ya", "yes"}

    kunci = f"{(settings.ai_searxng_url or '').strip()}|{bahasa}|{jumlah}|{kueri.lower()}"
    if not segar:
        tersimpan = _cache_ambil(kunci)
        if tersimpan is not None:
            return {
                "ok": True,
                "kueri": kueri,
                "jumlah": len(tersimpan),
                "hasil": tersimpan,
                "dari_cache": True,
                "catatan": "Hasil dari cache singkat; set segar=true untuk memaksa pencarian baru.",
            }

    galat: list[str] = []
    hasil: list[dict[str, Any]] = []

    try:
        hasil = _cari_searxng(kueri, jumlah, bahasa)
    except Exception as exc:  # noqa: BLE001 - cadangan harus tetap dicoba
        galat.append(f"SearXNG: {exc}")
        log.warning("SearXNG gagal: %s", exc)

    if not hasil:
        try:
            hasil = _cari_duckduckgo(kueri, jumlah)
        except Exception as exc:  # noqa: BLE001
            galat.append(f"DuckDuckGo: {exc}")
            log.warning("DuckDuckGo gagal: %s", exc)

    if not hasil:
        return {
            "ok": False,
            "kueri": kueri,
            "pesan": (
                "Tidak ada hasil pencarian web. Pastikan instans SearXNG aktif "
                "(AI_SEARXNG_URL) atau koneksi internet tersedia."
            ),
            "galat": galat,
        }

    _cache_simpan(kunci, hasil, int(settings.ai_web_search_cache_menit))

    return {
        "ok": True,
        "kueri": kueri,
        "jumlah": len(hasil),
        "hasil": hasil,
        "sumber": hasil[0].get("sumber", ""),
        "catatan": (
            "Hasil berasal dari internet dan dapat berubah. Selalu sebutkan tautan "
            "sumbernya dan jangan menganggapnya sebagai data resmi aplikasi."
        ),
    }


def bangun() -> list[Tool]:
    """Kembalikan daftar alat pencarian."""
    properti = {
        "kueri": {"type": "string", "description": "Kata kunci / pertanyaan pencarian (wajib)."},
        "jumlah": {"type": "integer", "description": "Jumlah hasil (1-10, bawaan 5)."},
        "bahasa": {"type": "string", "description": "Kode bahasa SearXNG, mis. 'id' atau 'en'."},
        "segar": {"type": "boolean", "description": "true = abaikan cache, paksa pencarian baru."},
    }
    return [
        Tool(
            nama="web_search",
            deskripsi=(
                "Cari informasi TERKINI di internet (SearXNG, cadangan DuckDuckGo). "
                "Gunakan bila pertanyaan menyangkut berita/peraturan/istilah mutakhir yang "
                "tidak ada di basis data aplikasi. JANGAN pakai untuk data laporan/absensi "
                "internal — untuk itu gunakan search_notes/statistics. Selalu sebutkan "
                "tautan sumber pada jawaban."
            ),
            parameter=skema_objek(properti, wajib=["kueri"]),
            fungsi=web_search,
        ),
    ]
