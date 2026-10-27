/* ==================================================================
   app.js — perilaku bersama seluruh halaman
   ================================================================== */
(function () {
    'use strict';

    var META_CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    /* ---------- Dropdown ---------------------------------------- */
    document.addEventListener('click', function (e) {
        var pemicu = e.target.closest('[data-dropdown]');
        var menuTerbuka = document.querySelector('.dropdown-menu.tampil');

        if (pemicu) {
            var id = pemicu.getAttribute('data-dropdown');
            var menu = document.getElementById(id);
            if (menu) {
                var akanBuka = !menu.classList.contains('tampil');
                if (menuTerbuka) menuTerbuka.classList.remove('tampil');
                if (akanBuka) menu.classList.add('tampil');
                e.preventDefault();
                return;
            }
        }

        if (menuTerbuka && !e.target.closest('.dropdown-menu')) {
            menuTerbuka.classList.remove('tampil');
        }

        /* Modal */
        if (e.target.classList && e.target.classList.contains('modal-latar')) {
            e.target.classList.remove('tampil');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var m = document.querySelector('.modal-latar.tampil');
            if (m) m.classList.remove('tampil');
            var p = document.getElementById('panelChat');
            if (p && p.classList.contains('tampil')) p.classList.remove('tampil');
        }
    });

    window.bukaModal = function (id) {
        var m = document.getElementById(id);
        if (m) m.classList.add('tampil');
    };
    window.tutupModal = function (id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('tampil');
    };

    /* ---------- Konfirmasi hapus -------------------------------- */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.matches('[data-konfirmasi]')) {
            var pesan = f.getAttribute('data-konfirmasi') ||
                'Lanjutkan tindakan ini?';
            if (!window.confirm(pesan)) {
                e.preventDefault();
            }
        }
    });

    /* ---------- Token CSRF otomatis untuk form AJAX ------------- */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.method && f.method.toUpperCase() === 'POST' && META_CSRF &&
            !f.querySelector('input[name="csrf_token"]')) {
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'csrf_token';
            i.value = META_CSRF;
            f.appendChild(i);
        }
    }, true);

    /* ---------- Tampilkan/sembunyikan kata sandi ---------------- */
    var IKON_MATA = '<svg class="sp-ikon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        + ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        + ' aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>'
        + '<circle cx="12" cy="12" r="3"/></svg>';
    var IKON_MATA_SILANG = '<svg class="sp-ikon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        + ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        + ' aria-hidden="true" focusable="false"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8'
        + 'a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8'
        + 'a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>'
        + '<line x1="1" y1="1" x2="23" y2="23"/></svg>';
    document.addEventListener('click', function (e) {
        var m = e.target.closest('[data-mata]');
        if (!m) return;
        var input = document.getElementById(m.getAttribute('data-mata'));
        if (!input) return;
        var tampil = input.type === 'password';
        input.type = tampil ? 'text' : 'password';
        m.innerHTML = tampil ? IKON_MATA_SILANG : IKON_MATA;
        m.setAttribute('aria-label', tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
    });

    /* ---------- Pengukur kekuatan kata sandi -------------------- */
    function skorSandi(v) {
        var s = 0;
        if (v.length >= 10) s++;
        if (v.length >= 14) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
        if (/\d/.test(v)) s++;
        if (/[^A-Za-z0-9]/.test(v)) s++;
        if (/^(.)\1+$/.test(v)) s = 0;
        return s;
    }

    document.addEventListener('input', function (e) {
        var input = e.target;
        if (!input.matches || !input.matches('[data-sandi]')) return;

        var kotak = document.getElementById(input.getAttribute('data-sandi'));
        if (!kotak) return;

        var skor = skorSandi(input.value);
        var label = kotak.nextElementSibling;

        kotak.className = 'kekuatan-sandi ' +
            (skor <= 1 ? 'lemah' : (skor <= 3 ? 'sedang' : 'kuat'));

        if (label && label.classList.contains('label-kekuatan')) {
            label.textContent = input.value === ''
                ? ''
                : (skor <= 1 ? 'Terlalu lemah'
                    : (skor <= 3 ? 'Cukup — tambahkan angka/simbol'
                        : 'Kuat — kata sandi siap dipakai'));
        }
    });

    /* ---------- Pratinjau unggahan gambar/berkas ---------------- */
    function formatBytes(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(2) + ' MB';
    }

    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input.matches || !input.matches('input[type="file"]')) return;

        var kotakId = input.getAttribute('data-pratinjau');
        if (!kotakId) return;
        var kotak = document.getElementById(kotakId);
        if (!kotak) return;

        kotak.innerHTML = '';
        var files = input.files;
        var batas = parseInt(input.getAttribute('data-maks') || '5', 10);

        if (files.length > batas) {
            var p = document.createElement('div');
            p.className = 'alert alert-peringatan';
            p.textContent = 'Maksimal ' + batas + ' berkas per unggahan.';
            kotak.appendChild(p);
            input.value = '';
            return;
        }

        for (var i = 0; i < files.length; i++) {
            (function (f) {
                var baris = document.createElement('div');
                baris.className = 'pratinjau-berkas';

                var teks = document.createElement('span');
                teks.textContent = f.name + ' · ' + formatBytes(f.size);
                baris.appendChild(teks);

                if (f.type.indexOf('image/') === 0) {
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(f);
                    img.alt = '';
                    img.style.cssText =
                        'width:38px;height:38px;object-fit:cover;border-radius:6px;flex:0 0 38px';
                    baris.insertBefore(img, teks);
                }

                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'hapus';
                x.textContent = '×';
                x.title = 'Hapus dari daftar';
                x.addEventListener('click', function () { baris.remove(); });
                baris.appendChild(x);

                kotak.appendChild(baris);
            })(files[i]);
        }
    });

    /* ---------- Seret & lepas untuk area unggah ----------------- */
    document.addEventListener('dragover', function (e) {
        var a = e.target.closest && e.target.closest('.area-unggah');
        if (a) { e.preventDefault(); a.classList.add('seret'); }
    });
    document.addEventListener('dragleave', function (e) {
        var a = e.target.closest && e.target.closest('.area-unggah');
        if (a) a.classList.remove('seret');
    });
    document.addEventListener('drop', function (e) {
        var a = e.target.closest && e.target.closest('.area-unggah');
        if (!a) return;
        e.preventDefault();
        a.classList.remove('seret');
        var input = a.querySelector('input[type="file"]');
        if (input && e.dataTransfer && e.dataTransfer.files) {
            try { input.files = e.dataTransfer.files; } catch (err) { /* kompatibilitas */ }
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    /* ---------- Kirim form via Enter (textarea: Ctrl+Enter) ----- */
    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'TEXTAREA' && e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            var f = e.target.closest('form');
            if (f) { e.preventDefault(); f.requestSubmit ? f.requestSubmit() : f.submit(); }
        }
    });

    /* ==========================================================
       CHATBOT ASISTEN
       ========================================================== */
    /* ==========================================================
       SESI PERCAKAPAN (bersama panel mengapung & halaman penuh)
       Sesi dikirim ke api_chat.php agar AI punya ingatan
       percakapan dan riwayat halaman tidak terpotong-potong.
       ========================================================== */
    var chatSesi = 0;

    function sesiChat(el) {
        if (chatSesi > 0) return chatSesi;
        var d = el ? parseInt(el.getAttribute('data-sesi'), 10) : 0;
        if (d > 0) {
            chatSesi = d;
        } else {
            try { d = parseInt(sessionStorage.getItem('chat_sesi') || '0', 10); } catch (e) { d = 0; }
            if (d > 0) chatSesi = d;
        }
        return chatSesi;
    }

    function simpanSesiChat(id, el) {
        id = parseInt(id, 10);
        if (!(id > 0)) return;
        chatSesi = id;
        if (el) el.setAttribute('data-sesi', String(id));
        try { sessionStorage.setItem('chat_sesi', String(id)); } catch (e) {}
    }

    /* Tautan sumber jawaban (web search / catatan) di bawah balasan bot. */
    function sumberChat(el, daftar) {
        if (!Array.isArray(daftar)) return;
        var tautan = daftar.filter(function (s) { return s && typeof s.tautan === 'string' && s.tautan; });
        if (!tautan.length) return;
        var d = document.createElement('div');
        d.className = 'sumber-chat';
        d.appendChild(document.createTextNode('Sumber: '));
        tautan.forEach(function (s, i) {
            if (i > 0) d.appendChild(document.createTextNode(' · '));
            var a = document.createElement('a');
            a.href = s.tautan;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = s.judul || s.tautan;
            d.appendChild(a);
        });
        d.style.cssText = 'font-size:12px;color:#005EB8;padding:0 10px 10px;word-break:break-word;';
        el.appendChild(d);
        el.scrollTop = el.scrollHeight;
    }

    function initChat() {
        var panel = document.getElementById('panelChat');
        if (!panel) return;

        var tombol = document.getElementById('tombolChat');
        var tutup = document.getElementById('tutupChat');
        var badan = document.getElementById('badanChat');
        var input = document.getElementById('inputChat');
        var kirim = document.getElementById('kirimChat');
        var saran = document.getElementById('saranChat');

        function buka(status) {
            panel.classList.toggle('tampil', status);
            if (tombol) tombol.setAttribute('aria-expanded', String(status));
            if (status && input) setTimeout(function () { input.focus(); }, 120);
        }

        if (tombol) tombol.addEventListener('click', function () { buka(!panel.classList.contains('tampil')); });
        if (tutup) tutup.addEventListener('click', function () { buka(false); });

        function tambahPesan(peran, teks) {
            var d = document.createElement('div');
            d.className = 'pesan-chat ' + peran;
            d.textContent = teks;
            badan.appendChild(d);
            badan.scrollTop = badan.scrollHeight;
            return d;
        }

        function tambahMengetik() {
            var d = document.createElement('div');
            d.className = 'pesan-chat bot mengetik';
            d.innerHTML = '<i></i><i></i><i></i>';
            badan.appendChild(d);
            badan.scrollTop = badan.scrollHeight;
            return d;
        }

        var sedangKirim = false;

        function kirimPesan(teks) {
            teks = (teks || '').trim();
            if (teks === '' || sedangKirim) return;

            sedangKirim = true;
            if (kirim) kirim.disabled = true;
            tambahPesan('user', teks);
            if (input) input.value = '';

            var mengetik = tambahMengetik();

            fetch('api_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': META_CSRF },
                credentials: 'same-origin',
                body: JSON.stringify({ pesan: teks, session_id: sesiChat(badan) })
            })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    mengetik.remove();
                    if (data && data.session) simpanSesiChat(data.session, badan);
                    if (data && data.balasan) {
                        tambahPesan('bot', data.balasan);
                        sumberChat(badan, data.sumber);
                    } else {
                        tambahPesan('bot', 'Maaf, saya belum mendapat jawaban. Coba ulangi.');
                    }
                })
                .catch(function (err) {
                    mengetik.remove();
                    tambahPesan('bot',
                        'Layanan AI belum tersedia. Pastikan layanan Python sudah berjalan.\n(' + err.message + ')');
                })
                .finally(function () {
                    sedangKirim = false;
                    if (kirim) kirim.disabled = false;
                    if (input) input.focus();
                });
        }

        if (kirim) kirim.addEventListener('click', function () { kirimPesan(input.value); });

        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    kirimPesan(input.value);
                }
            });
        }

        if (saran) {
            saran.addEventListener('click', function (e) {
                var b = e.target.closest('[data-saran]');
                if (b) kirimPesan(b.getAttribute('data-saran'));
            });
        }
    }

    /* ==========================================================
       CHATBOT ASISTEN — HALAMAN PENUH (asisten.php)
       ========================================================== */
    function initChatPenuh() {
        var badan = document.getElementById('asistenBadan');
        if (!badan) return;

        var input = document.getElementById('asistenInput');
        var kirim = document.getElementById('asistenKirim');
        var saran = document.getElementById('asistenSaran');

        function gulirBawah() { badan.scrollTop = badan.scrollHeight; }
        if (badan.children.length > 1) gulirBawah();

        function tambahPesan(peran, teks) {
            var d = document.createElement('div');
            d.className = 'pesan-chat ' + peran;
            d.textContent = teks;
            badan.appendChild(d);
            gulirBawah();
            return d;
        }

        function tambahMengetik() {
            var d = document.createElement('div');
            d.className = 'pesan-chat bot mengetik';
            d.innerHTML = '<i></i><i></i><i></i>';
            badan.appendChild(d);
            gulirBawah();
            return d;
        }

        var sedangKirim = false;

        function kirimPesan(teks) {
            teks = (teks || '').trim();
            if (teks === '' || sedangKirim) return;

            sedangKirim = true;
            if (kirim) kirim.disabled = true;
            tambahPesan('user', teks);
            if (input) input.value = '';

            var mengetik = tambahMengetik();

            fetch('api_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': META_CSRF },
                credentials: 'same-origin',
                body: JSON.stringify({ pesan: teks, session_id: sesiChat(badan) })
            })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    mengetik.remove();
                    if (data && data.session) simpanSesiChat(data.session, badan);
                    if (data && data.balasan) {
                        tambahPesan('bot', data.balasan);
                        sumberChat(badan, data.sumber);
                    } else {
                        tambahPesan('bot', 'Maaf, saya belum mendapat jawaban. Coba ulangi.');
                    }
                })
                .catch(function (err) {
                    mengetik.remove();
                    tambahPesan('bot',
                        'Layanan AI belum tersedia. Pastikan layanan Python sudah berjalan.\n(' + err.message + ')');
                })
                .finally(function () {
                    sedangKirim = false;
                    if (kirim) kirim.disabled = false;
                    if (input) input.focus();
                });
        }

        if (kirim) kirim.addEventListener('click', function () { kirimPesan(input.value); });

        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    kirimPesan(input.value);
                }
            });
            input.addEventListener('input', function () {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 160) + 'px';
            });
        }

        if (saran) {
            saran.addEventListener('click', function (e) {
                var b = e.target.closest('[data-saran]');
                if (b) kirimPesan(b.getAttribute('data-saran'));
            });
        }

        // Kartu kemampuan juga mengirim pertanyaan.
        document.querySelectorAll('[data-saran]').forEach(function (el) {
            if (el.closest('#asistenSaran')) return;
            el.addEventListener('click', function () { kirimPesan(el.getAttribute('data-saran')); });
        });
    }

    /* ---------- Polling notifikasi ringan ----------------------- */
    function initNotifikasiBadge() {
        var lonceng = document.querySelector('.tombol-lonceng .titik');
        if (!lonceng) return;
        setInterval(function () {
            fetch('api_notifikasi_jumlah.php', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d) return;
                    var n = parseInt(d.jumlah || 0, 10);
                    if (n > 0) {
                        lonceng.textContent = n > 99 ? '99+' : String(n);
                        lonceng.style.display = '';
                    } else {
                        lonceng.style.display = 'none';
                    }
                })
                .catch(function () { /* diabaikan */ });
        }, 60000);
    }

    /* ==========================================================
       PERIKSA KELENGKAPAN LAPORAN (AI) — buat_laporan / edit_revisi
       ========================================================== */
    function initValidasiAI() {
        var tombol = document.querySelectorAll('[data-validasi-ai]');
        if (!tombol.length) return;

        function nilai(nama) {
            var el = document.querySelector('[name="' + nama + '"]');
            return el ? String(el.value || '') : '';
        }

        Array.prototype.forEach.call(tombol, function (btn) {
            btn.addEventListener('click', function () {
                var target = document.querySelector(btn.getAttribute('data-hasil') || '#hasilValidasiAI');
                if (!target) return;

                btn.disabled = true;
                var teksAsli = btn.textContent;
                btn.textContent = 'Memeriksa…';
                target.className = 'narasi-hasil';
                target.hidden = false;
                target.innerHTML = '<p class="text-redup">Menghubungi asisten AI…</p>';

                var isian = {};
                ['tanggal', 'jam_mulai', 'jam_selesai', 'kegiatan',
                 'deskripsi', 'hasil', 'kendala', 'rencana_berikutnya', 'rencana'
                ].forEach(function (k) { isian[k] = nilai(k); });

                fetch('api_validasi.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': META_CSRF },
                    body: JSON.stringify({ laporan: isian })
                })
                    .then(function (r) { return r.json().then(function (d) { return { kode: r.status, data: d }; }); })
                    .then(function (hasil) {
                        var d = hasil.data || {};
                        if (!d.ok) {
                            target.className = 'narasi-hasil';
                            target.innerHTML = '<p class="text-redup">' +
                                (d.balasan || d.galat || 'Pemeriksaan AI tidak tersedia.') + '</p>';
                            return;
                        }
                        var v = d.verdict || {};
                        var skor = (v.skor === undefined || v.skor === null) ? '—' : v.skor;
                        var badgeSkor = v.lengkap ? 'badge-hijau' : 'badge-merah';
                        var badgeVerdict = v.lengkap ? 'badge-hijau' : 'badge-kuning';
                        var teksVerdict = v.verdict ? String(v.verdict) : (v.lengkap ? 'LENGKAP' : 'PERLU DIPERBAIKI');
                        var html = '<div class="ringkasan-pil">' +
                            '<span class="badge ' + badgeSkor + ' badge-tanpa-titik">Skor kelengkapan: ' + skor + '/100</span>' +
                            '<span class="badge ' + badgeVerdict + ' badge-tanpa-titik">' + teksVerdict + '</span>' +
                            '</div>';

                        var galat = v.galat || {};
                        var kunciG = Object.keys(galat);
                        if (kunciG.length) {
                            html += '<p><strong>Perlu diperbaiki:</strong></p><ul>';
                            kunciG.forEach(function (k) { html += '<li>' + k + ': ' + galat[k] + '</li>'; });
                            html += '</ul>';
                        }
                        var peringatan = v.peringatan || [];
                        if (peringatan.length) {
                            html += '<p><strong>Catatan:</strong></p><ul>';
                            peringatan.forEach(function (p) { html += '<li>' + p + '</li>'; });
                            html += '</ul>';
                        }
                        if (d.balasan) {
                            html += '<p class="text-redup">' + d.balasan.replace(/</g, '&lt;') + '</p>';
                        }
                        target.innerHTML = html;
                    })
                    .catch(function (err) {
                        target.className = 'narasi-hasil';
                        target.innerHTML = '<p class="text-redup">Gagal menghubungi asisten AI (' +
                            (err.message || 'jaringan') + ').</p>';
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.textContent = teksAsli;
                    });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initChat(); initChatPenuh(); initValidasiAI(); initNotifikasiBadge();
        });
    } else {
        initChat(); initChatPenuh(); initValidasiAI(); initNotifikasiBadge();
    }
})();

