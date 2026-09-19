// table.js — comportamento do componente data-table: ordenação por coluna e seleção de linhas.
// Ordenação no cliente (listas paginadas do servidor entram na Fase 2 e reaproveitam o mesmo markup).

const colecao = new Intl.Collator('pt-BR', { numeric: true, sensitivity: 'base' });

function valorDaCelula(linha, indice, tipo) {
    const celula = linha.children[indice];
    const bruto = celula?.dataset.value ?? celula?.textContent?.trim() ?? '';
    if (tipo === 'numero') {
        const n = parseFloat(String(bruto).replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.'));
        return Number.isNaN(n) ? Number.NEGATIVE_INFINITY : n;
    }
    return bruto;
}

function ordenar(tabela, th) {
    const corpo = tabela.tBodies[0];
    const indice = [...th.parentElement.children].indexOf(th);
    const tipo = th.dataset.sort;
    const atual = th.getAttribute('aria-sort');
    const direcao = atual === 'ascending' ? 'descending' : 'ascending';

    tabela.querySelectorAll('th[aria-sort]').forEach((outro) => outro.setAttribute('aria-sort', 'none'));
    th.setAttribute('aria-sort', direcao);

    const fator = direcao === 'ascending' ? 1 : -1;
    const linhas = [...corpo.rows].sort((a, b) => {
        const va = valorDaCelula(a, indice, tipo);
        const vb = valorDaCelula(b, indice, tipo);
        return (typeof va === 'number' ? va - vb : colecao.compare(va, vb)) * fator;
    });
    linhas.forEach((linha) => corpo.appendChild(linha));
}

function atualizarSelecao(raiz) {
    const linhas = [...raiz.querySelectorAll('[data-select-row]')];
    const marcadas = linhas.filter((c) => c.checked);
    linhas.forEach((c) => c.closest('tr')?.toggleAttribute('data-state', false));
    marcadas.forEach((c) => c.closest('tr')?.setAttribute('data-state', 'selected'));
    const todas = raiz.querySelector('[data-select-all]');
    if (todas) {
        todas.checked = linhas.length > 0 && marcadas.length === linhas.length;
        todas.indeterminate = marcadas.length > 0 && marcadas.length < linhas.length;
    }
    raiz.dispatchEvent(new CustomEvent('datatable:selecao', { detail: { total: marcadas.length }, bubbles: true }));
}

document.addEventListener('click', (ev) => {
    const alvo = ev.target instanceof Element ? ev.target : null;
    const botao = alvo?.closest('.data-table-sort');
    if (botao) {
        const th = botao.closest('th');
        const tabela = botao.closest('table');
        if (th && tabela) ordenar(tabela, th);
    }
});

document.addEventListener('change', (ev) => {
    const alvo = ev.target instanceof Element ? ev.target : null;
    const raiz = alvo?.closest('[data-data-table]');
    if (!alvo || !raiz) return;

    if (alvo.matches('[data-select-all]')) {
        raiz.querySelectorAll('[data-select-row]').forEach((c) => { c.checked = alvo.checked; });
        atualizarSelecao(raiz);
    } else if (alvo.matches('[data-select-row]')) {
        atualizarSelecao(raiz);
    }
});
