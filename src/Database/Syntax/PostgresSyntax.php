<?php

namespace Miida\Database\Syntax;

/**
 * MIIDA - Strategy de Sintaxe dedicada ao PostgreSQL
 */
class PostgresSyntax implements SgbdSyntaxInterface
{
    public function obterNomeQualificado(string $banco, string $schema, string $tabela): string
    {
        $schemaReal = empty($schema) ? 'public' : $schema;
        return "\"{$schemaReal}\".\"{$tabela}\"";
    }
    
    public function obterDdlCriarSchema(string $schema): string
    {
        return "CREATE SCHEMA IF NOT EXISTS \"{$schema}\";";
    }
    
    public function obterDdlCriarTabela(string $schema, string $tabela, string $corpoColunas): string
    {
        $schemaReal = empty($schema) ? 'public' : $schema;
        return "CREATE TABLE IF NOT EXISTS \"{$schemaReal}\".\"{$tabela}\" (\n    {$corpoColunas}\n);";
    }
    
    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string
    {
        $colunasEscapadas = array_map(fn($c) => "\"{$c}\"", $colunas);
        $listaColunas = implode(', ', $colunasEscapadas);
        
        $valoresParametros = array_map(fn($c) => ":{$c}", $colunas);
        $listaValores = implode(', ', $valoresParametros);
        
        $sql = "INSERT INTO {$tabelaQualificada} ({$listaColunas}) VALUES ({$listaValores})";
        
        $colunasUpdate = array_diff($colunas, $chavesPrimarias);
        
        if (!empty($chavesPrimarias) && !empty($colunasUpdate)) {
            $pksEscapadas = array_map(fn($c) => "\"{$c}\"", $chavesPrimarias);
            $listaPks = implode(', ', $pksEscapadas);
            
            $updates = [];
            foreach ($colunasUpdate as $col) {
                $updates[] = "\"{$col}\" = EXCLUDED.\"{$col}\"";
            }
            $listaUpdates = implode(', ', $updates);
            
            $sql .= " ON CONFLICT ({$listaPks}) DO UPDATE SET {$listaUpdates}";
        } else if (!empty($chavesPrimarias)) {
            $pksEscapadas = array_map(fn($c) => "\"{$c}\"", $chavesPrimarias);
            $listaPks = implode(', ', $pksEscapadas);
            $sql .= " ON CONFLICT ({$listaPks}) DO NOTHING";
        }
        
        return $sql;
    }

    public function obterSqlConcat(array $colunas): string
    {
        $escapadas = array_map(fn($c) => "CAST(\"{$c}\" AS TEXT)", $colunas);
        return implode(" || '-' || ", $escapadas);
    }

    public function escaparColuna(string $coluna): string
    {
        return "\"{$coluna}\"";
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
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
        return "pgsql:host={$host};port={$port};dbname={$banco}";
    }

    public function obterBancoAdministrativo(): string
    {
        return 'postgres';
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        return [
            'checagem' => "SELECT 1 FROM pg_database WHERE datname = '{$bancoAlvo}'",
            'criacao'  => "CREATE DATABASE \"{$bancoAlvo}\""
        ];
    }

    public function obterNomeQualificadoTabelaControle(): string
    {
        return "public.miida_controle_sincronizacao";
    }

    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string
    {
        return "SELECT * FROM \"public\".\"{$tabela}\" WHERE \"{$colunaControle}\" > :ultima_data";
    }

    /**
     * EXECUÇÃO DO UPSERT INTELIGENTE COM VERIFICAÇÃO VIA MANIFESTO JSON
     */
    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks, array $tabelaConfig): void
    {
        $mapeamento = $tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] ?? [];
        
        // Cria um mapa reverso para identificar o tipo original baseado no 'nome_destino'
        $tiposPorColunaDestino = [];
        foreach ($mapeamento as $colOriginal => $meta) {
            $nomeDestino = $meta['nome_destino'] ?? $colOriginal;
            $tiposPorColunaDestino[$nomeDestino] = strtoupper($meta['tipo'] ?? 'VARCHAR');
        }

        $colunas = array_keys($registro);
        $sql = $this->obterSqlUpsert($tabelaQualificada, $colunas, $pks);
        $stmt = $destino->prepare($sql);

        foreach ($registro as $col => $val) {
            $tipoConfigurado = $tiposPorColunaDestino[$col] ?? 'VARCHAR';

            // Tratamento explícito para tipos BOOLEAN / BIT
            if ($tipoConfigurado === 'BIT' || $tipoConfigurado === 'BOOLEAN') {
                if ($val === '' || $val === null) {
                    $stmt->bindValue(":{$col}", null, \PDO::PARAM_NULL);
                } else {
                    $boolVal = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                    $stmt->bindValue(":{$col}", $boolVal, \PDO::PARAM_BOOL);
                }
                continue;
            }

            // Tratamento para campos de data vazios
            if (in_array($tipoConfigurado, ['DATETIME', 'TIMESTAMP']) && $val === '') {
                $stmt->bindValue(":{$col}", null, \PDO::PARAM_NULL);
                continue;
            }

            // Fallback padrão para strings, inteiros, etc.
            if ($val === null) {
                $stmt->bindValue(":{$col}", null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(":{$col}", $val, \PDO::PARAM_STR);
            }
        }
        
        $stmt->execute();
    }
}