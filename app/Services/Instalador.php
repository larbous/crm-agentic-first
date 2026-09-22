<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\Response;
use App\Repositories\MigracaoRepository;
use Throwable;

/**
 * Instalação automática na primeira requisição: aplica as migrações pendentes e o seed padrão sem precisar de
 * linha de comando/SSH — copiar a pasta para a hospedagem (com `storage/` gravável) já é suficiente para o CRM
 * ficar de pé; falta só criar o primeiro usuário pela tela (`InstalacaoController`, rota `/instalar`).
 *
 * Só roda uma vez: a marca `storage/db/.instalado` faz `executarSeNecessario()` sair na primeira linha em todas as
 * requisições seguintes (um único `is_file`, custo desprezível). Atualizações futuras (migrações novas de uma
 * versão mais nova do CRM) continuam pelo `php scripts/migrate.php` de sempre — este mecanismo cobre só a
 * instalação inicial, como documentado em `docs/INSTALACAO.md`.
 */
final class Instalador
{
    /** Pasta usada nos testes em vez de storage/db real (evita gravar arquivos no projeto ao rodar a suíte). */
    private static ?string $pastaTeste = null;

    public static function definirPasta(?string $pasta): void
    {
        self::$pastaTeste = $pasta;
    }

    public static function executarSeNecessario(): void
    {
        $pastaDb = self::pastaDb();
        $marca = $pastaDb . '/.instalado';
        if (is_file($marca)) {
            return;
        }
        if (!is_dir($pastaDb) && !@mkdir($pastaDb, 0775, true) && !is_dir($pastaDb)) {
            return; // sem permissão de escrita: o operador precisa rodar migrate.php/seed.php manualmente
        }

        $lock = @fopen($pastaDb . '/.instalando.lock', 'c');
        if ($lock === false) {
            return;
        }
        try {
            flock($lock, LOCK_EX);
            if (is_file($marca)) {
                return; // outra requisição concorrente já terminou enquanto esperávamos o lock
            }

            $pdo = DB::conexao();
            $migracoes = new MigracaoRepository($pdo);
            $migracoes->garantirTabela();
            $aplicadas = $migracoes->aplicadas();
            $arquivos = glob(rtrim((string) Config::obter('caminhos.migracoes'), '/\\') . '/*.sql') ?: [];
            sort($arquivos);
            foreach ($arquivos as $arquivo) {
                $nome = basename($arquivo);
                if (!in_array($nome, $aplicadas, true)) {
                    $migracoes->aplicar($nome, (string) file_get_contents($arquivo));
                }
            }
            Semeador::executar($pdo);
            file_put_contents($marca, agora());
        } catch (Throwable $e) {
            error_log('Instalador: ' . $e); // não grava a marca: a próxima requisição tenta de novo
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** A tabela usuarios está vazia? Usado para mostrar (ou não) a tela /instalar. */
    public static function precisaDoPrimeiroUsuario(): bool
    {
        try {
            return (int) DB::conexao()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
        } catch (Throwable) {
            return false; // banco ainda não migrado (ex.: storage/ sem permissão de escrita): não força a tela
        }
    }

    /** Middleware de rota: redireciona para /instalar enquanto não houver usuário algum. */
    public static function middleware(array $contexto): ?Response
    {
        return self::precisaDoPrimeiroUsuario() ? Response::redirecionar(url('/instalar')) : null;
    }

    private static function pastaDb(): string
    {
        return self::$pastaTeste ?? rtrim((string) Config::obter('caminhos.raiz'), '/\\') . '/storage/db';
    }
}
