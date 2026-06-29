<?php

namespace Miida\Database\Syntax;

class PostgresSyntax implements SgbdSyntaxInterface 
{
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string 
    {
        // No Postgres, o banco (database) fica mapeado na string de conexão DSN.
        // A qualificação de tabelas nas queries utiliza obrigatoriamente "schema"."tabela".
        $schema = $schema ?? 'public';
        return "\"{$schema}\".\"{$tabela}\"";
    }

    public function obterDdlCriarSchema(string $schema): string 
    {
        return "CREATE SCHEMA IF NOT EXISTS \"{$schema}\"";
    }

    public function obterDdlCriarTabela(string $schema, string $tabela, string $corpoColunas): string 
    {
        $schema = $schema ?? 'public';
        return "CREATE TABLE IF NOT EXISTS \"{$schema}\".\"{$tabela}\" ( {$corpoColunas} )";
    }

    public function obterSqlConcat(array $colunas): string 
    {
        // Concatena múltiplas chaves usando o padrão TEXT e separador hífen para órfãos
        $colunasEscapadas = array_map(fn($c) => "CAST(\"{$c}\" AS TEXT)", $colunas);
        return "CONCAT_WS('-', " . implode(', ', $colunasEscapadas) . ")";
    }

    /**
     * ASSINATURA REVISADA: Agora perfeitamente compatível com o contrato da interface (3 parâmetros)
     */
    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string 
    {
        $listaColunas = implode(', ', array_map(fn($c) => "\"{$c}\"", $colunas));
        $valores = implode(', ', array_map(fn($c) => ":{$c}", $colunas));
        
        $atualizacoes = [];
        foreach ($colunas as $coluna) {
            // No PostgreSQL, atualizamos apenas colunas que não pertencem à Chave Primária
            if (!in_array($coluna, $chavesPrimarias)) {
                $atualizacoes[] = "\"{$coluna}\" = EXCLUDED.\"{$coluna}\"";
            }
        }
        
        $pksString = implode(', ', array_map(fn($pk) => "\"{$pk}\"", $chavesPrimarias));
        $stringUpdate = !empty($atualizacoes) ? "DO UPDATE SET " . implode(', ', $atualizacoes) : "DO NOTHING";

        return "INSERT INTO {$tabelaQualificada} ({$listaColunas}) VALUES ({$valores}) 
                ON CONFLICT ({$pksString}) {$stringUpdate}";
    }

    public function escaparColuna(string $coluna): string
    {
        return "\"{$coluna}\"";
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        // PostgreSQL não aceita o comando 'USE banco;'. A separação é feita via DSN na conexão.
        // Retornamos vazio para que o $destino->exec() no Cloner ignore silenciosamente de forma segura.
        return "";
    }

    public function obterTipoTextoLongo(): string
    {
        return "TEXT";
    }

    public function obterTipoDataHora(): string
    {
        return "TIMESTAMP";
    }

    public function obterDsn(string $host, int $port, string $banco): string
    {
        return "pgsql:host={$host};port={$port}" . ($banco ? ";dbname={$banco}" : "");
    }

    public function obterBancoAdministrativo(): string
    {
        return "postgres";
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        return [
            'checagem' => "SELECT 1 FROM pg_database WHERE datname = '{$bancoAlvo}'",
            'criacao'  => "CREATE DATABASE \"{$bancoAlvo}\";"
        ];
    }    

    public function obterNomeQualificadoTabelaControle(): string
    {
        return "\"postgres\".\"public\".\"miida_controle_sincronizacao\"";
    }

    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string
    {
        return "SELECT * FROM \"{$banco}\".\"public\".\"{$tabela}\" WHERE \"{$colunaControle}\" > :ultima_data ORDER BY \"{$colunaControle}\" ASC";
    }

    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks): void
    {
        $colunas = array_keys($registro);
        $listaColunas = '"' . implode('", "', $colunas) . '"';
        $placeholders = ':' . implode(', :', $colunas);

        $updates = [];
        foreach ($colunas as $coluna) {
            if (!in_array($coluna, $pks)) {
                $updates[] = "\"{$coluna}\" = EXCLUDED.\"{$coluna}\"";
            }
        }

        $listaPks = '"' . implode('", "', $pks) . '"';

        $sql = "INSERT INTO {$tabelaQualificada} ({$listaColunas}) VALUES ({$placeholders}) 
                ON CONFLICT ({$listaPks}) DO UPDATE SET " . implode(', ', $updates);

        $stmt = $destino->prepare($sql);
        $stmt->execute($registro);
    }
}