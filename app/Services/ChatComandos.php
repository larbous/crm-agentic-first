<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgenteRepository;
use App\Repositories\BuscaRepository;

/** Comandos "/" do chat (SPEC §3.2): executam direto no servidor, sem IA. Escritas passam pelo ActionExecutor (origem "humano"). */
final class ChatComandos
{
    private const AJUDA = <<<'TXT'
Comandos disponíveis:
/nota <texto> — anota no registro aberto (ou o último citado)
/tarefa <texto> [dd/mm] [hh:mm] — cria tarefa vinculada ao registro em contexto
/buscar <termo> — busca empresas, contatos e negócios
/concluir <id> — conclui uma tarefa
/desfazer — reverte a última ação registrada
/agentes · /squads — lista os disponíveis
@agente [nome do registro] — executa um agente (ex.: @pesquisador Padaria Central)
Ou escreva em linguagem natural, por exemplo: "cria negócio de 8 mil pro site da Padaria Central, contato Ana".
TXT;

    public function __construct(private readonly ActionExecutor $executor = new ActionExecutor())
    {
    }

    /**
     * @param array{entidade:string,id:int,nome:string}|null $tela
     * @param array{entidade:string,id:int,nome:string}|null $ultimaRef
     * @return array{conteudo:string,payload:array,ultima_ref:?array}
     */
    public function executar(string $texto, ?array $tela, ?array $ultimaRef): array
    {
        $partes = preg_split('/\s+/', trim($texto), 2);
        $comando = mb_strtolower(ltrim((string) $partes[0], '/'));
        $resto = trim((string) ($partes[1] ?? ''));

        return match ($comando) {
            'ajuda'   => RespostaChat::texto(self::AJUDA),
            'nota'    => $this->nota($resto, $tela ?? $ultimaRef),
            'tarefa'  => $this->tarefa($resto, $tela ?? $ultimaRef),
            'buscar'  => $this->buscar($resto),
            'concluir' => $this->concluir($resto),
            'desfazer' => $this->desfazer(),
            'agentes' => $this->agentes(),
            'squads'  => RespostaChat::texto('Ainda não há squads cadastrados. Eles chegam na Fase 6.'),
            default   => RespostaChat::erro("Comando desconhecido: /{$comando}. Use /ajuda."),
        };
    }

    private function agentes(): array
    {
        $ativos = (new AgenteRepository())->todos(true);
        if ($ativos === []) {
            return RespostaChat::texto('Não há agentes ativos. Cadastre ou importe em Agentes.');
        }
        $linhas = array_map(static fn (array $a): string => '@' . $a['slug'] . ' — ' . ($a['descricao'] !== '' ? $a['descricao'] : $a['nome'])
            . ' (' . ($a['def']['entrada'] === 'nenhuma' ? 'sem registro' : mb_strtolower(Schema::entidade($a['def']['entrada'])['plural'])) . ')', $ativos);
        return RespostaChat::texto("Agentes disponíveis:\n" . implode("\n", $linhas));
    }

    private function nota(string $texto, ?array $contexto): array
    {
        if ($texto === '') {
            return RespostaChat::erro('Informe o texto: /nota <texto>.');
        }
        if ($contexto === null) {
            return RespostaChat::erro('Abra uma empresa, contato ou negócio (ou cite um antes) para usar /nota.');
        }
        $r = $this->executor->criar('atividades', ['tipo' => 'nota', 'descricao' => $texto] + $this->vinculo($contexto), 'humano');
        return $r->ok
            ? $this->comPai(RespostaChat::acao('nota', 'atividades', $r, ['pai' => $contexto]), $contexto)
            : RespostaChat::erro('Não foi possível registrar a nota: ' . $r->mensagem);
    }

