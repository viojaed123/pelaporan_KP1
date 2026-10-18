import { useEffect, useState } from 'react';
import api from '../api';

/*
 * Ringkasan kegiatan mingguan / bulanan.
 *
 * Porting dari ringkasan.php: karyawan melihat ringkasan pribadi;
 * mentor/admin dapat memilih satu karyawan atau seluruh tim.
 * Memakai endpoint GET /api/v1/ringkasan-kegiatan.
 */
export default function RingkasanKegiatan() {
    const [hasil, setHasil] = useState(null);
    const [periode, setPeriode] = useState('mingguan');
    const [tanggal, setTanggal] = useState('');
    const [user, setUser] = useState('');
    const [galat, setGalat] = useState('');

    useEffect(() => {
        const params = { periode };
        if (tanggal) params.tanggal = tanggal;
        if (user) params.user = user;

        api.get('/ringkasan-kegiatan', { params })
            .then((res) => { setHasil(res.data); setGalat(''); })
            .catch((err) => setGalat(err.response?.data?.pesan ?? 'Gagal memuat ringkasan.'));
    }, [periode, tanggal, user]);

    const stat = hasil?.stat;

    const kartu = stat
        ? [
            { label: 'Jumlah laporan', nilai: stat.jml, warna: 'navy', tambahan: banding(hasil.selisih) },
            { label: 'Hari aktif', nilai: stat.hari, warna: 'biru' },
            { label: 'Total durasi', nilai: jamMenit(stat.menit), warna: 'hijau' },
            { label: 'Ada kendala', nilai: stat.kendala, warna: 'merah' },
            { label: 'Draft', nilai: stat.draft, warna: 'biru' },
            { label: 'Menunggu', nilai: stat.dikirim, warna: 'kuning' },
            { label: 'Diperiksa', nilai: stat.diperiksa, warna: 'hijau' },
            { label: 'Perlu revisi', nilai: stat.revisi, warna: 'merah' },
        ]
        : [];

    return (
        <>
            <h2 className="judul-halaman">Ringkasan Kegiatan</h2>

            <div className="kartu">
                <div className="kartu-tubuh">
                    <div className="grup-form-tiga">
                        <div className="form-grup">
                            <label htmlFor="periode">Periode</label>
                            <select id="periode" className="isian" value={periode} onChange={(e) => setPeriode(e.target.value)}>
                                <option value="mingguan">Mingguan</option>
                                <option value="bulanan">Bulanan</option>
                            </select>
                        </div>
                        <div className="form-grup">
                            <label htmlFor="tanggal">Tanggal acuan</label>
                            <input id="tanggal" type="date" className="isian" value={tanggal} onChange={(e) => setTanggal(e.target.value)} />
                        </div>
                        {(hasil?.daftar_karyawan ?? []).length > 0 && (
                            <div className="form-grup">
                                <label htmlFor="user">Sasaran</label>
                                <select id="user" className="isian" value={user} onChange={(e) => setUser(e.target.value)}>
                                    <option value="">Seluruh karyawan</option>
                                    {(hasil.daftar_karyawan ?? []).map((k) => (
                                        <option key={k.id} value={k.id}>{k.nama}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {galat && <div className="peringatan">{galat}</div>}

            {hasil && (
                <p className="sapaan">
                    {hasil.label} · <strong>{hasil.sasaran}</strong>
                </p>
            )}

            <div className="baris-kartu-stat">
                {kartu.map((k) => (
                    <div className={`kartu-stat warna-${k.warna}`} key={k.label}>
                        <span className="angka">{k.nilai}</span>
                        <span className="label">{k.label}</span>
                        {k.tambahan && <span className="teks-redup">{k.tambahan}</span>}
                    </div>
                ))}
            </div>

            {hasil && (
                <div className="kartu">
                    <div className="kartu-kepala">Perbandingan dengan periode sebelumnya</div>
                    <div className="kartu-tubuh">
                        <table className="tabel">
                            <thead>
                                <tr><th>Ukuran</th><th>Periode ini</th><th>Sebelumnya</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Jumlah laporan</td><td>{hasil.stat.jml}</td><td>{hasil.stat_sebelum.jml}</td></tr>
                                <tr><td>Hari aktif</td><td>{hasil.stat.hari}</td><td>{hasil.stat_sebelum.hari}</td></tr>
                                <tr><td>Total durasi</td><td>{jamMenit(hasil.stat.menit)}</td><td>{jamMenit(hasil.stat_sebelum.menit)}</td></tr>
                                <tr><td>Ada kendala</td><td>{hasil.stat.kendala}</td><td>{hasil.stat_sebelum.kendala}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </>
    );
}

function jamMenit(menit) {
    const m = Number(menit) || 0;
    const j = Math.floor(m / 60);
    const sisa = m % 60;
    if (j === 0) return `${sisa} menit`;
    return `${j} jam ${sisa} menit`;
}

function banding(selisih) {
    const n = Number(selisih) || 0;
    if (n === 0) return 'sama dengan periode lalu';
    return n > 0 ? `+${n} dari periode lalu` : `${n} dari periode lalu`;
}
