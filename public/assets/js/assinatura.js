// assinatura.js — janelas de assinatura/comentário do contrato e quadro de assinatura desenhada (mouse, caneta ou dedo).
// O desenho sai como PNG (data URL) no campo [data-pad-valor]; o servidor valida o arquivo e grava junto com o aceite.

for (const dialogo of document.querySelectorAll('dialog')) {
    if (dialogo.hasAttribute('data-abrir-ao-carregar')) dialogo.showModal();
}

document.addEventListener('click', (ev) => {
    const alvo = ev.target instanceof Element ? ev.target : null;
    const abrir = alvo?.closest('[data-abrir-dialogo]');
    if (abrir) {
        document.getElementById(abrir.getAttribute('data-abrir-dialogo'))?.showModal();
    } else if (alvo?.closest('[data-fechar-dialogo]')) {
        alvo.closest('dialog')?.close();
    }
});

function iniciarQuadro(canvas) {
    const form = canvas.closest('form');
    const valor = form.querySelector('[data-pad-valor]');
    const erro = form.querySelector('[data-pad-erro]');
    const ctx = canvas.getContext('2d');
    let desenhando = false;
    let temTraco = false;

    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';

    function ponto(ev) {
        const r = canvas.getBoundingClientRect();
        return [(ev.clientX - r.left) * (canvas.width / r.width), (ev.clientY - r.top) * (canvas.height / r.height)];
    }

    canvas.addEventListener('pointerdown', (ev) => {
        desenhando = true;
        canvas.setPointerCapture(ev.pointerId);
        const [x, y] = ponto(ev);
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineTo(x + 0.01, y + 0.01); // ponto simples também conta
        ctx.stroke();
        temTraco = true;
        if (erro) erro.hidden = true;
    });
    canvas.addEventListener('pointermove', (ev) => {
        if (!desenhando) return;
        const [x, y] = ponto(ev);
        ctx.lineTo(x, y);
        ctx.stroke();
    });
    const parar = () => { desenhando = false; };
    canvas.addEventListener('pointerup', parar);
    canvas.addEventListener('pointercancel', parar);

    function limpar() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        temTraco = false;
        valor.value = '';
    }
    form.querySelector('[data-pad-limpar]')?.addEventListener('click', limpar);

    form.addEventListener('submit', (ev) => {
        if (!temTraco && canvas.hasAttribute('data-pad-opcional')) return;
        if (!temTraco) {
            ev.preventDefault();
            if (erro) erro.hidden = false;
            canvas.focus();
            return;
        }
        valor.value = canvas.toDataURL('image/png');
    });
}

for (const canvas of document.querySelectorAll('[data-pad]')) iniciarQuadro(canvas);
