"""Uji pemformatan ringkasan, statistik, tren, dan tanggal."""

from __future__ import annotations

from datetime import date

from app.services.reporting import (
    analisis_tren,
    bangun_ringkasan,
    daftar_hari,
    hari_kerja,
    label_periode,
    rentang_bulan,
    rentang_minggu,
    statistik_kegiatan,
)


def _baris(tanggal: str, kegiatan: str, status: str = "draft", kendala: str = "") -> dict:
    return {"id": 1, "user_id": 1, "tanggal": tanggal, "kegiatan": kegiatan, "status": status, "kendala": kendala}


def test_rentang_minggu_senin_minggu() -> None:
    # 2026-10-14 adalah hari Rabu.
    dari, sampai = rentang_minggu(date(2026, 10, 14))
    assert dari.weekday() == 0  # Senin
    assert sampai.weekday() == 6  # Minggu
    assert dari == date(2026, 10, 12)
    assert sampai == date(2026, 10, 18)


def test_rentang_bulan() -> None:
    dari, sampai = rentang_bulan(date(2026, 2, 15))
    assert dari == date(2026, 2, 1)
    assert sampai == date(2026, 2, 28)


def test_label_periode() -> None:
    assert label_periode(date(2026, 10, 5), date(2026, 10, 11), "mingguan") == "5–11 Oktober 2026"
    assert label_periode(date(2026, 10, 1), date(2026, 10, 31), "bulanan") == "Oktober 2026"
    assert label_periode(date(2026, 10, 7), date(2026, 10, 7), "harian") == "7 Oktober 2026"


def test_daftar_hari_dan_hari_kerja() -> None:
    assert len(daftar_hari(date(2026, 10, 5), date(2026, 10, 11))) == 7
    # 5-9 Okt 2026 = Senin-Jumat (5 hari kerja)
    assert len(hari_kerja(date(2026, 10, 5), date(2026, 10, 11))) == 5


def test_statistik_kegiatan_dan_status() -> None:
    rows = [
        _baris("2026-10-10", "alur data", "diperiksa"),
        _baris("2026-10-11", "rapat", "dikirim"),
        _baris("2026-10-11", "dokumentasi", "diperiksa"),
    ]
    stat = statistik_kegiatan(rows, dari=date(2026, 10, 5), sampai=date(2026, 10, 11))
    assert stat["jumlah_laporan"] == 3
    assert stat["jumlah_hari"] == 2
    assert stat["status"]["diperiksa"] == 2
    assert len(stat["per_hari"]) == 2


def test_bangun_ringkasan_deterministik() -> None:
    rows = [
        _baris("2026-10-10", "mempelajari alur data", "diperiksa", "jaringan lambat"),
        _baris("2026-10-11", "rapat koordinasi", "dikirim"),
    ]
    hasil = bangun_ringkasan(rows, dari=date(2026, 10, 5), sampai=date(2026, 10, 11), nama="Vio")
    assert hasil["periode"]["label"] == "5–11 Oktober 2026"
    assert hasil["statistik"]["jumlah_laporan"] == 2
    assert "Vio" in hasil["narasi"]
    assert "2 laporan" in hasil["narasi"]
    assert "mempelajari alur data" in hasil["narasi"]
    assert len(hasil["tabel"]) == 2


def test_bangun_ringkasan_kosong() -> None:
    hasil = bangun_ringkasan([], dari=date(2026, 10, 5), sampai=date(2026, 10, 11), nama="Vio")
    assert hasil["statistik"]["jumlah_laporan"] == 0
    assert "belum ada laporan" in hasil["narasi"]


def test_analisis_tren_celah_dan_absensi() -> None:
    rows = [_baris("2026-10-05", "kegiatan senin")]
    absensi = [{"tanggal": "2026-10-05", "status": "hadir"}]
    tren = analisis_tren(rows, dari=date(2026, 10, 5), sampai=date(2026, 10, 9), baris_absensi=absensi)
    assert tren["hari_kerja"] == 5
    assert tren["jumlah_hari_kosong"] == 4
    assert "2026-10-06" in tren["hari_kosong"]
    assert tren["jumlah_laporan"] == 1
