<?php

namespace Miida\Engine;

use PDO;
use Exception;

class SchemaCloner
{
    private PDO $connModerno;

    /**
     * O Cloner opera diretamente sobre o nó de leitura/moderno (SQL Server 2022)
     */
    public function __construct(PDO $connModerno)
    {
        $this->connModerno = $connModerno;
    }

    /**
     * Executa a criação dos bancos e tabelas mapeados no JSON estruturado
     */
    public function clonar(array $bancosGerenciados): void
    {
        echo "=========================================================\n";
        echo "   MIIDA - EXECUTANDO SCHEMA CLONER (VERSÃO LIMPA)\n";
        echo "=========================================================\n\n";

        foreach ($bancosGerenciados as $banco) {
            $bancoDestino = $banco['banco_moderno'];
            
            // Invoca a verificação explícita de existência do banco de dados
            $this->garantirBancoDeDados($bancoDestino);

            // Aponta a conexão para usar o banco moderno correspondente
            $this->connModerno->exec("USE [{$bancoDestino}]");

            foreach ($banco['tabelas'] as $tabela) {
                $tabelaDestino = $tabela['tabela_moderna'];
                echo "  ├── Construindo DDL para a tabela: [{$tabelaDestino}]... ";
                
                $this->construirETestarTabela($tabela);
            }
            echo "\n";
        }
        echo "=========================================================\n";
        echo "       SCHEMA CLONER CONCLUÍDO COM SUCESSO!\n";
        echo "=========================================================\n";
    }

    /**
     * Verifica se o banco de dados já existe no servidor.
     * Se existir, o sistema avança. Se não existir, ele efetua a criação.
     */
    private function garantirBancoDeDados(string $nomeBanco): void
    {
        // Consulta o catálogo do sistema do SQL Server para checar a existência do banco
        $sqlCheck = "SELECT DB_ID(:nome) as banco_id";
        $stmt = $this->connModerno->prepare($sqlCheck);
        $stmt->execute([':nome' => $nomeBanco]);
        $resultado = $stmt->fetch();

        // Se o DB_ID retornar nulo, significa que o banco de dados não existe
        if ($resultado['banco_id'] === null) {
            echo "Banco de dados [{$nomeBanco}] não encontrado. Iniciando criação...\n";
            try {
                $this->connModerno->exec("CREATE DATABASE [{$nomeBanco}]");
                echo " -> Banco de dados [{$nomeBanco}] criado com sucesso.\n";
            } catch (Exception $e) {
                throw new Exception("Falha ao criar o banco de dados [{$nomeBanco}]: " . $e->getMessage());
            }
        } else {
            // Se já existir, apenas emite o aviso e passa para a próxima etapa
            echo "Banco de dados [{$nomeBanco}] já existente. Ignorando criação estrutural.\n";
        }
    }

    /**
     * Monta dinamicamente o comando CREATE TABLE com base no JSON (Suporta chaves compostas)
     */
    // private function construirETestarTabela(array $configTabela): void
    // {
    //     $tabelaDestino = $configTabela['tabela_moderna'];
    //     $schemaDestino = $configTabela['schema'];
    //     $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'];

    //     $colunasDdl = [];
    //     $chavesPrimarias = [];

    //     // Processa as colunas vindas do mapeamento da ACL
    //     foreach ($mapeamento as $colunaLegada => $detalhes) {
    //         $nomeColunaNova = $detalhes['nome_destino'] ?? $colunaLegada;
    //         $tipoColunaNova = trim($detalhes['tipo']);

    //         $linhaColuna = "[{$nomeColunaNova}] {$tipoColunaNova}";

    //         $isPk = isset($detalhes['pk']) && $detalhes['pk'] === true;
    //         $isNotNull = isset($detalhes['not_null']) && $detalhes['not_null'] === true;

    //         // Chaves primárias no SQL Server obrigatoriamente precisam ser NOT NULL
    //         if ($isPk) {
    //             $linhaColuna .= " NOT NULL";
    //             $chavesPrimarias[] = "[{$nomeColunaNova}]";
    //         } elseif ($isNotNull) {
    //             $linhaColuna .= " NOT NULL";
    //         } else {
    //             $linhaColuna .= " NULL";
    //         }

    //         $colunasDdl[] = $linhaColuna;
    //     }

    //     // Se houver chaves primárias mapeadas, anexa a restrição composta de forma agrupada
    //     if (!empty($chavesPrimarias)) {
    //         $stringPks = implode(", ", $chavesPrimarias);
    //         $colunasDdl[] = "PRIMARY KEY ({$stringPks})";
    //     }

    //     // Regra adaptativa temporal: adiciona a coluna de controle se o legado for nulo
    //     if ($configTabela['coluna_last_updated'] === null) {
    //         $colunasDdl[] = "[middleware_last_updated] DATETIME DEFAULT GETDATE()";
    //     }

