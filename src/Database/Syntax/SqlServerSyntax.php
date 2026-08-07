<?php

namespace Miida\Database\Syntax;

class SqlServerSyntax implements SgbdSyntaxInterface
{
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string
    {
        $schemaReal = $this->normalizarIdentificador(empty($schema) || strtolower($schema) === 'public' ? 'dbo' : $schema);

        return '[' . $this->normalizarIdentificador($banco) . '].[' . $schemaReal . '].[' . $this->normalizarIdentificador($tabela) . ']';
    }

    public function obterDdlCriarSchema(string $schema): string
    {
        $schemaLimpo = $this->normalizarIdentificador(empty($schema) ? 'dbo' : $schema);

        return "SET NOCOUNT ON; IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = '{$schemaLimpo}') BEGIN EXEC('CREATE SCHEMA [{$schemaLimpo}]') END;";
    }

    public function obterDdlCriarTabela(?string $schema, string $tabela, array $colunas, array $pks): string
    {
        $schemaLimpo = $this->normalizarIdentificador(empty($schema) ? 'dbo' : $schema);
        $tabelaLimpa = $this->normalizarIdentificador($tabela);

        $linhas = [];
        foreach ($colunas as $nomeColuna => $tipoColuna) {
            if (is_int($nomeColuna) || is_numeric($nomeColuna)) {
                continue;
            }

            $nomeLimpo = $this->normalizarIdentificador((string) $nomeColuna);
            $tipoLimpo = $this->normalizarTipo((string) $tipoColuna);
            $restricao = $this->ehPk($nomeLimpo, $pks) ? ' NOT NULL' : '';

            $linhas[] = '[' . $nomeLimpo . '] ' . $tipoLimpo . $restricao;
        }

        if (!empty($pks)) {
            $pksNormalizadas = array_map(fn($pk) => '[' . $this->normalizarIdentificador((string) $pk) . ']', $pks);
            $linhas[] = 'CONSTRAINT [PK_' . $this->normalizarIdentificador($tabela) . '] PRIMARY KEY (' . implode(', ', $pksNormalizadas) . ')';
        }

        $corpo = implode(",\n        ", $linhas);
        $objectId = '[dbo].[' . $tabelaLimpa . ']';

        return "SET NOCOUNT ON; IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('[{$schemaLimpo}].[{$tabelaLimpa}]') AND type = 'U') BEGIN CREATE TABLE [{$schemaLimpo}].[{$tabelaLimpa}] (\n        {$corpo}\n    ); END;";
    }

    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string
    {
        return '/* UPSERT nativo gerenciado via executarUpsert */';
    }

    public function obterSqlConcat(array $colunas): string
    {
        return implode(' + \'-\' + ', array_map(fn($c) => 'CAST([' . $this->normalizarIdentificador((string) $c) . '] AS VARCHAR(64))', $colunas));
    }

    public function escaparColuna(string $coluna): string
    {
        return '[' . $this->normalizarIdentificador($coluna) . ']';
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        return 'USE [' . $this->normalizarIdentificador($banco) . ']';
    }

    public function obterTipoTextoLongo(): string
    {
        return 'VARCHAR(MAX)';
    }

    public function obterTipoDataHora(): string
    {
        return 'DATETIME2(6)';
    }

    public function obterDsn(string $host, int $port, string $banco): string
    {
        return "dblib:host={$host}:{$port};dbname={$banco};charset=UTF-8";
    }

    public function obterBancoAdministrativo(): string
    {
        return 'master';
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        return [
            'checagem' => "SELECT name FROM sys.databases WHERE name = '{$bancoAlvo}'",
            'criacao'  => 'CREATE DATABASE [' . $this->normalizarIdentificador($bancoAlvo) . ']',
        ];
    }

    // public function obterNomeQualificadoTabelaControle(string $schema): string
    // {
    //         $schemaLimpo = $this->normalizarIdentificador($schema);
    //         return "`{$schemaLimpo}`.`miida_controle_sincronizacao`";
    // }

