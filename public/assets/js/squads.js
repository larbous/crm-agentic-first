// squads.js — squads de IA: andamento de uma execução (polling) e botão "Executar agora" dos squads sem registro.
import { csrf, toast } from './ui.js';

const INTERVALO_MS = 3000;

/** GET/POST JSON; lança Error com a mensagem do servidor em caso de falha. */
async function chamar(url, corpo) {
    const resp = await fetch(url, {
        method: corpo ? 'POST' : 'GET',
        headers: {
            Accept: 'application/json',
            ...(corpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() } : {}),
        },
        body: corpo ? JSON.stringify(corpo) : undefined,
    });
    let dados = null;
    try { dados = await resp.json(); } catch (_) { /* resposta sem JSON */ }
    if (!resp.ok || dados?.ok === false) {
        throw new Error(dados?.erro ?? (resp.status === 419 ? 'Sessão expirada. Recarregue a página.' : 'Não foi possível falar com o servidor.'));
    }
    return dados;
}

// ---- Andamento da execução: atualiza o painel enquanto o squad não terminar -----------------

const painel = document.querySelector('[data-squad-progresso]');
if (painel && painel.dataset.aberta === '1') {
    let statusAtual = null;

    const atualizar = async () => {
        try {
            const r = await chamar(painel.dataset.url);
            painel.innerHTML = r.html; // HTML montado e escapado no servidor
            // Ao mudar de estado (ex.: cancelar/retomar pelos botões da página), recarrega para refletir os botões do cabeçalho.
            if (statusAtual !== null && statusAtual !== r.status && r.terminou) {
                location.reload();
                return;
            }
            statusAtual = r.status;
            if (!r.terminou) setTimeout(atualizar, INTERVALO_MS);
        } catch (_) {
            setTimeout(atualizar, INTERVALO_MS * 3); // falha passageira de rede: tenta de novo mais devagar
        }
    };
    setTimeout(atualizar, INTERVALO_MS);
}

// ---- "Executar agora" no editor de squads sem registro --------------------------------------

const executar = document.querySelector('[data-squad-executar]');
executar?.querySelector('[data-squad-executar-botao]')?.addEventListener('click', async (ev) => {
    const botao = ev.currentTarget;
    botao.disabled = true;
    try {
        const r = await chamar(executar.dataset.url, {});
        location.href = r.url;
    } catch (e) {
        toast('error', 'Não foi possível executar o squad', e.message);
        botao.disabled = false;
    }
});
