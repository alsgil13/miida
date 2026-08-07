<?php

/**
 * MIIDA - Worker de Provisionamento Rápido e Seed do Ambiente de Dev (CLI)
 * Executa uma carga única (Single-Run) da clonagem de infraestrutura e sincronização inicial.
 */

require_once __DIR__ . '/../autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\SqlServerLegacySyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\SchemaCloner;
use Miida\Engine\DataSyncProcessor;
use Miida\Services\Logger;

$inicioExecucao = microtime(true);

echo "=========================================================\n";
echo "       MIIDA - SETUP & SEED AMBIENTE DESENVOLVIMENTO     \n";
echo "=========================================================\n";

try {
    // 1. Carrega o manifesto JSON desmascarando as variáveis de ambiente
    $config = carregarConfiguracaoPipeline();
    $infra = $config['configuracao_infraestrutura'];

    // 2. RESOLUÇÃO DINÂMICA DA STRATEGY DE ORIGEM (LEGADO)
    $sgbdOrigem = strtolower($infra['origem_command']['sgbd'] ?? 'mysql');
    $syntaxLegado = match ($sgbdOrigem) {
        'postgres', 'postgresql' => new PostgresSyntax(),
        'mysql'                 => new MySqlSyntax(),
        'sqlserver_legacy'      => new SqlServerLegacySyntax(),
        'sqlserver'             => new SqlServerSyntax(),
        default                 => new MySqlSyntax()
    };

    // 3. RESOLUÇÃO DINÂMICA DA STRATEGY DE DESTINO (MODERNO)
    $sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'postgres');
    $syntaxModerno = match ($sgbdDestino) {
        'postgres', 'postgresql' => new PostgresSyntax(),
        'mysql'                 => new MySqlSyntax(),
        'sqlserver_legacy'      => new SqlServerLegacySyntax(),
        'sqlserver'             => new SqlServerSyntax(),
        default                 => new PostgresSyntax()
    };

    echo "[*] SGBD Origem : " . strtoupper($sgbdOrigem) . "\n";
    echo "[*] SGBD Destino: " . strtoupper($sgbdDestino) . "\n";
    echo "---------------------------------------------------------\n";

    // 4. Conecta aos Nós de Banco
    echo "[*] Conectando à Origem... ";
    $connLegado = ConnectionFactory::getLegadoConnection($config, $syntaxLegado);
    echo "[OK]\n";

    echo "[*] Conectando ao Destino... ";
    $connModerno = ConnectionFactory::getModernoConnection($config, $syntaxModerno);
    echo "[OK]\n\n";

    // 5. Instancia Tabela de Controle, Logger e Repositorio
    echo "[*] Garantindo Tabela de Controle Interna... ";
    $ddlTabelaControle = $syntaxModerno->getDDLControle();
    $connModerno->exec($ddlTabelaControle);
    if ($connModerno->inTransaction()) {
        $connModerno->commit();
    }
    echo "[OK]\n\n";

    $logger      = new Logger($connModerno, $syntaxModerno);
    $controlRepo = new ControlRepository($connModerno, $syntaxModerno);

    // =========================================================
    // ETAPA 1: CLONAGEM DA ESTRUTURA (SCHEMA CLONER)
    // =========================================================
    echo "[1/2] Iniciando Clonagem e Validação da Infraestrutura...\n";
    $clonerFilter = new SchemaCloner($connModerno, $syntaxModerno);
    $resCloner    = $clonerFilter->process($config);

    if ($resCloner['status'] !== 'SUCESSO') {
        throw new \RuntimeException("Falha durante o provisionamento da infraestrutura de dev.");
    }
    echo "[OK] Infraestrutura pronta. Estruturas validadas/criadas: {$resCloner['total_processado']}\n\n";

    // =========================================================
    // ETAPA 2: CARGA INICIAL DE DADOS (DATA SYNC)
    // =========================================================
    echo "[2/2] Iniciando Carga Única de Dados...\n";
    $syncProcessor = new DataSyncProcessor(
        $connLegado,
        $connModerno,
        $syntaxLegado,
        $syntaxModerno,
        $controlRepo
    );

    $resSync = $syncProcessor->process($config);

    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);

    // 6. LOG CONSOLIDADO DE SETUP DEV
    if ($resSync['status'] === 'SUCESSO') {
        $mensagem = sprintf(
            "Ambiente Dev preparado e povoado com sucesso! Total de registros copiados: %d. Tempo: %ss",
            $resSync['total_processado'],
            $tempoTotal
        );

        $detalhes = json_encode([
            'tempo_segundos'   => $tempoTotal,
            'etapa_infra'      => $resCloner['detalhes'] ?? [],
            'etapa_sync'       => $resSync['detalhes'] ?? []
        ], JSON_UNESCAPED_UNICODE);

        // Dispara 1 único log consolidado do setup
        $logger->success('DevEnvironmentSetup', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[OK] {$mensagem}\n";
        echo "=========================================================\n";

    } else {
        $mensagem = sprintf("Ambiente Dev criado, porém a carga finalizou com avisos/falhas parciais (%ss)", $tempoTotal);
        $detalhes = json_encode($resSync['detalhes'] ?? [], JSON_UNESCAPED_UNICODE);

        $logger->error('DevEnvironmentSetup', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[WARNING] {$mensagem}\n";
        echo "=========================================================\n";
    }

} catch (\Throwable $e) {
    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);
    
    $loggerLocal = $logger ?? new Logger();
    $mensagemErro = sprintf("Falha crítica ao preparar Ambiente Dev: %s", $e->getMessage());
    $detalhesErro = sprintf("Arquivo: %s (Linha %d) | Tempo: %ss", $e->getFile(), $e->getLine(), $tempoTotal);

    $loggerLocal->error('DevEnvironmentSetup', $mensagemErro, $detalhesErro);

    echo "\nERRO CRÍTICO NO SETUP DEV: " . $e->getMessage() . "\n";
    exit(1);
}