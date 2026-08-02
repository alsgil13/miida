<?php

/**
 * MIIDA - Worker de Sincronização Incremental / Multi-SGBD (CLI)
 * Integrado com Logger para registros consolidados de alta performance
 */

require_once __DIR__ . '/../autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\SqlServerLegacySyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\DataSyncProcessor;
use Miida\Services\Logger;

$inicioExecucao = microtime(true);

try {
    // 1. Carrega o manifesto JSON desmascarando automaticamente as variáveis do .env
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

    // 4. Conecta aos Nós de Banco
    $connLegado  = ConnectionFactory::getLegadoConnection($config, $syntaxLegado);
    $connModerno = ConnectionFactory::getModernoConnection($config, $syntaxModerno);

    // 5. Instancia o Logger injetando a conexão e a syntax de destino para gravar no BD
    $logger = new Logger($connModerno, $syntaxModerno);

    // 6. Instancia o Repositório de Controle e o Processador de Sincronização
    $controlRepo = new ControlRepository($connModerno, $syntaxModerno);
    $processor = new DataSyncProcessor(
        $connLegado,
        $connModerno,
        $syntaxLegado,
        $syntaxModerno,
        $controlRepo
    );

    // 7. Executa o processamento do Pipeline
    $resultado = $processor->process($config);

    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);

    // 8. LOG CONSOLIDADO DE EXECUÇÃO
    if ($resultado['status'] === 'SUCESSO') {
        $mensagem = sprintf(
            "Carga efetuada com sucesso (%s -> %s). Total de registros: %d. Tempo: %ss",
            strtoupper($sgbdOrigem),
            strtoupper($sgbdDestino),
            $resultado['total_processado'],
            $tempoTotal
        );

        // Monta os detalhes em JSON apenas com resumo das tabelas
        $detalhes = json_encode([
            'tempo_segundos'   => $tempoTotal,
            'total_processado' => $resultado['total_processado'],
            'tabelas'          => $resultado['detalhes'] ?? []
        ], JSON_UNESCAPED_UNICODE);

        // Dispara o registro ÚNICO no log (arquivo físico + banco moderno)
        $logger->success('DataSyncProcessor', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[OK] {$mensagem}\n";
        echo "=========================================================\n";

    } else {
        // Trata falha parcial no retorno do processor
        $mensagem = sprintf("Sincronização concluída com avisos/falhas parciais (%ss)", $tempoTotal);
        $detalhes = json_encode($resultado['detalhes'] ?? [], JSON_UNESCAPED_UNICODE);

        $logger->error('DataSyncProcessor', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[WARNING] {$mensagem}\n";
        echo "=========================================================\n";
    }

} catch (\Throwable $e) {
    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);
    
    // Tenta gravar o log de erro fatal (usa logger se conexões estiverem ativas, senão grava só em arquivo)
    $loggerLocal = isset($logger) ? $logger : new Logger();
    
    $mensagemErro = sprintf("Falha crítica no Worker de Sincronização: %s", $e->getMessage());
    $detalhesErro = sprintf(
        "Arquivo: %s (Linha %d) | Tempo decorrido até falha: %ss",
        $e->getFile(),
        $e->getLine(),
        $tempoTotal
    );

    // Registra uma única entrada do ERRO
    $loggerLocal->error('DataSyncProcessor', $mensagemErro, $detalhesErro);

    echo "\nERRO CRÍTICO NA EXECUÇÃO: " . $e->getMessage() . "\n";
    exit(1);
}