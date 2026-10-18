import React from 'react';

/*
 * Ikon SVG ringan (stroke mengikuti warna teks saat ini).
 * Nama ikon meniru ikon_sp() pada aplikasi legacy agar tampilan konsisten.
 */
const GAMBAR = {
    'grafik': <><path d="M3 3v18h18" /><path d="M7 15l4-6 3 3 5-7" /></>,
    'tulis': <><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z" /></>,
    'jam': <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    'kalender': <><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></>,
    'dokumen': <><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" /><path d="M14 2v6h6" /></>,
    'pengguna': <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 4-6 8-6s8 2 8 6" /></>,
    'lonceng': <><path d="M18 8a6 6 0 10-12 0c0 7-3 8-3 8h18s-3-1-3-8" /><path d="M13.7 21a2 2 0 01-3.4 0" /></>,
    'keluar': <><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" /><path d="M16 17l5-5-5-5M21 12H9" /></>,
    'buku-besar': <><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z" /><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z" /></>,
};

export default function Ikon({ nama, className = 'ikon' }) {
    return (
        <svg
            className={className}
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {GAMBAR[nama] ?? GAMBAR.dokumen}
        </svg>
    );
}
