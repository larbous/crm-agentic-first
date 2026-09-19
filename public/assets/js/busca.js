// busca.js — busca global do topo (atalho "/"): empresas, contatos e negócios via GET /api/busca?q=

const raiz = document.querySelector('[data-busca]');
const campo = document.getElementById('busca-global');
const lista = document.getElementById('busca-resultados');

if (raiz && campo && lista) {
    let temporizador = null;
    let requisicao = 0;
    let itens = [];
    let ativo = -1;

    const fechar = () => {
        lista.hidden = true;
        campo.setAttribute('aria-expanded', 'false');
        ativo = -1;
    };

    const destacar = (indice) => {
        itens.forEach((el, i) => el.setAttribute('aria-selected', String(i === indice)));
        ativo = indice;
        itens[indice]?.scrollIntoView({ block: 'nearest' });
    };

    function montar(grupos) {
        lista.replaceChildren();
        itens = [];
        if (grupos.length === 0) {
            const vazio = document.createElement('p');
            vazio.className = 'text-muted-foreground p-3 text-center text-sm';
            vazio.textContent = 'Nada encontrado.';
            lista.append(vazio);
        }
        for (const grupo of grupos) {
            const titulo = document.createElement('div');
            titulo.className = 'busca-grupo';
            titulo.textContent = grupo.rotulo;
            lista.append(titulo);
            for (const item of grupo.itens) {
                const a = document.createElement('a');
                a.className = 'busca-item';
                a.href = item.url;
                a.setAttribute('role', 'option');
                a.setAttribute('aria-selected', 'false');
                const t = document.createElement('span');
                t.textContent = item.titulo;
                a.append(t);
                if (item.subtitulo) {
                    const s = document.createElement('small');
                    s.textContent = item.subtitulo;
                    a.append(s);
                }
                lista.append(a);
                itens.push(a);
            }
        }
        lista.hidden = false;
        campo.setAttribute('aria-expanded', 'true');
        ativo = -1;
    }

    async function buscar(termo) {
        const minha = ++requisicao;
        try {
            const resp = await fetch(`/api/busca?q=${encodeURIComponent(termo)}`, { headers: { Accept: 'application/json' } });
            if (!resp.ok || minha !== requisicao) return;
            montar((await resp.json()).grupos ?? []);
        } catch (_) {
            /* falha de rede: mantém a lista anterior */
        }
    }

    campo.addEventListener('input', () => {
        clearTimeout(temporizador);
        const termo = campo.value.trim();
        if (termo.length < 2) {
            requisicao++;
            fechar();
            return;
        }
        temporizador = setTimeout(() => buscar(termo), 200);
    });

    campo.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') {
            fechar();
            campo.blur();
        } else if (ev.key === 'ArrowDown' && itens.length) {
            ev.preventDefault();
            destacar((ativo + 1) % itens.length);
        } else if (ev.key === 'ArrowUp' && itens.length) {
            ev.preventDefault();
            destacar((ativo - 1 + itens.length) % itens.length);
        } else if (ev.key === 'Enter') {
            ev.preventDefault();
            (itens[ativo] ?? itens[0])?.click();
        }
    });

    campo.addEventListener('focus', () => {
        if (itens.length && campo.value.trim().length >= 2) {
            lista.hidden = false;
            campo.setAttribute('aria-expanded', 'true');
        }
    });

    document.addEventListener('click', (ev) => {
        if (!raiz.contains(ev.target)) fechar();
    });
}
