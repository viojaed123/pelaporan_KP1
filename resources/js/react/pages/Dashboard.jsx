import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';

/*
 * Dashboard: kartu ringkasan + daftar tugas terbaru,
 * memakai endpoint /api/v1/ringkasan & /api/v1/tugas.
 */
export default function Dashboard() {
    const { pengguna } = useAuth();
    const [ringkasan, setRingkasan] = useState(null);
    const [tugas, setTugas] = useState([]);
    const [galat, setGalat] = useState('');

    useEffect(() => {
        Promise.all([
            api.get('/ringkasan'),
            api.get('/tugas', { params: { per_halaman: 5 } }),
        ])
            .then(([r1, r2]) => {
                setRingkasan(r1.data.ringkasan);
                setTugas(r2.data.data.data ?? []);
            })
            .catch(() => setGalat('Gagal memuat ringkasan.'));
    }, []);

    const kartu = ringkasan
        ? [
            { label: 'Laporan bulan ini', nilai: ringkasan.laporan_bulan_ini, warna: 'navy', ke: '/laporan/riwayat' },
            {
                label: 'Absensi hari ini',
                nilai: ringkasan.absensi_hari_ini.ada
                    ? (ringkasan.absensi_hari_ini.jam_masuk ?? '—').slice(0, 5)
                    : 'Belum',
                warna: ringkasan.absensi_hari_ini.ada ? 'hijau' : 'kuning',
                ke: '/absensi',
            },
            { label: 'Tugas aktif', nilai: ringkasan.tugas_aktif, warna: 'biru', ke: '/tugas' },
            { label: 'Tugas terlambat', nilai: ringkasan.tugas_terlambat, warna: 'merah', ke: '/tugas' },
        ]
        : [];

    return (
        <>
            <p className="sapaan">Halo, <strong>{pengguna?.nama}</strong>. Selamat bertugas!</p>

            {galat && <div className="peringatan">{galat}</div>}

            <div className="baris-kartu-stat">
                {kartu.map((k) => (
                    <Link className={`kartu-stat warna-${k.warna}`} to={k.ke} key={k.label}>
                        <span className="angka">{k.nilai}</span>
                        <span className="label">{k.label}</span>
                    </Link>
                ))}
            </div>

            {ringkasan?.cost_avoidance && (
                <Link className="ca-strip" to="/cost-avoidance">
                    <div className="ca-strip-utama">
                        <p className="ca-eyebrow">
                            Cost avoidance disetujui
                            {ringkasan.cost_avoidance.cakupan === 'semua' ? ' · seluruh pengguna' : ''}
                        </p>
                        <p className="ca-strip-angka">{rupiah(ringkasan.cost_avoidance.total_disetujui)}</p>
                        <p className="ca-meta">
                            {ringkasan.cost_avoidance.disetujui ?? 0} entri disetujui
                            {' · '}
                            {ringkasan.cost_avoidance.diajukan ?? 0} menunggu verifikasi
                        </p>
                    </div>
                    <span className="ca-tombol">Lihat rincian</span>
                </Link>
            )}

            <div className="sorotan-ringkas" style={{ marginTop: 16 }}>
                <Link className="s-chip" to="/laporan/buat">
                    <span className="s-ikon">&#9998;</span>
                    <span className="s-teks">
                        <span className="s-judul">Buat Laporan</span>
                        <span className="s-ket">Catat kegiatan hari ini</span>
                    </span>
                </Link>
                <Link className="s-chip" to="/laporan/riwayat">
                    <span className="s-ikon">&#128196;</span>
                    <span className="s-teks">
                        <span className="s-judul">Riwayat Laporan</span>
                        <span className="s-ket">Pantau status pekerjaan</span>
                    </span>
                </Link>
                <Link className="s-chip" to="/absensi">
                    <span className="s-ikon">&#128197;</span>
                    <span className="s-teks">
                        <span className="s-judul">Isi Absensi</span>
                        <span className="s-ket">Check-in kehadiran</span>
                    </span>
                </Link>
                <Link className="s-chip" to="/kalender">
                    <span className="s-ikon">&#128197;</span>
                    <span className="s-teks">
                        <span className="s-judul">Kalender</span>
                        <span className="s-ket">Agenda aktivitas</span>
                    </span>
                </Link>
            </div>

            <div className="kartu">
                <div className="kartu-kepala">Tugas Terbaru</div>
                <div className="kartu-tubuh">
                    {tugas.length === 0 ? (
                        <p className="tabel-kosong">Belum ada tugas.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Judul</th>
                                    <th>Tenggat</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tugas.map((t) => (
                                    <tr key={t.id}>
                                        <td>{t.judul}</td>
                                        <td>{t.tenggat ?? '—'}</td>
                                        <td><span className={`badge badge-${badgeStatus(t.status)}`}>{t.status}</span></td>
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

function badgeStatus(status) {
    if (status === 'selesai') return 'hijau';
    if (status === 'dikerjakan') return 'kuning';
    return 'biru';
}

function rupiah(nilai) {
    return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(nilai) || 0);
}
