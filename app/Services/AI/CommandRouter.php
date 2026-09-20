<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ConsultaRepository;
use App\Repositories\ExecucaoRepository;
use App\Services\Schema;

/**
 * Roteador de linguagem natural: UMA chamada de IA por mensagem (sem histórico, sem loop) que devolve JSON
 * no contrato do SPEC §3.4. A saída é validada por whitelist (ContratoRoteador); o servidor executa.
 */
final class CommandRouter
{
    /** Padrão só usado se `ia.modelo_roteador` não estiver em `configuracoes`. */
    public const MODELO_PADRAO = 'claude-haiku-4-5-20251001';

    private static ?string $promptCache = null;

    public function __construct(
        private readonly Client $client = new Client(),
        private readonly ContextBuilder $contexto = new ContextBuilder(),
    ) {
    }

    /**
     * @return array{ok:true,plano:array,execucao_id:int,bruto:mixed}|array{ok:false,motivo:string,execucao_id:int,bruto:mixed}
     * @throws IaErro falha de rede, timeout, chave ausente ou recusada
     */
    public function rotear(string $mensagem, ?array $tela, ?array $ultimaRef, ?string $hoje = null): array
    {
        $modelo = (new ConfiguracaoRepository())->obter('ia.modelo_roteador', self::MODELO_PADRAO) ?: self::MODELO_PADRAO;
        $meta = $tela !== null ? ['entidade' => $tela['entidade'], 'registro_id' => $tela['id']] : [];
        $resposta = $this->client->chamar($modelo, self::prompt(), $this->contexto->montar($mensagem, $tela, $ultimaRef, $hoje), $meta, 800);

        $bruto = self::extrairJson($resposta->texto);
        $validacao = ContratoRoteador::validar($bruto);
        if (!$validacao['ok']) {
            (new ExecucaoRepository())->atualizar($resposta->execucaoId, ['erro' => 'saída recusada: ' . $validacao['motivo']]);
            return ['ok' => false, 'motivo' => $validacao['motivo'], 'execucao_id' => $resposta->execucaoId, 'bruto' => $bruto];
        }
        return ['ok' => true, 'plano' => $validacao['plano'], 'execucao_id' => $resposta->execucaoId, 'bruto' => $bruto];
    }

    /** Extrai o objeto JSON da resposta (tolera cerca de markdown e texto ao redor). */
    public static function extrairJson(string $texto): mixed
    {
        $texto = trim($texto);
        $ini = strpos($texto, '{');
        $fim = strrpos($texto, '}');
        if ($ini === false || $fim === false || $fim < $ini) {
            return null;
        }
        return json_decode(substr($texto, $ini, $fim - $ini + 1), true);
    }

    /** Prompt de sistema: fixo entre chamadas (o que permite o prompt caching). */
    public static function prompt(): string
    {
        return self::$promptCache ??= self::montarPrompt();
    }

