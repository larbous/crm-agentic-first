// formulario-publico.js — página pública do formulário de captação (também dentro de iframe).
// Evita duplo envio e, no modo iframe, avisa a página-mãe da altura e de um redirecionamento (ver embed-formulario.js).

const embutido = window.parent !== window;
const avisar = (dados) => {
    if (embutido) window.parent.postMessage({ larbous: 'formulario', ...dados }, '*');
};

document.querySelectorAll('[data-formulario-publico]').forEach((form) => {
    form.addEventListener('submit', () => {
        const botao = form.querySelector('[data-formulario-enviar]');
        // Desabilita depois do envio começar (desabilitar antes cancelaria o submit).
        setTimeout(() => { if (botao) botao.disabled = true; }, 0);
    });
});

const enviarAltura = () => avisar({ tipo: 'altura', altura: Math.ceil(document.documentElement.getBoundingClientRect().height) });

if (embutido) {
    if ('ResizeObserver' in window) new ResizeObserver(enviarAltura).observe(document.body);
    window.addEventListener('load', enviarAltura);
    enviarAltura();
}

const resultado = document.querySelector('[data-redirect]');
if (resultado?.dataset.redirect && embutido) {
    // O iframe não navega a página-mãe: pede para o script de incorporação fazê-lo.
    setTimeout(() => avisar({ tipo: 'redirecionar', url: resultado.dataset.redirect }), 1200);
}
