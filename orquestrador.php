<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Daemon Orquestrador de Sincronização Contínua (Worker Process)
 */

// Garante que o processo rode indefinidamente sem estourar tempo limite do PHP CLI
set_time_limit(0);

// require_once __DIR__ . '/src/Database/ConnectionFactory.php';
// require_once __DIR__ . '/src/Database/ControlRepository.php';
// require_once __DIR__ . '/src/Services/AntiCorruptionLayer.php';
// require_once __DIR__ . '/src/Services/Logger.php';
// require_once __DIR__ . '/src/Engine/DataSyncProcessor.php';
require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Services\AntiCorruptionLayer;
use Miida\Services\Logger;
use Miida\Engine\DataSyncProcessor;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo de parametrização 'pipeline_config.json' não foi encontrado.\n");
}

echo "=========================================================\n";
echo "           MIIDA - MOTOR DAEMON ORQUESTRADOR             \n";
echo "=========================================================\n";
echo "[*] Iniciando Worker em segundo plano (Loop Contínuo)...\n";
echo "[*] Monitorando alterações cadastrais e de infraestrutura...\n\n";

// Array de estado volátil em memória para controle de janelas de backoff por tabela
$cronometroTabelas = [];

while (true) {
    // Carrega/Recarrega o JSON a cada volta do laço permitindo Hot-Reload de parâmetros
    $config = json_decode(file_get_contents($jsonPath), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "ALERTA: Falha de sintaxe estrutural no JSON. Reavaliando no próximo ciclo...\n";
        sleep(5);
        continue;
    }

    $infra = $config['configuracao_infraestrutura'];
    
    // Intervalo de verificação da thread (Duração do sono leve do orquestrador antes de reavaliar o relógio)
    $tempoLoopControle = (int)($infra['intervalo_verificacao_segundos'] ?? 5);

    try {
        // Inicializa conexões dedicadas aos nós de processamento
        $connLegado = ConnectionFactory::getLegadoConnection($infra, 'master');
        $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');

        // Instanciação da malha de serviços e infraestrutura de suporte
        $controlRepo = new ControlRepository($connModerno);
        $acl         = new AntiCorruptionLayer();
        $logger      = new Logger($connModerno);
        
        // Inicialização do core engine injetando a malha desacoplada de dependências
        $processor   = new DataSyncProcessor($connLegado, $connModerno, $controlRepo, $acl, $logger);

        $agora = time();

        // Varre a árvore relacional descrita de forma declarativa no arquivo JSON
        foreach ($config['bancos_gerenciados'] as $banco) {
            foreach ($banco['tabelas'] as $tabela) {
                $tabelaModerna = $tabela['tabela_moderna'];
                
                // Janela de atraso/frequência individualizada mapeada no JSON (Fallback padrão: 1 minuto)
                $frequenciaMinutos = (int)($tabela['frequencia_sincronizacao_minutos'] ?? 1);
                $intervaloSegundos = $frequenciaMinutos * 60;

                $chaveCronometro = $banco['banco_moderno'] . '.' . $tabelaModerna;

                // Avalia se a tabela nunca rodou ou se a janela temporal de descanso expirou
                if (!isset($cronometroTabelas[$chaveCronometro]) || ($agora - $cronometroTabelas[$chaveCronometro]) >= $intervaloSegundos) {
                    
                    echo "\n [" . date('H:i:s') . "] Alocando pipeline incremental para: [{$chaveCronometro}]\n";
                    
                    // Executa o isolamento, extração, higienização (ACL) e a carga idempotente (Upsert)
                    $processor->sincronizarTabela($banco, $tabela);
                    
                    // Atualiza o marcador temporal em memória para iniciar o descanso da tabela
                    $cronometroTabelas[$chaveCronometro] = time();
                }
            }
        }

    } catch (Exception $e) {
        // Falhas operacionais ou de rede sofrem interceptação para auto-recuperação contínua
        echo "ERRO NO ORQUESTRADOR: " . $e->getMessage() . "\n";
        echo "[*] Liberando canais de comunicação e preparando auto-recuperação...\n";
    } finally {
        // Libera conexões de sockets ao final de cada avaliação para mitigar conexões persistentes ociosas
        ConnectionFactory::killConnections();
    }

    // Suspende a execução para poupar consumo de ciclos de CPU do servidor
    sleep($tempoLoopControle);
}