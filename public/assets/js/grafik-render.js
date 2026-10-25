/**
 * assets/js/grafik-render.js — renderer grafik LENGKAP untuk halaman dataset.
 *
 * Berbeda dari renderer inline di analisis_sosial.php (hanya `doughnut`/`bar`),
 * berkas ini mendukung banyak tipe. Dipakai khusus oleh analisis_dataset.php,
 * sehingga halaman lama TIDAK tersentuh.
 *
 * Prasyarat: chart.umd.min.js (vendor lokal) sudah dimuat SEBELUM berkas ini.
 * Berkas ini memakai window.grafikSiap() dari assets/js/grafik.js bila ada,
 * agar tipografi/tooltip seragam dengan dasbor lain.
 *
 * Kontrak data (satu <script type="application/json" data-grafik="ID"> per grafik):
 *   {
 *     tipe:  'bar'|'bar-horizontal'|'bar-stacked'|'line'|'area'|'pie'|'doughnut'
 *            |'polarArea'|'radar'|'scatter'|'bubble',
 *     label: [string, ...],              // label kategori / sumbu X
 *     nilai: [number, ...],              // nilai / sumbu Y
 *     warna: [string, ...],              // palet (opsional)
 *     opsi:  { horizontal?, stacked?, legend?, maxHeight?, sumbuX?, sumbuY?,
 *              satuan?, desimal?, logaritma?, labelData?, multi? }
 *   }
 *
 * Bila `opsi.multi` diisi, bentuk datanya berbeda (beberapa deret):
 *   { multi: [ { label: string, nilai: [number,...], warna: string }, ... ] }
 */
