// embed-formulario.js — incorpora um formulário de captação no site do cliente.
// Uso: <script src=".../assets/js/embed-formulario.js" data-formulario="CHAVE" async></script>
// Cria um iframe (no lugar do script, ou em #larbous-form-CHAVE se existir), ajusta a altura e repassa à página do
// formulário as UTMs, a URL da página e a referência. Script independente: não depende de nenhum outro arquivo do CRM.
(function () {
    var script = document.currentScript;
    if (!script) return;
    var chave = script.getAttribute('data-formulario');
    if (!/^[a-f0-9]{32}$/.test(chave || '')) return;

    // Base do CRM = tudo antes de "assets/js/embed-formulario.js" no endereço deste script.
    var base = script.src.replace(/assets\/js\/embed-formulario\.js.*$/, '');
    var utms = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    var params = [];
    var busca = new URLSearchParams(window.location.search);
    utms.forEach(function (k) {
        var v = busca.get(k);
        try {
            // Guarda na sessão: a pessoa pode ter chegado por um anúncio e preencher o formulário em outra página do site.
            if (v) sessionStorage.setItem('larbous_' + k, v); else v = sessionStorage.getItem('larbous_' + k);
        } catch (e) { /* sem armazenamento: usa só a query */ }
        if (v) params.push(k + '=' + encodeURIComponent(v));
    });
    params.push('pagina=' + encodeURIComponent(window.location.href.slice(0, 500)));
    if (document.referrer) params.push('ref=' + encodeURIComponent(document.referrer.slice(0, 500)));

    var iframe = document.createElement('iframe');
    iframe.src = base + 'f/' + chave + '?embed=1&' + params.join('&');
    iframe.title = 'Formulário de contato';
    iframe.loading = 'lazy';
    iframe.style.cssText = 'width:100%;border:0;min-height:320px;display:block';
    iframe.setAttribute('scrolling', 'no');

    var destino = document.getElementById('larbous-form-' + chave);
    if (destino) destino.appendChild(iframe); else script.parentNode.insertBefore(iframe, script);

    window.addEventListener('message', function (ev) {
        if (ev.source !== iframe.contentWindow || !ev.data || ev.data.larbous !== 'formulario') return;
        if (ev.data.tipo === 'altura' && ev.data.altura > 0) {
            iframe.style.height = Math.min(ev.data.altura, 5000) + 'px';
        } else if (ev.data.tipo === 'redirecionar' && /^https?:\/\//i.test(ev.data.url || '')) {
            window.location.href = ev.data.url;
        }
    });
})();
