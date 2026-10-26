import { useCallback, useEffect, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import Ikon from '../components/Ikon';
import api from '../api';

/** Baca preferensi tema tersimpan (kunci sama dengan assets/js/toggle.js). */
function temaGelapTersimpan() {
    try {
        return localStorage.getItem('phr_tema') === 'gelap';
    } catch (e) {
        return false;
    }
}

/** Terapkan tema ke <html> — sinkron dengan atribut yang dipakai CSS. */
function terapkanTema(gelap) {
    const r = document.documentElement;
    if (gelap) {
        r.setAttribute('data-tema', 'gelap');
        r.classList.add('dark');
    } else {
        r.removeAttribute('data-tema');
        r.classList.remove('dark');
    }
    try {
        localStorage.setItem('phr_tema', gelap ? 'gelap' : 'terang');
    } catch (e) {
        /* localStorage bisa diblokir; tema tetap berlaku untuk sesi ini. */
    }
}

/*
 * Tata letak SPA: sidebar + topbar -- meniru struktur & kelas CSS
 * layouts/app.blade.php sehingga identitas visual PHR TIDAK BERUBAH.
 */
const MENU = {
    karyawan: [
        { grup: 'Utama' },
        { key: 'dashboard', ikon: 'grafik', label: 'Dashboard', to: '/' },
        { key: 'buat', ikon: 'tulis', label: 'Buat Laporan', to: '/laporan/buat' },
        { key: 'riwayat', ikon: 'jam', label: 'Riwayat Laporan', to: '/laporan/riwayat' },
        { key: 'absensi', ikon: 'kalender', label: 'Absensi', to: '/absensi' },
        { key: 'tugas', ikon: 'dokumen', label: 'Tugas Saya', to: '/tugas' },
        { grup: 'Lain-lain' },
        { key: 'kasus', ikon: 'dokumen', label: 'Kasus', to: '/kasus' },
        { key: 'ringkasan', ikon: 'grafik', label: 'Ringkasan Kegiatan', to: '/ringkasan' },
        { key: 'kalender', ikon: 'kalender', label: 'Kalender Aktivitas', to: '/kalender' },
        { key: 'cost-avoidance', ikon: 'buku-besar', label: 'Cost Avoidance', to: '/cost-avoidance' },
        { key: 'asisten', ikon: 'grafik', label: 'Asisten AI', to: '/asisten' },
        { key: 'notifikasi', ikon: 'jam', label: 'Notifikasi', to: '/notifikasi' },
        { key: 'profil', ikon: 'pengguna', label: 'Profil', to: '/profil' },
    ],
    mentor: [
        { grup: 'Utama' },
        { key: 'dashboard', ikon: 'grafik', label: 'Dashboard', to: '/' },
        { key: 'riwayat', ikon: 'jam', label: 'Riwayat Laporan', to: '/laporan/riwayat' },
        { key: 'tugas', ikon: 'dokumen', label: 'Penugasan', to: '/tugas' },
        { grup: 'Analitik' },
        { key: 'rekap-laporan', ikon: 'dokumen', label: 'Rekap Laporan', to: '/rekap/laporan' },
        { key: 'rekap-absensi', ikon: 'kalender', label: 'Rekap Absensi', to: '/rekap/absensi' },
        { key: 'rekap-kasus', ikon: 'dokumen', label: 'Rekap Kasus', to: '/rekap/kasus' },
        { key: 'ringkasan', ikon: 'grafik', label: 'Ringkasan Kegiatan', to: '/ringkasan' },
        { key: 'kalender', ikon: 'kalender', label: 'Kalender Aktivitas', to: '/kalender' },
        { key: 'cost-avoidance', ikon: 'buku-besar', label: 'Cost Avoidance', to: '/cost-avoidance' },
        { grup: 'Lain-lain' },
        { key: 'kasus', ikon: 'dokumen', label: 'Kasus', to: '/kasus' },
        { key: 'asisten', ikon: 'grafik', label: 'Asisten AI', to: '/asisten' },
        { key: 'notifikasi', ikon: 'jam', label: 'Notifikasi', to: '/notifikasi' },
        { key: 'profil', ikon: 'pengguna', label: 'Profil', to: '/profil' },
    ],
};
MENU.admin = MENU.mentor;

export default function AppLayout() {
    const { pengguna, keluar } = useAuth();
    const navigasi = useNavigate();
    const [sidebarTampil, setSidebarTampil] = useState(false);
    const [menuUser, setMenuUser] = useState(false);
    const [gelap, setGelap] = useState(temaGelapTersimpan);
    const [jmlBelum, setJmlBelum] = useState(0);

    // Sinkronkan atribut <html> dengan state (toggle.js di <head> sudah
    // menerapkan preferensi awal sebelum React dimuat).
    useEffect(() => {
        terapkanTema(gelap);
    }, [gelap]);

    const gantiTema = useCallback(() => setGelap((v) => !v), []);

    const peran = pengguna?.role ?? 'karyawan';
    const daftar = MENU[peran] ?? MENU.karyawan;

    useEffect(() => {
        let aktif = true;
        const muat = async () => {
            try {
                const res = await api.get('/notifikasi/jumlah');
                if (aktif) {
                    setJmlBelum(Number(res.data?.jumlah ?? 0));
                }
            } catch (e) {
                /* diam saja */
            }
        };
        muat();
        const id = setInterval(muat, 60000);
        return () => {
            aktif = false;
            clearInterval(id);
        };
    }, []);

    const lakukanKeluar = async () => {
        await keluar();
        navigasi('/masuk');
    };

    return (
        <div className="app">
            <nav className={`sidebar${sidebarTampil ? ' tampil' : ''}`} id="sidebar">
                <div className="sidebar-brand">
                    <strong>PHR</strong>
                    <span>Pelaporan Kegiatan Harian</span>
                </div>
                <div className="sidebar-nav">
                    {daftar.map((item, i) =>
                        item.grup ? (
                            <div className="nav-grup" key={'g' + i}>{item.grup}</div>
                        ) : (
                            <NavLink
                                key={item.key}
                                to={item.to}
                                className={({ isActive }) => (isActive ? 'nav-aktif' : '')}
                                onClick={() => setSidebarTampil(false)}
                            >
                                <Ikon nama={item.ikon} />
                                <span>{item.label}</span>
                            </NavLink>
                        ),
                    )}
                </div>
                <div className="sidebar-footer">
                    <button type="button" className="nav-tombol" onClick={lakukanKeluar}>
                        <Ikon nama="keluar" />
                        <span>Keluar</span>
                    </button>
                </div>
            </nav>

            <div className="area-utama">
                <header className="topbar">
                    <button
                        className="tombol-burger"
                        type="button"
                        aria-label="Buka menu"
                        onClick={() => setSidebarTampil((v) => !v)}
                    >
                        <Ikon nama="buku-besar" />
                    </button>
                    <h1 className="judul-halaman">Sistem Pelaporan Kegiatan Harian</h1>
                    <div className="topbar-kanan">
                        <button
                            type="button"
                            id="tombolTema"
                            className="tombol-lonceng"
                            aria-label="Ganti tema"
                            title="Ganti tema terang/gelap"
                            onClick={gantiTema}
                            style={{
                                border: 0,
                                background: 'transparent',
                                cursor: 'pointer',
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                padding: 6,
                            }}
                        >
                            {gelap ? '\u2600\uFE0F' : '\uD83C\uDF19'}
                        </button>
                        <NavLink className="tombol-lonceng" to="/notifikasi" title="Notifikasi">
                            <Ikon nama="lonceng" />
                            {jmlBelum > 0 && <span className="badge-lonceng">{jmlBelum > 99 ? '99+' : jmlBelum}</span>}
                            {jmlBelum > 0 && <span className="badge-lonceng">{jmlBelum > 99 ? '99+' : jmlBelum}</span>}
                        </NavLink>
                        <div className="dropdown">
                            <button
                                className="dropdown-tombol"
                                type="button"
                                onClick={() => setMenuUser((v) => !v)}
                            >
                                <span className="avatar avatar-kecil">
                                    {(pengguna?.nama ?? '?').slice(0, 1).toUpperCase()}
                                </span>
                                <span className="sembunyi-mobile">{pengguna?.nama ?? ''}</span>
                                <span>&#9662;</span>
                            </button>
                            {menuUser && (
                                <div className="dropdown-menu tampil">
                                    <div className="label-menu">
                                        {pengguna?.nama}
                                        <br />
                                        <small>{peran}</small>
                                    </div>
                                    <NavLink to="/profil" onClick={() => setMenuUser(false)}>Profil Saya</NavLink>
                                    <button type="button" onClick={lakukanKeluar}>Keluar</button>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                <main className="isi" id="konten-utama">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
