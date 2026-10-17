"""Alat catatan harian: ``search_notes`` dan ``update_note``."""

from __future__ import annotations

from typing import Any

from ..db import catat_audit, eksekusi
from ..services.reporting import STATUS_LAPORAN, validasi_laporan
from .data import ambil_laporan, ambil_laporan_id, ringkas_baris, scope_user, sumber_laporan
from .registry import Tool, ToolContext, skema_objek

#: Kolom yang boleh diubah lewat update_note (daftar putih — mencegah injeksi).
KOLOM_DIIZINKAN: tuple[str, ...] = (
    "tanggal",
    "jam_mulai",
    "jam_selesai",
    "kegiatan",
    "deskripsi",
    "hasil",
    "kendala",
    "rencana_berikutnya",
)

#: Status yang masih boleh diubah oleh pemiliknya.
STATUS_BOLEH_UBAH: tuple[str, ...] = ("draft", "perlu_revisi")


# ---------------------------------------------------------------------------
# search_notes
# ---------------------------------------------------------------------------
def search_notes(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Cari laporan harian berdasarkan rentang tanggal, kata kunci, dan status."""
    from .data import rentang_default  # impor lokal untuk menghindari siklus

    dari, sampai = rentang_default(args.get("tanggal_dari"), args.get("tanggal_sampai"))
    status = args.get("status")
    if status and status.strip().lower() not in STATUS_LAPORAN:
        return {
            "ok": False,
            "pesan": f"Status '{status}' tidak dikenal. Pilihan: {', '.join(STATUS_LAPORAN)}.",
        }
    limit = int(args.get("limit") or 30)
    user_diminta = args.get("user_id")

    rows = ambil_laporan(
        ctx,
        dari=dari,
        sampai=sampai,
        status=status,
        kata_kunci=args.get("kata_kunci"),
        user_id=int(user_diminta) if user_diminta else None,
        limit=limit,
    )
    _, label = scope_user(ctx, int(user_diminta) if user_diminta else None)

    return {
        "ok": True,
        "periode": {"dari": dari.isoformat(), "sampai": sampai.isoformat()},
        "untuk": label,
        "jumlah": len(rows),
        "laporan": ringkas_baris(rows),
        "sumber": sumber_laporan(rows),
    }


# ---------------------------------------------------------------------------
# update_note
# ---------------------------------------------------------------------------
def update_note(ctx: ToolContext, args: dict[str, Any]) -> dict[str, Any]:
    """Ubah satu laporan harian (bawaan: *dry-run*, terapkan bila ``konfirmasi=true``).

    Pembatasan: hanya pemilik (atau admin), hanya saat status ``draft`` /
    ``perlu_revisi``, dan hanya kolom pada daftar putih. Setiap penerapan
    dicatat ke ``audit_log``.
    """
    laporan_id = args.get("laporan_id")
    if not laporan_id:
        return {"ok": False, "pesan": "Parameter 'laporan_id' wajib diisi."}
    try:
        laporan_id = int(laporan_id)
    except (TypeError, ValueError):
        return {"ok": False, "pesan": "Parameter 'laporan_id' harus berupa angka."}

    baris = ambil_laporan_id(ctx, laporan_id)
    if baris is None:
        return {"ok": False, "pesan": f"Laporan #{laporan_id} tidak ditemukan atau bukan milik Anda."}

    pemilik = int(baris.get("user_id", 0))
    if pemilik != ctx.user_id and ctx.role != "admin":
        return {"ok": False, "pesan": "Hanya pemilik laporan (atau admin) yang boleh mengubah laporan ini."}

    status_lama = str(baris.get("status") or "draft")
    if status_lama not in STATUS_BOLEH_UBAH and ctx.role != "admin":
        return {
            "ok": False,
            "pesan": (
                f"Status laporan saat ini '{status_lama}' tidak mengizinkan perubahan. "
                "Laporan berstatus 'dikirim' atau 'diperiksa' harus direvisi lewat alur aplikasi."
            ),
            "status": status_lama,
        }

    perubahan: dict[str, Any] = {}
    for kolom in KOLOM_DIIZINKAN:
        if kolom in args and args[kolom] is not None:
            perubahan[kolom] = str(args[kolom]).strip()

    if not perubahan:
        return {"ok": False, "pesan": "Tidak ada kolom yang diusulkan untuk diubah."}

    # Validasi hasil gabungan (perubahan + nilai lama).
    gabung = {**baris, **perubahan}
    val = validasi_laporan(gabung)
    if not val["lengkap"]:
        return {
            "ok": False,
            "pesan": "Perubahan menghasilkan laporan yang tidak lengkap/valid.",
            "galat": val["galat"],
            "usul": perubahan,
        }

    if not _benar(args.get("konfirmasi")):
        return {
            "ok": True,
            "perlu_konfirmasi": True,
            "status": "menunggu_persetujuan",
            "laporan_id": laporan_id,
            "usul": perubahan,
            "pesan": (
                "Ini pratinjau perubahan. Belum ada yang disimpan. "
                "Panggil ulang dengan konfirmasi=true setelah pengguna menyetujui."
            ),
        }

    # Terapkan (nama kolom berasal dari daftar putih, nilai tetap terikat).
    penetapan = ", ".join(f"`{k}` = %s" for k in perubahan)
    nilai = list(perubahan.values()) + [laporan_id]
    eksekusi(f"UPDATE laporan_harian SET {penetapan} WHERE id = %s", nilai)

    catat_audit(
        user_id=ctx.user_id,
        aksi="ai_update_laporan",
        tabel_ref="laporan_harian",
        baris_id=laporan_id,
        ringkasan=f"Laporan #{laporan_id} diperbarui lewat asisten AI",
        data_lama={k: baris.get(k) for k in perubahan},
        data_baru=perubahan,
    )

    return {
        "ok": True,
        "status": "tersimpan",
        "laporan_id": laporan_id,
        "perubahan": perubahan,
        "pesan": f"Laporan #{laporan_id} berhasil diperbarui (status tetap '{status_lama}').",
        "sumber": [{"tipe": "laporan_harian", "id": laporan_id, "tanggal": str(baris.get("tanggal")), "kegiatan": perubahan.get("kegiatan", baris.get("kegiatan"))}],
    }


def _benar(nilai: Any) -> bool:
    """Konversi boolean longgar (``1``/``"true"``/``"ya"``/``True``)."""
    if isinstance(nilai, bool):
        return nilai
    return str(nilai or "").strip().lower() in {"1", "true", "ya", "yes", "setuju", "benar"}


# ---------------------------------------------------------------------------
# Definisi alat
# ---------------------------------------------------------------------------
def bangun() -> list[Tool]:
    """Kembalikan daftar alat catatan."""
    properti_cari = {
        "tanggal_dari": {"type": "string", "description": "Tanggal awal (YYYY-MM-DD). Bawaan 30 hari terakhir."},
        "tanggal_sampai": {"type": "string", "description": "Tanggal akhir (YYYY-MM-DD). Bawaan hari ini."},
        "kata_kunci": {"type": "string", "description": "Kata kunci bebas pada kegiatan/deskripsi/hasil/kendala."},
        "status": {"type": "string", "enum": list(STATUS_LAPORAN), "description": "Saring status laporan."},
        "user_id": {"type": "integer", "description": "Khusus mentor/admin: batasi ke pengguna tertentu."},
        "limit": {"type": "integer", "description": "Maksimal baris (bawaan 30)."},
    }
    properti_ubah = {
        "laporan_id": {"type": "integer", "description": "ID laporan yang akan diubah (wajib)."},
        "tanggal": {"type": "string", "description": "Tanggal baru (YYYY-MM-DD)."},
        "jam_mulai": {"type": "string", "description": "Jam mulai baru (HH:MM)."},
        "jam_selesai": {"type": "string", "description": "Jam selesai baru (HH:MM)."},
        "kegiatan": {"type": "string", "description": "Nama kegiatan baru."},
        "deskripsi": {"type": "string", "description": "Deskripsi baru."},
        "hasil": {"type": "string", "description": "Hasil baru."},
        "kendala": {"type": "string", "description": "Kendala baru."},
        "rencana_berikutnya": {"type": "string", "description": "Rencana berikutnya."},
        "konfirmasi": {"type": "boolean", "description": "Set true untuk benar-benar menyimpan (bawaan false = pratinjau)."},
    }
    return [
        Tool(
            nama="search_notes",
            deskripsi=(
                "Cari laporan harian kegiatan berdasarkan rentang tanggal, kata kunci, dan/atau status. "
                "Untuk peran karyawan selalu dibatasi ke laporan miliknya sendiri. "
                "Gunakan alat ini sebelum menjawab pertanyaan apa pun tentang isi/riwayat laporan."
            ),
            parameter=skema_objek(properti_cari),
            fungsi=search_notes,
        ),
        Tool(
            nama="update_note",
            deskripsi=(
                "Perbarui satu laporan harian (hanya pemilik; hanya saat status draft/perlu_revisi). "
                "Bawaan hanya PRATINJAU; set konfirmasi=true untuk menyimpan. Setiap perubahan dicatat ke audit log."
            ),
            parameter=skema_objek(properti_ubah, wajib=["laporan_id"]),
            fungsi=update_note,
        ),
    ]
