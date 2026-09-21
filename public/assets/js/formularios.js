// formularios.js — construtor de formulários de captação: adicionar, reordenar (arrastar ou setas), largura, obrigatório,
// rótulos e opções. O estado vai para o textarea [data-fb-json] (campos_json) ao salvar; quem valida é o servidor.

const construtor = document.querySelector('[data-fb]');

if (construtor) {
    const destinos = JSON.parse(construtor.dataset.destinos || '[]');
    const porDestino = new Map(destinos.map((d) => [d.destino, d]));
    const lista = construtor.querySelector('[data-fb-lista]');
    const previa = construtor.querySelector('[data-fb-previa]');
    const seletor = construtor.querySelector('[data-fb-novo]');
    const json = construtor.querySelector('[data-fb-json]');
    const form = construtor.closest('form');
    const TIPOS = {
        texto: 'Texto curto', email: 'E-mail', telefone: 'Telefone', textarea: 'Texto longo',
        select: 'Lista de opções', checkbox: 'Sim/Não', numero: 'Número', data: 'Data',
    };
    const LARGURAS = [[12, 'Linha inteira'], [9, '3/4'], [8, '2/3'], [6, 'Metade'], [4, '1/3'], [3, '1/4']];

    let campos = [];
    try { campos = JSON.parse(json.value || '[]'); } catch (_) { campos = []; }
    // Campos cujo destino não existe mais (ex.: campo extra arquivado) saem da lista; o servidor também os recusaria.
    campos = campos.filter((c) => porDestino.has(c.campo_destino)).map((c) => ({
        ...c, tipo: c.tipo || porDestino.get(c.campo_destino).padrao, opcoes: Array.isArray(c.opcoes) ? c.opcoes : [],
        placeholder: c.placeholder ?? '', ajuda: c.ajuda ?? '', largura: Number(c.largura) || 12, obrigatorio: Number(c.obrigatorio) || 0,
    }));

    const el = (tag, attrs = {}, ...filhos) => {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') n.className = v;
            else if (k === 'text') n.textContent = v;
            else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
            else if (v === true) n.setAttribute(k, '');
            else if (v !== false && v != null) n.setAttribute(k, v);
        }
        for (const f of filhos) if (f) n.append(f);
        return n;
    };

    const sincronizar = () => { json.value = JSON.stringify(campos); };

    const atualizarSeletor = () => {
        const usados = new Set(campos.map((c) => c.campo_destino));
        seletor.replaceChildren();
        const grupos = new Map();
        for (const d of destinos) {
            if (usados.has(d.destino)) continue;
            if (!grupos.has(d.grupo)) grupos.set(d.grupo, el('optgroup', { label: d.grupo }));
            grupos.get(d.grupo).append(el('option', { value: d.destino, text: d.rotulo }));
        }
        seletor.append(...grupos.values());
        seletor.disabled = grupos.size === 0;
    };

    const campoInput = (rotulo, controle, classe = '') => el('div', { class: 'field ' + classe }, el('label', { text: rotulo }), controle);

    const mover = (i, delta) => {
        const j = i + delta;
        if (j < 0 || j >= campos.length) return;
        [campos[i], campos[j]] = [campos[j], campos[i]];
        desenhar();
    };

    const cartao = (c, i) => {
        const d = porDestino.get(c.campo_destino);
        const ligar = (input, chave, converter = (x) => x) => {
            input.addEventListener('input', () => { c[chave] = converter(input.value); sincronizar(); desenharPrevia(); });
            return input;
        };

        const rotulo = ligar(el('input', { class: 'input', type: 'text', value: c.rotulo, maxlength: 120 }), 'rotulo');
        const tipo = el('select', { class: 'select', disabled: d.tipos.length === 1 },
            ...d.tipos.map((t) => el('option', { value: t, text: TIPOS[t] ?? t, selected: t === c.tipo })));
        tipo.addEventListener('change', () => { c.tipo = tipo.value; sincronizar(); desenhar(); });
        const largura = el('select', { class: 'select' },
            ...LARGURAS.map(([n, t]) => el('option', { value: n, text: `${t} (${n})`, selected: n === c.largura })),
            LARGURAS.some(([n]) => n === c.largura) ? null : el('option', { value: c.largura, text: `${c.largura} colunas`, selected: true }));
        largura.addEventListener('change', () => { c.largura = Number(largura.value); sincronizar(); desenharPrevia(); });
        const obrig = el('input', { type: 'checkbox', class: 'input', checked: !!c.obrigatorio, id: `fb-obr-${i}` });
        obrig.addEventListener('change', () => { c.obrigatorio = obrig.checked ? 1 : 0; sincronizar(); desenharPrevia(); });

        const extra = [];
        if (!['checkbox', 'select'].includes(c.tipo)) {
            extra.push(campoInput('Texto de exemplo', ligar(el('input', { class: 'input', type: 'text', value: c.placeholder, maxlength: 120 }), 'placeholder')));
        }
        extra.push(campoInput('Ajuda (abaixo do campo)', ligar(el('input', { class: 'input', type: 'text', value: c.ajuda, maxlength: 200 }), 'ajuda')));
        if (c.tipo === 'select') {
            if (d.opcoes_sistema) {
                extra.push(el('p', { class: 'text-muted-foreground text-sm', text: 'Opções do sistema: ' + d.opcoes_sistema.join(', ') + '.' }));
            } else {
                const ta = el('textarea', { class: 'textarea', rows: 4, text: c.opcoes.join('\n') });
                ta.addEventListener('input', () => { c.opcoes = ta.value.split('\n').map((s) => s.trim()).filter(Boolean); sincronizar(); desenharPrevia(); });
                extra.push(campoInput('Opções (uma por linha)', ta, 'md:col-span-2'));
            }
        }

        const item = el('li', { class: 'fb-item', draggable: false, 'data-i': i },
            el('div', { class: 'fb-cab' },
                el('span', { class: 'fb-alca', title: 'Arraste para reordenar', 'aria-hidden': 'true', text: '⋮⋮' }),
                el('div', { class: 'min-w-0 flex-1' },
                    el('div', { class: 'truncate text-sm font-medium', text: c.rotulo || '(sem rótulo)' }),
                    el('div', { class: 'text-muted-foreground truncate text-xs', text: `${d.grupo} · ${d.rotulo}` })),
                el('button', { type: 'button', class: 'btn', 'data-variant': 'ghost', 'data-size': 'icon-sm', 'aria-label': 'Mover para cima', disabled: i === 0, onclick: () => mover(i, -1), text: '↑' }),
                el('button', { type: 'button', class: 'btn', 'data-variant': 'ghost', 'data-size': 'icon-sm', 'aria-label': 'Mover para baixo', disabled: i === campos.length - 1, onclick: () => mover(i, 1), text: '↓' }),
                el('button', { type: 'button', class: 'btn', 'data-variant': 'ghost', 'data-size': 'icon-sm', 'aria-label': 'Remover campo', onclick: () => { campos.splice(i, 1); desenhar(); }, text: '✕' })),
            el('div', { class: 'fb-corpo grid gap-3 md:grid-cols-2' },
                campoInput('Rótulo', rotulo),
                campoInput('Tipo', tipo),
                campoInput('Largura', largura),
                el('div', { class: 'field', 'data-orientation': 'horizontal' }, obrig, el('label', { for: `fb-obr-${i}`, text: 'Obrigatório' })),
                ...extra));

        // Arrastar: só a alça inicia o arrasto (não atrapalha a seleção de texto nos inputs).
        const alca = item.querySelector('.fb-alca');
        alca.addEventListener('pointerdown', () => { item.draggable = true; });
        const solta = () => { item.draggable = false; item.classList.remove('fb-arrastando'); };
        item.addEventListener('dragend', solta);
        item.addEventListener('dragstart', (ev) => { ev.dataTransfer.setData('text/plain', String(i)); ev.dataTransfer.effectAllowed = 'move'; item.classList.add('fb-arrastando'); });
        item.addEventListener('dragover', (ev) => { ev.preventDefault(); ev.dataTransfer.dropEffect = 'move'; });
        item.addEventListener('drop', (ev) => {
            ev.preventDefault();
            const de = Number(ev.dataTransfer.getData('text/plain'));
            if (Number.isNaN(de) || de === i) return;
            const [movido] = campos.splice(de, 1);
            campos.splice(i, 0, movido);
            desenhar();
        });
        return item;
    };

    const desenharPrevia = () => {
        previa.replaceChildren();
        if (campos.length === 0) {
            previa.append(el('p', { class: 'text-muted-foreground text-sm', text: 'Nenhum campo ainda.' }));
            return;
        }
        const grade = el('div', { class: 'fp-grade' });
        for (const c of campos) {
            const d = porDestino.get(c.campo_destino);
            let controle;
            if (c.campo_destino === 'pesquisa.nota') {
                controle = el('div', { class: 'fp-nps' }, ...Array.from({ length: 11 }, (_, n) => el('span', { class: 'fp-nps-nota', text: String(n) })));
            } else if (c.tipo === 'textarea') controle = el('textarea', { class: 'textarea', rows: 3, disabled: true, placeholder: c.placeholder });
            else if (c.tipo === 'select') {
                const ops = d.opcoes_sistema ?? c.opcoes;
                controle = el('select', { class: 'select', disabled: true }, el('option', { text: 'Selecione…' }), ...ops.map((o) => el('option', { text: o })));
            } else if (c.tipo === 'checkbox') controle = el('input', { type: 'checkbox', class: 'input', disabled: true });
            else controle = el('input', { class: 'input', type: 'text', disabled: true, placeholder: c.placeholder });
            const rot = el('label', { text: c.rotulo }, c.obrigatorio ? el('span', { class: 'text-destructive', text: ' *' }) : null);
            const campo = el('div', { class: 'field', 'data-orientation': c.tipo === 'checkbox' ? 'horizontal' : null },
                ...(c.tipo === 'checkbox' ? [controle, rot] : [rot, controle]),
                c.ajuda ? el('p', { class: 'text-muted-foreground text-sm', text: c.ajuda }) : null);
            const celula = el('div', { class: 'fp-campo' }, campo);
            celula.style.setProperty('--span', String(c.largura));
            grade.append(celula);
        }
        previa.append(grade);
    };

    const desenhar = () => {
        lista.replaceChildren(...campos.map(cartao));
        atualizarSeletor();
        desenharPrevia();
        sincronizar();
    };

    construtor.querySelector('[data-fb-adicionar]').addEventListener('click', () => {
        const d = porDestino.get(seletor.value);
        if (!d) return;
        campos.push({
            campo_destino: d.destino, rotulo: d.rotulo, tipo: d.padrao, placeholder: '', ajuda: '', obrigatorio: 0, opcoes: [],
            largura: ['textarea'].includes(d.padrao) || d.destino === 'pesquisa.nota' ? 12 : 6,
        });
        desenhar();
        lista.lastElementChild?.querySelector('input')?.focus();
    });

    form?.addEventListener('submit', sincronizar);
    desenhar();
}
