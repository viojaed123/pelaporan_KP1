"""Uji alat baru: pencarian web (``web_search``) dan agenda (``jadwal``).

Tidak menyentuh jaringan maupun basis data: seluruh ketergantungan eksternal
dipalsukan, dan hanya jalur murni/pratinjau yang diuji.
"""

from __future__ import annotations

import types

from app.tools import get_registry
from app.tools import search as search_mod
from app.tools.jadwal import (
    _batas_waktu,
    _benar,
    _norm_waktu,
    _validasi,
    buat_jadwal,
    daftar_jadwal,
    hapus_jadwal,
    ingatkan_jadwal,
    ubah_jadwal,
)
from app.tools.registry import ToolContext


# ---------------------------------------------------------------------------
# Registri
# ---------------------------------------------------------------------------
def test_registri_memuat_alat_baru() -> None:
    nama = set(get_registry().nama_semua())
    for alat in ("web_search", "buat_jadwal", "daftar_jadwal", "ubah_jadwal",
                 "hapus_jadwal", "ingatkan_jadwal"):
        assert alat in nama


# ---------------------------------------------------------------------------
# web_search
# ---------------------------------------------------------------------------
def _settings_palsu(**ubah: object) -> types.SimpleNamespace:
    basis = dict(
        ai_web_search_aktif=True,
        ai_searxng_url="",
        ai_web_search_timeout=1.0,
        ai_web_search_hasil=5,
        ai_web_search_cache_menit=0,
    )
    basis.update(ubah)
    return types.SimpleNamespace(**basis)


def test_web_search_nonaktif(monkeypatch) -> None:
    monkeypatch.setattr(search_mod, "get_settings", lambda: _settings_palsu(ai_web_search_aktif=False))
    hasil = search_mod.web_search(ToolContext(user_id=1), {"kueri": "apa itu rig"})
    assert hasil["ok"] is False
    assert "dinonaktifkan" in hasil["pesan"].lower()


def test_web_search_kueri_kosong(monkeypatch) -> None:
    monkeypatch.setattr(search_mod, "get_settings", lambda: _settings_palsu())
    hasil = search_mod.web_search(ToolContext(user_id=1), {"kueri": "   "})
    assert hasil["ok"] is False
    assert "kueri" in hasil["pesan"].lower()


def test_web_search_menggabungkan_hasil(monkeypatch) -> None:
    monkeypatch.setattr(search_mod, "get_settings", lambda: _settings_palsu())
    palsu = [{"judul": "Hasil", "tautan": "https://contoh.id", "ringkasan": "ringkas", "sumber": "searxng"}]
    monkeypatch.setattr(search_mod, "_cari_searxng", lambda q, j, b: palsu)
    hasil = search_mod.web_search(ToolContext(user_id=1), {"kueri": "berita energi"})
    assert hasil["ok"] is True
    assert hasil["jumlah"] == 1
    assert hasil["hasil"][0]["tautan"] == "https://contoh.id"


def test_cari_duckduckgo_mengurai_html(monkeypatch) -> None:
    class _Resp:
        def __init__(self) -> None:
            self.text = (
                '<a class="result-link" href="https://contoh.id/x">Pertamina</a>'
                '<td class="result-snippet">Ringkasan kegiatan</td>'
            )

        @staticmethod
        def raise_for_status() -> None:
            pass

    class _Klien:
        def __init__(self, **_: object) -> None:
            pass

        def __enter__(self) -> "_Klien":
            return self

        def __exit__(self, *a: object) -> None:
            pass

        def post(self, *a: object, **_: object) -> "_Resp":
            return _Resp()

    monkeypatch.setattr(search_mod, "_klien", lambda s: _Klien())
    hasil = search_mod._cari_duckduckgo("pertamina", 3)
    assert len(hasil) == 1
    assert hasil[0]["tautan"] == "https://contoh.id/x"
    assert hasil[0]["judul"] == "Pertamina"
    assert "kegiatan" in hasil[0]["ringkasan"]


def test_cari_searxng_mengurai_json(monkeypatch) -> None:
    class _Resp:
        def __init__(self) -> None:
            self.headers = {"content-type": "application/json"}

        @staticmethod
        def raise_for_status() -> None:
            pass

        @staticmethod
        def json() -> dict:
            return {
                "results": [
                    {
                        "url": "https://contoh.id/rig",
                        "title": "<b>Rig</b> Pertamina",
                        "content": "Penjelasan rig",
                    },
                    {"url": "https://contoh.id/2", "title": "Tanpa isi"},
                    {"url": "", "title": "Dilewati"},
                ]
            }

    class _Klien:
        def __init__(self, **_: object) -> None:
            pass

        def __enter__(self) -> "_Klien":
            return self

        def __exit__(self, *a: object) -> None:
            pass

        def __init__(self, **_: object) -> None:
            pass

        def __enter__(self) -> "_Klien":
            return self

        def __exit__(self, *a: object) -> None:
            pass

        def get(self, url: str, params: dict | None = None, headers: dict | None = None) -> "_Resp":
            return _Resp()

    monkeypatch.setattr(search_mod, "_klien", lambda s: _Klien())
    monkeypatch.setattr(search_mod, "get_settings", lambda: _settings_palsu(ai_searxng_url="http://example.local"))
    hasil = search_mod._cari_searxng("rig", 3, "id")
    assert len(hasil) == 2
    assert hasil[0]["tautan"] == "https://contoh.id/rig"
    assert "Rig Pertamina" in hasil[0]["judul"]
    assert hasil[1]["judul"] == "Tanpa isi"


