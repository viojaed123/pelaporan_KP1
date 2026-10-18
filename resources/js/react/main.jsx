import { createRoot } from 'react-dom/client';
import App from './App';

/* Titik masuk SPA React (di-merge oleh Vite lewat resources/js/app.js). */
const wadah = document.getElementById('app');
if (wadah) {
    createRoot(wadah).render(<App />);
}
