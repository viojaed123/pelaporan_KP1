import { useEffect, useState } from 'react';
import api from '../api';
import GrafikDonut from '../components/GrafikDonut';
import GrafikBatang from '../components/GrafikBatang';

const PER_HALAMAN = 50;

export default function RekapKasus() {
    const [data, setData] = useState([]);
    const [total, setTotal] = useState(0);
    const [ringkasan, setRingkasan] = useState(null);
    const [perUrgensi, setPerUrgensi] = useState([]);
    const [perKategori, setPerKategori] = useState([]);
    const [perPenanggungJawab, setPerPenanggungJawab] = useState([]);
    const [halaman, setHalaman] = useState(1);
    const [filter, setFilter] = useState({ dari: '', sampai: '', status: '', urgensi: '' });
    const [galat, setGalat] = useState('');
    const [unduhProses, setUnduhProses] = useState(false);

    useEffect(() => {
        const params = { per_halaman: PER_HALAMAN, halaman };
        if (filter.dari) params.dari = filter.dari;
        if (filter.sampai) params.sampai = filter.sampai;
        if (filter.status) params.status = filter.status;
        if (filter.urgensi) params.urgensi = filter.urgensi;

        api.get('/rekap/kasus', { params })
            .then((res) => {
                const d = res.data;
                setData(d.data?.data ?? []);
                setTotal(d.data?.total ?? 0);
                setRingkasan(d.ringkasan ?? null);
                setPerUrgensi(d.per_urgensi ?? []);
                setPerKategori(d.per_kategori ?? []);
                setPerPenanggungJawab(d.per_penanggung_jawab ?? []);
                setGalat('');
            })
            .catch((err) => setGalat(err.response?.data?.pesan ?? 'Gagal memuat rekap kasus.'));
    }, [halaman, filter]);

    const ubah = (nama) => (e) => {
        setFilter((f) => ({ ...f, [nama]: e.target.value }));
        setHalaman(1);
    };

    const totalHalaman = Math.max(1, Math.ceil(total / PER_HALAMAN));

    const kartu = ringkasan
        ? [
              { label: 'Total kasus', nilai: ringkasan.total ?? 0, warna: 'navy' },
              { label: 'Terbuka', nilai: ringkasan.terbuka ?? 0, warna: 'biru' },
              { label: 'Dalam Proses', nilai: ringkasan.dalam_proses ?? 0, warna: 'kuning' },
              { label: 'Selesai', nilai: ringkasan.selesai ?? 0, warna: 'hijau' },
              { label: 'Terlambat', nilai: ringkasan.terlambat ?? 0, warna: 'merah' },
              { label: 'Tanpa PIC', nilai: ringkasan.tanpa_pic ?? 0, warna: 'biru' },
          ]
        : [];

    const unduhXlsx = async () => {
        try {
            setUnduhProses(true);
            const params = {};
            if (filter.dari) params.dari = filter.dari;
            if (filter.sampai) params.sampai = filter.sampai;
            if (filter.status) params.status = filter.status;
            if (filter.urgensi) params.urgensi = filter.urgensi;

            const res = await api.get('/ekspor/kasus', { params, responseType: 'blob' });
            const url = URL.createObjectURL(new Blob([res.data]));
            const link = document.createElement('a');
            const ts = new Date().toISOString().slice(0, 10);
            link.href = url;
            link.download = `rekap-kasus-${ts}.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch (e) {
            setGalat(e.response?.data?.pesan ?? 'Gagal mengunduh XLSX kasus.');
        } finally {
            setUnduhProses(false);
        }
    };

    return (
        <>
            <h2 className="judul-halaman">Rekap Kasus</h2>

            <div className="kartu">
                <div className="kartu-kepala">Filter</div>
                <div className="kartu-tubuh">
                    <div className="baris-form">
                        <div className="form-grup">
                            <label htmlFor="dari">Tanggal mulai</label>
                            <input id="dari" type="date" className="isian" value={filter.dari} onChange={ubah('dari')} />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="sampai">Tanggal akhir</label>
                            <input id="sampai" type="date" className="isian" value={filter.sampai} onChange={ubah('sampai')} />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="status">Status Kasus</label>
                            <select id="status" className="isian" value={filter.status} onChange={ubah('status')}>
                                <option value="">Semua status</option>
                                <option value="Terbuka">Terbuka</option>
                                <option value="Dalam Proses">Dalam Proses</option>
                                <option value="Selesai">Selesai</option>
                            </select>
                        </div>
                        <div className="form-grup">
                            <label htmlFor="urgensi">Urgensi</label>
                            <select id="urgensi" className="isian" value={filter.urgensi} onChange={ubah('urgensi')}>
                                <option value="">Semua urgensi</option>
                                <option value="Rendah">Rendah</option>
                                <option value="Sedang">Sedang</option>
                                <option value="Tinggi">Tinggi</option>
                                <option value="Kritis">Kritis</option>
                            </select>
                        </div>
                        <div className="form-grup" style={{ alignSelf: 'flex-end' }}>
                            <button type="button" className="btn btn-utama" onClick={unduhXlsx} disabled={unduhProses}>
                                {unduhProses ? 'Mengunduh…' : 'Unduh XLSX (Kasus)'}
                            </button>
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

            <div className="baris-kartu">
                <div className="kartu">
                    <div className="kartu-kepala">Kasus per Urgensi</div>
                    <div className="kartu-tubuh">
                        {perUrgensi.length === 0 ? (
                            <p className="tabel-kosong">Belum ada data.</p>
                        ) : (
                            <GrafikDonut data={perUrgensi.map((u) => ({ label: u.urgensi, jumlah: u.jumlah }))} />
                        )}
                    </div>
                </div>
                <div className="kartu">
                    <div className="kartu-kepala">Kasus per Kategori</div>
                    <div className="kartu-tubuh">
                        {perKategori.length === 0 ? (
                            <p className="tabel-kosong">Belum ada data.</p>
                        ) : (
                            <GrafikBatang data={perKategori.map((c) => ({ label: c.kategori, jumlah: c.jumlah }))} />
                        )}
                    </div>
                </div>
            </div>

            {perPenanggungJawab.length > 0 && (
                <div className="kartu">
                    <div className="kartu-kepala">Per Penanggung Jawab (PIC)</div>
                    <div className="kartu-tubuh">
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>PIC</th>
                                    <th>Total</th>
                                    <th>Terbuka</th>
                                    <th>Dalam Proses</th>
                                    <th>Selesai</th>
                                    <th>Terlambat</th>
                                </tr>
                            </thead>
                            <tbody>
                                {perPenanggungJawab.map((p) => (
                                    <tr key={p.penanggung_jawab_id ?? p.nama}>
                                        <td>{p.nama ?? 'Belum Ditentukan'}</td>
                                        <td>{p.total}</td>
                                        <td>{p.terbuka}</td>
                                        <td>{p.dalam_proses}</td>
                                        <td>{p.selesai}</td>
                                        <td>{p.terlambat}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="kartu">
                <div className="kartu-kepala">Daftar Kasus</div>
                <div className="kartu-tubuh">
                    {data.length === 0 ? (
                        <p className="tabel-kosong">Tidak ada kasus pada filter ini.</p>
                    ) : (
                        <table className="tabel">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Judul</th>
                                    <th>Status</th>
                                    <th>Urgensi</th>
                                    <th>Kategori</th>
                                    <th>PIC</th>
                                    <th>Target Selesai</th>
                                    <th>Progres</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((k) => (
                                    <tr key={k.id}>
                                        <td>{k.kode_kasus ?? k.id}</td>
                                        <td>{k.judul}</td>
                                        <td>{k.status_kasus}</td>
                                        <td>{k.urgensi}</td>
                                        <td>{k.kategori ?? '—'}</td>
                                        <td>{k.penanggung_jawab_nama ?? k.penanggung_jawab_teks ?? '—'}</td>
                                        <td>{(k.target_selesai ?? '').slice(0, 10)}</td>
                                        <td>{k.progres ?? 0}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
                {totalHalaman > 1 && (
                    <div className="kartu-kaki">
                        <button className="btn" disabled={halaman <= 1} onClick={() => setHalaman(halaman - 1)}>
                            Sebelumnya
                        </button>
                        <span>
                            Halaman {halaman} / {totalHalaman} · {total} baris
                        </span>
                        <button className="btn" disabled={halaman >= totalHalaman} onClick={() => setHalaman(halaman + 1)}>
                            Berikutnya
                        </button>
                    </div>
                )}
            </div>
        </>
    );
}