    private static function montarPrompt(): string
    {
        $gravaveis = '';
        foreach (ContratoRoteador::GRAVAVEIS as $entidade) {
            $campos = [];
            foreach (Schema::gravaveis($entidade) as $chave => $def) {
                if ($def['t'] === 'fk' || str_starts_with($chave, 'utm_')) {
                    continue;
                }
                $campos[] = $def['t'] === 'enum' ? $chave . '=' . implode('|', array_keys(Schema::opcoes($def['op']))) : $chave;
            }
            $gravaveis .= "- {$entidade}: " . implode(', ', $campos) . "\n";
        }

        $consultaveis = '';
        $repo = new ConsultaRepository();
        foreach ($repo->entidades() as $entidade) {
            $campos = [];
            foreach ($repo->definicao($entidade)['campos'] as $chave => $def) {
                $campos[] = $def['t'] === 'enum' ? $chave . '=' . implode('|', array_keys(Schema::opcoes($def['op']))) : $chave;
            }
            $consultaveis .= "- {$entidade}: " . implode(', ', $campos) . "\n";
        }

        return <<<PROMPT
Você é o roteador de comandos do CRM de uma agência web brasileira. Converta a mensagem do operador em UM objeto JSON e responda SOMENTE com o JSON (sem texto, sem markdown).

Entrada (JSON): {"tela": registro aberto ou null, "ultima_ref": última entidade citada ou null, "hoje": "AAAA-MM-DD", "mensagem": "..."}.
"tela" e "ultima_ref" têm {entidade, id, nome}. Quando o operador disser "esse", "essa", "ele", "ela", "dele", "aqui" e similares, use o id de "tela" (prioridade) ou de "ultima_ref" no campo ref. Se a mensagem não citar nenhum registro e houver "tela", não repita a tela em ref: o servidor a usa por padrão.

Formatos de saída:
{"tipo":"acao","acao":A,"entidade":E,"dados":{...},"ref":{...}}
{"tipo":"acao","acao":"consultar","entidade":E,"filtro":[[campo,operador,valor]],"ordem":"campo asc|desc","limite":20}
{"tipo":"agente","slug":"...","alvo":{...},"entrada":"texto opcional"}
{"tipo":"squad","slug":"...","alvo":{...}}
{"tipo":"indefinido","pergunta":"pergunta curta para o operador"}

Ações (A): criar, atualizar, arquivar, nota, tarefa, concluir, mover_etapa, consultar, desfazer, converter_cliente.
- criar/atualizar/arquivar: entidade em empresas, contatos, negocios, atividades, tarefas. arquivar e atualizar exigem o registro alvo em ref (chave singular da entidade: empresa, contato, negocio, tarefa).
- nota: dados {"descricao":"texto"}; ref indica onde anotar (empresa, contato ou negocio).
- tarefa: dados {"titulo":"...","vencimento":"AAAA-MM-DD" ou "AAAA-MM-DD HH:MM","prioridade":...}; ref opcional.
- concluir: ref {"tarefa": título ou id}.
- mover_etapa: ref {"negocio":...}, dados {"etapa":"nome da etapa como o operador falou","valor_fechado":reais (só ao ganhar),"motivo_perda":"nome (só ao perder)","detalhe_perda":"..."}.
- converter_cliente: ref {"empresa":...}.
- desfazer: sem dados.
- ref: chaves empresa, contato, negocio, tarefa; valor = nome citado pelo operador (string) ou id vindo de tela/ultima_ref (inteiro). Ao criar negócio ou contato, ref.empresa liga à empresa; ao criar negócio, ref.contato é o contato principal. Nunca invente ids.
- Dinheiro em reais como número decimal (8 mil = 8000). Datas em AAAA-MM-DD, resolvendo "hoje", "amanhã", "sexta", "semana que vem" a partir de "hoje". Percentuais como inteiros.
- dados só aceitam os campos listados abaixo. Em dados de empresas, contatos e negocios, "origem" aceita o nome da origem (Indicação, Instagram, Google, Site…).
- Se faltar informação essencial ou a mensagem não for uma ordem para o CRM, devolva "indefinido" com uma pergunta curta.
- Para pedir execução de agente use @slug e de squad #slug apenas se a mensagem citar um; caso contrário não use esses tipos.

Campos graváveis (campo ou campo=valores permitidos):
{$gravaveis}
Consultas: operadores = != > >= < <= contem entre vazio nao_vazio ("entre" recebe [de, até]; vazio/nao_vazio não têm valor). Só use estes campos (valores de enum como listados; dinheiro em reais; datas AAAA-MM-DD):
{$consultaveis}
Exemplo: "cria negócio de 8 mil pro site da Padaria Central, contato Ana" →
{"tipo":"acao","acao":"criar","entidade":"negocios","dados":{"titulo":"Site Padaria Central","valor_estimado":8000},"ref":{"empresa":"Padaria Central","contato":"Ana"}}
PROMPT;
    }
}
