// ui.js — comportamentos globais da interface: tema, sidebar, modais, toasts e atalhos.

/** Token CSRF para chamadas fetch (header X-CSRF-Token). */
export function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/** Exibe um toast do Basecoat. categoria: success | error | info | warning. */
export function toast(categoria, titulo, descricao = '') {
    const toaster = document.getElementById('toaster');
    if (toaster && typeof toaster.toast === 'function') {
        toaster.toast({ category: categoria, title: titulo, description: descricao });
    }
}

function alternarTema() {
    const escuro = !document.documentElement.classList.contains('dark');
    document.documentElement.classList.toggle('dark', escuro);
    try { localStorage.setItem('themeMode', escuro ? 'dark' : 'light'); } catch (_) { /* sem armazenamento */ }
    document.dispatchEvent(new CustomEvent('basecoat:themechange', { detail: { mode: escuro ? 'dark' : 'light' } }));
}

function ehCampoDeTexto(el) {
    return el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
}

document.addEventListener('click', (ev) => {
    const alvo = ev.target instanceof Element ? ev.target : null;
    if (!alvo) return;

    if (alvo.closest('[data-alternar-tema]')) {
        alternarTema();
        return;
    }
    if (alvo.closest('[data-alternar-sidebar]')) {
        document.getElementById('sidebar')?.toggle?.();
        return;
    }
    const abrir = alvo.closest('[data-abrir-modal]');
    if (abrir) {
        document.getElementById(abrir.getAttribute('data-abrir-modal'))?.showModal();
        return;
    }
    const enviar = alvo.closest('[data-enviar-form]');
    if (enviar) {
        document.getElementById(enviar.getAttribute('data-enviar-form'))?.requestSubmit();
        return;
    }
    const demo = alvo.closest('[data-toast-demo]');
    if (demo) {
        toast(demo.getAttribute('data-toast-demo'), 'Título do toast', 'Descrição da notificação.');
    }
});

// Atalho "/" foca a busca global (quando habilitada).
document.addEventListener('keydown', (ev) => {
    if (ev.key === '/' && !ev.ctrlKey && !ev.metaKey && !ev.altKey && !ehCampoDeTexto(document.activeElement)) {
        const busca = document.getElementById('busca-global');
        if (busca && !busca.disabled) {
            ev.preventDefault();
            busca.focus();
        }
    }
});

// Mensagens flash da sessão viram toasts.
function mostrarFlash() {
    const bruto = document.getElementById('flash-dados')?.textContent;
    if (!bruto) return;
    try {
        for (const m of JSON.parse(bruto)) {
            toast(m.tipo, m.mensagem);
        }
    } catch (_) { /* JSON inválido: ignora */ }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(mostrarFlash, 0));
} else {
    setTimeout(mostrarFlash, 0);
}
