// editor-html.js — editor de texto formatado (HTML simples) dos contratos.
// Camada fina sobre contenteditable: estilos pré-definidos (títulos, cláusulas, listas, tabelas, assinaturas),
// variáveis {empresa.nome} e alternância com o código HTML. O servidor sanitiza o HTML de novo ao salvar.

const TAGS_PERMITIDAS = new Set([
    'P', 'BR', 'HR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'STRONG', 'B', 'EM', 'I', 'U', 'SUB', 'SUP', 'SMALL', 'UL', 'OL', 'LI',
    'BLOCKQUOTE', 'SPAN', 'DIV', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TH', 'TD', 'A',
]);
const DESCARTAR = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'LINK', 'META', 'SVG', 'MATH', 'TEMPLATE', 'HEAD', 'TITLE']);
const BLOCOS = new Set(['P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'DIV', 'BLOCKQUOTE', 'LI', 'TD', 'TH']);

const BLOCOS_PRONTOS = {
    clausula: '<h3>CLÁUSULA 1ª – TÍTULO DA CLÁUSULA</h3><p>1.1. Texto da cláusula.</p><p>1.2. Texto da cláusula.</p>',
    paragrafo: '<p>1.1. Texto do parágrafo.</p>',
    lista: '<ol type="a"><li>primeiro item;</li><li>segundo item;</li><li>terceiro item.</li></ol>',
    valores: '<table><thead><tr><th>Descrição</th><th align="right">Valor</th></tr></thead><tbody>'
        + '<tr><td>Serviço contratado</td><td align="right">{contrato.valor_total}</td></tr>'
        + '<tr><td>Mensalidade</td><td align="right">{contrato.valor_mensal}</td></tr></tbody></table>',
    assinaturas: '<p>E, por estarem de acordo, as partes assinam o presente contrato.</p>'
        + '<p align="center">{larbous.cidade}, {hoje.extenso}.</p>'
        + '<table><tbody><tr><td align="center"><br><br>______________________________<br><strong>{larbous.nome}</strong><br>CONTRATADA</td>'
        + '<td align="center"><br><br>______________________________<br><strong>{empresa.razao}</strong><br>CONTRATANTE</td></tr></tbody></table>',
    testemunhas: '<p><strong>Testemunhas:</strong></p><table><tbody><tr><td><br>______________________________<br>Nome:<br>CPF:</td>'
        + '<td><br>______________________________<br>Nome:<br>CPF:</td></tr></tbody></table>',
    foro: '<h3>CLÁUSULA – DO FORO</h3><p>Fica eleito o foro da comarca de {larbous.cidade} para dirimir quaisquer dúvidas ou litígios oriundos deste contrato, com renúncia a qualquer outro, por mais privilegiado que seja.</p>',
};

/** Limpa HTML colado (Word, sites): mantém só as tags permitidas, sem estilos nem classes. */
function limparHtml(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const varrer = (no) => {
        for (const filho of [...no.childNodes]) {
            if (filho.nodeType === Node.COMMENT_NODE) {
                filho.remove();
            } else if (filho.nodeType === Node.ELEMENT_NODE) {
                if (DESCARTAR.has(filho.tagName)) {
                    filho.remove();
                    continue;
                }
                varrer(filho);
                if (!TAGS_PERMITIDAS.has(filho.tagName)) {
                    filho.replaceWith(...filho.childNodes);
                    continue;
                }
                for (const attr of [...filho.attributes]) {
                    const manter = (filho.tagName === 'A' && attr.name === 'href')
                        || (attr.name === 'type' && filho.tagName === 'OL' && /^[aAiI1]$/.test(attr.value))
                        || (attr.name === 'align' && /^(left|right|center|justify)$/i.test(attr.value))
                        || (['colspan', 'rowspan'].includes(attr.name) && /^\d+$/.test(attr.value));
                    if (!manter) filho.removeAttribute(attr.name);
                }
            }
        }
    };
    varrer(doc.body);
    return doc.body.innerHTML;
}

function iniciar(raiz) {
    const area = raiz.querySelector('[data-ed-area]');
    const fonte = raiz.querySelector('[data-ed-fonte]');
    const barra = raiz.querySelector('[data-ed-barra]');
    const selBloco = raiz.querySelector('[data-ed-bloco]');
    const btnFonte = raiz.querySelector('[data-ed="fonte"]');
    let modoFonte = false;

    area.innerHTML = fonte.value.trim() === '' ? '<p><br></p>' : fonte.value;
    area.hidden = false;
    barra.hidden = false;
    fonte.hidden = true;
    document.execCommand('defaultParagraphSeparator', false, 'p');

    const sincronizar = () => { if (!modoFonte) fonte.value = area.innerHTML; };
    const exec = (cmd, valor = null) => { area.focus(); document.execCommand(cmd, false, valor); sincronizar(); atualizarBloco(); };

    function blocoAtual() {
        const sel = window.getSelection();
        let no = sel?.anchorNode ?? null;
        while (no && no !== area) {
            if (no.nodeType === Node.ELEMENT_NODE && BLOCOS.has(no.tagName)) return no;
            no = no.parentNode;
        }
        return null;
    }

    function atualizarBloco() {
        if (modoFonte) return;
        const tag = blocoAtual()?.tagName.toLowerCase();
        selBloco.value = ['h1', 'h2', 'h3', 'blockquote'].includes(tag) ? tag : 'p';
    }

    function alinhar(lado) {
        const bloco = blocoAtual();
        if (!bloco || bloco.tagName === 'LI') return;
        if (lado === 'left') bloco.removeAttribute('align');
        else bloco.setAttribute('align', lado);
        sincronizar();
    }

    function inserirTabela() {
        exec('insertHTML', '<table><thead><tr><th>Título</th><th>Título</th><th>Título</th></tr></thead><tbody>'
            + '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table><p><br></p>');
    }

    function inserirLink() {
        const url = window.prompt('Endereço do link (https://…):', 'https://');
        if (url && /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url.trim())) exec('createLink', url.trim());
    }

    function alternarFonte() {
        modoFonte = !modoFonte;
        if (modoFonte) {
            fonte.value = area.innerHTML;
        } else {
            area.innerHTML = fonte.value.trim() === '' ? '<p><br></p>' : fonte.value;
        }
        area.hidden = modoFonte;
        fonte.hidden = !modoFonte;
        btnFonte.setAttribute('aria-pressed', String(modoFonte));
        for (const el of barra.querySelectorAll('button:not([data-ed="fonte"]), select')) el.disabled = modoFonte;
        (modoFonte ? fonte : area).focus();
    }

    const ACOES = {
        bold: () => exec('bold'),
        italic: () => exec('italic'),
        underline: () => exec('underline'),
        ul: () => exec('insertUnorderedList'),
        ol: () => exec('insertOrderedList'),
        alinhar: (b) => alinhar(b.dataset.edValor),
        link: inserirLink,
        tabela: inserirTabela,
        linha: () => exec('insertHorizontalRule'),
        limpar: () => { exec('removeFormat'); exec('formatBlock', 'p'); },
        desfazer: () => exec('undo'),
        refazer: () => exec('redo'),
        fonte: alternarFonte,
    };

    barra.addEventListener('mousedown', (ev) => {
        // Mantém a seleção do texto ao clicar nos botões da barra.
        if (ev.target instanceof Element && ev.target.closest('button')) ev.preventDefault();
    });
    barra.addEventListener('click', (ev) => {
        const botao = ev.target instanceof Element ? ev.target.closest('[data-ed]') : null;
        if (botao && ACOES[botao.dataset.ed]) ACOES[botao.dataset.ed](botao);
    });
    selBloco.addEventListener('change', () => exec('formatBlock', selBloco.value));
    raiz.querySelector('[data-ed-modelo]').addEventListener('change', (ev) => {
        const sel = ev.target;
        if (BLOCOS_PRONTOS[sel.value]) exec('insertHTML', BLOCOS_PRONTOS[sel.value]);
        sel.value = '';
    });
    raiz.querySelector('[data-ed-variavel]').addEventListener('change', (ev) => {
        const sel = ev.target;
        if (sel.value) exec('insertText', sel.value);
        sel.value = '';
    });

    area.addEventListener('input', sincronizar);
    document.addEventListener('selectionchange', () => { if (area.contains(window.getSelection()?.anchorNode)) atualizarBloco(); });
    area.addEventListener('paste', (ev) => {
        const html = ev.clipboardData?.getData('text/html');
        ev.preventDefault();
        if (html) document.execCommand('insertHTML', false, limparHtml(html));
        else document.execCommand('insertText', false, ev.clipboardData?.getData('text/plain') ?? '');
        sincronizar();
    });
    area.addEventListener('keydown', (ev) => {
        if (!(ev.ctrlKey || ev.metaKey)) return;
        const atalhos = { b: 'bold', i: 'italic', u: 'underline' };
        if (atalhos[ev.key.toLowerCase()]) {
            ev.preventDefault();
            exec(atalhos[ev.key.toLowerCase()]);
        }
    });
    raiz.closest('form')?.addEventListener('submit', () => {
        if (!modoFonte) fonte.value = area.innerHTML;
    });
}

for (const raiz of document.querySelectorAll('[data-editor-html]')) iniciar(raiz);
