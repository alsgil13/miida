<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Script CLI de Inicialização, Provisionamento e Auditoria de Infraestrutura (Multi-SGBD)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\SchemaCloner;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' não foi encontrado na pasta /config.\n");
}

echo "=========================================================\n";
echo "      MIIDA - INICIALIZANDO SUBSISTEMA (MULTI-SGBD)      \n";
echo "=========================================================\n";
echo "[*] Carregando mapa de metadados declarativo...\n";

$config = json_decode(file_get_contents($jsonPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERRO: O arquivo JSON possui erros de sintaxe: " . json_last_error_msg() . "\n");
}

$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIÁVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $infra[$no][$chave] = getenv(substr($valor, 4)) ?: '';
        }
    }
}

try {
    // 1. IDENTIFICAÇÃO E INSTANCIAÇÃO DO PADRÃO STRATEGY PARA O DESTINO
    $sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'sqlserver');
    
    switch ($sgbdDestino) {
        case 'postgres':
        case 'postgresql':
            $syntaxModerno = new PostgresSyntax();
            break;
        case 'mysql':
            $syntaxModerno = new MySqlSyntax();
            break;
        case 'sqlserver':
        default:
            $syntaxModerno = new SqlServerSyntax();
            break;
    }

    echo " -> Motores detetados. Destino configurado como: [" . strtoupper($sgbdDestino) . "]\n";
    echo "[1/2] Conectando ao servidor moderno e preparando metadados...\n";

    // Estabelece a conexão centralizada através da nossa Factory (Agnóstica a banco na DSN)
    $connModerno = ConnectionFactory::getModernoConnection($infra);

    // Resolve os nomes qualificados das tabelas globais baseado no dialeto do banco ativo
    $tabelaControle = $syntaxModerno->obterNomeQualificado('master', 'dbo', 'miida_controle_sincronizacao');
    $tabelaLogs     = $syntaxModerno->obterNomeQualificado('master', 'dbo', 'miida_logs_sistema');

    // Executa a criação do Schema lógico (se o SGBD der suporte, ex: Postgres)
    $sqlSchemaMaster = $syntaxModerno->obterDdlCriarSchema('dbo');
    $connModerno->exec($sqlSchemaMaster);

    // FASE 1: PROVISIONAMENTO DAS TABELAS DE INTEGRALIDADE E MIDDLEWARE
    
    // Tabela de Controle de Cursores Temporais (Estrutura ANSI compatível com todos os SGBDs)
    $corpoControle = "
        banco_nome VARCHAR(128) NOT NULL,
        tabela_nome VARCHAR(128) NOT NULL,
        ultima_sincronizacao DATETIME NOT NULL,
        status_execucao VARCHAR(16) NOT NULL,
        registros_afetados INT NOT NULL,
        PRIMARY KEY (banco_nome, tabela_nome)
    ";
    // Ajusta o tipo DATETIME para TIMESTAMP se for Postgres para manter compatibilidade nativa de tipos
    if ($sgbdDestino === 'postgres' || $sgbdDestino === 'postgresql') {
        $corpoControle = str_replace('DATETIME', 'TIMESTAMP', $corpoControle);
    }
    
    $sqlCreateTableControle = $syntaxModerno->obterDdlCriarTabela('dbo', 'miida_controle_sincronizacao', $corpoControle);
    $connModerno->exec($sqlCreateTableControle);

    // Tabela Centralizada de Auditoria e Logs do MIIDA
    $corpoLogs = "
        id INT AUTO_INCREMENT PRIMARY KEY,
        data_log DATETIME NOT NULL,
        nivel VARCHAR(16) NOT NULL,
        componente VARCHAR(64) NOT NULL,
        mensagem TEXT NOT NULL,
        detalhes TEXT NULL
    ";
    // Ajustes finos sintáticos por dialeto para a tabela de logs
    if ($sgbdDestino === 'postgres' || $sgbdDestino === 'postgresql') {
        $corpoLogs = "
            id SERIAL PRIMARY KEY,
            data_log TIMESTAMP NOT NULL,
            nivel VARCHAR(16) NOT NULL,
            componente VARCHAR(64) NOT NULL,
            mensagem TEXT NOT NULL,
            detalhes TEXT NULL
        ";
    } elseif ($sgbdDestino === 'sqlserver') {
        $corpoLogs = "
            id INT IDENTITY(1,1) PRIMARY KEY,
            data_log DATETIME NOT NULL,
            nivel VARCHAR(16) NOT NULL,
            componente VARCHAR(64) NOT NULL,
            mensagem VARCHAR(MAX) NOT NULL,
            detalhes VARCHAR(MAX) NULL
        ";
    }

    $sqlCreateTableLogs = $syntaxModerno->obterDdlCriarTabela('dbo', 'miida_logs_sistema', $corpoLogs);
    $connModerno->exec($sqlCreateTableLogs);
    
    echo " -> Tabelas de controle e logs checadas/criadas com sucesso no nó de destino.\n\n";

    // FASE 2: CLONAGEM E ENGENHERIA REVERSA DOS BANCOS DE NEGÓCIO
    echo "[2/2] Iniciando clonagem declarativa dos esquemas de negócio...\n";
    
    // Injeta a estratégia de sintaxe diretamente no Cloner multi-SGBD
    $cloner = new SchemaCloner($connModerno, $syntaxModerno);
    $cloner->clonar($config['bancos_gerenciados']);

    echo "\n[ OK ] Todo o ecossistema (Controle + Negócio) foi provisionado com sucesso!\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\n[ X ] ERRO CRÍTICO DURANTE A EXECUÇÃO:\n " . $e->getMessage() . "\n";
    exit(1);
}