import { useRef, useState } from 'react';
import api from '../api';

/*
 * Asisten AI: chat lewat POST /api/v1/asisten/kirim.
 * Jawaban dihasilkan dari data database (angka nyata) + LLM opsional;
 * server menjamin tidak pernah menggantung (kunci mati LLM otomatis).
 */
export default function Asisten() {
    const [percakapan, setPercakapan] = useState([]);
    const [pesan, setPesan] = useState('');
    const [sibuk, setSibuk] = useState(false);
    const kotak = useRef(null);

    const kirim = async (e) => {
        e.preventDefault();
        const teks = pesan.trim();
        if (!teks || sibuk) return;
        setPercakapan((v) => [...v, { peran: 'user', isi: teks }]);
        setPesan('');
        setSibuk(true);
        try {
            const res = await api.post('/asisten/kirim', { pesan: teks });
            setPercakapan((v) => [...v, { peran: 'assistant', isi: res.data.jawaban }]);
        } catch {
            setPercakapan((v) => [...v, {
                peran: 'assistant',
                isi: 'Maaf, saya sedang sibuk — silakan coba lagi beberapa saat lagi.',
            }]);
        } finally {
            setSibuk(false);
            requestAnimationFrame(() => {
                if (kotak.current) kotak.current.scrollTop = kotak.current.scrollHeight;
            });
        }
    };

    return (
        <>
            <h2 className="judul-halaman">Asisten AI</h2>

            <div className="kartu">
                <div className="kartu-tubuh">
                    <div className="kotak-chat" ref={kotak}>
                        {percakapan.length === 0 && (
                            <p className="tabel-kosong">
                                Tanyakan misalnya: “berapa laporan bulan ini?” atau “siapa yang belum absen hari ini?”
                            </p>
                        )}
                        {percakapan.map((p, i) => (
                            <div className={`pesan-chat pesan-${p.peran}`} key={i}>
                                <strong>{p.peran === 'user' ? 'Anda' : 'Asisten'}:</strong> {p.isi}
                            </div>
                        ))}
                        {sibuk && <div className="pesan-chat pesan-assistant"><em>mengetik…</em></div>}
                    </div>
                    <form className="baris-chat" onSubmit={kirim}>
                        <input
                            className="isian"
                            type="text"
                            value={pesan}
                            placeholder="Tulis pertanyaan…"
                            onChange={(e) => setPesan(e.target.value)}
                            disabled={sibuk}
                        />
                        <button className="btn btn-utama" type="submit" disabled={sibuk || !pesan.trim()}>
                            Kirim
                        </button>
                    </form>
                </div>
            </div>
        </>
    );
}