/* ==================================================================
   Sidebar: mode mini, grup terlipat, dan pencarian menu.
   Ditulis sebagai IIFE terpisah agar tidak mengganggu perilaku di atas.
   ================================================================== */
(function () {
    'use strict';

    var KUNCI_MINI = 'phr_sidebar_mini';
    var KUNCI_GRUP = 'phr_sidebar_grup';

    function simpan(kunci, nilai) {
        try { localStorage.setItem(kunci, nilai); } catch (e) { /* abaikan */ }
    }

    function ambil(kunci) {
        try { return localStorage.getItem(kunci); } catch (e) { return null; }
    }

    function muatGrupTertutup() {
        var mentah = ambil(KUNCI_GRUP);
        if (!mentah) return [];
        try {
            var arr = JSON.parse(mentah);
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    function simpanGrupTertutup(daftar) {
        simpan(KUNCI_GRUP, JSON.stringify(daftar));
    }

    function initSidebar() {
        var app = document.querySelector('.app');
        var sidebar = document.getElementById('sidebar');
        if (!app || !sidebar) return;

        var tombolMini = document.getElementById('tombolMini');

        /* --- Mode mini --- */
        if (ambil(KUNCI_MINI) === '1') {
            app.classList.add('mini');
        }
        function sinkronMini() {
            if (tombolMini) {
                tombolMini.setAttribute('aria-pressed', app.classList.contains('mini') ? 'true' : 'false');
            }
        }
        sinkronMini();
        if (tombolMini) {
            tombolMini.addEventListener('click', function () {
                app.classList.toggle('mini');
                simpan(KUNCI_MINI, app.classList.contains('mini') ? '1' : '0');
                sinkronMini();
            });
        }

        /* --- Grup yang dapat dilipat --- */
        var grupTertutup = muatGrupTertutup();
        var daftarGrup = Array.prototype.slice.call(sidebar.querySelectorAll('.nav-grup'));

        daftarGrup.forEach(function (grup) {
            var judul = grup.querySelector('.nav-judul');
            var nama = grup.getAttribute('data-grup') || '';
            if (!judul) return;

            function terapkan(tertutup) {
                grup.classList.toggle('terlipat', tertutup);
                judul.setAttribute('aria-expanded', tertutup ? 'false' : 'true');
            }

            terapkan(grupTertutup.indexOf(nama) !== -1);

            judul.addEventListener('click', function () {
                var akanTutup = !grup.classList.contains('terlipat');
                terapkan(akanTutup);
                var idx = grupTertutup.indexOf(nama);
                if (akanTutup && idx === -1) {
                    grupTertutup.push(nama);
                } else if (!akanTutup && idx !== -1) {
                    grupTertutup.splice(idx, 1);
                }
                simpanGrupTertutup(grupTertutup);
            });
        });

        /* --- Pencarian menu --- */
        var cari = document.getElementById('cariMenu');
        if (cari) {
            cari.addEventListener('input', function () {
                var kata = cari.value.trim().toLowerCase();
                if (kata !== '') {
                    app.classList.remove('mini'); // pastikan label terlihat saat mencari
                }
                daftarGrup.forEach(function (grup) {
                    var adaCocok = false;
                    var items = Array.prototype.slice.call(grup.querySelectorAll('.nav-item'));
                    items.forEach(function (item) {
                        var teks = (item.textContent || '').toLowerCase();
                        var cocok = (kata === '' || teks.indexOf(kata) !== -1);
                        item.style.display = cocok ? '' : 'none';
                        if (cocok) adaCocok = true;
                    });
                    if (kata === '') {
                        grup.style.display = '';
                        grup.classList.remove('terlipat');
                    } else {
                        grup.style.display = adaCocok ? '' : 'none';
                        grup.classList.remove('terlipat');
                    }
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
