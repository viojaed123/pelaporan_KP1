"""Uji pemuatan & penyulihan prompt (regresi: placeholder ``$nama``)."""

from __future__ import annotations

from app.prompts import domain, muat, render


def test_domain_termuat() -> None:
    teks = domain()
    assert "draft" in teks
    assert "perlu_revisi" in teks


def test_render_system_chat_mengisi_placeholder() -> None:
    # Regresi: parameter ``nama`` tidak boleh bertabrakan dengan nama berkas prompt.
    teks = render(
        "system_chat",
        nama="Vio Fahendra",
        role="karyawan",
        user_id=1,
        tanggal="2026-10-15",
        konteks_json="{}",
        domain="ATURAN",
    )
    assert "Vio Fahendra" in teks
    assert "2026-10-15" in teks
    assert "ATURAN" in teks
    assert "$nama" not in teks


def test_muat_berkas_tidak_ada() -> None:
    import pytest

    with pytest.raises(FileNotFoundError):
        muat("prompt-yang-tidak-ada")
