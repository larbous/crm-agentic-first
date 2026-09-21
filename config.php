<?php

declare(strict_types=1);

/**
 * Configuração da aplicação. Valores locais/sensíveis ficam em config.local.php
 * (não versionado), que sobrescreve as chaves abaixo.
 */

$padrao = [
    'app' => [
        'nome'     => 'CRM Lárbous',
        'debug'    => false,
        'fuso'     => 'America/Sao_Paulo',
        'base_url' => '', // vazio = raiz do domínio; ex.: '/crm' se estiver em subpasta
        'url_publica' => '', // URL absoluta dos links públicos (propostas/contratos); vazio = host da requisição
    ],
    'db' => [
        // CRM_DB_CAMINHO permite apontar outro arquivo (ex.: banco descartável para testes manuais).
        'caminho' => getenv('CRM_DB_CAMINHO') ?: __DIR__ . '/storage/db/crm.sqlite',
    ],
    'sessao' => [
        'nome'      => 'crm_sessao',
        'lifetime'  => 60 * 60 * 12,
    ],
    'anthropic' => [
        'api_key' => '', // definir em config.local.php
    ],
    'gemini' => [
        'api_key' => '', // definir em config.local.php; provedor secundário (failover), opcional
    ],
    'caminhos' => [
        'raiz'       => __DIR__,
        'migracoes'  => __DIR__ . '/migrations',
        'uploads'    => __DIR__ . '/storage/uploads',
        'logs'       => __DIR__ . '/storage/logs',
        'library'    => __DIR__ . '/library',
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $sobrescrita = require $local;
    if (is_array($sobrescrita)) {
        $padrao = array_replace_recursive($padrao, $sobrescrita);
    }
}

return $padrao;
