// forms.js — comportamentos dos formulários: máscaras, campos de dinheiro e auto-preenchimento
// por CEP (ViaCEP) e CNPJ (BrasilAPI), ambos chamados diretamente do navegador.

import { toast } from './ui.js';

const digitos = (t) => String(t ?? '').replace(/\D+/g, '');

// ---- Máscaras -----------------------------------------------------------------------

const MASCARAS = {
    cnpj: (d) => d.slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2'),
    cpf: (d) => d.slice(0, 11).replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'),
    cep: (d) => d.slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2'),
};

document.addEventListener('input', (ev) => {
    const el = ev.target;
    const tipo = el instanceof HTMLInputElement ? el.dataset.mascara : null;
    if (tipo && MASCARAS[tipo]) {
        el.value = MASCARAS[tipo](digitos(el.value));
    }
});

// ---- Dinheiro (pt-BR) ---------------------------------------------------------------

function paraNumero(texto) {
    let t = String(texto).replace(/R\$|\s/g, '');
    if (t === '') return null;
    if (t.includes(',')) {
        t = t.replace(/\./g, '').replace(',', '.');
    } else if ((t.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(t)) {
        t = t.replace(/\./g, '');
    }
    const n = Number(t);
    return Number.isFinite(n) ? n : null;
}

document.addEventListener('focusout', (ev) => {
    const el = ev.target;
    if (el instanceof HTMLInputElement && el.hasAttribute('data-dinheiro') && el.value.trim() !== '') {
        const n = paraNumero(el.value);
        if (n !== null) {
            el.value = n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }
});

// ---- Auto-preenchimento ----------------------------------------------------------------

const campoPorNome = (nome) => document.getElementById(`campo-${nome}`);

/** Preenche apenas campos vazios, para nunca sobrescrever o que o operador digitou. */
function preencher(valores) {
    let preenchidos = 0;
    for (const [nome, valor] of Object.entries(valores)) {
        const el = campoPorNome(nome);
        if (el && valor !== undefined && valor !== null && String(valor).trim() !== '' && el.value.trim() === '') {
            el.value = String(valor);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            preenchidos++;
        }
    }
    return preenchidos;
}

const titulo = (t) => String(t ?? '').toLowerCase().replace(/(^|\s|-)(\p{L})/gu, (_, sep, letra) => sep + letra.toUpperCase())
    .replace(/\b(Da|De|Do|Das|Dos|E)\b/g, (m) => m.toLowerCase());

async function buscarJson(url) {
    const resposta = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!resposta.ok) throw new Error(String(resposta.status));
    return resposta.json();
}

async function preencherPorCep(input) {
    const cep = digitos(input.value);
    if (cep.length !== 8 || !campoPorNome('logradouro')) return;
    try {
        const d = await buscarJson(`https://viacep.com.br/ws/${cep}/json/`);
        if (d.erro) {
            toast('warning', 'CEP não encontrado', 'Confira o CEP ou preencha o endereço manualmente.');
            return;
        }
        preencher({ logradouro: d.logradouro, bairro: d.bairro, cidade: d.localidade, uf: d.uf });
    } catch (_) {
        toast('warning', 'Não foi possível consultar o CEP', 'Preencha o endereço manualmente.');
    }
}

const PORTE = { 'MICRO EMPRESA': 'me', 'EMPRESA DE PEQUENO PORTE': 'epp' };

async function preencherPorCnpj(input, botao) {
    const cnpj = digitos(input.value);
    if (cnpj.length !== 14) {
        toast('warning', 'CNPJ incompleto', 'Digite os 14 dígitos para consultar.');
        return;
    }
    botao.disabled = true;
    try {
        const d = await buscarJson(`https://brasilapi.com.br/api/cnpj/v1/${cnpj}`);
        const telefone = digitos(d.ddd_telefone_1);
        const n = preencher({
            razao_social: d.razao_social,
            nome_fantasia: titulo(d.nome_fantasia || d.razao_social),
            email_geral: String(d.email ?? '').toLowerCase(),
            telefone: telefone.length >= 10 ? `(${telefone.slice(0, 2)}) ${telefone.slice(2)}` : '',
            cep: d.cep ? MASCARAS.cep(digitos(d.cep)) : '',
            logradouro: titulo(d.logradouro), numero: d.numero, complemento: titulo(d.complemento),
            bairro: titulo(d.bairro), cidade: titulo(d.municipio), uf: d.uf,
            cnae_principal: d.cnae_fiscal ? `${d.cnae_fiscal} — ${d.cnae_fiscal_descricao ?? ''}`.trim() : '',
            data_fundacao: d.data_inicio_atividade,
        });
        const porte = campoPorNome('porte');
        if (porte && porte.value === '') {
            const valor = d.opcao_pelo_mei ? 'mei' : PORTE[String(d.porte ?? '').toUpperCase()];
            if (valor) porte.value = valor;
        }
        toast('success', 'Dados do CNPJ carregados', n ? `${n} campo(s) preenchido(s).` : 'Os campos já estavam preenchidos.');
    } catch (e) {
        toast('error', 'Não foi possível consultar o CNPJ', e.message === '404' ? 'CNPJ não encontrado na base pública.' : 'Tente novamente ou preencha manualmente.');
    } finally {
        botao.disabled = false;
    }
}

function iniciar() {
    // CEP: consulta ao sair do campo
    document.addEventListener('focusout', (ev) => {
        const el = ev.target;
        if (el instanceof HTMLInputElement && el.dataset.mascara === 'cep') {
            preencherPorCep(el);
        }
    });

    // CNPJ: botão "Buscar" ao lado do campo
    const cnpj = document.querySelector('input[data-mascara="cnpj"]');
    if (cnpj && campoPorNome('nome_fantasia')) {
        const botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'btn';
        botao.dataset.variant = 'outline';
        botao.dataset.size = 'sm';
        botao.textContent = 'Buscar dados do CNPJ';
        botao.addEventListener('click', () => preencherPorCnpj(cnpj, botao));
        const linha = document.createElement('div');
        linha.className = 'flex gap-2';
        cnpj.replaceWith(linha);
        cnpj.classList.add('flex-1');
        linha.append(cnpj, botao);
    }
}

iniciar();
