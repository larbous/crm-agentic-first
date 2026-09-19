// kanban.js — arrastar negócios entre etapas e modal de ganho/perda.
// Serve o kanban (/negocios/kanban) e o botão "Mover etapa" do detalhe do negócio.
// Toda mudança vai para POST /api/negocios/{id}/mover, que passa pelo ActionExecutor no servidor.

import { csrf, toast } from './ui.js';

const modal = document.getElementById('modal-etapa');
const form = document.getElementById('form-etapa');
let contexto = null;

function textoMoeda(centavos) {
    return (Number(centavos || 0) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function moverNoServidor(negocioId, payload) {
    let resposta;
    try {
        resposta = await fetch(`/api/negocios/${negocioId}/mover`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(payload),
        });
    } catch (_) {
        return { ok: false, mensagem: 'Sem conexão com o servidor. Tente novamente.' };
    }
    let dados = {};
    try { dados = await resposta.json(); } catch (_) { /* resposta sem JSON */ }
    const mensagem = dados.erros && Object.keys(dados.erros).length
        ? Object.values(dados.erros).join(' ')
        : (dados.mensagem || 'Não foi possível mover o negócio.');
    return { ok: resposta.ok && dados.ok === true, mensagem };
}

/** Etapa aberta: move direto. Ganho/perdido: pede valor fechado ou motivo antes. */
function solicitarMovimento({ negocioId, etapaId, etapaNome, tipo, valorEstimado, titulo }) {
    if (tipo === 'aberta' || !modal || !form) {
        return moverNoServidor(negocioId, { etapa_id: etapaId }).then((r) => {
            if (r.ok) {
                location.reload();
            } else {
                toast('error', 'Não foi possível mover', r.mensagem);
            }
            return r.ok;
        });
    }

    contexto = { negocioId, etapaId, tipo };
    form.reset();
    form.elements.negocio_id.value = negocioId;
    form.elements.etapa_id.value = etapaId;
    form.querySelector('[data-bloco="ganho"]').hidden = tipo !== 'ganho';
    form.querySelector('[data-bloco="perdido"]').hidden = tipo !== 'perdido';
    if (tipo === 'ganho') {
        form.elements.valor_fechado.value = valorEstimado ? textoMoeda(valorEstimado) : '';
    }
    const erro = form.querySelector('[data-etapa-erro]');
    erro.hidden = true;
    erro.textContent = '';
    form.querySelector('[data-etapa-resumo]').textContent =
        `Mover “${titulo}” para ${etapaNome} — ${tipo === 'ganho' ? 'confirme o valor fechado.' : 'informe o motivo da perda.'}`;
    modal.showModal();
    (tipo === 'ganho' ? form.elements.valor_fechado : form.elements.motivo_perda_id)?.focus();
    return Promise.resolve(false);
}

if (modal && form) {
    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (!contexto) return;
        const payload = { etapa_id: contexto.etapaId };
        if (contexto.tipo === 'ganho') {
            payload.valor_fechado = form.elements.valor_fechado.value;
        } else {
            payload.motivo_perda_id = form.elements.motivo_perda_id.value;
            payload.detalhe_perda = form.elements.detalhe_perda.value;
        }
        const botao = modal.querySelector('[data-etapa-confirmar]');
        botao.disabled = true;
        const r = await moverNoServidor(contexto.negocioId, payload);
        botao.disabled = false;
        if (r.ok) {
            modal.close();
            location.reload();
        } else {
            const erro = form.querySelector('[data-etapa-erro]');
            erro.textContent = r.mensagem;
            erro.hidden = false;
        }
    });
    modal.querySelector('[data-etapa-cancelar]')?.addEventListener('click', () => modal.close());
    modal.addEventListener('close', () => { contexto = null; });
}

// ---- Kanban: arrastar e soltar ------------------------------------------------------

const estado = { card: null };

document.addEventListener('dragstart', (ev) => {
    const card = ev.target instanceof Element ? ev.target.closest('.kanban-card') : null;
    if (!card) return;
    estado.card = card;
    ev.dataTransfer.effectAllowed = 'move';
    ev.dataTransfer.setData('text/plain', card.dataset.negocioId);
    requestAnimationFrame(() => card.classList.add('kanban-arrastando'));
});

document.addEventListener('dragover', (ev) => {
    const lista = ev.target instanceof Element ? ev.target.closest('[data-dropzone]') : null;
    if (lista && estado.card) {
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'move';
        lista.classList.add('kanban-alvo');
    }
});

document.addEventListener('dragleave', (ev) => {
    const lista = ev.target instanceof Element ? ev.target.closest('[data-dropzone]') : null;
    if (lista && !lista.contains(ev.relatedTarget)) {
        lista.classList.remove('kanban-alvo');
    }
});

document.addEventListener('drop', (ev) => {
    const lista = ev.target instanceof Element ? ev.target.closest('[data-dropzone]') : null;
    const card = estado.card;
    if (!lista || !card) return;
    ev.preventDefault();
    lista.classList.remove('kanban-alvo');

    const destino = lista.closest('.kanban-coluna');
    const origem = card.closest('.kanban-coluna');
    if (!destino || destino === origem) return;

    solicitarMovimento({
        negocioId: card.dataset.negocioId,
        etapaId: destino.dataset.etapaId,
        etapaNome: destino.dataset.nome,
        tipo: destino.dataset.tipo,
        valorEstimado: card.dataset.valorEstimado,
        titulo: card.dataset.titulo,
    });
});

document.addEventListener('dragend', () => {
    document.querySelectorAll('.kanban-arrastando').forEach((c) => c.classList.remove('kanban-arrastando'));
    document.querySelectorAll('.kanban-alvo').forEach((l) => l.classList.remove('kanban-alvo'));
    estado.card = null;
});

// ---- Detalhe do negócio: botão "Mover etapa" ----------------------------------------

document.addEventListener('click', (ev) => {
    const botao = ev.target instanceof Element ? ev.target.closest('[data-mover-confirmar]') : null;
    const raiz = botao?.closest('[data-mover-negocio]');
    if (!botao || !raiz) return;

    const select = raiz.querySelector('[data-mover-select]');
    const opcao = select?.selectedOptions[0];
    if (!opcao) return;
    if (opcao.defaultSelected) {
        toast('info', 'O negócio já está nesta etapa');
        return;
    }
    solicitarMovimento({
        negocioId: raiz.dataset.negocioId,
        etapaId: opcao.value,
        etapaNome: opcao.dataset.nome,
        tipo: opcao.dataset.tipo,
        valorEstimado: raiz.dataset.valorEstimado,
        titulo: document.querySelector('h1')?.textContent?.trim() ?? 'negócio',
    });
});
