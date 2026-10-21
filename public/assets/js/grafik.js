/**
 * assets/js/grafik.js — pembantu grafik bersama untuk seluruh dasbor.
 *
 * Prasyarat: chart.umd.min.js (Chart.js 4, vendor lokal) sudah dimuat
 * sebelum berkas ini (keduanya memakai atribut defer sehingga urutan
 * eksekusi terjaga).
 *
 * Menyediakan:
 *   - window.WARNA_GRAFIK      : palet korporat (#005EB8/#003D7D/#EB1C24/#159447)
 *   - window.grafikSiap(cb)    : tunggu Chart lalu panggil cb(Chart) dengan
 *                                default tipografi/tooltip yang seragam
 *   - window.grafikKosong(id, pesan) : ganti canvas dengan pesan "belum ada data"
 */
(function (window) {
    'use strict';

    /** Palet warna korporat — dipakai konsisten di semua dasbor. */
    var WARNA = {
        navy: '#003D7D',
        biru: '#005EB8',
        biruMuda: '#4A90D9',
        hijau: '#159447',
        hijauMuda: '#3DBE6E',
        kuning: '#d69e2e',
        merah: '#EB1C24',
        abu: '#94a3b8',
        abuMuda: '#cbd5e1'
    };

    /** Urutan warna status laporan: draft, menunggu, diperiksa, revisi. */
    var WARNA_STATUS = [WARNA.abu, WARNA.kuning, WARNA.hijau, WARNA.merah];

    /** Urutan warna urgensi kasus: rendah, sedang, tinggi, kritis. */
    var WARNA_URGENSI = [WARNA.hijau, WARNA.biru, WARNA.kuning, WARNA.merah];

    /** Urutan warna absensi: hadir, izin, sakit, alpa. */
    var WARNA_ABSENSI = [WARNA.hijau, WARNA.kuning, WARNA.biru, WARNA.merah];

    window.WARNA_GRAFIK = WARNA;
    window.WARNA_STATUS = WARNA_STATUS;
    window.WARNA_URGENSI = WARNA_URGENSI;
    window.WARNA_ABSENSI = WARNA_ABSENSI;

    /**
     * Panggil cb(Chart) begitu Chart.js siap. Bila dalam ~4 detik belum
     * termuat (mis. berkas vendor hilang), catat galat ke konsol.
     */
    function grafikSiap(cb, percobaan) {
        percobaan = percobaan || 0;
        if (typeof window.Chart !== 'undefined') {
            var Chart = window.Chart;
            Chart.defaults.font.family =
                "'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', sans-serif";
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#475569';
            Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(2,32,71,.92)';
            Chart.defaults.plugins.tooltip.padding = 10;
            Chart.defaults.plugins.tooltip.cornerRadius = 8;
            Chart.defaults.plugins.tooltip.titleFont = { weight: '600' };
            try {
                cb(Chart);
            } catch (galat) {
                console.error('Gagal menyiapkan grafik:', galat);
            }
            return;
        }
        if (percobaan > 40) {
            console.error(
                'Chart.js gagal dimuat. Pastikan berkas assets/js/vendor/chart.umd.min.js ada.'
            );
            return;
        }
        window.setTimeout(function () {
            grafikSiap(cb, percobaan + 1);
        }, 100);
    }

    window.grafikSiap = grafikSiap;

    /**
     * Ganti canvas dengan pesan "belum ada data". true bila canvas ada.
     */
    window.grafikKosong = function (id, pesan) {
        var canvas = document.getElementById(id);
        if (!canvas || !canvas.parentNode) {
            return false;
        }
        var kotak = document.createElement('div');
        kotak.className = 'empty';
        kotak.innerHTML =
            '<p class="empty-teks">' + (pesan || 'Belum ada data untuk ditampilkan.') + '</p>';
        canvas.parentNode.replaceChild(kotak, canvas);
        return true;
    };

    /**
     * true bila sekurangnya satu nilai > 0 (untuk menyembunyikan grafik hampa).
     */
    window.grafikAdaData = function (daftar) {
        var nilai = Array.isArray(daftar)
            ? daftar
            : Object.values(daftar || {});
        return nilai.some(function (v) {
            return Number(v) > 0;
        });
    };
})(window);
