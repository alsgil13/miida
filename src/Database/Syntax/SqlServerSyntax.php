<?php

namespace Miida\Database\Syntax;

class SqlServerSyntax implements SgbdSyntaxInterface 
{
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string 
    {
        $schema = $schema ?? 'dbo';
        return "[{$banco}].[{$schema}].[{$tabela}]";
    }

    public function obterDdlCriarSchema(string $schema): string 
    {
        return "IF NOT EXISTS (SELECT * FROM sys.schemas WHERE name = '{$schema}') BEGIN EXEC('CREATE SCHEMA {$schema}') END";
    }

    public function obterDdlCriarTabela(string $schema, string $tabela, string $corpoColunas): string 
    {
        $schema = $schema ?? 'dbo';
        return "IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('[{$schema}].[{$tabela}]') AND type = 'U')
                BEGIN
                    CREATE TABLE [{$schema}].[{$tabela}] ( {$corpoColunas} )
                END";
    }

    public function obterSqlConcat(array $colunas): string 
    {
        return implode(" + '-' + ", array_map(fn($c) => "CAST([{$c}] AS VARCHAR(64))", $colunas));
    }

    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string 
    {
        $camposMergeSource = implode(', ', array_map(fn($c) => ":{$c} AS [{$c}]", $colunas));
        $stringJoinMerge = implode(' AND ', array_map(fn($pk) => "t.[{$pk}] = s.[{$pk}]", $chavesPrimarias));
        
        $camposUpdate = [];
        foreach ($colunas as $coluna) {
            if (!in_array($coluna, $chavesPrimarias)) {
                $camposUpdate[] = "t.[{$coluna}] = s.[{$coluna}]";
            }
        }
        
        $stringUpdate = !empty($camposUpdate) ? "UPDATE SET " . implode(', ', $camposUpdate) : "UPDATE SET t.[hash_versao] = s.[hash_versao]";
        $stringInsertColunas = implode(', ', array_map(fn($c) => "[{$c}]", $colunas));
        $stringInsertValores = implode(', ', array_map(fn($c) => "s.[{$c}]", $colunas));

        return "MERGE {$tabelaQualificada} AS t
                USING (SELECT {$camposMergeSource}) AS s
                ON ({$stringJoinMerge})
                WHEN MATCHED THEN {$stringUpdate}
                WHEN NOT MATCHED THEN INSERT ({$stringInsertColunas}) VALUES ({$stringInsertValores});";
    }
}