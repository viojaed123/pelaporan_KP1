import { useEffect, useState } from 'react';
import api from '../api';

/*
 * Kalender aktivitas bulanan.
 *
 * Porting dari kalender.php: karyawan melihat kalender pribadi
 * (laporan + absensi per hari); mentor/admin dapat memilih satu
 * karyawan atau melihat agregat tim (jumlah laporan & karyawan aktif).
 * Memakai endpoint GET /api/v1/kalender.
 */
const HARI = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

export default function KalenderAktivitas() {
    const [hasil, setHasil] = useState(null);
    const [bulan, setBulan] = useState('');
    const [user, setUser] = useState('');
    const [galat, setGalat] = useState('');

    useEffect(() => {
        const params = {};
        if (bulan) params.bulan = bulan;
        if (user) params.user = user;

        api.get('/kalender', { params })
            .then((res) => { setHasil(res.data); setGalat(''); })
            .catch((err) => setGalat(err.response?.data?.pesan ?? 'Gagal memuat kalender.'));
    }, [bulan, user]);

    let tahun = 0;
    let bulanNum = 1;
    if (hasil?.bulan) {
        const bagian = hasil.bulan.split('-');
        tahun = Number(bagian[0]);
        bulanNum = Number(bagian[1]);
    }

    const jumlahHari = tahun ? new Date(tahun, bulanNum, 0).getDate() : 0;
    const offset = tahun ? (new Date(tahun, bulanNum - 1, 1).getDay() + 6) % 7 : 0;

    const peta = {};
    (hasil?.hari ?? []).forEach((h) => { peta[h.tanggal] = h; });
    const absensi = hasil?.absensi ?? {};

    const geser = (delta) => {
        if (!tahun) return;
        const d = new Date(tahun, bulanNum - 1 + delta, 1);
        setBulan(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
    };

    const sel = [];
    for (let i = 0; i < offset; i++) sel.push(null);
    for (let d = 1; d <= jumlahHari; d++) sel.push(d);

    return (
        <>
            <div className="baris-atas">
                <h2 className="judul-halaman">Kalender Aktivitas</h2>
                <div className="baris-tombol">
                    <button className="btn" onClick={() => geser(-1)}>‹ Bulan lalu</button>
                    <button className="btn" onClick={() => geser(1)}>Bulan depan ›</button>
                </div>
            </div>

            <div className="kartu">
                <div className="kartu-tubuh">
                    {(hasil?.daftar_karyawan ?? []).length > 0 && (
                        <div className="grup-form-dua">
                            <div className="form-grup">
                                <label htmlFor="user">Sasaran</label>
                                <select id="user" className="isian" value={user} onChange={(e) => setUser(e.target.value)}>
                                    <option value="">Seluruh karyawan</option>
                                    {(hasil.daftar_karyawan ?? []).map((k) => (
                                        <option key={k.id} value={k.id}>{k.nama}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {galat && <div className="peringatan">{galat}</div>}

            {hasil && (
                <div className="kartu">
                    <div className="kartu-kepala">
                        {hasil.nama_bulan} · <strong>{hasil.sasaran}</strong>
                    </div>
                    <div className="kartu-tubuh">
                        <div className="kalender-hari">
                            {HARI.map((h) => (
                                <div className="kalender-hari-sel" key={h}>{h}</div>
                            ))}
                        </div>
                        <div className="kalender-grid">
                            {sel.map((d, i) => {
                                if (d === null) return <div className="kalender-sel kosong" key={`k${i}`} />;
                                const kunci = `${hasil.bulan}-${String(d).padStart(2, '0')}`;
                                const info = peta[kunci];
                                const abs = absensi[kunci];
                                const akhirPekan = (offset + d - 1) % 7 >= 5;
                                const kelas = [
                                    'kalender-sel',
                                    info ? 'ada-laporan' : '',
                                    akhirPekan ? 'akhir-pekan' : '',
                                ].filter(Boolean).join(' ');
                                return (
                                    <div className={kelas} key={kunci}>
                                        <span className="kalender-tanggal">{d}</span>
                                        <div className="kalender-meta">
                                            {info && <span className="badge badge-biru">{info.jml} laporan</span>}
                                            {info && hasil.sasaran_id === null && info.org > 1 && (
                                                <span className="badge badge-navy">{info.org} org</span>
                                            )}
                                            {info && info.revisi > 0 && (
                                                <span className="badge badge-merah">{info.revisi} revisi</span>
                                            )}
                                        </div>
                                        {abs && (
                                            <span className={`kalender-absensi kalender-absensi-${kelasAbs(abs.status)}`}>
                                                {abs.status}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

/* Kelas warna absensi legacy: kalender-absensi-{hadir|izin|sakit|alpa}. */
function kelasAbs(s) {
    const t = (s ?? '').toLowerCase();
    return ['hadir', 'izin', 'sakit', 'alpa'].includes(t) ? t : 'hadir';
}
