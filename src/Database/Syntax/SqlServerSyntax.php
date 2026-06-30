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
    if (empty($registro)) {
        return;
    }

    // Identifica a chave primária mapeada
    $pkRealDestino = $pks[0] ?? 'id';
    $valorPk = $registro[$pkRealDestino] ?? reset($registro);

    $tokenLimpo = str_replace(['[', ']', ' ', '.', '-'], '_', $pkRealDestino);
    
    $whereConds = ["[{$pkRealDestino}] = :pk_{$tokenLimpo}"];

    // CORREÇÃO DA NAVALHA DE OCCAM: Usar COUNT(*) garante um retorno numérico absoluto (0 ou mais)
    $sqlCheck = "SELECT COUNT(*) FROM {$tabelaQualificada} WHERE " . $whereConds[0];
    $stmtCheck = $destino->prepare($sqlCheck);
    $stmtCheck->bindValue(":pk_{$tokenLimpo}", $valorPk);
    $stmtCheck->execute();
    
    // Força a conversão explicitamente para inteiro
    $totalEncontrado = (int)$stmtCheck->fetchColumn();

    // Se o contador for maior que zero, a linha realmente existe -> UPDATE
    if ($totalEncontrado > 0) {
        $updateFields = [];
        $updateParams = [":pk_{$tokenLimpo}" => $valorPk];

        foreach ($registro as $colunaReg => $valorReg) {
            if ($colunaReg === $pkRealDestino || is_numeric($colunaReg)) {
                continue;
            }
            $tkn = str_replace(['[', ']', ' ', '.', '-'], '_', $colunaReg);
            $updateFields[] = "[{$colunaReg}] = :up_{$tkn}";
            $updateParams[":up_{$tkn}"] = $valorReg;
        }

        if (empty($updateFields)) return;

        $sqlUpdate = "UPDATE {$tabelaQualificada} SET " . implode(', ', $updateFields) . " WHERE " . $whereConds[0];
        $stmtUpdate = $destino->prepare($sqlUpdate);
        foreach ($updateParams as $token => $valor) {
            $stmtUpdate->bindValue($token, $valor);
        }
        $stmtUpdate->execute();

    } else {
        // Se for zero, a linha não existe -> FORÇA O INSERT REAL
        $insertCols = [];
        $insertTokens = [];
        $insertParams = [];

        foreach ($registro as $colunaReg => $valorReg) {
            if (is_numeric($colunaReg)) {
                continue;
            }
            $tkn = str_replace(['[', ']', ' ', '.', '-'], '_', $colunaReg);
            $insertCols[] = "[{$colunaReg}]";
            $insertTokens[] = ":ins_{$tkn}";
            $insertParams[":ins_{$tkn}"] = $valorReg;
        }

        $sqlInsert = "INSERT INTO {$tabelaQualificada} (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertTokens) . ")";
        $stmtInsert = $destino->prepare($sqlInsert);
        foreach ($insertParams as $token => $valor) {
            $stmtInsert->bindValue($token, $valor);
        }
        $stmtInsert->execute();
    }
}
}