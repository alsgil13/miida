<?php

/**
 * MIIDA - Middleware de Ingestao, Integracao e Desacoplamento de Arquiteturas
 * Daemon Orquestrador de Sincronizacao Continua (Worker Process) - Versao Multi-SGBD Limpa
 */

set_time_limit(0);

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Services\AntiCorruptionLayer;
use Miida\Services\Logger;
use Miida\Engine\DataSyncProcessor;
use Miida\Engine\LimpezaOrfaosProcessor;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo de parametrizacao 'pipeline_config.json' nao foi encontrado.\n");
}

echo "=========================================================\n";
echo "           MIIDA - MOTOR DAEMON ORQUESTRADOR             \n";
echo "=========================================================\n";
echo "[*] Iniciando Worker em segundo plano (Loop Continuo)...\n";
echo "[*] Monitorando alteracoes cadastrais e de infraestrutura...\n\n";

$config = json_decode(file_get_contents($jsonPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERRO: O arquivo JSON possui erros de sintaxe: " . json_last_error_msg() . "\n");
}

$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIAVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $envVarName = substr($valor, 4);
            $infra[$no][$chave] = getenv($envVarName) ?: '';
        }
    }
}

try {
    // 1. RESOLUCAO DOS DIALETOS DE SGBD (PADRAO STRATEGY)
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

    // Adaptação: Injeção das instâncias de Strategy na criação das conexões da Factory
    echo "[*] Conectando ao Banco Legado de Origem...\n";
    $connLegado = ConnectionFactory::getLegadoConnection($infra, $syntaxLegado);

    echo "[*] Conectando ao Banco Moderno de Destino...\n";
    $connModerno = ConnectionFactory::getModernoConnection($infra, $syntaxModerno);
    echo "[OK] Inicializacao de conexoes e motores efetuada com sucesso.\n\n";

    // 2. INJECAO DE DEPENDENCIAS DE INFRAESTRUTURA
    $controlRepo = new ControlRepository($connModerno, $syntaxModerno);
    $acl = new AntiCorruptionLayer();
    $logger = new Logger($connModerno, $syntaxModerno);
    
    // Processadores configurados com suporte Multi-SGBD nativo
    $sincronizador = new DataSyncProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $controlRepo);
    $limpador = new LimpezaOrfaosProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $logger);

    // 3. ESTRUTURAÇÃO DOS MARCADORES DE CRONOMETRO EM MEMORIA
    $cronometroTabelas = [];
    $intervaloLimpezaMinutos = (int)($config['configuracao_global']['intervalo_limpeza_orfaos_minutos'] ?? 60);
    $proximaLimpezaOrfaos = time() + ($intervaloLimpezaMinutos * 60);

    echo "Orquestrador rodando. Intervalo de limpeza de orfaos configurado para " . $intervaloLimpezaMinutos . " minutos.\n";
    echo "Iniciando loop de captura incremental...\n\n";

    // LOOP INFINITO DE ORQUESTRAÇÃO DO INGESTION ENGINE
    while (true) {
        $agora = time();

        // SUB-PIPELINE 1: CAPTURA INCREMENTAL DAS ALTERAÇÕES (DATA SYNC)
        foreach ($config['bancos_gerenciados'] as $banco) {
            foreach ($banco['tabelas'] as $tabela) {
                
                $chaveCronometro = $banco['banco_moderno'] . "." . $tabela['tabela_moderna'];
                $intervaloTabelaSegundos = (int)($tabela['intervalo_sincronizacao_segundos'] ?? 10);

                if (!isset($cronometroTabelas[$chaveCronometro])) {
                    $cronometroTabelas[$chaveCronometro] = 0;
                }

                if ($agora >= ($cronometroTabelas[$chaveCronometro] + $intervaloTabelaSegundos)) {
                    
                    // Executa a carga incremental isolada
                    $sincronizador->sincronizarTabela($banco, $tabela);
                    
                    // Atualiza o marcador temporal da tabela para o proximo ciclo
                    $cronometroTabelas[$chaveCronometro] = time();
                }
            }
        }

        // SUB-PIPELINE 2: AUDITORIA CRONOMETRADA DE EXPURGO DE ORFAOS
        if ($agora >= $proximaLimpezaOrfaos) {
            echo "\n\n [ALERTA] Disparando ciclo global de limpeza de registros orfaos...\n";
            $inicioLimpeza = microtime(true);

            foreach ($config['bancos_gerenciados'] as $banco) {
                foreach ($banco['tabelas'] as $tabela) {
                    $limpador->executarLimpeza($banco, $tabela);
                }
            }

            $tempoGastoLimpeza = round((microtime(true) - $inicioLimpeza) * 1000, 2);
            echo " [OK] Ciclo de limpeza finalizado em " . $tempoGastoLimpeza . " ms.\n\n";

            // Reagenda a proxima execucao baseando-se no tempo definido no JSON
            $proximaLimpezaOrfaos = time() + ($intervaloLimpezaMinutos * 60);
        }

        // Descanso defensivo do processador para evitar consumo de 100% de CPU thread lock
        usleep(200000); // 200 milissegundos
    }

} catch (Exception $e) {
    echo "ERRO NO ORQUESTRADOR: " . $e->getMessage() . "\n";
    if (isset($logger)) {
        $logger->error("DataSyncOrchestrator Daemon", "Falha fatal no loop do orquestrador", $e->getMessage());
    }
    exit(1);
}