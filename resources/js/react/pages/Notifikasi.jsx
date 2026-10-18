import { useEffect, useState } from 'react';
import api from '../api';

/** Notifikasi: daftar + penandaan dibaca (per item / semua). */
export default function Notifikasi() {
    const [data, setData] = useState([]);
    const [belum, setBelum] = useState(0);
    const [galat, setGalat] = useState('');

    const muat = () => {
        api.get('/notifikasi', { params: { per_halaman: 50 } })
            .then((res) => {
                setData(res.data.data.data ?? []);
                setBelum(res.data.belum_dibaca ?? 0);
            })
            .catch(() => setGalat('Gagal memuat notifikasi.'));
    };

    useEffect(muat, []);

    const tandai = async (id) => {
        await api.post(`/notifikasi/${id}/dibaca`);
        muat();
    };

    const tandaiSemua = async () => {
        await api.post('/notifikasi/dibaca-semua');
        muat();
    };

    return (
        <>
            <div className="baris-atas">
                <h2 className="judul-halaman">Notifikasi {belum > 0 ? `(${belum} baru)` : ''}</h2>
                {belum > 0 && <button className="btn" onClick={tandaiSemua}>Tandai semua dibaca</button>}
            </div>
            {galat && <div className="peringatan">{galat}</div>}

            <div className="kartu">
                <div className="kartu-tubuh">
                    {data.length === 0 ? (
                        <p className="tabel-kosong">Tidak ada notifikasi.</p>
                    ) : (
                        <ul className="daftar-notifikasi">
                            {data.map((n) => (
                                <li key={n.id} className={n.sudah_dibaca ? 'sudah-dibaca' : 'belum-dibaca'}>
                                    <p>{n.pesan}</p>
                                    {!n.sudah_dibaca && (
                                        <button className="btn" onClick={() => tandai(n.id)}>Tandai dibaca</button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
