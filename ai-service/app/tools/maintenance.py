"""Alat pemeliharaan basis data: cadangan & pemulihan (khusus admin)."""

from __future__ import annotations

from typing import Any

from ..db import catat_audit
from ..services import backup
from .registry import Tool, ToolContext, skema_objek


def backup_database(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Buat cadangan basis data (mysqldump) dan catat ke riwayat."""
    tipe = "otomatis" if str(args.get("tipe") or "").lower() == "otomatis" else "manual"
    hasil = backup.buat_cadangan(tipe=tipe, oleh=ctx.user_id)
    if not hasil.get("ok"):
        return {"ok": False, "pesan": f"Gagal membuat cadangan: {hasil.get('pesan')}"}

    try:
        backup.pangkas()
    except Exception:  # noqa: BLE001 - pemangkasan tidak kritis
        pass

    catat_audit(
        user_id=ctx.user_id,
        aksi="ai_backup_buat",
        tabel_ref="backup_riwayat",
        ringkasan=f"Cadangan dibuat lewat asisten AI: {hasil['nama_file']}",
        data_baru={"nama_file": hasil["nama_file"]},
    )
    return {
        "ok": True,
        "status": "selesai",
        "nama_file": hasil["nama_file"],
        "jalur": hasil["jalur"],
        "ukuran": hasil.get("ukuran"),
        "checksum": hasil.get("checksum"),
        "tipe": tipe,
        "pesan": f"Cadangan berhasil dibuat: {hasil['nama_file']}.",
    }


def list_backups(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Daftar berkas cadangan terbaru."""
    try:
        backup.sinkron_riwayat()
    except Exception:  # noqa: BLE001
        pass
    rows = backup.daftar_cadangan(limit=int(args.get("limit") or 20))
    return {
        "ok": True,
        "jumlah": len(rows),
        "cadangan": [
            {
                "id": r.get("id"),
                "nama_file": r.get("nama_file"),
                "ukuran": r.get("ukuran"),
                "tipe": r.get("tipe"),
                "ada": r.get("ada"),
                "dibuat_pada": str(r.get("dibuat_pada") or ""),
                "dibuat_oleh": r.get("nama_pembuat"),
            }
            for r in rows
        ],
    }


def restore_database(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Pulihkan basis data — **selalu approval-gated**.

    Alat ini TIDAK akan memulihkan tanpa ``konfirmasi="PULIHKAN"``. Sebelum
    menimpa, sistem otomatis membuat cadangan pengaman (lihat ``backup.py``).
    Bila pengguna belum menyetujui, alat hanya mengembalikan usulan aksi.
    """
    backup_id = args.get("backup_id") or args.get("id")
    konfirmasi = str(args.get("konfirmasi") or "")

    if not backup_id:
        return {"ok": False, "pesan": "Parameter 'backup_id' wajib diisi (lihat list_backups)."}
    try:
        backup_id = int(backup_id)
    except (TypeError, ValueError):
        return {"ok": False, "pesan": "Parameter 'backup_id' harus berupa angka."}

    baris = backup.ambil_baris(backup_id)
    if baris is None:
        return {"ok": False, "pesan": f"Cadangan #{backup_id} tidak ditemukan atau berkasnya hilang."}

    if konfirmasi.strip().upper() != "PULIHKAN":
        return {
            "ok": True,
            "status": "menunggu_persetujuan",
            "butuh_konfirmasi": True,
            "backup_id": backup_id,
            "nama_file": baris.get("_nama"),
            "pesan": (
                "Pemulihan basis data bersifat berisiko dan memerlukan persetujuan eksplisit. "
                "Seluruh data saat ini akan ditimpa oleh isi berkas cadangan (cadangan pengaman "
                "akan dibuat otomatis terlebih dahulu). Untuk menjalankan, panggil ulang dengan "
                'konfirmasi="PULIHKAN" setelah pengguna setuju.'
            ),
        }

    if ctx.role != "admin":
        return {"ok": False, "pesan": "Hanya admin yang diizinkan memulihkan basis data."}

    hasil = backup.pulihkan(backup_id, konfirmasi="PULIHKAN")
    if not hasil.get("ok"):
        return {"ok": False, "pesan": hasil.get("pesan")}

    catat_audit(
        user_id=ctx.user_id,
        aksi="ai_backup_pulihkan",
        tabel_ref="backup_riwayat",
        baris_id=backup_id,
        ringkasan=f"Basis data dipulihkan lewat asisten AI dari {hasil['dipulihkan_dari']}",
        data_baru={
            "dipulihkan_dari": hasil["dipulihkan_dari"],
            "cadangan_pengaman": hasil["cadangan_pengaman"],
        },
    )
    return {"ok": True, "status": "selesai", **hasil}


def bangun() -> list[Tool]:
    """Kembalikan daftar alat pemeliharaan (khusus admin)."""
    return [
        Tool(
            nama="backup_database",
            deskripsi="Buat cadangan basis data (mysqldump) ke folder backups/db. Khusus admin.",
            parameter=skema_objek(
                {"tipe": {"type": "string", "enum": ["manual", "otomatis"], "description": "Jenis cadangan."}}
            ),
            fungsi=backup_database,
            peran=("admin",),
        ),
        Tool(
            nama="list_backups",
            deskripsi="Tampilkan daftar berkas cadangan basis data terbaru. Khusus admin.",
            parameter=skema_objek({"limit": {"type": "integer", "description": "Jumlah maksimum."}}),
            fungsi=list_backups,
            peran=("admin",),
        ),
        Tool(
            nama="restore_database",
            deskripsi=(
                "Pulihkan basis data dari berkas cadangan. WAJIB persetujuan eksplisit: tanpa "
                'konfirmasi="PULIHKAN" alat hanya mengembalikan usulan. Membuat cadangan pengaman '
                "otomatis sebelum menimpa. Khusus admin."
            ),
            parameter=skema_objek(
                {
                    "backup_id": {"type": "integer", "description": "ID cadangan pada backup_riwayat."},
                    "konfirmasi": {
                        "type": "string",
                        "description": 'Ketik "PULIHKAN" untuk benar-benar memulihkan.',
                    },
                },
                wajib=["backup_id"],
            ),
            fungsi=restore_database,
            peran=("admin",),
            butuh_persetujuan=True,
        ),
    ]
