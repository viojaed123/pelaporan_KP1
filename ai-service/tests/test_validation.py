"""Uji logika validasi kelengkapan laporan (fungsi murni)."""

from __future__ import annotations

from datetime import date, timedelta

from app.services.reporting import validasi_laporan, validasi_many


def _laporan_lengkap() -> dict:
    return {
        "tanggal": date.today().isoformat(),
        "jam_mulai": "08:00",
        "jam_selesai": "17:00",
        "kegiatan": "Mengikuti rapat koordinasi tim",
        "deskripsi": "Menghadiri rapat koordinasi untuk membahas progres mingguan tim.",
        "hasil": "Notulen rapat selesai dibuat.",
        "kendala": "Tidak ada.",
        "rencana_berikutnya": "Melanjutkan pekerjaan besok.",
    }


def test_laporan_lengkap_lolos() -> None:
    hasil = validasi_laporan(_laporan_lengkap())
    assert hasil["lengkap"] is True
    assert hasil["verdict"] == "LENGKAP"
    assert hasil["skor"] == 100
    assert hasil["galat"] == {}


def test_field_wajib_kosong_ditolak() -> None:
    hasil = validasi_laporan({"tanggal": date.today().isoformat()})
    assert hasil["lengkap"] is False
    assert "kegiatan" in hasil["galat"]
    assert "deskripsi" in hasil["galat"]
    assert hasil["verdict"] == "PERLU PERBAIKAN"


def test_tanggal_tidak_valid_dan_masa_depan() -> None:
    assert validasi_laporan({"tanggal": "bukan-tanggal", "kegiatan": "x", "deskripsi": "y"})["galat"]["tanggal"]
    besok_dua = (date.today() + timedelta(days=5)).isoformat()
    hasil = validasi_laporan({"tanggal": besok_dua, "kegiatan": "x", "deskripsi": "y"})
    assert "tanggal" in hasil["galat"]


def test_jam_selesai_lebih_awal_ditolak() -> None:
    data = _laporan_lengkap()
    data["jam_mulai"] = "17:00"
    data["jam_selesai"] = "08:00"
    hasil = validasi_laporan(data)
    assert "jam_selesai" in hasil["galat"]


def test_jam_format_pendek_dinormalkan() -> None:
    data = _laporan_lengkap()
    data["jam_mulai"] = "7:30"
    data["jam_selesai"] = "16:45"
    hasil = validasi_laporan(data)
    assert hasil["bersih"]["jam_mulai"] == "07:30"
    assert "jam_mulai" not in hasil["galat"]


def test_skor_sebagian_tanpa_field_opsional() -> None:
    data = {
        "tanggal": date.today().isoformat(),
        "kegiatan": "Rapat koordinasi tim",
        "deskripsi": "Menghadiri rapat koordinasi membahas progres.",
    }
    hasil = validasi_laporan(data)
    assert hasil["lengkap"] is True
    assert 0 < hasil["skor"] < 100


def test_validasi_many_agregat() -> None:
    lengkap = _laporan_lengkap()
    kurang = {"tanggal": date.today().isoformat(), "kegiatan": "", "deskripsi": ""}
    hasil = validasi_many([lengkap, kurang])
    assert hasil["jumlah"] == 2
    assert hasil["jumlah_lengkap"] == 1
    assert hasil["jumlah_perlu_perbaikan"] == 1
