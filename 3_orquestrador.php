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

    echo "[*] Conectando ao Banco Legado de Origem...\n";
    $primeiroBancoLegado = $config['bancos_gerenciados'][0]['banco_legado'] ?? '';
    $connLegado = ConnectionFactory::getLegadoConnection($infra, $primeiroBancoLegado);

    echo "[*] Conectando ao Banco Moderno de Destino...\n";
    $primeiroBancoModerno = $config['bancos_gerenciados'][0]['banco_moderno'] ?? 'dw_moderno_db';
    $connModerno = ConnectionFactory::getModernoConnection($infra, $primeiroBancoModerno);
    echo "[OK] Inicializacao de conexoes e motores efetuada com sucesso.\n\n";

    // 2. INJECAO DE DEPENDENCIAS DE INFRAESTRUTURA
    $controlRepo = new ControlRepository($connModerno, $syntaxModerno);
    $logger = new Logger($connModerno, $syntaxModerno);
    
    // Processadores reconfigurados com a assinatura correta
    $sincronizador = new DataSyncProcessor($connLegado, $connModerno, $controlRepo, $syntaxModerno);
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
                
                $tabelaModerna = $tabela['tabela_moderna'];
                $schemaModerno = $tabela['schema_moderno'] ?? 'dbo';
                if ($sgbdDestino === 'sqlserver' && strtolower($schemaModerno) === 'public') {
                    $schemaModerno = 'dbo';
                }

                $chaveCronometro = $banco['banco_moderno'] . "." . $tabelaModerna;
                $intervaloTabelaSegundos = (int)($tabela['intervalo_sincronizacao_segundos'] ?? 10);

                if (!isset($cronometroTabelas[$chaveCronometro])) {
                    $cronometroTabelas[$chaveCronometro] = 0;
                }

                if ($agora >= ($cronometroTabelas[$chaveCronometro] + $intervaloTabelaSegundos)) {
                    
                    $horaFormatada = date('H:i:s');
                    echo "[{$horaFormatada}] Verificando incrementos para: {$banco['banco_legado']}.{$tabela['tabela_legada']}...\n";
                    
                    // Executa a carga incremental isolada
                    $linhas = $sincronizador->sincronizarTabela($banco, $tabela);
                    
                    if ($linhas > 0) {
                        echo "   └── [ OK ] +{$linhas} novos registros sincronizados.\n";
                    }
                    
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
                    if ($sgbdDestino === 'sqlserver' && isset($tabela['schema_moderno']) && strtolower($tabela['schema_moderno']) === 'public') {
                        $tabela['schema_moderno'] = 'dbo';
                    }
                    $limpador->executarLimpeza($banco, $tabela);
                }
            }

            $tempoGastoLimpeza = round((microtime(true) - $inicioLimpeza) * 1000, 2);
            echo " [OK] Ciclo de limpeza finalizado em " . $tempoGastoLimpeza . " ms.\n\n";

            // Reagenda a proxima execucao
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