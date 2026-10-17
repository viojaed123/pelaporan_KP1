"""Paket layanan AI (FastAPI) untuk Sistem Pelaporan Kegiatan Harian.

Gateway tunggal antara aplikasi PHP dan model Ollama (Qwen 2.5). Modul-modul
di dalam paket ini sengaja dipisah rapi:

* ``config``          -- konfigurasi terpusat (pydantic-settings).
* ``db``              -- akses MariaDB/MySQL ber-parameter (PyMySQL).
* ``ollama_client``   -- klien HTTP asinkron ke Ollama.
* ``agent``           -- gelung agen (function calling).
* ``tools``           -- perangkat yang dapat dipanggil model.
* ``services``        -- logika domain murni (validasi, ringkasan, ekspor).
* ``routers``         -- endpoint HTTP yang cocok dengan kontrak PHP.
* ``jobs``            -- pekerja latar belakang untuk tabel ``ai_jobs``.
"""

__all__ = ["__version__"]

__version__ = "1.0.0"
