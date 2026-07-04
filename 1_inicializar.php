<?php

/**
 * MIIDA - Script de Inicialização de Estrutura Automática (CLI)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\SqlServerLegacySyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;

echo "=========================================================\n";
echo "        MIIDA - INICIALIZAÇÃO DE INFRAESTRUTURA METADADO  \n";
echo "=========================================================\n";

$jsonPath = __DIR__ . '/config/pipeline_config.json';
if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' nao encontrado.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIÁVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $varNome = substr($valor, 4);
            $infra[$no][$chave] = getenv($varNome) ?: ($_ENV[$varNome] ?? ($_SERVER[$varNome] ?? ''));
        }
    }
}

// Atualiza o array global de configuracao com os dados reais desmascarados
$config['configuracao_infraestrutura'] = $infra;

// RESOLUÇÃO DA STRATEGY DO SGBD DESTINO
$sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'sqlserver');
switch ($sgbdDestino) {
    case 'postgres':
    case 'postgresql':          $syntaxModerno = new PostgresSyntax(); break;
    case 'mysql':               $syntaxModerno = new MySqlSyntax(); break;
    case 'sqlserver_legacy':    $syntaxModerno = new SqlServerLegacySyntax(); break;
    case 'sqlserver':
    default:                    $syntaxModerno = new SqlServerSyntax(); break;
}

try {
    echo "[*] Iniciando varredura dos escopos do manifesto...\n\n";

    foreach ($config['bancos_gerenciados'] as $banco) {
        $bancoModernoAlvo = $banco['banco_moderno'];
        echo "---------------------------------------------------------\n";
        echo "[*] Processando Banco Moderno: [{$bancoModernoAlvo}]\n";
        echo "---------------------------------------------------------\n";

        // 1. Garante o "CREATE DATABASE" e abre a conexão PDO focada ESTRITAMENTE DENTRO desse catálogo
        $connModerno = ConnectionFactory::getModernoConnection($config, $syntaxModerno, $bancoModernoAlvo);
        if ($sgbdDestino === 'sqlserver') {
            // $connModerno->exec('SET NOCOUNT ON;');
            $connModerno->exec('USE [' . $bancoModernoAlvo . '];');
        }
        echo "[OK] Banco de dados verificado/criado com sucesso.\n";

        // 2. GARANTE A TABELA DE CONTROLE DE SINCRONIZAÇÃO NESTE BANCO (Evita Cross-Database)
        echo " -> Garantindo Tabela de Controle Interna... ";
        // $ddlTabelaControle = "
        //     CREATE TABLE IF NOT EXISTS public.miida_controle_sincronizacao (
        //         id SERIAL PRIMARY KEY,
        //         banco_nome VARCHAR(150) NOT NULL,
        //         tabela_nome VARCHAR(150) NOT NULL,
        //         ultima_sincronizacao TIMESTAMP NULL,
        //         status_execucao VARCHAR(50) NOT NULL,
        //         registros_afetados INT DEFAULT 0,
        //         criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        //     );
        //     CREATE INDEX IF NOT EXISTS idx_miida_controle_busca 
        //     ON public.miida_controle_sincronizacao (banco_nome, tabela_nome, status_execucao);
        // ";

        $ddlTabelaControle = $syntaxModerno->getDDLControle();
        $connModerno->exec($ddlTabelaControle);
        if ($connModerno->inTransaction()) {
            $connModerno->commit();
        }
        echo "[OK]\n";

        // 3. Criação Dinâmica de Schemas e Tabelas mapeados para ESTE banco
        foreach ($banco['tabelas'] as $tabela) {
            $schema = $tabela['schema_moderno'] ?? null;
            $nomeTabela = $tabela['tabela_moderna'];
            
            echo " -> Garantindo Tabela [{$nomeTabela}]... ";

            // O provisionamento de schema/tabela é responsabilidade exclusiva da Strategy.
            if (!empty($sqlSchema)) {
                $sqlSchema = $syntaxModerno->obterDdlCriarSchema($schema);
                if ($sgbdDestino === 'sqlserver') {
                    $connModerno->exec('USE [' . $bancoModernoAlvo . '];');
                }
                $connModerno->exec($sqlSchema);
            }

            // MAPEAMENTO CORRETO: Acessa o nó interno exatamente como está na árvore do JSON
            $camposMapeados = $tabela['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

            // Montagem do mapa cru para a Strategy assumir toda a sintaxe final
            $colunasSql = [];
            $pks = []; // Array para acumular as colunas que fazem parte da chave primária
            $colunasMapeadas = [];
            
            foreach ($camposMapeados as $colunaOriginal => $meta) {
                $nomeDestino = $meta['nome_destino'];
                $tipoDestino = $meta['tipo'];
                $isPk = $meta['pk'] ?? false;
                $chaveDestinoNormalizada = strtolower(trim((string)$nomeDestino));

                // Tradução amigável para tipos (DATETIME vira TIMESTAMP no Postgres)
                // if (strtoupper($tipoDestino) === 'DATETIME') {
                //     $tipoDestino = 'TIMESTAMP';
                // }
                // // Tradução amigável (BIT vira BOOLEAN no Postgres)
                // if (strtoupper($tipoDestino) === 'BIT') {
                //     $tipoDestino = 'BOOLEAN'; 
                // }

                if (!isset($colunasMapeadas[$chaveDestinoNormalizada])) {
                    $colunasSql[$nomeDestino] = $tipoDestino;
                    $colunasMapeadas[$chaveDestinoNormalizada] = true;
                }

                if ($isPk) {
                    $pks[] = $nomeDestino;
                }
            }

            // Injeta a coluna de controle de tracking do middleware apenas como fallback, se ainda não existir no mapeamento
            $colunaTracking = $tabela['coluna_last_updated'] ?? 'middleware_last_updated';
            $colunaTrackingNormalizada = strtolower(trim((string)$colunaTracking));
            if (!isset($colunasMapeadas[$colunaTrackingNormalizada])) {
                $colunasSql[$colunaTracking] = $syntaxModerno->obterTipoDataHora();
                $colunasMapeadas[$colunaTrackingNormalizada] = true;
            }

            // CORREÇÃO: Injeta de forma fixa a coluna de hash exigida pelo processador de sincronização
            if (!isset($colunasMapeadas['hash_versao'])) {
                $colunasSql['hash_versao'] = 'VARCHAR(64)';
            }
            
            // DDL purificada apontando explicitamente para o par Schema + Tabela correto da iteração
            $ddlTabela = $syntaxModerno->obterDdlCriarTabela($schema, $nomeTabela, $colunasSql, $pks);
            
            $stmt = $connModerno->prepare($ddlTabela);
            $stmt->execute();
            if($sgbdDestino === 'sqlserver'){
                do {
                    if ($stmt->columnCount() > 0) {
                        $stmt->fetchAll();
                    }
                } while ($stmt->nextRowset());
                $stmt->closeCursor();
                $stmt = null;
            }

            // if ($connModerno->inTransaction()) {
            //     $connModerno->commit();
            // }

            
            echo "[OK]\n";
        }
        
        echo "[OK] Todo o escopo de [{$bancoModernoAlvo}] foi estruturado.\n\n";
    }

    echo "=========================================================\n";
    echo "[OK] AMBIENTE MULTI-BANCO INICIALIZADO COM SUCESSO!\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\nERRO CRÍTICO NA INICIALIZAÇÃO: " . $e->getMessage() . "\n";
    exit(1);
}