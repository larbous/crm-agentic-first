// chat.js — chat do CRM: painel lateral recolhível (Ctrl+K) e caixa central da página Início.
// O servidor decide tudo (comandos, IA, validação, execução); aqui só enviamos texto e cliques de botões
// e inserimos o HTML das respostas, que o servidor já entrega escapado.

import { csrf, toast } from './ui.js';

const CHAVE = 'chatAberto';
const painel = document.getElementById('painel-chat');

// ---- Painel lateral ------------------------------------------------------------------

function definirAberto(aberto, { salvar = true } = {}) {
    if (!painel) return;
    painel.hidden = !aberto;
    document.querySelectorAll('[data-alternar-chat][aria-controls]').forEach((b) => {
        b.setAttribute('aria-expanded', String(aberto));
    });
    if (aberto) {
        carregarHistorico(painel.querySelector('[data-chat]'));
        painel.querySelector('[data-chat-entrada]')?.focus();
    }
    if (salvar) {
        try { localStorage.setItem(CHAVE, aberto ? '1' : '0'); } catch (_) { /* sem armazenamento */ }
    }
}

/** Sem painel (página Início), o atalho leva ao chat central. */
function focarChatDaPagina() {
    const campo = document.querySelector('[data-chat][data-modo="pagina"] [data-chat-entrada]');
    campo?.focus();
    campo?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

function alternarChat() {
    if (painel) definirAberto(painel.hidden);
    else focarChatDaPagina();
}

document.addEventListener('click', (ev) => {
    if (ev.target instanceof Element && ev.target.closest('[data-alternar-chat]')) alternarChat();
});

document.addEventListener('keydown', (ev) => {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
        ev.preventDefault();
        alternarChat();
    } else if (painel && ev.key === 'Escape' && !painel.hidden && window.matchMedia('(max-width: 1023.98px)').matches) {
        definirAberto(false);
    }
});

// ---- Mensagens -----------------------------------------------------------------------

function rolarParaOFim(log) {
    log.scrollTop = log.scrollHeight;
}

function removerVazio(log) {
    log.querySelector('.chat-vazio')?.remove();
}

/** Insere HTML de mensagem(ns) já renderizado pelo servidor. */
function anexarHtml(log, html) {
    removerVazio(log);
    const t = document.createElement('template');
    t.innerHTML = html;
    log.append(t.content);
    rolarParaOFim(log);
}

function anexarOperador(log, texto) {
    removerVazio(log);
    const msg = document.createElement('div');
    msg.className = 'chat-msg';
    msg.dataset.papel = 'operador';
    const balao = document.createElement('div');
    balao.className = 'chat-balao';
    balao.textContent = texto;
    msg.append(balao);
    log.append(msg);
    rolarParaOFim(log);
}

function anexarPensando(log) {
    const msg = document.createElement('div');
    msg.className = 'chat-msg chat-pensando';
    msg.dataset.papel = 'sistema';
    msg.innerHTML = '<div class="chat-balao" aria-label="Processando">…</div>';
    log.append(msg);
    rolarParaOFim(log);
    return msg;
}

function anexarErroLocal(log, texto) {
    const msg = document.createElement('div');
    msg.className = 'chat-msg';
    msg.dataset.papel = 'sistema';
    msg.dataset.erro = '';
    const balao = document.createElement('div');
    balao.className = 'chat-balao';
    balao.textContent = `✗ ${texto}`;
    msg.append(balao);
    log.append(msg);
    rolarParaOFim(log);
}

// ---- Atualização da tela após o chat alterar dados ------------------------------------

let formularioSujo = false;
document.addEventListener('input', (ev) => {
    if (ev.target instanceof Element && ev.target.closest('#conteudo form:not([data-chat-form])')) formularioSujo = true;
});

/**
 * Depois que o chat grava algo, recarrega a página para a tela refletir a alteração (timeline, listas, totais).
 * Se há um formulário editado na tela, não recarrega (evita perder o que foi digitado) e avisa.
 */
function atualizarTela() {
    if (formularioSujo) {
        toast('info', 'Alteração feita pelo chat', 'Salve ou descarte o formulário e atualize a página para ver.');
        return;
    }
    setTimeout(() => window.location.reload(), 700);
}

