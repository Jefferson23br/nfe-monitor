(function () {
    'use strict';

    document.querySelectorAll('[data-copy-chave]').forEach(function (el) {
        el.addEventListener('click', function () {
            var chave = el.getAttribute('data-copy-chave') || el.textContent.trim();
            if (!chave || !navigator.clipboard) {
                return;
            }
            navigator.clipboard.writeText(chave).then(function () {
                var original = el.getAttribute('title') || '';
                el.setAttribute('title', 'Chave copiada!');
                el.style.color = 'var(--dash-success, #059669)';
                setTimeout(function () {
                    el.setAttribute('title', original || 'Clique para copiar a chave');
                    el.style.color = '';
                }, 1500);
            });
        });
    });
})();
