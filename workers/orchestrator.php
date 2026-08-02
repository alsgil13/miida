<?php

/**
 * MIIDA - Motor Daemon Orquestrador de Sincronização e Pipelines
 * Executa os filtros (FilterInterface) em loop contínuo sob demanda e cronogramas.
 */

set_time_limit(0);

require_once __DIR__ . '/../autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\SqlServerLegacySyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\SchemaCloner;
use Miida\Engine\DataSyncProcessor;
use Miida\Engine\LimpezaOrfaosProcessor;
use Miida\Services\Logger;

echo "=========================================================\n";
echo "          MIIDA - MOTOR DAEMON ORQUESTRADOR              \n";
echo "=========================================================\n";
echo "[*] Iniciando Loop Contínuo do Pipeline...\n\n";

// 1. Carrega o manifesto e prepara as variáveis .env
$config = carregarConfiguracaoPipeline();
$infra = $config['configuracao_infraestrutura'];

// 2. RESOLUÇÃO DAS STRATEGIES DE SGBD
$sgbdOrigem = strtolower($infra['origem_command']['sgbd'] ?? 'mysql');
$syntaxLegado = match ($sgbdOrigem) {
    'postgres', 'postgresql' => new PostgresSyntax(),
    'mysql'                 => new MySqlSyntax(),
    'sqlserver_legacy'      => new SqlServerLegacySyntax(),
    'sqlserver'             => new SqlServerSyntax(),
    default                 => new MySqlSyntax()
};

$sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'postgres');
$syntaxModerno = match ($sgbdDestino) {
    'postgres', 'postgresql' => new PostgresSyntax(),
    'mysql'                 => new MySqlSyntax(),
    'sqlserver_legacy'      => new SqlServerLegacySyntax(),
    'sqlserver'             => new SqlServerSyntax(),
    default                 => new PostgresSyntax()
};

// 3. FASE DE BOOTSTRAP (1x na inicialização)
try {
    echo "[*] Executando verificação/provisionamento inicial de infraestrutura...\n";
    $connModernoBoot = ConnectionFactory::getModernoConnection($config, $syntaxModerno);
    
    // Garante Tabela de Controle Interna
    $ddlControle = $syntaxModerno->getDDLControle();
    $connModernoBoot->exec($ddlControle);
    if ($connModernoBoot->inTransaction()) {
        $connModernoBoot->commit();
    }

    // Executa Clonagem/Provisionamento via Filter
    $schemaCloner = new SchemaCloner($connModernoBoot, $syntaxModerno);
    $resCloner = $schemaCloner->process($config);
    
    echo "[OK] Infraestrutura validada. Total de tabelas verificadas/criadas: {$resCloner['total_processado']}\n\n";
    $connModernoBoot = null; // Fecha conexão do bootstrap

} catch (\Throwable $e) {
    echo "ERRO CRÍTICO NO BOOTSTRAP DE INFRAESTRUTURA: " . $e->getMessage() . "\n";
    exit(1);
}

// 4. PREPARAÇÃO DO TEMPORIZADOR E LOOP CONTINUO
$intervaloLimpezaMinutos = (int)($infra['intervalo_limpeza_orfaos_minutos'] ?? 60);
$proximaLimpezaOrfaos = time(); // Executa a primeira verificação de órfãos imediatamente no start

echo "[*] Daemon pronto. Intervalo de limpeza de órfãos: {$intervaloLimpezaMinutos} minuto(s).\n";
echo "[*] Entrando em ciclo de monitoramento...\n\n";

while (true) {
    $inicioCiclo = microtime(true);
    $agora = time();

    try {
        // Conecta aos bancos para o ciclo atual
        $connLegado  = ConnectionFactory::getLegadoConnection($config, $syntaxLegado);
        $connModerno = ConnectionFactory::getModernoConnection($config, $syntaxModerno);

        $logger      = new Logger($connModerno, $syntaxModerno);
        $controlRepo = new ControlRepository($connModerno, $syntaxModerno);

        // Instancia os Filters de Execução Contínua
        $dataSyncProcessor = new DataSyncProcessor(
            $connLegado,
            $connModerno,
            $syntaxLegado,
            $syntaxModerno,
            $controlRepo
        );

        $limpezaProcessor = new LimpezaOrfaosProcessor(
            $connLegado,
            $connModerno,
            $syntaxLegado,
            $syntaxModerno,
            $logger
        );

        // --- FILTRO 1: CAPTURA INCREMENTAL DE DADOS (DataSyncProcessor) ---
        $resSync = $dataSyncProcessor->process($config);

        if ($resSync['total_processado'] > 0) {
            $tempoSync = round(microtime(true) - $inicioCiclo, 2);
            $msg = sprintf("Sincronização concluída. Registros afetados: %d. Tempo: %ss", $resSync['total_processado'], $tempoSync);
            
            $logger->success('Orchestrator:DataSync', $msg, json_encode($resSync['detalhes'], JSON_UNESCAPED_UNICODE));
            echo "[" . date('Y-m-d H:i:s') . "] [SYNC] {$msg}\n";
        }

        // --- FILTRO 2: EXPURGO CRONOMETRADO DE ÓRFÃOS (LimpezaOrfaosProcessor) ---
        if ($agora >= $proximaLimpezaOrfaos) {
            echo "[" . date('Y-m-d H:i:s') . "] [PURGE] Iniciando varredura cronometrada de órfãos...\n";
            $resLimpeza = $limpezaProcessor->process($config);
            
            $tempoLimpeza = round(microtime(true) - $inicioCiclo, 2);
            $msgLimpeza = sprintf("Expurgo de órfãos concluído. Total removido: %d. Tempo: %ss", $resLimpeza['total_processado'], $tempoLimpeza);

            $logger->info('Orchestrator:LimpezaOrfaos', $msgLimpeza);
            echo "[" . date('Y-m-d H:i:s') . "] [PURGE] {$msgLimpeza}\n";

            // Reagenda o próximo ciclo de expurgo
            $proximaLimpezaOrfaos = time() + ($intervaloLimpezaMinutos * 60);
        }

        // Libera conexões PDO ao final da iteração para evitar esgotar recursos de Pool
        $connLegado = null;
        $connModerno = null;

    } catch (\Throwable $e) {
        // Trata erro crítico e gera o log
        $loggerLocal = $logger ?? new Logger();
        $msgErro = "Falha no ciclo do Orquestrador: " . $e->getMessage();
        
        $loggerLocal->error('Orchestrator', $msgErro, "Linha {$e->getLine()} em {$e->getFile()}");
        echo "[" . date('Y-m-d H:i:s') . "] [ERROR] {$msgErro}\n";

        // Garante liberação de conexões mesmo em falha
        $connLegado = null;
        $connModerno = null;
    }

    // Descanso defensivo de CPU (2 segundos entre varreduras)
    sleep(2);
}