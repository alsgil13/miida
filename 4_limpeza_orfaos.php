<?php

/**
 * MIIDA - Middleware de Ingestao, Integracao e Desacoplamento de Arquiteturas
 * Script Auxiliar CLI para Auditoria e Expurgos de Registros Orfaos (Manual Execution)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Services\Logger;
use Miida\Engine\LimpezaOrfaosProcessor;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' nao foi encontrado.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIAVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $infra[$no][$chave] = getenv(substr($valor, 4)) ?: '';
        }
    }
}

try {
    // RESOLUCAO DOS DIALETOS DE SGBD (PADRAO STRATEGY)
    $sgbdOrigem = strtolower($infra['origem_command']['sgbd'] ?? 'sqlserver');
    switch ($sgbdOrigem) {
        case 'postgres':
        case 'postgresql': $syntaxLegado = new PostgresSyntax(); break;
        case 'mysql':      $syntaxLegado = new MySqlSyntax(); break;
        case 'sqlserver':
        default:           $syntaxLegado = new SqlServerSyntax(); break;
    }

    $sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'sqlserver');
    switch ($sgbdDestino) {
        case 'postgres':
        case 'postgresql': $syntaxModerno = new PostgresSyntax(); break;
        case 'mysql':      $syntaxModerno = new MySqlSyntax(); break;
        case 'sqlserver':
        default:           $syntaxModerno = new SqlServerSyntax(); break;
    }

    $connLegado  = ConnectionFactory::getLegadoConnection($infra);
    $connModerno = ConnectionFactory::getModernoConnection($infra);
    $logger      = new Logger($connModerno, $syntaxModerno);

    $limpador = new LimpezaOrfaosProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $logger);

    echo "=========================================================\n";
    echo "          MIIDA - AUDITORIA DE EXCLUSOES MANUAL          \n";
    echo "=========================================================\n";
    
    $inicioCiclo = microtime(true);
    foreach ($config['bancos_gerenciados'] as $banco) {
        foreach ($banco['tabelas'] as $tabela) {
            $limpador->executarLimpeza($banco, $tabela);
        }
    }
    $tempoCicloMili = round((microtime(true) - $inicioCiclo) * 1000, 2);
    
    echo "\n=========================================================\n";
    echo "Ciclo global de auditoria finalizado em " . $tempoCicloMili . " ms\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "ERRO CRITICO NA EXECUÇÃO: " . $e->getMessage() . "\n";
    exit(1);
}