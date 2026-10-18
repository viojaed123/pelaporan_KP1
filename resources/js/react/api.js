import axios from 'axios';

/**
 * Klien API untuk SPA React.
 *
 * Memakai token Bearer yang sama dengan aplikasi mobile (Sanctum),
 * sehingga SATU set endpoint (/api/v1/*) melayani keduanya.
 * Bila token kedaluwarsa/dicabut (401), pengguna otomatis
 * dikembalikan ke halaman masuk SPA.
 */
const api = axios.create({ baseURL: '/api/v1' });

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('token_api');
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
});

api.interceptors.response.use(
    (res) => res,
    (err) => {
        const url = err.config?.url ?? '';
        const kecualiLogin = url.includes('/auth/login') || url.includes('/auth/verifikasi');
        if (err.response?.status === 401 && !kecualiLogin) {
            localStorage.removeItem('token_api');
            window.location.assign('/app');
        }
        return Promise.reject(err);
    },
);

export default api;
