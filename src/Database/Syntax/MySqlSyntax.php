<?php

namespace Miida\Database\Syntax;

class MySqlSyntax implements SgbdSyntaxInterface 
{
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string 
    {
        // No MySQL, o conceito de "Schema" e "Database" se misturam de forma nativa.
        // Ignoramos o schema e qualificamos diretamente como `banco`.`tabela`
        return "`{$banco}`.`{$tabela}`";
    }

    public function obterDdlCriarSchema(string $schema): string 
    {
        // MySQL não possui schemas lógicos isolados dentro do mesmo banco como o SQL Server.
        // Retornamos uma query inofensiva padrão ANSI para não quebrar o fluxo do Cloner.
        return "SELECT 1;";
    }

    public function obterDdlCriarTabela(string $schema, string $tabela, string $corpoColunas): string 
    {
        // Sintaxe nativa, limpa e performática do MySQL
        return "CREATE TABLE IF NOT EXISTS `{$tabela}` ( {$corpoColunas} )";
    }

    public function obterSqlConcat(array $colunas): string 
    {
        // Ex: CONCAT_WS('-', CAST(`Id` AS CHAR), CAST(`Codigo` AS CHAR))
        $colunasEscapadas = array_map(fn($c) => "CAST(`{$c}` AS CHAR)", $colunas);
        return "CONCAT_WS('-', " . implode(', ', $colunasEscapadas) . ")";
    }

    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string 
    {
        $listaColunas = implode(', ', array_map(fn($c) => "`{$c}`", $colunas));
        $valores = implode(', ', array_map(fn($c) => ":{$c}", $colunas));
        
        $atualizacoes = [];
        foreach ($colunas as $coluna) {
            // No MySQL, atualizamos apenas as colunas que NÃO são chaves primárias
            if (!in_array($coluna, $chavesPrimarias)) {
                $atualizacoes[] = "`{$coluna}` = VALUES(`{$coluna}`)";
            }
        }
        
        // Cláusula defensiva caso a tabela possua apenas PKs e nenhuma coluna de valor
        $stringUpdate = !empty($atualizacoes) ? implode(', ', $atualizacoes) : "`hash_versao` = VALUES(`hash_versao`)";

        return "INSERT INTO {$tabelaQualificada} ({$listaColunas}) VALUES ({$valores}) 
                ON DUPLICATE KEY UPDATE {$stringUpdate}";
    }

    public function escaparColuna(string $coluna): string
    {
        return "`{$coluna}`";
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        return "USE `{$banco}`;";
    }

    public function obterTipoTextoLongo(): string
    {
        return "LONGTEXT";
    }

    public function obterTipoDataHora(): string
    {
        return "DATETIME";
    }

    public function obterDsn(string $host, int $port, string $banco): string
    {
        return "mysql:host={$host};port={$port}" . ($banco ? ";dbname={$banco}" : "");
    }

    public function obterBancoAdministrativo(): string
    {
        return "mysql";
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        return [
            'checagem' => null, // MySQL aceita IF NOT EXISTS direto
            'criacao'  => "CREATE DATABASE IF NOT EXISTS `{$bancoAlvo}`;"
        ];
    }    
    
    public function obterNomeQualificadoTabelaControle(): string
    {
        return "`mysql`.`miida_controle_sincronizacao`";
    }

}