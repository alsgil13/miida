<?php

namespace Miida\Database\Syntax;

class SqlServerSyntax implements SgbdSyntaxInterface 
{
    public function obterNomeQualificado(string $banco, string $schema, string $tabela): string
    {
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

    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string
    {
        // Método exigido pela interface (utilizado caso queira gerar apenas a string SQL crua do Merge)
        return "/* UPSERT nativo gerenciado via método executarUpsert direta no PDO */";
    }

    public function obterSqlConcat(array $colunas): string 
    {
        return implode(" + '-' + ", array_map(fn($c) => "CAST([{$c}] AS VARCHAR(64))", $colunas));
    }

    public function escaparColuna(string $coluna): string
    {
        return "[{$coluna}]";
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        return "USE [{$banco}]";
    }

    public function obterTipoTextoLongo(): string
    {
        return "VARCHAR(MAX)";
    }

    public function obterTipoDataHora(): string
    {
        return "DATETIME";
    }

    public function obterDsn(string $host, int $port, string $banco): string
    {
        return "dblib:host={$host}:{$port};dbname=master;charset=UTF-8";
    }

    public function obterBancoAdministrativo(): string
    {
        return "master";
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        return [
            'checagem' => "SELECT name FROM sys.databases WHERE name = '{$bancoAlvo}'",
            'criacao'  => "CREATE DATABASE [{$bancoAlvo}]"
        ];
    }

    public function obterNomeQualificadoTabelaControle(): string
    {
        return "[master].[dbo].[miida_controle_sincronizacao]";
    }

    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string
    {
        return "SELECT * FROM [{$banco}].[dbo].[{$tabela}] WHERE [{$colunaControle}] > :ultima_data";
    }

    /**
     * Executa a estratégia de UPSERT (Merge/Sincronização) nativa para SQL Server
     * SUPORTA PERFEITAMENTE CHAVES PRIMÁRIAS COMPOSTAS (MULTI-PK)
     */
    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks): void
    {
        if (empty($pks)) {
            throw new \Exception("Erro de Sintaxe: Nao e possivel realizar UPSERT sem chaves primarias.");
        }

        // 1. Constrói a cláusula WHERE combinando TODAS as PKs com AND
        $whereConds = [];
        $whereParams = [];
        foreach ($pks as $pk) {
            $tkn = str_replace(['[', ']', ' ', '.', '-'], '_', $pk);
            $whereConds[] = "[{$pk}] = :whr_{$tkn}";
            $whereParams[":whr_{$tkn}"] = $registro[$pk] ?? null;
        }

        $sqlCheck = "SELECT COUNT(*) FROM {$tabelaQualificada} WHERE " . implode(' AND ', $whereConds);
        $stmtCheck = $destino->prepare($sqlCheck);
        foreach ($whereParams as $token => $valor) {
            $stmtCheck->bindValue($token, $valor);
        }
        $stmtCheck->execute();
        $existe = (int)$stmtCheck->fetchColumn();

        if ($existe > 0) {
            // Se o registro composto existe -> Executa UPDATE nas colunas de dados
            $updateFields = [];
            $updateParams = [];

            // Adiciona os parâmetros do WHERE primeiro para não haver colisão de tokens
            foreach ($whereParams as $token => $valor) {
                $updateParams[$token] = $valor;
            }

            foreach ($registro as $colunaReg => $valorReg) {
                if (is_numeric($colunaReg) || in_array($colunaReg, $pks)) {
                    continue; // Ignora chaves numéricas do PDO e as colunas da PK
                }
                $tkn = str_replace(['[', ']', ' ', '.', '-'], '_', $colunaReg);
                $updateFields[] = "[{$colunaReg}] = :up_{$tkn}";
                $updateParams[":up_{$tkn}"] = $valorReg;
            }

            if (!empty($updateFields)) {
                $sqlUpdate = "UPDATE {$tabelaQualificada} SET " . implode(', ', $updateFields) . " WHERE " . implode(' AND ', $whereConds);
                $stmtUpdate = $destino->prepare($sqlUpdate);
                foreach ($updateParams as $token => $valor) {
                    $stmtUpdate->bindValue($token, $valor);
                }
                $stmtUpdate->execute();
            }
        } else {
            // Se não existe -> Executa INSERT limpo com todas as colunas
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