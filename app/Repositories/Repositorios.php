<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;

/** Registro de repositórios por nome de entidade (as mesmas chaves do Schema). */
final class Repositorios
{
    /** @var array<string,BaseRepository> */
    private static array $instancias = [];

    public static function para(string $entidade): BaseRepository
    {
        return self::$instancias[$entidade] ??= match ($entidade) {
            'empresas'      => new EmpresaRepository(),
            'contatos'      => new ContatoRepository(),
            'negocios'      => new NegocioRepository(),
            'atividades'    => new AtividadeRepository(),
            'tarefas'       => new TarefaRepository(),
            'anexos'        => new AnexoRepository(),
            'tags'          => new TagRepository(),
            'origens'       => new SimplesRepository('origens'),
            'motivos_perda' => new SimplesRepository('motivos_perda'),
            'pipelines'     => new PipelineRepository(),
            'etapas'        => new EtapaRepository(),
            'servicos'      => new ServicoRepository(),
            'modelos_documento' => new ModeloRepository(),
            'contrato_tipos' => new SimplesRepository('contrato_tipos'),
            'propostas'     => new PropostaRepository(),
            'contratos'     => new ContratoRepository(),
            'campos_extras_def' => new CampoExtraRepository(),
            'metas'         => new MetaRepository(),
            'areas'         => new SimplesRepository('areas'),
            'chamados'      => new ChamadoRepository(),
            default        => throw new InvalidArgumentException("Entidade desconhecida: {$entidade}"),
        };
    }

    public static function empresas(): EmpresaRepository { return self::para('empresas'); }
    public static function contatos(): ContatoRepository { return self::para('contatos'); }
    public static function negocios(): NegocioRepository { return self::para('negocios'); }
    public static function atividades(): AtividadeRepository { return self::para('atividades'); }
    public static function tarefas(): TarefaRepository { return self::para('tarefas'); }
    public static function chamados(): ChamadoRepository { return self::para('chamados'); }
    public static function anexos(): AnexoRepository { return self::para('anexos'); }
    public static function tags(): TagRepository { return self::para('tags'); }
    public static function pipelines(): PipelineRepository { return self::para('pipelines'); }
    public static function etapas(): EtapaRepository { return self::para('etapas'); }
    public static function servicos(): ServicoRepository { return self::para('servicos'); }
    public static function modelos(): ModeloRepository { return self::para('modelos_documento'); }
    public static function propostas(): PropostaRepository { return self::para('propostas'); }
    public static function contratos(): ContratoRepository { return self::para('contratos'); }
    public static function camposExtras(): CampoExtraRepository { return self::para('campos_extras_def'); }
    public static function metas(): MetaRepository { return self::para('metas'); }
}