    // public function obterSqlSelecaoIncremental(string $banco, ?string $schema, string $tabela, string $colunaControle): string
    // {
    //     return 'SELECT * FROM [' . $this->normalizarIdentificador($banco) . '].[dbo].[' . $this->normalizarIdentificador($tabela) . ']' .
    //         ' WHERE [' . $this->normalizarIdentificador($colunaControle) . '] > :ultima_data';
    // }
    // public function obterSqlSelecaoIncremental(string $banco, ?string $schema, string $tabela, string $colunaControle): string
    // {
    //     $schemaReal = empty($schema) ? 'public' : $schema;

    //     return 'SELECT * FROM `'
    //         . $this->normalizarIdentificador($banco) . '`.`'
    //         . $this->normalizarIdentificador($schemaReal) . '`.`'
    //         . $this->normalizarIdentificador($tabela) . '`'
    //         . ' WHERE `'
    //         . $this->normalizarIdentificador($colunaControle) . '` > :ultima_data'
    //         . ' ORDER BY `'
    //         . $this->normalizarIdentificador($colunaControle) . '` ASC';
    // }
    public function obterNomeQualificadoTabelaControle(string $schema): string
    {
        $schemaLimpo = $this->normalizarIdentificador(empty($schema) || strtolower($schema) === 'public' ? 'dbo' : $schema);
        return "[{$schemaLimpo}].[miida_controle_sincronizacao]";
    }

    public function obterSqlSelecaoIncremental(string $banco, ?string $schema, string $tabela, string $colunaControle): string
    {
        $schemaReal = empty($schema) || strtolower($schema) === 'public' ? 'dbo' : $schema;

        return 'SELECT * FROM ['
            . $this->normalizarIdentificador($banco) . '].['
            . $this->normalizarIdentificador($schemaReal) . '].['
            . $this->normalizarIdentificador($tabela) . ']'
            . ' WHERE ['
            . $this->normalizarIdentificador($colunaControle) . '] > :ultima_data';
    }

    // public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks, array $tabelaConfig): void
    // {
    //     if (empty($pks)) {
    //         throw new \Exception('Erro de Sintaxe: Nao e possivel realizar UPSERT sem chaves primarias.');
    //     }

    //     $colunas = [];
    //     foreach ($registro as $coluna => $valor) {
    //         if (!is_numeric($coluna)) {
    //             $colunas[] = (string) $coluna;
    //         }
    //     }

    //     $whereConds = [];
    //     $whereParams = [];
    //     foreach ($pks as $pk) {
    //         $pkLimpo = $this->normalizarIdentificador((string) $pk);
    //         $token = 'whr_' . $this->placeholderSeguro($pkLimpo);
    //         $whereConds[] = '[' . $pkLimpo . '] = :' . $token;
    //         $whereParams[':' . $token] = $registro[$pkLimpo] ?? null;
    //     }

    //     $sqlCheck = 'SELECT COUNT(*) FROM ' . $tabelaQualificada . ' WHERE ' . implode(' AND ', $whereConds);
    //     $stmtCheck = $destino->prepare($sqlCheck);
    //     foreach ($whereParams as $token => $valor) {
    //         if ($valor === null) {
    //             $stmtCheck->bindValue($token, null, \PDO::PARAM_NULL);
    //         } elseif (is_bool($valor)) {
    //             $stmtCheck->bindValue($token, $valor ? 1 : 0, \PDO::PARAM_INT);
    //         } else {
                
    //             $stmtCheck->bindValue($token, $valor);
    //         }
    //     }
    //     $stmtCheck->execute();
    //     $existe = (int) $stmtCheck->fetchColumn();
    //     $stmtCheck->closeCursor();
    //     $stmtCheck = null;

    //     if ($existe > 0) {
    //         $updateFields = [];
    //         $updateParams = [];

