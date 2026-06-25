<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;

/**
 * MIIDA - SchemaCloner
 * Mecanismo de Engenharia Reversa e Clonagem Estrutural Declarativa (Multi-SGBD)
 */
class SchemaCloner
{
    private PDO $destino;
    private SgbdSyntaxInterface $syntax;

    public function __construct(PDO $destino, SgbdSyntaxInterface $syntax)
    {
        $this->destino = $destino;
        $this->syntax = $syntax;
    }

    /**
     * Executa o provisionamento estrutural baseado nos bancos gerenciados do manifesto JSON
     */
    public function clonar(array $bancosGerenciados): void
    {
        foreach ($bancosGerenciados as $bancoConfig) {
            $bancoModerno = $bancoConfig['banco_moderno'];
            $sgbdNome = strtolower(get_class($this->syntax));

            if (strpos($sgbdNome, 'sqlserver') !== false) {
                $stmtUse = $this->destino->query("USE [{$bancoModerno}];");
                if ($stmtUse) {
                    $stmtUse->closeCursor();
                }
            } elseif (strpos($sgbdNome, 'mysql') !== false) {
                $this->destino->exec("USE `{$bancoModerno}`;");
            }

            foreach ($bancoConfig['tabelas'] as $tabelaConfig) {
                $tabelaModerna = $tabelaConfig['tabela_moderna'];
                $schemaModerno = $tabelaConfig['schema_moderno'] ?? 'dbo';
                $mapeamento    = $tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

                // AJUSTE DE COMPATIBILIDADE SUTIL:
                // SQL Server nao usa o schema 'public' por padrao (isso e do Postgres).
                // Se o manifesto json veio com public, convertemos para dbo no SQL Server.
                if (strpos($sgbdNome, 'sqlserver') !== false && strtolower($schemaModerno) === 'public') {
                    $schemaModerno = 'dbo';
                }

                echo "  ├── Provisionando estrutura: [{$bancoModerno}].[{$schemaModerno}].[{$tabelaModerna}]\n";

                // 1. Cria o Schema lógico se o SGBD der suporte
                $sqlSchema = $this->syntax->obterDdlCriarSchema($schemaModerno);
                if (!empty($sqlSchema) && strtolower($schemaModerno) !== 'dbo') {
                    try {
                        $stmtSchema = $this->destino->query($sqlSchema);
                        if ($stmtSchema) {
                            $stmtSchema->closeCursor();
                        }
                    } catch (Exception $e) {
                        // Ignora se o schema ja existir
                    }
                }

                // 2. Monta dinamicamente as colunas do DDL baseando-se no mapa da ACL do JSON
                $colunasDdl = [];
                foreach ($mapeamento as $colunaOrigem => $props) {
                    $nomeColDestino = $props['nome_destino'];
                    $tipoDestino    = strtoupper($props['tipo_destino'] ?? 'VARCHAR(255)');
                    
                    if (strpos($sgbdNome, 'sqlserver') !== false && $tipoDestino === 'TEXT') {
                        $tipoDestino = 'VARCHAR(MAX)';
                    }

                    $restricao = '';
                    if (!empty($props['pk'])) {
                        $restricao = ' NOT NULL';
                    }

                    $colunasDdl[] = "[{$nomeColDestino}] {$tipoDestino}{$restricao}";
                }

                $colunaLastUpdated = $tabelaConfig['coluna_last_updated'] ?? 'middleware_last_updated';
                
                if (strpos($sgbdNome, 'postgres') !== false) {
                    $colunasDdl[] = "[\"{$colunaLastUpdated}\"] TIMESTAMP NOT NULL";
                    $colunasDdl[] = "[\"hash_versao\"] VARCHAR(32) NOT NULL";
                } else {
                    $colunasDdl[] = "[{$colunaLastUpdated}] DATETIME NOT NULL";
                    $colunasDdl[] = "[hash_versao] VARCHAR(32) NOT NULL";
                }

                $pks = [];
                foreach ($mapeamento as $colunaOrigem => $props) {
                    if (!empty($props['pk'])) {
                        $pks[] = "[{$props['nome_destino']}]";
                    }
                }
                
                if (!empty($pks)) {
                    $colunasDdl[] = "PRIMARY KEY (" . implode(', ', $pks) . ")";
                }

                $corpoTabelaSql = implode(",\n        ", $colunasDdl);

                if (strpos($sgbdNome, 'mysql') !== false) {
                    $corpoTabelaSql = str_replace(['[', ']'], ['`', '`'], $corpoTabelaSql);
                } elseif (strpos($sgbdNome, 'postgres') !== false) {
                    $corpoTabelaSql = str_replace(['[', ']'], ['"', '"'], $corpoTabelaSql);
                }

                // 4. Executa a criação física da tabela de negócio no destino
                $sqlCriarTabela = $this->syntax->obterDdlCriarTabela($schemaModerno, $tabelaModerna, $corpoTabelaSql);
                
                // Limpezas extras de DDL para garantir isolamento no banco corrente
                $sqlCriarTabela = str_replace("[master].", "", $sqlCriarTabela);
                if (strpos($sgbdNome, 'sqlserver') !== false) {
                    $sqlCriarTabela = str_replace("[public].", "[{$schemaModerno}].", $sqlCriarTabela);
                }

                try {
                    $stmtTable = $this->destino->query($sqlCriarTabela);
                    if ($stmtTable) {
                        $stmtTable->closeCursor();
                    }
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'already') === false && strpos($e->getMessage(), 'exist') === false) {
                        throw new Exception("Falha ao criar tabela de negócio {$tabelaModerna}: " . $e->getMessage());
                    }
                }
            }
        }
    }
}