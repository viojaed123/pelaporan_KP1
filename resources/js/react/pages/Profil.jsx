import { useState } from 'react';
import api from '../api';
import { useAuth } from '../context/AuthContext';

/** Profil: data akun + perubahan kata sandi (dengan riwayat). */
export default function Profil() {
    const { pengguna, keluar } = useAuth();
    const [f, setF] = useState({ password_lama: '', password_baru: '', password_baru_confirmation: '' });
    const [pesan, setPesan] = useState('');
    const [galat, setGalat] = useState('');
    const [sukses, setSukses] = useState(false);
    const [sibuk, setSibuk] = useState(false);

    const ubah = (k) => (e) => setF({ ...f, [k]: e.target.value });

    const kirim = async (e) => {
        e.preventDefault();
        setSibuk(true);
        setPesan('');
        setGalat('');
        setSukses(false);
        try {
            const res = await api.post('/auth/ganti-password', f);
            setPesan(res.data.pesan);
            setSukses(true);
            setF({ password_lama: '', password_baru: '', password_baru_confirmation: '' });
        } catch (err) {
            setGalat(err.response?.data?.pesan ?? 'Gagal mengubah kata sandi.');
        } finally {
            setSibuk(false);
        }
    };

    return (
        <>
            <h2 className="judul-halaman">Profil</h2>

            <div className="kartu">
                <div className="kartu-kepala">Data Akun</div>
                <div className="kartu-tubuh">
                    <p><strong>Nama:</strong> {pengguna?.nama}</p>
                    <p><strong>Surel:</strong> {pengguna?.email}</p>
                    <p><strong>Peran:</strong> {pengguna?.role}</p>
                    <p><strong>Status:</strong> {pengguna?.status}</p>
                </div>
            </div>

            <div className="kartu">
                <div className="kartu-kepala">Ubah Kata Sandi</div>
                <div className="kartu-tubuh">
                    {pesan && <div className={sukses ? 'sukses' : 'peringatan'}>{pesan}</div>}
                    <form onSubmit={kirim}>
                        <div className="form-grup">
                            <label htmlFor="pl">Kata sandi lama</label>
                            <input id="pl" type="password" className="isian" value={f.password_lama} onChange={ubah('password_lama')} required />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="pb">Kata sandi baru</label>
                            <input id="pb" type="password" className="isian" value={f.password_baru} onChange={ubah('password_baru')} required />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="pbc">Ulangi kata sandi baru</label>
                            <input id="pbc" type="password" className="isian" value={f.password_baru_confirmation} onChange={ubah('password_baru_confirmation')} required />
                        </div>
                        <button className="btn btn-utama" type="submit" disabled={sibuk}>
                            {sibuk ? 'Menyimpan…' : 'Simpan'}
                        </button>
                    </form>
                </div>
            </div>

            <div className="kartu">
                <div className="kartu-tubuh">
                    <button className="btn" onClick={keluar}>Keluar dari sesi ini</button>
                </div>
            </div>
        </>
    );
}
