import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api';

const KATEGORI = [
    'Negosiasi Pengadaan',
    'Substitusi Material',
    'Optimalisasi Operasi & Pemeliharaan',
    'Penghindaran Denda & Penalti',
    'Perpanjangan Umur Aset',
    'Pengurangan Downtime',
    'Pekerjaan In-house',
    'Penghindaran Sewa & Logistik',
    'Lainnya',
];

const STATUS = [
    ['draft', 'Draft'],
    ['diajukan', 'Menunggu Verifikasi'],
    ['disetujui', 'Disetujui'],
    ['ditolak', 'Ditolak'],
];

const LABEL = Object.fromEntries(STATUS);

const KELAS_STATUS = {
    draft: 'ca-st-draft',
    diajukan: 'ca-st-diajukan',
    disetujui: 'ca-st-disetujui',
    ditolak: 'ca-st-ditolak',
};

const rupiah = (nilai) =>
    'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(nilai) || 0);

const periodeLabel = (periode) => {
    if (!periode) return '-';
    const tgl = new Date(`${periode}-01T00:00:00`);
    return Number.isNaN(tgl.getTime())
        ? periode
        : tgl.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
};

const formKosong = () => ({
    id: 0,
    judul: '',
    kategori: KATEGORI[0],
    periode: new Date().toISOString().slice(0, 7),
    estimasi: '',
    realisasi: '',
    unit: '',
    deskripsi: '',
});

