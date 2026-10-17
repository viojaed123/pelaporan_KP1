"""Pencadangan & pemulihan basis data.

Meniru perilaku ``backup.php``:
* nama berkas ``pelaporan_YYYYmmdd_HHMMSS_<tipe>.sql``;
* menyimpan baris ke ``backup_riwayat`` beserta ukuran & checksum sha256;
* pemulihan selalu membuat **cadangan pengaman** lebih dulu;
* memangkas berkas lama sesuai ``AI_BACKUP_KEEP``.
"""

from __future__ import annotations

import hashlib
import logging
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Any, Sequence

from ..config import Settings, get_settings
from ..db import ambil_banyak, ambil_satu, eksekusi, eksekusi_id
from .reporting import format_tanggal_id

log = logging.getLogger("ai-service.backup")


class BackupError(RuntimeError):
    """Galat pencadangan/pemulihan yang dapat ditampilkan kepada pengguna."""


# ---------------------------------------------------------------------------
# Proses eksternal
# ---------------------------------------------------------------------------
def jalankan_proses(argv: Sequence[str], stdin: bytes | None = None, timeout: float | None = None) -> dict[str, Any]:
    """Jalankan proses tanpa shell; kembalikan kode, keluaran, dan galat."""
    settings = get_settings()
    timeout = timeout or settings.ai_proses_timeout
    biner = argv[0] if argv else ""
    if not biner or not Path(biner).is_file():
        return {
            "ok": False,
            "kode": -1,
            "keluaran": "",
            "galat": f"Biner tidak ditemukan: {biner}",
        }
    try:
        proc = subprocess.run(  # noqa: S603 - argv eksplisit, tanpa shell
            list(argv),
            input=stdin,
            capture_output=True,
            timeout=timeout,
            check=False,
        )
    except subprocess.TimeoutExpired:
        return {"ok": False, "kode": -1, "keluaran": "", "galat": "Proses melebihi batas waktu."}
    except OSError as exc:
        return {"ok": False, "kode": -1, "keluaran": "", "galat": str(exc)}

    return {
        "ok": proc.returncode == 0,
        "kode": int(proc.returncode),
        "keluaran": proc.stdout.decode("utf-8", "replace"),
        "galat": proc.stderr.decode("utf-8", "replace"),
    }


def argumen_koneksi(settings: Settings) -> list[str]:
    """Argumen koneksi bersama untuk mysqldump/mysql (tanpa nama basis data)."""
    arg = ["-h", settings.ai_db_host, "-P", str(settings.ai_db_port), "-u", settings.ai_db_app_user]
    if settings.ai_db_app_password:
        arg.append(f"--password={settings.ai_db_app_password}")
    return arg


# ---------------------------------------------------------------------------
# Membuat cadangan
# ---------------------------------------------------------------------------
def buat_cadangan(tipe: str = "manual", oleh: int | None = None) -> dict[str, Any]:
    """Buat berkas cadangan baru dan catat ke ``backup_riwayat``.

    Returns:
        dict dengan kunci ``ok``, ``nama_file``, ``jalur``, ``ukuran_bytes``,
        ``checksum``; atau ``ok=False`` + ``pesan`` bila gagal.
    """
    settings = get_settings()
    tipe = "otomatis" if tipe == "otomatis" else "manual"
    folder = settings.backup_dir_path
    folder.mkdir(parents=True, exist_ok=True)

    stempel = datetime.now().strftime("%Y%m%d_%H%M%S")
    nama = f"pelaporan_{stempel}_{tipe}.sql"
    jalur = folder / nama

    argv = [
        settings.ai_mysqldump_bin,
        *argumen_koneksi(settings),
        "--single-transaction",
        "--quick",
        "--routines",
        "--events",
        "--default-character-set=utf8mb4",
        f"--result-file={jalur}",
        settings.ai_db_name,
    ]
    hasil = jalankan_proses(argv)

    if not hasil["ok"] or not jalur.is_file() or jalur.stat().st_size == 0:
        if jalur.is_file():
            try:
                jalur.unlink()
            except OSError:
                pass
        pesan = hasil["galat"] or "mysqldump gagal."
        log.error("Gagal membuat cadangan: %s", pesan)
        return {"ok": False, "pesan": pesan}

    ukuran = jalur.stat().st_size
    checksum = _sha256(jalur)
    try:
        eksekusi(
            """
            INSERT INTO backup_riwayat (nama_file, ukuran_bytes, checksum, tipe, dibuat_oleh)
            VALUES (%s, %s, %s, %s, %s)
            """,
            (nama, ukuran, checksum, tipe, oleh),
        )
    except Exception as exc:  # noqa: BLE001 - berkas tetap sah walau riwayat gagal
        log.warning("Cadangan dibuat tetapi riwayat gagal dicatat: %s", exc)

    return {
        "ok": True,
        "nama_file": nama,
        "jalur": str(jalur),
        "ukuran_bytes": ukuran,
        "ukuran": ukuran_mudah(ukuran),
        "checksum": checksum,
        "tipe": tipe,
    }


