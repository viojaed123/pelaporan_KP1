(function () {
    'use strict';

    var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)');
    var adegan = document.querySelector('[data-adegan]');
    var panggung = document.querySelector('[data-panggung]');

    if (adegan && panggung && !kurangiGerak.matches) {
        var bisaTunjuk = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        if (bisaTunjuk) {
            var rx = 0;
            var ry = 0;
            var tx = 0;
            var ty = 0;
            var bingkai = 0;

            var terapkan = function () {
                rx += (tx - rx) * 0.12;
                ry += (ty - ry) * 0.12;

                panggung.style.setProperty('--rx', rx.toFixed(3) + 'deg');
                panggung.style.setProperty('--ry', ry.toFixed(3) + 'deg');

                if (Math.abs(tx - rx) > 0.02 || Math.abs(ty - ry) > 0.02) {
                    bingkai = window.requestAnimationFrame(terapkan);
                } else {
                    bingkai = 0;
                }
            };

            var mulai = function () {
                if (bingkai === 0) {
                    bingkai = window.requestAnimationFrame(terapkan);
                }
            };

            adegan.addEventListener('pointermove', function (e) {
                var kotak = adegan.getBoundingClientRect();
                var x = (e.clientX - kotak.left) / kotak.width - 0.5;
                var y = (e.clientY - kotak.top) / kotak.height - 0.5;

                tx = Math.max(-1, Math.min(1, y * 2)) * -7;
                ty = Math.max(-1, Math.min(1, x * 2)) * 9;

                adegan.classList.add('sedang-menunjuk');
                mulai();
            });

            adegan.addEventListener('pointerleave', function () {
                tx = 0;
                ty = 0;
                adegan.classList.remove('sedang-menunjuk');
                mulai();
            });
        }

        var minta = false;
        var geser = function () {
            minta = false;

            var kotak = adegan.getBoundingClientRect();
            if (kotak.bottom < 0 || kotak.top > window.innerHeight) {
                adegan.style.setProperty('--gulir', '0px');
                return;
            }

            var nilai = Math.min(54, window.scrollY * 0.1);

            adegan.style.setProperty('--gulir', nilai.toFixed(1) + 'px');
        };

        window.addEventListener('scroll', function () {
            if (minta) {
                return;
            }
            minta = true;
            window.requestAnimationFrame(geser);
        }, { passive: true });

        geser();
    }

    if (kurangiGerak.matches || !('IntersectionObserver' in window)) {
        return;
    }

    var kandidat = document.querySelectorAll('.bagian-judul, .sorotan, .fitur, .langkah, .papan-plang .pil');
    if (kandidat.length === 0) {
        return;
    }

    var pengamat = new IntersectionObserver(function (masukan) {
        masukan.forEach(function (catatan) {
            if (!catatan.isIntersecting) {
                return;
            }
            catatan.target.classList.add('tampil');
            pengamat.unobserve(catatan.target);

            var jeda = parseInt(catatan.target.style.getPropertyValue('--jeda'), 10) || 0;
            window.setTimeout(function () {
                catatan.target.classList.remove('gerak-siap', 'tampil');
                catatan.target.style.removeProperty('--jeda');
            }, 760 + jeda);
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -36px 0px' });

    kandidat.forEach(function (el, i) {
        el.classList.add('gerak-siap');
        el.style.setProperty('--jeda', (i % 4) * 55 + 'ms');
        pengamat.observe(el);
    });
})();
