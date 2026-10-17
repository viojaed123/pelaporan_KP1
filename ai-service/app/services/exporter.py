"""Ekspor dokumen: ``.xlsx`` (openpyxl) dan ``.docx`` (python-docx)."""

from __future__ import annotations

import re
from datetime import datetime
from pathlib import Path
from typing import Any, Sequence

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter
from docx import Document
from docx.shared import Pt

from .reporting import ke_tanggal

_KEPALA = ["Tanggal", "Kegiatan", "Deskripsi", "Jam Mulai", "Jam Selesai", "Status", "Hasil", "Kendala", "Rencana"]
_KUNCI = ["tanggal", "kegiatan", "deskripsi", "jam_mulai", "jam_selesai", "status", "hasil", "kendala", "rencana_berikutnya"]


def slug(teks: str, maks: int = 60) -> str:
    """Ubah teks menjadi potongan nama berkas yang aman."""
    teks = re.sub(r"[^\w\s-]", "", (teks or "").strip(), flags=re.UNICODE)
    teks = re.sub(r"[\s_]+", "-", teks).strip("-")
    return (teks or "ekspor")[:maks].lower()


def _stempel() -> str:
    return datetime.now().strftime("%Y%m%d_%H%M%S")


def ekspor_excel(
    laporan: Sequence[dict[str, Any]],
    *,
    tujuan: Path,
    judul: str = "Laporan Kegiatan Harian",
    periode: str = "",
) -> Path:
    """Tulis daftar laporan ke berkas ``.xlsx``; kembalikan jalur berkas."""
    tujuan = Path(tujuan)
    tujuan.parent.mkdir(parents=True, exist_ok=True)

    wb = Workbook()
    ws = wb.active
    ws.title = "Laporan"

    judul_sel = ws.cell(row=1, column=1, value=judul)
    judul_sel.font = Font(bold=True, size=14)
    ws.merge_cells(start_row=1, start_column=1, end_row=1, end_column=len(_KEPALA))
    if periode:
        ws.cell(row=2, column=1, value=f"Periode: {periode}")
        ws.merge_cells(start_row=2, start_column=1, end_row=2, end_column=len(_KEPALA))

    baris_kepala = 4
    for kolom, teks in enumerate(_KEPALA, start=1):
        sel = ws.cell(row=baris_kepala, column=kolom, value=teks)
        sel.font = Font(bold=True, color="FFFFFF")
        sel.fill = PatternFill("solid", fgColor="0072BC")
        sel.alignment = Alignment(vertical="center")

    for i, baris in enumerate(laporan, start=baris_kepala + 1):
        for kolom, kunci in enumerate(_KUNCI, start=1):
            nilai = baris.get(kunci)
            if kunci == "tanggal":
                nilai = ke_tanggal(nilai) or nilai
                if hasattr(nilai, "isoformat"):
                    nilai = nilai.isoformat()
            ws.cell(row=i, column=kolom, value="" if nilai is None else str(nilai))
        ws.cell(row=i, column=1).alignment = Alignment(horizontal="center")

    lebar = [12, 28, 45, 10, 11, 14, 30, 30, 30]
    for kolom, w in enumerate(lebar, start=1):
        ws.column_dimensions[get_column_letter(kolom)].width = w
    ws.freeze_panes = ws.cell(row=baris_kepala + 1, column=1)

    wb.save(tujuan)
    return tujuan


def ekspor_word(
    narasi: str,
    *,
    tujuan: Path,
    judul: str = "Laporan Kegiatan Kegiatan",
    periode: str = "",
    tabel: Sequence[dict[str, Any]] | None = None,
) -> Path:
    """Tulis narasi (dan tabel opsional) ke berkas ``.docx``."""
    tujuan = Path(tujuan)
    tujuan.parent.mkdir(parents=True, exist_ok=True)

    doc = Document()
    doc.add_heading(judul, level=0)
    if periode:
        p = doc.add_paragraph(f"Periode: {periode}")
        p.runs[0].italic = True

    for paragraf in (narasi or "").split("\n"):
        teks = paragraf.strip()
        if not teks:
            continue
        if re.match(r"^\d+\.\s", teks):
            doc.add_heading(teks, level=1)
        else:
            doc.add_paragraph(teks)

    if tabel:
        doc.add_heading("Lampiran: Daftar Kegiatan", level=1)
        t = doc.add_table(rows=1, cols=3)
        t.style = "Light Grid Accent 1"
        hdr = t.rows[0].cells
        for i, nama in enumerate(("Tanggal", "Kegiatan", "Status")):
            hdr[i].text = nama
            for run in hdr[i].paragraphs[0].runs:
                run.bold = True
                run.font.size = Pt(10)
        for baris in tabel:
            sel = t.add_row().cells
            sel[0].text = str(baris.get("tanggal_label") or baris.get("tanggal") or "")
            sel[1].text = str(baris.get("kegiatan") or "")
            sel[2].text = str(baris.get("status") or "")

    doc.save(tujuan)
    return tujuan


def nama_berkas(prefix: str, judul: str, periode: str, ekstensi: str) -> str:
    """Bentuk nama berkas, mis. ``laporan-mingguan-20261012-20261018_20261015_120000.xlsx``."""
    dasar = "-".join(x for x in (slug(judul), slug(periode)) if x)
    return f"{prefix}_{dasar or 'laporan'}_{_stempel()}.{ekstensi}"
