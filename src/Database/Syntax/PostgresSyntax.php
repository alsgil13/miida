<?php

namespace Miida\Database\Syntax;

class PostgresSyntax implements SgbdSyntaxInterface
{
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string
    {
        $schemaReal = empty($schema) ? 'public' : $schema;

        return '"' . $this->normalizarIdentificador($schemaReal) . '"."' . $this->normalizarIdentificador($tabela) . '"';
    }

    public function obterDdlCriarSchema(string $schema): string
    {
        return 'CREATE SCHEMA IF NOT EXISTS "' . $this->normalizarIdentificador($schema) . '";';
    }

    public function obterDdlCriarTabela(?string $schema, string $tabela, array $colunas, array $pks): string
    {
        $schemaReal = empty($schema) ? 'public' : $schema;

        $linhas = [];
        foreach ($colunas as $nomeColuna => $tipoColuna) {
            if (is_int($nomeColuna) || is_numeric($nomeColuna)) {
                continue;
            }

            $nomeLimpo = $this->normalizarIdentificador((string) $nomeColuna);
            $tipoLimpo = $this->normalizarTipo((string) $tipoColuna);
            $restricao = $this->ehPk($nomeLimpo, $pks) ? ' NOT NULL' : '';

            $linhas[] = '"' . $nomeLimpo . '" ' . $tipoLimpo . $restricao;
        }

        if (!empty($pks)) {
            $pksNormalizadas = array_map(fn($pk) => '"' . $this->normalizarIdentificador((string) $pk) . '"', $pks);
            $linhas[] = 'CONSTRAINT "PK_' . $this->normalizarIdentificador($tabela) . '" PRIMARY KEY (' . implode(', ', $pksNormalizadas) . ')';
        }

        $corpo = implode(",\n    ", $linhas);

        return 'CREATE TABLE IF NOT EXISTS "' . $this->normalizarIdentificador($schemaReal) . '"."' . $this->normalizarIdentificador($tabela) . '" (' . $corpo . ');';
    }

    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string
    {
        $colunasEscapadas = array_map(fn($c) => '"' . $this->normalizarIdentificador((string) $c) . '"', $colunas);
        $listaColunas = implode(', ', $colunasEscapadas);
        $valoresParametros = array_map(fn($c) => ':' . $this->placeholderSeguro((string) $c), $colunas);
        $listaValores = implode(', ', $valoresParametros);

        $sql = 'INSERT INTO ' . $tabelaQualificada . ' (' . $listaColunas . ') VALUES (' . $listaValores . ')';

        $colunasUpdate = array_values(array_filter($colunas, fn($col) => !$this->ehPk((string) $col, $chavesPrimarias)));

        if (!empty($chavesPrimarias) && !empty($colunasUpdate)) {
            $pksEscapadas = array_map(fn($c) => '"' . $this->normalizarIdentificador((string) $c) . '"', $chavesPrimarias);
            $updates = [];
            foreach ($colunasUpdate as $col) {
                $nome = $this->normalizarIdentificador((string) $col);
                $updates[] = '"' . $nome . '" = EXCLUDED."' . $nome . '"';
            }

            $sql .= ' ON CONFLICT (' . implode(', ', $pksEscapadas) . ') DO UPDATE SET ' . implode(', ', $updates);
        } elseif (!empty($chavesPrimarias)) {
            $pksEscapadas = array_map(fn($c) => '"' . $this->normalizarIdentificador((string) $c) . '"', $chavesPrimarias);
            $sql .= ' ON CONFLICT (' . implode(', ', $pksEscapadas) . ') DO NOTHING';
        }

        return $sql;
    }

    public function obterSqlConcat(array $colunas): string
    {
        $escapadas = array_map(fn($c) => 'CAST("' . $this->normalizarIdentificador((string) $c) . '" AS TEXT)', $colunas);
        return implode(' || \'-\' || ', $escapadas);
    }

    public function escaparColuna(string $coluna): string
    {
        return '"' . $this->normalizarIdentificador($coluna) . '"';
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        return '';
    }

    public function obterTipoTextoLongo(): string
    {
        return 'TEXT';
    }

