// propostas.js — editor de itens da proposta: catálogo, linhas dinâmicas e totais em tempo real.
// A regra é a mesma do servidor (ActionExecutor): total do item = quantidade × unitário − desconto;
// desconto geral em valor ou percentual sobre o subtotal. O servidor sempre recalcula ao salvar.

const raiz = document.querySelector('[data-editor-itens]');

if (raiz) {
    const corpo = raiz.querySelector('[data-itens-corpo]');
    const modelo = raiz.querySelector('template[data-item-modelo]');
    const vazio = raiz.querySelector('[data-itens-vazio]');
    const catalogo = JSON.parse(raiz.querySelector('script[data-servicos]')?.textContent || '[]');
    let indice = corpo.querySelectorAll('[data-item]').length;

    const numero = (texto) => {
        let t = String(texto ?? '').replace(/R\$|\s/g, '');
        if (t === '') return 0;
        if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
        else if ((t.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(t)) t = t.replace(/\./g, '');
        const n = Number(t);
        return Number.isFinite(n) ? n : 0;
    };
    const centavos = (texto) => Math.round(numero(texto) * 100);
    const moeda = (c) => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const campo = (linha, nome) => linha.querySelector(`[data-calc="${nome}"]`)?.value ?? '';

    function recalcular() {
        let subtotal = 0;
        let recorrente = 0;
        for (const linha of corpo.querySelectorAll('[data-item]')) {
            const bruto = Math.round(numero(campo(linha, 'quantidade')) * centavos(campo(linha, 'unitario')));
            const total = Math.max(0, bruto - centavos(campo(linha, 'desconto')));
            linha.querySelector('[data-item-total]').textContent = moeda(total);
            subtotal += total;
            if (linha.querySelector('input[type="checkbox"][name$="[recorrente]"]')?.checked) recorrente += total;
        }
        const tipo = document.querySelector('[name="desconto_tipo"]')?.value ?? 'valor';
        const valor = document.querySelector('[name="desconto_valor"]')?.value ?? '';
        const desconto = tipo === 'percentual' ? Math.round(subtotal * numero(valor) / 100) : centavos(valor);
        const finais = { subtotal, desconto: Math.min(desconto, subtotal), total: Math.max(0, subtotal - desconto), recorrente };
        for (const [chave, v] of Object.entries(finais)) {
            const el = raiz.querySelector(`[data-total="${chave}"]`);
            if (el) el.textContent = moeda(v);
        }
        if (vazio) vazio.hidden = corpo.querySelector('[data-item]') !== null;
    }

    function adicionar(valores = {}) {
        const html = modelo.innerHTML.replaceAll('__i__', String(indice++));
        const suporte = document.createElement('tbody');
        suporte.innerHTML = html;
        const linha = suporte.firstElementChild;
        const definir = (seletor, valor) => {
            const el = linha.querySelector(seletor);
            if (el && valor !== undefined && valor !== null) el.value = valor;
        };
        definir('[name$="[servico_id]"]', valores.servico_id ?? '');
        definir('[name$="[descricao]"]', valores.descricao ?? '');
        definir('[name$="[unidade]"]', valores.unidade ?? '');
        definir('[data-calc="unitario"]', valores.preco ?? '');
        if (valores.recorrente) linha.querySelector('input[type="checkbox"]').checked = true;
        corpo.append(linha);
        recalcular();
        linha.querySelector('[name$="[descricao]"]')?.focus();
    }

    raiz.addEventListener('click', (ev) => {
        const alvo = ev.target instanceof Element ? ev.target : null;
        if (!alvo) return;
        if (alvo.closest('[data-adicionar-avulso]')) {
            adicionar();
        } else if (alvo.closest('[data-remover-item]')) {
            alvo.closest('[data-item]')?.remove();
            recalcular();
        }
    });

    raiz.addEventListener('change', (ev) => {
        const alvo = ev.target;
        if (alvo instanceof HTMLSelectElement && alvo.hasAttribute('data-servico-select')) {
            const s = catalogo.find((c) => String(c.id) === alvo.value);
            if (s) {
                adicionar({ servico_id: s.id, descricao: s.descricao, unidade: s.unidade.toLowerCase(), preco: s.preco, recorrente: s.recorrente });
            }
            alvo.value = '';
        }
        recalcular();
    });

    raiz.addEventListener('input', recalcular);
    // O desconto geral fica em outra aba, fora do editor.
    document.querySelectorAll('[name="desconto_tipo"], [name="desconto_valor"]').forEach((el) => {
        el.addEventListener('input', recalcular);
        el.addEventListener('change', recalcular);
    });

    recalcular();
}
