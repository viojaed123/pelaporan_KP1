import { useEffect, useState } from 'react';
import api from '../api';

/*
 * Rekap absensi seluruh peserta (khusus mentor/admin).
 *
 * Porting dari rekap_absensi.php: filter periode, kartu ringkasan status
 * kehadiran, rekap per peserta, dan daftar catatan absensi.
 * Memakai endpoint GET /api/v1/rekap/absensi.
 */
const PER_HALAMAN = 100;

export default function RekapAbsensi() {
    const [data, setData] = useState([]);
    const [total, setTotal] = useState(0);
    const [periode, setPeriode] = useState(null);
    const [ringkasan, setRingkasan] = useState(null);
    const [perPeserta, setPerPeserta] = useState([]);
    const [halaman, setHalaman] = useState(1);
    const [filter, setFilter] = useState({ awal: '', akhir: '' });
    const [galat, setGalat] = useState('');

    useEffect(() => {
        const params = { per_halaman: PER_HALAMAN, halaman };
        if (filter.awal) params.awal = filter.awal;
        if (filter.akhir) params.akhir = filter.akhir;

        api.get('/rekap/absensi', { params })
            .then((res) => {
                setData(res.data.data.data ?? []);
                setTotal(res.data.data.total ?? 0);
                setPeriode(res.data.periode);
                setRingkasan(res.data.ringkasan);
                setPerPeserta(res.data.per_peserta ?? []);
                setGalat('');
            })
            .catch((err) => setGalat(err.response?.data?.pesan ?? 'Gagal memuat rekap absensi.'));
    }, [halaman, filter]);

    const ubah = (nama) => (e) => {
        setFilter((f) => ({ ...f, [nama]: e.target.value }));
        setHalaman(1);
    };

    const totalHalaman = Math.max(1, Math.ceil(total / PER_HALAMAN));

    const kartu = ringkasan
        ? [
            { label: 'Total catatan', nilai: ringkasan.total, warna: 'navy' },
            { label: 'Hadir', nilai: ringkasan.hadir, warna: 'hijau' },
            { label: 'Izin', nilai: ringkasan.izin, warna: 'biru' },
            { label: 'Sakit', nilai: ringkasan.sakit, warna: 'kuning' },
            { label: 'Alpa', nilai: ringkasan.alpa, warna: 'merah' },
        ]
        : [];

    const unduhXlsx = async () => {
        try {
            const params = {};
            if (filter.awal) params.awal = filter.awal;
            if (filter.akhir) params.akhir = filter.akhir;
            const res = await api.get('/ekspor/absensi', { params, responseType: 'blob' });
            const url = URL.createObjectURL(new Blob([res.data]));
            const link = document.createElement('a');
            const ts = new Date().toISOString().slice(0, 10);
            link.href = url;
            link.download = `rekap-absensi-${ts}.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch (e) {
            setGalat(e.response?.data?.pesan ?? 'Gagal mengunduh XLSX absensi.');
        }
    };

    return (
        <>
            <h2 className="judul-halaman">Rekap Absensi</h2>

            <div className="kartu">
                <div className="kartu-kepala">
                    Filter Periode
                    {periode && <span className="teks-redup"> {periode.awal} s.d. {periode.akhir}</span>}
                </div>
                <div className="kartu-tubuh">
                    <div className="baris-form">
                        <div className="form-grup">
                            <label htmlFor="awal">Tanggal awal</label>
                            <input id="awal" type="date" className="isian" value={filter.awal} onChange={ubah('awal')} />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="akhir">Tanggal akhir</label>
                            <input id="akhir" type="date" className="isian" value={filter.akhir} onChange={ubah('akhir')} />
                        </div>
                        <div className="form-grup" style={{ alignSelf: 'flex-end' }}>
                            <button type="button" className="btn btn-utama" onClick={unduhXlsx}>Unduh XLSX (Absensi)</button>
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
                <div className="kartu-kepala">Rekap per Peserta</div>
                <div className="kartu-tubuh">
                    {perPeserta.length === 0 ? (
                        <p className="tabel-kosong">Belum ada karyawan terdaftar.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Surel</th>
                                    <th>Hadir</th>
                                    <th>Izin</th>
                                    <th>Sakit</th>
                                    <th>Alpa</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {perPeserta.map((p) => (
                                    <tr key={p.user_id}>
                                        <td>{p.nama}</td>
                                        <td>{p.email}</td>
                                        <td>{p.hadir}</td>
                                        <td>{p.izin}</td>
                                        <td>{p.sakit}</td>
                                        <td>{p.alpa}</td>
                                        <td><strong>{p.total_catatan}</strong></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            <div className="kartu">
                <div className="kartu-kepala">Catatan Absensi</div>
                <div className="kartu-tubuh">
                    {data.length === 0 ? (
                        <p className="tabel-kosong">Tidak ada catatan absensi pada periode ini.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Nama</th>
                                    <th>Masuk</th>
                                    <th>Pulang</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((a) => (
                                    <tr key={a.id}>
                                        <td>{(a.tanggal ?? '').slice(0, 10)}</td>
                                        <td>{a.nama}</td>
                                        <td>{(a.jam_masuk ?? '—').slice(0, 5)}</td>
                                        <td>{(a.jam_pulang ?? '—').slice(0, 5)}</td>
                                        <td><span className={`badge badge-${badgeStatus(a.status)}`}>{a.status}</span></td>
                                        <td>{a.keterangan ?? '—'}</td>
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

function badgeStatus(s) {
    const t = (s ?? '').toLowerCase();
    if (t === 'hadir') return 'hijau';
    if (t === 'sakit') return 'kuning';
    if (t === 'alpa') return 'merah';
    return 'biru';
}
