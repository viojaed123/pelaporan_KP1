"""Uji parsing skema permintaan (Pydantic)."""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.schemas import ChatRequest, NarrativeRequest, SummarizeRequest, ValidateRequest


def test_chat_request_defaults_dan_normalisasi_role() -> None:
    req = ChatRequest(pesan="Halo", user_id=1, role="KARYAWAN")
    assert req.role == "karyawan"
    assert req.session == 0
    assert req.konteks == {}


def test_chat_request_role_tidak_dikenal_jadi_karyawan() -> None:
    req = ChatRequest(pesan="Halo", role="superuser")
    assert req.role == "karyawan"


def test_chat_request_pesan_kosong_ditolak() -> None:
    with pytest.raises(ValidationError):
        ChatRequest(pesan="")


def test_summarize_request_defaults() -> None:
    req = SummarizeRequest(jenis="mingguan")
    assert req.jenis == "mingguan"
    assert req.parameter == {}


def test_narrative_request() -> None:
    req = NarrativeRequest(parameter={"user_id": 2})
    assert req.parameter["user_id"] == 2


def test_validate_request_dict_dan_list() -> None:
    satu = ValidateRequest(laporan={"kegiatan": "x"})
    assert isinstance(satu.laporan, dict)
    banyak = ValidateRequest(laporan=[{"kegiatan": "x"}, {"kegiatan": "y"}])
    assert isinstance(banyak.laporan, list)
    assert len(banyak.laporan) == 2


def test_validate_request_kosong_default() -> None:
    req = ValidateRequest()
    assert req.laporan == {}
