import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api';

/*
 * Formulir laporan harian baru (POST /api/v1/laporan).
 * Lokasi bisa diisi teks biasa atau diambil dari GPS perangkat.
 */
export default function BuatLaporan() {
    const navigasi = useNavigate();
    const [f, setF] = useState({
        tanggal: new Date().toISOString().slice(0, 10),
        jam_mulai: '',
        jam_selesai: '',
        kegiatan: '',
        lokasi_teks: '',
        deskripsi: '',
        hasil: '',
        kendala: '',
        rencana_berikutnya: '',
    });
    const [pesan, setPesan] = useState('');
    const [galat, setGalat] = useState([]);
    const [sibuk, setSibuk] = useState(false);

    const ubah = (k) => (e) => setF({ ...f, [k]: e.target.value });

    const lokasiGps = () => {
        if (!navigator.geolocation) return setPesan('Perangkat tidak mendukung GPS.');
        navigator.geolocation.getCurrentPosition(
            (pos) => setF((v) => ({
                ...v,
                garis_lintang: pos.coords.latitude.toFixed(7),
                garis_bujur: pos.coords.longitude.toFixed(7),
            })),
            () => setPesan('Lokasi GPS tidak dapat diambil.'),
        );
    };

    const kirim = async (e) => {
        e.preventDefault();
        setSibuk(true);
        setGalat([]);
        setPesan('');
        try {
            await api.post('/laporan', f);
            navigasi('/laporan/riwayat');
        } catch (err) {
            const errors = err.response?.data?.errors;
            if (errors) {
                setGalat(Object.values(errors).flat());
            } else {
                setPesan(err.response?.data?.pesan ?? 'Gagal menyimpan laporan.');
            }
        } finally {
            setSibuk(false);
        }
    };

    return (
        <>
            <h2 className="judul-halaman">Buat Laporan Harian</h2>

            {pesan && <div className="peringatan">{pesan}</div>}
            {galat.length > 0 && (
                <div className="peringatan">
                    <ul>{galat.map((g, i) => <li key={i}>{g}</li>)}</ul>
                </div>
            )}

            <form className="kartu" onSubmit={kirim}>
                <div className="kartu-tubuh grup-form">
                    <div className="form-grup">
                        <label htmlFor="tanggal">Tanggal *</label>
                        <input id="tanggal" type="date" className="isian" value={f.tanggal} onChange={ubah('tanggal')} required />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="jam_mulai">Jam mulai</label>
                        <input id="jam_mulai" type="time" className="isian" value={f.jam_mulai} onChange={ubah('jam_mulai')} />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="jam_selesai">Jam selesai</label>
                        <input id="jam_selesai" type="time" className="isian" value={f.jam_selesai} onChange={ubah('jam_selesai')} />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="kegiatan">Kegiatan *</label>
                        <input id="kegiatan" type="text" className="isian" value={f.kegiatan} onChange={ubah('kegiatan')} required maxLength={255} />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="lokasi_teks">Lokasi</label>
                        <input id="lokasi_teks" type="text" className="isian" value={f.lokasi_teks} onChange={ubah('lokasi_teks')} maxLength={255} />
                        <button type="button" className="btn" onClick={lokasiGps}>Gunakan GPS perangkat</button>
                    </div>
                    <div className="form-grup">
                        <label htmlFor="deskripsi">Deskripsi kegiatan</label>
                        <textarea id="deskripsi" className="isian" rows={3} value={f.deskripsi} onChange={ubah('deskripsi')} />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="hasil">Hasil</label>
                        <textarea id="hasil" className="isian" rows={3} value={f.hasil} onChange={ubah('hasil')} />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="kendala">Kendala</label>
                        <textarea id="kendala" className="isian" rows={2} value={f.kendala} onChange={ubah('kendala')} />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="rencana_berikutnya">Rencana berikutnya</label>
                        <textarea id="rencana_berikutnya" className="isian" rows={2} value={f.rencana_berikutnya} onChange={ubah('rencana_berikutnya')} />
                    </div>
                </div>
                <div className="kartu-kaki">
                    <button className="btn btn-utama" type="submit" disabled={sibuk}>
                        {sibuk ? 'Menyimpan…' : 'Simpan Laporan'}
                    </button>
                </div>
            </form>
        </>
    );
}