# ---------------------------------------------------------------------------
# jadwal — fungsi murni
# ---------------------------------------------------------------------------
def test_norm_waktu_berbagai_format() -> None:
    assert _norm_waktu("2026-10-17") == "2026-10-17 08:00:00"
    assert _norm_waktu("2026-10-17 14:30") == "2026-10-17 14:30:00"
    assert _norm_waktu("2026-10-17T09:05:00") == "2026-10-17 09:05:00"
    assert _norm_waktu("17/10/2026 14:30") == "2026-10-17 14:30:00"
    assert _norm_waktu("bukan tanggal") is None
    assert _norm_waktu("") is None


def test_batas_waktu_rentang_tanggal() -> None:
    # Batas awal tanggal penuh mulai dari 00:00:00 ...
    assert _batas_waktu("2026-12-01", akhir=False) == "2026-12-01 00:00:00"
    # ... dan batas akhir mencakup seluruh hari (23:59:59), bukan 08:00.
    assert _batas_waktu("2026-12-01", akhir=True) == "2026-12-01 23:59:59"
    # Masukan dengan jam tetap dihormati apa adanya.
    assert _batas_waktu("2026-12-01 09:30", akhir=True) == "2026-12-01 09:30:00"
    assert _batas_waktu("", akhir=True) is None
    assert _batas_waktu("bukan tanggal", akhir=False) is None


def test_benar_longgar() -> None:
    assert _benar(True) is True
    assert _benar("ya") is True
    assert _benar("true") is True
    assert _benar("") is False
    assert _benar(0) is False


def test_validasi_judul_wajib() -> None:
    bersih, galat = _validasi({"mulai": "2026-10-17 09:00"})
    assert galat
    assert "judul" in " ".join(galat).lower()


def test_validasi_waktu_gagal() -> None:
    _, galat = _validasi({"judul": "Rapat", "mulai": "salah"})
    assert any("mulai" in g.lower() for g in galat)


def test_validasi_selesai_sebelum_mulai() -> None:
    _, galat = _validasi({
        "judul": "Rapat", "mulai": "2026-10-17 10:00", "selesai": "2026-10-17 09:00",
    })
    assert any("selesai" in g.lower() for g in galat)


def test_validasi_pengingat_di_luar_batas() -> None:
    _, galat = _validasi({
        "judul": "Rapat", "mulai": "2026-10-17 10:00", "pengingat_menit": 99999,
    })
    assert any("pengingat" in g.lower() for g in galat)


def test_validasi_sukses() -> None:
    bersih, galat = _validasi({"judul": "Rapat mingguan", "mulai": "2026-10-17 09:00"})
    assert galat == []
    assert bersih["judul"] == "Rapat mingguan"
    assert bersih["mulai"] == "2026-10-17 09:00:00"


# ---------------------------------------------------------------------------
# jadwal — jalur pratinjau (tanpa basis data)
# ---------------------------------------------------------------------------
def test_buat_jadwal_pratinjau() -> None:
    ctx = ToolContext(user_id=5, role="karyawan")
    hasil = buat_jadwal(ctx, {"judul": "Kunjungan lapangan", "mulai": "2026-10-18 07:30"})
    assert hasil["ok"] is True
    assert hasil["perlu_konfirmasi"] is True
    assert hasil["status"] == "menunggu_persetujuan"
    assert hasil["usul"]["mulai"] == "2026-10-18 07:30:00"


def test_buat_jadwal_tanpa_pengguna_gagal() -> None:
    hasil = buat_jadwal(ToolContext(user_id=0), {"judul": "X", "mulai": "2026-10-18 07:30", "konfirmasi": True})
    assert hasil["ok"] is False


def test_daftar_jadwal_tanpa_pengguna_gagal() -> None:
    hasil = daftar_jadwal(ToolContext(user_id=0), {})
    assert hasil["ok"] is False


def test_ubah_jadwal_id_kosong_gagal() -> None:
    hasil = ubah_jadwal(ToolContext(user_id=1), {"judul": "Baru"})
    assert hasil["ok"] is False


def test_hapus_jadwal_id_kosong_gagal() -> None:
    hasil = hapus_jadwal(ToolContext(user_id=1), {})
    assert hasil["ok"] is False


def test_ingatkan_jadwal_id_kosong_gagal() -> None:
    hasil = ingatkan_jadwal(ToolContext(user_id=1), {})
    assert hasil["ok"] is False
