// chat.js — painel lateral de chat (recolhível, atalho Ctrl+K).
// Fase 1: apenas abrir/fechar e lembrar o estado. O envio de mensagens entra na Fase 4.

const CHAVE = 'chatAberto';
const painel = document.getElementById('painel-chat');

function definirAberto(aberto, { salvar = true } = {}) {
    if (!painel) return;
    painel.hidden = !aberto;
    document.querySelectorAll('[data-alternar-chat][aria-controls]').forEach((b) => {
        b.setAttribute('aria-expanded', String(aberto));
    });
    if (aberto) {
        painel.querySelector('input:not([disabled]), textarea:not([disabled])')?.focus();
    }
    if (salvar) {
        try { localStorage.setItem(CHAVE, aberto ? '1' : '0'); } catch (_) { /* sem armazenamento */ }
    }
}

if (painel) {
    let inicial = false;
    try { inicial = localStorage.getItem(CHAVE) === '1' && window.matchMedia('(min-width: 1024px)').matches; } catch (_) { /* sem armazenamento */ }
    definirAberto(inicial, { salvar: false });

    document.addEventListener('click', (ev) => {
        if (ev.target instanceof Element && ev.target.closest('[data-alternar-chat]')) {
            definirAberto(painel.hidden);
        }
    });

    document.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
            ev.preventDefault();
            definirAberto(painel.hidden);
        } else if (ev.key === 'Escape' && !painel.hidden && window.matchMedia('(max-width: 1023.98px)').matches) {
            definirAberto(false);
        }
    });
}