    //         foreach ($registro as $colunaReg => $valorReg) {
    //             if (is_numeric($colunaReg) || $this->ehPk((string) $colunaReg, $pks)) {
    //                 continue;
    //             }

    //             $colLimpo = $this->normalizarIdentificador((string) $colunaReg);
    //             $token = 'up_' . $this->placeholderSeguro($colLimpo);
    //             $updateFields[] = '[' . $colLimpo . '] = :' . $token;
    //             $updateParams[':' . $token] = $valorReg;
    //         }

    //         if (!empty($updateFields)) {
    //             $sqlUpdate = 'UPDATE ' . $tabelaQualificada . ' SET ' . implode(', ', $updateFields) . ' WHERE ' . implode(' AND ', $whereConds);
    //             $stmtUpdate = $destino->prepare($sqlUpdate);

    //             foreach ($whereParams as $token => $valor) {
    //                 if ($valor === null) {
    //                     $stmtUpdate->bindValue($token, null, \PDO::PARAM_NULL);
    //                 } elseif (is_bool($valor)) {
    //                     $stmtUpdate->bindValue($token, $valor ? 1 : 0, \PDO::PARAM_INT);
    //                 } else {
    //                     $stmtUpdate->bindValue($token, $valor);
    //                 }
    //             }
    //             foreach ($updateParams as $token => $valor) {
    //                 if ($valor === null) {
    //                     $stmtUpdate->bindValue($token, null, \PDO::PARAM_NULL);
    //                 } elseif (is_bool($valor)) {
    //                     $stmtUpdate->bindValue($token, $valor ? 1 : 0, \PDO::PARAM_INT);
    //                 } else {
                 
    //                     $stmtUpdate->bindValue($token, $valor);
    //                 }
    //             }
    //             $stmtUpdate->execute();
    //             $stmtUpdate->closeCursor();
    //             $stmtUpdate = null;
    //         }
    //     } else {
    //         $insertCols = [];
    //         $insertTokens = [];
    //         $insertParams = [];

    //         foreach ($registro as $colunaReg => $valorReg) {
    //             if (is_numeric($colunaReg)) {
    //                 continue;
    //             }
    //             // Arruma problema de timestamps vindos do POSTGRES
    //             if (is_string($valorReg) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $valorReg)) {
    //                 // Corta as 3 últimas casas decimais do milissegundo (de .691839 para .691)
    //                 $valorReg = substr($valorReg, 0, -3);
    //             }      
    //             $colLimpo = $this->normalizarIdentificador((string) $colunaReg);
    //             $token = 'ins_' . $this->placeholderSeguro($colLimpo);
    //             $insertCols[] = '[' . $colLimpo . ']';
    //             $insertTokens[] = ':' . $token;
    //             $insertParams[':' . $token] = $valorReg;
    //         }

