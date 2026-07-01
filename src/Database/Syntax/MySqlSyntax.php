<?php

namespace Miida\Database\Syntax;

class MySqlSyntax implements SgbdSyntaxInterface 
{
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string 
    {
        // No MySQL, o conceito de "Schema" e "Database" se misturam de forma nativa.
        // Ignoramos o schema e qualificamos diretamente como `banco`.`tabela`
        return "`{$banco}`.`{$tabela}`";
    }

    public function obterDdlCriarSchema(string $schema): string 
    {
        // MySQL não possui schemas lógicos isolados dentro do mesmo banco como o SQL Server.
        return "SELECT 1;";
    }

    public function obterDdlCriarTabela(string $schema, string $tabela, string $corpoColunas): string 
    {
        return "CREATE TABLE IF NOT EXISTS `{$tabela}` ( {$corpoColunas} )";
    }

    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string
    {
        return "/* UPSERT nativo gerenciado via método executarUpsert direto no PDO */";
    }

    public function obterSqlConcat(array $colunas): string 
    {
        $colunasEscapadas = array_map(fn($c) => "CAST(`{$c}` AS CHAR)", $colunas);
        return "CONCAT_WS('-', " . implode(', ', $colunasEscapadas) . ")";
    }

    public function escaparColuna(string $coluna): string
    {
        return "`{$coluna}`";
    }

    public function obterComandoTrocaBanco(string $banco): string
    {
        return "USE `{$banco}`";
    }

    public function obterTipoTextoLongo(): string
    {
        return "LONGTEXT";
    }

    public function obterTipoDataHora(): string
    {
        return "DATETIME";
    }

    public function obterDsn(string $host, int $port, string $banco): string
    {
        return "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
    }

    public function obterBancoAdministrativo(): string
    {
        return "mysql";
    }

    public function obterDdlGarantirBanco(string $bancoAlvo): array
    {
        return [
            'checagem' => null, // MySQL aceita IF NOT EXISTS direto na query
            'criacao'  => "CREATE DATABASE IF NOT EXISTS `{$bancoAlvo}`"
        ];
    }    
    
    public function obterNomeQualificadoTabelaControle(): string
    {
        return "`mysql`.`miida_controle_sincronizacao`";
    }

    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string
    {
        return "SELECT * FROM `{$banco}`.`{$tabela}` WHERE `{$colunaControle}` > :ultima_data ORDER BY `{$colunaControle}` ASC";
    }

    /**
     * Executa a estratégia de UPSERT (Merge/Sincronização) nativa para MySQL 8.0
     * Utiliza a instrução de alta performance: INSERT INTO ... ON DUPLICATE KEY UPDATE
     */
    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks): void
    {
        $colunas = array_keys($registro);
        
        // Remove índices numéricos gerados ocasionalmente pelo PDO fetch
        $colunasValidas = array_filter($colunas, fn($col) => !is_numeric($col));

        $listaColunas = '`' . implode('`, `', $colunasValidas) . '`';
        $placeholders = ':' . implode(', :', $colunasValidas);

        $updates = [];
        foreach ($colunasValidas as $coluna) {
            if (!in_array($coluna, $pks)) {
                $updates[] = "`{$coluna}` = VALUES(`{$coluna}`)";
            }
        }

        // Se a tabela só possuir PKs e nenhuma coluna de dados, evita quebra de sintaxe
        if (empty($updates)) {
            $sql = "INSERT IGNORE INTO {$tabelaQualificada} ({$listaColunas}) VALUES ({$placeholders})";
        } else {
            $listaUpdates = implode(', ', $updates);
            $sql = "INSERT INTO {$tabelaQualificada} ({$listaColunas}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$listaUpdates}";
        }

        $stmt = $destino->prepare($sql);
        foreach ($colunasValidas as $coluna) {
            $stmt->bindValue(":{$coluna}", $registro[$coluna]);
        }
        $stmt->execute();
    }
}