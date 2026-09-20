// ia-rapida.js — ações rápidas de IA (SPEC §8): melhorar texto, tom formal/amigável, resumir histórico, sugerir resposta.
// Cada clique é uma chamada ao servidor (POST /api/ia/rapida); o resultado aparece num painel para aceitar ou descartar.
// Nada é gravado aqui: "Usar este texto" só preenche o campo, e quem grava é o formulário, como sempre.

import { csrf, toast } from './ui.js';

async function pedir(payload) {
    const resposta = await fetch('/api/ia/rapida', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), Accept: 'application/json' },
        body: JSON.stringify(payload),
    });
    let dados = null;
    try { dados = await resposta.json(); } catch (_) { /* resposta sem JSON */ }
    if (!resposta.ok || !dados?.ok) {
        throw new Error(dados?.erro || 'Não foi possível concluir a ação. Tente novamente.');
    }
    return dados.texto;
}

function alternarOcupado(barra, ocupado) {
    barra.querySelectorAll('[data-ia-acao]').forEach((b) => { b.disabled = ocupado; });
    barra.setAttribute('aria-busy', String(ocupado));
}

function mostrarPainel(barra, texto, { pronto }) {
    const painel = barra.querySelector('[data-ia-resultado]');
    painel.hidden = false;
    painel.querySelector('[data-ia-texto]').textContent = texto;
    painel.querySelector('[data-ia-decisao]').hidden = !pronto;
    barra.dataset.iaSugestao = pronto ? texto : '';
}

function esconderPainel(barra) {
    const painel = barra.querySelector('[data-ia-resultado]');
    if (painel) painel.hidden = true;
    barra.dataset.iaSugestao = '';
}

async function executar(botao) {
    const barra = botao.closest('[data-ia-barra]');
    if (!barra) return;
    const acao = botao.dataset.iaAcao;
    barra.dataset.iaAssunto = botao.dataset.iaAssunto ?? '';

    let payload;
    if (barra.dataset.iaEntidade) {
        payload = { acao, entidade: barra.dataset.iaEntidade, id: Number(barra.dataset.iaId) };
    } else {
        const campo = document.getElementById(barra.dataset.iaDestino);
        const texto = campo?.value.trim() ?? '';
        if (!texto) {
            toast('warning', 'Escreva algo no campo antes de usar a IA.');
            campo?.focus();
            return;
        }
        payload = { acao, texto };
    }

    alternarOcupado(barra, true);
    mostrarPainel(barra, 'Gerando sugestão…', { pronto: false });
    try {
        mostrarPainel(barra, await pedir(payload), { pronto: true });
    } catch (erro) {
        esconderPainel(barra);
        toast('error', 'Ação rápida de IA', erro.message);
    } finally {
        alternarOcupado(barra, false);
    }
}

function aceitar(barra) {
    const sugestao = barra.dataset.iaSugestao ?? '';
    const destino = document.getElementById(barra.dataset.iaDestino);
    if (!sugestao || !destino) return;

    if (barra.dataset.iaModo === 'acrescentar' && destino.value.trim() !== '') {
        destino.value = `${destino.value.trimEnd()}\n\n${sugestao}`;
    } else {
        destino.value = sugestao;
    }
    destino.dispatchEvent(new Event('input', { bubbles: true }));

    // Timeline: se o assunto da nova atividade estiver vazio, o resumo ganha um assunto.
    if (barra.dataset.iaAssunto) {
        const assunto = document.getElementById('atv-assunto');
        if (assunto && assunto.value.trim() === '') assunto.value = barra.dataset.iaAssunto;
    }
    esconderPainel(barra);
    destino.scrollIntoView({ block: 'center', behavior: 'smooth' });
    destino.focus();
}

document.addEventListener('click', (ev) => {
    const alvo = ev.target instanceof Element ? ev.target : null;
    if (!alvo) return;

    const botao = alvo.closest('[data-ia-acao]');
    if (botao) {
        executar(botao);
        return;
    }
    const barra = alvo.closest('[data-ia-barra]');
    if (!barra) return;
    if (alvo.closest('[data-ia-aceitar]')) aceitar(barra);
    else if (alvo.closest('[data-ia-descartar]')) esconderPainel(barra);
});
