(function () {
    'use strict';

    var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)');
    var bisaTunjuk = window.matchMedia('(hover: hover) and (pointer: fine)');

    if (kurangiGerak.matches || !bisaTunjuk.matches) {
        return;
    }

    var kartu = document.querySelectorAll('[data-tilt]');

    kartu.forEach(function (el) {
        var atur = function (e) {
            var kotak = el.getBoundingClientRect();
            var x = (e.clientX - kotak.left) / kotak.width;
            var y = (e.clientY - kotak.top) / kotak.height;

            x = Math.max(0, Math.min(1, x));
            y = Math.max(0, Math.min(1, y));

            el.style.setProperty('--tilt-x', ((0.5 - y) * 7).toFixed(2) + 'deg');
            el.style.setProperty('--tilt-y', ((x - 0.5) * 8).toFixed(2) + 'deg');
            el.style.setProperty('--sorot-x', (x * 100).toFixed(1) + '%');
            el.style.setProperty('--sorot-y', (y * 100).toFixed(1) + '%');
        };

        var masuk = function () {
            el.classList.add('menunjuk');
        };

        var lepas = function () {
            el.classList.remove('menunjuk');
            el.style.setProperty('--tilt-x', '0deg');
            el.style.setProperty('--tilt-y', '0deg');
            el.style.setProperty('--sorot-x', '50%');
            el.style.setProperty('--sorot-y', '50%');
        };

        el.addEventListener('pointerenter', masuk);
        el.addEventListener('pointermove', atur);
        el.addEventListener('pointerleave', lepas);
        el.addEventListener('pointercancel', lepas);
        el.addEventListener('blur', lepas);
    });
})();