def _sha256(jalur: Path) -> str:
    h = hashlib.sha256()
    with jalur.open("rb") as f:
        for blok in iter(lambda: f.read(1024 * 1024), b""):
            h.update(blok)
    return h.hexdigest()


# ---------------------------------------------------------------------------
# Riwayat & daftar
# ---------------------------------------------------------------------------
def ambil_baris(baris_id: int) -> dict[str, Any] | None:
    """Ambil baris ``backup_riwayat`` dan verifikasi berkasnya ada (anti path traversal)."""
    baris = ambil_satu("SELECT * FROM backup_riwayat WHERE id = %s LIMIT 1", (baris_id,))
    if not baris:
        return None
    nama_aman = Path(str(baris.get("nama_file") or "")).name
    jalur = get_settings().backup_dir_path / nama_aman
    if not jalur.is_file():
        return None
    baris["_jalur"] = str(jalur)
    baris["_nama"] = nama_aman
    return baris


def daftar_cadangan(limit: int = 50) -> list[dict[str, Any]]:
    """Daftar cadangan terbaru beserta status keberadaan berkas."""
    rows = ambil_banyak(
        """
        SELECT b.*, u.nama AS nama_pembuat
        FROM backup_riwayat b
        LEFT JOIN users u ON u.id = b.dibuat_oleh
        ORDER BY b.id DESC
        LIMIT %s
        """,
        (int(limit),),
    )
    folder = get_settings().backup_dir_path
    for b in rows:
        nama_aman = Path(str(b.get("nama_file") or "")).name
        jalur = folder / nama_aman
        b["ada"] = jalur.is_file()
        b["ukuran_bytes_nyata"] = jalur.stat().st_size if b["ada"] else 0
        b["ukuran"] = ukuran_mudah(b["ukuran_bytes_nyata"])
        b["dibuat_pada_label"] = format_tanggal_id(b.get("dibuat_pada"))
    return rows


def sinkron_riwayat() -> int:
    """Daftarkan berkas ``.sql`` di folder cadangan yang belum tercatat."""
    folder = get_settings().backup_dir_path
    if not folder.is_dir():
        return 0
    tercatat = {str(b.get("nama_file")) for b in ambil_banyak("SELECT nama_file FROM backup_riwayat")}
    tambah = 0
    for jalur in sorted(folder.glob("*.sql")):
        if jalur.name in tercatat:
            continue
        ukuran = jalur.stat().st_size
        checksum = _sha256(jalur)
        tipe = "otomatis" if "_otomatis" in jalur.name else "manual"
        waktu = datetime.fromtimestamp(jalur.stat().st_mtime)
        try:
            eksekusi_id(
                """
                INSERT INTO backup_riwayat
                    (nama_file, ukuran_bytes, checksum, tipe, dibuat_oleh, dibuat_pada)
                VALUES (%s, %s, %s, %s, NULL, %s)
                """,
                (jalur.name, ukuran, checksum, tipe, waktu.strftime("%Y-%m-%d %H:%M:%S")),
            )
            tambah += 1
        except Exception as exc:  # noqa: BLE001
            log.warning("Gagal mendaftarkan %s: %s", jalur.name, exc)
    return tambah