    public function obterTipoDataHora(): string
    {
        return 'TIMESTAMP';
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
            'criacao'  => 'CREATE DATABASE "' . $this->normalizarIdentificador($bancoAlvo) . '"',
        ];
    }

    public function obterNomeQualificadoTabelaControle(): string
    {
        return 'public.miida_controle_sincronizacao';
    }

    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string
    {
        return 'SELECT * FROM "' . $this->normalizarIdentificador('public') . '"."' . $this->normalizarIdentificador($tabela) . '"'
            . ' WHERE "' . $this->normalizarIdentificador($colunaControle) . '" > :ultima_data';
    }

    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks, array $tabelaConfig): void
    {
        $mapeamento = $tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] ?? [];
        $tiposPorColunaDestino = [];
        foreach ($mapeamento as $colOriginal => $meta) {
            $nomeDestino = $meta['nome_destino'] ?? $colOriginal;
            $tiposPorColunaDestino[$this->normalizarIdentificador((string) $nomeDestino)] = strtoupper((string) ($meta['tipo'] ?? 'VARCHAR'));
        }

        $colunas = [];
        foreach ($registro as $col => $val) {
            if (!is_numeric($col)) {
                $colunas[] = (string) $col;
            }
        }

        $sql = $this->obterSqlUpsert($tabelaQualificada, $colunas, $pks);
        $stmt = $destino->prepare($sql);

        foreach ($registro as $col => $val) {
            if (is_numeric($col)) {
                continue;
            }

            $colunaNormalizada = $this->normalizarIdentificador((string) $col);
            $tipoConfigurado = $tiposPorColunaDestino[$colunaNormalizada] ?? 'VARCHAR';

            if ($tipoConfigurado === 'BIT' || $tipoConfigurado === 'BOOLEAN') {
                if ($val === '' || $val === null) {
                    $stmt->bindValue(':' . $this->placeholderSeguro($colunaNormalizada), null, \PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':' . $this->placeholderSeguro($colunaNormalizada), filter_var($val, FILTER_VALIDATE_BOOLEAN), \PDO::PARAM_BOOL);
                }
                continue;
            }

            if (in_array($tipoConfigurado, ['DATETIME', 'TIMESTAMP'], true) && $val === '') {
                $stmt->bindValue(':' . $this->placeholderSeguro($colunaNormalizada), null, \PDO::PARAM_NULL);
                continue;
            }

            if ($val === null) {
                $stmt->bindValue(':' . $this->placeholderSeguro($colunaNormalizada), null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $this->placeholderSeguro($colunaNormalizada), $val, \PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        $stmt->closeCursor();
        $stmt = null;
    }

    public function getDDLControle(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS public.miida_controle_sincronizacao (
    id SERIAL PRIMARY KEY,
    banco_nome VARCHAR(150) NOT NULL,
    tabela_nome VARCHAR(150) NOT NULL,
    ultima_sincronizacao TIMESTAMP NULL,
    status_execucao VARCHAR(50) NOT NULL,
    registros_afetados INT DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_miida_controle_busca
ON public.miida_controle_sincronizacao (banco_nome, tabela_nome, status_execucao);
SQL;
    }

    private function normalizarIdentificador(string $valor): string
    {
        $valor = trim($valor);
        return str_replace(['"', '`', '[', ']'], '', $valor);
    }

    private function normalizarTipo(string $tipo): string
    {
        $tipo = strtoupper(trim($tipo));
        $mapa = [
            'BIT' => 'BOOLEAN',
            'DATETIME' => 'TIMESTAMP',
            'TIMESTAMP' => 'TIMESTAMP',
            'TEXT' => 'TEXT',
            'LONGTEXT' => 'TEXT',
        ];

        return $mapa[$tipo] ?? $tipo;
    }

    private function ehPk(string $coluna, array $pks): bool
    {
        $colunaNormalizada = strtolower($this->normalizarIdentificador($coluna));
        foreach ($pks as $pk) {
            if ($colunaNormalizada === strtolower($this->normalizarIdentificador((string) $pk))) {
                return true;
            }
        }

        return false;
    }

    private function placeholderSeguro(string $coluna): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $coluna) ?? $coluna;
    }
}