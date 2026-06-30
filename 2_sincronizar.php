<?php

/**
 * MIIDA - Script de Execução de Carga / Sincronização Manual (CLI)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\DataSyncProcessor;

// CORREÇÃO: Definindo explicitamente o caminho do arquivo de configuração
$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' nao foi encontrado.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERRO: O arquivo JSON possui erros de sintaxe: " . json_last_error_msg() . "\n");
}

$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIAVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $infra[$no][$chave] = getenv(substr($valor, 4)) ?: '';
        }
    }
}

// 1. RESOLUÇÃO DOS DIALETOS DE SGBD (PADRAO STRATEGY)
$sgbdOrigem = strtolower($infra['origem_command']['sgbd'] ?? 'mysql');
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

try {
    echo "=========================================================\n";
    echo "          MIIDA - EXECUÇÃO DE CARGA MANUAL (CLI)         \n";
    echo "=========================================================\n";

    echo "[*] Conectando ao No de Extracao Legado [" . strtoupper($sgbdOrigem) . "]...\n";
    $connLegado = ConnectionFactory::getLegadoConnection($infra, $syntaxLegado);

    echo "[*] Conectando ao Banco Moderno de Destino [" . strtoupper($sgbdDestino) . "]...\n";
    $connModerno = ConnectionFactory::getModernoConnection($infra, $syntaxModerno);
    
    echo "[OK] Conexoes estabelecidas com sucesso.\n\n";

    // 2. INICIALIZAÇÃO DO REPOSITÓRIO E PROCESSADOR
    $controlRepo = new ControlRepository($connModerno, $syntaxModerno);
    $sincronizador = new DataSyncProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $controlRepo);

    echo "[*] Iniciando varredura das tabelas configuradas...\n";
    $inicioCarga = microtime(true);

    // 3. LOOP DE SINCRONIZAÇÃO DAS TABELAS DO MANIFESTO
    foreach ($config['bancos_gerenciados'] as $banco) {
        foreach ($banco['tabelas'] as $tabela) {
            echo " -> Sincronizando: {$banco['banco_moderno']}.{$tabela['tabela_moderna']}... ";
            
            $linhasSincronizadas = $sincronizador->sincronizarTabela($banco, $tabela);
            
            echo "[OK] ({$linhasSincronizadas} registros processados)\n";
        }
    }

    $tempoTotal = round((microtime(true) - $inicioCarga), 2);
    echo "\n=========================================================\n";
    echo "[OK] Carga manual finalizada com sucesso em {$tempoTotal} segundos.\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\nERRO CRÍTICO NA EXECUÇÃO: " . $e->getMessage() . "\n";
    exit(1);
}