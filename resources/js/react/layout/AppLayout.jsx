import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import Ikon from '../components/Ikon';

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
        { key: 'ringkasan', ikon: 'grafik', label: 'Ringkasan Kegiatan', to: '/ringkasan' },
        { key: 'kalender', ikon: 'kalender', label: 'Kalender Aktivitas', to: '/kalender' },
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

    const peran = pengguna?.role ?? 'karyawan';
    const daftar = MENU[peran] ?? MENU.karyawan;

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
                        <NavLink className="tombol-lonceng" to="/notifikasi" title="Notifikasi">
                            <Ikon nama="lonceng" />
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