async function api(caminho, corpo) {
    const resp = await fetch(caminho, {
        method: corpo ? 'POST' : 'GET',
        headers: {
            Accept: 'application/json',
            ...(corpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() } : {}),
        },
        body: corpo ? JSON.stringify(corpo) : undefined,
    });
    let dados = null;
    try { dados = await resp.json(); } catch (_) { /* resposta sem JSON */ }
    if (!resp.ok || !dados?.ok) {
        throw new Error(dados?.erro ?? (resp.status === 419 ? 'Sessão expirada. Recarregue a página.' : 'Não foi possível falar com o servidor.'));
    }
    return dados;
}

async function carregarHistorico(caixa) {
    if (!caixa || caixa.dataset.carregado) return;
    caixa.dataset.carregado = '1';
    const log = caixa.querySelector('[data-chat-log]');
    try {
        const dados = await api('/api/chat/historico');
        if (dados.html) {
            anexarHtml(log, dados.html);
        } else {
            log.innerHTML = '<p class="chat-vazio">Escreva um comando (/ajuda) ou peça em linguagem natural.</p>';
        }
    } catch (e) {
        delete caixa.dataset.carregado;
        anexarErroLocal(log, e.message);
    }
}

async function enviar(caixa, texto) {
    const log = caixa.querySelector('[data-chat-log]');
    anexarOperador(log, texto);
    const pensando = anexarPensando(log);
    caixa.classList.add('chat-ocupado');
    try {
        const dados = await api('/api/chat', { mensagem: texto, tela: window.location.pathname });
        pensando.remove();
        anexarHtml(log, dados.resposta.html);
        if (dados.alterou) atualizarTela();
    } catch (e) {
        pensando.remove();
        anexarErroLocal(log, e.message);
    } finally {
        caixa.classList.remove('chat-ocupado');
    }
}

async function acionar(botao) {
    const caixa = botao.closest('[data-chat]');
    const msg = botao.closest('.chat-msg');
    if (!caixa || !msg) return;
    const log = caixa.querySelector('[data-chat-log]');
    const botoes = msg.querySelectorAll('[data-chat-acao]');
    botoes.forEach((b) => { b.disabled = true; });
    const opcao = botao.dataset.opcao;

    try {
        const dados = await api('/api/chat/acao', {
            mensagem_id: Number(botao.dataset.mensagem),
            acao: botao.dataset.chatAcao,
            opcao: opcao === undefined ? null : Number(opcao),
        });
        if (dados.atualizada) {
            const t = document.createElement('template');
            t.innerHTML = dados.atualizada.html;
            msg.replaceWith(t.content);
        }
        if (dados.resposta) anexarHtml(log, dados.resposta.html);
        if (dados.alterou) atualizarTela();
    } catch (e) {
        botoes.forEach((b) => { b.disabled = false; });
        toast('error', 'Não foi possível concluir', e.message);
    }
}

document.addEventListener('click', (ev) => {
    if (!(ev.target instanceof Element)) return;
    const botao = ev.target.closest('[data-chat-acao]');
    if (botao) {
        acionar(botao);
        return;
    }
    const ajuda = ev.target.closest('[data-chat-ajuda]');
    if (ajuda) {
        const caixa = ajuda.closest('[data-chat]');
        if (caixa) enviar(caixa, '/ajuda');
    }
});

// ---- Formulário ----------------------------------------------------------------------

function ajustarAltura(campo) {
    campo.style.height = 'auto';
    campo.style.height = `${Math.min(campo.scrollHeight, 160)}px`;
}

document.querySelectorAll('[data-chat]').forEach((caixa) => {
    const form = caixa.querySelector('[data-chat-form]');
    const campo = caixa.querySelector('[data-chat-entrada]');
    if (!form || !campo) return;

    const submeter = () => {
        const texto = campo.value.trim();
        if (texto === '' || caixa.classList.contains('chat-ocupado')) return;
        campo.value = '';
        ajustarAltura(campo);
        enviar(caixa, texto);
    };

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        submeter();
    });
    campo.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
            ev.preventDefault();
            submeter();
        }
    });
    campo.addEventListener('input', () => ajustarAltura(campo));

    // A caixa da página carrega o histórico na hora; a do painel, quando ele abre.
    if (caixa.dataset.modo === 'pagina') carregarHistorico(caixa);
});

// ---- Estado inicial do painel ---------------------------------------------------------

if (painel) {
    let inicial = false;
    try { inicial = localStorage.getItem(CHAVE) === '1'; } catch (_) { /* sem armazenamento */ }
    // Páginas como o Início pedem o painel aberto de saída (só no desktop; a preferência salva não muda).
    if (painel.hasAttribute('data-aberto-padrao')) inicial = true;
    definirAberto(inicial && window.matchMedia('(min-width: 1024px)').matches, { salvar: false });
}