    //     // Adiciona a coluna obrigatória do MIIDA para cálculo do Hash MD5 de concorrência por linha
    //     $colunasDdl[] = "[hash_versao] VARCHAR(32) NOT NULL";

    //     $stringColunas = implode(",\n    ", $colunasDdl);

    //     // Criar esquema caso não exista
    //     $sqlCreateSchema = "IF NOT EXISTS (SELECT * FROM sys.schemas WHERE name = '{$schemaDestino}')
    //                             BEGIN
    //                                 EXEC('CREATE SCHEMA {$schemaDestino}')
    //                             END";
    //     try {
    //         $this->connModerno->exec($sqlCreateSchema);
    //         echo "OK (Schema Criado /verificado)\n";
    //     } catch (Exception $e) {
    //         echo "FALHA!\n";
    //         throw new Exception("Erro ao executar DDL do schema {$schemaDestino}: " . $e->getMessage());
    //     }
    //     // Cláusula defensiva (Idempotente) para não destruir dados se rodado novamente
    //     $sqlCreateTable = "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = '{$tabelaDestino}')
    //     BEGIN
    //         CREATE TABLE [{$schemaDestino}.{$tabelaDestino}] (
    //             {$stringColunas}
    //         )
    //     END";

    //     try {
    //         $this->connModerno->exec($sqlCreateTable);
    //         echo "OK (Estrutura gerada/verificada)\n";
    //     } catch (Exception $e) {
    //         echo "FALHA!\n";
    //         throw new Exception("Erro ao executar DDL da tabela {$schemaDestino}.{$tabelaDestino}: " . $e->getMessage());
    //     }
    // }
/**
     * Monta dinamicamente o comando CREATE TABLE com base no JSON (Suporta chaves compostas)
     */
    private function construirETestarTabela(array $configTabela): void
    {
        $tabelaDestino = $configTabela['tabela_moderna'];
        // CORREÇÃO: Alinhado com a chave do seu JSON reorganizado
        $schemaDestino = $configTabela['schema'] ?? 'dbo'; 
        $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'];

        $colunasDdl = [];
        $chavesPrimarias = [];

        // Processa as colunas vindas do mapeamento da ACL
        foreach ($mapeamento as $colunaLegada => $detalhes) {
            $nomeColunaNova = $detalhes['nome_destino'] ?? $colunaLegada;
            $tipoColunaNova = trim($detalhes['tipo']);

            $linhaColuna = "[{$nomeColunaNova}] {$tipoColunaNova}";

            $isPk = isset($detalhes['pk']) && $detalhes['pk'] === true;
            $isNotNull = isset($detalhes['not_null']) && $detalhes['not_null'] === true;

            if ($isPk) {
                $linhaColuna .= " NOT NULL";
                $chavesPrimarias[] = "[{$nomeColunaNova}]";
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

        if ($configTabela['coluna_last_updated'] === null) {
            $colunasDdl[] = "[middleware_last_updated] DATETIME DEFAULT GETDATE()";
        }

        $colunasDdl[] = "[hash_versao] VARCHAR(32) NOT NULL";

        $stringColunas = implode(",\n    ", $colunasDdl);

        // 1. Criar esquema caso não exista
        $sqlCreateSchema = "IF NOT EXISTS (SELECT * FROM sys.schemas WHERE name = '{$schemaDestino}')
                            BEGIN
                                EXEC('CREATE SCHEMA {$schemaDestino}')
                            END";
        try {
            // SOLUÇÃO DO ERRO 20019: Usar prepare/execute e liberar o cursor imediatamente
            $stmtSchema = $this->connModerno->prepare($sqlCreateSchema);
            $stmtSchema->execute();
            $stmtSchema->closeCursor(); // <-- Libera a conexão pdo_dblib para o próximo comando
            
            echo "OK (Schema Criado /verificado) -> ";
        } catch (Exception $e) {
            echo "FALHA SCHEMA!\n";
            throw new Exception("Erro ao executar DDL do schema {$schemaDestino}: " . $e->getMessage());
        }

        // 2. Cláusula defensiva (Idempotente) para criar a tabela
        // CORREÇÃO: No SQL Server, a sintaxe correta para o IF NOT EXISTS com Schema é verificar na sys.objects ou sys.tables
        $sqlCreateTable = "IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('[{$schemaDestino}].[{$tabelaDestino}]') AND type = 'U')
        BEGIN
            CREATE TABLE [{$schemaDestino}].[{$tabelaDestino}] (
                {$stringColunas}
            )
        END";

        try {
            $stmtTable = $this->connModerno->prepare($sqlCreateTable);
            $stmtTable->execute();
            $stmtTable->closeCursor(); // <-- Boa prática manter limpo para a próxima tabela do loop
            
            echo "OK (Estrutura gerada/verificada)\n";
        } catch (Exception $e) {
            echo "FALHA TABELA!\n";
            throw new Exception("Erro ao executar DDL da tabela {$schemaDestino}.{$tabelaDestino}: " . $e->getMessage());
        }
    }
}