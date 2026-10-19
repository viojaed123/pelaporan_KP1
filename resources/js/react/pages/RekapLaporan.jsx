import { useEffect, useState } from 'react';
import api from '../api';

/*
 * Rekap laporan seluruh karyawan (khusus mentor/admin).
 *
 * Porting dari rekap_laporan.php: filter rentang tanggal, status, dan
 * karyawan; kartu ringkasan jumlah per status; tabel hasil berhalaman.
 * Memakai endpoint GET /api/v1/rekap/laporan.
 */
const PER_HALAMAN = 50;

export default function RekapLaporan() {
    const [data, setData] = useState([]);
    const [total, setTotal] = useState(0);
    const [ringkasan, setRingkasan] = useState(null);
    const [karyawan, setKaryawan] = useState([]);
    const [halaman, setHalaman] = useState(1);
    const [filter, setFilter] = useState({ tanggal_mulai: '', tanggal_akhir: '', status: '', karyawan: '' });
    const [galat, setGalat] = useState('');

    useEffect(() => {
        const params = { per_halaman: PER_HALAMAN, halaman };
        Object.entries(filter).forEach(([k, v]) => { if (v !== '') params[k] = v; });

        api.get('/rekap/laporan', { params })
            .then((res) => {
                setData(res.data.data.data ?? []);
                setTotal(res.data.data.total ?? 0);
                setRingkasan(res.data.ringkasan);
                setKaryawan(res.data.daftar_karyawan ?? []);
                setGalat('');
            })
            .catch((err) => setGalat(err.response?.data?.pesan ?? 'Gagal memuat rekap laporan.'));
    }, [halaman, filter]);

    const ubah = (nama) => (e) => {
        setFilter((f) => ({ ...f, [nama]: e.target.value }));
        setHalaman(1);
    };

    const totalHalaman = Math.max(1, Math.ceil(total / PER_HALAMAN));

    const kartu = ringkasan
        ? [
            { label: 'Total laporan', nilai: ringkasan.total, warna: 'navy' },
            { label: 'Draft', nilai: ringkasan.draft ?? 0, warna: 'biru' },
            { label: 'Menunggu', nilai: (ringkasan.dikirim ?? 0) + (ringkasan.diajukan ?? 0), warna: 'kuning' },
            { label: 'Diperiksa', nilai: ringkasan.diperiksa ?? 0, warna: 'hijau' },
            { label: 'Perlu revisi', nilai: ringkasan.perlu_revisi ?? 0, warna: 'merah' },
        ]
        : [];

    const unduhXlsx = async () => {
        try {
            const params = { per_halaman: 1000000 };
            if (filter.tanggal_mulai) params.tanggal_mulai = filter.tanggal_mulai;
            if (filter.tanggal_akhir) params.tanggal_akhir = filter.tanggal_akhir;
            if (filter.status) params.status = filter.status;
            if (filter.karyawan) params.karyawan = filter.karyawan;
            const res = await api.get('/ekspor/laporan', { params, responseType: 'blob' });
            const url = URL.createObjectURL(new Blob([res.data]));
            const link = document.createElement('a');
            const ts = new Date().toISOString().slice(0, 10);
            link.href = url;
            link.download = `rekap-laporan-${ts}.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch (e) {
            setGalat(e.response?.data?.pesan ?? 'Gagal mengunduh XLSX laporan.');
        }
    };

    return (
        <>
            <h2 className="judul-halaman">Rekap Laporan</h2>

            <div className="kartu">
                <div className="kartu-kepala">Filter</div>
                <div className="kartu-tubuh">
                    <div className="baris-form">
                        <div className="form-grup">
                            <label htmlFor="mulai">Tanggal mulai</label>
                            <input id="mulai" type="date" className="isian" value={filter.tanggal_mulai} onChange={ubah('tanggal_mulai')} />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="akhir">Tanggal akhir</label>
                            <input id="akhir" type="date" className="isian" value={filter.tanggal_akhir} onChange={ubah('tanggal_akhir')} />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="status">Status</label>
                            <select id="status" className="isian" value={filter.status} onChange={ubah('status')}>
                                <option value="">Semua status</option>
                                <option value="draft">Draft</option>
                                <option value="dikirim">Menunggu Pemeriksaan</option>
                                <option value="diperiksa">Diperiksa</option>
                                <option value="perlu_revisi">Perlu Revisi</option>
                            </select>
                        </div>
                        <div className="form-grup">
                            <label htmlFor="karyawan">Karyawan</label>
                            <select id="karyawan" className="isian" value={filter.karyawan} onChange={ubah('karyawan')}>
                                <option value="">Semua karyawan</option>
                                {karyawan.map((k) => (
                                    <option key={k.id} value={k.id}>{k.nama}</option>
                                ))}
                            </select>
                        </div>
                        <div className="form-grup" style={{ alignSelf: 'flex-end' }}>
                            <button type="button" className="btn btn-utama" onClick={unduhXlsx}>Unduh XLSX (Laporan)</button>
                        </div>
                    </div>
                </div>
            </div>

            {galat && <div className="peringatan">{galat}</div>}

            <div className="baris-kartu-stat">
                {kartu.map((k) => (
                    <div className={`kartu-stat warna-${k.warna}`} key={k.label}>
                        <span className="angka">{k.nilai}</span>
                        <span className="label">{k.label}</span>
                    </div>
                ))}
            </div>

            <div className="kartu">
                <div className="kartu-kepala">Daftar Laporan</div>
                <div className="kartu-tubuh">
                    {data.length === 0 ? (
                        <p className="tabel-kosong">Tidak ada laporan pada filter ini.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Karyawan</th>
                                    <th>Divisi</th>
                                    <th>Kegiatan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((l) => (
                                    <tr key={l.id}>
                                        <td>{(l.tanggal ?? '').slice(0, 10)}</td>
                                        <td>{l.karyawan}</td>
                                        <td>{l.divisi ?? '—'}</td>
                                        <td>{l.kegiatan}</td>
                                        <td>
                                            <span className={`badge badge-${badgeStatus(l.status)}`}>
                                                {labelStatus(l.status)}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
                {totalHalaman > 1 && (
                    <div className="kartu-kaki">
                        <button className="btn" disabled={halaman <= 1} onClick={() => setHalaman(halaman - 1)}>Sebelumnya</button>
                        <span>Halaman {halaman} / {totalHalaman} · {total} baris</span>
                        <button className="btn" disabled={halaman >= totalHalaman} onClick={() => setHalaman(halaman + 1)}>Berikutnya</button>
                    </div>
                )}
            </div>
        </>
    );
}

function labelStatus(s) {
    const map = {
        draft: 'Draft',
        dikirim: 'Menunggu Pemeriksaan',
        diajukan: 'Menunggu Pemeriksaan',
        diperiksa: 'Diperiksa',
        perlu_revisi: 'Perlu Revisi',
    };
    return map[s] ?? (s ? s.replace(/_/g, ' ') : '—');
}

function badgeStatus(s) {
    if (s === 'diperiksa') return 'hijau';
    if (s === 'perlu_revisi') return 'merah';
    if (s === 'dikirim' || s === 'diajukan') return 'kuning';
    return 'biru';
}
