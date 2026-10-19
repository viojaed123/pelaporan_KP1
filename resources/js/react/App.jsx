import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import AppLayout from './layout/AppLayout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import RiwayatLaporan from './pages/RiwayatLaporan';
import BuatLaporan from './pages/BuatLaporan';
import Absensi from './pages/Absensi';
import Tugas from './pages/Tugas';
import Kasus from './pages/Kasus';
import KasusForm from './pages/KasusForm';
import Notifikasi from './pages/Notifikasi';
import Asisten from './pages/Asisten';
import Profil from './pages/Profil';
import RekapLaporan from './pages/RekapLaporan';
import RekapAbsensi from './pages/RekapAbsensi';
import RekapKasus from './pages/RekapKasus';
import RingkasanKegiatan from './pages/RingkasanKegiatan';
import KalenderAktivitas from './pages/KalenderAktivitas';

/* Penjaga rute: tanpa token → halaman masuk. */
function Jaga({ children }) {
    const { pengguna, sibuk } = useAuth();
    if (sibuk) return <div className="muat">Memuat…</div>;
    if (!pengguna) return <Navigate to="/masuk" replace />;
    return children;
}

/* Penjaga peran: halaman khusus mentor/admin (API tetap menolak 403). */
function PerluPeran({ peran, children }) {
    const { pengguna } = useAuth();
    if (!peran.includes(pengguna?.role)) {
        return <div className="peringatan">Halaman ini khusus untuk mentor/admin.</div>;
    }
    return children;
}

/* Bila sudah masuk, halaman masuk tidak perlu ditampilkan lagi. */
function SudahMasuk({ children }) {
    const { pengguna, sibuk } = useAuth();
    if (sibuk) return <div className="muat">Memuat…</div>;
    if (pengguna) return <Navigate to="/" replace />;
    return children;
}

export default function App() {
    return (
        <AuthProvider>
            <BrowserRouter basename="/app">
                <Routes>
                    <Route path="/masuk" element={<SudahMasuk><Login /></SudahMasuk>} />
                    <Route element={<Jaga><AppLayout /></Jaga>}>
                        <Route path="/" element={<Dashboard />} />
                        <Route path="/laporan/buat" element={<BuatLaporan />} />
                        <Route path="/laporan/riwayat" element={<RiwayatLaporan />} />
                        <Route path="/absensi" element={<Absensi />} />
                        <Route path="/tugas" element={<Tugas />} />
                        <Route path="/kasus" element={<Kasus />} />
                        <Route path="/kasus/buat" element={<KasusForm />} />
                        <Route path="/kasus/:id/edit" element={<KasusForm />} />
                        <Route path="/notifikasi" element={<Notifikasi />} />
                        <Route path="/asisten" element={<Asisten />} />
                        <Route path="/profil" element={<Profil />} />
                        <Route
                            path="/rekap/laporan"
                            element={<PerluPeran peran={['mentor', 'admin']}><RekapLaporan /></PerluPeran>}
                        />
                        <Route
                            path="/rekap/absensi"
                            element={<PerluPeran peran={['mentor', 'admin']}><RekapAbsensi /></PerluPeran>}
                        />
                        <Route
                            path="/rekap/kasus"
                            element={<PerluPeran peran={['mentor', 'admin']}><RekapKasus /></PerluPeran>}
                        />
                        <Route path="/ringkasan" element={<RingkasanKegiatan />} />
                        <Route path="/kalender" element={<KalenderAktivitas />} />
                        <Route path="*" element={<Navigate to="/" replace />} />
                    </Route>
                </Routes>
            </BrowserRouter>
        </AuthProvider>
    );
}
