import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import api from '../api';

/*
 * Konteks autentikasi SPA.
 *
 * Token disimpan di localStorage dan dikirim sebagai Bearer -- sama
 * seperti APK mobile, sehingga perilaku kedua klien identik dan diuji
 * oleh tes API yang sama (tests/Feature/ApiMobileTest.php).
 */
const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [pengguna, setPengguna] = useState(null);
    const [sibuk, setSibuk] = useState(true);

    useEffect(() => {
        const token = localStorage.getItem('token_api');
        if (!token) {
            setSibuk(false);
            return;
        }
        api.get('/auth/profil')
            .then((res) => setPengguna(res.data.pengguna))
            .catch(() => localStorage.removeItem('token_api'))
            .finally(() => setSibuk(false));
    }, []);

    const masuk = useCallback((token, user) => {
        localStorage.setItem('token_api', token);
        setPengguna(user);
    }, []);

    const keluar = useCallback(async () => {
        try { await api.post('/auth/logout'); } catch { /* token mungkin sudah mati */ }
        localStorage.removeItem('token_api');
        setPengguna(null);
    }, []);

    return (
        <AuthContext.Provider value={{ pengguna, sibuk, masuk, keluar }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    return useContext(AuthContext);
}
