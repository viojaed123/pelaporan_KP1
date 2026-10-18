import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';

/*
 * Riwayat laporan milik pengguna (mentor/admin: semua, dengan filter
 * bulan). Memakai endpoint /api/v1/laporan/riwayat.
 */
export default function RiwayatLaporan() {
    const [data, setData] = useState([]);
    const [total, setTotal] = useState(0);
    const [halaman, setHalaman] = useState(1);
    const [bulan, setBulan] = useState('');
    const [galat, setGalat] = useState('');

    useEffect(() => {
        api.get('/laporan/riwayat', { params: { per_halaman: 10, halaman, bulan: bulan || undefined } })
            .then((res) => {
                setData(res.data.data.data ?? []);
                setTotal(res.data.data.total ?? 0);
            })
            .catch(() => setGalat('Gagal memuat riwayat laporan.'));
    }, [halaman, bulan]);

    const totalHalaman = Math.max(1, Math.ceil(total / 10));

    return (
        <>
            <div className="baris-atas">
                <h2 className="judul-halaman">Riwayat Laporan</h2>
                <Link className="btn btn-utama" to="/laporan/buat">+ Buat Laporan</Link>
            </div>

            <div className="form-grup">
                <label htmlFor="bulan">Filter bulan</label>
                <input
                    id="bulan"
                    type="month"
                    className="isian"
                    value={bulan}
                    onChange={(e) => { setBulan(e.target.value); setHalaman(1); }}
                />
            </div>

            {galat && <div className="peringatan">{galat}</div>}

            <div className="kartu">
                <div className="kartu-tubuh">
                    {data.length === 0 ? (
                        <p className="tabel-kosong">Belum ada laporan.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Kegiatan</th>
                                    <th>Lokasi</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((l) => (
                                    <tr key={l.id}>
                                        <td>{(l.tanggal ?? '').slice(0, 10)}</td>
                                        <td>{l.kegiatan}</td>
                                        <td>{l.lokasi_teks ?? '—'}</td>
                                        <td><span className={`badge badge-${badge(l.status)}`}>{l.status}</span></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
                {totalHalaman > 1 && (
                    <div className="kartu-kaki">
                        <button className="btn" disabled={halaman <= 1} onClick={() => setHalaman(halaman - 1)}>Sebelumnya</button>
                        <span>Halaman {halaman} / {totalHalaman}</span>
                        <button className="btn" disabled={halaman >= totalHalaman} onClick={() => setHalaman(halaman + 1)}>Berikutnya</button>
                    </div>
                )}
            </div>
        </>
    );
}

function badge(status) {
    if (status === 'diterima') return 'hijau';
    if (status === 'direvisi' || status === 'diperiksa') return 'kuning';
    if (status === 'ditolak') return 'merah';
    return 'biru';
}
