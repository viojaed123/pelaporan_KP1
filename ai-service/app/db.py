"""Akses basis data (MariaDB/MySQL) memakai PyMySQL.

Semua kueri memakai parameter terikat (``%s``) sehingga aman dari injeksi SQL.
Kueri baca diarahkan ke akun baca-saja (``bi_reader``) bila tersedia; bila akun
itu belum diberi hak, layanan otomatis jatuh ke akun aplikasi (``pelaporan_app``)
agar tetap berfungsi.
"""

from __future__ import annotations

import json
import logging
from contextlib import contextmanager
from datetime import date, datetime, time
from decimal import Decimal
from typing import Any, Iterator, Sequence

import pymysql
from pymysql.cursors import DictCursor

from .config import Settings, get_settings

log = logging.getLogger("ai-service.db")

#: Bernilai true setelah akun baca-saja terbukti tidak bisa dipakai.
_readonly_broken: bool = False


class DatabaseError(RuntimeError):
    """Galat basis data ternormalisasi — dipetakan ke HTTP 503 oleh router."""


# ---------------------------------------------------------------------------
# Koneksi
# ---------------------------------------------------------------------------
def _connect(settings: Settings, readonly: bool) -> pymysql.connections.Connection:
    """Buka koneksi ke MariaDB dengan kredensial aplikasi atau baca-saja."""
    user = settings.ai_db_readonly_user if readonly else settings.ai_db_app_user
    password = (
        settings.ai_db_readonly_password if readonly else settings.ai_db_app_password
    )
    return pymysql.connect(
        host=settings.ai_db_host,
        port=int(settings.ai_db_port),
        user=user,
        password=password,
        database=settings.ai_db_name,
        charset="utf8mb4",
        cursorclass=DictCursor,
        autocommit=True,
        connect_timeout=int(settings.ai_db_connect_timeout),
    )


@contextmanager
def koneksi(readonly: bool = False) -> Iterator[pymysql.connections.Connection]:
    """Konteks koneksi. Jatuh ke akun aplikasi bila akun baca-saja gagal."""
    global _readonly_broken
    settings = get_settings()
    gunakan_ro = readonly and settings.ai_db_prefer_readonly and not _readonly_broken

    conn: pymysql.connections.Connection | None = None
    if gunakan_ro:
        try:
            conn = _connect(settings, True)
        except pymysql.MySQLError as exc:  # pragma: no cover - bergantung lingkungan
            log.warning("Akun baca-saja gagal dipakai (%s); beralih ke akun aplikasi.", exc)
            _readonly_broken = True
            conn = None

    if conn is None:
        try:
            conn = _connect(settings, False)
        except pymysql.MySQLError as exc:
            raise DatabaseError(str(exc)) from exc

    try:
        yield conn
    finally:
        try:
            conn.close()
        except Exception:  # pragma: no cover - penutupan defensif
            pass


def _eksekusi_pada(
    settings: Settings, sql: str, params: Sequence[Any] | None, readonly: bool, fetch: str
) -> Any:
    conn = _connect(settings, readonly)
    try:
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params) if params else ())
            if fetch == "all":
                return list(cur.fetchall())
            if fetch == "one":
                return cur.fetchone()
            if fetch == "id":
                return int(cur.lastrowid or 0)
            return int(cur.rowcount or 0)
    finally:
        try:
            conn.close()
        except Exception:  # pragma: no cover - penutupan defensif
            pass


def _jalankan(
    sql: str, params: Sequence[Any] | None, *, readonly: bool, fetch: str
) -> Any:
    """Jalankan kueri dengan cadangan otomatis dari baca-saja ke akun aplikasi."""
    global _readonly_broken
    settings = get_settings()
    gunakan_ro = readonly and settings.ai_db_prefer_readonly and not _readonly_broken

    try:
        return _eksekusi_pada(settings, sql, params, gunakan_ro, fetch)
    except pymysql.MySQLError as exc:
        if gunakan_ro:
            log.warning(
                "Kueri baca-saja gagal (%s); menandai akun baca-saja nonaktif dan mencoba akun aplikasi.",
                exc,
            )
            _readonly_broken = True
            try:
                return _eksekusi_pada(settings, sql, params, False, fetch)
            except pymysql.MySQLError as exc2:
                raise DatabaseError(str(exc2)) from exc2
        raise DatabaseError(str(exc)) from exc


