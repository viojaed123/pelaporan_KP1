import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../api';

const OPSI_URGENSI = ['Rendah', 'Sedang', 'Tinggi', 'Kritis'];
const OPSI_STATUS = ['Terbuka', 'Dalam Proses', 'Selesai'];
const OPSI_KATEGORI = [
    'Sosial & Kemasyarakatan', 'Lingkungan', 'Kesehatan', 'Infrastruktur',
    'Ekonomi & Mata Pencaharian', 'Keamanan', 'Lainnya',
];

/*
 * Formulir kasus (buat & edit) dengan PIC dan anggota tim
 * DIKETIK BEBAS -- tanpa dropdown, sesuai keputusan desain:
 *   PIC     : satu kolom teks (nama atau surel);
 *   Anggota : kotak teks multi-baris, satu nama per baris.
 * Nama yang cocok dengan pengguna terdaftar otomatis tertaut di server
 * (lihat App\Services\CocokPengguna); yang tidak cocok tetap teks.
 */
export default function KasusForm() {
    const { id } = useParams();
    const modeEdit = !!id;
    const navigasi = useNavigate();

    const [f, setF] = useState({
        judul: '',
        deskripsi: '',
        kategori: '',
        tingkat_urgensi: 'Sedang',
        status_kasus: 'Terbuka',
        progres: 0,
        target_selesai: '',
        nama_lokasi: '',
        garis_lintang: '',
        garis_bujur: '',
        penanggung_jawab: '',
        anggota: '',
    });
    const [galat, setGalat] = useState([]);
    const [pesan, setPesan] = useState('');
    const [sibuk, setSibuk] = useState(false);
    const [memuat, setMemuat] = useState(modeEdit);

    useEffect(() => {
        if (!modeEdit) return;
        api.get(`/kasus/${id}`)
            .then((res) => {
                const k = res.data.kasus;
                setF({
                    judul: k.judul ?? '',
                    deskripsi: k.deskripsi ?? '',
                    kategori: k.kategori ?? '',
                    tingkat_urgensi: k.urgensi ?? 'Sedang',
                    status_kasus: k.status ?? 'Terbuka',
                    progres: k.progres ?? 0,
                    target_selesai: k.target_selesai ?? '',
                    nama_lokasi: k.lokasi ?? '',
                    garis_lintang: '',
                    garis_bujur: '',
                    penanggung_jawab: k.penanggung_jawab ?? '',
                    /* Anggota dikembalikan sebagai satu nama per baris. */
                    anggota: (k.anggota ?? []).map((a) => a.nama).join('\n'),
                });
            })
            .catch(() => setPesan('Gagal memuat data kasus.'))
            .finally(() => setMemuat(false));
    }, [id, modeEdit]);

    const ubah = (k) => (e) => setF({ ...f, [k]: e.target.value });

    const kirim = async (e) => {
        e.preventDefault();
        setSibuk(true);
        setGalat([]);
        setPesan('');
        try {
            const res = modeEdit
                ? await api.put(`/kasus/${id}`, f)
                : await api.post('/kasus', f);
            setPesan(res.data.pesan);
            navigasi('/kasus');
        } catch (err) {
            const errors = err.response?.data?.errors;
            setGalat(errors ? Object.values(errors).flat() : [err.response?.data?.pesan ?? 'Gagal menyimpan kasus.']);
        } finally {
            setSibuk(false);
        }
    };

    if (memuat) return <div className="muat">Memuat…</div>;

    return (
        <>
            <h2 className="judul-halaman">{modeEdit ? 'Edit Kasus' : 'Buat Kasus Baru'}</h2>

            {pesan && <div className="peringatan">{pesan}</div>}
            {galat.length > 0 && (
                <div className="peringatan"><ul>{galat.map((g, i) => <li key={i}>{g}</li>)}</ul></div>
            )}

            <form className="kartu" onSubmit={kirim}>
                <div className="kartu-tubuh grup-form">
                    <div className="form-grup">
                        <label htmlFor="judul">Judul Kasus *</label>
                        <input id="judul" className="isian" value={f.judul} onChange={ubah('judul')} required maxLength={200} />
                    </div>
                    <div className="form-grup">
                        <label htmlFor="deskripsi">Deskripsi</label>
                        <textarea id="deskripsi" className="isian" rows={4} value={f.deskripsi} onChange={ubah('deskripsi')} />
                    </div>
                    <div className="grup-form-dua">
                        <div className="form-grup">
                            <label htmlFor="kategori">Kategori</label>
                            <select id="kategori" className="isian" value={f.kategori} onChange={ubah('kategori')}>
                                <option value="">— Pilih kategori —</option>
                                {OPSI_KATEGORI.map((k) => <option key={k} value={k}>{k}</option>)}
                            </select>
                        </div>
                        <div className="form-grup">
                            <label htmlFor="urgensi">Tingkat Urgensi *</label>
                            <select id="urgensi" className="isian" value={f.tingkat_urgensi} onChange={ubah('tingkat_urgensi')}>
                                {OPSI_URGENSI.map((u) => <option key={u} value={u}>{u}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="grup-form-tiga">
                        <div className="form-grup">
                            <label htmlFor="status">Status</label>
                            <select id="status" className="isian" value={f.status_kasus} onChange={ubah('status_kasus')}>
                                {OPSI_STATUS.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </div>
                        <div className="form-grup">
                            <label htmlFor="progres">Progres (%)</label>
                            <input id="progres" type="number" min="0" max="100" className="isian" value={f.progres} onChange={ubah('progres')} />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="target">Target Selesai</label>
                            <input id="target" type="date" className="isian" value={f.target_selesai} onChange={ubah('target_selesai')} />
                        </div>
                    </div>
                    <div className="form-grup">
                        <label htmlFor="lokasi">Nama Lokasi</label>
                        <input id="lokasi" className="isian" value={f.nama_lokasi} onChange={ubah('nama_lokasi')} maxLength={200} />
                    </div>
                    <div className="grup-form-dua">
                        <div className="form-grup">
                            <label htmlFor="lat">Garis Lintang (opsional)</label>
                            <input id="lat" className="isian" value={f.garis_lintang} onChange={ubah('garis_lintang')} placeholder="Contoh: 1.28111" />
                        </div>
                        <div className="form-grup">
                            <label htmlFor="lng">Garis Bujur (opsional)</label>
                            <input id="lng" className="isian" value={f.garis_bujur} onChange={ubah('garis_bujur')} placeholder="Contoh: 101.21345" />
                        </div>
                    </div>

                    {/* PIC & anggota: TEKS BEBAS, tanpa dropdown. */}
                    <div className="form-grup">
                        <label htmlFor="pj">Penanggung Jawab (PIC)</label>
                        <input id="pj" className="isian" value={f.penanggung_jawab} onChange={ubah('penanggung_jawab')}
                               maxLength={150} placeholder="Ketik nama atau surel penanggung jawab" />
                        <span className="teks-redup">Ketik bebas — bila cocok dengan pengguna terdaftar, otomatis tertaut.</span>
                    </div>
                    <div className="form-grup">
                        <label htmlFor="anggota">Anggota Tim (satu nama per baris)</label>
                        <textarea id="anggota" className="isian" rows={4} value={f.anggota} onChange={ubah('anggota')}
                                  placeholder={'Budi\nSari\nAndi'} />
                        <span className="teks-redup">Ketik bebas, satu nama per baris. Nama yang cocok dengan pengguna terdaftar otomatis tertaut.</span>
                    </div>
                </div>
                <div className="kartu-kaki">
                    <button className="btn btn-utama" type="submit" disabled={sibuk}>
                        {sibuk ? 'Menyimpan…' : 'Simpan Kasus'}
                    </button>
                </div>
            </form>
        </>
    );
}