(function (window, document) {
    'use strict';

    /** Label pengganti nilai kosong — HARUS sama dengan konstanta PHP. */
    var KOSONG = 'Belum diisi';

    /** Warna abu netral untuk "Belum diisi". */
    var ABU = '#94a3b8';

    /** Palet korporat default. */
    var PALET = ['#005EB8', '#159447', '#EB1C24', '#f59e0b', '#8b5cf6', '#06b6d4', '#0F766E', '#D97706', '#4A90D9', '#3DBE6E'];

    /** true bila tema gelap sedang aktif. */
    function modeGelap() {
        return document.documentElement.getAttribute('data-tema') === 'gelap';
    }

    /** Warna grid/teks yang menyesuaikan tema. */
    function warnaKontras() {
        return modeGelap()
            ? { grid: 'rgba(148,163,184,.22)', teks: '#cbd5e1' }
            : { grid: 'rgba(100,116,139,.16)', teks: '#475569' };
    }

    /** Format angka ringkas: 1.200.000 -> "1,2 jt". */
    function ringkas(n) {
        n = Number(n) || 0;
        var a = Math.abs(n);
        if (a >= 1e12) { return (n / 1e12).toFixed(1).replace('.', ',') + ' T'; }
        if (a >= 1e9) { return (n / 1e9).toFixed(1).replace('.', ',') + ' M'; }
        if (a >= 1e6) { return (n / 1e6).toFixed(1).replace('.', ',') + ' jt'; }
        if (a >= 1e3) { return (n / 1e3).toFixed(1).replace('.', ',') + ' rb'; }
        return String(n);
    }

    /** Format angka gaya Indonesia. */
    function formatAngka(n, desimal) {
        var d = typeof desimal === 'number' ? desimal : 0;
        var f = Number(n) || 0;
        return f.toLocaleString('id-ID', { minimumFractionDigits: d, maximumFractionDigits: d });
    }

    /** Pilih tipe dasar Chart.js dari tipe kita. */
    function tipeDasar(tipe) {
        switch (tipe) {
            case 'bar':
            case 'bar-horizontal':
            case 'bar-stacked':
                return 'bar';
            case 'line':
            case 'area':
                return 'line';
            case 'pie': return 'pie';
            case 'doughnut': return 'doughnut';
            case 'polarArea': return 'polarArea';
            case 'radar': return 'radar';
            case 'scatter': return 'scatter';
            case 'bubble': return 'bubble';
            default: return 'bar';
        }
    }

    /** Warna per titik: "Belum diisi" selalu abu netral. */
    function warnaTitik(labelArr, palet) {
        var p = (palet && palet.length) ? palet : PALET;
        return labelArr.map(function (l, i) {
            return l === KOSONG ? ABU : p[i % p.length];
        });
    }

    /** Bangun objek grafik untuk satu blok data. */
    function bangun(d, Chart) {
        var tipe = d.tipe || 'bar';
        var dasar = tipeDasar(tipe);
        var opsi = d.opsi || {};
        var kontras = warnaKontras();
        var palet = d.warna || PALET;

        var label = Array.isArray(d.label) ? d.label : [];
        var nilai = Array.isArray(d.nilai) ? d.nilai : [];

        var chartOpsi = { responsive: true, maintainAspectRatio: false };
        chartOpsi.plugins = { legend: { display: false } };

        var datasets = [];
        var data;

        if (opsi.multi && Array.isArray(opsi.multi)) {
            /* Beberapa deret (mis. bar stacked per kategori). */
            datasets = opsi.multi.map(function (s, i) {
                return {
                    label: s.label || ('Deret ' + (i + 1)),
                    data: Array.isArray(s.nilai) ? s.nilai : [],
                    backgroundColor: s.warna || palet[i % palet.length],
                    borderColor: s.warna || palet[i % palet.length],
                    borderWidth: 1,
                    borderRadius: 4,
                    fill: tipe === 'area',
                    tension: 0.35
                };
            });
            chartOpsi.plugins.legend = { display: true, position: opsi.legend || 'bottom' };
        } else if (dasar === 'scatter' || dasar === 'bubble') {
            /* Poin {x,y} / {x,y,r}. */
            var titik = nilai.map(function (v, i) {
                if (dasar === 'bubble') {
                    var b = Array.isArray(v) ? v : [v, v, 5];
                    return { x: Number(b[0]) || 0, y: Number(b[1]) || 0, r: Math.max(3, Math.min(20, Number(b[2]) || 6)) };
                }
                var p = Array.isArray(v) ? v : [i, v];
                return { x: Number(p[0]) || 0, y: Number(p[1]) || 0 };
            });
            datasets = [{
                data: titik,
                backgroundColor: opsi.warnaTitik || 'rgba(0,94,184,.72)',
                borderColor: '#fff',
                borderWidth: 1
            }];
            chartOpsi.plugins.tooltip = { callbacks: { label: function (c) { return '(' + formatAngka(c.parsed.x) + ', ' + formatAngka(c.parsed.y) + ')'; } } };
        } else {
            /* Satu deret biasa. */
            data = nilai.map(function (v) { return Number(v) || 0; });
            var warna;

            if (dasar === 'pie' || dasar === 'doughnut' || dasar === 'polarArea' || dasar === 'radar') {
                warna = warnaTitik(label, palet);
            } else if (tipe === 'line' || tipe === 'area') {
                warna = palet[0];
            } else {
                warna = Array.isArray(d.warna) && d.warna.length === nilai.length && d.warna.length <= 2
                    ? warnaTitik(label, palet)
                    : warnaTitik(label, palet);
            }

            var ds = { data: data, borderWidth: 1, borderColor: '#fff' };
            if (dasar === 'line') {
                ds.backgroundColor = tipe === 'area' ? 'rgba(0,94,184,.18)' : warna;
                ds.borderColor = Array.isArray(warna) ? 'rgba(0,94,184,.9)' : warna;
                ds.fill = tipe === 'area';
                ds.tension = 0.35;
                ds.pointBackgroundColor = '#005EB8';
                ds.pointRadius = 3;
            } else if (dasar === 'radar') {
                ds.backgroundColor = 'rgba(0,94,184,.18)';
                ds.borderColor = '#005EB8';
                ds.pointBackgroundColor = '#005EB8';
            } else {
                ds.backgroundColor = warna;
                ds.borderRadius = 5;
            }
            datasets = [ds];
        }

        /* Skala & sumbu. */
        if (dasar !== 'pie' && dasar !== 'doughnut' && dasar !== 'polarArea' && dasar !== 'radar') {
            var tickY = { color: kontras.teks };
            if (opsi.desimal) { tickY.precision = opsi.desimal; } else { tickY.precision = 0; }
            if (opsi.satuan === 'rupiah') {
                tickY.callback = function (v) { return opsi.ringkas === false ? formatAngka(v) : ringkas(v); };
            } else if (opsi.satuan) {
                tickY.callback = function (v) { return formatAngka(v, opsi.desimal || 0) + ' ' + opsi.satuan; };
            } else {
                tickY.callback = function (v) { return formatAngka(v, opsi.desimal || 0); };
            }

            var sumbuKategori = {
                grid: { color: kontras.grid },
                ticks: { color: kontras.teks }
            };
            var sumbuNilai = {
                beginAtZero: true,
                grid: { color: kontras.grid },
                ticks: tickY
            };
            if (opsi.logaritma) { sumbuNilai.type = 'logarithmic'; }
            if (opsi.stacked) {
                sumbuKategori.stacked = true;
                sumbuNilai.stacked = true;
            }

            chartOpsi.scales = {
                x: opsi.horizontal ? sumbuNilai : sumbuKategori,
                y: opsi.horizontal ? sumbuKategori : sumbuNilai
            };
            if (opsi.sumbuX) { chartOpsi.scales.x.title = { display: true, text: opsi.sumbuX, color: kontras.teks }; }
            if (opsi.sumbuY) { chartOpsi.scales.y.title = { display: true, text: opsi.sumbuY, color: kontras.teks }; }
        }

        if (opsi.legend) {
            chartOpsi.plugins.legend = { display: true, position: opsi.legend, labels: { color: kontras.teks } };
        }

        /* Tooltip: tampilkan angka lengkap + satuan. */
        if (!chartOpsi.plugins.tooltip) {
            chartOpsi.plugins.tooltip = {
                callbacks: {
                    label: function (c) {
                        var v = c.parsed.y !== undefined && c.parsed.y !== null ? c.parsed.y : c.parsed;
                        var teks = formatAngka(v, opsi.desimal || 0);
                        if (opsi.satuan === 'rupiah') { teks = 'Rp ' + formatAngka(v); }
                        else if (opsi.satuan && typeof v === 'number') { teks += ' ' + opsi.satuan; }
                        return (c.dataset && c.dataset.label ? c.dataset.label + ': ' : '') + teks;
                    }
                }
            };
        }

        return { tipe: dasar, data: { labels: label, datasets: datasets }, options: chartOpsi };
    }

    /** Render semua grafik pada halaman. */
    function render(chart) {
        var Chart = chart || window.Chart;
        if (!Chart) { return; }

        var wadah = document.querySelectorAll('script[data-grafik]');
        Array.prototype.forEach.call(wadah, function (el) {
            var d;
            try { d = JSON.parse(el.textContent || '{}'); } catch (e) { return; }

            var kanvas = document.getElementById(el.getAttribute('data-grafik'));
            if (!kanvas) { return; }

            var opsi = d.opsi || {};
            if (opsi.maxHeight) { kanvas.style.maxHeight = opsi.maxHeight; }

            try {
                var konf = bangun(d, Chart);
                new Chart(kanvas, konf);
            } catch (galat) {
                console.error('Gagal merender grafik', el.getAttribute('data-grafik'), galat);
                if (window.grafikKosong) {
                    window.grafikKosong(el.getAttribute('data-grafik'), 'Grafik gagal ditampilkan.');
                }
            }
        });
    }

    /** Render ulang saat tema berubah (warna grid/teks menyesuaikan). */
    function pasangPemantauTema() {
        var pengamat = new MutationObserver(function () {
            // Hancurkan grafik lama lalu gambar ulang.
            if (window.Chart && Chart.instances) {
                Object.keys(Chart.instances).forEach(function (k) {
                    try { Chart.instances[k].destroy(); } catch (e) { /* abaikan */ }
                });
            }
            render();
        });
        pengamat.observe(document.documentElement, { attributes: true, attributeFilter: ['data-tema'] });
    }

    window.grafikRenderSemua = function () {
        if (typeof window.grafikSiap === 'function') {
            window.grafikSiap(function (Chart) { render(Chart); pasangPemantauTema(); });
        } else if (window.Chart) {
            render();
            pasangPemantauTema();
        }
    };

    /* Jalankan otomatis bila DOM sudah siap. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.grafikRenderSemua(); });
    } else {
        window.grafikRenderSemua();
    }
})(window, document);