    private function tarefa(string $texto, ?array $contexto): array
    {
        if ($texto === '') {
            return RespostaChat::erro('Informe o texto: /tarefa <texto> [dd/mm] [hh:mm].');
        }

        // Data (dd/mm ou dd/mm/aaaa) e hora (hh:mm) opcionais, no final do texto, em qualquer ordem.
        $partes = preg_split('/\s+/', $texto);
        $data = $hora = null;
        while (count($partes) > 1) {
            $ultimo = end($partes);
            if ($hora === null && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $ultimo, $m) === 1) {
                $hora = sprintf('%02d:%02d', $m[1], $m[2]);
            } elseif ($data === null && preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$#', $ultimo, $m) === 1) {
                $data = $this->resolverData((int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null);
                if ($data === null) {
                    return RespostaChat::erro("Data inválida: {$ultimo}.");
                }
            } else {
                break;
            }
            array_pop($partes);
        }
        $dados = ['titulo' => implode(' ', $partes)];
        if ($data !== null || $hora !== null) {
            $dados['vencimento'] = ($data ?? hoje()) . ($hora !== null ? ' ' . $hora : '');
        }

        $r = $this->executor->criar('tarefas', $dados + ($contexto !== null ? $this->vinculo($contexto) : []), 'humano');
        return $r->ok
            ? $this->comPai(RespostaChat::acao('criar', 'tarefas', $r, ['pai' => $contexto]), $contexto)
            : RespostaChat::erro('Não foi possível criar a tarefa: ' . $r->mensagem);
    }

    private function buscar(string $termo): array
    {
        if ($termo === '') {
            return RespostaChat::erro('Informe o termo: /buscar <termo>.');
        }
        $achados = (new BuscaRepository())->buscar($termo, 8);
        $grupos = [];
        $total = 0;
        foreach (['empresas' => 'Empresas', 'contatos' => 'Contatos', 'negocios' => 'Negócios'] as $chave => $rotulo) {
            $itens = [];
            foreach ($achados[$chave] as $l) {
                $itens[] = ['titulo' => (string) $l['titulo'], 'subtitulo' => (string) ($l['subtitulo'] ?? ''), 'link' => "/{$chave}/" . (int) $l['id']];
            }
            if ($itens !== []) {
                $grupos[] = ['rotulo' => $rotulo, 'itens' => $itens];
                $total += count($itens);
            }
        }
        return [
            'conteudo'   => $total === 0 ? "Nada encontrado para \"{$termo}\"." : "{$total} resultado(s) para \"{$termo}\"",
            'payload'    => ['tipo' => 'busca', 'grupos' => $grupos],
            'ultima_ref' => null,
        ];
    }

    private function concluir(string $id): array
    {
        if (preg_match('/^#?(\d+)$/', $id, $m) !== 1) {
            return RespostaChat::erro('Informe o id da tarefa: /concluir <id>.');
        }
        $r = $this->executor->concluirTarefa((int) $m[1], 'humano');
        return $r->ok
            ? RespostaChat::acao('concluir', 'tarefas', $r)
            : RespostaChat::erro('Não foi possível concluir: ' . $r->mensagem);
    }

    private function desfazer(): array
    {
        $r = $this->executor->desfazer(null, 'humano');
        return $r->ok ? RespostaChat::desfeito($r->mensagem) : RespostaChat::erro($r->mensagem);
    }

    /** Campo de vínculo (empresa_id, contato_id ou negocio_id) para o registro em contexto. */
    private function vinculo(array $contexto): array
    {
        return match ($contexto['entidade']) {
            'empresas' => ['empresa_id' => $contexto['id']],
            'contatos' => ['contato_id' => $contexto['id']],
            'negocios' => ['negocio_id' => $contexto['id']],
            default    => [],
        };
    }

    private function comPai(array $resposta, ?array $pai): array
    {
        $resposta['ultima_ref'] = $pai;
        return $resposta;
    }

    /** dd/mm sem ano: ano corrente, ou o próximo se a data já passou. Null se inexistente. */
    private function resolverData(int $dia, int $mes, ?int $ano): ?string
    {
        $hoje = hoje();
        $anoUsado = $ano ?? (int) substr($hoje, 0, 4);
        if (!checkdate($mes, $dia, $anoUsado)) {
            return null;
        }
        $iso = sprintf('%04d-%02d-%02d', $anoUsado, $mes, $dia);
        if ($ano === null && $iso < $hoje) {
            $iso = sprintf('%04d-%02d-%02d', $anoUsado + 1, $mes, $dia);
            return checkdate($mes, $dia, $anoUsado + 1) ? $iso : null;
        }
        return $iso;
    }
}
