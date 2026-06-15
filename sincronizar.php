<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Script Principal CLI de Execução e Orquestração do Pipeline de Dados
 */

// require_once __DIR__ . '/src/Database/ConnectionFactory.php';
// require_once __DIR__ . '/src/Database/ControlRepository.php';
// require_once __DIR__ . '/src/Services/AntiCorruptionLayer.php';
// require_once __DIR__ . '/src/Services/Logger.php'; // ADICIONADO O REQUIRE DO LOGGER
// require_once __DIR__ . '/src/Engine/DataSyncProcessor.php';
require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Services\AntiCorruptionLayer;
use Miida\Services\Logger; // ADICIONADO O USE DO LOGGER
use Miida\Engine\DataSyncProcessor;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' ausente.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

echo "=========================================================\n";
echo "          MIIDA - INICIANDO PIPELINE DE DADOS            \n";
echo "=========================================================\n";

try {
    // Inicializa Conexões através da ConnectionFactory
    echo "[*] Conectando ao Nó de Escrita Legado (SQL 2005)...\n";
    $connLegado = ConnectionFactory::getLegadoConnection($infra, 'master');
    
    echo "[*] Conectando ao Nó de Leitura Moderno (SQL 2022)...\n";
    $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');
    echo "[✔] Conexões estabelecidas com sucesso.\n\n";

    // Instancia componentes de suporte
    $controlRepo = new ControlRepository($connModerno);
    $acl = new AntiCorruptionLayer();
    $logger = new Logger($connModerno); // INSTANCIADO O LOGGER HÍBRIDO

    // Instancia o Core Engine (DataSyncProcessor) injetando o logger como 5º argumento
    $processor = new DataSyncProcessor($connLegado, $connModerno, $controlRepo, $acl, $logger);

    // 4. Varre a estrutura declarativa e processa banco por banco, tabela por tabela
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
    // Encerra os canais de comunicação com segurança
    ConnectionFactory::killConnections();
    echo "[*] Conexões encerradas de forma segura.\n";
}