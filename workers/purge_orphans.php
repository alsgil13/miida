<?php

/**
 * MIIDA - Worker de Expurgo e Auditoria de Registros Órfãos (CLI)
 * Processa a exclusão no ambiente moderno de registros que foram removidos do legado.
 */

require_once __DIR__ . '/../autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\SqlServerLegacySyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\LimpezaOrfaosProcessor;
use Miida\Services\Logger;

$inicioExecucao = microtime(true);

echo "=========================================================\n";
echo "        MIIDA - AUDITORIA E EXPURGO DE ÓRFÃOS            \n";
echo "=========================================================\n";

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

    echo "[*] SGBD Origem : " . strtoupper($sgbdOrigem) . "\n";
    echo "[*] SGBD Destino: " . strtoupper($sgbdDestino) . "\n";
    echo "---------------------------------------------------------\n";

    // 4. Conecta aos Nós de Banco
    echo "[*] Conectando ao nó de origem (Legado)... ";
    $connLegado = ConnectionFactory::getLegadoConnection($config, $syntaxLegado);
    echo "[OK]\n";

    echo "[*] Conectando ao nó de destino (Moderno)... ";
    $connModerno = ConnectionFactory::getModernoConnection($config, $syntaxModerno);
    echo "[OK]\n\n";

    // 5. Instancia o Logger injetando a conexão e a syntax de destino para gravar no BD
    $logger = new Logger($connModerno, $syntaxModerno);

    // 6. Instancia o Processador de Limpeza de Órfãos
    $limpadorFilter = new LimpezaOrfaosProcessor(
        $connLegado,
        $connModerno,
        $syntaxLegado,
        $syntaxModerno,
        $logger
    );

    // 7. Executa o expurgo de órfãos via FilterInterface::process()
    echo "[*] Iniciando varredura e expurgo de dados órfãos...\n\n";
    $resultado = $limpadorFilter->process($config);

    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);

    // 8. LOG CONSOLIDADO DE EXECUÇÃO
    if ($resultado['status'] === 'SUCESSO') {
        $mensagem = sprintf(
            "Expurgo de órfãos concluído com sucesso. Registros removidos: %d. Tempo: %ss",
            $resultado['total_processado'],
            $tempoTotal
        );

        $detalhes = json_encode([
            'tempo_segundos'   => $tempoTotal,
            'total_removidos'  => $resultado['total_processado'],
            'detalhes'         => $resultado['detalhes'] ?? []
        ], JSON_UNESCAPED_UNICODE);

        // Dispara o registro ÚNICO no log (arquivo físico + banco moderno)
        $logger->success('LimpezaOrfaosProcessor', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[OK] {$mensagem}\n";
        echo "=========================================================\n";

    } else {
        // Trata falhas parciais registradas no retorno do processador
        $mensagem = sprintf("Expurgo concluído com avisos/falhas parciais (%ss)", $tempoTotal);
        $detalhes = json_encode($resultado['detalhes'] ?? [], JSON_UNESCAPED_UNICODE);

        $logger->error('LimpezaOrfaosProcessor', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[WARNING] {$mensagem}\n";
        echo "=========================================================\n";
    }

} catch (\Throwable $e) {
    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);
    
    // Fallback de logger caso a exceção ocorra antes de instanciar a conexão
    $loggerLocal = $logger ?? new Logger();
    
    $mensagemErro = sprintf("Falha crítica no Worker de Expurgo de Órfãos: %s", $e->getMessage());
    $detalhesErro = sprintf(
        "Arquivo: %s (Linha %d) | Tempo decorrido até falha: %ss",
        $e->getFile(),
        $e->getLine(),
        $tempoTotal
    );

    // Registra uma entrada ÚNICA do erro
    $loggerLocal->error('LimpezaOrfaosProcessor', $mensagemErro, $detalhesErro);

    echo "\nERRO CRÍTICO NA EXECUÇÃO: " . $e->getMessage() . "\n";
    exit(1);
}