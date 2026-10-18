import { useEffect, useState } from 'react';
import api from '../api';

/*
 * Tugas: daftar penugasan + pembaruan status (baru → dikerjakan → selesai).
 * Mentor/admin dapat memfilter per penerima; karyawan hanya melihat
 * tugas miliknya (dijelaskan API).
 */
export default function Tugas() {
    const [data, setData] = useState([]);
    const [galat, setGalat] = useState('');
    const [sibuk, setSibuk] = useState(0);

    const muat = () => {
        api.get('/tugas', { params: { per_halaman: 50 } })
            .then((res) => setData(res.data.data.data ?? []))
            .catch(() => setGalat('Gagal memuat tugas.'));
    };

    useEffect(muat, []);

    const ubahStatus = async (id, status) => {
        setSibuk(id);
        try {
            await api.patch(`/tugas/${id}/status`, { status });
            muat();
        } catch (err) {
            setGalat(err.response?.data?.pesan ?? 'Gagal memperbarui status.');
        } finally {
            setSibuk(0);
        }
    };

    const statusBerikut = { baru: 'dikerjakan', dikerjakan: 'selesai' };

    return (
        <>
            <h2 className="judul-halaman">Tugas</h2>
            {galat && <div className="peringatan">{galat}</div>}

            <div className="kartu">
                <div className="kartu-tubuh">
                    {data.length === 0 ? (
                        <p className="tabel-kosong">Belum ada tugas.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Judul</th>
                                    <th>Deskripsi</th>
                                    <th>Tenggat</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((t) => (
                                    <tr key={t.id}>
                                        <td>{t.judul}</td>
                                        <td>{t.deskripsi ?? '—'}</td>
                                        <td>{t.tenggat ?? '—'}</td>
                                        <td><span className={`badge badge-${t.status === 'selesai' ? 'hijau' : t.status === 'dikerjakan' ? 'kuning' : 'biru'}`}>{t.status}</span></td>
                                        <td>
                                            {statusBerikut[t.status] && (
                                                <button
                                                    className="btn"
                                                    disabled={sibuk === t.id}
                                                    onClick={() => ubahStatus(t.id, statusBerikut[t.status])}
                                                >
                                                    {t.status === 'baru' ? 'Mulai Kerjakan' : 'Tandai Selesai'}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </>
    );
}
