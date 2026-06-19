<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Daemon Orquestrador de Sincronização Contínua (Worker Process)
 */

set_time_limit(0);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/4_limpeza_orfaos.php'; // Inclui o novo arquivo de limpeza

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Services\AntiCorruptionLayer;
use Miida\Services\Logger;
use Miida\Engine\DataSyncProcessor;
use Miida\Engine\LimpezaOrfaosProcessor; // Namespace da nova classe

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo de parametrização 'pipeline_config.json' não foi encontrado.\n");
}

echo "=========================================================\n";
echo "           MIIDA - MOTOR DAEMON ORQUESTRADOR             \n";
echo "=========================================================\n";
echo "[*] Iniciando Worker em segundo plano (Loop Contínuo)...\n";
echo "[*] Monitorando alterações cadastrais e de infraestrutura...\n\n";

// LÊ O CONFIG APENAS UMA VEZ NA INICIALIZAÇÃO
$config = json_decode(file_get_contents($jsonPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERRO: Falha de sintaxe estrutural no JSON inicial.\n");
}

$infra = $config['configuracao_infraestrutura'];

// PROCESSA AS SENHAS E CONEXÕES DO .ENV UMA ÚNICA VEZ FORA DO LAÇO
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $envVarName = substr($valor, 4);
            $infra[$no][$chave] = getenv($envVarName) ?: '';
        }
    }
}

$tempoLoopControle = (int)($infra['intervalo_verificacao_segundos'] ?? 5);
$intervaloLimpezaMinutos = (int)($infra['intervalo_limpeza_orfaos_minutos'] ?? 60);

$cronometroTabelas = [];
$proximaLimpezaOrfaos = time(); // Agenda a primeira auditoria de órfãos imediatamente ao iniciar

while (true) {
    try {
        $connLegado  = ConnectionFactory::getLegadoConnection($infra, 'master');
        $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');

        $controlRepo = new ControlRepository($connModerno);
        $acl         = new AntiCorruptionLayer();
        $logger      = new Logger($connModerno);
        
        $processor   = new DataSyncProcessor($connLegado, $connModerno, $controlRepo, $acl, $logger);
        $limpador    = new LimpezaOrfaosProcessor($connLegado, $connModerno, $logger);

        $agora = time();

        // ---------------------------------------------------------------------
        // SUB-PIPELINE 1: CAPTURA INCREMENTAL (INSERÇÕES / ATUALIZAÇÕES)
        // ---------------------------------------------------------------------
        foreach ($config['bancos_gerenciados'] as $banco) {
            foreach ($banco['tabelas'] as $tabela) {
                $tabelaModerna = $tabela['tabela_moderna'];
                $frequenciaMinutos = (int)($tabela['frequencia_sincronizacao_minutos'] ?? 1);
                $intervaloSegundos = $frequenciaMinutos * 60;
                $chaveCronometro = $banco['banco_moderno'] . '.' . $tabelaModerna;

                if (!isset($cronometroTabelas[$chaveCronometro]) || ($agora - $cronometroTabelas[$chaveCronometro]) >= $intervaloSegundos) {
                    echo "\n [" . date('H:i:s') . "] Alocando pipeline incremental para: [{$chaveCronometro}]\n";
                    $inicioMili = microtime(true);

                    $processor->sincronizarTabela($banco, $tabela);
                    
                    $tempoGastoMili = round((microtime(true) - $inicioMili) * 1000, 2);
                    echo " [" . date('H:i:s') . "] Concluído: [{$chaveCronometro}] em {$tempoGastoMili} ms\n";
                    
                    $cronometroTabelas[$chaveCronometro] = time();
                }
            }
        }

        // ---------------------------------------------------------------------
        // SUB-PIPELINE 2: AUDITORIA CRONOMETRADA DE EXPURGO DE ÓRFÃOS (DELEÇÕES)
        // ---------------------------------------------------------------------
        if ($agora >= $proximaLimpezaOrfaos) {
            echo "\n\n [⚠️ " . date('H:i:s') . "] ALERTA DE CRON: Disparando ciclo global de limpeza de registros órfãos...\n";
            $inicioLimpeza = microtime(true);

            foreach ($config['bancos_gerenciados'] as $banco) {
                foreach ($banco['tabelas'] as $tabela) {
                    $limpador->executarLimpeza($banco, $tabela);
                }
            }

            $tempoGastoLimpeza = round((microtime(true) - $inicioLimpeza) * 1000, 2);
            echo " [✔ " . date('H:i:s') . "] Ciclo de limpeza finalizado em {$tempoGastoLimpeza} ms.\n\n";

            // reagenda a próxima execução de limpeza baseado no tempo configurado no JSON
            $proximaLimpezaOrfaos = time() + ($intervaloLimpezaMinutos * 60);
        }

    } catch (Exception $e) {
        echo "ERRO NO ORQUESTRADOR: " . $e->getMessage() . "\n";
        echo "[*] Liberando canais de comunicação e preparando auto-recuperação...\n";
    } finally {
        ConnectionFactory::killConnections();
    }

    sleep($tempoLoopControle);
}