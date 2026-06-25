<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Script Principal CLI de Execução e Orquestração do Pipeline de Dados (Multi-SGBD)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Services\AntiCorruptionLayer;
use Miida\Services\Logger;
use Miida\Engine\DataSyncProcessor;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' ausente.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIÁVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $envVarName = substr($valor, 4);
            $infra[$no][$chave] = getenv($envVarName) ?: '';
        }
    }
}

echo "=========================================================\n";
echo "    MIIDA - INICIANDO PIPELINE DE DADOS (MULTI-SGBD)     \n";
echo "=========================================================\n";

try {
    // 1. INSTANCIAÇÃO DINÂMICA DA ESTRATÉGIA DO LEGADO (ORIGEM)
    $sgbdOrigem = strtolower($infra['origem_command']['sgbd'] ?? 'sqlserver');
    switch ($sgbdOrigem) {
        case 'postgres':
        case 'postgresql': $syntaxLegado = new PostgresSyntax(); break;
        case 'mysql':      $syntaxLegado = new MySqlSyntax(); break;
        case 'sqlserver':
        default:           $syntaxLegado = new SqlServerSyntax(); break;
    }

    // 2. INSTANCIAÇÃO DINÂMICA DA ESTRATÉGIA DO MODERNO (DESTINO)
    $sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'sqlserver');
    switch ($sgbdDestino) {
        case 'postgres':
        case 'postgresql': $syntaxModerno = new PostgresSyntax(); break;
        case 'mysql':      $syntaxModerno = new MySqlSyntax(); break;
        case 'sqlserver':
        default:           $syntaxModerno = new SqlServerSyntax(); break;
    }

    echo "[*] Conectando ao Nó de Extração Legado [" . strtoupper($sgbdOrigem) . "]...\n";
    $connLegado = ConnectionFactory::getLegadoConnection($infra);

    echo "[*] Conectando ao Nó de Escrita Moderno [" . strtoupper($sgbdDestino) . "]...\n";
    $connModerno = ConnectionFactory::getModernoConnection($infra);
    echo "[OK] Conexões agnósticas estabelecidas com sucesso.\n\n";

    // 3. INJEÇÃO DOS DIALETOS NOS COMPONENTES CORE
    $controlRepo = new ControlRepository($connModerno, $syntaxModerno);
    $acl = new AntiCorruptionLayer();
    $logger = new Logger($connModerno, $syntaxModerno); // Agora grava logs usando o dialeto correto

    // O processador agora recebe os dois dialetos isolados para gerir o cruzamento de dados
    $processor = new DataSyncProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $controlRepo, $acl, $logger);

    foreach ($config['bancos_gerenciados'] as $banco) {
        echo "Processando pipeline do Banco de Dados: [{$banco['banco_legado']}] -> [{$banco['banco_moderno']}]\n";
        
        foreach ($banco['tabelas'] as $tabela) {
            $processor->sincronizarTabela($banco, $tabela);
        }
        echo "\n";
    }

    echo "=========================================================\n";
    echo "       PIPELINE DE SINCRONIZAÇÃO CONCLUÍDO COM SUCESSO!  \n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\n[ X ] ERRO CRÍTICO NO PIPELINE:\n " . $e->getMessage() . "\n";
    exit(1);
}