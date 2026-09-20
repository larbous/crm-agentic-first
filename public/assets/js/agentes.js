// agentes.js — agentes de IA: validação do editor JSON, teste em simulação, execução pelos botões do detalhe
// e seleção em lote nas ações pendentes.
import { csrf, toast } from './ui.js';

/** POST/GET JSON. Devolve o corpo decodificado; lança Error com a mensagem do servidor em caso de falha. */
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

// ---- Editor: validar a definição ----------------------------------------------------------

const editor = document.querySelector('[data-agente-editor]');
if (editor) {
    const json = editor.querySelector('[data-agente-json]');
    const saida = editor.querySelector('[data-agente-validacao]');

    editor.querySelector('[data-agente-validar]')?.addEventListener('click', async () => {
        saida.replaceChildren();
        try {
            const r = await chamar(json.dataset.validarUrl, { definicao: json.value });
            if (r.valida) {
                saida.textContent = '✓ Definição válida.';
                saida.className = 'text-sm text-[var(--success)]';
                return;
            }
            const lista = document.createElement('ul');
            lista.className = 'text-destructive list-inside list-disc text-sm';
            for (const erro of r.erros) {
                const li = document.createElement('li');
                li.textContent = erro;
                lista.append(li);
            }
            saida.className = 'text-sm';
            saida.append(lista);
        } catch (e) {
            saida.className = 'text-destructive text-sm';
            saida.textContent = e.message;
        }
    });
}

// ---- Teste em simulação (editor) ----------------------------------------------------------

const teste = document.querySelector('[data-agente-teste]');
if (teste) {
    const busca = teste.querySelector('[data-teste-busca]');
    const lista = teste.querySelector('[data-teste-resultados]');
    const escolhido = teste.querySelector('[data-teste-escolhido]');
    const botao = teste.querySelector('[data-teste-enviar]');
    const saida = teste.querySelector('[data-teste-saida]');
    let registroId = null;
    let temporizador = null;

    const fechar = () => { if (lista) lista.hidden = true; };

    async function pesquisar(termo) {
        try {
            const r = await chamar(`${teste.dataset.registrosUrl}?q=${encodeURIComponent(termo)}`);
            lista.replaceChildren();
            for (const item of r.itens) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'busca-item';
                b.setAttribute('role', 'option');
                b.textContent = item.titulo;
                b.addEventListener('click', () => {
                    registroId = item.id;
                    escolhido.textContent = `Registro escolhido: ${item.titulo}`;
                    busca.value = item.titulo;
                    fechar();
                });
                lista.append(b);
            }
            if (r.itens.length === 0) {
                const vazio = document.createElement('p');
                vazio.className = 'text-muted-foreground p-3 text-center text-sm';
                vazio.textContent = 'Nada encontrado.';
                lista.append(vazio);
            }
            lista.hidden = false;
        } catch (_) { /* mantém a lista anterior */ }
    }

    busca?.addEventListener('input', () => {
        registroId = null;
        escolhido.textContent = 'Nenhum registro escolhido.';
        clearTimeout(temporizador);
        temporizador = setTimeout(() => pesquisar(busca.value.trim()), 250);
    });
    busca?.addEventListener('focus', () => { if (!busca.value) pesquisar(''); });
    document.addEventListener('click', (ev) => { if (!teste.contains(ev.target)) fechar(); });

    botao.addEventListener('click', async () => {
        if (teste.dataset.precisaRegistro === '1' && registroId === null) {
            toast('warning', 'Escolha um registro', 'O agente precisa de um registro para trabalhar.');
            return;
        }
        botao.disabled = true;
        saida.innerHTML = '<p class="text-muted-foreground text-sm">Executando a simulação… isso pode levar alguns segundos.</p>';
        try {
            const r = await chamar(teste.dataset.executarUrl, {
                registro_id: registroId,
                entrada: teste.querySelector('[data-teste-entrada]')?.value ?? '',
                simulacao: true,
            });
            saida.innerHTML = r.html; // HTML montado e escapado no servidor
        } catch (e) {
            saida.textContent = e.message;
            saida.className = 'text-destructive text-sm';
        } finally {
            botao.disabled = false;
        }
    });
}

// ---- Execução pelos botões do detalhe do registro ------------------------------------------

const dialogo = document.getElementById('modal-agente');
if (dialogo) {
    const form = dialogo.querySelector('[data-agente-form]');
    const saida = dialogo.querySelector('[data-agente-saida]');
    const rodape = dialogo.querySelector('footer');
    const enviar = dialogo.querySelector('[data-agente-enviar]');
    let atual = null;

    const restaurar = () => {
        form.hidden = false;
        rodape.hidden = false;
        saida.replaceChildren();
    };

    document.addEventListener('click', (ev) => {
        const item = ev.target instanceof Element ? ev.target.closest('[data-agente-executar]') : null;
        if (!item) return;
        atual = { url: item.dataset.executarUrl, registro: Number(item.dataset.registro) };
        // Squads vão para a fila do worker e não têm simulação.
        dialogo.querySelector('[data-agente-simulacao]').hidden = item.dataset.semSimulacao === '1';
        dialogo.querySelector('h2').textContent = item.dataset.agenteNome;
        dialogo.querySelector('[data-agente-descricao-texto]').textContent = item.dataset.agenteDescricao ?? '';
        form.reset();
        restaurar();
        dialogo.showModal();
    });

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (!atual) return;
        const simulacao = form.elements.simulacao?.checked === true && !dialogo.querySelector('[data-agente-simulacao]').hidden;
        enviar.disabled = true;
        const rotulo = enviar.textContent;
        enviar.textContent = 'Executando…';
        try {
            const r = await chamar(atual.url, { registro_id: atual.registro, entrada: form.elements.entrada.value, simulacao });
            if (r.url) { // squad enfileirado: vai para o andamento da execução
                location.href = r.url;
                return;
            }
            if (simulacao) {
                saida.innerHTML = r.html; // HTML montado e escapado no servidor
                form.hidden = true;
                rodape.hidden = true;
                return;
            }
            if (!r.sucesso) {
                toast('error', 'O agente não concluiu', 'Veja os detalhes em Execuções.');
            } else {
                toast('success', 'Agente executado', r.pendentes > 0 ? `${r.pendentes} ação(ões) aguardam sua aprovação.` : r.resumo);
            }
            dialogo.close();
            if (r.alterou) setTimeout(() => location.reload(), 900);
        } catch (e) {
            toast('error', 'Falha ao executar o agente', e.message);
        } finally {
            enviar.disabled = false;
            enviar.textContent = rotulo;
        }
    });

    dialogo.addEventListener('close', restaurar);
}

// ---- Ações pendentes: selecionar todas e confirmar o lote ----------------------------------

const todas = document.querySelector('[data-selecionar-todas]');
todas?.addEventListener('change', () => {
    document.querySelectorAll('input[name="ids[]"]').forEach((c) => { c.checked = todas.checked; });
});
document.getElementById('form-lote')?.addEventListener('submit', (ev) => {
    const marcadas = document.querySelectorAll('input[name="ids[]"]:checked').length;
    if (marcadas === 0) {
        ev.preventDefault();
        toast('warning', 'Selecione ao menos uma ação');
        return;
    }
    const aprovar = ev.submitter?.value === 'aprovar';
    if (!window.confirm(`${aprovar ? 'Aprovar e aplicar' : 'Rejeitar'} ${marcadas} ação(ões)?`)) ev.preventDefault();
});
