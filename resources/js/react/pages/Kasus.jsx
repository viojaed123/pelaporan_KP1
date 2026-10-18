import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';

/*
 * Kasus: daftar + detail (termasuk anggota tim & log status).
 * Visibilitas dijelaskan API: karyawan hanya kasus yang melibatkannya.
 */
export default function Kasus() {
    const [data, setData] = useState([]);
    const [terpilih, setTerpilih] = useState(null);
    const [galat, setGalat] = useState('');

    useEffect(() => {
        api.get('/kasus', { params: { per_halaman: 50 } })
            .then((res) => setData(res.data.data.data ?? []))
            .catch(() => setGalat('Gagal memuat daftar kasus.'));
    }, []);

    const buka = async (id) => {
        try {
            const res = await api.get(`/kasus/${id}`);
            setTerpilih(res.data.kasus);
        } catch (err) {
            setGalat(err.response?.data?.pesan ?? 'Gagal memuat detail kasus.');
        }
    };

    return (
        <>
            <div className="baris-atas">
                <h2 className="judul-halaman">Kasus</h2>
                <Link className="btn btn-utama" to="/kasus/buat">+ Buat Kasus Baru</Link>
            </div>
            {galat && <div className="peringatan">{galat}</div>}

            <div className="kartu">
                <div className="kartu-tubuh">
                    {data.length === 0 ? (
                        <p className="tabel-kosong">Belum ada kasus yang melibatkan Anda.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Judul</th>
                                    <th>Urgensi</th>
                                    <th>Status</th>
                                    <th>Progres</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((k) => (
                                    <tr key={k.id}>
                                        <td>{k.kode}</td>
                                        <td>{k.judul}</td>
                                        <td><span className={`badge badge-${urgensi(k.urgensi)}`}>{k.urgensi}</span></td>
                                        <td>{k.status}</td>
                                        <td>{k.progres}%</td>
                                        <td><button className="btn" onClick={() => buka(k.id)}>Detail</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {terpilih && (
                <div className="kartu">
                    <div className="kartu-kepala">
                        {terpilih.kode} — {terpilih.judul}
                        <Link className="btn" to={`/kasus/${terpilih.id}/edit`}>Edit</Link>
                        <button className="btn tutup" onClick={() => setTerpilih(null)}>Tutup</button>
                    </div>
                    <div className="kartu-tubuh">
                        <p>{terpilih.deskripsi ?? '—'}</p>
                        <p>
                            Penanggung jawab: <strong>{terpilih.penanggung_jawab ?? '—'}</strong>
                            {' · '}Kategori: <strong>{terpilih.kategori ?? '—'}</strong>
                        </p>
                        <h4>Anggota Tim</h4>
                        <ul>
                            {(terpilih.anggota ?? []).map((a, i) => (
                                <li key={i}>{a.nama} — {a.peran}</li>
                            ))}
                            {(terpilih.anggota ?? []).length === 0 && <li>Tidak ada anggota tercatat.</li>}
                        </ul>
                        <h4>Riwayat Status</h4>
                        <table className="tabel">
                            <thead>
                                <tr><th>Perubahan</th><th>Oleh</th><th>Waktu</th></tr>
                            </thead>
                            <tbody>
                                {(terpilih.log_status ?? []).map((l, i) => (
                                    <tr key={i}>
                                        <td>{l.status_lama ?? '—'} → {l.status_baru}</td>
                                        <td>{l.oleh ?? '—'}</td>
                                        <td>{(l.pada ?? '').slice(0, 16).replace('T', ' ')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </>
    );
}

function urgensi(u) {
    if (u === 'tinggi' || u === 'kritis') return 'merah';
    if (u === 'sedang') return 'kuning';
    return 'biru';
}
