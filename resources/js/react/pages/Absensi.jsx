import { useEffect, useState } from 'react';
import api from '../api';

/*
 * Absensi: status hari ini + riwayat. Check-in / check-out lewat
 * tombol; lokasi opsional dari teks atau GPS.
 */
export default function Absensi() {
    const [riwayat, setRiwayat] = useState([]);
    const [pesan, setPesan] = useState('');
    const [galat, setGalat] = useState('');
    const [sibuk, setSibuk] = useState(false);

    const muat = () => {
        api.get('/absensi/riwayat', { params: { per_halaman: 10 } })
            .then((res) => setRiwayat(res.data.data.data ?? []))
            .catch(() => setGalat('Gagal memuat data absensi.'));
    };

    useEffect(muat, []);

    const hariIni = riwayat.find((a) => (a.tanggal ?? '').slice(0, 10) === new Date().toISOString().slice(0, 10));

    const aksi = async (url) => {
        setSibuk(true);
        setPesan('');
        setGalat('');
        try {
            const res = await api.post(url, {});
            setPesan(res.data.pesan);
            muat();
        } catch (err) {
            setGalat(err.response?.data?.pesan ?? 'Gagal memproses absensi.');
        } finally {
            setSibuk(false);
        }
    };

    return (
        <>
            <h2 className="judul-halaman">Absensi</h2>

            <div className="kartu">
                <div className="kartu-kepala">Kehadiran Hari Ini</div>
                <div className="kartu-tubuh">
                    {!hariIni && <p>Anda belum check-in hari ini.</p>}
                    {hariIni && (
                        <p>
                            Masuk <strong>{(hariIni.jam_masuk ?? '—').slice(0, 5)}</strong>
                            {' · '}
                            Pulang <strong>{(hariIni.jam_pulang ?? '—').slice(0, 5)}</strong>
                            {' '}
                            <span className="badge badge-hijau">{hariIni.status}</span>
                        </p>
                    )}
                    {pesan && <div className="sukses">{pesan}</div>}
                    {galat && <div className="peringatan">{galat}</div>}
                    <div className="baris-tombol">
                        <button
                            className="btn btn-utama"
                            disabled={sibuk || !!hariIni}
                            onClick={() => aksi('/absensi/check-in')}
                        >
                            Check-in
                        </button>
                        <button
                            className="btn"
                            disabled={sibuk || !hariIni || !!hariIni.jam_pulang}
                            onClick={() => aksi('/absensi/check-out')}
                        >
                            Check-out
                        </button>
                    </div>
                </div>
            </div>

            <div className="kartu">
                <div className="kartu-kepala">Riwayat</div>
                <div className="kartu-tubuh">
                    {riwayat.length === 0 ? (
                        <p className="tabel-kosong">Belum ada data absensi.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Masuk</th>
                                    <th>Pulang</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {riwayat.map((a) => (
                                    <tr key={a.id}>
                                        <td>{(a.tanggal ?? '').slice(0, 10)}</td>
                                        <td>{(a.jam_masuk ?? '—').slice(0, 5)}</td>
                                        <td>{(a.jam_pulang ?? '—').slice(0, 5)}</td>
                                        <td><span className="badge badge-hijau">{a.status}</span></td>
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
