import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';

/*
 * Halaman masuk SPA -- mendukung alur DUA LANGKAH:
 *   1. email + sandi;
 *   2. bila akun mengaktifkan 2FA, muncul kolom kode verifikasi (OTP).
 */
export default function Login() {
    const { masuk } = useAuth();
    const navigasi = useNavigate();

    const [email, setEmail] = useState('');
    const [sandi, setSandi] = useState('');
    const [ticket, setTicket] = useState(null);
    const [kode, setKode] = useState('');
    const [pesan, setPesan] = useState('');
    const [sibuk, setSibuk] = useState(false);

    const kirim = async (e) => {
        e.preventDefault();
        setPesan('');
        setSibuk(true);
        try {
            if (!ticket) {
                const res = await api.post('/auth/login', { email, password: sandi });
                if (res.data.dua_faktor) {
                    setTicket(res.data.ticket);
                    setPesan(res.data.pesan ?? 'Masukkan kode verifikasi.');
                } else {
                    masuk(res.data.token, res.data.pengguna);
                    navigasi('/');
                }
            } else {
                const res = await api.post('/auth/verifikasi', { ticket, kode });
                masuk(res.data.token, res.data.pengguna);
                navigasi('/');
            }
        } catch (err) {
            setPesan(err.response?.data?.pesan ?? 'Gagal menghubungi server. Coba lagi.');
        } finally {
            setSibuk(false);
        }
    };

    return (
        <div className="halaman-auth">
            <form className="kartu-auth" onSubmit={kirim}>
                <h1 className="judul-auth">Masuk</h1>
                <p className="teks-auth">
                    Sistem Pelaporan Kegiatan Harian (PHR)
                </p>

                {!ticket && (
                    <>
                        <div className="form-grup">
                            <label htmlFor="email">Alamat surel</label>
                            <input
                                id="email"
                                type="email"
                                className="isian"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                required
                                autoComplete="username"
                            />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="sandi">Kata sandi</label>
                            <input
                                id="sandi"
                                type="password"
                                className="isian"
                                value={sandi}
                                onChange={(e) => setSandi(e.target.value)}
                                required
                                autoComplete="current-password"
                            />
                        </div>
                    </>
                )}

                {ticket && (
                    <div className="form-grup">
                        <label htmlFor="kode">Kode verifikasi (6 angka dari surel)</label>
                        <input
                            id="kode"
                            type="text"
                            inputMode="numeric"
                            className="isian"
                            value={kode}
                            onChange={(e) => setKode(e.target.value)}
                            required
                            autoFocus
                        />
                    </div>
                )}

                {pesan && <div className="peringatan">{pesan}</div>}

                <button className="btn btn-utama" type="submit" disabled={sibuk}>
                    {sibuk ? 'Memproses…' : ticket ? 'Verifikasi' : 'Masuk'}
                </button>
            </form>
        </div>
    );
}
