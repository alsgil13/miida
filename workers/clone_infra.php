<?php

/**
 * MIIDA - Worker de Clonagem/Provisionamento de Infraestrutura (CLI)
 * Utiliza SchemaCloner via FilterInterface e Logger para emissão de telemetria consolidada.
 */

require_once __DIR__ . '/../autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\SqlServerLegacySyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\SchemaCloner;
use Miida\Services\Logger;

$inicioExecucao = microtime(true);

echo "=========================================================\n";
echo "      MIIDA - PROVISIONAMENTO DE ESTRUTURA (CLONE)       \n";
echo "=========================================================\n";

try {
    // 1. Carrega o manifesto JSON desmascarando automaticamente as variáveis do .env
    $config = carregarConfiguracaoPipeline();
    $infra = $config['configuracao_infraestrutura'];

    // 2. RESOLUÇÃO DINÂMICA DA STRATEGY DO SGBD DESTINO
    $sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'postgres');
    $syntaxModerno = match ($sgbdDestino) {
        'postgres', 'postgresql' => new PostgresSyntax(),
        'mysql'                 => new MySqlSyntax(),
        'sqlserver_legacy'      => new SqlServerLegacySyntax(),
        'sqlserver'             => new SqlServerSyntax(),
        default                 => new PostgresSyntax()
    };

    echo "[*] SGBD Destino Alvo: " . strtoupper($sgbdDestino) . "\n";
    echo "---------------------------------------------------------\n";

    // 3. Conecta ao Nó de Destino
    echo "[*] Conectando ao banco destino... ";
    $connModerno = ConnectionFactory::getModernoConnection($config, $syntaxModerno);
    echo "[OK]\n";

    // 4. GARANTE A TABELA DE CONTROLE DE SINCRONIZAÇÃO
    echo "[*] Garantindo Tabela de Controle Interna... ";
    $ddlTabelaControle = $syntaxModerno->getDDLControle();
    $connModerno->exec($ddlTabelaControle);
    if ($connModerno->inTransaction()) {
        $connModerno->commit();
    }
    echo "[OK]\n\n";

    // 5. Instancia o Logger injetando a conexão e a syntax de destino para gravar no BD
    $logger = new Logger($connModerno, $syntaxModerno);

    // 6. Instancia o Engine de Provisionamento Estrutural (SchemaCloner)
    $clonerFilter = new SchemaCloner($connModerno, $syntaxModerno);

    // 7. Executa a clonagem estrutural via FilterInterface::process()
    echo "[*] Criando/Garantindo tabelas de negócio e schemas configurados...\n\n";
    $resultado = $clonerFilter->process($config);

    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);

    // 8. LOG CONSOLIDADO DE EXECUÇÃO E TELEMETRIA
    if ($resultado['status'] === 'SUCESSO') {
        $mensagem = sprintf(
            "Provisionamento de infraestrutura concluído com sucesso (%s). Estruturas validadas/criadas: %d. Tempo: %ss",
            strtoupper($sgbdDestino),
            $resultado['total_processado'],
            $tempoTotal
        );

        $detalhes = json_encode([
            'tempo_segundos'   => $tempoTotal,
            'total_processado' => $resultado['total_processado'],
            'detalhes'         => $resultado['detalhes'] ?? []
        ], JSON_UNESCAPED_UNICODE);

        // Dispara o registro ÚNICO no log (arquivo físico + banco moderno)
        $logger->success('SchemaCloner', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[OK] {$mensagem}\n";
        echo "=========================================================\n";

    } else {
        // Trata falhas parciais na clonagem
        $mensagem = sprintf("Provisionamento finalizado com falhas parciais (%ss)", $tempoTotal);
        $detalhes = json_encode($resultado['detalhes'] ?? [], JSON_UNESCAPED_UNICODE);

        $logger->error('SchemaCloner', $mensagem, $detalhes);

        echo "=========================================================\n";
        echo "[WARNING] {$mensagem}\n";
        echo "=========================================================\n";
    }

} catch (\Throwable $e) {
    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);
    
    // Fallback de logger caso a exceção ocorra antes de instanciar o logger com BD
    $loggerLocal = $logger ?? new Logger();
    
    $mensagemErro = sprintf("Falha crítica no Worker de Clonagem de Infraestrutura: %s", $e->getMessage());
    $detalhesErro = sprintf(
        "Arquivo: %s (Linha %d) | Tempo decorrido até falha: %ss",
        $e->getFile(),
        $e->getLine(),
        $tempoTotal
    );

    // Registra uma entrada ÚNICA de erro no log
    $loggerLocal->error('SchemaCloner', $mensagemErro, $detalhesErro);

    echo "\nERRO CRÍTICO NA EXECUÇÃO: " . $e->getMessage() . "\n";
    exit(1);
}