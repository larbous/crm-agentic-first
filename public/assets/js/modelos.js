// modelos.js — editor de modelos de documento: inserir variáveis, formatação HTML simples e
// pré-visualização com registro de exemplo (POST /api/modelos/preview, sempre renderizada e sanitizada no servidor).

import { csrf } from './ui.js';

const form = document.querySelector('[data-modelo-form]');

if (form) {
    const texto = form.querySelector('#campo-conteudo');
    const tipo = form.querySelector('[data-modelo-tipo]');
    const negocio = form.querySelector('[data-preview-negocio]');
    const saida = form.querySelector('[data-preview]');
    const avisos = form.querySelector('[data-preview-avisos]');
    let temporizador = null;
    let requisicao = 0;

    function inserir(trecho, selecionarDentro = false) {
        const ini = texto.selectionStart;
        const fim = texto.selectionEnd;
        texto.setRangeText(trecho, ini, fim, 'end');
        if (selecionarDentro) texto.setSelectionRange(ini + selecionarDentro[0], ini + selecionarDentro[1]);
        texto.focus();
        texto.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function envolver(tag) {
        const sel = texto.value.slice(texto.selectionStart, texto.selectionEnd);
        const miolo = sel || 'texto';
        const modelos = {
            h2: `<h2>${miolo}</h2>\n`,
            p: `<p>${miolo}</p>\n`,
            strong: `<strong>${miolo}</strong>`,
            em: `<em>${miolo}</em>`,
            ul: `<ul>\n  <li>${miolo}</li>\n  <li>item</li>\n</ul>\n`,
            ol: `<ol>\n  <li>${miolo}</li>\n  <li>item</li>\n</ol>\n`,
            hr: '<hr>\n',
        };
        if (modelos[tag]) inserir(modelos[tag]);
    }

    async function atualizar() {
        const minha = ++requisicao;
        try {
            const resp = await fetch('/api/modelos/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify({ tipo: tipo.value, conteudo: texto.value, negocio_id: negocio?.value || null }),
            });
            if (minha !== requisicao) return;
            const dados = await resp.json();
            if (!resp.ok) throw new Error(dados.erro || 'erro');
            saida.innerHTML = dados.html || '<span class="text-muted-foreground text-sm">Sem conteúdo.</span>';
            saida.classList.toggle('documento-corpo', tipo.value === 'contrato');
            const faltando = dados.naoResolvidas ?? [];
            avisos.hidden = faltando.length === 0;
            avisos.textContent = faltando.length ? `Variáveis desconhecidas: ${faltando.join(', ')}` : '';
        } catch (_) {
            saida.textContent = 'Não foi possível gerar a pré-visualização.';
        }
    }

    function agendar() {
        clearTimeout(temporizador);
        temporizador = setTimeout(atualizar, 350);
    }

    function ajustarTipo() {
        const contrato = tipo.value === 'contrato';
        form.querySelector('[data-barra-html]').hidden = !contrato;
        form.querySelector('[data-dica-html]').hidden = !contrato;
        form.querySelector('[data-dica-texto]').hidden = contrato;
        form.querySelector('[data-so-email]').hidden = tipo.value !== 'email';
    }

    form.addEventListener('click', (ev) => {
        const alvo = ev.target instanceof Element ? ev.target : null;
        const variavel = alvo?.closest('[data-variavel]');
        if (variavel) {
            inserir(variavel.getAttribute('data-variavel'));
            return;
        }
        const formatar = alvo?.closest('[data-formatar]');
        if (formatar) envolver(formatar.getAttribute('data-formatar'));
    });

    form.querySelector('[data-filtro-variaveis]')?.addEventListener('input', (ev) => {
        const termo = ev.target.value.trim().toLowerCase();
        for (const grupo of form.querySelectorAll('.variavel-grupo')) {
            let visiveis = 0;
            for (const item of grupo.querySelectorAll('[data-variavel]')) {
                const mostra = termo === '' || item.textContent.toLowerCase().includes(termo);
                item.hidden = !mostra;
                if (mostra) visiveis++;
            }
            grupo.hidden = visiveis === 0;
            if (termo !== '') grupo.open = visiveis > 0;
        }
    });

    texto.addEventListener('input', agendar);
    tipo.addEventListener('change', () => { ajustarTipo(); agendar(); });
    negocio?.addEventListener('change', agendar);
    ajustarTipo();
    atualizar();
}
