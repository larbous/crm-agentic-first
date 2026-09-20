<?php

declare(strict_types=1);

/**
 * Modelos de documento de exemplo (importados pelo seed). São ponto de partida: revise o texto
 * (idealmente com um advogado) e ajuste os dados da agência em Configurações → Dados da agência.
 * Retorna uma lista de [tipo, nome, assunto, conteudo].
 */
return [
    ['proposta', 'Proposta comercial — apresentação padrão', null, <<<'TXT'
Olá {contato.primeiro_nome},

É um prazer apresentar esta proposta para a {empresa.nome}. Depois de conhecer o cenário e os objetivos do projeto "{negocio.titulo}", preparamos uma solução sob medida, com escopo, prazos e investimento descritos abaixo.

A {larbous.nome} desenvolve sites, lojas virtuais e presença digital, e acompanha cada projeto de perto — do briefing à entrega e ao pós-lançamento.

Esta proposta é válida até {proposta.validade}. Qualquer dúvida, é só falar com a gente: {larbous.email} {larbous.telefone}.
TXT],

    ['contrato', 'Contrato de prestação de serviços — desenvolvimento web (exemplo)', null, <<<'HTML'
<h1>CONTRATO DE PRESTAÇÃO DE SERVIÇOS DE DESENVOLVIMENTO WEB</h1>
<p>Contrato nº <strong>{contrato.numero}</strong>, firmado em {hoje.extenso}.</p>

<h2>1. DAS PARTES</h2>
<p><strong>CONTRATADA:</strong> {larbous.nome}, inscrita no CNPJ sob o nº {larbous.cnpj}, com sede em {larbous.endereco}, representada por {larbous.responsavel}.</p>
<p><strong>CONTRATANTE:</strong> {empresa.razao}, inscrita no CNPJ sob o nº {empresa.cnpj}, com sede em {empresa.endereco_completo}, neste ato representada por {contato.nome_completo}.</p>

<h2>2. DO OBJETO</h2>
<p>O presente contrato tem por objeto a prestação dos serviços descritos na proposta comercial {proposta.numero_versao}, conforme os itens abaixo:</p>
{proposta.itens}

<h2>3. DO VALOR E DA FORMA DE PAGAMENTO</h2>
<p>Pelos serviços contratados, a CONTRATANTE pagará à CONTRATADA o valor total de <strong>{contrato.valor_total}</strong> ({contrato.valor_total_extenso}).</p>
<p>Forma de pagamento: {contrato.forma_pagamento}. Quando houver parcela recorrente, o valor mensal é de {contrato.valor_mensal} ({contrato.valor_mensal_extenso}), com vencimento todo dia {contrato.dia_vencimento_pagamento}.</p>
<p>O atraso no pagamento sujeita a CONTRATANTE a multa de 2% e juros de 1% ao mês, calculados pro rata die.</p>

<h2>4. DA VIGÊNCIA E DA RENOVAÇÃO</h2>
<p>O contrato vigorará {contrato.vigencia}. Salvo aviso em contrário com antecedência de {contrato.aviso_renovacao_dias} dias, as partes poderão renová-lo mediante novo aceite.</p>

<h2>5. DAS OBRIGAÇÕES DA CONTRATADA</h2>
<ul>
<li>Executar os serviços com diligência técnica, respeitando o escopo e os prazos acordados;</li>
<li>Manter sigilo sobre as informações a que tiver acesso em razão do contrato;</li>
<li>Comunicar imediatamente qualquer impedimento que possa afetar o cronograma.</li>
</ul>

<h2>6. DAS OBRIGAÇÕES DA CONTRATANTE</h2>
<ul>
<li>Fornecer, em tempo hábil, textos, imagens, acessos e demais materiais necessários;</li>
<li>Validar as etapas entregues nos prazos combinados, sob pena de reprogramação do cronograma;</li>
<li>Efetuar os pagamentos nas datas acordadas.</li>
</ul>

<h2>7. DA PROPRIEDADE INTELECTUAL</h2>
<p>Após a quitação integral dos valores, a CONTRATANTE passa a deter os direitos de uso sobre o material produzido especificamente para ela. Ferramentas, códigos e componentes preexistentes da CONTRATADA permanecem de sua titularidade.</p>

<h2>8. DA PROTEÇÃO DE DADOS (LGPD)</h2>
<p>As partes se comprometem a tratar dados pessoais em conformidade com a Lei nº 13.709/2018, utilizando-os apenas para as finalidades deste contrato.</p>

<h2>9. DA RESCISÃO</h2>
<p>Qualquer das partes poderá rescindir este contrato mediante aviso prévio de {contrato.aviso_previo_dias} dias. A rescisão imotivada pela CONTRATANTE sujeita-a à multa de {contrato.multa_rescisoria} sobre o valor restante do contrato, sem prejuízo do pagamento dos serviços já executados.</p>

<h2>10. DO REAJUSTE</h2>
<p>Índice de reajuste: {contrato.indice_reajuste}. Próximo reajuste previsto: {contrato.data_proximo_reajuste}.</p>

<h2>11. DO FORO</h2>
<p>Fica eleito o foro da comarca de {larbous.cidade} para dirimir quaisquer controvérsias oriundas deste contrato, com renúncia a qualquer outro.</p>

<p>E, por estarem de acordo, as partes assinam este contrato eletronicamente.</p>
<p><em>Modelo de exemplo: revise com seu advogado antes de usar.</em></p>
HTML],
];
