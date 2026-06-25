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
}