"""Pekerja latar belakang untuk tabel ``ai_jobs``.

Dijalankan sebagai proses terpisah::

    python -m app.jobs.worker            # gelung terus-menerus
    python -m app.jobs.worker --sekali   # proses semua yang antre lalu keluar

Untuk produksi di Windows, jalankan lewat Penjadwal Tugas (Task Scheduler)
dengan ``--sekali`` tiap menit, ATAU jalankan ``--gelung`` sebagai layanan.
"""

from __future__ import annotations

import argparse
import logging
import time

from ..config import get_settings
from ..db import DatabaseError
from ..services import jobs as svc

log = logging.getLogger("ai-service.worker")


def konfigurasi_log(level: str = "info") -> None:
    logging.basicConfig(
        level=getattr(logging, level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)-7s %(name)s: %(message)s",
    )


def proses_semua(maks: int = 100) -> int:
    """Proses semua job yang saat ini berstatus antre (batas ``maks``)."""
    total = 0
    for _ in range(max(1, maks)):
        try:
            ringkas = svc.proses_sekali()
        except DatabaseError as exc:
            log.error("Basis data tidak dapat diakses: %s", exc)
            break
        if ringkas is None:
            break
        total += 1
        log.info(
            "Job %s (%s) -> %s%s",
            ringkas["id"],
            ringkas["jenis"],
            ringkas["status"],
            f" :: {ringkas['pesan_error']}" if ringkas.get("pesan_error") else "",
        )
    return total


def gelung(interval: float | None = None) -> None:
    """Gelung tanpa henti: klaim & proses job, lalu tidur sebentar."""
    settings = get_settings()
    interval = interval if interval is not None else settings.ai_jobs_poll_interval
    log.info("Pekerja ai_jobs berjalan (interval %.1fs)...", interval)
    while True:
        if not settings.ai_jobs_enabled:
            log.info("AI_JOBS_ENABLED=false; pekerja berhenti.")
            return
        try:
            svc.pulihkan_macet()
            diproses = proses_semua(maks=settings.ai_jobs_batch * 20)
            if diproses == 0:
                time.sleep(interval)
        except KeyboardInterrupt:  # pragma: no cover
            log.info("Pekerja dihentikan oleh pengguna.")
            return
        except Exception as exc:  # noqa: BLE001 - pekerja harus tahan banting
            log.exception("Galat pada gelung pekerja: %s", exc)
            time.sleep(interval)


def main(argv: list[str] | None = None) -> int:
    """Titik masuk CLI pekerja."""
    parser = argparse.ArgumentParser(description="Pekerja antrean ai_jobs")
    grup = parser.add_mutually_exclusive_group()
    grup.add_argument("--sekali", action="store_true", help="Proses semua job lalu keluar.")
    grup.add_argument("--gelung", action="store_true", help="Jalankan gelung terus-menerus.")
    parser.add_argument("--interval", type=float, default=None, help="Jeda polling (detik).")
    parser.add_argument("--maks", type=int, default=100, help="Batas job untuk mode --sekali.")
    args = parser.parse_args(argv)

    settings = get_settings()
    konfigurasi_log(settings.ai_log_level)

    if args.sekali:
        jumlah = proses_semua(args.maks)
        log.info("Selesai: %d job diproses.", jumlah)
        return 0
    gelung(args.interval)
    return 0


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
