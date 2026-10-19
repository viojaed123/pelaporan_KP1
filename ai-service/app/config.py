"""Konfigurasi terpusat layanan AI.

Semua nilai berasal dari variabel lingkungan (atau berkas ``.env``). Nama field
sengaja memakai awalan ``ai_`` sehingga variabel lingkungan yang diharapkan
adalah versi huruf besarnya (``AI_...``) — sama dengan konstanta pada
``includes/config.php`` di sisi PHP.

Contoh: field :attr:`Settings.ai_service_key` dibaca dari ``AI_SERVICE_KEY``.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict

#: Direktori ``ai-service/`` (induk dari paket ``app``).
BASE_DIR: Path = Path(__file__).resolve().parent.parent


class Settings(BaseSettings):
    """Seluruh pengaturan layanan, tervalidasi saat impor pertama."""

    model_config = SettingsConfigDict(
        env_file=str(BASE_DIR / ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    # ------------------------------------------------------------------
    # Identitas & HTTP
    # ------------------------------------------------------------------
    ai_service_key: str = Field(
        default="ganti-kunci-layanan-ai-disini",
        description="Kunci layanan (header X-Service-Key). Wajib sama dengan AI_SERVICE_KEY di PHP.",
    )
    ai_host: str = Field(default="127.0.0.1", description="Alamat bind uvicorn.")
    ai_port: int = Field(default=8000, description="Port uvicorn.")
    ai_log_level: str = Field(default="info", description="Tingkat log aplikasi.")
    ai_docs_public: bool = Field(
        default=False,
        description="Bila true, /docs & /openapi.json bebas kunci (hanya untuk pengembangan).",
    )

    # ------------------------------------------------------------------
    # Ollama / model
    # ------------------------------------------------------------------
    ai_ollama_base_url: str = Field(default="http://localhost:11434")
    ai_model: str = Field(default="qwen2.5:7b", description="Model utama (Q4).")
    ai_model_fallback: str = Field(
        default="qwen2.5:3b", description="Model cadangan saat memori sempit."
    )
    ai_num_ctx: int = Field(default=8192, description="Ukuran konteks (num_ctx).")
    ai_temperature: float = Field(default=0.2)
    ai_top_p: float = Field(default=0.9)
    ai_keep_alive: str = Field(default="10m", description="Durasi model tetap di memori.")
    ai_request_timeout: float = Field(
        default=120.0, description="Batas tunggu satu panggilan ke Ollama (detik)."
    )
    ai_connect_timeout: float = Field(default=5.0)
    ai_max_retries: int = Field(default=2, description="Percobaan ulang saat galat transien.")
    ai_max_tool_iterations: int = Field(default=6, description="Batas putaran function calling.")
    ai_health_timeout: float = Field(default=4.0)
    ai_degrade_deterministic: bool = Field(
        default=False,
        description=(
            "Bila true, endpoint ringkas/validasi/narasi menghasilkan jawaban "
            "deterministik (tanpa model) ketika Ollama mati. Default false: "
            "Ollama mati => HTTP 503 dengan field 'detail'."
        ),
    )
    ai_history_limit: int = Field(
        default=10, description="Jumlah pesan percakapan sebelumnya yang dimuat sebagai konteks."
    )

    # ------------------------------------------------------------------
    # Google Gemini (model utama)
    # ------------------------------------------------------------------
    ai_gemini_api_key: str = Field(
        default="",
        description=(
            "Kunci API Google AI Studio (AIza...). Kosong = Gemini dilewati dan "
            "layanan memakai Ollama seperti sediakala."
        ),
    )
    ai_gemini_base_url: str = Field(
        default="https://generativelanguage.googleapis.com/v1beta",
        description="Alamat dasar REST Gemini (bisa diganti lewat proxy).",
    )
    ai_gemini_model: str = Field(
        default="gemini-3.8-flash", description="Model Gemini utama (function calling)."
    )
    ai_gemini_model_fallback: str = Field(
        default="gemini-3.5-flash-lite",
        description="Model Gemini cadangan bila model utama gagal/rate limit.",
    )
    ai_gemini_search_model: str = Field(
        default="gemini-3.5-flash-lite",
        description="Model pemanggil Google Search grounding (alat web_search).",
    )
    ai_gemini_search_timeout: float = Field(
        default=30.0, description="Batas tunggu satu pencarian grounding Gemini (detik)."
    )

    # ------------------------------------------------------------------
    # Basis data
    # ------------------------------------------------------------------
    ai_db_host: str = Field(default="localhost")
    ai_db_port: int = Field(default=3306)
    ai_db_name: str = Field(default="pelaporan")
    ai_db_app_user: str = Field(default="pelaporan_app")
    ai_db_app_password: str = Field(default="GantiDenganKataSandiKuat_2026!")
    ai_db_readonly_user: str = Field(default="bi_reader")
    ai_db_readonly_password: str = Field(default="BI_ReadOnly_Kuat_2026!")
    ai_db_prefer_readonly: bool = Field(
        default=True,
        description=(
            "Gunakan akun baca-saja untuk kueri. Bila akun tersebut belum diberi "
            "hak (mis. GRANT view di powerbi/sql/02_views.sql belum dijalankan), "
            "layanan otomatis jatuh ke akun aplikasi."
        ),
    )
    ai_db_connect_timeout: float = Field(default=5.0)

    # ------------------------------------------------------------------
    # Ekspor dokumen
    # ------------------------------------------------------------------
    ai_export_dir: str = Field(
        default="exports",
        description="Folder keluaran .xlsx/.docx (relatif terhadap ai-service/ bila bukan absolut).",
    )

    # ------------------------------------------------------------------
    # Pencadangan basis data
    # ------------------------------------------------------------------
    ai_backup_dir: str = Field(
        default="../backups/db", description="Folder berkas .sql (sama dengan BACKUP_DIR PHP)."
    )
    ai_mysqldump_bin: str = Field(default=r"C:\xampp\mysql\bin\mysqldump.exe")
    ai_mysql_bin: str = Field(default=r"C:\xampp\mysql\bin\mysql.exe")
    ai_backup_keep: int = Field(default=10, description="Jumlah berkas cadangan yang disimpan.")
    ai_restore_aktif: bool = Field(
        default=True,
        description="Bila false, alat restore_database selalu menolak (pengaman ganda).",
    )
    ai_proses_timeout: float = Field(
        default=300.0, description="Batas waktu proses mysqldump/mysql (detik)."
    )

    # ------------------------------------------------------------------
    # Pekerja antrean (ai_jobs)
    # ------------------------------------------------------------------
    ai_jobs_poll_interval: float = Field(default=3.0, description="Jeda antar-polling pekerja.")
    ai_jobs_batch: int = Field(default=1, description="Jumlah job yang diproses per putaran.")
    ai_jobs_enabled: bool = Field(
        default=True, description="Bila false, pekerja tidak mengklaim job baru."
    )

    # ------------------------------------------------------------------
    # Pencarian web (SearXNG)
    # ------------------------------------------------------------------
    ai_web_search_aktif: bool = Field(
        default=True,
        description="Bila false, alat web_search selalu menolak (kembalikan pesan nonaktif).",
    )
    ai_searxng_url: str = Field(
        default="",
        description=(
            "Alamat dasar instans SearXNG, mis. http://127.0.0.1:8888. "
            "Kosong = lewati SearXNG dan langsung pakai cadangan DuckDuckGo."
        ),
    )
    ai_web_search_timeout: float = Field(
        default=12.0, description="Batas tunggu satu permintaan pencarian web (detik)."
    )
    ai_web_search_hasil: int = Field(
        default=5, description="Jumlah hasil pencarian yang dikembalikan (1-10)."
    )
    ai_web_search_cache_menit: int = Field(
        default=5, description="Lama cache hasil pencarian (menit) untuk menghemat permintaan."
    )
    ai_http_ca_bundle: str = Field(
        default="",
        description=(
            "Jalur berkas PEM berisi CA tambahan untuk verifikasi TLS permintaan "
            "web (mis. sertifikat root proxy perusahaan). Kosong = pakai bawaan certifi."
        ),
    )

    # ------------------------------------------------------------------
    # Properti turunan (path absolut)
    # ------------------------------------------------------------------
    @property
    def backup_dir_path(self) -> Path:
        """Folder cadangan sebagai :class:`Path` absolut."""
        return self._resolve(self.ai_backup_dir)

    @property
    def export_dir_path(self) -> Path:
        """Folder ekspor sebagai :class:`Path` absolut."""
        return self._resolve(self.ai_export_dir)

    @staticmethod
    def _resolve(value: str) -> Path:
        p = Path(value)
        return p if p.is_absolute() else (BASE_DIR / p).resolve()

    @property
    def model_names(self) -> list[str]:
        """Daftar model yang dicoba, berurutan (utama lalu cadangan)."""
        names = [self.ai_model]
        if self.ai_model_fallback and self.ai_model_fallback != self.ai_model:
            names.append(self.ai_model_fallback)
        return names


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Kembalikan instansi :class:`Settings` tunggal (di-cache)."""
    return Settings()


def reset_settings_cache() -> None:
    """Bersihkan cache (dipakai pengujian)."""
    get_settings.cache_clear()
