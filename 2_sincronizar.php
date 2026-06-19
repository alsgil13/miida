<?php

/**
 * MIIDA - Middleware de Ingestão, Integration e Desacoplamento de Arquiteturas
 * Script Principal CLI de Execução e Orquestração do Pipeline de Dados
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
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
echo "          MIIDA - INICIANDO PIPELINE DE DADOS            \n";
echo "=========================================================\n";

try {
<<<<<<< HEAD
    echo "[*] Conectando ao Nó de Escrita Legado (SQL 2005)...\n";
=======
    // Inicializa Conexões através da ConnectionFactory
    echo "[...] Conectando ao Nó de Escrita Legado (SQL 2005)...\n";
>>>>>>> 8ce4f97fa9824e1b54e365f8c3e9ad84581da60a
    $connLegado = ConnectionFactory::getLegadoConnection($infra, 'master');
    
    echo "[...] Conectando ao Nó de Leitura Moderno (SQL 2022)...\n";
    $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');
    echo "[OK] Conexões estabelecidas com sucesso.\n\n";

    $controlRepo = new ControlRepository($connModerno);
    $acl = new AntiCorruptionLayer();
    $logger = new Logger($connModerno);

    $processor = new DataSyncProcessor($connLegado, $connModerno, $controlRepo, $acl, $logger);

    foreach ($config['bancos_gerenciados'] as $banco) {
        echo "Processando pipeline do Banco de Dados: [{$banco['banco_legado']}]\n";
        
        foreach ($banco['tabelas'] as $tabela) {
            $processor->sincronizarTabela($banco, $tabela);
        }
        echo "\n";
    }

    echo "=========================================================\n";
    echo "       PIPELINE DE SINCRONIZAÇÃO CONCLUÍDO COM SUCESSO!  \n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\nERRO NO PROCESSAMENTO DO PIPELINE:\n";
    echo $e->getMessage() . "\n";
    echo "=========================================================\n";
} finally {
    ConnectionFactory::killConnections();
    echo "[...] Conexões encerradas de forma segura.\n";
}