    //         $sqlInsert = 'INSERT INTO ' . $tabelaQualificada . ' (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertTokens) . ')';
    //         $stmtInsert = $destino->prepare($sqlInsert);
    //         foreach ($insertParams as $token => $valor) {
    //             if ($valor === null) {
    //                 $stmtInsert->bindValue($token, null, \PDO::PARAM_NULL);
    //             } elseif (is_bool($valor)) {
    //                 $stmtInsert->bindValue($token, $valor ? 1 : 0, \PDO::PARAM_INT);
    //             } else {
    //                 $stmtInsert->bindValue($token, $valor);
    //             }
    //         }
    //         $stmtInsert->execute();
    //         $stmtInsert->closeCursor();
    //         $stmtInsert = null;
    //     }
    // }
public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks, array $tabelaConfig): void
{
    if (empty($pks)) {
        throw new \Exception('Erro de Sintaxe: Nao e possivel realizar UPSERT sem chaves primarias.');
    }

    $colunas = array_filter(array_keys($registro), fn($c) => !is_numeric($c));

    // Monta condição do WHERE pelas PKs
    $whereConds = [];
    foreach ($pks as $pk) {
        $pkLimpo = $this->normalizarIdentificador((string) $pk);
        $whereConds[] = '[' . $pkLimpo . '] = :' . $this->placeholderSeguro($pkLimpo);
    }
    $strWhere = implode(' AND ', $whereConds);

    // Monta campos de UPDATE
    $updateFields = [];
    foreach ($colunas as $col) {
        if (!$this->ehPk($col, $pks)) {
            $colLimpo = $this->normalizarIdentificador($col);
            $updateFields[] = '[' . $colLimpo . '] = :' . $this->placeholderSeguro($colLimpo);
        }
    }

    // Monta campos de INSERT
    $insertCols = array_map(fn($c) => '[' . $this->normalizarIdentificador($c) . ']', $colunas);
    $insertTokens = array_map(fn($c) => ':' . $this->placeholderSeguro($c), $colunas);

    // SQL único com IF EXISTS nativo do T-SQL
    $sql = "IF EXISTS (SELECT 1 FROM {$tabelaQualificada} WHERE {$strWhere})
                UPDATE {$tabelaQualificada} SET " . implode(', ', $updateFields) . " WHERE {$strWhere};
            ELSE
                INSERT INTO {$tabelaQualificada} (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertTokens) . ");";

    $stmt = $destino->prepare($sql);

    foreach ($colunas as $colunaReg) {
        $valorReg = $registro[$colunaReg];

        // Trata precisão de datas vindas do Postgres
        if (is_string($valorReg) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $valorReg)) {
            $valorReg = substr($valorReg, 0, -3);
        }

        $token = ':' . $this->placeholderSeguro($colunaReg);
        if ($valorReg === null) {
            $stmt->bindValue($token, null, \PDO::PARAM_NULL);
        } elseif (is_bool($valorReg)) {
            $stmt->bindValue($token, $valorReg ? 1 : 0, \PDO::PARAM_INT);
        } else {
            $stmt->bindValue($token, $valorReg);
        }
    }

    $stmt->execute();
    $stmt->closeCursor();
    $stmt = null;
}
    public function getDDLControle(string $schema): string
    {
        $schemaLimpo = $this->normalizarIdentificador(empty($schema) ? 'dbo' : $schema);
        return <<<SQL
                    IF NOT EXISTS (SELECT * FROM sys.schemas WHERE name = '$schemaLimpo')
                    BEGIN
                        EXEC('CREATE SCHEMA $schemaLimpo');
                    END
                    IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('[{$schemaLimpo}].[miida_controle_sincronizacao]') AND type = 'U')
                    BEGIN
                        CREATE TABLE [dbo].[miida_controle_sincronizacao] (
                            [id] INT IDENTITY(1,1) NOT NULL,
                            [banco_nome] VARCHAR(150) NOT NULL,
                            [tabela_nome] VARCHAR(150) NOT NULL,
                            [ultima_sincronizacao] DATETIME2(6) NULL,
                            [status_execucao] VARCHAR(50) NOT NULL,
                            [registros_afetados] INT DEFAULT 0,
                            [criado_em] DATETIME2(6) DEFAULT SYSDATETIME(),
                            CONSTRAINT [PK_miida_controle_sincronizacao] PRIMARY KEY ([id])
                        );
                    END;
                    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_miida_controle_busca' AND object_id = OBJECT_ID('[dbo].[miida_controle_sincronizacao]'))
                    BEGIN
                        CREATE INDEX idx_miida_controle_busca
                        ON [dbo].[miida_controle_sincronizacao] (banco_nome, tabela_nome, status_execucao);
                    END;
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
            'BOOLEAN' => 'BIT',
            'BIT' => 'BIT',
            'DATETIME' => 'DATETIME2(6)',
            'TIMESTAMP' => 'DATETIME2(6)',
            'TEXT' => 'VARCHAR(MAX)',
            'LONGTEXT' => 'VARCHAR(MAX)',
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