export default function CostAvoidance() {
    const [baris, setBaris] = useState([]);
    const [ringkasan, setRingkasan] = useState(null);
    const [filter, setFilter] = useState({ bulan: '', status: '', kategori: '' });
    const [form, setForm] = useState(null);
    const [galat, setGalat] = useState('');
    const [info, setInfo] = useState('');
    const [sibuk, setSibuk] = useState(false);

    const params = useMemo(() => {
        const p = { per_halaman: 50 };
        if (filter.bulan) p.bulan = filter.bulan;
        if (filter.status) p.status = filter.status;
        if (filter.kategori) p.kategori = filter.kategori;
        return p;
    }, [filter]);

    const muat = useCallback(() => {
        Promise.all([
            api.get('/cost-avoidance', { params }),
            api.get('/cost-avoidance/ringkasan'),
        ])
            .then(([r1, r2]) => {
                setBaris(r1.data?.data?.data ?? []);
                setRingkasan(r2.data?.ringkasan ?? null);
            })
            .catch(() => setGalat('Gagal memuat data cost avoidance.'));
    }, [params]);

    useEffect(() => {
        muat();
    }, [muat]);

    const ubahBidang = (kunci, nilai) => setForm((lama) => ({ ...lama, [kunci]: nilai }));

    const simpan = async (e) => {
        e.preventDefault();
        if (!form) return;
        setSibuk(true);
        setGalat('');

        const muatan = {
            judul: form.judul,
            kategori: form.kategori,
            periode: form.periode,
            estimasi: form.estimasi,
            realisasi: form.realisasi === '' ? 0 : form.realisasi,
            unit: form.unit,
            deskripsi: form.deskripsi,
        };

        try {
            if (form.id > 0) {
                await api.put(`/cost-avoidance/${form.id}`, muatan);
                setInfo('Data diperbarui.');
            } else {
                await api.post('/cost-avoidance', muatan);
                setInfo('Data penghematan dicatat.');
            }
            setForm(null);
            muat();
        } catch (err) {
            const kesalahan = err.response?.data?.errors;
            const pesan = kesalahan ? Object.values(kesalahan)[0]?.[0] : err.response?.data?.pesan;
            setGalat(pesan ?? 'Gagal menyimpan data.');
        } finally {
            setSibuk(false);
        }
    };

    const ajukan = async (id) => {
        if (!window.confirm('Ajukan data ini untuk verifikasi?')) return;
        setSibuk(true);
        try {
            const res = await api.post(`/cost-avoidance/${id}/ajukan`);
            setInfo(res.data?.pesan ?? 'Data diajukan.');
            muat();
        } catch (err) {
            setGalat(err.response?.data?.pesan ?? 'Gagal mengajukan data.');
        } finally {
            setSibuk(false);
        }
    };

    const verifikasi = async (id, keputusan) => {
        let catatan = '';
        if (keputusan === 'tolak') {
            const jawab = window.prompt('Alasan penolakan:');
            if (jawab === null) return;
            catatan = jawab.trim();
            if (catatan === '') {
                setGalat('Catatan wajib diisi saat menolak.');
                return;
            }
        } else if (!window.confirm('Setujui data ini?')) {
            return;
        }

        setSibuk(true);
        try {
            const res = await api.post(`/cost-avoidance/${id}/verifikasi`, { keputusan, catatan });
            setInfo(res.data?.pesan ?? 'Verifikasi tersimpan.');
            muat();
        } catch (err) {
            setGalat(err.response?.data?.pesan ?? 'Gagal menyimpan verifikasi.');
        } finally {
            setSibuk(false);
        }
    };

    const hapus = async (id) => {
        if (!window.confirm('Hapus data ini?')) return;
        setSibuk(true);
        try {
            await api.delete(`/cost-avoidance/${id}`);
            setInfo('Data dihapus.');
            muat();
        } catch (err) {
            setGalat(err.response?.data?.pesan ?? 'Gagal menghapus data.');
        } finally {
            setSibuk(false);
        }
    };

    const mulaiUbah = (row) => {
        setGalat('');
        setForm({
            id: row.id,
            judul: row.judul,
            kategori: row.kategori,
            periode: row.periode,
            estimasi: String(row.estimasi),
            realisasi: String(row.realisasi),
            unit: row.unit ?? '',
            deskripsi: row.deskripsi ?? '',
        });
    };

    const unduh = async (format) => {
        try {
            setGalat('');
            const p = { format };
            if (filter.bulan) p.bulan = filter.bulan;
            if (filter.status) p.status = filter.status;
            if (filter.kategori) p.kategori = filter.kategori;

            const res = await api.get('/ekspor/cost-avoidance', { params: p, responseType: 'blob' });
            const url = URL.createObjectURL(new Blob([res.data]));
            const link = document.createElement('a');
            const ts = new Date().toISOString().slice(0, 10);
            link.href = url;
            link.download = `cost-avoidance-${ts}.${format}`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch (e) {
            setGalat(e.response?.data?.pesan ?? 'Gagal mengunduh berkas.');
        }
    };

    const jumlah = ringkasan?.jumlah ?? {};
    const perKategori = ringkasan?.per_kategori ?? [];
    const maksKategori = perKategori.reduce((n, k) => Math.max(n, Number(k.nilai) || 0), 0);

    return (
        <div className="ca-halaman">
            <header className="ca-kepala">
                <div>
                    <p className="ca-eyebrow">Cost Avoidance</p>
                    <h2 className="ca-judul">Nilai yang berhasil dihindari</h2>
                    <p className="ca-ket">
                        Catat estimasi biaya, realisasi yang keluar, lalu ajukan untuk diverifikasi.
                    </p>
                </div>
                <div className="ca-kepala-aksi">
                    <button
                        type="button"
                        className="ca-tombol"
                        disabled={sibuk}
                        onClick={() => unduh('xlsx')}
                    >
                        Excel
                    </button>
                    <button
                        type="button"
                        className="ca-tombol"
                        disabled={sibuk}
                        onClick={() => unduh('pdf')}
                    >
                        PDF
                    </button>
                    <button
                        type="button"
                        className="ca-tombol ca-tombol-utama"
                        onClick={() => {
                            setGalat('');
                            setForm(formKosong());
                        }}
                    >
                        Catat penghematan
                    </button>
                </div>
            </header>

            {galat && <div className="peringatan">{galat}</div>}
            {info && <div className="peringatan" style={{ background: 'var(--hijau-50)', color: 'var(--hijau-700)' }}>{info}</div>}

            <section className="ca-hero">
                <p className="ca-eyebrow">Total disetujui</p>
                <p className="ca-angka">{rupiah(ringkasan?.total_disetujui ?? 0)}</p>
                <p className="ca-konteks">
                    <span>{jumlah.disetujui ?? 0} disetujui</span>
                    <span>{jumlah.diajukan ?? 0} menunggu verifikasi</span>
                    <span>{jumlah.draft ?? 0} draft</span>
                    {(jumlah.ditolak ?? 0) > 0 && <span>{jumlah.ditolak} ditolak</span>}
                </p>
            </section>

            <div className="ca-panel">
                <div className="ca-panel-kepala">
                    <h3>Rincian per kategori</h3>
                    <span className="ca-meta">Hanya yang disetujui</span>
                </div>

                {perKategori.length === 0 ? (
                    <p className="ca-kosong">Belum ada nilai yang disetujui.</p>
                ) : (
                    <ul className="ca-rincian">
                        {perKategori.map((k) => (
                            <li className="ca-rincian-baris" key={k.nama}>
                                <span className="ca-rincian-nama">{k.nama}</span>
                                <span className="ca-rincian-nilai">{rupiah(k.nilai)}</span>
                                <span className="ca-bar">
                                    <span
                                        className="ca-bar-isi"
                                        style={{ width: `${maksKategori > 0 ? Math.round((Number(k.nilai) / maksKategori) * 100) : 0}%` }}
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {form && (
                <div className="ca-panel">
                    <div className="ca-panel-kepala">
                        <h3>{form.id > 0 ? 'Ubah data' : 'Data baru'}</h3>
                        <span className="ca-meta">Nilai avoidance dihitung otomatis</span>
                    </div>

                    <form className="ca-form" onSubmit={simpan}>
                        <label className="ca-bidang ca-bidang-lebar">
                            <span>Uraian</span>
                            <input
                                type="text"
                                maxLength={200}
                                required
                                value={form.judul}
                                onChange={(e) => ubahBidang('judul', e.target.value)}
                            />
                        </label>

                        <label className="ca-bidang">
                            <span>Kategori</span>
                            <select value={form.kategori} onChange={(e) => ubahBidang('kategori', e.target.value)}>
                                {KATEGORI.map((k) => (
                                    <option key={k} value={k}>{k}</option>
                                ))}
                            </select>
                        </label>

                        <label className="ca-bidang">
                            <span>Periode</span>
                            <input
                                type="month"
                                required
                                value={form.periode}
                                onChange={(e) => ubahBidang('periode', e.target.value)}
                            />
                        </label>

                        <label className="ca-bidang">
                            <span>Estimasi biaya</span>
                            <input
                                type="text"
                                inputMode="numeric"
                                required
                                placeholder="1500000"
                                value={form.estimasi}
                                onChange={(e) => ubahBidang('estimasi', e.target.value)}
                            />
                        </label>

                        <label className="ca-bidang">
                            <span>Realisasi biaya</span>
                            <input
                                type="text"
                                inputMode="numeric"
                                placeholder="0"
                                value={form.realisasi}
                                onChange={(e) => ubahBidang('realisasi', e.target.value)}
                            />
                        </label>

                        <label className="ca-bidang">
                            <span>Unit</span>
                            <input
                                type="text"
                                maxLength={120}
                                placeholder="Divisi atau lokasi"
                                value={form.unit}
                                onChange={(e) => ubahBidang('unit', e.target.value)}
                            />
                        </label>

                        <label className="ca-bidang ca-bidang-lebar">
                            <span>Keterangan</span>
                            <textarea
                                rows={3}
                                value={form.deskripsi}
                                onChange={(e) => ubahBidang('deskripsi', e.target.value)}
                            />
                        </label>

                        <div className="ca-form-aksi">
                            <button className="ca-tombol ca-tombol-utama" type="submit" disabled={sibuk}>
                                {form.id > 0 ? 'Simpan perubahan' : 'Simpan sebagai draft'}
                            </button>
                            <button className="ca-tombol" type="button" onClick={() => setForm(null)}>
                                Batal
                            </button>
                        </div>
                    </form>
                </div>
            )}

            <div className="ca-panel">
                <div className="ca-panel-kepala">
                    <h3>Daftar pengajuan</h3>
                    <span className="ca-meta">{baris.length} entri</span>
                </div>

                <form className="ca-filter" onSubmit={(e) => e.preventDefault()}>
                    <label>
                        <span>Periode</span>
                        <input
                            type="month"
                            value={filter.bulan}
                            onChange={(e) => setFilter((f) => ({ ...f, bulan: e.target.value }))}
                        />
                    </label>
                    <label>
                        <span>Status</span>
                        <select
                            value={filter.status}
                            onChange={(e) => setFilter((f) => ({ ...f, status: e.target.value }))}
                        >
                            <option value="">Semua status</option>
                            {STATUS.map(([nilai, label]) => (
                                <option key={nilai} value={nilai}>{label}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span>Kategori</span>
                        <select
                            value={filter.kategori}
                            onChange={(e) => setFilter((f) => ({ ...f, kategori: e.target.value }))}
                        >
                            <option value="">Semua kategori</option>
                            {KATEGORI.map((k) => (
                                <option key={k} value={k}>{k}</option>
                            ))}
                        </select>
                    </label>
                    <button
                        className="ca-tombol"
                        type="button"
                        onClick={() => setFilter({ bulan: '', status: '', kategori: '' })}
                    >
                        Reset
                    </button>
                </form>

                {baris.length === 0 ? (
                    <p className="ca-kosong">Belum ada data pada filter ini.</p>
                ) : (
                    <table className="ca-tabel">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Uraian</th>
                                <th>Periode</th>
                                <th className="ca-kanan">Nilai</th>
                                <th>Status</th>
                                <th className="ca-kanan">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {baris.map((row) => (
                                <tr key={row.id}>
                                    <td data-label="Kode"><span className="ca-kode">{row.kode}</span></td>
                                    <td data-label="Uraian">
                                        <span className="ca-uraian">{row.judul}</span>
                                        <span className="ca-meta">
                                            {row.kategori}
                                            {row.unit ? ` · ${row.unit}` : ''}
                                        </span>
                                        {row.deskripsi && <span className="ca-desk">{row.deskripsi}</span>}
                                        {row.catatan_verifikasi && (
                                            <span className="ca-catatan">Catatan: {row.catatan_verifikasi}</span>
                                        )}
                                    </td>
                                    <td data-label="Periode">{periodeLabel(row.periode)}</td>
                                    <td data-label="Nilai" className="ca-nominal">
                                        <strong>{rupiah(row.nilai_avoidance)}</strong>
                                        <span className="ca-meta">
                                            {rupiah(row.estimasi)} &rarr; {rupiah(row.realisasi)}
                                        </span>
                                    </td>
                                    <td data-label="Status">
                                        {row.status === 'disetujui' ? (
                                            <span className="ca-cap">Disetujui</span>
                                        ) : (
                                            <span className={`ca-lencana ${KELAS_STATUS[row.status] ?? ''}`}>
                                                {LABEL[row.status] ?? row.status}
                                            </span>
                                        )}
                                        {row.verifikator && (
                                            <span className="ca-meta">
                                                {row.verifikator}
                                                {row.diverifikasi_pada ? ` · ${row.diverifikasi_pada.slice(0, 10)}` : ''}
                                            </span>
                                        )}
                                    </td>
                                    <td data-label="Aksi" className="ca-kanan">
                                        <div className="ca-aksi">
                                            {row.boleh_ubah && (
                                                <>
                                                    <button
                                                        type="button"
                                                        className="ca-tombol ca-tombol-kecil"
                                                        onClick={() => mulaiUbah(row)}
                                                    >
                                                        Ubah
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="ca-tombol ca-tombol-kecil ca-tombol-utama"
                                                        disabled={sibuk}
                                                        onClick={() => ajukan(row.id)}
                                                    >
                                                        Ajukan
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="ca-tombol ca-tombol-kecil ca-tombol-bahaya"
                                                        disabled={sibuk}
                                                        onClick={() => hapus(row.id)}
                                                    >
                                                        Hapus
                                                    </button>
                                                </>
                                            )}
                                            {row.boleh_verifikasi && (
                                                <>
                                                    <button
                                                        type="button"
                                                        className="ca-tombol ca-tombol-kecil ca-tombol-utama"
                                                        disabled={sibuk}
                                                        onClick={() => verifikasi(row.id, 'setujui')}
                                                    >
                                                        Setujui
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="ca-tombol ca-tombol-kecil"
                                                        disabled={sibuk}
                                                        onClick={() => verifikasi(row.id, 'tolak')}
                                                    >
                                                        Tolak
                                                    </button>
                                                </>
                                            )}
                                            {!row.boleh_ubah && !row.boleh_verifikasi && (
                                                <span className="ca-meta">&mdash;</span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
}
