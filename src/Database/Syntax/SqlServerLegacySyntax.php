<?php

namespace Miida\Database\Syntax;

/**
 * Estratégia de Sintaxe específica para SQL Server 2005 / Legado via pdo_dblib
 */
class SqlServerLegacySyntax extends SqlServerSyntax
{
    /**
     * Retorna a DSN exata homologada para o SQL Server 2005
     */
    public function obterDsn(string $host, int $port, string $db): string
    {
        // Aplica a receita exata que funcionou no script duplaConn.php
        return "dblib:host={$host}:{$port};dbname={$db};version=7.0;charset=UTF-8";
    }

    /**
     * Sobrescrita opcional: Se o SQL Server 2005 reclamar de tipos DATETIME2 
     * ou funções modernas, você pode ajustar pequenos comportamentos DDL aqui.
     */
    public function obterDdlCriarTabela(?string $schema, string $tabela, array $colunas, array $pks): string
    {
        // 1. Chame o método da classe mãe passando exatamente os mesmos parâmetros recebidos
        $ddl = parent::obterDdlCriarTabela($schema, $tabela, $colunas, $pks);
        
        // 2. Aplica a higienização para o motor antigo: o SQL Server 2005 não conhece o tipo DATETIME2.
        // Substituímos por DATETIME comum, mantendo o ecossistema seguro.
        return str_replace('DATETIME2', 'DATETIME', $ddl);
    }
}