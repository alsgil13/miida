<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;

class SchemaCloner
{
    private PDO $connModerno;
    private SgbdSyntaxInterface $syntax;

    /**
     * O Cloner agora recebe a conexão e a estratégia de dialeto do SGBD moderno (Destino)
     */
    public function __construct(PDO $connModerno, SgbdSyntaxInterface $syntax)
    {
        $this->connModerno = $connModerno;
        $this->syntax = $syntax;
    }

    /**
     * Executa a criação das estruturas mapeadas no JSON
     */
    public function clonar(array $bancosGerenciados): void
    {
        echo "=========================================================\n";
        echo "   MIIDA - EXECUTANDO SCHEMA CLONER (MULTI-SGBD)\n";
        echo "=========================================================\n\n";

        foreach ($bancosGerenciados as $banco) {
            $bancoDestino = $banco['banco_moderno'];

            // Como MySQL/Postgres não usam "USE banco" de forma intercambiável,
            // as estruturas e tabelas passam a ser qualificadas pelo nome completo nas queries.
            foreach ($banco['tabelas'] as $tabela) {
                $tabelaDestino = $tabela['tabela_moderna'];
                echo "  ├── Construindo DDL para a tabela: [{$tabelaDestino}]... ";
                
                $this->construirETestarTabela($bancoDestino, $tabela);
            }
            echo "\n";
        }
        echo "=========================================================\n";
        echo "       SCHEMA CLONER CONCLUÍDO COM SUCESSO!\n";
        echo "=========================================================\n";
    }

    /**
     * Monta dinamicamente o comando CREATE TABLE com base na Estratégia de Sintaxe
     */
    private function construirETestarTabela(string $bancoDestino, array $configTabela): void
    {
        $tabelaDestino = $configTabela['tabela_moderna'];
        $schemaDestino = $configTabela['schema_moderno'] ?? null; 
        $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'];

        $colunasDdl = [];
        $chavesPrimarias = [];

        // Processa as colunas vindas do mapeamento da ACL
        foreach ($mapeamento as $colunaLegada => $detalhes) {
            $nomeColunaNova = $detalhes['nome_destino'] ?? $colunaLegada;
            $tipoColunaNova = trim($detalhes['tipo']);

            // Remove colchetes fixos para manter compatibilidade com MySQL/Postgres
            $linhaColuna = "{$nomeColunaNova} {$tipoColunaNova}";

            $isPk = isset($detalhes['pk']) && $detalhes['pk'] === true;
            $isNotNull = isset($detalhes['not_null']) && $detalhes['not_null'] === true;

            if ($isPk) {
                $linhaColuna .= " NOT NULL";
                $chavesPrimarias[] = "{$nomeColunaNova}";
            } elseif ($isNotNull) {
                $linhaColuna .= " NOT NULL";
            } else {
                $linhaColuna .= " NULL";
            }

            $colunasDdl[] = $linhaColuna;
        }

        if (!empty($chavesPrimarias)) {
            $stringPks = implode(", ", $chavesPrimarias);
            $colunasDdl[] = "PRIMARY KEY ({$stringPks})";
        }

        // Respeita o padrão de timestamp padrão de cada SGBD caso a coluna de atualização seja nula
        if ($configTabela['coluna_last_updated'] === null) {
            $colunasDdl[] = "middleware_last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        }

        $colunasDdl[] = "hash_versao VARCHAR(32) NOT NULL";

        $stringColunas = implode(",\n    ", $colunasDdl);

        // O STRATEGY EM AÇÃO: Deixamos de usar blocos procedurais IF NOT EXISTS hardcoded
        $sqlCreateSchema = $this->syntax->obterDdlCriarSchema($schemaDestino);
        $sqlCreateTable  = $this->syntax->obterDdlCriarTabela($schemaDestino, $tabelaDestino, $stringColunas);

        try {
            // Executa a query de criação de schema (Retorna query neutra em SGBDs que não possuem schemas isolados)
            $stmtSchema = $this->connModerno->prepare($sqlCreateSchema);
            $stmtSchema->execute();
            $stmtSchema->closeCursor(); 
            
            echo "OK (Schema) -> ";
        } catch (Exception $e) {
            echo "FALHA SCHEMA!\n";
            throw new Exception("Erro ao executar DDL do schema {$schemaDestino}: " . $e->getMessage());
        }

        try {
            $stmtTable = $this->connModerno->prepare($sqlCreateTable);
            $stmtTable->execute();
            $stmtTable->closeCursor(); 
            
            echo "OK (Estrutura gerada)\n";
        } catch (Exception $e) {
            echo "FALHA TABELA!\n";
            throw new Exception("Erro ao executar DDL da tabela {$tabelaDestino}: " . $e->getMessage());
        }
    }
}