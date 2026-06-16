document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-toggle-password'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        btn.textContent = show ? 'Ocultar' : 'Mostrar';
    });
});

document.querySelectorAll('form[data-confirm-password]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var a = form.querySelector('[name="senha"]');
        var b = form.querySelector('[name="senha2"]');
        if (!a || !b) return;
        if (a.value !== b.value) {
            e.preventDefault();
            alert('As senhas não coincidem.');
        }
    });
});