def pangkas(keep: int | None = None) -> int:
    """Hapus berkas cadangan lama sehingga tersisa ``keep`` terbaru."""
    settings = get_settings()
    keep = max(1, keep if keep is not None else settings.ai_backup_keep)
    rows = ambil_banyak(
        "SELECT id, nama_file FROM backup_riwayat ORDER BY id DESC LIMIT 1000"
    )
    dihapus = 0
    for baris in rows[keep:]:
        nama_aman = Path(str(baris.get("nama_file") or "")).name
        jalur = settings.backup_dir_path / nama_aman
        if jalur.is_file():
            try:
                jalur.unlink()
            except OSError:
                continue
        try:
            eksekusi("DELETE FROM backup_riwayat WHERE id = %s", (int(baris["id"]),))
            dihapus += 1
        except Exception as exc:  # noqa: BLE001
            log.warning("Gagal memangkas baris %s: %s", baris.get("id"), exc)
    return dihapus


# ---------------------------------------------------------------------------
# Pemulihan (approval-gated)
# ---------------------------------------------------------------------------
def pulihkan(baris_id: int, konfirmasi: str) -> dict[str, Any]:
    """Pulihkan basis data dari cadangan — WAJIB konfirmasi ``PULIHKAN``.

    Selalu membuat cadangan pengaman lebih dulu. Mengembalikan dict status.
    """
    settings = get_settings()

    if not settings.ai_restore_aktif:
        return {"ok": False, "pesan": "Pemulihan dinonaktifkan oleh konfigurasi (AI_RESTORE_AKTIF=false)."}
    if (konfirmasi or "").strip().upper() != "PULIHKAN":
        return {
            "ok": False,
            "butuh_konfirmasi": True,
            "pesan": "Pemulihan dibatalkan: kata konfirmasi tidak sesuai (ketik PULIHKAN).",
        }

    baris = ambil_baris(baris_id)
    if baris is None:
        return {"ok": False, "pesan": "Berkas cadangan tidak valid atau tidak ditemukan."}

    # 1. Cadangan pengaman sebelum menimpa.
    pengaman = buat_cadangan("manual", None)
    if not pengaman.get("ok"):
        return {
            "ok": False,
            "pesan": "Dibatalkan: cadangan pengaman gagal dibuat. Pemulihan tidak dijalankan.",
            "galat_pengaman": pengaman.get("pesan"),
        }

    # 2. Jalankan `mysql < berkas`.
    jalur = Path(str(baris["_jalur"]))
    try:
        isi = jalur.read_bytes()
    except OSError as exc:
        return {"ok": False, "pesan": f"Tidak dapat membaca berkas cadangan: {exc}"}

    argv = [
        settings.ai_mysql_bin,
        *argumen_koneksi(settings),
        "--default-character-set=utf8mb4",
        settings.ai_db_name,
    ]
    hasil = jalankan_proses(argv, stdin=isi)

    if not hasil["ok"]:
        pesan = hasil["galat"] or "Proses pemulihan gagal."
        return {"ok": False, "pesan": f"Pemulihan gagal: {pesan[:500]}"}

    # 3. Setelah restore, tabel backup_riwayat ikut terputar balik; daftarkan ulang.
    try:
        sinkron_riwayat()
    except Exception as exc:  # noqa: BLE001
        log.warning("Sinkronisasi riwayat setelah pemulihan gagal: %s", exc)

    return {
        "ok": True,
        "dipulihkan_dari": baris["_nama"],
        "cadangan_pengaman": pengaman["nama_file"],
        "pesan": f"Basis data dipulihkan dari {baris['_nama']}.",
    }


def ukuran_mudah(byte: int) -> str:
    """Format ukuran byte agar mudah dibaca (meniru ``ukuran_mudah`` PHP)."""
    byte = int(byte or 0)
    if byte >= 1048576:
        return f"{byte / 1048576:.2f} MB".replace(".", ",")
    if byte >= 1024:
        return f"{byte / 1024:.1f} KB".replace(".", ",")
    return f"{byte} B"
