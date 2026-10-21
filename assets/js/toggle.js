(function () {
    'use strict';
    var KEY = 'phr_tema';

    function isDark() {
        var r = document.documentElement;
        return r.getAttribute('data-tema') === 'gelap' || r.classList.contains('dark');
    }

    function apply(dark) {
        var r = document.documentElement;
        if (dark) {
            r.setAttribute('data-tema', 'gelap');
            r.classList.add('dark');
        } else {
            r.removeAttribute('data-tema');
            r.classList.remove('dark');
        }
        try { localStorage.setItem(KEY, dark ? 'gelap' : 'terang'); } catch (e) {}
        var b = document.getElementById('tombolTema');
        if (b) b.textContent = dark ? '☀️' : '🌙';
    }

    // Terapkan preferensi tersimpan secepat mungkin.
    try { if (localStorage.getItem(KEY) === 'gelap') apply(true); } catch (e) {}

    function toggle() {
        apply(!isDark());
    }

    // Delegasi event: tetap bekerja walau tombol dibuat ulang / skrip dimuat ulang.
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (t && t.closest && t.closest('#tombolTema')) {
            e.preventDefault();
            toggle();
        }
    }, false);

    // Keyboard: Enter/Space saat tombol fokus.
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.id === 'tombolTema') {
            e.preventDefault();
            toggle();
        }
    }, false);

    // Sinkronkan ikon tombol dengan tema saat ini.
    function syncIcon() {
        var b = document.getElementById('tombolTema');
        if (!b) return;
        if (!b.dataset.temaTerpasang) {
            b.dataset.temaTerpasang = '1';
            b.style.cursor = 'pointer';
        }
        b.textContent = isDark() ? '☀️' : '🌙';
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncIcon);
    } else {
        syncIcon();
    }
    window.addEventListener('load', syncIcon);
})();
