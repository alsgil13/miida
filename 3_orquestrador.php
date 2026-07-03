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

    // 2. ESTRUTURAÇÃO DOS MARCADORES DE CRONOMETRO EM MEMORIA
    $cronometroTabelas = [];
    $intervaloLimpezaMinutos = (int)($infra['intervalo_limpeza_orfaos_minutos'] ?? 60);
    $proximaLimpezaOrfaos = time() + ($intervaloLimpezaMinutos * 60);

    echo "Orquestrador inicializado. Intervalo de limpeza de orfaos: " . $intervaloLimpezaMinutos . " minutos.\n";
    echo "Iniciando loop de captura incremental multi-banco...\n\n";

    // LOOP INFINITO DE ORQUESTRAÇÃO DO INGESTION ENGINE
    while (true) {
        $agora = time();

        // PROCESSAMENTO ISOLADO POR BANCO GERENCIADO (GARANTE MULTI-DATABASE NO POSTGRES)
        foreach ($config['bancos_gerenciados'] as $banco) {
            $nomeBancoLegado  = $banco['banco_legado'];
            $nomeBancoModerno = $banco['banco_moderno'];
            
            $connLegado  = null;
            $connModerno = null;
            $necessitaConexao = false;

            // Checagem prévia: Verifica se alguma tabela deste banco precisa de processamento neste ciclo
            foreach ($banco['tabelas'] as $tabela) {
                $chaveCronometro = $nomeBancoModerno . "." . $tabela['tabela_moderna'];
                $intervaloTabelaSegundos = (int)($tabela['intervalo_sincronizacao_segundos'] ?? 10);

                // CORREÇÃO: Inicializa a chave de tempo antes de realizar a soma comparativa
                if (!isset($cronometroTabelas[$chaveCronometro])) {
                    $cronometroTabelas[$chaveCronometro] = 0;
                }

                if ($agora >= ($cronometroTabelas[$chaveCronometro] + $intervaloTabelaSegundos)) {
                    $necessitaConexao = true;
                    break;
                }
            }

            // Força a conexão também se for o momento do ciclo cronometrado de expurgos
            if ($agora >= $proximaLimpezaOrfaos) {
                $necessitaConexao = true;
            }

            // Estabelece as conexões injetando dinamicamente os nomes dos catálogos atuais
            if ($necessitaConexao) {
                $connLegado  = ConnectionFactory::getLegadoConnection($infra, $syntaxLegado, $nomeBancoLegado);
                $connModerno = ConnectionFactory::getModernoConnection($infra, $syntaxModerno, $nomeBancoModerno);
                
                $controlRepo   = new ControlRepository($connModerno, $syntaxModerno);
                $sincronizador = new DataSyncProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $controlRepo);
                $logger        = new Logger($connModerno, $syntaxModerno);
                $limpador      = new LimpezaOrfaosProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $logger);

                // SUB-PIPELINE 1: CAPTURA INCREMENTAL (DATA SYNC)
                foreach ($banco['tabelas'] as $tabela) {
                    $chaveCronometro = $nomeBancoModerno . "." . $tabela['tabela_moderna'];
                    $intervaloTabelaSegundos = (int)($tabela['intervalo_sincronizacao_segundos'] ?? 10);

                    // Garante que a chave exista por redundância de segurança dentro do escopo
                    if (!isset($cronometroTabelas[$chaveCronometro])) {
                        $cronometroTabelas[$chaveCronometro] = 0;
                    }

                    if ($agora >= ($cronometroTabelas[$chaveCronometro] + $intervaloTabelaSegundos)) {
                        // Executa a carga incremental isolada com segurança de contexto
                        $sincronizador->sincronizarTabela($banco, $tabela);
                        $cronometroTabelas[$chaveCronometro] = time();
                    }
                }

                // SUB-PIPELINE 2: AUDITORIA CRONOMETRADA DE EXPURGO DE ORFAOS DESTE BANCO
                if ($agora >= $proximaLimpezaOrfaos) {
                    echo "\n [ALERTA] Disparando ciclo de limpeza de registros orfaos para: [{$nomeBancoModerno}]\n";
                    $inicioLimpeza = microtime(true);

                    foreach ($banco['tabelas'] as $tabela) {
                        $limpador->executarLimpeza($banco, $tabela);
                    }

                    $tempoGastoLimpeza = round((microtime(true) - $inicioLimpeza) * 1000, 2);
                    echo " [OK] Limpeza de [{$nomeBancoModerno}] finalizada em " . $tempoGastoLimpeza . " ms.\n";
                }

                // Desconecta explicitamente para liberar recursos do Pool do SGBD e do Docker Engine
                $connLegado  = null;
                $connModerno = null;
            }
        }

        // Atualiza o temporizador global da limpeza após varrer todos os escopos pendentes
        if ($agora >= $proximaLimpezaOrfaos) {
            $proximaLimpezaOrfaos = time() + ($intervaloLimpezaMinutos * 60);
            echo "\n [SISTEMA] Proximo ciclo global de orfaos reagendado.\n\n";
        }

        // Descanso defensivo do processador para evitar consumo de 100% de CPU thread lock
        usleep(200000); // 200 milissegundos
    }

} catch (Exception $e) {
    echo "ERRO CRITICO NO ORQUESTRADOR: " . $e->getMessage() . "\n";
    exit(1);
}