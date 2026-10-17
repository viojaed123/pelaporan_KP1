"""Alat pengiriman pesan: ``send_email`` dan ``send_whatsapp`` (approval-gated).

PENTING: kedua alat ini **tidak pernah mengirim langsung**. Mereka hanya
mengembalikan *usulan aksi* agar pengguna menyetujuinya lebih dulu. Pengiriman
sebenarnya disambungkan di sisi PHP (SMTP pada ``includes/mailer.php`` dan
gateway WhatsApp pada ``config.php``: ``WA_GATEWAY``).

Bila ``simpan_antrean=true``, usulan dicatat sebagai baris ``laporan_terkirim``
berstatus ``antre`` agar sisi PHP dapat mengambilnya dan mengirim.
"""

from __future__ import annotations

from typing import Any

from ..db import ambil_satu, eksekusi_id
from .registry import Tool, ToolContext, skema_objek


def _simpan_antrean(kanal: str, penerima: str, subjek: str | None, isi: str, laporan_id: int | None) -> int:
    """Catat usulan pengiriman ke ``laporan_terkirim`` (status 'antre')."""
    return eksekusi_id(
        """
        INSERT INTO laporan_terkirim (laporan_id, kanal, penerima, subjek, status)
        VALUES (%s, %s, %s, %s, 'antre')
        """,
        (laporan_id, kanal, penerima[:190], (subjek or "")[:255] or None),
    )


def send_email(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Ajukan pengiriman email laporan — tidak mengirim, hanya usulan."""
    penerima = str(args.get("penerima") or "").strip()
    if "@" not in penerima:
        return {"ok": False, "pesan": "Alamat email penerima tidak valid."}
    subjek = str(args.get("subjek") or "Laporan Kegiatan Harian").strip()
    isi = str(args.get("isi") or "").strip()
    laporan_id = args.get("laporan_id")
    try:
        laporan_id = int(laporan_id) if laporan_id else None
    except (TypeError, ValueError):
        return {"ok": False, "pesan": "Parameter 'laporan_id' harus berupa angka."}

    if laporan_id:
        baris = ambil_satu("SELECT id, user_id FROM laporan_harian WHERE id = %s", (laporan_id,))
        if baris is None:
            return {"ok": False, "pesan": f"Laporan #{laporan_id} tidak ditemukan."}
        if not ctx.bisa_lintas_pengguna and int(baris["user_id"]) != ctx.user_id:
            return {"ok": False, "pesan": "Anda hanya boleh mengirim laporan milik sendiri."}

    usulan: dict[str, Any] = {
        "kanal": "email",
        "penerima": penerima,
        "subjek": subjek,
        "isi": isi,
        "laporan_id": laporan_id,
        "dikirim_oleh": ctx.user_id,
    }

    if _benar(args.get("simpan_antrean")):
        try:
            usulan["id_antrean"] = _simpan_antrean("email", penerima, subjek, isi, laporan_id)
        except Exception as exc:  # noqa: BLE001
            usulan["catatan_antrean"] = f"Gagal menyimpan antrean: {exc}"

    return {
        "ok": True,
        "status": "menunggu_persetujuan",
        "butuh_persetujuan": True,
        "usulan": usulan,
        "pesan": (
            "Usulan pengiriman email sudah disiapkan. Tindakan ini TIDAK dijalankan otomatis; "
            "pengguna harus menyetujuinya, lalu pengiriman dilakukan oleh aplikasi (PHP/SMTP)."
        ),
    }


def send_whatsapp(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Ajukan pengiriman WhatsApp laporan — tidak mengirim, hanya usulan."""
    nomor = str(args.get("nomor") or args.get("penerima") or "").strip()
    if not nomor or not any(ch.isdigit() for ch in nomor):
        return {"ok": False, "pesan": "Nomor WhatsApp penerima tidak valid."}
    isi = str(args.get("isi") or "").strip()
    laporan_id = args.get("laporan_id")
    try:
        laporan_id = int(laporan_id) if laporan_id else None
    except (TypeError, ValueError):
        return {"ok": False, "pesan": "Parameter 'laporan_id' harus berupa angka."}

    if laporan_id:
        baris = ambil_satu("SELECT id, user_id FROM laporan_harian WHERE id = %s", (laporan_id,))
        if baris is None:
            return {"ok": False, "pesan": f"Laporan #{laporan_id} tidak ditemukan."}
        if not ctx.bisa_lintas_pengguna and int(baris["user_id"]) != ctx.user_id:
            return {"ok": False, "pesan": "Anda hanya boleh mengirim laporan milik sendiri."}

    usulan: dict[str, Any] = {
        "kanal": "whatsapp",
        "nomor": nomor,
        "isi": isi,
        "laporan_id": laporan_id,
        "dikirim_oleh": ctx.user_id,
    }

    if _benar(args.get("simpan_antrean")):
        try:
            usulan["id_antrean"] = _simpan_antrean("whatsapp", nomor, None, isi, laporan_id)
        except Exception as exc:  # noqa: BLE001
            usulan["catatan_antrean"] = f"Gagal menyimpan antrean: {exc}"

    return {
        "ok": True,
        "status": "menunggu_persetujuan",
        "butuh_persetujuan": True,
        "usulan": usulan,
        "pesan": (
            "Usulan pengiriman WhatsApp sudah disiapkan. Tindakan ini TIDAK dijalankan otomatis; "
            "pengguna harus menyetujuinya, lalu pengiriman dilakukan oleh aplikasi (gateway WhatsApp)."
        ),
    }


def _benar(nilai: Any) -> bool:
    if isinstance(nilai, bool):
        return nilai
    return str(nilai or "").strip().lower() in {"1", "true", "ya", "yes", "setuju"}


def bangun() -> list[Tool]:
    """Kembalikan daftar alat pengiriman (approval-gated)."""
    properti_email = {
        "penerima": {"type": "string", "description": "Alamat email tujuan."},
        "subjek": {"type": "string", "description": "Subjek email."},
        "isi": {"type": "string", "description": "Isi email."},
        "laporan_id": {"type": "integer", "description": "ID laporan yang dikirim (opsional)."},
        "simpan_antrean": {"type": "boolean", "description": "Simpan usulan ke laporan_terkirim (status antre)."},
    }
    properti_wa = {
        "nomor": {"type": "string", "description": "Nomor WhatsApp tujuan."},
        "isi": {"type": "string", "description": "Isi pesan."},
        "laporan_id": {"type": "integer", "description": "ID laporan yang dikirim (opsional)."},
        "simpan_antrean": {"type": "boolean", "description": "Simpan usulan ke laporan_terkirim (status antre)."},
    }
    return [
        Tool(
            nama="send_email",
            deskripsi=(
                "Ajukan pengiriman email laporan. TIDAK mengirim langsung — hanya mengembalikan "
                "usulan yang harus disetujui pengguna. Pengiriman dilakukan oleh aplikasi PHP."
            ),
            parameter=skema_objek(properti_email, wajib=["penerima", "isi"]),
            fungsi=send_email,
            butuh_persetujuan=True,
        ),
        Tool(
            nama="send_whatsapp",
            deskripsi=(
                "Ajukan pengiriman WhatsApp laporan. TIDAK mengirim langsung — hanya mengembalikan "
                "usulan yang harus disetujui pengguna. Pengiriman dilakukan oleh gateway aplikasi PHP."
            ),
            parameter=skema_objek(properti_wa, wajib=["nomor", "isi"]),
            fungsi=send_whatsapp,
            butuh_persetujuan=True,
        ),
    ]
