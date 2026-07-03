<?php

namespace Miida\Database\Syntax;

class MySqlSyntax implements SgbdSyntaxInterface
{
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string
    {
        $bancoLimpo = $this->normalizarIdentificador($banco);
        $tabelaLimpa = $this->normalizarIdentificador($tabela);

        return "`{$bancoLimpo}`.`{$tabelaLimpa}`";
    }

    public function obterDdlCriarSchema(string $schema): string
    {
        $schemaLimpo = $this->normalizarIdentificador($schema);

        if ($this->ehSchemaLogico($schemaLimpo)) {
            return '';
        }

        return "CREATE DATABASE IF NOT EXISTS `{$schemaLimpo}`;";
    }

    public function obterDdlCriarTabela(?string $schema, string $tabela, array $colunas, array $pks): string
    {
        $tabelaLimpa = $this->normalizarIdentificador($tabela);

        $linhas = [];
        foreach ($colunas as $nomeColuna => $tipoColuna) {
            if (is_int($nomeColuna) || is_numeric($nomeColuna)) {
                continue;
            }

            $nomeLimpo = $this->normalizarIdentificador((string) $nomeColuna);
            $tipoLimpo = $this->normalizarTipo((string) $tipoColuna);
            $restricao = $this->ehPk($nomeLimpo, $pks) ? ' NOT NULL' : '';

            $linhas[] = "`{$nomeLimpo}` {$tipoLimpo}{$restricao}";
        }

        if (!empty($pks)) {
            $pksNormalizadas = array_map(fn($pk) => "`" . $this->normalizarIdentificador((string) $pk) . "`", $pks);
            $linhas[] = 'PRIMARY KEY (' . implode(', ', $pksNormalizadas) . ')';
        }

        $corpo = implode(",\n    ", $linhas);

        return "CREATE TABLE IF NOT EXISTS `{$tabelaLimpa}` (\n    {$corpo}\n);";
    }

    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string
    {
        return "/* UPSERT nativo gerenciado via executarUpsert */";
    }

    public function obterSqlConcat(array $colunas): string
    {
        $colunasEscapadas = array_map(fn($c) => "CAST(`{$this->normalizarIdentificador((string) $c)}` AS CHAR)", $colunas);
        return 'CONCAT_WS(' . "'-'" . ', ' . implode(', ', $colunasEscapadas) . ')';
    }

    public function escaparColuna(string $coluna): string
    {
        return '`' . $this->normalizarIdentificador($coluna) . '`';
            $stmt->closeCursor();
            $stmt = null;
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        return 'USE `' . $this->normalizarIdentificador($banco) . '`';
    }

    public function obterTipoTextoLongo(): string
    {
        return 'LONGTEXT';
    }

    public function obterTipoDataHora(): string
    {
        return 'DATETIME';
    }

    public function obterDsn(string $host, int $port, string $banco): string
    {
        return "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
    }

    public function obterBancoAdministrativo(): string
    {
        return 'mysql';
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        $bancoLimpo = $this->normalizarIdentificador($bancoAlvo);

        return [
            'checagem' => null,
            'criacao'  => "CREATE DATABASE IF NOT EXISTS `{$bancoLimpo}`;",
        ];
    }

    public function obterNomeQualificadoTabelaControle(): string
    {
        return '`miida_controle_sincronizacao`';
    }

    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string
    {
        return 'SELECT * FROM `' . $this->normalizarIdentificador($banco) . '`.'
            . '`' . $this->normalizarIdentificador($tabela) . '`'
            . ' WHERE `' . $this->normalizarIdentificador($colunaControle) . '` > :ultima_data'
            . ' ORDER BY `' . $this->normalizarIdentificador($colunaControle) . '` ASC';
    }

    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks, array $tabelaConfig): void
    {
        $colunas = [];
        foreach ($registro as $coluna => $valor) {
            if (!is_numeric($coluna)) {
                $colunas[] = (string) $coluna;
            }
        }

        $colunasEscapadas = array_map(fn($c) => '`' . $this->normalizarIdentificador($c) . '`', $colunas);
        $placeholders = array_map(fn($c) => ':' . $this->placeholderSeguro($c), $colunas);

        $updates = [];
        foreach ($colunas as $coluna) {
            if ($this->ehPk($coluna, $pks)) {
                continue;
            }
            $colEscapada = '`' . $this->normalizarIdentificador($coluna) . '`';
            $updates[] = "{$colEscapada} = VALUES({$colEscapada})";
        }

        if (empty($updates)) {
            $sql = 'INSERT IGNORE INTO ' . $tabelaQualificada
                . ' (' . implode(', ', $colunasEscapadas) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')';
        } else {
            $sql = 'INSERT INTO ' . $tabelaQualificada
                . ' (' . implode(', ', $colunasEscapadas) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $destino->prepare($sql);
        foreach ($colunas as $coluna) {
            $stmt->bindValue(':' . $this->placeholderSeguro($coluna), $registro[$coluna]);
        }
        $stmt->execute();
    }

    public function getDDLControle(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS `miida_controle_sincronizacao` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `banco_nome` VARCHAR(150) NOT NULL,
    `tabela_nome` VARCHAR(150) NOT NULL,
    `ultima_sincronizacao` TIMESTAMP NULL,
    `status_execucao` VARCHAR(50) NOT NULL,
    `registros_afetados` INT DEFAULT 0,
    `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_miida_controle_busca` (`banco_nome`, `tabela_nome`, `status_execucao`)
);
SQL;
    }

    private function normalizarIdentificador(string $valor): string
    {
        $valor = trim($valor);
        $valor = str_replace(['"', '`', '[', ']'], '', $valor);
        return $valor;
    }

    private function ehSchemaLogico(string $schema): bool
    {
        $schema = strtolower(trim($schema));

        return $schema === '' || $schema === 'public' || $schema === 'dbo';
    }

    private function normalizarTipo(string $tipo): string
    {
        $tipo = strtoupper(trim($tipo));
        $mapa = [
            'BIT' => 'TINYINT(1)',
            'BOOLEAN' => 'TINYINT(1)',
            'DATETIME' => 'DATETIME',
            'TIMESTAMP' => 'TIMESTAMP',
            'TEXT' => 'LONGTEXT',
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