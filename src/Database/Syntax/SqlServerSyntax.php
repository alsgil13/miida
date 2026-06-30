<?php

namespace Miida\Database\Syntax;

class SqlServerSyntax implements SgbdSyntaxInterface 
{
    public function obterNomeQualificado(string $banco, string $schema, string $tabela): string
    {
        // Captura a regra de normalização que estava poluindo o core do processador/cloner
        if (strtolower($schema) === 'public' || empty($schema)) {
            $schema = 'dbo';
        }

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


    public function escaparColuna(string $coluna): string
    {
        return "[{$coluna}]";
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        // No SQL Server, o comando USE muda o contexto da conexão física do PDO
        return "USE [{$banco}];";
    }

    public function obterTipoTextoLongo(): string
    {
        // TEXT está depreciado no SQL Server; o padrão moderno recomendado é VARCHAR(MAX)
        return "VARCHAR(MAX)";
    }

    public function obterTipoDataHora(): string
    {
        return "DATETIME";
    }

    public function obterDsn(string $host, int $port, string $banco): string
    {
        return "dblib:host={$host};port={$port}" . ($banco ? ";dbname={$banco}" : "");
    }

    public function obterBancoAdministrativo(): string
    {
        return "master";
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        return [
            'checagem' => "SELECT database_id FROM sys.databases WHERE name = '{$bancoAlvo}'",
            'criacao'  => "CREATE DATABASE [{$bancoAlvo}];"
        ];
    }    

    public function obterNomeQualificadoTabelaControle(): string
    {
        return "[master].[dbo].[miida_controle_sincronizacao]";
    }

    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string
    {
        // SQL Server usa colchetes e paginação ou ordenação compatível
        return "SELECT * FROM [{$banco}].[dbo].[{$tabela}] WHERE [{$colunaControle}] > :ultima_data ORDER BY [{$colunaControle}] ASC";
    }

    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks): void
{
    $colunas = array_keys($registro);

    // 1. QUERY DE SELEÇÃO (CHECK)
    $whereConds = [];
    $whereParams = [];
    foreach ($pks as $pk) {
        if (array_key_exists($pk, $registro)) {
            $whereConds[] = "[{$pk}] = :pk_{$pk}";
            $whereParams[":pk_{$pk}"] = $registro[$pk];
        }
    }

    $sqlCheck = "SELECT 1 FROM {$tabelaQualificada} WHERE " . implode(' AND ', $whereConds);
    $stmtCheck = $destino->prepare($sqlCheck);
    
    // Faz o bind forçado dos parâmetros do WHERE
    foreach ($whereParams as $token => $valor) {
        $stmtCheck->bindValue($token, $valor);
    }
    $stmtCheck->execute();
    $existe = $stmtCheck->fetchColumn();

    // 2. DECISÃO ENTRE UPDATE OU INSERT
    if ($existe) {
        $updateFields = [];
        $updateParams = [];
        
        foreach ($colunas as $coluna) {
            if (!in_array($coluna, $pks)) {
                $updateFields[] = "[{$coluna}] = :up_{$coluna}";
                $updateParams[":up_{$coluna}"] = $registro[$coluna];
            }
        }

        if (empty($updateFields)) {
            return; // Nada para atualizar além da PK
        }

        // Adiciona os mesmos parâmetros do WHERE para o UPDATE encontrar o registro
        foreach ($pks as $pk) {
            $updateParams[":pk_{$pk}"] = $registro[$pk];
        }

        $sqlUpdate = "UPDATE {$tabelaQualificada} SET " . implode(', ', $updateFields) . " WHERE " . implode(' AND ', $whereConds);
        $stmtUpdate = $destino->prepare($sqlUpdate);
        
        // Faz o bind forçado limpando qualquer token fantasma
        foreach ($updateParams as $token => $valor) {
            $stmtUpdate->bindValue($token, $valor);
        }
        $stmtUpdate->execute();

    } else {
        $insertCols = [];
        $insertTokens = [];
        $insertParams = [];

        foreach ($colunas as $coluna) {
            $insertCols[] = "[{$coluna}]";
            $insertTokens[] = ":ins_{$coluna}";
            $insertParams[":ins_{$coluna}"] = $registro[$coluna];
        }

        $sqlInsert = "INSERT INTO {$tabelaQualificada} (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertTokens) . ")";
        $stmtInsert = $destino->prepare($sqlInsert);
        
        // Faz o bind forçado garantindo paridade total 1:1
        foreach ($insertParams as $token => $valor) {
            $stmtInsert->bindValue($token, $valor);
        }
        $stmtInsert->execute();
    }
}
}