# ---------------------------------------------------------------------------
# API publik
# ---------------------------------------------------------------------------
def ambil_banyak(sql: str, params: Sequence[Any] | None = None) -> list[dict[str, Any]]:
    """Kueri SELECT yang mengembalikan banyak baris (akun baca-saja)."""
    return _jalankan(sql, params, readonly=True, fetch="all")


def ambil_satu(sql: str, params: Sequence[Any] | None = None) -> dict[str, Any] | None:
    """Kueri SELECT yang mengembalikan satu baris atau ``None``."""
    return _jalankan(sql, params, readonly=True, fetch="one")


def eksekusi(sql: str, params: Sequence[Any] | None = None) -> int:
    """Jalankan INSERT/UPDATE/DELETE; kembalikan jumlah baris terpengaruh."""
    return _jalankan(sql, params, readonly=False, fetch="rowcount")


def eksekusi_id(sql: str, params: Sequence[Any] | None = None) -> int:
    """Jalankan INSERT; kembalikan ``lastrowid``."""
    return _jalankan(sql, params, readonly=False, fetch="id")


def sehat() -> bool:
    """True bila basis data dapat dijangkau (dipakai oleh /health)."""
    try:
        with koneksi(readonly=True) as conn, conn.cursor() as cur:
            cur.execute("SELECT 1")
            cur.fetchone()
        return True
    except Exception as exc:  # pragma: no cover - bergantung lingkungan
        log.warning("Basis data tidak sehat: %s", exc)
        return False


# ---------------------------------------------------------------------------
# Utilitas audit & serialisasi
# ---------------------------------------------------------------------------
#: Kolom yang tidak boleh masuk catatan audit.
_KOLOM_RAHASIA: frozenset[str] = frozenset(
    {"password", "kode_hash", "password_hash", "ingat_token_hash", "sesi_hash"}
)


def _saring(baris: dict[str, Any] | None) -> dict[str, Any] | None:
    if baris is None:
        return None
    return {k: v for k, v in baris.items() if k not in _KOLOM_RAHASIA}


def json_aman(obj: Any) -> str:
    """Serialisasi JSON yang memahami tipe data khas basis data."""
    return json.dumps(obj, ensure_ascii=False, default=_serial_default)


def _serial_default(obj: Any) -> Any:
    if isinstance(obj, (datetime, date, time)):
        return obj.isoformat()
    if isinstance(obj, Decimal):
        return float(obj)
    if isinstance(obj, (bytes, bytearray)):
        return obj.decode("utf-8", "replace")
    return str(obj)


def catat_audit(
    *,
    user_id: int | None,
    aksi: str,
    tabel_ref: str | None = None,
    baris_id: int | None = None,
    ringkasan: str | None = None,
    data_lama: dict[str, Any] | None = None,
    data_baru: dict[str, Any] | None = None,
    ip_address: str | None = None,
    user_agent: str | None = None,
) -> None:
    """Tulis satu baris ke ``audit_log`` (mengikuti konvensi ``catat_audit`` PHP)."""
    try:
        eksekusi(
            """
            INSERT INTO audit_log
                (user_id, aksi, tabel_ref, baris_id, ringkasan,
                 data_lama, data_baru, ip_address, user_agent)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                user_id,
                aksi,
                tabel_ref,
                baris_id,
                (ringkasan or "")[:255] or None,
                json_aman(_saring(data_lama)) if data_lama is not None else None,
                json_aman(_saring(data_baru)) if data_baru is not None else None,
                (ip_address or "")[:45] or None,
                (user_agent or "")[:255] or None,
            ),
        )
    except Exception as exc:  # pragma: no cover - audit tidak boleh menggagalkan aksi
        log.error("Gagal menulis audit_log: %s", exc)
